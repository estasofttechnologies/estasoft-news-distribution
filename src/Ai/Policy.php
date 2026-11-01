<?php
/**
 * What a multisite network allows the AI assistant to do.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Network\AiBudget;
use Estasoft\NewsDistribution\Network\AiPolicy;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the AI policy a network administrator sets in Network, AI policy (`Network\AiPolicy`,
 * stored in the network option `estasoft_nd_network`, key `ai`). The keys used here are
 * `allowed`, `providers`, `drafts`, `authors`, `max_tokens_month` and `second_approver`;
 * `max_log_months` is read by `Core\Operations\PrivacyInventory`.
 *
 * On a site that is not part of a multisite network there is no policy and every answer is the
 * permissive one, so the site owner decides.
 */
final class Policy {

	/**
	 * Creates the reader. It needs nothing from the plugin, so it can be made anywhere.
	 *
	 * @param Plugin|null $plugin The running plugin, when there is one.
	 */
	public function __construct( ?Plugin $plugin = null ) {
		unset( $plugin );
	}

	/**
	 * The policy in force, or an empty list outside multisite.
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		return is_multisite() ? AiPolicy::get() : array();
	}

	/**
	 * Whether a network policy applies to this site at all.
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return is_multisite();
	}

	/**
	 * Whether the network has turned the assistant off for this site: it turned it off for every
	 * site, or it allows no provider.
	 *
	 * @return bool
	 */
	public function locked(): bool {
		$policy = $this->stored();

		if ( array() === $policy ) {
			return false;
		}

		return ! $policy['allowed'] || array() === $policy['providers'];
	}

	/**
	 * The provider ids the network allows. Outside multisite every provider is allowed, and
	 * the list is empty.
	 *
	 * @return string[]
	 */
	public function providers(): array {
		$policy = $this->stored();

		return array() === $policy ? array() : array_values( array_map( 'strval', (array) $policy['providers'] ) );
	}

	/**
	 * Whether a provider may be used on this site.
	 *
	 * @param string $provider_id Provider id.
	 * @return bool
	 */
	public function allows_provider( string $provider_id ): bool {
		return ! $this->exists() || in_array( $provider_id, $this->providers(), true );
	}

	/**
	 * Whether a site may choose to send drafts and scheduled posts.
	 *
	 * @return bool
	 */
	public function allows_drafts(): bool {
		$policy = $this->stored();

		return array() === $policy || (bool) $policy['drafts'];
	}

	/**
	 * Whether a site may choose to send author names. A network never lets a site do it.
	 *
	 * @return bool
	 */
	public function allows_authors(): bool {
		$policy = $this->stored();

		return array() === $policy || (bool) $policy['authors'];
	}

	/**
	 * The most tokens a month the network lets a site set, or 0 when nothing caps it.
	 *
	 * @return int
	 */
	public function token_cap(): int {
		return max( 0, (int) ( $this->stored()['max_tokens_month'] ?? 0 ) );
	}

	/**
	 * Tokens a month that all sites of the network may use together, or 0 when the network sets no limit.
	 *
	 * @return int
	 */
	public function network_budget(): int {
		return max( 0, (int) ( $this->stored()['network_tokens_month'] ?? 0 ) );
	}

	/**
	 * Whether the network has used its whole monthly budget, which pauses the assistant on every site.
	 * Always false outside multisite and when the network sets no limit (`AiBudget`).
	 *
	 * @return bool
	 */
	public function network_budget_used(): bool {
		return $this->exists() && AiBudget::exhausted();
	}

	/**
	 * The percent of a limit at which the network wants to be told: 80 or 90. Outside multisite it is 80.
	 *
	 * @return int
	 */
	public function alert_at(): int {
		$alert = (int) ( $this->stored()['alert_at'] ?? 0 );

		return in_array( $alert, AiPolicy::ALERT_AT, true ) ? $alert : 80;
	}

	/**
	 * Whether the network asks for a second approver where a language has no signed-off reviewer.
	 *
	 * @return bool
	 */
	public function second_approver(): bool {
		return ! empty( $this->stored()['second_approver'] );
	}

	/**
	 * Who the second approver must be: `editors` or `admins`.
	 *
	 * @return string
	 */
	public function second_approver_role(): string {
		return 'admins' === ( $this->stored()['second_approver_role'] ?? '' ) ? 'admins' : 'editors';
	}
}
