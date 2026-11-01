<?php
/**
 * The one switch for the whole Outlets channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Outlets is on while at least one outlet is on and set up, so it has no flag of its own. This class
 * gives it a switch anyway, for `PUT /channels/outlets` and for the Channels hub, by working on the
 * switches of the outlets.
 *
 * Off turns every switch that is on to off and remembers which they were, in the Outlets state option.
 * Nothing else changes: each outlet keeps its settings, its keys and its accounts. On turns back exactly
 * the remembered switches. When nothing was remembered, or what was remembered can no longer post
 * anywhere, nothing is changed and the answer is a 409 that says to set up an outlet first.
 *
 * Both callers use `apply()`, so the audit trail gets one entry per change from either of them.
 */
final class OutletsSwitch {

	public const SET_UP_FIRST = 'estasoft_nd_set_up_first';

	private Plugin $plugin;

	/**
	 * Creates the switch.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The sentence for a person who asks to switch Outlets on with nothing to switch on.
	 *
	 * @return string
	 */
	public static function set_up_first_text(): string {
		return __( 'Set up an outlet first. Open Outlets, enter the details of one and turn it on.', 'estasoft-news-distribution' );
	}

	/**
	 * Whether the switch can be applied, decided before anything is written.
	 *
	 * @param OutletsChannel       $channel The channel.
	 * @param bool                 $on      The wanted state.
	 * @param array<string, mixed> $input   Other settings sent in the same request. An outlet they switch on and set up counts.
	 * @return \WP_Error|null A 409 error, or null when it can.
	 */
	public function check( OutletsChannel $channel, bool $on, array $input = array() ): ?\WP_Error {
		if ( ! $on || $channel->is_enabled() ) {
			return null;
		}

		$sent = array();

		foreach ( array_keys( $channel->switch_keys() ) as $key ) {
			if ( array_key_exists( $key, $input ) && filter_var( $input[ $key ], FILTER_VALIDATE_BOOLEAN ) ) {
				$sent[] = $key;
			}
		}

		if ( array() !== $sent && $channel->would_be_on( $sent ) ) {
			return null;
		}

		$kept = $channel->state()->remembered();

		if ( array() === $kept || ! $channel->would_be_on( $kept ) ) {
			return new \WP_Error( self::SET_UP_FIRST, self::set_up_first_text(), array( 'status' => 409 ) );
		}

		return null;
	}

	/**
	 * Switches the channel off or on, and records it.
	 *
	 * Asking for the state it is already in changes nothing and records nothing, and an off that finds
	 * nothing on leaves the remembered switches as they were.
	 *
	 * @param OutletsChannel $channel The channel.
	 * @param bool           $on      The wanted state.
	 * @return array{changed: string[], enabled: bool}|\WP_Error The switches that changed, and the state now; or the error.
	 */
	public function apply( OutletsChannel $channel, bool $on ) {
		$settings = $this->plugin->channel_settings();
		$changed  = array();

		if ( $on === $channel->is_enabled() ) {
			return $this->done( $channel, $changed );
		}

		if ( $on ) {
			$refused = $this->check( $channel, true );

			if ( null !== $refused ) {
				return $refused;
			}

			$changed = $channel->state()->remembered();
			$saved   = $settings->save( $channel->id(), $channel->fields(), array_fill_keys( $changed, true ) );
		} else {
			$current = $channel->settings();

			foreach ( array_keys( $channel->switch_keys() ) as $key ) {
				if ( ! empty( $current[ $key ] ) ) {
					$changed[] = $key;
				}
			}

			$channel->state()->remember( $changed );
			$saved = $settings->save( $channel->id(), $channel->fields(), array_fill_keys( $changed, false ) );
		}

		if ( is_wp_error( $saved ) ) {
			if ( ! $on ) {
				$channel->state()->forget_remembered();
			}

			return $saved;
		}

		if ( $on ) {
			$channel->state()->forget_remembered();
		}

		$channel->reset();

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			$on
				? sprintf(
					/* translators: %s: the outlets turned on, such as Telegram, Mastodon. */
					__( 'Outlets switched on. Turned back on: %s.', 'estasoft-news-distribution' ),
					$this->names( $channel, $changed )
				)
				: sprintf(
					/* translators: %s: the outlets turned off, such as Telegram, Mastodon. */
					__( 'Outlets switched off. Turned off: %s. Their settings and keys were kept.', 'estasoft-news-distribution' ),
					$this->names( $channel, $changed )
				)
		);

		return $this->done( $channel, $changed );
	}

	/**
	 * The result, with the channel's flag in the settings kept in line with what the channel now is.
	 *
	 * @param OutletsChannel $channel The channel.
	 * @param string[]       $changed Switches that changed.
	 * @return array{changed: string[], enabled: bool}
	 */
	private function done( OutletsChannel $channel, array $changed ): array {
		$enabled = $channel->is_enabled();

		if ( $this->plugin->channel_settings()->is_enabled( $channel->id() ) !== $enabled ) {
			$this->plugin->channel_settings()->set_enabled( $channel->id(), $enabled );
		}

		return array(
			'changed' => $changed,
			'enabled' => $enabled,
		);
	}

	/**
	 * Names for the audit entry.
	 *
	 * @param OutletsChannel $channel The channel.
	 * @param string[]       $keys    Switches, such as telegram_on and webhook_2_on.
	 * @return string
	 */
	private function names( OutletsChannel $channel, array $keys ): string {
		$names = array();
		$of    = $channel->switch_keys();

		foreach ( $keys as $key ) {
			$outlet = isset( $of[ $key ] ) ? $channel->outlet( $of[ $key ] ) : null;
			$name   = null !== $outlet ? $outlet->label() : $key;

			if ( 1 === preg_match( '/^webhook_(\d+)_on$/', $key, $match ) ) {
				$name = sprintf(
					/* translators: %d: the number of a webhook endpoint. */
					__( 'Webhook endpoint %d', 'estasoft-news-distribution' ),
					(int) $match[1]
				);
			}

			$names[] = $name;
		}

		return implode( ', ', $names );
	}
}
