<?php
/**
 * What the Network screens know about each site, read once and kept five minutes.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\LanguageResolverFactory;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Reads every site through `SiteLoop` (bounded, always restored) and keeps the answer in a site
 * transient for five minutes, so opening a Network screen never costs a pass over the network
 * more than once in that time. `forget()` drops it; the Network screens do that after a template
 * is applied or undone.
 *
 * A row, with times as ISO 8601 in UTC and status codes from `Core\Status`:
 *
 *     id, name, url, domain, admin_url, log_url,
 *     active          bool    News Distribution is active on the site
 *     status          string  healthy, attention or not_set_up
 *     needs           string[] plain sentences, most important first
 *     languages       string[] language codes
 *     channels_on, channels_total   int
 *     last_fetch      string|null
 *     ai              { assistant: on|off|needs_wordpress|blocked, provider, tokens_month, token_limit }
 */
final class SiteSummaries {

	public const CACHE = 'estasoft_nd_net_sites';
	public const TTL   = 5 * MINUTE_IN_SECONDS;

	/**
	 * The summary of every site.
	 *
	 * @param bool $fresh Read the sites again even when a kept copy exists.
	 * @return array{generated: string, rows: array<int, array<string, mixed>>, total: int, left: int, WordPress: string, cached: bool}
	 */
	public function all( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$kept = get_site_transient( self::CACHE );

			if ( is_array( $kept ) && isset( $kept['rows'], $kept['generated'] ) ) {
				$kept['cached'] = true;

				return $kept;
			}
		}

		$visit = SiteLoop::visit(
			SiteLoop::site_ids(),
			static function ( int $blog_id ): array {
				return self::summarise( $blog_id );
			}
		);

		$rows = array();

		foreach ( $visit['done'] as $blog_id => $row ) {
			$rows[] = is_wp_error( $row ) ? self::failed_row( (int) $blog_id, $row ) : $row;
		}

		$answer = array(
			'generated' => Clock::iso( Clock::now() ),
			'rows'      => $rows,
			'total'     => SiteLoop::total(),
			'left'      => count( $visit['left'] ),
			'wordpress' => (string) get_bloginfo( 'version' ),
			'cached'    => false,
		);

		set_site_transient( self::CACHE, $answer, self::TTL );

