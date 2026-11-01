<?php
/**
 * What News Distribution can see about the site it runs on, and whether it may send.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Integrations\KnownPlugins;
use Estasoft\NewsDistribution\Integrations\SiteFetch;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Three jobs, each with a small public surface.
 *
 * 1. Staging and demo mode: `staging()` and `is_paused()` say whether pushes are
 *    paused. The Dispatcher asks through a filter that the environment module answers.
 * 2. Probes: `probes()` and `probe()` look at the site. A probe result is cached
 *    for five minutes in the transient `estasoft_nd_probe_{name}`. The probes that
 *    need a request to the site's own address (`robots`, `rest`) never run while a
 *    screen is drawn: they run when a person presses "Check again" or when the
 *    REST address `GET /environment` is asked for them.
 * 3. Support information: `support_text()` is what a person pastes to get help. It
 *    holds no secret, no email address, no article text and no visitor data.
 *
 * Shape of one probe result:
 *
 *     array(
 *       'name'       => string,  // robots, rest, page_cache, server, translation, queue, object_cache
 *       'label'      => string,  // for people
 *       'status'     => string,  // 'ok', 'info', 'warning', 'problem' or 'unknown' (not checked yet)
 *       'summary'    => string,  // one sentence
 *       'detail'     => string,  // a second sentence, or empty
 *       'data'       => array,   // machine-readable facts, see each probe
 *       'checked_at' => int,     // Unix time, 0 when never
 *       'cached'     => bool,    // true when this came from the five-minute cache
 *     )
 */
final class Environment {

	public const PROBE_TTL = 300;

	/**
	 * Probes that read the site's own address. Everything else is read locally.
	 */
	private const REMOTE_PROBES = array( 'robots', 'rest' );

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The environment type WordPress reports: local, development, staging or production.
	 *
	 * @return string
	 */
	public function detected_type(): string {
		/**
		 * Lets a test or a host replace the environment type WordPress reports (internal).
		 *
		 * @param string $type local, development, staging or production.
		 */
		return (string) apply_filters( 'estasoft_nd_internal_environment_type', wp_get_environment_type() );
	}

	/**
	 * Whether pushes are paused, and why.
	 *
	 * Automatic follows the environment type and pauses only for `staging`. Local and
	 * development are treated as live, so a developer's machine is never surprised.
	 * Demo mode pauses pushes whatever the mode says.
	 *
	 * @return array{paused: bool, reason: string, mode: string, detected: string, demo: bool}
	 *         `reason` is 'mode_on', 'detected', 'demo' or an empty string.
	 */
	public function staging(): array {
		$settings = $this->plugin->settings();
		$mode     = (string) $settings->get( 'environment.mode', 'auto' );
		$demo     = (bool) $settings->get( 'environment.demo', false );
		$detected = $this->detected_type();
		$reason   = '';

		if ( 'on' === $mode ) {
			$reason = 'mode_on';
		} elseif ( 'auto' === $mode && 'staging' === $detected ) {
			$reason = 'detected';
		} elseif ( $demo ) {
			$reason = 'demo';
		}

		if ( '' === $reason && $demo ) {
			$reason = 'demo';
		}

		return array(
			'paused'   => '' !== $reason,
			'reason'   => $reason,
			'mode'     => $mode,
			'detected' => $detected,
			'demo'     => $demo,
		);
	}

	/**
	 * Whether pushes are paused (staging or demo mode).
	 *
	 * @return bool
	 */
	public function is_paused(): bool {
		return $this->staging()['paused'];
	}

	/**
	 * The names of every probe, in the order the screen lists them.
	 *
	 * @return string[]
	 */
	public static function probe_names(): array {
		return array( 'translation', 'queue', 'object_cache', 'robots', 'rest', 'page_cache', 'server' );
	}

	/**
	 * Every probe.
	 *
	 * @param bool $run     When false, no request to the site is made: a remote probe that is not cached answers 'unknown'.
	 * @param bool $refresh Ignore the five-minute cache and look again.
	 * @return array<string, array<string, mixed>> Probe name => result.
	 */
	public function probes( bool $run = true, bool $refresh = false ): array {
		$all = array();

		foreach ( self::probe_names() as $name ) {
			$all[ $name ] = $this->probe( $name, $run, $refresh );
		}

		return $all;
	}

