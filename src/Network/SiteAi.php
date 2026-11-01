<?php
/**
 * Whether the AI assistant can be used on one site, for the Network screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Ai\Consent;
use Estasoft\NewsDistribution\Ai\Usage;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the current site's own AI choices (the `ai` section of its settings) and the network
 * policy, and answers one of six things:
 *
 * - `needs_wordpress`: WordPress is older than 7.0, which has no AI Client.
 * - `blocked`: the network policy allows no provider, so the assistant is off everywhere.
 * - `on`: the site's owner turned the assistant on.
 * - `off`: the site's owner has not (the default).
 * - `paused_network`: it is on, and the network's monthly AI budget is used (`AiBudget`), so it is paused on every site.
 * - `paused_site`: it is on, and this site has used its own monthly limit (held to the network's cap), so it is paused here only.
 *
 * The tokens used this month and the provider come from the site's own AI counters and log. The
 * `estasoft_nd_internal_network_site_ai` filter lets the AI code correct them. Without them the
 * screen shows a dash.
 */
final class SiteAi {

	/**
	 * The first WordPress version that has the AI Client.
	 */
	public const MIN_WORDPRESS = '7.0';

	/**
	 * Whether this WordPress has the AI Client at all.
	 *
	 * @return bool
	 */
	public static function wordpress_ok(): bool {
		return version_compare( (string) get_bloginfo( 'version' ), self::MIN_WORDPRESS, '>=' );
	}

	/**
	 * The AI row of the site that is current. Call it inside `SiteLoop`.
	 *
	 * The switch and the monthly limit are the site's own settings (`ai.enabled`, `ai.monthly_tokens`),
	 * held to the network's cap; the tokens are the site's counter for this month; the provider is the one
	 * named on the newest row of the site's AI log. A site that has no log table, or no row yet, shows no provider.
	 *
	 * @param int $blog_id The current site's ID.
	 * @return array{assistant: string, provider: string, tokens_month: int, token_limit: int}
	 */
	public static function read( int $blog_id ): array {
		$stored = get_option( 'estasoft_nd_settings', array() );
		$own    = is_array( $stored ) && isset( $stored['ai'] ) && is_array( $stored['ai'] ) ? $stored['ai'] : array();
		$policy = AiPolicy::get();

		if ( ! self::wordpress_ok() ) {
			$state = 'needs_wordpress';
		} elseif ( ! $policy['allowed'] ) {
			$state = 'blocked';
		} else {
			$state = ! empty( $own['enabled'] ) && self::consented() ? 'on' : 'off';
		}

		$limit = max( 1, (int) ( $own['monthly_tokens'] ?? 2000000 ) );
		$cap   = (int) $policy['max_tokens_month'];

		$row = array(
			'assistant'    => $state,
			'provider'     => 'on' === $state ? self::provider() : '',
			'tokens_month' => 'on' === $state ? self::tokens() : 0,
			'token_limit'  => $cap > 0 ? min( $limit, $cap ) : $limit,
		);

		/**
		 * Lets the AI workstream or another plugin correct the provider and the tokens a site shows.
		 *
		 * Called with the site current. Keys: `provider` (a provider id), `tokens_month`, `token_limit`.
		 *
		 * @param array<string, mixed> $row     The row so far.
		 * @param int                  $blog_id The site.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_network_site_ai', $row, $blog_id );

		if ( is_array( $filtered ) ) {
			$row = array(
				'assistant'    => $row['assistant'],
				'provider'     => isset( $filtered['provider'] ) && is_string( $filtered['provider'] ) ? sanitize_key( $filtered['provider'] ) : $row['provider'],
				'tokens_month' => max( 0, (int) ( $filtered['tokens_month'] ?? $row['tokens_month'] ) ),
				'token_limit'  => max( 0, (int) ( $filtered['token_limit'] ?? $row['token_limit'] ) ),
			);
		}

		$row['assistant'] = self::paused( $row );

		return $row;
	}

	/**
	 * The state of a site whose assistant is on, with a limit held in mind: paused by the network's budget, by its own
	 * limit, or still on. Any other state is returned as it is.
	 *
	 * @param array{assistant: string, provider: string, tokens_month: int, token_limit: int} $row The row so far.
	 * @return string
	 */
	public static function paused( array $row ): string {
		if ( 'on' !== $row['assistant'] ) {
			return (string) $row['assistant'];
		}

		if ( AiBudget::exhausted() ) {
			return 'paused_network';
		}

		return $row['token_limit'] > 0 && $row['tokens_month'] >= $row['token_limit'] ? 'paused_site' : 'on';
	}

	/**
	 * Whether the site's owner has recorded consent to send text to a provider.
	 *
	 * @return bool
	 */
	private static function consented(): bool {
		$record = get_option( Consent::OPTION, array() );

		return is_array( $record ) && ! empty( $record['at'] );
	}

	/**
	 * Tokens the site used this month, from its own counter, in the site's time zone. The site that is current.
	 *
	 * @return int
	 */
	public static function tokens(): int {
		$usage = get_option( Usage::OPTION, array() );

		if ( ! is_array( $usage ) || wp_date( 'Y-m', Clock::now(), Clock::zone() ) !== ( $usage['month'] ?? '' ) ) {
			return 0;
		}

		return max( 0, (int) ( $usage['tokens'] ?? 0 ) );
	}

	/**
	 * Tokens the site used in one month, the network's month, which is counted in UTC.
	 *
	 * A site keeps its own count by its own calendar month. For a few hours around a month change the site's calendar
	 * is not in the network's month, and what it has counted belongs to the other month, so it is not read then.
	 *
	 * @param string $month The network's month, such as "2026-11".
	 * @return int Tokens, or -1 when the site's own calendar is not in that month yet (or no longer).
	 */
	public static function tokens_in( string $month ): int {
		if ( wp_date( 'Y-m', Clock::now(), Clock::zone() ) !== $month ) {
			return -1;
		}

		return self::tokens();
	}

	/**
	 * The provider named on the newest row of the site's AI log.
	 *
	 * @return string A provider id of the network list, or an empty string.
	 */
	private static function provider(): string {
		global $wpdb;

		$table = Schema::table( 'ai_log' );

		if ( ! Tables::exists( $table ) ) {
			return '';
		}

		$connection = (string) $wpdb->get_var( $wpdb->prepare( "SELECT connection FROM %i WHERE connection <> '' ORDER BY id DESC LIMIT 1", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( array_keys( AiPolicy::providers() ) as $id ) {
			if ( 0 === stripos( $connection, (string) $id ) ) {
				return (string) $id;
			}
		}

		return '';
	}
}
