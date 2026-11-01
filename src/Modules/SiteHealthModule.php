<?php
/**
 * Tests and facts for WordPress's own Site Health screen.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Overview\Facts;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\ClockCheck;
use Estasoft\NewsDistribution\Core\CachePurge;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Frontend\Rewrite;
use Estasoft\NewsDistribution\Integrations\KnownPlugins;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds News Distribution to Tools, Site Health:
 *
 * - **Status.** Direct tests, each with the badge "News Distribution": permalinks, the background
 *   queue, the news sitemap and the feeds can be reached, the time zone, a caching plugin, and a news
 *   sitemap Google has never fetched. A test that does not apply to this site is not added.
 * - **Info.** One section with the same facts as Settings, Environment, "Support information". It
 *   never holds a key, a token, a password, an email address or article text.
 *
 * No test makes a request over the network. "Can be reached" reads WordPress's own address rules
 * (the same rules that decide where a request goes), so it says that WordPress would send the
 * address to News Distribution. It cannot see a CDN or a firewall that stands in front of the site;
 * only the Outside-in self-test report asks from outside.
 */
final class SiteHealthModule implements ModuleInterface {

	public const SECTION = 'estasoft-news-distribution';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'site_status_tests', array( $this, 'tests' ) );
		add_filter( 'debug_information', array( $this, 'debug' ) );
	}

	/**
	 * Adds the direct tests.
	 *
	 * @param mixed $tests WordPress's tests: `direct` and `async`.
	 * @return array<string, mixed>
	 */
	public function tests( $tests ): array {
		$tests           = is_array( $tests ) ? $tests : array();
		$tests['direct'] = isset( $tests['direct'] ) && is_array( $tests['direct'] ) ? $tests['direct'] : array();
		$pretty          = '' !== (string) get_option( 'permalink_structure' );
		$own             = array(
			'estasoft_nd_permalinks' => array( __( 'Permalinks allow sitemap addresses', 'estasoft-news-distribution' ), 'test_permalinks' ),
			'estasoft_nd_queue'      => array( __( 'The background queue is running', 'estasoft-news-distribution' ), 'test_queue' ),
		);

		// Addresses cannot answer under plain permalinks, and the first test already says so.
		if ( $pretty && $this->channel_on( 'google-news' ) ) {
			$own['estasoft_nd_sitemap'] = array( __( 'The news sitemap can be reached', 'estasoft-news-distribution' ), 'test_sitemap' );
		}

		if ( $pretty && array() !== $this->feed_slugs() ) {
			$own['estasoft_nd_feeds'] = array( __( 'Your feeds can be reached', 'estasoft-news-distribution' ), 'test_feeds' );
		}

		$own['estasoft_nd_timezone'] = array( __( 'The WordPress time zone is a fixed offset', 'estasoft-news-distribution' ), 'test_timezone' );

		if ( array() !== KnownPlugins::found() && $this->has_cache_plugin() ) {
			$own['estasoft_nd_cache'] = array( __( 'A caching plugin may serve old feeds', 'estasoft-news-distribution' ), 'test_cache' );
		}

		if ( $this->channel_on( 'google-news' ) ) {
			$own['estasoft_nd_fetched'] = array( __( 'No news sitemap is waiting for Google', 'estasoft-news-distribution' ), 'test_fetched' );
		}

		foreach ( $own as $id => $test ) {
			$tests['direct'][ $id ] = array(
				'label' => $test[0],
				'test'  => array( $this, $test[1] ),
			);
		}

		return $tests;
	}

	/**
	 * A test result in the shape Site Health reads.
	 *
	 * @param string $test    The test's id.
	 * @param string $status  good, recommended or critical.
	 * @param string $label   The test's title as it reads for this result (plain).
	 * @param string $text    One or two sentences (plain), each paragraph separated by a blank line.
	 * @param string $action  Button text (plain), or empty.
	 * @param string $url     Where the button goes.
	 * @return array<string, mixed>
	 */
	private function result( string $test, string $status, string $label, string $text, string $action = '', string $url = '' ): array {
		$paragraphs = '';

		foreach ( explode( "\n\n", $text ) as $paragraph ) {
			$paragraphs .= '<p>' . esc_html( trim( $paragraph ) ) . '</p>';
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'News Distribution', 'estasoft-news-distribution' ),
				'color' => 'blue',
			),
			'description' => $paragraphs,
			'actions'     => '' === $action ? '' : '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html( $action ) . '</a></p>',
			'test'        => $test,
		);
	}

	/**
	 * Whether a channel is on.
	 *
	 * @param string $id Channel id.
	 * @return bool
	 */
	private function channel_on( string $id ): bool {
		$channel = $this->plugin->registry()->get( $id );

		return null !== $channel && $channel->is_enabled();
	}

	/**
	 * The feed addresses of the pulled channels that are on, apart from the news sitemap.
	 *
	 * @return array<string, string> Channel id => the path under the site address, such as "feed/flipboard".
	 */
	private function feed_slugs(): array {
		$slugs = array();

		foreach ( $this->plugin->registry()->pulled() as $channel ) {
			$slug = $channel->feed_slug();

			if ( '' !== $slug && 'google-news' !== $channel->id() && $channel->is_enabled() ) {
				$slugs[ $channel->id() ] = 'feed/' . $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Whether WordPress would send an address to News Distribution, read from its own address rules.
	 *
	 * Nothing is requested. The rules are matched the way WordPress matches them, in order, so a rule of
	 * another plugin that comes first and takes the address is seen.
	 *
	 * @param string $path The address under the site's address, such as "feed/google-news".
	 * @return bool
	 */
	private function answers( string $path ): bool {
		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) || array() === $rules ) {
			return false;
		}

		$path = trim( $path, '/' );

		foreach ( $rules as $pattern => $query ) {
			if ( 1 === @preg_match( '#^' . $pattern . '#', $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A broken pattern from another plugin must not stop the test.
				return false !== strpos( (string) $query, Rewrite::VAR_FEED . '=' );
			}
		}

		return false;
	}

	/**
	 * The test: permalinks.
	 *
	 * @return array<string, mixed>
	 */
	public function test_permalinks(): array {
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return $this->result(
				'estasoft_nd_permalinks',
				'good',
				__( 'Permalinks allow sitemap addresses', 'estasoft-news-distribution' ),
				__( 'WordPress reads addresses such as /feed/google-news/, so feeds and sitemaps can answer.', 'estasoft-news-distribution' )
			);
		}

		return $this->result(
			'estasoft_nd_permalinks',
			'critical',
			__( 'Permalinks are plain, so feeds and sitemaps cannot answer', 'estasoft-news-distribution' ),
			__( 'With plain permalinks WordPress ignores addresses such as /feed/google-news/. Choose any other permalink structure and save.', 'estasoft-news-distribution' ),
			__( 'Open Permalinks', 'estasoft-news-distribution' ),
			admin_url( 'options-permalink.php' )
		);
	}

	/**
	 * The test: the background queue.
	 *
	 * @return array<string, mixed>
	 */
	public function test_queue(): array {
		$fix = array( __( 'How to fix this', 'estasoft-news-distribution' ), EnvironmentModule::screen_url() );

		if ( ! $this->plugin->queue()->is_available() ) {
			return $this->result(
				'estasoft_nd_queue',
				'critical',
				__( 'The background queue is not available', 'estasoft-news-distribution' ),
				__( 'Action Scheduler is not loaded, so pushes cannot be sent or tried again. Pulled sitemaps and feeds still work.', 'estasoft-news-distribution' ),
				$fix[0],
				$fix[1]
			);
		}

		$since = Facts::queue_stalled_since( $this->plugin );

		if ( $since > 0 ) {
			$hours = (int) floor( ( Clock::now() - $since ) / HOUR_IN_SECONDS );

			return $this->result(
				'estasoft_nd_queue',
				'critical',
				__( 'The background queue is not running', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %d: a number of hours. */
					_n( 'Action Scheduler has not run for %d hour, so pushes are waiting. Pulled sitemaps and feeds still work.', 'Action Scheduler has not run for %d hours, so pushes are waiting. Pulled sitemaps and feeds still work.', $hours, 'estasoft-news-distribution' ),
					$hours
				),
				$fix[0],
				$fix[1]
			);
		}

		return $this->result(
			'estasoft_nd_queue',
			'good',
			__( 'The background queue is running', 'estasoft-news-distribution' ),
			__( 'Action Scheduler is installed, and no push is waiting past its time.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The line every "can be reached" test ends with.
	 *
	 * @return string
	 */
	private function local_note(): string {
		return __( 'This was read from the address rules WordPress uses, without a request over the network. It cannot see a CDN or a firewall in front of your site; the Outside-in self-test report asks from outside.', 'estasoft-news-distribution' );
	}

	/**
	 * The test: the news sitemap.
	 *
	 * @return array<string, mixed>
	 */
	public function test_sitemap(): array {
		if ( $this->answers( 'feed/google-news' ) ) {
			return $this->result(
				'estasoft_nd_sitemap',
				'good',
				__( 'The news sitemap can be reached', 'estasoft-news-distribution' ),
				__( 'WordPress sends /feed/google-news/ to News Distribution.', 'estasoft-news-distribution' ) . "\n\n" . $this->local_note(),
				__( 'Open the self-test report', 'estasoft-news-distribution' ),
				Links::admin( 'estasoft-nd-reports', array( 'report' => 'self-test' ) )
			);
		}

		return $this->result(
			'estasoft_nd_sitemap',
			'critical',
			__( 'The news sitemap address is not answered by News Distribution', 'estasoft-news-distribution' ),
			__( 'WordPress does not send /feed/google-news/ to News Distribution. Another plugin’s address rule may come first, or the rules were not saved after the plugin was turned on. Saving the permalinks makes WordPress read them again.', 'estasoft-news-distribution' ),
			__( 'Open Permalinks', 'estasoft-news-distribution' ),
			admin_url( 'options-permalink.php' )
		);
	}

	/**
	 * The test: the feeds.
	 *
	 * @return array<string, mixed>
	 */
	public function test_feeds(): array {
		$missing = array();

		foreach ( $this->feed_slugs() as $id => $path ) {
			if ( ! $this->answers( $path ) ) {
				$channel   = $this->plugin->registry()->get( (string) $id );
				$missing[] = null === $channel ? (string) $id : $channel->label();
			}
		}

		if ( array() === $missing ) {
			return $this->result(
				'estasoft_nd_feeds',
				'good',
				__( 'Your feeds can be reached', 'estasoft-news-distribution' ),
				__( 'WordPress sends the address of each feed that is on to News Distribution.', 'estasoft-news-distribution' ) . "\n\n" . $this->local_note(),
				__( 'Open the self-test report', 'estasoft-news-distribution' ),
				Links::admin( 'estasoft-nd-reports', array( 'report' => 'self-test' ) )
			);
		}

		return $this->result(
			'estasoft_nd_feeds',
			'critical',
			__( 'A feed address is not answered by News Distribution', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %s: a list of channel names. */
				__( 'WordPress does not send the address of %s to News Distribution. Another plugin’s address rule may come first. Saving the permalinks makes WordPress read the rules again.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $missing )
			),
			__( 'Open Permalinks', 'estasoft-news-distribution' ),
			admin_url( 'options-permalink.php' )
		);
	}

	/**
	 * A fixed offset such as UTC+2, from the stored number of hours.
	 *
	 * @param float $offset Hours.
	 * @return string
	 */
	private function offset_label( float $offset ): string {
		$sign    = $offset < 0 ? '-' : '+';
		$hours   = (int) floor( abs( $offset ) );
		$minutes = (int) round( ( abs( $offset ) - $hours ) * 60 );

		return 'UTC' . $sign . $hours . ( $minutes > 0 ? ':' . sprintf( '%02d', $minutes ) : '' );
	}

	/**
	 * The test: the time zone.
	 *
	 * @return array<string, mixed>
	 */
	public function test_timezone(): array {
		$zone = ( new ClockCheck( $this->plugin ) )->zone();

		if ( 'offset' !== $zone['kind'] ) {
			return $this->result(
				'estasoft_nd_timezone',
				'good',
				__( 'The WordPress time zone follows a place', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: a time zone such as Europe/Amsterdam or UTC. */
					__( 'The time zone is set to %s.', 'estasoft-news-distribution' ),
					(string) $zone['string']
				)
			);
		}

		return $this->result(
			'estasoft_nd_timezone',
			'recommended',
			__( 'The WordPress time zone is a fixed offset', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %s: a fixed offset such as UTC+2. */
				__( 'The time zone is set to %s. When daylight saving starts or ends, times in your news sitemap are an hour wrong for part of the year.', 'estasoft-news-distribution' ),
				$this->offset_label( (float) get_option( 'gmt_offset' ) )
			) . "\n\n" . __( 'Choose a city instead, such as Europe/Amsterdam.', 'estasoft-news-distribution' ),
			__( 'Open Clock and time zone', 'estasoft-news-distribution' ),
			Links::settings( 'clock' )
		);
	}

	/**
	 * Whether a caching plugin is active.
	 *
	 * @return bool
	 */
	private function has_cache_plugin(): bool {
		foreach ( KnownPlugins::found() as $plugin ) {
			if ( 'cache' === $plugin['kind'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The test: a caching plugin.
	 *
	 * @return array<string, mixed>
	 */
	public function test_cache(): array {
		$names = array();

		foreach ( KnownPlugins::found() as $plugin ) {
			if ( 'cache' === $plugin['kind'] ) {
				$names[] = $plugin['name'];
			}
		}

		$purge  = $this->plugin->make( CachePurge::class );
		$asked  = array();
		$kinds  = $purge->what();
		$on     = in_array( CachePurge::WHAT_FEEDS, $kinds, true ) || in_array( CachePurge::WHAT_SITEMAPS, $kinds, true );
		$action = array( __( 'Open Caching', 'estasoft-news-distribution' ), Links::settings( 'caching' ) );

		foreach ( $purge->installed() as $adapter ) {
			$asked[] = $adapter->label();
		}

		if ( $on && array() !== $asked && count( $asked ) >= count( $names ) ) {
			return $this->result(
				'estasoft_nd_cache',
				'good',
				__( 'Your caching plugin is asked to drop old feeds', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: a list of caching plugin names. */
					__( 'When an article changes, News Distribution asks %s to drop the stored copies of the feeds and sitemaps that changed.', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $asked )
				),
				$action[0],
				$action[1]
			);
		}

		return $this->result(
			'estasoft_nd_cache',
			'recommended',
			__( 'A caching plugin may serve old feeds', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %s: a list of caching plugin names. */
				__( '%s is active. A page cache can keep sending an old copy of a feed or a sitemap after you publish.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $names )
			) . "\n\n" . __( 'Ask the plugin to leave /feed/ addresses out of its page cache, or check that News Distribution is allowed to clear them.', 'estasoft-news-distribution' ),
			$action[0],
			$action[1]
		);
	}

	/**
	 * The test: a news sitemap Google has never fetched.
	 *
	 * @return array<string, mixed>
	 */
	public function test_fetched(): array {
		$channel = $this->plugin->registry()->get( 'google-news' );
		$fix     = array( __( 'Open Site verification', 'estasoft-news-distribution' ), Links::channel( 'verification' ) );
		$since   = (int) $this->plugin->channel_settings()->get( 'google-news' )['enabled_since'];
		$never   = array();

		if ( null !== $channel && ( 0 === $since || $since < Clock::now() - ( 3 * DAY_IN_SECONDS ) ) ) {
			foreach ( Facts::fetch_states( $this->plugin, $channel ) as $code => $state ) {
				if ( Status::NEVER === $state['status'] ) {
					$never[] = Catalog::name( (string) $code );
				}
			}
		}

		if ( array() === $never ) {
			return $this->result(
				'estasoft_nd_fetched',
				'good',
				__( 'No news sitemap is waiting for Google', 'estasoft-news-distribution' ),
				__( 'No news sitemap that is on has waited more than three days for its first visit from Google, as far as your own server saw.', 'estasoft-news-distribution' )
			);
		}

		return $this->result(
			'estasoft_nd_fetched',
			'recommended',
			1 === count( $never )
				? sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( 'Google has never fetched the %s news sitemap', 'estasoft-news-distribution' ),
					$never[0]
				)
				: sprintf(
					/* translators: %d: a number of news sitemaps. */
					__( 'Google has never fetched %d of your news sitemaps', 'estasoft-news-distribution' ),
					count( $never )
				),
			sprintf(
				/* translators: %s: a list of language names. */
				__( 'The news sitemaps that Google has not read are: %s. News Distribution sees a fetch when Google reads the address from your server. Google does not know an address you have not submitted.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $never )
			) . "\n\n" . __( 'Check that each address is submitted in Search Console.', 'estasoft-news-distribution' ),
			$fix[0],
			$fix[1]
		);
	}

	/**
	 * Adds the Info section: the same facts as the support information of Settings, Environment.
	 *
	 * @param mixed $info WordPress's sections.
	 * @return array<string, mixed>
	 */
	public function debug( $info ): array {
		$info        = is_array( $info ) ? $info : array();
		$environment = new Environment( $this->plugin );
		$staging     = $environment->staging();
		$settings    = $this->plugin->settings();

		$packs = array();

		foreach ( $this->plugin->rules()->all() as $platform => $pack ) {
			$packs[] = $platform . ' ' . (string) ( $pack['version'] ?? '?' );
		}

		$on = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			$on[] = $channel->label();
		}

		$languages = array();
		$off       = array_map( 'strval', (array) $settings->get( 'languages.off', array() ) );

		foreach ( $this->plugin->languages()->languages() as $code ) {
			$languages[] = UiLanguages::native_name( (string) $code ) . ( in_array( (string) $code, $off, true ) ? ' (' . __( 'turned off', 'estasoft-news-distribution' ) . ')' : '' );
		}

		$others = array();

		foreach ( KnownPlugins::found() as $plugin ) {
			if ( in_array( $plugin['kind'], array( 'news', 'seo', 'sitemap', 'cache' ), true ) ) {
				$others[] = trim( $plugin['name'] . ' ' . $plugin['version'] );
			}
		}

		$failed = $this->plugin->log()->query(
			array(
				'status'   => Status::FAILED,
				'per_page' => 20,
			)
		)['rows'];

		$fields = array(
			'version'   => array( __( 'Version', 'estasoft-news-distribution' ), defined( 'ESTASOFT_ND_VERSION' ) ? ESTASOFT_ND_VERSION : '' ),
			'packs'     => array( __( 'Rule packs', 'estasoft-news-distribution' ), array() === $packs ? __( 'None', 'estasoft-news-distribution' ) : implode( ', ', $packs ) ),
			'channels'  => array( __( 'Channels that are on', 'estasoft-news-distribution' ), array() === $on ? __( 'None', 'estasoft-news-distribution' ) : implode( ', ', $on ) ),
			'languages' => array( __( 'Languages', 'estasoft-news-distribution' ), array() === $languages ? __( 'None', 'estasoft-news-distribution' ) : implode( ', ', $languages ) ),
			'queue'     => array( __( 'Background queue', 'estasoft-news-distribution' ), $this->queue_line() ),
			'staging'   => array(
				__( 'Staging mode', 'estasoft-news-distribution' ),
				$staging['paused']
					? sprintf(
						/* translators: %s: why pushes are held: mode_on, detected or demo. */
						__( 'On (%s)', 'estasoft-news-distribution' ),
						(string) $staging['reason']
					)
					: __( 'Off', 'estasoft-news-distribution' ),
			),
			'plugins'   => array( __( 'Plugins that touch sitemaps, feeds or tags', 'estasoft-news-distribution' ), array() === $others ? __( 'None found', 'estasoft-news-distribution' ) : implode( ', ', $others ) ),
			'timezone'  => array( __( 'Time zone', 'estasoft-news-distribution' ), wp_timezone_string() ),
			'errors'    => array(
				__( 'Last 20 errors', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %d: a number of errors. */
					_n( '%d, without article text', '%d, without article text', count( $failed ), 'estasoft-news-distribution' ),
					count( $failed )
				),
			),
		);

		$rows = array();

		foreach ( $fields as $key => $field ) {
			$rows[ $key ] = array(
				'label' => $field[0],
				'value' => (string) $field[1],
			);
		}

		$info[ self::SECTION ] = array(
			'label'       => __( 'Estasoft News Distribution', 'estasoft-news-distribution' ),
			'description' => __( 'The same facts as Settings, Environment, Support information. It holds no key, token, password, email address or article text.', 'estasoft-news-distribution' ),
			'fields'      => $rows,
		);

		return $info;
	}

	/**
	 * "Action Scheduler 3.8.1, running" for the Info section.
	 *
	 * @return string
	 */
	private function queue_line(): string {
		if ( ! $this->plugin->queue()->is_available() ) {
			return __( 'Action Scheduler is not installed', 'estasoft-news-distribution' );
		}

		$version = class_exists( '\ActionScheduler_Versions' ) ? (string) \ActionScheduler_Versions::instance()->latest_version() : '';
		$name    = trim( 'Action Scheduler ' . $version );

		return 0 === Facts::queue_stalled_since( $this->plugin )
			? sprintf(
				/* translators: %s: a name such as "Action Scheduler 3.8.1". */
				__( '%s, running', 'estasoft-news-distribution' ),
				$name
			)
			: sprintf(
				/* translators: %s: a name such as "Action Scheduler 3.8.1". */
				__( '%s, pushes are overdue', 'estasoft-news-distribution' ),
				$name
			);
	}
}
