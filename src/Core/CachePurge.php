<?php
/**
 * Drops stored copies of the addresses an article changed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\SearchSitemap\AbstractSearchSitemapChannel;
use Estasoft\NewsDistribution\Integrations\CachePlugins\CachePluginInterface;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * When an article goes live, really changes or goes away, this works out the
 * addresses that changed and hands them on in two ways: it fires the public
 * action `estasoft_nd_purge_urls` with the list, and it asks each caching plugin
 * that is installed to drop them (the adapters are in
 * `src/Integrations/CachePlugins/`).
 *
 * A copy of a featured image made for a platform (`Content\ImagePresets`) is the fourth kind of change: when one is
 * made, replaced or removed, the copy's address and the feeds of the channel that prints it are dropped. A copy keeps its
 * file name when it is made again, so a stored image would otherwise outlive it. Changes seen in one request are
 * gathered and handled once at shutdown; more than one copy in a request is handed to the queue as a single job.
 *
 * It never contacts another server. It calls functions of plugins on this same
 * site, at shutdown, after the page was already sent. Nothing runs when the
 * caching settings are switched off for that kind of change.
 *
 * Get it with `Plugin::instance()->make( CachePurge::class )`. The Settings
 * screen "Cache purge" reads `log()`, `adapters()` and `test()`.
 *
 * A log row (`log()`, newest first, at most 50, in the option
 * `estasoft_nd_cache_purge_log`, not autoloaded):
 *
 *     array(
 *       'time'    => int,                       // Unix time
 *       'kind'    => 'publish'|'change'|'remove'|'image'|'test',
 *       'post_id' => int,                       // 0 for a test or an image copy
 *       'title'   => string,                    // for an image copy: the file names of the copies
 *       'what'    => array( 'article' => int, 'sitemaps' => int, 'feeds' => int, 'home' => int ),  // an image copy adds 'images' => int
 *       'hook'    => bool,                      // whether anything listened to estasoft_nd_purge_urls
 *       'through' => array( array( 'id' => string, 'label' => string, 'ok' => bool, 'asked' => int, 'message' => string ) ),
 *       'ok'      => bool,                      // false when a plugin answered with an error
 *     )
 */
final class CachePurge {

	public const HOOK       = 'estasoft_nd_purge_urls';
	public const LOG_OPTION = 'estasoft_nd_cache_purge_log';

	public const WHAT_ARTICLE  = 'article';
	public const WHAT_SITEMAPS = 'sitemaps';
	public const WHAT_FEEDS    = 'feeds';
	public const WHAT_HOME     = 'home';

	public const WHEN_PUBLISH = 'publish';
	public const WHEN_CHANGE  = 'change';
	public const WHEN_REMOVE  = 'remove';
	public const WHEN_IMAGE   = 'image';

	/**
	 * The queued job that purges the copies of a bulk change.
	 */
	public const IMAGES_HOOK = 'estasoft_nd_purge_image_copies';

	private const LOG_MAX = 50;

	/**
	 * The order the plugins are listed in.
	 *
	 * @var string[]
	 */
	private const ORDER = array( 'wp-rocket', 'litespeed-cache', 'w3-total-cache', 'wp-super-cache', 'wp-fastest-cache' );

	private Plugin $plugin;

	/**
	 * Adapters given by a test, or null to find them by folder.
	 *
	 * @var array<int, CachePluginInterface>|null
	 */
	private ?array $given;

	/**
	 * Changes seen in this request that wait for shutdown.
	 *
	 * @var array<int, array{kind: string, event: Event}>
	 */
	private array $pending = array();

	/**
	 * Image copies changed in this request that wait for shutdown, by attachment and preset.
	 *
	 * @var array<string, array{attachment: int, preset: string, urls: string[]}>
	 */
	private array $images = array();

	/**
	 * Creates the service.
	 *
	 * @param Plugin                                $plugin   The running plugin.
	 * @param array<int, CachePluginInterface>|null $adapters Adapters to use instead of the ones found by folder. For tests.
	 */
	public function __construct( Plugin $plugin, ?array $adapters = null ) {
		$this->plugin = $plugin;
		$this->given  = $adapters;
	}

