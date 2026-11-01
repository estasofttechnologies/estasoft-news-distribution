<?php
/**
 * Per-channel settings: the `estasoft_nd_channels` option.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Secrets;
use Estasoft\NewsDistribution\Core\Settings\Input;
use Estasoft\NewsDistribution\Core\Settings\SecretInput;
use Estasoft\NewsDistribution\Core\Settings\Validator;

defined( 'ABSPATH' ) || exit;

/**
 * `{ channel_key: { enabled, enabled_since, last_test, ...fields } }`, where the
 * key is the channel id with hyphens turned to underscores. Secrets are never
 * kept here; they go to Core\Secrets.
 */
final class ChannelSettings {

	public const OPTION = 'estasoft_nd_channels';

	/**
	 * The stored option for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $stored = null;

	/**
	 * Fixed values for tests, or null to read the option.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $injected;

	/**
	 * Creates the store.
	 *
	 * @param array<string, array<string, mixed>>|null $values Fixed values for tests.
	 */
	public function __construct( ?array $values = null ) {
		$this->injected = $values;
	}

	/**
	 * The storage key for a channel id.
	 *
	 * @param string $channel_id Channel id, such as "google-news".
	 * @return string
	 */
	public static function key( string $channel_id ): string {
		return str_replace( '-', '_', $channel_id );
	}

	/**
	 * The key a channel's secret field is stored under in Core\Secrets.
	 *
	 * @param string $channel_id Channel id.
	 * @param string $field      Field name.
	 * @return string
	 */
	public static function secret_key( string $channel_id, string $field ): string {
		return 'channel_' . self::key( $channel_id ) . '_' . $field;
	}

	/**
	 * A channel's settings as a screen or REST response needs them: a saved
	 * secret is never returned, only a mask and an `<field>_is_set` flag.
	 *
	 * @param string                              $channel_id Channel id.
	 * @param array<string, array<string, mixed>> $fields     The channel's field specs.
	 * @return array<string, mixed>
	 */
	public function for_screen( string $channel_id, array $fields = array() ): array {
		$values  = $this->get( $channel_id, array_diff_key( $fields, SecretInput::fields( $fields ) ) );
		$secrets = new Secrets();

		foreach ( array_keys( SecretInput::fields( $fields ) ) as $field ) {
			$key                          = self::secret_key( $channel_id, (string) $field );
			$values[ $field ]             = $secrets->masked( $key );
			$values[ $field . '_is_set' ] = $secrets->has( $key );
		}

		return $values;
	}

	/**
	 * A channel's settings with defaults filled in.
	 *
	 * @param string                              $channel_id Channel id.
	 * @param array<string, array<string, mixed>> $fields     The channel's field specs.
	 * @return array<string, mixed>
	 */
	public function get( string $channel_id, array $fields = array() ): array {
		$stored = $this->all()[ self::key( $channel_id ) ] ?? array();
		$stored = is_array( $stored ) ? $stored : array();
		$values = array_merge(
			array(
				'enabled'       => false,
				'enabled_since' => 0,
				'last_test'     => array(),
			),
			Validator::defaults( $fields )
		);

		foreach ( $stored as $name => $value ) {
			if ( array_key_exists( $name, $values ) ) {
				$values[ $name ] = $value;
			}
		}

		return $values;
	}

	/**
	 * Whether a channel is switched on.
	 *
	 * @param string $channel_id Channel id.
	 * @return bool
	 */
	public function is_enabled( string $channel_id ): bool {
		$stored = $this->all()[ self::key( $channel_id ) ] ?? array();

		return is_array( $stored ) && ! empty( $stored['enabled'] );
	}

	/**
	 * Switches a channel on or off.
	 *
	 * @param string $channel_id Channel id.
	 * @param bool   $enabled    New state.
	 * @return void
	 */
	public function set_enabled( string $channel_id, bool $enabled ): void {
		$all = $this->all();
		$key = self::key( $channel_id );
		$row = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		if ( $enabled && empty( $row['enabled'] ) ) {
			$row['enabled_since'] = Clock::now();
		}

		$row['enabled'] = $enabled;
		$all[ $key ]    = $row;

		$this->write( $all );
	}

	/**
	 * Validates and saves a channel's settings. Fields missing from the input keep their value.
	 *
	 * @param string                              $channel_id Channel id.
	 * @param array<string, array<string, mixed>> $fields     The channel's field specs.
	 * @param array<string, mixed>                $input      Submitted values, `enabled` included.
	 * @return array<string, mixed>|\WP_Error Saved settings, or an error naming the fields.
	 */
	public function save( string $channel_id, array $fields, array $input ) {
		$has_enabled = array_key_exists( 'enabled', $input );
		$enabled     = $has_enabled ? $input['enabled'] : false;
		$input       = Input::normalise( $fields, $input );
		$secrets     = SecretInput::fields( $fields );
		$plain       = array_diff_key( $fields, $secrets );
		$current     = $this->get( $channel_id, $plain );
		$cleaned     = Validator::clean( $plain, $input, $current );
		$errors      = $cleaned['errors'];
		$changes     = array();

		if ( array() === $errors ) {
			$read    = SecretInput::read( $secrets, $input );
			$errors  = $read['errors'];
			$changes = $read['changes'];
		}

		if ( array() !== $errors ) {
			return new \WP_Error(
				'estasoft_nd_invalid_value',
				(string) reset( $errors ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		$store = new Secrets();
		foreach ( $changes as $field => $value ) {
			$store->set( self::secret_key( $channel_id, $field ), $value );
		}

		$all = $this->all();
		$key = self::key( $channel_id );
		$row = array_merge( $current, $cleaned['values'] );

		if ( $has_enabled ) {
			$on = (bool) filter_var( $enabled, FILTER_VALIDATE_BOOLEAN );

			if ( $on && ! $current['enabled'] ) {
				$row['enabled_since'] = Clock::now();
			}

			$row['enabled'] = $on;
		}

		$all[ $key ] = $row;
		$this->write( $all );

		return $row;
	}

	/**
	 * Stores the result of the latest test a person started.
	 *
	 * @param string               $channel_id Channel id.
	 * @param array<string, mixed> $result     time, ok (bool), code (int), message.
	 * @return void
	 */
	public function set_last_test( string $channel_id, array $result ): void {
		$all = $this->all();
		$key = self::key( $channel_id );
		$row = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();

		$row['last_test'] = $result;
		$all[ $key ]      = $row;

		$this->write( $all );
	}

	/**
	 * Everything stored, as is. Used by the backup, which leaves secrets out because they are not here.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null === $this->stored ) {
			$raw          = null !== $this->injected ? $this->injected : get_option( self::OPTION, array() );
			$this->stored = is_array( $raw ) ? $raw : array();
		}

		return $this->stored;
	}

	/**
	 * Forgets the copy read earlier in this request, so the next read goes to
	 * the option again. Tests call it after they reset the option.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->stored = null;
	}

	/**
	 * Replaces everything. Used by a restore.
	 *
	 * @param array<string, array<string, mixed>> $all New contents.
	 * @return void
	 */
	public function replace_all( array $all ): void {
		$this->write( $all );
	}

	/**
	 * Saves the option.
	 *
	 * @param array<string, array<string, mixed>> $all Contents.
	 * @return void
	 */
	private function write( array $all ): void {
		$this->stored = $all;

		if ( null === $this->injected ) {
			update_option( self::OPTION, $all, false );
		}

		do_action( 'estasoft_nd_output_changed', 'channels' );
	}
}
