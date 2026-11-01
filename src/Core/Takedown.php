<?php
/**
 * Take an article down from every channel, and show each channel's step.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A takedown does what News Distribution can do, then says plainly what it
 * could not:
 *
 * - Feeds and sitemaps that News Distribution builds drop the article at once
 *   (the feed builder asks `is_down()`), and the cached documents are cleared.
 *   Step: Removed.
 * - Pushed channels get an unpublish event through the queue's own delivery
 *   code, now, so the answer is known. Step: Sent when the channel accepted it,
 *   Needs attention when it did not (a failed push is also tried again by the
 *   queue, which the step says).
 * - Anything News Distribution cannot do (a story imported into Medium by hand,
 *   a platform that decides when it drops an article) is written as an
 *   instruction. It never says an article is gone from a platform it cannot see.
 *
 * The state is the post meta `_estasoft_nd_takedown`. A takedown that was only
 * recorded in the audit table (older data, the sample world) still counts.
 *
 * The post itself stays published until a person switches it to draft.
 *
 * Get it with `Plugin::instance()->make( Takedown::class )`.
 */
final class Takedown {

	public const META = '_estasoft_nd_takedown';

	/**
	 * Steps. These are Core\Status codes: Removed, Sent and Needs attention.
	 */
	public const REMOVED   = Status::REMOVED;
	public const SENT      = Status::SENT;
	public const ATTENTION = Status::ATTENTION;

	/**
	 * Channels that share one row, as the screen shows them.
	 *
	 * @var array<string, array{label: string, channels: string[]}>
	 */
	private const ROW_GROUPS = array(
		'search-sitemaps' => array(
			'label'    => 'Bing, Yandex and Baidu',
			'channels' => array( 'bing', 'yandex', 'baidu' ),
		),
		'readers'         => array(
			'label'    => 'Flipboard and feed readers',
			'channels' => array( 'flipboard', 'feed-readers' ),
		),
	);

	private Plugin $plugin;

	/**
	 * Takedowns found in the audit table for a batch of articles, while a feed is filtered.
	 *
	 * @var array<int, bool>|null
	 */
	private ?array $primed = null;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	// ---------------------------------------------------------------------
	// State.
	// ---------------------------------------------------------------------

	/**
	 * The takedown of an article, or null when it never had one.
	 *
	 * @param int $post_id Article ID.
	 * @return array{status: string, started: int, by: int, restored: int}|null status is `down` or `restored`.
	 */
	public function state( int $post_id ): ?array {
		$meta = get_post_meta( $post_id, self::META, true );

		if ( is_array( $meta ) && in_array( $meta['status'] ?? '', array( 'down', 'restored' ), true ) ) {
			return array(
				'status'   => (string) $meta['status'],
				'started'  => (int) ( $meta['started'] ?? 0 ),
				'by'       => (int) ( $meta['by'] ?? 0 ),
				'restored' => (int) ( $meta['restored'] ?? 0 ),
			);
		}

		$row = $this->audited( $post_id );

		if ( null === $row || self::is_restore_row( $row ) ) {
			return null;
		}

		return array(
			'status'   => 'down',
			'started'  => (int) $row['created_ts'],
			'by'       => (int) $row['user_id'],
			'restored' => 0,
		);
	}

	/**
	 * Whether the article is taken down now.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	public function is_down( int $post_id ): bool {
		$state = $this->state( $post_id );

		return null !== $state && 'down' === $state['status'];
	}

	/**
	 * Looks up the takedowns recorded in the audit table for a batch of articles with one query.
	 * Until release_prime() is called, is_down() answers the ones without post meta from this.
	 *
	 * @param int[] $post_ids Article IDs.
	 * @return void
	 */
	public function prime( array $post_ids ): void {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );

		$this->primed = array();

		if ( array() === $ids ) {
			return;
		}

