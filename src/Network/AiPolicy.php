<?php
/**
 * The AI policy a network administrator sets for every site.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in the network option `estasoft_nd_network`, key `ai`. The keys `allowed`, `providers`,
 * `drafts`, `authors`, `max_tokens_month`, `second_approver` and `max_log_months` are the ones
 * `Ai\Policy` and `Core\Operations\PrivacyInventory` read; the rest belong to this screen.
 *
 * - `allowed` (bool): false when no provider is allowed, which turns the assistant off on every site.
 * - `providers` (string[]): provider ids sites may choose from.
 * - `drafts` (bool): whether a site may choose to send drafts and scheduled posts.
 * - `authors` (bool): always false. Author names are never sent, and sites cannot change it.
 * - `approve_translations` (bool): a person approves every AI translation before it is published.
 * - `second_approver` (bool): the same as `approve_translations`; a language with no signed-off reviewer needs a second approver.
 * - `second_approver_role` (string): `editors` or `admins`.
 * - `network_tokens_month` (int): tokens a month across all sites; 0 is no limit.
 * - `max_tokens_month` (int): tokens a month one site may set for itself; 0 is no limit.
 * - `alert_at` (int): 80 or 90, as a percent of a limit.
 * - `max_log_months` (int): 12, 24 or 36, the longest a site may keep its AI log.
 * - `updated`, `updated_by` (int): when and by whom.
 *
 * A value outside its range is rejected with the field named, never changed. A site can keep its AI
 * log for less than `max_log_months`, never longer; that is enforced where the site chooses.
 */
final class AiPolicy {

	public const LOG_MONTHS = array( 12, 24, 36 );
	public const ALERT_AT   = array( 80, 90 );
	public const ROLES      = array( 'editors', 'admins' );
	public const TOKENS_MAX = 100000000000;

