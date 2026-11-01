<?php
/**
 * What the Article check and the article routes show about an article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Checks\AbstractCheck;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Checks\CheckReport;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The REST routes under `/articles` and the Article check screen read the same
 * service, so they agree. Get it with `Plugin::instance()->make( ArticleService::class )`.
 *
 * Nothing here calls another server. `send()` puts work in the queue and returns.
 *
 * Shapes (times are ISO 8601 in UTC; statuses are `Core\Status` codes):
 *
 *     summary():  { id, title, language, status, author{id,name}, link, edit_link,
 *                   published, modified, check{checked,counts,clean,skipped_on},
 *                   results{ channel => { status, at, message } }, date_guard }
 *     check():    { post_id, stored, checked, language, counts, clean, channels, findings,
 *                   results, date_guard }
 *     preview():  { post_id, channel{id,label,mode}, language, eligible, content_type, entry,
 *                   findings[ { code, severity, message, fix, rule } ], pack }
 *     history():  { post_id, delivery[], date_guard[], versions[] }
 *     send():     { post_id, queued[], skipped[ { channel, reason } ] }
 */
final class ArticleService {

	/**
	 * How many of the newest articles are looked at when a filter needs the stored check of each.
	 */
	private const WINDOW = 500;

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * A page of articles.
	 *
	 * Arguments: `language` (a code, or empty for all), `status` (`problems`, `waiting`, or a
	 * post status such as `publish`; empty for all), `search`, `page`, `per_page` (at most 100).
	 * A person who may only see their own articles only gets those.
	 *
	 * @param array<string, mixed> $args Filters and paging.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function list( array $args ): array {
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$language = (string) ( $args['language'] ?? '' );
		$filter   = (string) ( $args['status'] ?? '' );
		$search   = trim( (string) ( $args['search'] ?? '' ) );
		$statuses = array( 'publish', 'future', 'draft', 'pending' );
		$query    = array(
			'post_type'              => $this->plugin->settings()->post_types(),
			'post_status'            => in_array( $filter, $statuses, true ) ? array( $filter ) : $statuses,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		if ( '' !== $search ) {
			$query['s'] = $search;
		}

		if ( $this->plugin->access()->sees_only_own() ) {
			$query['author'] = get_current_user_id();
		}

		$window = in_array( $filter, array( 'problems', 'waiting' ), true );

		if ( $window ) {
			$query['posts_per_page'] = self::WINDOW;
			$query['fields']         = 'ids';
			$query['no_found_rows']  = true;
		} else {
			$query['posts_per_page'] = $per_page;
			$query['paged']          = $page;
		}

		if ( 'waiting' === $filter ) {
			$query['post__in'] = $this->waiting_ids();
		}

		$everything = '' === $language || 'all' === $language;

		if ( $everything ) {
			$query['lang'] = '';
		} else {
			$query = array_merge( $query, $this->plugin->languages()->query_args( $language ) );
		}

		$run = static function () use ( $query ): array {
			$posts = new \WP_Query( $query );

			return array( $posts->posts, (int) $posts->found_posts );
		};

		$result = $everything ? $run() : $this->plugin->languages()->with_language( $language, $run );
		$posts  = (array) $result[0];
		$total  = (int) $result[1];

		if ( $window ) {
			$checker = $this->plugin->make( ArticleChecker::class );

			if ( 'problems' === $filter ) {
				$posts = array_values(
					array_filter(
						$posts,
						static function ( $id ) use ( $checker ): bool {
							$report = $checker->stored( (int) $id );

							return null !== $report && ! $report->is_clean();
						}
					)
				);
			}

			$total = count( $posts );
			$posts = array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );
		}

		$items = array();

		foreach ( $posts as $post ) {
			$post = $post instanceof \WP_Post ? $post : get_post( (int) $post );

			if ( $post instanceof \WP_Post ) {
				$items[] = $this->summary( $post );
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * One article for a list: the stored check and the result on each channel.
	 *
	 * @param \WP_Post $post The post.
	 * @return array<string, mixed>
	 */
	public function summary( \WP_Post $post ): array {
		$id       = (int) $post->ID;
		$language = $this->plugin->languages()->language_of_post( $id );
		$report   = $this->plugin->make( ArticleChecker::class )->stored( $id );
		$counts   = null === $report ? $this->zero_counts() : $report->counts();
		$date     = Clock::from_gmt( (string) $post->post_date_gmt );

		return array(
			'id'         => $id,
			'title'      => wp_strip_all_tags( get_the_title( $post ) ),
			'language'   => $language,
			'status'     => (string) $post->post_status,
			'author'     => array(
				'id'   => (int) $post->post_author,
				'name' => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			),
			'link'       => 'publish' === $post->post_status ? (string) get_permalink( $post ) : '',
			'edit_link'  => (string) get_edit_post_link( $id, 'raw' ),
			'published'  => $date > 0 ? Clock::iso( $date ) : null,
			'modified'   => Clock::iso( Clock::from_gmt( (string) $post->post_modified_gmt ) ),
			'check'      => array(
				'checked'    => null === $report ? null : Clock::iso( $report->checked() ),
				'counts'     => $counts,
				'clean'      => null === $report ? null : $report->is_clean(),
				'skipped_on' => null === $report ? array() : $report->skipping_channels(),
			),
			'results'    => $this->results( $post, $language, $report ),
			'date_guard' => $this->guard( $id ),
		);
	}

	/**
	 * The stored check of an article, or the run of a new one.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $run     Run the checks now and store the result.
	 * @return array<string, mixed>|null Null when there is no such post.
	 */
	public function check( int $post_id, bool $run ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$checker = $this->plugin->make( ArticleChecker::class );
		$report  = $run ? $checker->check( $post_id, true ) : $checker->stored( $post_id );
		$stored  = null !== $report;
		$data    = $stored ? $report->to_array() : array();
		$labels  = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$labels[ $channel->id() ] = $channel->label();
		}

		$channels = array();

		foreach ( (array) ( $data['channels'] ?? array() ) as $id => $channel ) {
			$channels[ (string) $id ] = array(
				'label'    => $labels[ (string) $id ] ?? (string) $id,
				'eligible' => (bool) ( $channel['eligible'] ?? true ),
				'findings' => array_values( (array) ( $channel['findings'] ?? array() ) ),
			);
		}

		$language = $this->plugin->languages()->language_of_post( $post_id );

		return array(
			'post_id'    => $post_id,
			'stored'     => $stored,
			'checked'    => $stored ? Clock::iso( $report->checked() ) : null,
			'language'   => $language,
			'counts'     => $stored ? $report->counts() : $this->zero_counts(),
			'clean'      => $stored ? $report->is_clean() : null,
			'channels'   => $channels,
			'findings'   => array_values( (array) ( $data['findings'] ?? array() ) ),
			'results'    => $this->results( $post, $language, $report ),
			'date_guard' => $this->guard( $post_id ),
		);
	}

	/**
	 * The result of an article on each channel that is on and takes its language.
	 *
	 * A delivery row says what happened. Without one, a blocked article is skipped, an
	 * article on a pulled channel is in the feed while it is inside the feed window, and
	 * anything else is off. Nothing is shown for an article that is not published yet.
	 *
	 * @param \WP_Post         $post     The post.
	 * @param string           $language Language code.
	 * @param CheckReport|null $report   The stored check.
	 * @return array<string, array{status: string, at: string|null, message: string}>
	 */
	public function results( \WP_Post $post, string $language, ?CheckReport $report ): array {
		if ( 'publish' !== $post->post_status ) {
			return array();
		}

		$latest    = $this->plugin->log()->latest_for_post( (int) $post->ID );
		$published = Clock::from_gmt( (string) $post->post_date_gmt );
		$out       = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$id = $channel->id();

			if ( isset( $latest[ $id ] ) ) {
				$out[ $id ] = array(
					'status'  => (string) $latest[ $id ]['status'],
					'at'      => Clock::iso( (int) $latest[ $id ]['created_ts'] ),
					'message' => (string) $latest[ $id ]['message'],
				);
				continue;
			}

			if ( null !== $report && ! $report->is_eligible( $id ) ) {
				$out[ $id ] = array(
					'status'  => Status::SKIPPED,
					'at'      => null,
					'message' => $this->first_block( $report, $id ),
				);
				continue;
			}

			$window = $channel->feed_window();
			$inside = ChannelInterface::MODE_PULLED === $channel->mode() && Clock::now() - $published <= (int) $window['max_age'];

			$out[ $id ] = array(
				'status'  => $inside ? Status::IN_FEED : Status::OFF,
				'at'      => null,
				'message' => '',
			);
		}

		/**
		 * Lets a hold or a release plan say that a feed does not carry the article yet (internal).
		 *
		 * @param array<string, array{status: string, at: string|null, message: string}> $out      Result per channel.
		 * @param \WP_Post                                                                $post     The post.
		 * @param string                                                                  $language Language code.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_article_results', $out, $post, $language );

		return is_array( $filtered ) ? $filtered : $out;
	}

	/**
	 * The entry a channel would get for an article, as text, and the rule behind each finding.
	 *
	 * The checks run now and nothing is stored.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $channel_id Channel id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function preview( int $post_id, string $channel_id ) {
		$channel = $this->plugin->registry()->get( $channel_id );

		if ( null === $channel ) {
			return new \WP_Error( 'estasoft_nd_unknown_channel', __( 'There is no channel with that name.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$article = $this->plugin->reader()->find( $post_id );

		if ( null === $article ) {
			return new \WP_Error( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$accepts = $channel->accepts_language( $article->language() );
		$entry   = '';

		if ( $accepts ) {
			try {
				$entry = $channel->build( array( $article ), $article->language() );
			} catch ( \Throwable $error ) {
				$entry = '';
			}
		}

		$report   = $this->plugin->make( ArticleChecker::class )->build( $article );
		$channels = $report->channels();
		$findings = array();

		foreach ( (array) ( $channels[ $channel_id ]['findings'] ?? array() ) as $finding ) {
			$finding['rule'] = $this->rule_for( $channel_id, (string) $finding['code'] );
			$findings[]      = $finding;
		}

		$pack = $this->plugin->rules()->pack( $channel_id );

		return array(
			'post_id'      => $post_id,
			'channel'      => array(
				'id'    => $channel->id(),
				'label' => $channel->label(),
				'mode'  => $channel->mode(),
			),
			'language'     => $article->language(),
			'accepts'      => $accepts,
			'eligible'     => $accepts && $report->is_eligible( $channel_id ),
			'content_type' => $channel->content_type(),
			'entry'        => $entry,
			'findings'     => $findings,
			'pack'         => array(
				'platform' => $channel_id,
				'version'  => (string) ( $pack['version'] ?? '' ),
				'read_on'  => isset( $pack['read_on'] ) && is_string( $pack['read_on'] ) ? $pack['read_on'] : null,
			),
		);
	}

	/**
	 * The rule a finding comes from: the channel's pack when it has the rule, else the article-checks pack.
	 *
	 * @param string $channel_id Channel id.
	 * @param string $code       Finding code.
	 * @return array{pack: string, version: string, code: string, severity: string, confidence: string, source: string, read_on: string|null, note: string}|null
	 */
	public function rule_for( string $channel_id, string $code ): ?array {
		$rules = $this->plugin->rules();
		$keys  = array_merge( array( $code ), AbstractCheck::PACK_ALIASES[ $code ] ?? array() );

		foreach ( array( $channel_id, AbstractCheck::PACK ) as $platform ) {
			foreach ( $keys as $key ) {
				$rule = $rules->rule( $platform, $key );

				if ( array() === $rule ) {
					continue;
				}

				return array(
					'pack'       => $platform,
					'version'    => $rules->version( $platform ),
					'code'       => $key,
					'severity'   => $rules->severity( $platform, $key ),
					'confidence' => (string) ( $rule['confidence'] ?? 'unverified' ),
					'source'     => (string) ( $rule['source'] ?? '' ),
					'read_on'    => isset( $rule['read_on'] ) && is_string( $rule['read_on'] ) ? $rule['read_on'] : null,
					'note'       => (string) ( $rule['note'] ?? '' ),
				);
			}
		}

		return null;
	}

	/**
	 * What happened to an article: the delivery log and the date guard's decisions, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function history( int $post_id ): array {
		$rows     = $this->plugin->log()->query(
			array(
				'post'     => $post_id,
				'per_page' => 50,
			)
		)['rows'];
		$delivery = array();

		foreach ( $rows as $row ) {
			$delivery[] = array(
				'id'            => (int) $row['id'],
				'channel'       => (string) $row['channel'],
				'event'         => (string) $row['event'],
				'status'        => (string) $row['status'],
				'attempt'       => (int) $row['attempt'],
				'response_code' => (int) $row['response_code'],
				'message'       => (string) $row['message'],
				'at'            => Clock::iso( (int) $row['created_ts'] ),
			);
		}

		$guard = $this->plugin->make( DateGuard::class );
		$dates = array();

		foreach ( $guard->events( $post_id ) as $event ) {
			$dates[] = $this->guard_entry( $event );
		}

		$versions = array();

		foreach ( array_slice( array_values( wp_get_post_revisions( $post_id ) ), 0, 20 ) as $revision ) {
			$versions[] = array(
				'id'     => (int) $revision->ID,
				'at'     => Clock::iso( Clock::from_gmt( (string) $revision->post_modified_gmt ) ),
				'author' => (string) get_the_author_meta( 'display_name', (int) $revision->post_author ),
			);
		}

		return array(
			'post_id'    => $post_id,
			'delivery'   => $delivery,
			'date_guard' => $dates,
			'versions'   => $versions,
		);
	}

	/**
	 * Send now: puts a delivery for each pushed channel in the queue with no index-first delay.
	 *
	 * A channel's own rules still apply: an article a channel would refuse is skipped and
	 * the reason goes in the delivery log. A hold set elsewhere still holds. Pulled channels
	 * are not sent anything, because they fetch their feed.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $only    Channel ids to send to; empty for every pushed channel that is on.
	 * @return array{post_id: int, queued: string[], skipped: array<int, array{channel: string, reason: string}>}|\WP_Error
	 */
	public function send( int $post_id, array $only = array() ) {
		$article = $this->plugin->reader()->find( $post_id );

		if ( null === $article ) {
			return new \WP_Error( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		if ( ! $article->is_public() ) {
			return new \WP_Error( 'estasoft_nd_not_published', __( 'The article is not published, so there is nothing to send.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$queue = $this->plugin->queue();

		if ( ! $queue->is_available() ) {
			return new \WP_Error( 'estasoft_nd_queue_unavailable', __( 'The background queue is not available, so nothing can be sent now.', 'estasoft-news-distribution' ), array( 'status' => 503 ) );
		}

		/**
		 * Fires before Send now queues anything, so a waiting delivery or a release plan
		 * does not stand in its way (internal).
		 *
		 * @param int      $post_id Post ID.
		 * @param string[] $only    Channel ids, or an empty array for every pushed channel.
		 */
		do_action( 'estasoft_nd_internal_send_now', $post_id, $only );

		$log     = $this->plugin->log();
		$latest  = $log->latest_for_post( $post_id );
		$queued  = array();
		$skipped = array();

		foreach ( $this->plugin->registry()->pushed() as $channel ) {
			$id = $channel->id();

			if ( array() !== $only && ! in_array( $id, $only, true ) ) {
				continue;
			}

			if ( ! $channel->is_enabled() || ! $channel->accepts_language( $article->language() ) ) {
				if ( array() !== $only ) {
					$skipped[] = array(
						'channel' => $id,
						'reason'  => __( 'The channel is off, or it does not take this language.', 'estasoft-news-distribution' ),
					);
				}

				continue;
			}

			$errors = $channel->validate( $article )->errors();

			if ( array() !== $errors ) {
				$reason = (string) $errors[0]['message'];
				$log->record( $post_id, $id, $article->language(), Event::UPDATE, Status::SKIPPED, 1, 0, $reason );
				$skipped[] = array(
					'channel' => $id,
					'reason'  => $reason,
				);
				continue;
			}

			$type   = isset( $latest[ $id ] ) && Status::SENT === $latest[ $id ]['status'] ? Event::UPDATE : Event::PUBLISH;
			$action = $queue->enqueue( $id, $post_id, $type, 0 );

			if ( 0 === $action ) {
				$skipped[] = array(
					'channel' => $id,
					'reason'  => __( 'The same delivery is already waiting.', 'estasoft-news-distribution' ),
				);
				continue;
			}

			$log->record( $post_id, $id, $article->language(), $type, Status::QUEUED, 1, 0, '', Clock::now() );
			$queued[] = $id;
		}

		return array(
			'post_id' => $post_id,
			'queued'  => $queued,
			'skipped' => $skipped,
		);
	}

	/**
	 * The date guard's last decision on an article, in the shape the routes return.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	private function guard( int $post_id ): ?array {
		$last = $this->plugin->make( DateGuard::class )->last( $post_id );

		return null === $last ? null : $this->guard_entry( $last );
	}

	/**
	 * One stored decision, in the shape the routes return.
	 *
	 * @param array<string, mixed> $entry A stored decision.
	 * @return array<string, mixed>
	 */
	private function guard_entry( array $entry ): array {
		$decision = (string) ( $entry['decision'] ?? '' );
		$reason   = (string) ( $entry['reason'] ?? '' );
		$chars    = (int) ( $entry['chars'] ?? 0 );

		return array(
			'decision'    => $decision,
			'status'      => DateGuard::status_of( $decision ),
			'reason'      => $reason,
			'chars'       => $chars,
			'description' => DateGuard::describe( $reason, $chars ),
			'at'          => Clock::iso( (int) ( $entry['at'] ?? 0 ) ),
			'modified'    => '' === (string) ( $entry['modified_gmt'] ?? '' ) ? null : Clock::iso( Clock::from_gmt( (string) $entry['modified_gmt'] ) ),
			'previous'    => '' === (string) ( $entry['previous_gmt'] ?? '' ) ? null : Clock::iso( Clock::from_gmt( (string) $entry['previous_gmt'] ) ),
		);
	}

	/**
	 * The message of the first finding that blocks a channel.
	 *
	 * @param CheckReport $report     The check.
	 * @param string      $channel_id Channel id.
	 * @return string
	 */
	private function first_block( CheckReport $report, string $channel_id ): string {
		foreach ( (array) ( $report->channels()[ $channel_id ]['findings'] ?? array() ) as $finding ) {
			if ( Status::BLOCK === ( $finding['severity'] ?? '' ) ) {
				return (string) $finding['message'];
			}
		}

		return '';
	}

	/**
	 * Post IDs that have a delivery waiting in the queue.
	 *
	 * @return int[] At least one ID, so a query on them finds nothing when none waits.
	 */
	private function waiting_ids(): array {
		$log   = $this->plugin->log();
		$found = array();

		foreach ( $log->query(
			array(
				'status'   => Status::QUEUED,
				'per_page' => 100,
			)
		)['rows'] as $row ) {
			$post_id = (int) $row['post_id'];

			if ( isset( $found[ $post_id ] ) ) {
				continue;
			}

			foreach ( $log->latest_for_post( $post_id ) as $latest ) {
				if ( Status::QUEUED === $latest['status'] ) {
					$found[ $post_id ] = true;
					break;
				}
			}
		}

		return array() === $found ? array( 0 ) : array_keys( $found );
	}

	/**
	 * Counts with nothing in them.
	 *
	 * @return array<string, int>
	 */
	private function zero_counts(): array {
		return array(
			Status::BLOCK => 0,
			Status::WARN  => 0,
			Status::INFO  => 0,
		);
	}
}
