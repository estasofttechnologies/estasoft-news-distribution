<?php
/**
 * The AI policy tab's data: the policy, and what each site does with it.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One builder for the AI policy screen and `GET|PUT /network/ai-policy`.
 *
 * Shape:
 *
 *     {
 *       policy            see AiPolicy (no secrets exist in it)
 *       provider_options  [ { id, label, allowed } ]
 *       locked            { authors: true }
 *       WordPress         { version, minimum, ok }
 *       qualifying        { sites, total, names_not }       sites that can use the assistant today
 *       usage             { tokens_month, limit, percent, month, exhausted, resets, alert_at, alerts, left }
 *       sites             [ { id, name, assistant, provider, provider_name, tokens_month, token_limit, percent } ]
 *       updated, updated_by
 *     }
 *
 * `usage` is the network's running total this month (`AiBudget`): `tokens_month` of `limit` (0 is no limit),
 * the `percent` used, the `month` as `YYYY-MM` (UTC), whether the budget is `exhausted`, when the count starts
 * again (`resets`, ISO 8601), the percent the network wants to be told at (`alert_at`) and `alerts`, what was done
 * about this month's emails: `[ { threshold, state, at } ]` where `state` is queued, sending, sent, retry, failed
 * or superseded and `at` is ISO 8601. `left` is the number of sites a recount did not reach in time (0 when the
 * counters were not recounted for this answer).
 *
 * `sites` is sorted by the tokens used this month, most first, then by name. `assistant` is one of on, off,
 * needs_wordpress, blocked, paused_network (it is on and the network's budget is used) or paused_site (it is on and the
 * site used its own limit). The tokens come from the network's counter for that site. The provider comes
 * from each site through the `estasoft_nd_internal_network_site_ai` filter.
 *
 * A recount from the sites (`AiBudget::rebuild()`) runs when `$fresh` is true and the first time in a month.
 */
final class AiOverview {

	/**
	 * Builds the answer.
	 *
	 * @param bool $fresh Read the sites again.
	 * @return array<string, mixed>
	 */
	public static function build( bool $fresh = false ): array {
		$policy    = AiPolicy::get();
		$summary   = ( new SiteSummaries() )->all( $fresh );
		$providers = AiPolicy::providers();
		$options   = array();
		$sites     = array();
		$qualify   = 0;
		$not       = array();
		$left      = 0;

		// A reading a person asked for, or a month nobody has counted yet, counts again from the sites first.
		if ( $fresh || ! AiBudget::is_built() ) {
			$left = AiBudget::rebuild()['left'];
		}

		$count = AiBudget::usage();
		$limit = (int) $policy['network_tokens_month'];

		foreach ( $providers as $id => $label ) {
			$options[] = array(
				'id'      => (string) $id,
				'label'   => (string) $label,
				'allowed' => in_array( (string) $id, $policy['providers'], true ),
			);
		}

		foreach ( $summary['rows'] as $row ) {
			$ai         = $row['ai'];
			$site_limit = (int) $ai['token_limit'];
			$used       = isset( $count['sites'][ (int) $row['id'] ] ) ? (int) $count['sites'][ (int) $row['id'] ] : (int) $ai['tokens_month'];

			if ( $row['active'] && SiteAi::wordpress_ok() ) {
				++$qualify;
			} else {
				$not[] = (string) $row['name'];
			}

			// The kept reading can be five minutes old; whether the budget is used is read now.
			$assistant = in_array( (string) $ai['assistant'], array( 'on', 'paused_network', 'paused_site' ), true )
				? SiteAi::paused(
					array(
						'assistant'    => 'on',
						'provider'     => (string) $ai['provider'],
						'tokens_month' => $used,
						'token_limit'  => $site_limit,
					)
				)
				: (string) $ai['assistant'];

			$sites[] = array(
				'id'            => (int) $row['id'],
				'name'          => (string) $row['name'],
				'assistant'     => $assistant,
				'provider'      => (string) $ai['provider'],
				'provider_name' => isset( $providers[ $ai['provider'] ] ) ? (string) $providers[ $ai['provider'] ] : '',
				'tokens_month'  => $used,
				'token_limit'   => $site_limit,
				'percent'       => $site_limit > 0 ? (int) floor( $used * 100 / $site_limit ) : 0,
			);
		}

		usort(
			$sites,
			static function ( array $a, array $b ): int {
				$by_tokens = $b['tokens_month'] <=> $a['tokens_month'];

				return 0 !== $by_tokens ? $by_tokens : strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		$alerts = array();

		foreach ( AiBudget::alerts() as $threshold => $entry ) {
			$alerts[] = array(
				'threshold' => (int) $threshold,
				'state'     => $entry['state'],
				'at'        => $entry['at'] > 0 ? Clock::iso( $entry['at'] ) : null,
			);
		}

		return array(
			'policy'           => $policy,
			'provider_options' => $options,
			'locked'           => array( 'authors' => true ),
			'wordpress'        => array(
				'version' => (string) get_bloginfo( 'version' ),
				'minimum' => SiteAi::MIN_WORDPRESS,
				'ok'      => SiteAi::wordpress_ok(),
			),
			'qualifying'       => array(
				'sites'     => $qualify,
				'total'     => count( $summary['rows'] ),
				'names_not' => array_slice( $not, 0, 3 ),
			),
			'usage'            => array(
				'tokens_month' => $count['total'],
				'limit'        => $limit,
				'percent'      => $limit > 0 ? (int) floor( $count['total'] * 100 / $limit ) : 0,
				'month'        => $count['month'],
				'exhausted'    => $limit > 0 && $count['total'] >= $limit,
				'resets'       => Clock::iso( AiBudget::resets_at() ),
				'alert_at'     => (int) $policy['alert_at'],
				'alerts'       => $alerts,
				'left'         => $left,
			),
			'sites'            => $sites,
			'updated'          => $policy['updated'] > 0 ? Clock::iso( (int) $policy['updated'] ) : null,
			'updated_by'       => (int) $policy['updated_by'],
		);
	}
}