	/**
	 * The providers a network can allow.
	 *
	 * @return array<string, string> Provider id => label.
	 */
	public static function providers(): array {
		$list = array(
			'anthropic' => __( 'Anthropic', 'estasoft-news-distribution' ),
			'openai'    => __( 'OpenAI', 'estasoft-news-distribution' ),
			'google'    => __( 'Google', 'estasoft-news-distribution' ),
			'ollama'    => __( 'Ollama, on your own server', 'estasoft-news-distribution' ),
		);

		/**
		 * Lets the AI workstream or another plugin add a provider a network can allow.
		 *
		 * @param array<string, string> $list Provider id => label.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_network_ai_providers', $list );

		return is_array( $filtered ) ? array_map( 'strval', $filtered ) : $list;
	}

	/**
	 * What a network that has never saved the policy gets.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'allowed'              => true,
			'providers'            => array( 'anthropic', 'openai', 'google' ),
			'drafts'               => true,
			'authors'              => false,
			'approve_translations' => true,
			'second_approver'      => true,
			'second_approver_role' => 'editors',
			'network_tokens_month' => 20000000,
			'max_tokens_month'     => 2000000,
			'alert_at'             => 80,
			'max_log_months'       => 12,
			'updated'              => 0,
			'updated_by'           => 0,
		);
	}

	/**
	 * The policy in force: what is stored, over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = Store::get( 'ai', array() );
		$stored = is_array( $stored ) ? $stored : array();
		$policy = array_merge( self::defaults(), $stored );

		$policy['allowed']              = (bool) $policy['allowed'];
		$policy['providers']            = array_values( array_intersect( array_keys( self::providers() ), array_map( 'strval', (array) $policy['providers'] ) ) );
		$policy['drafts']               = (bool) $policy['drafts'];
		$policy['authors']              = false;
		$policy['approve_translations'] = (bool) $policy['approve_translations'];
		$policy['second_approver']      = (bool) $policy['approve_translations'];
		$policy['second_approver_role'] = in_array( $policy['second_approver_role'], self::ROLES, true ) ? (string) $policy['second_approver_role'] : 'editors';
		$policy['network_tokens_month'] = max( 0, (int) $policy['network_tokens_month'] );
		$policy['max_tokens_month']     = max( 0, (int) $policy['max_tokens_month'] );
		$policy['alert_at']             = in_array( (int) $policy['alert_at'], self::ALERT_AT, true ) ? (int) $policy['alert_at'] : 80;
		$policy['max_log_months']       = in_array( (int) $policy['max_log_months'], self::LOG_MONTHS, true ) ? (int) $policy['max_log_months'] : 12;
		$policy['updated']              = (int) $policy['updated'];
		$policy['updated_by']           = (int) $policy['updated_by'];

		return $policy;
	}

	/**
	 * Whether the policy was ever saved.
	 *
	 * @return bool
	 */
	public static function is_saved(): bool {
		$stored = Store::get( 'ai', array() );

		return is_array( $stored ) && array() !== $stored;
	}

	/**
	 * The longest a site may keep its AI log, in months.
	 *
	 * @return int
	 */
	public static function max_log_months(): int {
		return (int) self::get()['max_log_months'];
	}

	/**
	 * What a site may keep, given what it chose: never more than the network allows.
	 *
	 * @param int $site_months The site's own choice.
	 * @return int
	 */
	public static function clamp_log_months( int $site_months ): int {
		return max( 1, min( $site_months, self::max_log_months() ) );
	}

	/**
	 * Checks and saves the policy.
	 *
	 * Fields left out keep their value. When no provider is chosen the assistant is off on every site.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 * @return array<string, mixed>|\WP_Error The saved policy, or an error with `fields` in its data.
	 */
	public static function save( array $input ) {
		$cleaned = self::clean( $input, self::get() );

		if ( array() !== $cleaned['errors'] ) {
			return new \WP_Error(
				'estasoft_nd_invalid_value',
				(string) reset( $cleaned['errors'] ),
				array(
					'status' => 400,
					'fields' => $cleaned['errors'],
				)
			);
		}

		$before               = self::get();
		$values               = $cleaned['values'];
		$values['updated']    = Clock::now();
		$values['updated_by'] = get_current_user_id();

		Store::set( 'ai', $values );

		$after   = self::get();
		$changed = array();

		foreach ( array_keys( self::defaults() ) as $key ) {
			if ( ! in_array( $key, array( 'updated', 'updated_by' ), true ) && $before[ $key ] !== $after[ $key ] ) {
				$changed[] = $key;
			}
		}

		if ( array() !== $changed ) {
			self::audit(
				sprintf(
					/* translators: %s: the names of the settings that changed, such as "providers, drafts". */
					__( 'Network AI policy changed: %s.', 'estasoft-news-distribution' ),
					implode( ', ', $changed )
				)
			);
		}

		return $after;
	}

	/**
	 * Writes a line to the audit log of the site that is current (the main site, in Network Admin),
	 * when that site has the table.
	 *
	 * @param string $detail What happened, in a short sentence. Never a secret.
	 * @return void
	 */
	public static function audit( string $detail ): void {
		$audit = new Audit();

		if ( Tables::exists( $audit->table() ) ) {
			$audit->record( Audit::SETTINGS, 0, $detail );
		}
	}

	/**
	 * Checks submitted values against the current policy.
	 *
	 * @param array<string, mixed> $input   Submitted values.
	 * @param array<string, mixed> $current The policy in force.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function clean( array $input, array $current ): array {
		$errors = array();
		$values = $current;

		if ( array_key_exists( 'providers', $input ) ) {
			$chosen = is_array( $input['providers'] ) ? array_values( array_unique( array_map( 'strval', $input['providers'] ) ) ) : array();
			$chosen = array_values( array_filter( $chosen, 'strlen' ) );
			$known  = self::providers();
			$bad    = array_values( array_diff( $chosen, array_keys( $known ) ) );

			if ( array() !== $bad ) {
				$errors['providers'] = sprintf(
					/* translators: %s: a provider name that is not on the list. */
					__( '“%s” is not a provider this network can allow.', 'estasoft-news-distribution' ),
					(string) reset( $bad )
				);
			} else {
				// Keep the order of the list on the screen.
				$values['providers'] = array_values( array_intersect( array_keys( $known ), $chosen ) );
				$values['allowed']   = array() !== $values['providers'];
			}
		}

		foreach ( array( 'drafts', 'approve_translations' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$values[ $flag ] = (bool) filter_var( $input[ $flag ], FILTER_VALIDATE_BOOLEAN );
			}
		}

		if ( array_key_exists( 'second_approver_role', $input ) ) {
			if ( is_string( $input['second_approver_role'] ) && in_array( $input['second_approver_role'], self::ROLES, true ) ) {
				$values['second_approver_role'] = $input['second_approver_role'];
			} else {
				$errors['second_approver_role'] = __( 'Choose who the second approver is.', 'estasoft-news-distribution' );
			}
		}

		foreach ( array( 'network_tokens_month', 'max_tokens_month' ) as $budget ) {
			if ( ! array_key_exists( $budget, $input ) ) {
				continue;
			}

			$whole = self::whole_number( $input[ $budget ] );

			if ( null === $whole || $whole > self::TOKENS_MAX ) {
				$errors[ $budget ] = sprintf(
					/* translators: %s: the largest number of tokens a limit can be, such as 100,000,000,000. */
					__( 'Enter a whole number from 0 to %s. 0 means no limit.', 'estasoft-news-distribution' ),
					number_format_i18n( self::TOKENS_MAX )
				);
			} else {
				$values[ $budget ] = $whole;
			}
		}

		if ( ! isset( $errors['network_tokens_month'] )
			&& ! isset( $errors['max_tokens_month'] )
			&& (int) $values['network_tokens_month'] > 0
			&& (int) $values['max_tokens_month'] > (int) $values['network_tokens_month']
		) {
			$errors['max_tokens_month'] = __( 'A site’s limit cannot be higher than the network’s limit.', 'estasoft-news-distribution' );
		}

		if ( array_key_exists( 'alert_at', $input ) ) {
			$alert = is_scalar( $input['alert_at'] ) ? (int) $input['alert_at'] : 0;

			if ( in_array( $alert, self::ALERT_AT, true ) ) {
				$values['alert_at'] = $alert;
			} else {
				$errors['alert_at'] = __( 'Choose 80% or 90% of a limit.', 'estasoft-news-distribution' );
			}
		}

		if ( array_key_exists( 'max_log_months', $input ) ) {
			$months = is_scalar( $input['max_log_months'] ) ? (int) $input['max_log_months'] : 0;

			if ( in_array( $months, self::LOG_MONTHS, true ) ) {
				$values['max_log_months'] = $months;
			} else {
				$errors['max_log_months'] = __( 'Choose 12, 24 or 36 months.', 'estasoft-news-distribution' );
			}
		}

		$values['authors']         = false;
		$values['second_approver'] = (bool) $values['approve_translations'];

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * A whole number typed the way people type one: digits, with commas, spaces or points between thousands.
	 *
	 * @param mixed $raw What was submitted.
	 * @return int|null Null when it is not a whole number of at least 0.
	 */
	public static function whole_number( $raw ): ?int {
		if ( is_int( $raw ) ) {
			return $raw >= 0 ? $raw : null;
		}

		if ( ! is_string( $raw ) ) {
			return null;
		}

		$digits = trim( $raw );

		if ( 1 !== preg_match( '/^[0-9]{1,3}([,. \x{00A0}\x{202F}][0-9]{3})+$|^[0-9]{1,13}$/u', $digits ) ) {
			return null;
		}

		return (int) preg_replace( '/[^0-9]/', '', $digits );
	}
}
