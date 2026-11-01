<?php
/**
 * Privacy tools.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Operations\PersonalData;
use Estasoft\NewsDistribution\Core\Operations\PrivacyInventory;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the suggested text to WordPress's Privacy Policy Guide, the personal
 * data exporter and eraser, and the daily clean-up of the AI log.
 */
final class PrivacyModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;
		$tools        = $plugin->make( PersonalData::class );

		add_filter( 'wp_privacy_personal_data_exporters', array( $tools, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $tools, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_text' ) );
		add_action( 'estasoft_nd_maintenance', array( $this, 'clean_ai_log' ) );
	}

	/**
	 * Gives WordPress the suggested policy text. WordPress asks for it on `admin_init`.
	 *
	 * @return void
	 */
	public function add_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		wp_add_privacy_policy_content(
			__( 'Estasoft News Distribution', 'estasoft-news-distribution' ),
			wp_kses_post( wpautop( $this->plugin->make( PrivacyInventory::class )->policy_text(), false ) )
		);
	}

	/**
	 * After the daily clean-up: drops AI log rows older than the chosen period.
	 *
	 * @return void
	 */
	public function clean_ai_log(): void {
		$this->plugin->make( PrivacyInventory::class )->purge_ai_log();
	}
}
