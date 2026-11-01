<?php
/**
 * The things on the Overview that need the person.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Digest;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Integrations\ConflictDetector;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the "Needs attention" list from stored results only: the health of the
 * channels, the stored check of the week's articles, the delivery log, the plugin
 * detector and the application tracker. It never calls another server.
 *
 * Each item is `{ id, kind, status, title, detail, channel, action: { label, url } | null }`.
 * `status` is attention or retry (the icon); `kind` is fetch, skipped, push, plugin or reminder.
 */
final class NeedsYou {

	/**
	 * Most skipped-article reasons listed.
	 */
	private const MAX_SKIPPED = 3;

	/**
	 * Most articles whose stored check is read.
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
	 * Every item, in the order the screen prints them.
	 *
	 * @param string $language A language code to look at, or an empty string for all.
	 * @return array<int, array<string, mixed>>
	 */
	public function items( string $language = '' ): array {
		return array_merge(
			$this->fetch_items(),
			$this->skipped_items( $language ),
			$this->push_items(),
			$this->plugin_items(),
			$this->reminder_items()
		);
	}

	/**
	 * A pulled channel that is later than usual or never fetched for a language.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_items(): array {
		$items = array();

		foreach ( $this->ordered( $this->plugin->registry()->pulled() ) as $channel ) {
			if ( ! $channel->is_enabled() || Status::ATTENTION !== $channel->health() ) {
				continue;
			}

			$states = Facts::fetch_states( $this->plugin, $channel );
			$noun   = Group::SITEMAP === $channel->group() ? 'sitemap' : 'feed';
			$late   = array();
			$fine   = array();

			foreach ( $states as $code => $state ) {
				$name = Catalog::name( (string) $code );

				if ( Status::NEVER === $state['status'] ) {
					$late[] = 'sitemap' === $noun
						/* translators: %s: a language name such as Kurmanji. */
						? sprintf( __( 'The %s sitemap has never been fetched.', 'estasoft-news-distribution' ), $name )
						/* translators: %s: a language name such as Kurmanji. */
						: sprintf( __( 'The %s feed has never been fetched.', 'estasoft-news-distribution' ), $name );
				} elseif ( Status::LATE === $state['status'] ) {
					$late[] = 'sitemap' === $noun
						/* translators: 1: a language name, 2: how long ago, such as "26 h ago". */
						? sprintf( __( 'The %1$s sitemap was last fetched %2$s.', 'estasoft-news-distribution' ), $name, Facts::ago( $state['last_ts'] ) )
						/* translators: 1: a language name, 2: how long ago, such as "26 h ago". */
						: sprintf( __( 'The %1$s feed was last fetched %2$s.', 'estasoft-news-distribution' ), $name, Facts::ago( $state['last_ts'] ) );
				} else {
					/* translators: 1: a language name such as English, 2: how long ago, such as "6 min ago". */
					$fine[] = sprintf( __( '%1$s was fetched %2$s.', 'estasoft-news-distribution' ), $name, Facts::ago( $state['last_ts'] ) );
				}
			}

			$count = count( $late );

			if ( 0 === $count ) {
				$rows = $channel->hub_rows();

				$items[] = $this->item(
					'fetch-' . $channel->id(),
					'fetch',
					Status::ATTENTION,
					/* translators: %s: a channel name such as Google News. */
					sprintf( __( '%s needs attention', 'estasoft-news-distribution' ), $channel->label() ),
					array() === $rows ? '' : (string) $rows[0]['summary'],
					$channel
				);
				continue;
			}

			if ( 'sitemap' === $noun ) {
				$title = sprintf(
					/* translators: 1: a channel name such as Google News, 2: a number of languages. */
					_n( '%1$s: %2$d language sitemap needs a look', '%1$s: %2$d language sitemaps need a look', $count, 'estasoft-news-distribution' ),
					$channel->label(),
					$count
				);
			} else {
				$title = sprintf(
					/* translators: 1: a channel name such as Microsoft Start, 2: a number of languages. */
					_n( '%1$s: %2$d language feed needs a look', '%1$s: %2$d language feeds need a look', $count, 'estasoft-news-distribution' ),
					$channel->label(),
					$count
				);
			}

			$detail = implode( ' ', array_merge( $late, $fine ) );

			/* translators: %s: a platform name such as Google News. */
			$detail .= ' ' . sprintf( __( '%s decides when it fetches.', 'estasoft-news-distribution' ), $channel->label() );

			$items[] = $this->item( 'fetch-' . $channel->id(), 'fetch', Status::ATTENTION, $title, $detail, $channel );
		}

		return $items;
	}

	/**
	 * Published articles of this week that a channel skips, grouped by the reason.
	 *
	 * @param string $language A language code, or an empty string for all.
	 * @return array<int, array<string, mixed>>
	 */
	private function skipped_items( string $language ): array {
		$ids = $this->week_ids( $language );

		if ( array() === $ids ) {
			return array();
		}

		update_meta_cache( 'post', $ids );

		$checker = $this->plugin->make( ArticleChecker::class );
		$groups  = array();

		foreach ( $ids as $post_id ) {
			$report = $checker->stored( $post_id );

			if ( null === $report ) {
				continue;
			}

			foreach ( $report->skipping_channels() as $channel_id ) {
				$channel = $this->plugin->registry()->get( $channel_id );
				$entry   = $report->channels()[ $channel_id ] ?? array();

				foreach ( (array) ( $entry['findings'] ?? array() ) as $finding ) {
					if ( Status::BLOCK !== ( $finding['severity'] ?? '' ) ) {
						continue;
					}

					$code = (string) ( $finding['code'] ?? '' );

					if ( ! isset( $groups[ $code ] ) ) {
						$groups[ $code ] = array(
							'code'     => $code,
							'message'  => (string) ( $finding['message'] ?? '' ),
							'channels' => array(),
							'posts'    => array(),
						);
					}

					$groups[ $code ]['channels'][ $channel_id ] = null === $channel ? $channel_id : $channel->label();
					$groups[ $code ]['posts'][ $post_id ]       = true;
				}
			}
		}

		uasort(
			$groups,
			static function ( array $a, array $b ): int {
				$by_count = count( $b['posts'] ) <=> count( $a['posts'] );

				return 0 !== $by_count ? $by_count : strcmp( $a['code'], $b['code'] );
			}
		);

		$items = array();

		foreach ( array_slice( $groups, 0, self::MAX_SKIPPED ) as $group ) {
			$count = count( $group['posts'] );

			$items[] = $this->item(
				'skipped-' . sanitize_key( $group['code'] ),
				'skipped',
				Status::ATTENTION,
				sprintf(
					/* translators: 1: a number of articles, 2: channel names such as "Microsoft Start". */
					_n( '%1$d article is skipped for %2$s', '%1$d articles are skipped for %2$s', $count, 'estasoft-news-distribution' ),
					$count,
					wp_sprintf( '%l', array_values( $group['channels'] ) )
				),
				$group['message'],
				null,
				array(
					'label' => __( 'Review articles', 'estasoft-news-distribution' ),
					'url'   => Links::check(),
				)
			);
		}

		return $items;
	}

	/**
	 * The IDs of the articles published since the start of this week, newest first.
	 *
	 * @param string $language A language code, or an empty string for all.
	 * @return int[]
	 */
	private function week_ids( string $language ): array {
		$digest = $this->plugin->make( Digest::class );
		$query  = array(
			'post_type'              => $this->plugin->settings()->post_types(),
			'post_status'            => 'publish',
			'posts_per_page'         => self::WINDOW,
			'fields'                 => 'ids',
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
			'date_query'             => array(
				array(
					'column'    => 'post_date_gmt',
					'after'     => Clock::to_gmt( $digest->week_start( Clock::now() ) ),
					'before'    => Clock::to_gmt( Clock::now() ),
					'inclusive' => true,
				),
			),
		);

		if ( $this->plugin->access()->sees_only_own() ) {
			$query['author'] = get_current_user_id();
		}

		$all = '' === $language;

		if ( $all ) {
			$query['lang'] = '';
		} else {
			$query = array_merge( $query, $this->plugin->languages()->query_args( $language ) );
		}

		$run = static function () use ( $query ): array {
			$posts = new \WP_Query( $query );

			return (array) $posts->posts;
		};

		return array_map( 'intval', $all ? $run() : (array) $this->plugin->languages()->with_language( $language, $run ) );
	}

	/**
	 * A push that is being retried, or that gave up, in the last 24 hours. One item per channel.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function push_items(): array {
		$log   = $this->plugin->log();
		$since = Clock::now() - DAY_IN_SECONDS;
		$found = array();

		$retrying = $log->query(
			array(
				'status'      => Status::RETRY,
				'since'       => $since,
				'latest_only' => true,
				'per_page'    => 50,
			)
		)['rows'];

		$failed = $log->query(
			array(
				'status'   => Status::FAILED,
				'since'    => $since,
				'per_page' => 50,
			)
		)['rows'];

		foreach ( $failed as $row ) {
			if ( ! $log->has_later( (int) $row['id'] ) ) {
				$found[ (string) $row['channel'] ]['failed'][] = $row;
			}
		}

		foreach ( $retrying as $row ) {
			$found[ (string) $row['channel'] ]['retry'][] = $row;
		}

		$items = array();

		foreach ( $this->ordered( $this->plugin->registry()->pushed() ) as $channel ) {
			if ( ! $channel->is_enabled() || ! isset( $found[ $channel->id() ] ) ) {
				continue;
			}

			$rows = $found[ $channel->id() ];

			if ( ! empty( $rows['failed'] ) ) {
				$items[] = $this->failed_item( $channel, $rows['failed'] );
			} elseif ( ! empty( $rows['retry'] ) ) {
				$items[] = $this->retry_item( $channel, $rows['retry'] );
			}
		}

		return $items;
	}

	/**
	 * The item for a pushed channel whose delivery gave up.
	 *
	 * @param ChannelInterface                 $channel The channel.
	 * @param array<int, array<string, mixed>> $rows    Its failed rows, newest first.
	 * @return array<string, mixed>
	 */
	private function failed_item( ChannelInterface $channel, array $rows ): array {
		$count = count( $rows );
		$title = 'websub' === $channel->id()
			? sprintf(
				/* translators: %d: a number of WebSub pings. */
				_n( '%d WebSub ping failed', '%d WebSub pings failed', $count, 'estasoft-news-distribution' ),
				$count
			)
			: sprintf(
				/* translators: 1: a number of deliveries, 2: a channel name such as Outlets. */
				_n( '%1$d delivery to %2$s failed', '%1$d deliveries to %2$s failed', $count, 'estasoft-news-distribution' ),
				$count,
				$channel->label()
			);

		$detail = trim( (string) $rows[0]['message'] . ' ' . __( 'Retries have stopped.', 'estasoft-news-distribution' ) );

		foreach ( $this->extra_notes( $channel ) as $note ) {
			$detail .= ' ' . $note;
		}

		return $this->item( 'push-' . $channel->id(), 'push', Status::ATTENTION, $title, $detail, $channel );
	}

	/**
	 * The item for a pushed channel that is trying again.
	 *
	 * @param ChannelInterface                 $channel The channel.
	 * @param array<int, array<string, mixed>> $rows    Its retrying rows, newest first.
	 * @return array<string, mixed>
	 */
	private function retry_item( ChannelInterface $channel, array $rows ): array {
		$count = count( $rows );
		$title = 'websub' === $channel->id()
			? sprintf(
				/* translators: %d: a number of WebSub pings. */
				_n( '%d WebSub ping failed and is retrying', '%d WebSub pings failed and are retrying', $count, 'estasoft-news-distribution' ),
				$count
			)
			: sprintf(
				/* translators: 1: a number of deliveries, 2: a channel name such as Outlets. */
				_n( '%1$d delivery to %2$s failed and is retrying', '%1$d deliveries to %2$s failed and are retrying', $count, 'estasoft-news-distribution' ),
				$count,
				$channel->label()
			);

		$row    = $rows[0];
		$detail = sprintf(
			/* translators: 1: the attempt that just failed, 2: the most attempts, 3: a clock time such as 14:37. */
			__( 'Attempt %1$d of %2$d, next try %3$s.', 'estasoft-news-distribution' ),
			(int) $row['attempt'],
			Retry::MAX_ATTEMPTS,
			Facts::clock( (int) $row['run_at_ts'] )
		);

		// The stored message already ends with the time of the next try, which the sentence above says.
		$message = trim( (string) preg_replace( '/\s*Trying again at [0-9:]+\.?$/', '', (string) $row['message'] ) );

		if ( '' !== $message ) {
			$detail .= ' ' . $message;
		}

		if ( 1 === $count && (int) $row['post_id'] > 0 ) {
			$detail .= ' ' . sprintf(
				/* translators: %s: an article title. */
				__( 'It is for “%s”.', 'estasoft-news-distribution' ),
				wp_strip_all_tags( get_the_title( (int) $row['post_id'] ) )
			);
		}

		return $this->item(
			'push-' . $channel->id(),
			'push',
			Status::RETRY,
			$title,
			$detail,
			$channel,
			array(
				'label' => __( 'Open delivery log', 'estasoft-news-distribution' ),
				'url'   => Links::delivery_log(),
			)
		);
	}

	/**
	 * Extra sentences a channel offers about what needs the person, when it offers any.
	 *
	 * Outlets lists the stories that wait to be imported by hand. A channel without
	 * the method adds nothing.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return string[]
	 */
	private function extra_notes( ChannelInterface $channel ): array {
		if ( ! method_exists( $channel, 'attention' ) ) {
			return array();
		}

		$notes = array();

		foreach ( (array) $channel->attention() as $item ) {
			if ( isset( $item['key'], $item['text'] ) && 'medium' === $item['key'] ) {
				$notes[] = (string) $item['text'];
			}
		}

		return $notes;
	}

	/**
	 * Another plugin that prints the same news sitemap.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function plugin_items(): array {
		$found = ( new ConflictDetector( $this->plugin ) )->detect();
		$items = array();

		foreach ( (array) $found['rows'] as $row ) {
			if ( Status::CONFLICT !== ( $row['status'] ?? '' ) || 'news' !== ( $row['kind'] ?? '' ) ) {
				continue;
			}

			$manage = $this->plugin->access()->can( Access::MANAGE );
			$action = null;

			if ( $manage && ! empty( $row['action']['url'] ) ) {
				$action = array(
					'label' => (string) $row['action']['label'],
					'url'   => (string) $row['action']['url'],
				);
			}

			if ( 'gn-publisher' === $row['id'] ) {
				$title = sprintf(
					/* translators: %s: the name of another plugin, such as GN Publisher. */
					__( 'The %s plugin also outputs a Google News sitemap', 'estasoft-news-distribution' ),
					(string) $row['name']
				);
				$detail = __( 'Google may see 2 sitemaps with different rules. The guide shows how to switch, and the old sitemap address keeps answering.', 'estasoft-news-distribution' );
			} else {
				$title = sprintf(
					/* translators: %s: the name of another plugin. */
					__( '%s may also output a Google News sitemap', 'estasoft-news-distribution' ),
					(string) $row['name']
				);
				$detail = trim( (string) $row['result'] . ' ' . (string) $row['todo'] );
			}

			$items[] = $this->item( 'plugin-' . sanitize_key( (string) $row['id'] ), 'plugin', Status::ATTENTION, $title, $detail, null, $action );
		}

		return $items;
	}

	/**
	 * An application whose reminder date has passed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function reminder_items(): array {
		$items        = array();
		$applications = $this->plugin->applications();

		foreach ( Applications::platforms() as $platform => $name ) {
			if ( ! $applications->is_overdue( (string) $platform ) ) {
				continue;
			}

			$row     = $applications->get( (string) $platform );
			$channel = $this->plugin->registry()->get( (string) $platform );
			$date    = wp_date( 'j M', (int) strtotime( $row['reminder_on'] . ' 12:00:00' ), Clock::zone() );

			$items[] = $this->item(
				'reminder-' . sanitize_key( (string) $platform ),
				'reminder',
				Status::ATTENTION,
				sprintf(
					/* translators: %s: a platform name such as Apple News. */
					__( '%s: your reminder date has passed', 'estasoft-news-distribution' ),
					(string) $name
				),
				sprintf(
					/* translators: 1: a date such as "28 Oct", 2: a status such as "In review". */
					__( 'You asked to be reminded on %1$s. The status you set is %2$s. News Distribution cannot see the answer, so check with the platform.', 'estasoft-news-distribution' ),
					$date,
					Status::word( $row['status'] )
				),
				$channel,
				array(
					'label' => sprintf(
						/* translators: %s: a platform name such as Apple News. */
						__( 'Open %s', 'estasoft-news-distribution' ),
						(string) $name
					),
					'url'   => Facts::channel_url( $this->plugin, null === $channel ? 'listings' : $channel->id() ),
				)
			);
		}

		return $items;
	}

	/**
	 * Channels in the order of the Channels hub: by group, then by their own order.
	 *
	 * @param ChannelInterface[] $channels Channels.
	 * @return ChannelInterface[]
	 */
	private function ordered( array $channels ): array {
		$groups = array_flip( array_keys( Group::all() ) );

		usort(
			$channels,
			static function ( ChannelInterface $a, ChannelInterface $b ) use ( $groups ): int {
				return array( $groups[ $a->group() ] ?? 99, $a->order() ) <=> array( $groups[ $b->group() ] ?? 99, $b->order() );
			}
		);

		return array_values( $channels );
	}

	/**
	 * One item in the shape the screen and the route share.
	 *
	 * @param string                                       $id      Stable id.
	 * @param string                                       $kind    fetch, skipped, push, plugin or reminder.
	 * @param string                                       $status  attention or retry (picks the icon).
	 * @param string                                       $title   One plain sentence.
	 * @param string                                       $detail  What it means and what to do.
	 * @param ChannelInterface|null                        $channel The channel it is about, when there is one.
	 * @param array{label: string, url: string}|null|false $action  The button, or false to open the channel page.
	 * @return array<string, mixed>
	 */
	private function item( string $id, string $kind, string $status, string $title, string $detail, ?ChannelInterface $channel, $action = false ): array {
		if ( false === $action ) {
			$action = null === $channel ? null : array(
				'label' => sprintf(
					/* translators: %s: a channel name such as Google News. */
					__( 'Open %s', 'estasoft-news-distribution' ),
					$channel->label()
				),
				'url'   => Facts::channel_url( $this->plugin, $channel->id() ),
			);
		}

		return array(
			'id'      => $id,
			'kind'    => $kind,
			'status'  => $status,
			'title'   => $title,
			'detail'  => trim( $detail ),
			'channel' => null === $channel ? '' : $channel->id(),
			'action'  => $action,
		);
	}
}
