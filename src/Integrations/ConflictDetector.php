<?php
/**
 * Finds the plugins that print the same sitemaps, feeds or tags, or that cache them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations;

use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * It reads, and says what it read. It can see that a plugin is active and, once a
 * person has run the check on the Compatibility screen, what the site prints at the
 * usual news sitemap addresses. It cannot see how another plugin is set up, and it
 * never changes another plugin's settings.
 *
 * Shape of `detect()`:
 *
 *     array(
 *       'conflicts'   => int,   // rows with the status Conflict
 *       'scan_ran_at' => int,   // when the news sitemaps and feeds were last looked at, 0 when never
 *       'rows'        => array( array(
 *         'id'      => string,  // a KnownPlugins id, 'core-sitemaps' or 'unknown-news'
 *         'name'    => string,
 *         'version' => string,
 *         'kind'    => string,  // news, seo, sitemap, cache, language or core
 *         'status'  => string,  // Status::CONFLICT or Status::NO_CONFLICT
 *         'result'  => string,  // what was seen
 *         'todo'    => string,  // what to do, or empty
 *         'action'  => array{label: string, url: string}|null,
 *       ) ),
 *     )
 *
 * Nothing here makes a request. The scan result is read from what the person last ran.
 */
final class ConflictDetector {

	private Plugin $plugin;