	/**
	 * One probe.
	 *
	 * @param string $name    A name from probe_names().
	 * @param bool   $run     When false, no request to the site is made.
	 * @param bool   $refresh Ignore the five-minute cache and look again.
	 * @return array<string, mixed>
	 */
	public function probe( string $name, bool $run = true, bool $refresh = false ): array {
		if ( ! in_array( $name, self::probe_names(), true ) ) {
			return $this->result( $name, $name, 'unknown', __( 'This check does not exist.', 'estasoft-news-distribution' ) );
		}

		$key = 'estasoft_nd_probe_' . $name;

		if ( ! $refresh ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) && isset( $cached['status'] ) ) {
				$cached['cached'] = true;

				return $cached;
			}
		}

		$remote = in_array( $name, self::REMOTE_PROBES, true );

		if ( $remote && ! $run ) {
			return $this->result( $name, $this->label_of( $name ), 'unknown', __( 'Not checked yet.', 'estasoft-news-distribution' ), __( 'Press Check again to look. This reads your own site only.', 'estasoft-news-distribution' ) );
		}

		$result = call_user_func( array( $this, 'probe_' . $name ) );

		$result['checked_at'] = Clock::now();
		$result['cached']     = false;

		set_transient( $key, $result, self::PROBE_TTL );

		return $result;
	}

	/**
	 * Forgets every cached probe.
	 *
	 * @return void
	 */
	public function forget_probes(): void {
		foreach ( self::probe_names() as $name ) {
			delete_transient( 'estasoft_nd_probe_' . $name );
		}
	}

	/**
	 * The probe results for the REST address.
	 *
	 * @param bool $refresh Look again instead of using the cache.
	 * @return array<string, mixed>
	 */
	public function report( bool $refresh = false ): array {
		return array(
			'environment_type' => $this->detected_type(),
			'staging'          => $this->staging(),
			'probes'           => $this->probes( true, $refresh ),
		);
	}

	/**
	 * Builds a probe result.
	 *
	 * @param string               $name    Probe name.
	 * @param string               $label   Label for people.
	 * @param string               $status  ok, info, warning, problem or unknown.
	 * @param string               $summary One sentence.
	 * @param string               $detail  A second sentence, or empty.
	 * @param array<string, mixed> $data    Machine-readable facts.
	 * @return array<string, mixed>
	 */
	private function result( string $name, string $label, string $status, string $summary, string $detail = '', array $data = array() ): array {
		return array(
			'name'       => $name,
			'label'      => $label,
			'status'     => $status,
			'summary'    => $summary,
			'detail'     => $detail,
			'data'       => $data,
			'checked_at' => 0,
			'cached'     => false,
		);
	}

	/**
	 * The label of a probe.
	 *
	 * @param string $name Probe name.
	 * @return string
	 */
	private function label_of( string $name ): string {
		$labels = array(
			'translation'  => __( 'Translation plugin', 'estasoft-news-distribution' ),
			'queue'        => __( 'Background queue', 'estasoft-news-distribution' ),
			'object_cache' => __( 'Object cache', 'estasoft-news-distribution' ),
			'robots'       => __( 'robots.txt', 'estasoft-news-distribution' ),
			'rest'         => __( 'REST address', 'estasoft-news-distribution' ),
			'page_cache'   => __( 'Page cache', 'estasoft-news-distribution' ),
			'server'       => __( 'Web server', 'estasoft-news-distribution' ),
		);

		return $labels[ $name ] ?? $name;
	}

	/**
	 * The translation plugin and the languages it gives the site.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_translation(): array {
		$resolver = $this->plugin->languages();
		$names    = array(
			'polylang' => 'Polylang',
			'wpml'     => 'WPML',
		);
		$label    = $this->label_of( 'translation' );

		if ( ! isset( $names[ $resolver->name() ] ) ) {
			return $this->result(
				'translation',
				$label,
				'info',
				__( 'None found.', 'estasoft-news-distribution' ),
				__( 'The site has one language. Install Polylang or WPML to publish in more.', 'estasoft-news-distribution' ),
				array(
					'plugin'    => '',
					'languages' => $resolver->languages(),
				)
			);
		}

		$codes   = array_map( 'strval', $resolver->languages() );
		$listed  = array_map(
			static function ( string $code ): string {
				return UiLanguages::native_name( $code );
			},
			$codes
		);
		$summary = sprintf(
			/* translators: %s: the name of a translation plugin, such as Polylang. */
			__( '%s: installed.', 'estasoft-news-distribution' ),
			$names[ $resolver->name() ]
		);

		return $this->result(
			'translation',
			$label,
			'ok',
			$summary,
			sprintf(
				/* translators: %s: a list of language names. */
				__( 'Its languages: %s.', 'estasoft-news-distribution' ),
				wp_sprintf_l( '%l', $listed )
			),
			array(
				'plugin'    => $resolver->name(),
				'languages' => $codes,
			)
		);
	}

	/**
	 * The background queue.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_queue(): array {
		$label = $this->label_of( 'queue' );

		if ( $this->plugin->queue()->is_available() ) {
			return $this->result( 'queue', $label, 'ok', __( 'Action Scheduler: installed.', 'estasoft-news-distribution' ), __( 'Pushes and retries run in it.', 'estasoft-news-distribution' ), array( 'available' => true ) );
		}

		return $this->result( 'queue', $label, 'problem', __( 'Action Scheduler: not installed.', 'estasoft-news-distribution' ), __( 'Without it nothing can be pushed or retried.', 'estasoft-news-distribution' ), array( 'available' => false ) );
	}

	/**
	 * The persistent object cache.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_object_cache(): array {
		$label = $this->label_of( 'object_cache' );

		if ( wp_using_ext_object_cache() ) {
			return $this->result( 'object_cache', $label, 'ok', __( 'Object cache: installed.', 'estasoft-news-distribution' ), __( 'Built feeds are kept in it.', 'estasoft-news-distribution' ), array( 'persistent' => true ) );
		}

		return $this->result( 'object_cache', $label, 'info', __( 'Object cache: not installed.', 'estasoft-news-distribution' ), __( 'No persistent cache found, so feeds are cached as transients.', 'estasoft-news-distribution' ), array( 'persistent' => false ) );
	}

	/**
	 * The robots.txt file: whether it answers and whether it blocks the feeds.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_robots(): array {
		$label = $this->label_of( 'robots' );
		$reply = SiteFetch::get( home_url( '/robots.txt' ) );

		if ( ! $reply['ok'] ) {
			return $this->result(
				'robots',
				$label,
				'warning',
				__( 'robots.txt could not be reached.', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: the reason a request failed. */
					__( 'The request failed: %s', 'estasoft-news-distribution' ),
					$reply['error']
				),
				array(
					'reachable' => false,
					'status'    => 0,
				)
			);
		}

		if ( 404 === $reply['status'] ) {
			return $this->result(
				'robots',
				$label,
				'ok',
				__( 'There is no robots.txt file.', 'estasoft-news-distribution' ),
				__( 'Crawlers may read everything, feeds included.', 'estasoft-news-distribution' ),
				array(
					'reachable' => true,
					'status'    => 404,
				)
			);
		}

		if ( $reply['status'] < 200 || $reply['status'] >= 300 ) {
			return $this->result(
				'robots',
				$label,
				'warning',
				sprintf(
					/* translators: %d: an HTTP status code such as 500. */
					__( 'robots.txt answered with status %d.', 'estasoft-news-distribution' ),
					$reply['status']
				),
				'',
				array(
					'reachable' => true,
					'status'    => $reply['status'],
				)
			);
		}

		$read = SiteFetch::parse_robots( $reply['body'] );

		if ( $read['blocks_feeds'] ) {
			return $this->result(
				'robots',
				$label,
				'warning',
				__( 'robots.txt blocks the feeds for all crawlers.', 'estasoft-news-distribution' ),
				__( 'A platform that obeys robots.txt cannot fetch them. Remove the line that blocks /feed/.', 'estasoft-news-distribution' ),
				array(
					'reachable'     => true,
					'status'        => $reply['status'],
					'blocks_feeds'  => true,
					'blocked_paths' => $read['blocked_paths'],
					'sitemaps'      => $read['sitemaps'],
				)
			);
		}

		return $this->result(
			'robots',
			$label,
			'ok',
			__( 'robots.txt is reachable.', 'estasoft-news-distribution' ),
			__( 'It does not block the feeds.', 'estasoft-news-distribution' ),
			array(
				'reachable'     => true,
				'status'        => $reply['status'],
				'blocks_feeds'  => false,
				'blocked_paths' => array(),
				'sitemaps'      => $read['sitemaps'],
			)
		);
	}

	/**
	 * The REST address.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_rest(): array {
		$label = $this->label_of( 'rest' );
		$reply = SiteFetch::get( rest_url() );

		if ( ! $reply['ok'] ) {
			return $this->result(
				'rest',
				$label,
				'problem',
				__( 'The REST address could not be reached.', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: the reason a request failed. */
					__( 'The request failed: %s', 'estasoft-news-distribution' ),
					$reply['error']
				),
				array(
					'reachable' => false,
					'status'    => 0,
				)
			);
		}

		if ( $reply['status'] >= 200 && $reply['status'] < 300 && null !== json_decode( $reply['body'], true ) ) {
			return $this->result(
				'rest',
				$label,
				'ok',
				__( 'The REST address answers.', 'estasoft-news-distribution' ),
				__( 'Settings save through it.', 'estasoft-news-distribution' ),
				array(
					'reachable' => true,
					'status'    => $reply['status'],
				)
			);
		}

		return $this->result(
			'rest',
			$label,
			'problem',
			sprintf(
				/* translators: %d: an HTTP status code such as 403. */
				__( 'The REST address answered with status %d.', 'estasoft-news-distribution' ),
				$reply['status']
			),
			__( 'A security plugin or a server rule may be blocking it. Settings still save without it.', 'estasoft-news-distribution' ),
			array(
				'reachable' => true,
				'status'    => $reply['status'],
			)
		);
	}

	/**
	 * Page cache plugins, by name only. How they are set up cannot be seen.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_page_cache(): array {
		$label = $this->label_of( 'page_cache' );
		$found = array();

		foreach ( KnownPlugins::found() as $plugin ) {
			if ( 'cache' === $plugin['kind'] ) {
				$found[] = $plugin['name'];
			}
		}

		if ( array() === $found ) {
			$summary = defined( 'WP_CACHE' ) && WP_CACHE
				? __( 'Page caching is turned on, but no known cache plugin was found.', 'estasoft-news-distribution' )
				: __( 'No page cache plugin found.', 'estasoft-news-distribution' );

			return $this->result(
				'page_cache',
				$label,
				'ok',
				$summary,
				'',
				array(
					'plugins'  => array(),
					'wp_cache' => defined( 'WP_CACHE' ) && WP_CACHE,
				)
			);
		}

		return $this->result(
			'page_cache',
			$label,
			'info',
			sprintf(
				/* translators: %s: a list of plugin names. */
				__( 'Found: %s.', 'estasoft-news-distribution' ),
				wp_sprintf_l( '%l', $found )
			),
			__( 'News Distribution cannot see whether it caches feeds. If it does, add /feed/* to its never-cached list.', 'estasoft-news-distribution' ),
			array(
				'plugins'  => $found,
				'wp_cache' => defined( 'WP_CACHE' ) && WP_CACHE,
			)
		);
	}

	/**
	 * The web server's name, without its version.
	 *
	 * @return array<string, mixed>
	 */
	private function probe_server(): array {
		$label    = $this->label_of( 'server' );
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		$known    = array(
			'litespeed'          => 'LiteSpeed',
			'openresty'          => 'OpenResty',
			'nginx'              => 'nginx',
			'apache'             => 'Apache',
			'caddy'              => 'Caddy',
			'microsoft'          => 'Microsoft IIS',
			'development server' => 'PHP development server',
		);
		$name     = '';

		foreach ( $known as $needle => $title ) {
			if ( false !== strpos( $software, $needle ) ) {
				$name = $title;
				break;
			}
		}

		if ( '' === $name ) {
			return $this->result( 'server', $label, 'info', __( 'The web server did not say what it is.', 'estasoft-news-distribution' ), '', array( 'name' => '' ) );
		}

		return $this->result(
			'server',
			$label,
			'info',
			$name,
			'',
			array( 'name' => $name )
		);
	}

	/**
	 * What to paste to get help, as lines grouped under headings.
	 *
	 * @return array<string, array<int, string>> Heading => lines.
	 */
	public function support_sections(): array {
		global $wp_version;

		$staging  = $this->staging();
		$settings = $this->plugin->settings();
		$channels = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			$channels[] = $channel->label();
		}

		$packs = array();

		foreach ( $this->plugin->rules()->all() as $platform => $pack ) {
			$packs[] = $platform . ' ' . (string) ( $pack['version'] ?? '?' );
		}

		$others = array();

		foreach ( KnownPlugins::found() as $plugin ) {
			if ( in_array( $plugin['kind'], array( 'news', 'seo', 'sitemap', 'cache' ), true ) ) {
				$others[] = trim( $plugin['name'] . ' ' . $plugin['version'] );
			}
		}

		$languages = array_map( 'strval', $this->plugin->languages()->languages() );
		$off       = array_map( 'strval', (array) $settings->get( 'languages.off', array() ) );

		$sections = array(
			__( 'Versions', 'estasoft-news-distribution' ) => array(
				'News Distribution: ' . ( defined( 'ESTASOFT_ND_VERSION' ) ? ESTASOFT_ND_VERSION : '' ),
				'WordPress: ' . (string) $wp_version,
				'PHP: ' . PHP_VERSION,
			),
			__( 'Site', 'estasoft-news-distribution' )     => array(
				'Environment type: ' . $this->detected_type(),
				'Staging mode: ' . ( $staging['paused'] ? 'on (' . $staging['reason'] . ')' : 'off' ),
				'Translation plugin: ' . ( '' === $this->plugin->languages()->name() || 'none' === $this->plugin->languages()->name() ? 'none' : $this->plugin->languages()->name() ),
				'Languages: ' . ( array() === $languages ? 'none' : implode( ', ', $languages ) ),
				'Languages turned off: ' . ( array() === $off ? 'none' : implode( ', ', $off ) ),
				'Time zone: ' . wp_timezone_string(),
			),
			__( 'Rule packs', 'estasoft-news-distribution' ) => array() === $packs ? array( 'none' ) : $packs,
			__( 'Channels that are on', 'estasoft-news-distribution' ) => array() === $channels ? array( 'none' ) : $channels,
			__( 'Plugins that touch sitemaps, feeds or tags', 'estasoft-news-distribution' ) => array() === $others ? array( 'none found' ) : $others,
		);

		$probes = array();

		foreach ( $this->probes( false ) as $probe ) {
			$probes[] = $probe['label'] . ': ' . $probe['summary'];
		}

		$sections[ __( 'Checks of the site', 'estasoft-news-distribution' ) ] = $probes;
		$sections[ __( 'The last 20 errors', 'estasoft-news-distribution' ) ] = $this->error_lines();

		return $sections;
	}

	/**
	 * The text a person pastes to get help.
	 *
	 * @return string
	 */
	public function support_text(): string {
		$lines = array(
			'Estasoft News Distribution support information',
			'Created: ' . gmdate( 'Y-m-d H:i', Clock::now() ) . ' UTC',
		);

		foreach ( $this->support_sections() as $heading => $items ) {
			$lines[] = '';
			$lines[] = '== ' . $heading . ' ==';

			foreach ( $items as $item ) {
				$lines[] = $item;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The last 20 failed deliveries, without article text.
	 *
	 * A line holds the time, the channel, the language, the event, the result and the
	 * response code, then a short message from the platform with addresses and
	 * email addresses taken out. Article titles are never read.
	 *
	 * @return string[]
	 */
	private function error_lines(): array {
		$page  = $this->plugin->log()->query(
			array(
				'status'   => Status::FAILED,
				'per_page' => 20,
			)
		);
		$lines = array();

		foreach ( $page['rows'] as $row ) {
			$message = wp_strip_all_tags( (string) $row['message'] );
			$message = (string) preg_replace( '#https?://\S+#i', '[address]', $message );
			$message = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $message );
			$message = mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $message ) ), 0, 120 );

			$lines[] = trim(
				gmdate( 'Y-m-d H:i', (int) $row['created_ts'] ) . ' UTC '
				. (string) $row['channel'] . ' '
				. (string) $row['language'] . ' '
				. (string) $row['event'] . ' '
				. (string) $row['status'] . ' '
				. ( (int) $row['response_code'] > 0 ? (string) (int) $row['response_code'] . ' ' : '' )
				. $message
			);
		}

		return array() === $lines ? array( 'none' ) : $lines;
	}
}