	/**
	 * Every adapter, in the order the screen lists them.
	 *
	 * @return array<string, CachePluginInterface> By id.
	 */
	public function adapters(): array {
		$list = array();

		if ( null !== $this->given ) {
			foreach ( $this->given as $adapter ) {
				$list[ $adapter->id() ] = $adapter;
			}
		} else {
			foreach ( Discovery::classes( 'Integrations/CachePlugins' ) as $class ) {
				$adapter = new $class();

				if ( $adapter instanceof CachePluginInterface ) {
					$list[ $adapter->id() ] = $adapter;
				}
			}
		}

		uksort(
			$list,
			static function ( string $a, string $b ): int {
				$left  = array_search( $a, self::ORDER, true );
				$right = array_search( $b, self::ORDER, true );

				return ( false === $left ? 99 : $left ) <=> ( false === $right ? 99 : $right );
			}
		);

		return $list;
	}

	/**
	 * The adapters whose plugin is installed.
	 *
	 * @return array<string, CachePluginInterface>
	 */
	public function installed(): array {
		return array_filter(
			$this->adapters(),
			static function ( CachePluginInterface $adapter ): bool {
				return $adapter->is_installed();
			}
		);
	}

	/**
	 * Which kinds of address are dropped.
	 *
	 * @return string[] WHAT_* values.
	 */
	public function what(): array {
		return array_map( 'strval', (array) $this->plugin->settings()->get( 'caching.purge_what', array( self::WHAT_ARTICLE, self::WHAT_SITEMAPS, self::WHAT_FEEDS ) ) );
	}

	/**
	 * Which changes cause a purge.
	 *
	 * @return string[] WHEN_* values.
	 */
	public function when(): array {
		return array_map( 'strval', (array) $this->plugin->settings()->get( 'caching.purge_when', array( self::WHEN_PUBLISH, self::WHEN_CHANGE, self::WHEN_REMOVE, self::WHEN_IMAGE ) ) );
	}

	/**
	 * Hooked to `estasoft_nd_post_event`: remembers the change until shutdown.
	 *
	 * Nothing is decided here. The modified-date guard writes its decision after
	 * this event, so "really changes" is read when the request ends.
	 *
	 * @param mixed $event The event.
	 * @return void
	 */
	public function on_event( $event ): void {
		if ( ! $event instanceof Event ) {
			return;
		}

		if ( Event::PUBLISH === $event->type() ) {
			$kind = self::WHEN_PUBLISH;
		} elseif ( Event::UPDATE === $event->type() ) {
			$kind = self::WHEN_CHANGE;
		} else {
			$kind = self::WHEN_REMOVE;
		}

		if ( ! in_array( $kind, $this->when(), true ) ) {
			return;
		}

		$known = $this->pending[ $event->post_id() ]['kind'] ?? '';

		// A post published and then edited in one request is still one publish.
		if ( self::WHEN_PUBLISH === $known && self::WHEN_CHANGE === $kind ) {
			return;
		}

		$this->pending[ $event->post_id() ] = array(
			'kind'  => $kind,
			'event' => $event,
		);
	}

	/**
	 * Hooked to `shutdown`: purges what the request changed.
	 *
	 * @return int Purges made.
	 */
	public function flush(): int {
		$pending       = $this->pending;
		$this->pending = array();
		$made          = 0;

		foreach ( $pending as $post_id => $item ) {
			$event = $item['event'];

			if ( self::WHEN_CHANGE === $item['kind'] && ! $this->changed_for_real( $event ) ) {
				continue;
			}

			$plan = $this->plan( (int) $post_id, $event->language(), $item['kind'], $event->url() );

			if ( array() === $plan['urls'] ) {
				continue;
			}

			$title = '' !== $event->title() ? $event->title() : wp_strip_all_tags( (string) get_the_title( (int) $post_id ) );

			$this->purge( $plan, $item['kind'], (int) $post_id, $title );
			++$made;
		}

		return $made + $this->flush_images();
	}