		$table = $this->plugin->audit()->table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id, post_id, user_id, action, created_gmt FROM %i WHERE action IN ( %s, %s ) AND post_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY id ASC', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Only %d placeholders are added to the list.
				array_merge( array( $table, Audit::TAKEDOWN, Audit::RESTORE ), $ids )
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$this->primed[ (int) $row['post_id'] ] = $row;
		}
	}

	/**
	 * Stops answering from the batch looked up by prime().
	 *
	 * @return void
	 */
	public function release_prime(): void {
		$this->primed = null;
	}

	// ---------------------------------------------------------------------
	// Actions.
	// ---------------------------------------------------------------------

	/**
	 * Takes the article down. Called again while it is down, it tries the steps that failed once more.
	 *
	 * @param int      $post_id Article ID.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error What `read()` returns.
	 */
	public function start( int $post_id, ?int $user_id = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'publish' !== $post->post_status && ! $this->is_down( $post_id ) ) {
			return $this->fail( 'estasoft_nd_takedown_not_published', __( 'Only a published article can be taken down.', 'estasoft-news-distribution' ), 409 );
		}

		$user_id  = null === $user_id ? get_current_user_id() : $user_id;
		$language = $this->plugin->languages()->language_of_post( $post_id );
		$again    = $this->is_down( $post_id );

		if ( ! $again ) {
			Removed::remember(
				$post_id,
				array(
					'url'      => (string) get_permalink( $post ),
					'title'    => wp_strip_all_tags( get_the_title( $post ) ),
					'language' => $language,
				)
			);

			update_post_meta(
				$post_id,
				self::META,
				array(
					'status'   => 'down',
					'started'  => Clock::now(),
					'by'       => $user_id,
					'restored' => 0,
				)
			);

			$this->plugin->queue()->cancel_for_article( $post_id, array( Event::PUBLISH, Event::UPDATE ) );
			$this->plugin->audit()->record(
				Audit::TAKEDOWN,
				$post_id,
				sprintf(
					/* translators: %s: the article title. */
					__( 'Takedown started for %s.', 'estasoft-news-distribution' ),
					wp_strip_all_tags( get_the_title( $post ) )
				),
				$user_id
			);

			/**
			 * Fires when a takedown starts, after the article is out of every feed and sitemap News Distribution builds.
			 *
			 * @param int   $post_id Article ID.
			 * @param Event $event   An unpublish event for the article.
			 */
			do_action( 'estasoft_nd_takedown_started', $post_id, new Event( Event::UNPUBLISH, $post_id, $language, null, Removed::recall( $post_id ) ) );
		}

		$this->plugin->cache()->flush();
		$this->send_notices( $post_id, $again );

		$read = $this->read( $post_id );

		if ( ! $again ) {
			$this->tell( $post_id, $user_id, $read );
		}

		return $read;
	}

	/**
	 * Puts the article back in the feeds and sitemaps. It cannot take back what was sent.
	 *
	 * @param int      $post_id Article ID.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error What `read()` returns.
	 */
	public function restore( int $post_id, ?int $user_id = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$state = $this->state( $post_id );

		if ( null === $state || 'down' !== $state['status'] ) {
			return $this->fail( 'estasoft_nd_not_down', __( 'This article is not taken down.', 'estasoft-news-distribution' ), 409 );
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		update_post_meta(
			$post_id,
			self::META,
			array(
				'status'   => 'restored',
				'started'  => $state['started'],
				'by'       => $state['by'],
				'restored' => Clock::now(),
			)
		);

		$this->plugin->audit()->record( Audit::RESTORE, $post_id, __( 'Takedown undone. The article is back in your feeds and sitemaps. Notices already sent cannot be taken back.', 'estasoft-news-distribution' ), $user_id );

		/**
		 * Fires when a takedown is undone and the article is back in the feeds and sitemaps.
		 *
		 * @param int   $post_id Article ID.
		 * @param Event $event   A publish event for the article.
		 */
		do_action( 'estasoft_nd_takedown_restored', $post_id, new Event( Event::PUBLISH, $post_id, $this->plugin->languages()->language_of_post( $post_id ) ) );

		$this->plugin->cache()->flush();

		return $this->read( $post_id );
	}

	/**
	 * Switches the post to draft, so readers no longer see it.
	 *
	 * @param int      $post_id Article ID.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error What `read()` returns.
	 */
	public function draft( int $post_id, ?int $user_id = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'draft' === $post->post_status ) {
			return $this->read( $post_id );
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;
		$result  = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $this->fail( 'estasoft_nd_draft_failed', __( 'The post could not be switched to draft.', 'estasoft-news-distribution' ), 500 );
		}

		$this->plugin->audit()->record( Audit::TAKEDOWN, $post_id, __( 'The post was switched to draft.', 'estasoft-news-distribution' ), $user_id );

		return $this->read( $post_id );
	}

	// ---------------------------------------------------------------------
	// What the screens and routes read.
	// ---------------------------------------------------------------------

	/**
	 * The takedown as the screen and the route show it.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	public function read( int $post_id ): array {
		$post  = get_post( $post_id );
		$state = $this->state( $post_id );
		$steps = $this->steps( $post_id );
		$count = array(
			self::REMOVED   => 0,
			self::SENT      => 0,
			self::ATTENTION => 0,
		);
		$names = array();

		foreach ( $steps as $step ) {
			if ( isset( $count[ $step['status'] ] ) ) {
				++$count[ $step['status'] ];
			}

			if ( self::ATTENTION === $step['status'] ) {
				$names[] = $step['label'];
			}
		}

		$status = null === $state ? 'none' : $state['status'];
		$by     = null === $state ? 0 : $state['by'];
		$health = '';

		if ( 'down' === $status ) {
			$health = 0 === $count[ self::ATTENTION ] ? self::REMOVED : self::ATTENTION;
		}

		return array(
			'post_id'     => $post_id,
			'title'       => $post instanceof \WP_Post ? wp_strip_all_tags( get_the_title( $post ) ) : '',
			'post_status' => $post instanceof \WP_Post ? (string) $post->post_status : '',
			'published'   => $post instanceof \WP_Post ? $this->iso( Clock::from_gmt( (string) $post->post_date_gmt ) ) : null,
			'status'      => $status,
			'started'     => null === $state ? null : $this->iso( $state['started'] ),
			'started_ts'  => null === $state ? 0 : $state['started'],
			'by'          => $this->person( $by ),
			'restored'    => null === $state || 0 === $state['restored'] ? null : $this->iso( $state['restored'] ),
			'steps'       => array_map(
				function ( array $step ): array {
					$step['at'] = $this->iso( (int) $step['at'] );

					return $step;
				},
				$steps
			),
			'summary'     => array(
				'removed'   => $count[ self::REMOVED ],
				'sent'      => $count[ self::SENT ],
				'attention' => $count[ self::ATTENTION ],
				'needs'     => $names,
			),
			'health'      => $health,
		);
	}

	/**
	 * One step for each channel (some share a row), in the order of the Channels hub.
	 *
	 * Before a takedown starts the steps say what would happen, with an empty status. After it
	 * starts each step is a Core\Status code: Removed, Sent or Needs attention.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, array{id: string, label: string, channels: string[], status: string, message: string, at: int, link: array{label: string, url: string}|null, blocking: bool}>
	 */
	public function steps( int $post_id ): array {
		$state    = $this->state( $post_id );
		$language = $this->plugin->languages()->language_of_post( $post_id );
		$started  = null === $state ? 0 : $state['started'];
		$down     = null !== $state && 'down' === $state['status'];
		$latest   = $down ? $this->latest_notices( $post_id, $started ) : array();
		$rows     = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			if ( ! $channel->is_enabled() || ! $this->has_articles( $channel ) ) {
				continue;
			}

			$follow = $this->follow_up( $channel, $post_id );

			if ( ChannelInterface::MODE_MANUAL === $channel->mode() ) {
				$rows[ $channel->id() ] = $this->row( $channel->id(), $channel->label(), array( $channel->id() ), $down ? self::ATTENTION : '', null === $follow ? '' : $follow['message'], $started, $follow, true );
				continue;
			}

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			if ( ChannelInterface::MODE_PULLED === $channel->mode() ) {
				$rows[ $channel->id() ] = $this->pulled_row( $channel, $down, $started, $follow );
				continue;
			}

			$rows[ $channel->id() ] = $this->pushed_row( $channel, $down, $latest[ $channel->id() ] ?? null, $follow );
		}

		return $this->grouped( $rows );
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * Runs the queue's own delivery for an unpublish event, now, on each pushed channel.
	 *
	 * @param int  $post_id Article ID.
	 * @param bool $again   True when the takedown was already started: only channels whose step is not Sent are tried.
	 * @return void
	 */
	private function send_notices( int $post_id, bool $again ): void {
		$dispatcher = new Dispatcher( $this->plugin->registry(), $this->plugin->reader(), $this->plugin->queue(), $this->plugin->log() );
		$language   = $this->plugin->languages()->language_of_post( $post_id );
		$state      = $this->state( $post_id );
		$latest     = $again && null !== $state ? $this->latest_notices( $post_id, $state['started'] ) : array();

		foreach ( $this->plugin->registry()->pushed() as $channel ) {
			if ( ! $channel->is_enabled() || ! $channel->accepts_language( $language ) ) {
				continue;
			}

			if ( $again && isset( $latest[ $channel->id() ] ) && Status::SENT === $latest[ $channel->id() ]['status'] ) {
				continue;
			}

			$dispatcher->handle( $channel->id(), $post_id, Event::UNPUBLISH, 1 );
		}
	}

	/**
	 * Tells the person who started the takedown what is left for them, by email, when there is something.
	 *
	 * @param int                  $post_id Article ID.
	 * @param int                  $user_id Person.
	 * @param array<string, mixed> $read    The takedown as read().
	 * @return void
	 */
	private function tell( int $post_id, int $user_id, array $read ): void {
		$todo = array();
		$done = array();

		foreach ( $read['steps'] as $step ) {
			if ( self::ATTENTION === $step['status'] ) {
				$todo[] = $step['label'] . ': ' . $step['message'];
			} elseif ( '' !== $step['status'] ) {
				$done[] = $step['label'];
			}
		}

		if ( array() === $todo ) {
			return;
		}

		$context = array(
			'article_id' => $post_id,
			'title'      => (string) $read['title'],
			'by'         => $user_id,
			'done'       => implode( ', ', $done ),
			'todo'       => $todo,
		);
		$alerts  = $this->plugin->make( Alerts::class );

		$alerts->send( Alerts::TAKEDOWN, $context );
		$alerts->schedule_reminder( Alerts::TAKEDOWN, $context, DAY_IN_SECONDS );
	}

	/**
	 * The latest unpublish row of each channel since a time.
	 *
	 * @param int $post_id Article ID.
	 * @param int $since   Unix time the takedown started.
	 * @return array<string, array<string, mixed>> Channel id => delivery log row.
	 */
	private function latest_notices( int $post_id, int $since ): array {
		$rows   = $this->plugin->log()->query(
			array(
				'post'     => $post_id,
				'since'    => max( 0, $since - 5 ),
				'per_page' => 100,
			)
		)['rows'];
		$latest = array();

		foreach ( $rows as $row ) {
			if ( Event::UNPUBLISH !== $row['event'] || isset( $latest[ $row['channel'] ] ) ) {
				continue;
			}

			$latest[ (string) $row['channel'] ] = $row;
		}

		return $latest;
	}

	/**
	 * The step of a channel that serves a document: the article is out of it.
	 *
	 * @param ChannelInterface                                                        $channel The channel.
	 * @param bool                                                                    $down    Whether the takedown has started.
	 * @param int                                                                     $started Unix time it started.
	 * @param array{message: string, label: string, url: string, blocking: bool}|null $follow  What a person has to do, if anything.
	 * @return array<string, mixed>
	 */
	private function pulled_row( ChannelInterface $channel, bool $down, int $started, ?array $follow ): array {
		$time    = $down ? Clock::format( $started ) : '';
		$message = $this->pulled_message( $channel, $time, $down );

		if ( ! $down ) {
			return $this->row( $channel->id(), $channel->label(), array( $channel->id() ), '', $message, 0, $follow, false );
		}

		$status = null !== $follow && $follow['blocking'] ? self::ATTENTION : self::REMOVED;

		return $this->row( $channel->id(), $channel->label(), array( $channel->id() ), $status, $message, $started, $follow, null !== $follow && $follow['blocking'] );
	}

	/**
	 * The step of a pushed channel: what the removal notice did.
	 *
	 * @param ChannelInterface                                                        $channel The channel.
	 * @param bool                                                                    $down    Whether the takedown has started.
	 * @param array<string, mixed>|null                                               $row     The channel's latest unpublish row since the takedown started.
	 * @param array{message: string, label: string, url: string, blocking: bool}|null $follow  What a person has to do, if anything.
	 * @return array<string, mixed>
	 */
	private function pushed_row( ChannelInterface $channel, bool $down, ?array $row, ?array $follow ): array {
		if ( ! $down ) {
			return $this->row( $channel->id(), $channel->label(), array( $channel->id() ), '', __( 'A removal notice is sent when you take the article down.', 'estasoft-news-distribution' ), 0, $follow, false );
		}

		$blocking = null !== $follow && $follow['blocking'];

		if ( null === $row ) {
			return $this->row( $channel->id(), $channel->label(), array( $channel->id() ), self::ATTENTION, __( 'No removal notice has been sent yet.', 'estasoft-news-distribution' ), 0, $follow, true );
		}

		$at = (int) $row['created_ts'];

		if ( Status::SENT === $row['status'] ) {
			$text = $this->sent_message( $channel, $row );

			return $this->row( $channel->id(), $channel->label(), array( $channel->id() ), $blocking ? self::ATTENTION : self::SENT, $text, $at, $follow, $blocking );
		}

		$text = trim( (string) $row['message'] );

		if ( Status::RETRY === $row['status'] || Status::QUEUED === $row['status'] ) {
			$text = '' !== $text ? $text : __( 'The notice did not go through. It is tried again.', 'estasoft-news-distribution' );
		} elseif ( Status::FAILED === $row['status'] ) {
			$text = '' !== $text ? $text : __( 'The notice did not go through.', 'estasoft-news-distribution' );
		} elseif ( '' === $text ) {
			$text = __( 'No notice was sent.', 'estasoft-news-distribution' );
		}

		return $this->row( $channel->id(), $channel->label(), array( $channel->id() ), self::ATTENTION, $text, $at, $follow, true );
	}

	/**
	 * What a pushed channel says about a removal notice that went through.
	 *
	 * @param ChannelInterface     $channel The channel.
	 * @param array<string, mixed> $row     The delivery log row.
	 * @return string
	 */
	private function sent_message( ChannelInterface $channel, array $row ): string {
		$time = Clock::format( (int) $row['created_ts'] );
		$code = (int) $row['response_code'];
		$own  = trim( (string) $row['message'] );

		switch ( $channel->id() ) {
			case 'indexnow':
				return sprintf(
					/* translators: 1: a time such as 10:41, 2: an HTTP status such as 200. */
					__( 'Removal notice sent at %1$s, and IndexNow answered %2$d. It passes the notice to the search engines that take part.', 'estasoft-news-distribution' ),
					$time,
					$code > 0 ? $code : 200
				);

			case 'websub':
				return sprintf(
					/* translators: %s: a time such as 10:41. */
					__( 'Hub pinged at %s so subscribers fetch the updated feed.', 'estasoft-news-distribution' ),
					$time
				);
		}

		$lead = sprintf(
			/* translators: %s: a time such as 10:41. */
			__( 'Removal notice sent at %s.', 'estasoft-news-distribution' ),
			$time
		);

		return '' === $own ? $lead : $lead . ' ' . $own;
	}

	/**
	 * What a channel that serves a document says after the article was left out of it.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param string           $time    The time the takedown started, or an empty string before it starts.
	 * @param bool             $down    Whether the takedown has started.
	 * @return string
	 */
	private function pulled_message( ChannelInterface $channel, string $time, bool $down ): string {
		$id = $channel->id();

		if ( ! $down ) {
			return Group::SITEMAP === $channel->group()
				? __( 'The article leaves the sitemap at once.', 'estasoft-news-distribution' )
				: __( 'The article leaves the feed at once.', 'estasoft-news-distribution' );
		}

		switch ( $id ) {
			case 'google-news':
				return sprintf(
					/* translators: %s: a time such as 10:40. */
					__( 'Removed from the news sitemap at %s. Google decides when it drops the article from Google News, which can take up to a few days. To hide the address from Google Search sooner, use the Removals tool in Search Console.', 'estasoft-news-distribution' ),
					$time
				);

			case 'bing':
			case 'yandex':
			case 'baidu':
				return sprintf(
					/* translators: %s: a time such as 10:40. */
					__( 'Removed from the search sitemap at %s. Each engine decides when it drops the article.', 'estasoft-news-distribution' ),
					$time
				);

			case 'microsoft-start':
				return sprintf(
					/* translators: %s: a time such as 10:40. */
					__( 'Removed from the feed at %s. Microsoft decides when it drops the article.', 'estasoft-news-distribution' ),
					$time
				);

			case 'yahoo-news':
				return sprintf(
					/* translators: %s: a time such as 10:40. */
					__( 'Removed from the feed at %s. Yahoo decides when it drops the article.', 'estasoft-news-distribution' ),
					$time
				);

			case 'smartnews':
				return __( 'Removed from the feed. SmartNews decides when it drops the article.', 'estasoft-news-distribution' );

			case 'apple-news':
				return $this->plugin->make( Hold::class )->in_review( $channel )
					? __( 'Removed from the feed. Apple News is In review and has never fetched your feed, so nothing was published there and there is nothing to delete in News Publisher.', 'estasoft-news-distribution' )
					: __( 'Removed from the feed. Apple News decides when it drops the article.', 'estasoft-news-distribution' );

			case 'flipboard':
			case 'feed-readers':
				return __( 'Removed from the feed. A reader that already fetched the article may keep its own copy.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: 1: a channel name, 2: a time such as 10:40. */
			__( 'Removed from the %1$s output at %2$s. The platform decides when it drops the article.', 'estasoft-news-distribution' ),
			$channel->label(),
			$time
		);
	}

	/**
	 * What News Distribution cannot do for a channel, and what a person can do instead.
	 *
	 * Blocking means the takedown is not finished until the person has done it.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param int              $post_id Article ID.
	 * @return array{message: string, label: string, url: string, blocking: bool}|null
	 */
	private function follow_up( ChannelInterface $channel, int $post_id ): ?array {
		unset( $post_id );

		switch ( $channel->id() ) {
			case 'google-news':
				return array(
					'message'  => '',
					'label'    => __( 'Open Search Console', 'estasoft-news-distribution' ),
					'url'      => 'https://search.google.com/search-console/removals',
					'blocking' => false,
				);

			case 'outlets':
				return $this->outlets_follow_up( $channel );

			case 'news-break':
				return array(
					'message'  => __( 'News Distribution sends nothing to News Break, so it cannot remove the article there. If you gave the article to News Break by hand, ask them to remove it.', 'estasoft-news-distribution' ),
					'label'    => '',
					'url'      => '',
					'blocking' => true,
				);
		}

		return null;
	}

	/**
	 * Outlets: a story imported into Medium by hand cannot be deleted from here, and neither can a post already made elsewhere.
	 *
	 * @param ChannelInterface $channel The Outlets channel.
	 * @return array{message: string, label: string, url: string, blocking: bool}|null
	 */
	private function outlets_follow_up( ChannelInterface $channel ): ?array {
		if ( ! $channel instanceof OutletsChannel ) {
			return null;
		}

		$on = $channel->active_ids();

		if ( in_array( 'medium', $on, true ) ) {
			$text = __( 'If you imported this story into Medium, delete it there: News Distribution cannot delete it for you.', 'estasoft-news-distribution' );

			if ( array() !== array_intersect( array( 'telegram', 'mastodon', 'bluesky' ), $on ) ) {
				$text .= ' ' . __( 'Posts already made on Telegram, Mastodon or Bluesky stay there until you delete them.', 'estasoft-news-distribution' );
			}

			return array(
				'message'  => $text,
				'label'    => __( 'Open Medium', 'estasoft-news-distribution' ),
				'url'      => 'https://medium.com/me/stories/public',
				'blocking' => true,
			);
		}

		if ( array() !== array_intersect( array( 'telegram', 'mastodon', 'bluesky' ), $on ) ) {
			return array(
				'message'  => __( 'Posts already made on Telegram, Mastodon or Bluesky stay there until you delete them.', 'estasoft-news-distribution' ),
				'label'    => '',
				'url'      => '',
				'blocking' => true,
			);
		}

		return null;
	}

	/**
	 * One row of the steps.
	 *
	 * @param string                                                                  $id       Row id.
	 * @param string                                                                  $label    Row name.
	 * @param string[]                                                                $channels Channel ids in the row.
	 * @param string                                                                  $status   Step status, or an empty string before the takedown starts.
	 * @param string                                                                  $message  What happened, or what will.
	 * @param int                                                                     $at       Unix time, or 0.
	 * @param array{message: string, label: string, url: string, blocking: bool}|null $follow   What a person has to do, if anything.
	 * @param bool                                                                    $blocking Whether the person has to act.
	 * @return array{id: string, label: string, channels: string[], status: string, message: string, at: int, link: array{label: string, url: string}|null, blocking: bool}
	 */
	private function row( string $id, string $label, array $channels, string $status, string $message, int $at, ?array $follow, bool $blocking ): array {
		if ( null !== $follow && '' !== $follow['message'] && false === strpos( $message, $follow['message'] ) ) {
			$message = trim( $message . ' ' . $follow['message'] );
		}

		$link = null;

		if ( null !== $follow && '' !== $follow['url'] ) {
			$link = array(
				'label' => $follow['label'],
				'url'   => $follow['url'],
			);
		}

		return array(
			'id'       => $id,
			'label'    => $label,
			'channels' => $channels,
			'status'   => $status,
			'message'  => $message,
			'at'       => $at,
			'link'     => $link,
			'blocking' => $blocking,
		);
	}

	/**
	 * The name of the merged search sitemap row: the engines that are on, such as "Bing and Yandex".
	 *
	 * @param string[] $members Channel ids in the row.
	 * @return string
	 */
	private function search_label( array $members ): string {
		if ( array( 'bing', 'yandex' ) === $members ) {
			return __( 'Bing and Yandex', 'estasoft-news-distribution' );
		}

		$names = array(
			'bing'   => __( 'Bing', 'estasoft-news-distribution' ),
			'yandex' => __( 'Yandex', 'estasoft-news-distribution' ),
			'baidu'  => __( 'Baidu', 'estasoft-news-distribution' ),
		);
		$list  = array();

		foreach ( $members as $member ) {
			$list[] = $names[ $member ] ?? $member;
		}

		return wp_sprintf( '%l', $list );
	}

	/**
	 * Merges the rows that share a line on the screen.
	 *
	 * @param array<string, array<string, mixed>> $rows Rows by channel id.
	 * @return array<int, array<string, mixed>>
	 */
	private function grouped( array $rows ): array {
		$out   = array();
		$taken = array();

		foreach ( $rows as $id => $row ) {
			if ( isset( $taken[ $id ] ) ) {
				continue;
			}

			foreach ( self::ROW_GROUPS as $key => $group ) {
				if ( ! in_array( $id, $group['channels'], true ) ) {
					continue;
				}

				$members = array_values( array_intersect( $group['channels'], array_keys( $rows ) ) );

				if ( count( $members ) < 2 ) {
					break;
				}

				$merged             = $row;
				$merged['id']       = $key;
				$merged['label']    = 'search-sitemaps' === $key ? $this->search_label( $members ) : __( 'Flipboard and feed readers', 'estasoft-news-distribution' );
				$merged['channels'] = $members;

				foreach ( $members as $member ) {
					$taken[ $member ] = true;

					if ( self::ATTENTION === $rows[ $member ]['status'] ) {
						$merged['status']   = self::ATTENTION;
						$merged['message']  = $rows[ $member ]['message'];
						$merged['blocking'] = true;
					}
				}

				$out[] = $merged;
				continue 2;
			}

			$out[] = $row;
		}

		return $out;
	}

	/**
	 * Whether the channel has anything to do with one article.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	private function has_articles( ChannelInterface $channel ): bool {
		if ( ChannelInterface::MODE_MANUAL === $channel->mode() ) {
			// Only a channel someone feeds by hand with articles. Listings and verification hold no article.
			return 'news-break' === $channel->id();
		}

		return in_array( $channel->group(), array( Group::SITEMAP, Group::PARTNER, Group::READERS, Group::PUSHES ), true );
	}

	/**
	 * The last takedown or restore row in the audit table for an article.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>|null
	 */
	private function audited( int $post_id ): ?array {
		global $wpdb;

		if ( null !== $this->primed ) {
			$row = $this->primed[ $post_id ] ?? null;

			if ( null === $row ) {
				return null;
			}

			$row['created_ts'] = Clock::from_gmt( (string) $row['created_gmt'] );

			return $row;
		}

		$table = $this->plugin->audit()->table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT id, post_id, user_id, action, created_gmt FROM %i WHERE post_id = %d AND action IN ( %s, %s ) ORDER BY id DESC LIMIT 1', $table, $post_id, Audit::TAKEDOWN, Audit::RESTORE ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		$row['created_ts'] = Clock::from_gmt( (string) $row['created_gmt'] );

		return $row;
	}

	/**
	 * Whether an audit row is the undo of a takedown.
	 *
	 * @param array<string, mixed> $row An audit row.
	 * @return bool
	 */
	private static function is_restore_row( array $row ): bool {
		return Audit::RESTORE === ( $row['action'] ?? '' );
	}

	/**
	 * The article, if it exists and is of a type the plugin sends.
	 *
	 * @param int $post_id Article ID.
	 * @return \WP_Post|\WP_Error
	 */
	private function post( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $this->fail( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return $post;
	}

	/**
	 * A person as the routes show them.
	 *
	 * @param int $user_id User ID.
	 * @return array{id: int, name: string}|null
	 */
	private function person( int $user_id ): ?array {
		if ( $user_id < 1 ) {
			return null;
		}

		return array(
			'id'   => $user_id,
			'name' => (string) get_the_author_meta( 'display_name', $user_id ),
		);
	}

	/**
	 * An ISO time, or null for zero.
	 *
	 * @param int $timestamp Unix time.
	 * @return string|null
	 */
	private function iso( int $timestamp ): ?string {
		return $timestamp > 0 ? Clock::iso( $timestamp ) : null;
	}

	/**
	 * A plain-language error.
	 *
	 * @param string $code    Stable code.
	 * @param string $message Sentence the screen can show as written.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	private function fail( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
