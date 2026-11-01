<?php
/**
 * The links a readiness report is shared through.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SmartNews;

defined( 'ABSPATH' ) || exit;

/**
 * A link opens a snapshot of the report, made when the link was created, until
 * it expires or the owner revokes it. There is one link for each platform:
 * making a new one stops the old one. The page behind a link shows only what
 * the owner ticked, and never an admin screen, a secret or visitor data.
 *
 * The links are kept in one option that is not loaded on every request.
 */
final class ReportLinks {

	/**
	 * Where the links are kept.
	 */
	public const OPTION = 'estasoft_nd_report_links';

	/**
	 * The address segment the shared page is served under.
	 */
	public const PATH = 'news-distribution-report';

	/**
	 * How long a link can work, in days.
	 *
	 * @var int[]
	 */
	public const DAYS = array( 7, 30, 90 );

	/**
	 * Makes a link, replacing any earlier one for the platform.
	 *
	 * @param string               $platform Platform id.
	 * @param array<string, mixed> $report   The report snapshot (no secrets).
	 * @param string[]             $sections Section keys to show on the page.
	 * @param int                  $days     How long the link works.
	 * @param int                  $now      Unix time to treat as now.
	 * @return array<string, mixed> The stored link.
	 */
	public static function create( string $platform, array $report, array $sections, int $days, int $now ): array {
		$days    = in_array( $days, self::DAYS, true ) ? $days : self::DAYS[0];
		$include = array_values( array_intersect( ReportView::keys(), $sections ) );
		$link    = array(
			'token'    => wp_generate_password( 32, false, false ),
			'platform' => $platform,
			'created'  => $now,
			'expires'  => $now + $days * DAY_IN_SECONDS,
			'days'     => $days,
			'include'  => $include,
			'report'   => self::only( $report, $include ),
		);
		$before  = self::active( $platform, $now );
		$all     = self::others( $platform );

		// A new link takes the place of the old one in the application email, if the old one was attached.
		$link['attached'] = null !== $before && ! empty( $before['attached'] );

		$all[] = $link;
		update_option( self::OPTION, $all, false );

		// The shared page uses a rewrite rule; ask for it to be flushed once.
		update_option( 'estasoft_nd_rewrite_dirty', 1 );

		return $link;
	}

	/**
	 * The link that works now for a platform.
	 *
	 * @param string $platform Platform id.
	 * @param int    $now      Unix time to treat as now.
	 * @return array<string, mixed>|null
	 */
	public static function active( string $platform, int $now ): ?array {
		foreach ( self::all() as $link ) {
			if ( $platform === $link['platform'] && (int) $link['expires'] > $now ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Finds a link by its token, when it still works.
	 *
	 * @param string $token A token from an address.
	 * @param int    $now   Unix time to treat as now.
	 * @return array<string, mixed>|null
	 */
	public static function find( string $token, int $now ): ?array {
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return null;
		}

		foreach ( self::all() as $link ) {
			if ( hash_equals( (string) $link['token'], $token ) && (int) $link['expires'] > $now ) {
				return $link;
			}
		}

		return null;
	}

	/**
	 * Marks the link for a platform to be added to the application email, or
	 * takes the mark off. The Applications screen reads it with attached_url().
	 *
	 * @param string $platform Platform id.
	 * @param bool   $on       Whether to attach the link.
	 * @param int    $now      Unix time to treat as now.
	 * @return bool False when there is no link that works.
	 */
	public static function attach( string $platform, bool $on, int $now ): bool {
		$all   = self::all();
		$found = false;

		foreach ( $all as $index => $link ) {
			if ( $platform === $link['platform'] && (int) $link['expires'] > $now ) {
				$all[ $index ]['attached'] = $on;
				$found                     = true;
			}
		}

		if ( $found ) {
			update_option( self::OPTION, $all, false );
		}

		return $found;
	}

	/**
	 * The address to put in an application email for a platform: the link that
	 * works now, when the owner chose to attach it. An empty string otherwise.
	 *
	 * @param string $platform Platform id.
	 * @param int    $now      Unix time to treat as now.
	 * @return string
	 */
	public static function attached_url( string $platform, int $now ): string {
		$link = self::active( $platform, $now );

		return null !== $link && ! empty( $link['attached'] ) ? self::url( (string) $link['token'] ) : '';
	}

	/**
	 * Stops the link for a platform at once.
	 *
	 * @param string $platform Platform id.
	 * @return void
	 */
	public static function revoke( string $platform ): void {
		$rest = self::others( $platform );

		if ( array() === $rest ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $rest, false );
	}

	/**
	 * The address of the shared page. A site with pretty permalinks gets
	 * `/news-distribution-report/{token}/`; a site with plain permalinks gets the
	 * same page as a query argument, which needs no rewrite rule.
	 *
	 * @param string $token  The link's token.
	 * @param bool   $for_print Whether the address opens the print view.
	 * @return string
	 */
	public static function url( string $token, bool $for_print = false ): string {
		$url = self::pretty() ? home_url( '/' . self::PATH . '/' . $token . '/' ) : add_query_arg( ReportPage::VAR, $token, home_url( '/' ) );

		return $for_print ? add_query_arg( 'print', '1', $url ) : $url;
	}

	/**
	 * The address as a person reads it: the middle of the token is left out.
	 *
	 * @param string $token The link's token.
	 * @return string
	 */
	public static function shown( string $token ): string {
		$short = substr( $token, 0, 4 ) . '…' . substr( $token, -2 );

		return self::pretty() ? home_url( '/' . self::PATH . '/' . $short ) : home_url( '/?' . ReportPage::VAR . '=' . $short );
	}

	/**
	 * Whether the site uses pretty permalinks, so the rewrite rule can answer.
	 *
	 * @return bool
	 */
	private static function pretty(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * A report without the parts the owner did not tick, so a stored link never
	 * holds contact details or addresses the page does not show.
	 *
	 * @param array<string, mixed> $report  The report.
	 * @param string[]             $kept    Section keys that stay.
	 * @return array<string, mixed>
	 */
	private static function only( array $report, array $kept ): array {
		$parts = array(
			'publication' => 'details',
			'feeds'       => 'feeds',
			'rules'       => 'checks',
			'samples'     => 'samples',
			'contact'     => 'contact',
		);

		foreach ( $parts as $section => $key ) {
			if ( ! in_array( $section, $kept, true ) ) {
				unset( $report[ $key ] );
			}
		}

		return $report;
	}

	/**
	 * Every stored link.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$links  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $link ) {
			if ( is_array( $link ) && ! empty( $link['token'] ) && ! empty( $link['platform'] ) ) {
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * Every link except the ones for a platform.
	 *
	 * @param string $platform Platform id.
	 * @return array<int, array<string, mixed>>
	 */
	private static function others( string $platform ): array {
		return array_values(
			array_filter(
				self::all(),
				static function ( array $link ) use ( $platform ): bool {
					return $platform !== $link['platform'];
				}
			)
		);
	}
}