	/**
	 * Hooked by `Content\ImagePresets`: a copy of a photo was made, made again or removed.
	 *
	 * Nothing is decided here either. The change waits for shutdown, so a request that makes six copies asks once.
	 * Nothing is remembered when "An image copy is made, replaced or removed" is not ticked in Settings, Cache purge.
	 *
	 * @param int      $attachment Attachment ID of the photo.
	 * @param string   $preset     Preset id, which is the id of the channel that prints the copy.
	 * @param string[] $urls       Addresses of the copy file, as it was and as it is now.
	 * @return void
	 */
	public function on_image_copy( int $attachment, string $preset, array $urls ): void {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $urls ) ) ) ) );

		if ( $attachment < 1 || '' === $preset || array() === $urls || ! in_array( self::WHEN_IMAGE, $this->when(), true ) ) {
			return;
		}

		$key = $attachment . ':' . $preset;

		$this->images[ $key ] = array(
			'attachment' => $attachment,
			'preset'     => $preset,
			'urls'       => array_values( array_unique( array_merge( $this->images[ $key ]['urls'] ?? array(), $urls ) ) ),
		);
	}

	/**
	 * Purges the copies this request changed: once, and through the queue when there is more than one.
	 *
	 * @return int Purges made now. A job handed to the queue is not counted.
	 */
	private function flush_images(): int {
		$changes      = array_values( $this->images );
		$this->images = array();

		if ( array() === $changes ) {
			return 0;
		}

		// A bulk fill must not ask the caching plugins while it is still making the copies, nor once per photo.
		if ( count( $changes ) > 1 && function_exists( 'as_enqueue_async_action' ) ) {
			Queue::once( 0, self::IMAGES_HOOK, array( $changes ), Queue::GROUP );

			return 0;
		}

		return null === $this->purge_images( $changes ) ? 0 : 1;
	}

	/**
	 * Hooked to the queued job: purges the copies of a bulk change.
	 *
	 * @param mixed $changes What `on_image_copy()` gathered: a list of attachment, preset and addresses.
	 * @return void
	 */
	public function run_images( $changes = array() ): void {
		$clean = array();

		foreach ( is_array( $changes ) ? $changes : array() as $change ) {
			if ( is_array( $change ) && isset( $change['attachment'], $change['preset'], $change['urls'] ) && is_array( $change['urls'] ) ) {
				$clean[] = array(
					'attachment' => (int) $change['attachment'],
					'preset'     => (string) $change['preset'],
					'urls'       => array_map( 'strval', $change['urls'] ),
				);
			}
		}

		$this->purge_images( $clean );
	}

	/**
	 * Works out and makes the purge of some image copies. Writes one log row.
	 *
	 * @param array<int, array{attachment: int, preset: string, urls: string[]}> $changes The copies.
	 * @return array<string, mixed>|null The log row, or null when there was nothing to drop.
	 */
	public function purge_images( array $changes ): ?array {
		$plan = $this->image_plan( $changes );

		if ( array() === $plan['urls'] ) {
			return null;
		}

		$names = array();

		foreach ( $changes as $change ) {
			foreach ( $change['urls'] as $url ) {
				$names[ wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ] = true;
			}
		}

		$shown = array_slice( array_keys( $names ), 0, 3 );
		$title = implode( ', ', $shown ) . ( count( $names ) > 3 ? ', …' : '' );

		return $this->purge( $plan, self::WHEN_IMAGE, 0, $title );
	}

	/**
	 * The addresses to drop for some image copies: the copies themselves, and the feeds or sitemaps of the channels
	 * that print them (the preset id is the channel id; see `Channels\ChannelImage`).
	 *
	 * The same "Addresses" choices as for an article decide the feeds and the sitemaps. The copy's own address is
	 * always part of it, because that is what changed.
	 *
	 * @param array<int, array{attachment: int, preset: string, urls: string[]}> $changes The copies.
	 * @return array{urls: string[], post_ids: int[], what: array{article: int, sitemaps: int, feeds: int, home: int, images: int}}
	 */
	public function image_plan( array $changes ): array {
		$what      = $this->what();
		$languages = $this->plugin->languages()->languages();
		$groups    = array(
			'images'            => array(),
			self::WHAT_SITEMAPS => array(),
			self::WHAT_FEEDS    => array(),
		);

		foreach ( $changes as $change ) {
			$groups['images'] = array_merge( $groups['images'], $change['urls'] );
			$channel          = $this->plugin->registry()->get( $change['preset'] );

			if ( null === $channel || ! $channel->is_enabled() || ChannelInterface::MODE_PULLED !== $channel->mode() || '' === $channel->feed_slug() ) {
				continue;
			}

			$kind = $this->is_sitemap( $channel ) ? self::WHAT_SITEMAPS : self::WHAT_FEEDS;

			if ( in_array( $kind, $what, true ) ) {
				$groups[ $kind ] = array_merge( $groups[ $kind ], $this->addresses_of( $channel, $languages ) );
			}
		}

		$urls   = array();
		$counts = array(
			self::WHAT_ARTICLE  => 0,
			self::WHAT_SITEMAPS => 0,
			self::WHAT_FEEDS    => 0,
			self::WHAT_HOME     => 0,
			'images'            => 0,
		);

		foreach ( $groups as $name => $list ) {
			$list = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $list ) ) ) );
			$list = array_values( array_diff( $list, $urls ) );

			$counts[ $name ] = count( $list );
			$urls            = array_merge( $urls, $list );
		}

		return array(
			'urls'     => $urls,
			'post_ids' => array(),
			'what'     => $counts,
		);
	}

	/**
	 * Whether a save changed the article in a way the date guard counts.
	 *
	 * With the guard off, or when it wrote nothing for this save, every save counts.
	 *
	 * @param Event $event The update event.
	 * @return bool
	 */
	private function changed_for_real( Event $event ): bool {
		$guard = $this->plugin->make( DateGuard::class );

		if ( ! $guard->enabled() ) {
			return true;
		}

		$last = $guard->last( $event->post_id() );

		if ( null === $last || (int) ( $last['at'] ?? 0 ) < $event->timestamp() - 2 ) {
			return true;
		}

		return DateGuard::MOVED === (string) $last['decision'];
	}

	/**
	 * The addresses to drop for one article, by kind.
	 *
	 * @param int    $post_id      Article ID.
	 * @param string $language     Language code of the article.
	 * @param string $kind         WHEN_* value.
	 * @param string $removed_url  The address the article had while it was public, for a removal.
	 * @return array{urls: string[], post_ids: int[], what: array{article: int, sitemaps: int, feeds: int, home: int}}
	 */
	public function plan( int $post_id, string $language, string $kind, string $removed_url = '' ): array {
		$what     = $this->what();
		$urls     = array();
		$post_ids = array();
		$counts   = array(
			self::WHAT_ARTICLE  => 0,
			self::WHAT_SITEMAPS => 0,
			self::WHAT_FEEDS    => 0,
			self::WHAT_HOME     => 0,
		);
		$versions = $this->plugin->make( Translations::class )->versions_of( $post_id, $language );
		$groups   = array();

		if ( in_array( self::WHAT_ARTICLE, $what, true ) ) {
			if ( self::WHEN_REMOVE === $kind ) {
				$groups[ self::WHAT_ARTICLE ] = '' !== $removed_url ? array( $removed_url ) : array();
			} else {
				$groups[ self::WHAT_ARTICLE ] = array();

				foreach ( $versions as $id ) {
					$link = 'publish' === get_post_status( (int) $id ) ? get_permalink( (int) $id ) : false;

					if ( is_string( $link ) && '' !== $link ) {
						$groups[ self::WHAT_ARTICLE ][] = $link;
					}
				}
			}

			$post_ids = array_values( $versions );
		}

		// Sitemaps and feeds change in the languages of the article and its versions.
		$languages = array_keys( $versions );

		if ( in_array( self::WHAT_SITEMAPS, $what, true ) ) {
			$groups[ self::WHAT_SITEMAPS ] = $this->channel_addresses( true, $languages );
		}

		if ( in_array( self::WHAT_FEEDS, $what, true ) ) {
			$groups[ self::WHAT_FEEDS ] = $this->channel_addresses( false, $languages );
		}

		if ( in_array( self::WHAT_HOME, $what, true ) ) {
			$home = array( home_url( '/' ) );

			foreach ( (array) get_the_category( $post_id ) as $category ) {
				$link = get_category_link( $category );

				if ( is_string( $link ) && '' !== $link ) {
					$home[] = $link;
				}
			}

			$groups[ self::WHAT_HOME ] = $home;
		}

		foreach ( $groups as $name => $list ) {
			$list = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $list ) ) ) );
			$list = array_values( array_diff( $list, $urls ) );

			$counts[ $name ] = count( $list );
			$urls            = array_merge( $urls, $list );
		}

		return array(
			'urls'     => $urls,
			'post_ids' => $post_ids,
			'what'     => $counts,
		);
	}

	/**
	 * The addresses of the feeds or the sitemaps of the channels that are on, in some languages.
	 *
	 * @param bool     $sitemaps True for the sitemaps (the news sitemap and the search sitemap), false for the other feeds.
	 * @param string[] $languages Language codes.
	 * @return string[]
	 */
	private function channel_addresses( bool $sitemaps, array $languages ): array {
		$urls = array();

		foreach ( $this->plugin->registry()->pulled() as $channel ) {
			if ( ! $channel->is_enabled() || '' === $channel->feed_slug() || $this->is_sitemap( $channel ) !== $sitemaps ) {
				continue;
			}

			$urls = array_merge( $urls, $this->addresses_of( $channel, $languages ) );
		}

		return $urls;
	}

	/**
	 * The addresses of one channel's document, one for each of the languages it takes.
	 *
	 * @param ChannelInterface $channel   The channel.
	 * @param string[]         $languages Language codes.
	 * @return string[]
	 */
	private function addresses_of( ChannelInterface $channel, array $languages ): array {
		$urls = array();

		foreach ( $languages as $language ) {
			if ( $channel->accepts_language( (string) $language ) ) {
				$urls[] = add_query_arg( 'lang', rawurlencode( (string) $language ), home_url( '/feed/' . $channel->feed_slug() . '/' ) );
			}
		}

		return $urls;
	}

	/**
	 * Whether a channel's document is a sitemap.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	private function is_sitemap( ChannelInterface $channel ): bool {
		return $channel instanceof AbstractSearchSitemapChannel || 'google-news' === $channel->id();
	}

	/**
	 * Fires the hook and asks each installed caching plugin. Writes one log row.
	 *
	 * @param array{urls: string[], post_ids: int[], what: array<string, int>} $plan    What `plan()` returned.
	 * @param string                                                           $kind    WHEN_* value, or `test`.
	 * @param int                                                              $post_id Article ID, 0 for a test.
	 * @param string                                                           $title   Article title, for the log.
	 * @return array<string, mixed> The log row.
	 */
	public function purge( array $plan, string $kind, int $post_id = 0, string $title = '' ): array {
		$urls      = array_values( $plan['urls'] );
		$listeners = false !== has_action( 'estasoft_nd_purge_urls' );

		/**
		 * Fires when addresses should be dropped from a cache after a real change.
		 *
		 * A caching plugin or a snippet listens. The list holds full addresses of
		 * the article, its translations, the feeds and sitemaps that changed, and,
		 * when the owner chose it, the home page and section pages. It never holds
		 * a request to clear a whole cache.
		 *
		 * @param string[] $urls Addresses to drop.
		 */
		do_action( 'estasoft_nd_purge_urls', $urls );

		$through = array();
		$ok      = true;

		foreach ( $this->installed() as $adapter ) {
			$answer    = $adapter->purge( $urls, (array) $plan['post_ids'] );
			$ok        = $ok && $answer['ok'];
			$through[] = array(
				'id'      => $adapter->id(),
				'label'   => $adapter->label(),
				'ok'      => (bool) $answer['ok'],
				'asked'   => (int) $answer['asked'],
				'message' => (string) $answer['message'],
			);
		}

		$row = array(
			'time'    => Clock::now(),
			'kind'    => $kind,
			'post_id' => $post_id,
			'title'   => $title,
			'what'    => $plan['what'],
			'hook'    => $listeners,
			'through' => $through,
			'ok'      => $ok,
		);

		// Nothing was asked of anyone, so there is nothing to report.
		if ( array() !== $through || $listeners || 'test' === $kind ) {
			$this->add_to_log( $row );
		}

		return $row;
	}

	/**
	 * The test a person starts: one made-up address, sent the same ways a real purge goes.
	 *
	 * @return array<string, mixed> The log row.
	 */
	public function test(): array {
		$url = home_url( '/estasoft-nd-cache-test-' . strtolower( wp_generate_password( 8, false, false ) ) . '/' );

		return $this->purge(
			array(
				'urls'     => array( $url ),
				'post_ids' => array(),
				'what'     => array(
					self::WHAT_ARTICLE  => 1,
					self::WHAT_SITEMAPS => 0,
					self::WHAT_FEEDS    => 0,
					self::WHAT_HOME     => 0,
				),
			),
			'test'
		);
	}

	/**
	 * The log, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function log(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * The latest test of one plugin: its log row and its answer.
	 *
	 * @param string $adapter_id Adapter id.
	 * @return array{time: int, ok: bool, asked: int, message: string}|null Null when it was never tested.
	 */
	public function last_test( string $adapter_id ): ?array {
		foreach ( $this->log() as $row ) {
			if ( 'test' !== ( $row['kind'] ?? '' ) ) {
				continue;
			}

			foreach ( (array) $row['through'] as $answer ) {
				if ( isset( $answer['id'] ) && $adapter_id === $answer['id'] ) {
					return array(
						'time'    => (int) $row['time'],
						'ok'      => (bool) $answer['ok'],
						'asked'   => (int) $answer['asked'],
						'message' => (string) $answer['message'],
					);
				}
			}
		}

		return null;
	}

	/**
	 * Empties the log. Used by an uninstall and by a reset.
	 *
	 * @return void
	 */
	public function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Words for what a row asked about: "Article, 2 sitemaps, 3 feeds".
	 *
	 * @param array<string, int> $what Counts by kind.
	 * @return string
	 */
	public static function describe( array $what ): string {
		$parts = array();

		if ( ! empty( $what[ self::WHAT_ARTICLE ] ) ) {
			$parts[] = _n( 'Article', 'Articles', (int) $what[ self::WHAT_ARTICLE ], 'estasoft-news-distribution' );
		}

		if ( ! empty( $what['images'] ) ) {
			/* translators: %d: number of image copies. */
			$parts[] = sprintf( _n( '%d image copy', '%d image copies', (int) $what['images'], 'estasoft-news-distribution' ), (int) $what['images'] );
		}

		if ( ! empty( $what[ self::WHAT_SITEMAPS ] ) ) {
			/* translators: %d: number of sitemaps. */
			$parts[] = sprintf( _n( '%d sitemap', '%d sitemaps', (int) $what[ self::WHAT_SITEMAPS ], 'estasoft-news-distribution' ), (int) $what[ self::WHAT_SITEMAPS ] );
		}

		if ( ! empty( $what[ self::WHAT_FEEDS ] ) ) {
			/* translators: %d: number of feeds. */
			$parts[] = sprintf( _n( '%d feed', '%d feeds', (int) $what[ self::WHAT_FEEDS ], 'estasoft-news-distribution' ), (int) $what[ self::WHAT_FEEDS ] );
		}

		if ( ! empty( $what[ self::WHAT_HOME ] ) ) {
			/* translators: %d: number of pages. */
			$parts[] = sprintf( _n( '%d home or section page', '%d home and section pages', (int) $what[ self::WHAT_HOME ], 'estasoft-news-distribution' ), (int) $what[ self::WHAT_HOME ] );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Adds a row to the front of the log and keeps the newest 50.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return void
	 */
	private function add_to_log( array $row ): void {
		$log = $this->log();

		array_unshift( $log, $row );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
	}
}
