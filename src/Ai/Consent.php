<?php
/**
 * The owner's recorded consent to send site text to an AI provider.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing in the AI feature sends text anywhere until this record exists. It names the
 * person, the time and the providers that were installed when they confirmed, so a provider
 * installed later does not inherit the consent. It is stored in its own site option, never in
 * the settings, so saving the settings form cannot create it.
 *
 * Turning the assistant off removes the record; the next time needs a new confirmation.
 */
final class Consent {

	public const OPTION  = 'estasoft_nd_ai_consent';
	public const VERSION = 1;

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * What WordPress and the network report. Asked when needed, because it asks this class too.
	 *
	 * @return Availability
	 */
	private function availability(): Availability {
		$availability = $this->plugin->make( Availability::class );

		return $availability instanceof Availability ? $availability : new Availability( $this->plugin );
	}

	/**
	 * The stored record, or null when nobody has confirmed.
	 *
	 * @return array{user: int, at: int, providers: string[], version: int}|null
	 */
	public function record(): ?array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || empty( $stored['at'] ) || (int) ( $stored['version'] ?? 0 ) !== self::VERSION ) {
			return null;
		}

		return array(
			'user'      => (int) ( $stored['user'] ?? 0 ),
			'at'        => (int) $stored['at'],
			'providers' => array_values( array_map( 'strval', (array) ( $stored['providers'] ?? array() ) ) ),
			'version'   => self::VERSION,
		);
	}

	/**
	 * Whether a provider is named in the record.
	 *
	 * @param string $provider_id Provider id.
	 * @return bool
	 */
	public function covers( string $provider_id ): bool {
		$record = $this->record();

		return null !== $record && in_array( $provider_id, $record['providers'], true );
	}

	/**
	 * Whether consent is recorded and still covers every provider the site could use now.
	 *
	 * @return bool
	 */
	public function valid(): bool {
		$record = $this->record();

		if ( null === $record || array() === $record['providers'] ) {
			return false;
		}

		foreach ( $this->availability()->usable_providers() as $provider ) {
			if ( ! in_array( $provider['id'], $record['providers'], true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a record exists but a provider has been added since.
	 *
	 * @return bool
	 */
	public function needs_reconfirm(): bool {
		return null !== $this->record() && ! $this->valid();
	}

	/**
	 * Records consent and turns the owner's switch on.
	 *
	 * @param int $user_id The person who confirmed. Must be an administrator.
	 * @return true|\WP_Error
	 */
	public function grant( int $user_id ) {
		if ( ! $this->plugin->access()->can( 'admin', null, $user_id ) ) {
			return new \WP_Error( 'estasoft_nd_ai_forbidden', __( 'Only an administrator can turn on the AI assistant.', 'estasoft-news-distribution' ), array( 'status' => 403 ) );
		}

		$ids = array_column( $this->availability()->usable_providers(), 'id' );

		if ( array() === $ids ) {
			return new \WP_Error( 'estasoft_nd_ai_no_provider', __( 'Connect an AI provider first. Then you can confirm what is sent and to whom.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		if ( ! $this->plugin->make( Config::class )->any_on() ) {
			return new \WP_Error( 'estasoft_nd_ai_no_feature', __( 'Turn on at least one feature and save first. Then you can confirm what is sent and to whom.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		update_option(
			self::OPTION,
			array(
				'user'      => $user_id,
				'at'        => Clock::now(),
				'providers' => $ids,
				'version'   => self::VERSION,
			),
			false
		);

		$saved = $this->plugin->settings()->save_section( 'ai', array( 'enabled' => true ) );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->plugin->audit()->record( 'ai_consent_granted', 0, implode( ',', $ids ), $user_id );

		return true;
	}

	/**
	 * Removes the record and turns the owner's switch off.
	 *
	 * @param int $user_id The person who turned it off.
	 * @return true|\WP_Error
	 */
	public function revoke( int $user_id ) {
		if ( ! $this->plugin->access()->can( 'admin', null, $user_id ) ) {
			return new \WP_Error( 'estasoft_nd_ai_forbidden', __( 'Only an administrator can turn off the AI assistant.', 'estasoft-news-distribution' ), array( 'status' => 403 ) );
		}

		delete_option( self::OPTION );

		$saved = $this->plugin->settings()->save_section(
			'ai',
			array(
				'enabled'  => false,
				'features' => array_fill_keys( Config::TOGGLES, false ),
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->plugin->audit()->record( 'ai_consent_revoked', 0, '', $user_id );

		return true;
	}
}
