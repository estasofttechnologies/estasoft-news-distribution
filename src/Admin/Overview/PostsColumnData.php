<?php
/**
 * What the Posts list shows for each article in the "News Distribution" column, read for the whole page at once.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Core\Takedown;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The list reads the delivery log with one query for every article on the page, and the stored checks
 * from the post meta WordPress already loaded for the page. Drawing a row asks for no query of its own.
 *
 * The results are the ones `Core\ArticleService::results()` gives for the Article check, worked out the
 * same way (a delivery row says what happened; without one, a blocked article is skipped and an article
 * inside a feed's window is in the feed). They are not read through that method because it reads the log
 * once per article.
 *
 * A cell is:
 *
 *     {
 *       published,                       // true for a published article
 *       groups: { sitemap|partner|readers|pushes: { title, status } },   // published only
 *       line:   { status, text },        // the muted line under the icons
 *     }
 */
final class PostsColumnData {

	private Plugin $plugin;

	/**
	 * Cells already worked out, by article.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $cells = array();

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Reads the cells of a page of articles.
	 *
	 * @param array<int, \WP_Post|int> $posts The articles on the page.
	 * @return void
	 */
	public function prime( array $posts ): void {
		$todo = array();

		foreach ( $posts as $post ) {
			$post = get_post( $post );

			if ( $post instanceof \WP_Post && ! isset( $this->cells[ (int) $post->ID ] ) ) {
				$todo[ (int) $post->ID ] = $post;
			}
		}

		if ( array() === $todo ) {
			return;
		}

		// A page of the Posts list has its meta loaded already. This is for any other caller.
		update_meta_cache( 'post', array_keys( $todo ) );

		$latest = $this->latest( array_keys( $todo ) );

		// A takedown is found per article. Read them for the whole page in one query, and let go after.
		$takedown = $this->plugin->make( Takedown::class );

		$takedown->prime( array_keys( $todo ) );

		try {
			foreach ( $todo as $id => $post ) {
				$this->cells[ $id ] = $this->cell( $post, $latest[ $id ] ?? array() );
			}
		} finally {
			$takedown->release_prime();
		}
	}

	/**
	 * The cell of one article. An article that was not primed is read on its own.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>|null Null when there is no such article.
	 */
	public function get( int $post_id ): ?array {
		if ( ! isset( $this->cells[ $post_id ] ) ) {
			$this->prime( array( $post_id ) );
		}

		return $this->cells[ $post_id ] ?? null;
	}

	/**
	 * The group results an article shows, in column order.
	 *
	 * @return string[]
	 */
	public static function groups(): array {
		return array( Group::SITEMAP, Group::PARTNER, Group::READERS, Group::PUSHES );
	}

	/**
	 * The newest delivery row of each channel, for many articles, in one query.
	 *
	 * @param int[] $ids Article IDs.
	 * @return array<int, array<string, array<string, mixed>>> Article => channel => row.
	 */
	private function latest( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( array() === $ids ) {
			return array();
		}

		$table = $this->plugin->log()->table();
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT l.* FROM %i l INNER JOIN ( SELECT MAX( id ) AS id FROM %i WHERE post_id IN ( {$marks} ) GROUP BY post_id, channel ) m ON m.id = l.id", array_merge( array( $table, $table ), $ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %d placeholders are added to the list.
			ARRAY_A
		);
		$out   = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['id']            = (int) $row['id'];
			$row['post_id']       = (int) $row['post_id'];
			$row['attempt']       = (int) $row['attempt'];
			$row['response_code'] = (int) $row['response_code'];
			$row['status']        = Status::from_log( (string) $row['status'] );
			$row['created_ts']    = Clock::from_gmt( (string) $row['created_gmt'] );
			$row['run_at_ts']     = Clock::from_gmt( (string) ( $row['run_at_gmt'] ?? '' ) );