	/**
	 * Creates the detector.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The address of the switch-from-GN-Publisher screen.
	 *
	 * @return string
	 */
	public static function migration_url(): string {
		return add_query_arg(
			array(
				'page'    => 'estasoft-nd-settings',
				'section' => 'compatibility',
				'tab'     => 'gn-publisher',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Looks at the active plugins.
	 *
	 * @return array<string, mixed> See the class description.
	 */
	public function detect(): array {
		$gn   = new GnPublisher( $this->plugin );
		$scan = $gn->last_scan();
		$rows = array();

		foreach ( KnownPlugins::found() as $found ) {
			$row = $this->describe( $found, $gn, $scan );

			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		if ( $this->core_sitemaps_enabled() ) {
			$rows[] = array(
				'id'      => 'core-sitemaps',
				'name'    => __( 'WordPress core sitemaps', 'estasoft-news-distribution' ),
				'version' => '',
				'kind'    => 'core',
				'status'  => Status::NO_CONFLICT,
				'result'  => __( 'Serves /wp-sitemap.xml.', 'estasoft-news-distribution' ),
				'todo'    => __( 'A separate address. Nothing to do.', 'estasoft-news-distribution' ),
				'action'  => null,
			);
		}

		// A news sitemap that no known plugin explains.
		$has_news_plugin = false;

		foreach ( $rows as $row ) {
			if ( 'news' === $row['kind'] ) {
				$has_news_plugin = true;
			}
		}

		if ( ! $has_news_plugin && null !== $scan && array() !== $gn->others( $scan ) ) {
			$other = $gn->others( $scan )[0];

			array_unshift(
				$rows,
				array(
					'id'      => 'unknown-news',
					'name'    => __( 'Another plugin', 'estasoft-news-distribution' ),
					'version' => '',
					'kind'    => 'news',
					'status'  => Status::CONFLICT,
					'result'  => 'news_sitemap' === $other['type']
						? sprintf(
							/* translators: %s: an address such as /news-sitemap.xml. */
							__( 'Found a Google News sitemap at %s that News Distribution did not print.', 'estasoft-news-distribution' ),
							(string) $other['path']
						)
						: sprintf(
							/* translators: %s: an address such as /feed/gn/. */
							__( 'Found a news feed at %s that News Distribution did not print.', 'estasoft-news-distribution' ),
							(string) $other['path']
						),
					'todo'    => __( 'Keeping both on can give Google two sitemaps with different contents, so pick one.', 'estasoft-news-distribution' ),
					'action'  => null,
				)
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$order = array(
					'news'     => 0,
					'seo'      => 1,
					'sitemap'  => 2,
					'cache'    => 3,
					'language' => 4,
					'core'     => 5,
				);

				return ( $order[ $a['kind'] ] ?? 9 ) <=> ( $order[ $b['kind'] ] ?? 9 );
			}
		);

		$conflicts = 0;

		foreach ( $rows as $row ) {
			if ( Status::CONFLICT === $row['status'] ) {
				++$conflicts;
			}
		}

		return array(
			'conflicts'   => $conflicts,
			'scan_ran_at' => null === $scan ? 0 : (int) $scan['ran_at'],
			'rows'        => $rows,
		);
	}

	/**
	 * Whether WordPress's own sitemaps are on.
	 *
	 * @return bool
	 */
	private function core_sitemaps_enabled(): bool {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) ) {
			return false;
		}

		$server = wp_sitemaps_get_server();

		return is_object( $server ) && method_exists( $server, 'sitemaps_enabled' ) && $server->sitemaps_enabled();
	}

	/**
	 * Describes one found plugin.
	 *
	 * @param array<string, string>     $found The plugin.
	 * @param GnPublisher               $gn    The migration helper, which holds the last scan.
	 * @param array<string, mixed>|null $scan  The last scan.
	 * @return array<string, mixed>|null
	 */
	private function describe( array $found, GnPublisher $gn, ?array $scan ): ?array {
		$base = array(
			'id'      => $found['id'],
			'name'    => $found['name'],
			'version' => $found['version'],
			'kind'    => $found['kind'],
			'action'  => null,
		);

		switch ( $found['id'] ) {
			case 'gn-publisher':
				return array_merge( $base, $this->describe_gn_publisher( $gn, $scan ) );

			case 'yoast-news':
				return array_merge(
					$base,
					array(
						'status' => Status::CONFLICT,
						'result' => __( 'Can print a Google News sitemap.', 'estasoft-news-distribution' ),
						'todo'   => __( 'Keeping both on can give Google two sitemaps with different contents, so pick one. Turn off the news sitemap in that plugin; News Distribution cannot do it for you.', 'estasoft-news-distribution' ),
					)
				);

			case 'yoast':
			case 'rank-math':
			case 'aioseo':
				$mode = (string) $this->plugin->settings()->get( 'markup.other_plugin', 'leave' );

				return array_merge(
					$base,
					array(
						'status' => Status::NO_CONFLICT,
						'result' => __( 'Prints page tags and its own sitemap.', 'estasoft-news-distribution' ),
						'todo'   => 'replace' === $mode
							? __( 'News Distribution prints its own tags and asks this plugin to leave them out. A tag it cannot ask about stays, and Page markup says so.', 'estasoft-news-distribution' )
							: __( 'News Distribution adds only missing tags.', 'estasoft-news-distribution' ),
					)
				);

			case 'google-xml-sitemaps':
				return array_merge(
					$base,
					array(
						'status' => Status::NO_CONFLICT,
						'result' => __( 'Prints its own sitemap.', 'estasoft-news-distribution' ),
						'todo'   => __( 'The news sitemap is a separate address. Nothing to do.', 'estasoft-news-distribution' ),
					)
				);

			case 'jetpack':
				return array_merge(
					$base,
					array(
						'status' => Status::NO_CONFLICT,
						'result' => __( 'Can print sitemaps and page tags.', 'estasoft-news-distribution' ),
						'todo'   => __( 'News Distribution cannot see which Jetpack features are on. Run the check on the GN Publisher screen to see whether another news sitemap is printed.', 'estasoft-news-distribution' ),
					)
				);

			case 'wp-rocket':
				return array_merge(
					$base,
					array(
						'status' => Status::NO_CONFLICT,
						'result' => __( 'Caches pages. It does not cache feeds unless you turn that on.', 'estasoft-news-distribution' ),
						'todo'   => __( 'If you turn on feed caching, add /feed/* to its never-cached list (see WP Rocket below).', 'estasoft-news-distribution' ),
					)
				);

			case 'polylang':
			case 'wpml':
				return array_merge(
					$base,
					array(
						'status' => Status::NO_CONFLICT,
						'result' => __( 'Prints language links.', 'estasoft-news-distribution' ),
						'todo'   => __( 'Read by News Distribution.', 'estasoft-news-distribution' ),
					)
				);
		}

		if ( 'cache' === $found['kind'] ) {
			return array_merge(
				$base,
				array(
					'status' => Status::NO_CONFLICT,
					'result' => __( 'Caches pages.', 'estasoft-news-distribution' ),
					'todo'   => __( 'News Distribution cannot see whether it caches feeds. If it does, add /feed/* to its never-cached list.', 'estasoft-news-distribution' ),
				)
			);
		}

		return null;
	}

	/**
	 * The GN Publisher row, which depends on whether the check has run.
	 *
	 * @param GnPublisher               $gn   The migration helper.
	 * @param array<string, mixed>|null $scan The last scan.
	 * @return array<string, mixed>
	 */
	private function describe_gn_publisher( GnPublisher $gn, ?array $scan ): array {
		$action = array(
			'label' => __( 'Switch from GN Publisher', 'estasoft-news-distribution' ),
			'url'   => self::migration_url(),
		);

		if ( null === $scan ) {
			return array(
				'status' => Status::CONFLICT,
				'result' => __( 'Found. It can print a news feed and a Google News sitemap. News Distribution has not looked at what it prints yet.', 'estasoft-news-distribution' ),
				'todo'   => __( 'Keeping both on can give Google two sitemaps with different contents, so pick one.', 'estasoft-news-distribution' ),
				'action' => $action,
			);
		}

		$others   = $gn->others( $scan );
		$sitemaps = array_values(
			array_filter(
				$others,
				static function ( array $item ): bool {
					return 'news_sitemap' === $item['type'];
				}
			)
		);
		$feeds    = array_values(
			array_filter(
				$others,
				static function ( array $item ): bool {
					return 'news_feed' === $item['type'];
				}
			)
		);

		if ( array() === $others ) {
			return array(
				'status' => Status::NO_CONFLICT,
				'result' => __( 'Found. The check did not see a news sitemap or a news feed from it, so it may be turned off.', 'estasoft-news-distribution' ),
				'todo'   => __( 'Nothing to do. News Distribution cannot see how the plugin is set up.', 'estasoft-news-distribution' ),
				'action' => null,
			);
		}

		if ( array() !== $sitemaps && array() !== $feeds ) {
			$result = sprintf(
				/* translators: %s: an address such as /news-sitemap.xml. */
				__( 'Found printing a Google News sitemap at %s and a news feed.', 'estasoft-news-distribution' ),
				(string) $sitemaps[0]['path']
			);
		} elseif ( array() !== $sitemaps ) {
			$result = sprintf(
				/* translators: %s: an address such as /news-sitemap.xml. */
				__( 'Found printing a Google News sitemap at %s.', 'estasoft-news-distribution' ),
				(string) $sitemaps[0]['path']
			);
		} else {
			$result = __( 'Found printing a news feed.', 'estasoft-news-distribution' );
		}

		return array(
			'status' => Status::CONFLICT,
			'result' => $result,
			'todo'   => __( 'Keeping both on can give Google two sitemaps with different contents, so pick one.', 'estasoft-news-distribution' ),
			'action' => $action,
		);
	}
}
