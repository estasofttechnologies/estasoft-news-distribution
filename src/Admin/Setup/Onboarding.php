<?php
/**
 * Where the person is in the first-run setup.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The option `estasoft_nd_onboarding`:
 *
 *     {
 *       status,     // "" (never started), started, done, skipped or demo
 *       step,       // the step the person was last on: start, publication, channels or check
 *       redirected, // true once the activation redirect has opened the setup
 *       updated,    // Unix time of the last change
 *       preset,     // the channel setup last chosen: simple, digital or custom
 *       checked     // what the first check found, see FirstCheck
 *     }
 *
 * The option does not exist until the activation redirect, or the first save, writes it.
 * The Activator reads that: it asks for the redirect only while the option is missing.
 */
final class Onboarding {

	public const OPTION = 'estasoft_nd_onboarding';

	public const STATUS_STARTED = 'started';
	public const STATUS_DONE    = 'done';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_DEMO    = 'demo';

	public const START = 'start';

	/**
	 * The numbered steps, in order. The start screen comes before them and is not numbered.
	 *
	 * @return string[]
	 */
	public static function steps(): array {
		return array( 'publication', 'channels', 'check' );
	}

	/**
	 * Cleans a step name from a request.
	 *
	 * @param string $step Whatever was asked for.
	 * @return string One of the step names, or "start".
	 */
	public static function clean_step( string $step ): string {
		$step = sanitize_key( $step );

		return in_array( $step, self::steps(), true ) ? $step : self::START;
	}

	/**
	 * The step before another, or the start screen.
	 *
	 * @param string $step A step.
	 * @return string
	 */
	public static function previous( string $step ): string {
		$steps = self::steps();
		$index = array_search( $step, $steps, true );

		return false === $index || 0 === $index ? self::START : $steps[ $index - 1 ];
	}

	/**
	 * The step after another, or an empty string at the end.
	 *
	 * @param string $step A step.
	 * @return string
	 */
	public static function next( string $step ): string {
		if ( self::START === $step ) {
			return self::steps()[0];
		}

		$steps = self::steps();
		$index = array_search( $step, $steps, true );

		return false === $index || ! isset( $steps[ $index + 1 ] ) ? '' : $steps[ $index + 1 ];
	}

	/**
	 * What is stored, with every key present.
	 *
	 * @return array{status: string, step: string, redirected: bool, updated: int, preset: string, checked: array<string, mixed>}
	 */
	public static function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$status = (string) ( $stored['status'] ?? '' );

		return array(
			'status'     => in_array( $status, array( self::STATUS_STARTED, self::STATUS_DONE, self::STATUS_SKIPPED, self::STATUS_DEMO ), true ) ? $status : '',
			'step'       => self::clean_step( (string) ( $stored['step'] ?? '' ) ),
			'redirected' => ! empty( $stored['redirected'] ),
			'updated'    => (int) ( $stored['updated'] ?? 0 ),
			'preset'     => in_array( (string) ( $stored['preset'] ?? '' ), array( 'simple', 'digital', 'custom' ), true ) ? (string) $stored['preset'] : '',
			'checked'    => isset( $stored['checked'] ) && is_array( $stored['checked'] ) ? $stored['checked'] : array(),
		);
	}

	/**
	 * Changes some of what is stored.
	 *
	 * @param array<string, mixed> $changes Keys of `state()`.
	 * @return array<string, mixed> The new state.
	 */
	public static function save( array $changes ): array {
		$state = array_merge( self::state(), $changes );

		$state['updated'] = Clock::now();

		update_option( self::OPTION, $state, false );

		return $state;
	}
}