		return $answer;
	}

	/**
	 * One site's row from the summary.
	 *
	 * @param int  $blog_id Site ID.
	 * @param bool $fresh   Read again.
	 * @return array<string, mixed>|null
	 */
	public function row( int $blog_id, bool $fresh = false ): ?array {
		foreach ( $this->all( $fresh )['rows'] as $row ) {
			if ( (int) $row['id'] === $blog_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Drops the kept copy.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_site_transient( self::CACHE );
		self::forget_delivery();
	}

	/**
	 * Drops the kept delivery summaries.
	 *
	 * @return void
	 */
	public static function forget_delivery(): void {
		foreach ( Analytics::PERIODS as $period ) {
			delete_site_transient( Analytics::CACHE . $period );
		}
	}

	/**
	 * The summary of the site that is current. Call it inside `SiteLoop`.
	 *
	 * @param int $blog_id The current site's ID.
	 * @return array<string, mixed>
	 */
	public static function summarise( int $blog_id ): array {
		$url    = home_url( '/' );
		$parts  = wp_parse_url( $url );
		$domain = ( isset( $parts['host'] ) ? (string) $parts['host'] : '' ) . ( isset( $parts['path'] ) ? untrailingslashit( (string) $parts['path'] ) : '' );
		$name   = trim( (string) get_option( 'blogname', '' ) );

		$row = array(
			'id'             => $blog_id,
			'name'           => '' !== $name ? $name : $domain,
			'url'            => $url,
			'domain'         => $domain,
			'admin_url'      => admin_url( 'admin.php?page=estasoft-nd' ),
			'log_url'        => admin_url( 'admin.php?page=estasoft-nd-log' ),
			'active'         => self::plugin_is_active(),
			'status'         => Status::NOT_SET_UP,
			'needs'          => array(),
			'languages'      => array(),
			'channels_on'    => 0,
			'channels_total' => 0,
			'last_fetch'     => null,
			'ai'             => SiteAi::read( $blog_id ),
		);

		if ( ! $row['active'] ) {
			$row['needs'] = array( __( 'News Distribution is not active on this site.', 'estasoft-news-distribution' ) );

			return $row;
		}

		$row['languages']  = array_values( array_map( 'strval', LanguageResolverFactory::detect()->languages() ) );
		$fetched           = DeliveryQueries::last_fetch();
		$row['last_fetch'] = $fetched > 0 ? Clock::iso( $fetched ) : null;

		$channels              = SiteChannels::build();
		$row['channels_total'] = count( $channels );
		$configured            = self::any_channel_configured();
		$has_log               = DeliveryQueries::has_table();
		$labels                = array();
		$attention             = array();

		foreach ( $channels as $id => $channel ) {
			$labels[ $id ] = $channel->label();

			if ( ! $channel->is_enabled() ) {
				continue;
			}

			++$row['channels_on'];

			if ( $has_log && Status::ATTENTION === $channel->health() ) {
				$attention[ $id ] = $channel->label();
			}
		}

		if ( ! $configured ) {
			// A channel that is on by default is not "set up": nobody on this site has chosen it yet.
			$row['channels_on'] = 0;

			return $row;
		}

		$day    = Clock::now() - DAY_IN_SECONDS;
		$failed = array();

		foreach ( DeliveryQueries::by_channel( $day ) as $id => $counts ) {
			if ( $counts['failed'] > 0 ) {
				$failed[ $id ] = $counts['failed'];
			}
		}

		if ( array() !== $failed ) {
			$total = array_sum( $failed );
			$names = array_map(
				static function ( string $id ) use ( $labels ): string {
					return $labels[ $id ] ?? $id;
				},
				array_keys( $failed )
			);

			$row['needs'][] = sprintf(
				/* translators: 1: number of failed deliveries, 2: channel names, such as "WebSub and IndexNow", 3: number of attempts each. */
				_n( '%1$d failed delivery to %2$s, after %3$d attempts each', '%1$d failed deliveries to %2$s, after %3$d attempts each', $total, 'estasoft-news-distribution' ),
				$total,
				wp_sprintf_l( '%l', $names ),
				Retry::MAX_ATTEMPTS
			);

			// A channel already named above does not need a second line.
			$attention = array_diff_key( $attention, $failed );
		}

		if ( array() !== $attention ) {
			$row['needs'][] = sprintf(
				/* translators: 1: number of channels, 2: their names, such as "Google News, Microsoft Start". */
				_n( '%1$d channel: %2$s', '%1$d channels: %2$s', count( $attention ), 'estasoft-news-distribution' ),
				count( $attention ),
				implode( ', ', array_values( $attention ) )
			);
		}

		$slowest = DeliveryQueries::slowest( $day );

		if ( $slowest > DeliveryQueries::SLOW_SECONDS ) {
			$row['needs'][] = sprintf(
				/* translators: 1: seconds the slowest delivery took, 2: the number of seconds over which a delivery is called slow. */
				__( 'Slow deliveries: the slowest took %1$d s. Slow means over %2$d s.', 'estasoft-news-distribution' ),
				$slowest,
				DeliveryQueries::SLOW_SECONDS
			);
		}

		$row['status'] = array() === $row['needs'] ? Status::HEALTHY : Status::ATTENTION;

		return $row;
	}

	/**
	 * A row for a site that could not be read.
	 *
	 * @param int       $blog_id Site ID.
	 * @param \WP_Error $error   Why.
	 * @return array<string, mixed>
	 */
	private static function failed_row( int $blog_id, \WP_Error $error ): array {
		$site = get_site( $blog_id );

		return array(
			'id'             => $blog_id,
			'name'           => $site instanceof \WP_Site ? trim( $site->domain . $site->path, '/' ) : (string) $blog_id,
			'url'            => $site instanceof \WP_Site ? get_home_url( $blog_id, '/' ) : '',
			'domain'         => $site instanceof \WP_Site ? trim( $site->domain . $site->path, '/' ) : '',
			'admin_url'      => get_admin_url( $blog_id, 'admin.php?page=estasoft-nd' ),
			'log_url'        => get_admin_url( $blog_id, 'admin.php?page=estasoft-nd-log' ),
			'active'         => false,
			'status'         => Status::ATTENTION,
			'needs'          => array(
				sprintf(
					/* translators: %s: the reason a site could not be read. */
					__( 'This site could not be read: %s', 'estasoft-news-distribution' ),
					$error->get_error_message()
				),
			),
			'languages'      => array(),
			'channels_on'    => 0,
			'channels_total' => 0,
			'last_fetch'     => null,
			'ai'             => array(
				'assistant'    => 'off',
				'provider'     => '',
				'tokens_month' => 0,
				'token_limit'  => 0,
			),
		);
	}

	/**
	 * Whether News Distribution is active on the current site: for the network or on the site itself.
	 *
	 * @return bool
	 */
	private static function plugin_is_active(): bool {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$basename = plugin_basename( ESTASOFT_ND_FILE );

		return is_plugin_active_for_network( $basename ) || in_array( $basename, (array) get_option( 'active_plugins', array() ), true );
	}

	/**
	 * Whether anyone ever switched a channel on, on the current site.
	 *
	 * @return bool
	 */
	private static function any_channel_configured(): bool {
		$stored = get_option( 'estasoft_nd_channels', array() );

		if ( ! is_array( $stored ) ) {
			return false;
		}

		foreach ( $stored as $settings ) {
			if ( is_array( $settings ) && ! empty( $settings['enabled'] ) ) {
				return true;
			}
		}

		return false;
	}
}