			$out[ $row['post_id'] ][ (string) $row['channel'] ] = $row;
		}

		return $out;
	}

	/**
	 * One article's cell.
	 *
	 * @param \WP_Post                            $post   The article.
	 * @param array<string, array<string, mixed>> $latest Its newest delivery row for each channel.
	 * @return array<string, mixed>
	 */
	private function cell( \WP_Post $post, array $latest ): array {
		$report = $this->plugin->make( ArticleChecker::class )->stored( (int) $post->ID );

		if ( 'publish' !== $post->post_status ) {
			return array(
				'published' => false,
				'line'      => $this->unpublished_line( $report ),
			);
		}

		$language = $this->plugin->languages()->language_of_post( (int) $post->ID );
		$results  = $this->results( $post, $language, $latest, $report );
		$date     = Clock::from_gmt( (string) $post->post_date_gmt );
		$row      = ( new ArticleRows( $this->plugin ) )->build(
			array(
				'id'        => (int) $post->ID,
				'title'     => '',
				'language'  => $language,
				'published' => $date > 0 ? Clock::iso( $date ) : null,
				'edit_link' => '',
				'results'   => $results,
			),
			$latest
		);
		$groups   = array();

		foreach ( self::groups() as $group ) {
			$groups[ $group ] = array(
				'title'  => (string) Group::all()[ $group ]['title'],
				'status' => (string) $row['groups'][ $group ]['status'],
			);
		}

		return array(
			'published' => true,
			'groups'    => $groups,
			'line'      => $this->published_line( $row, $latest, $report ),
		);
	}

	/**
	 * The result of an article on each channel that is on and takes its language.
	 *
	 * The same rules as `Core\ArticleService::results()`, with the delivery rows already read.
	 *
	 * @param \WP_Post                                           $post     The article.
	 * @param string                                             $language Language code.
	 * @param array<string, array<string, mixed>>                $latest   Newest delivery row of each channel.
	 * @param \Estasoft\NewsDistribution\Checks\CheckReport|null $report The stored check.
	 * @return array<string, array{status: string, at: string|null, message: string}>
	 */
	private function results( \WP_Post $post, string $language, array $latest, $report ): array {
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

		/** This filter is documented in Core\ArticleService::results(). */
		$filtered = apply_filters( 'estasoft_nd_internal_article_results', $out, $post, $language );

		return is_array( $filtered ) ? $filtered : $out;
	}

	/**
	 * The message of the first finding that blocks a channel.
	 *
	 * @param \Estasoft\NewsDistribution\Checks\CheckReport $report     The check.
	 * @param string                                        $channel_id Channel id.
	 * @return string
	 */
	private function first_block( $report, string $channel_id ): string {
		foreach ( (array) ( $report->channels()[ $channel_id ]['findings'] ?? array() ) as $finding ) {
			if ( Status::BLOCK === ( $finding['severity'] ?? '' ) ) {
				return (string) $finding['message'];
			}
		}

		return '';
	}

	/**
	 * The line for an article that is not published: how many things the check found.
	 *
	 * @param \Estasoft\NewsDistribution\Checks\CheckReport|null $report The stored check.
	 * @return array{status: string, text: string}
	 */
	private function unpublished_line( $report ): array {
		if ( null === $report ) {
			return array(
				'status' => Status::OFF,
				'text'   => __( 'Not checked yet', 'estasoft-news-distribution' ),
			);
		}

		$counts = $report->counts();
		$fix    = (int) ( $counts[ Status::BLOCK ] ?? 0 ) + (int) ( $counts[ Status::WARN ] ?? 0 );

		if ( 0 === $fix ) {
			return array(
				'status' => Status::CLEAN,
				'text'   => __( 'No problems found', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'status' => Status::ATTENTION,
			'text'   => sprintf(
				/* translators: %s: a number of things the article check found. */
				_n( '%s thing to fix', '%s things to fix', $fix, 'estasoft-news-distribution' ),
				number_format_i18n( $fix )
			),
		);
	}

	/**
	 * The line under the icons of a published article: the worst result in words, or "No problems found".
	 *
	 * @param array<string, mixed>                               $row    The article's row from `ArticleRows`.
	 * @param array<string, array<string, mixed>>                $latest Newest delivery row of each channel.
	 * @param \Estasoft\NewsDistribution\Checks\CheckReport|null $report The stored check.
	 * @return array{status: string, text: string}
	 */
	private function published_line( array $row, array $latest, $report ): array {
		$overall = (array) $row['overall'];
		$status  = (string) $overall['status'];
		$label   = (string) $overall['label'];
		$time    = null === $overall['at'] ? '' : Facts::clock( (int) strtotime( (string) $overall['at'] ) );

		switch ( $status ) {
			case Status::FAILED:
				return array(
					'status' => $status,
					'text'   => sprintf(
						/* translators: %s: a channel name such as Outlets. */
						__( '%s failed', 'estasoft-news-distribution' ),
						$label
					),
				);

			case Status::RETRY:
				return array(
					'status' => $status,
					'text'   => sprintf(
						/* translators: 1: a channel name such as WebSub, 2: a clock time such as 14:37. */
						__( '%1$s retrying, next try %2$s', 'estasoft-news-distribution' ),
						$label,
						$time
					),
				);

			case Status::SKIPPED:
				$reason = null === $report ? '' : $this->reason( $this->first_block_code( $report, (string) $overall['channel'] ) );

				return array(
					'status' => $status,
					'text'   => '' === $reason
						? sprintf(
							/* translators: %s: a channel name such as Microsoft Start. */
							__( '%s skipped', 'estasoft-news-distribution' ),
							$label
						)
						: sprintf(
							/* translators: 1: a channel name such as Microsoft Start, 2: the reason, such as "no featured image". */
							__( '%1$s skipped: %2$s', 'estasoft-news-distribution' ),
							$label,
							$reason
						),
				);

			case Status::QUEUED:
				$names = array();

				foreach ( (array) $overall['waiting_groups'] as $group ) {
					$names[] = (string) Group::all()[ $group ]['title'];
				}

				return array(
					'status' => $status,
					'text'   => sprintf(
						/* translators: 1: group names such as "Pushes", 2: the word waiting and a clock time, such as "waiting 14:38". */
						__( '%1$s %2$s', 'estasoft-news-distribution' ),
						wp_sprintf( '%l', $names ),
						trim( Facts::lower( Status::word( Status::QUEUED ) ) . ' ' . $time )
					),
				);
		}

		$sent = $this->sent_line( $row, $latest );

		if ( '' !== $sent ) {
			return array(
				'status' => Status::SENT,
				'text'   => $sent,
			);
		}

		foreach ( self::groups() as $group ) {
			if ( Status::OFF !== $row['groups'][ $group ]['status'] ) {
				return array(
					'status' => Status::IN_FEED,
					'text'   => __( 'No problems found', 'estasoft-news-distribution' ),
				);
			}
		}

		return array(
			'status' => Status::OFF,
			'text'   => __( 'Not in a feed now', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * "Pushes sent 13:41, outlets 13:56": when the push channels last sent the article.
	 *
	 * @param array<string, mixed>                $row    The article's row from `ArticleRows`.
	 * @param array<string, array<string, mixed>> $latest Newest delivery row of each channel.
	 * @return string Empty when nothing was pushed.
	 */
	private function sent_line( array $row, array $latest ): string {
		$pushes = 0;
		$outlet = 0;

		foreach ( (array) $row['groups'][ Group::PUSHES ]['channels'] as $id => $channel ) {
			if ( Status::SENT !== $channel['status'] || ! isset( $latest[ (string) $id ] ) ) {
				continue;
			}

			$when = (int) $latest[ (string) $id ]['created_ts'];

			if ( 'outlets' === (string) $id ) {
				$outlet = max( $outlet, $when );
			} else {
				$pushes = max( $pushes, $when );
			}
		}

		$parts = array();

		if ( $pushes > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: a clock time such as 13:41. */
				__( 'Pushes sent %s', 'estasoft-news-distribution' ),
				Facts::clock( $pushes )
			);
		}

		if ( $outlet > 0 && array() === $parts ) {
			$parts[] = sprintf(
				/* translators: %s: a clock time such as 13:56. */
				__( 'Outlets sent %s', 'estasoft-news-distribution' ),
				Facts::clock( $outlet )
			);
		} elseif ( $outlet > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: a clock time such as 13:56. */
				__( 'outlets %s', 'estasoft-news-distribution' ),
				Facts::clock( $outlet )
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * The code of the first finding that blocks a channel.
	 *
	 * @param \Estasoft\NewsDistribution\Checks\CheckReport $report     The check.
	 * @param string                                        $channel_id Channel id.
	 * @return string
	 */
	private function first_block_code( $report, string $channel_id ): string {
		foreach ( (array) ( $report->channels()[ $channel_id ]['findings'] ?? array() ) as $finding ) {
			if ( Status::BLOCK === ( $finding['severity'] ?? '' ) ) {
				return (string) $finding['code'];
			}
		}

		return '';
	}

	/**
	 * A few words for why an article is left out, for the codes a person can fix on the article.
	 *
	 * Any other code gets no reason here. The Article check has the full sentence.
	 *
	 * @param string $code A finding code such as image.missing.
	 * @return string Lower case, or an empty string.
	 */
	private function reason( string $code ): string {
		switch ( $code ) {
			case 'image.missing':
			case 'thumbnail.missing':
			case 'item.thumbnail':
			case 'rules.image_missing':
				return __( 'no featured image', 'estasoft-news-distribution' );

			case 'body.empty':
				return __( 'no body text', 'estasoft-news-distribution' );

			case 'body.min_words':
				return __( 'the body is too short', 'estasoft-news-distribution' );

			case 'title.missing':
				return __( 'no title', 'estasoft-news-distribution' );

			case 'link.missing':
			case 'url.missing':
				return __( 'no address', 'estasoft-news-distribution' );

			case 'date.invalid':
				return __( 'the date is not valid', 'estasoft-news-distribution' );
		}

		return '';
	}
}
