<?php
/**
 * Counts visits by the site the reader came from.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Referrals\Counter;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Off until an administrator turns on "Count visits by source" (Settings, Privacy and data, or the
 * switch on the Referrals report). While it is on:
 *
 * - The page of a published, public article of a chosen post type prints one small script in the
 *   footer, for a reader who is not signed in. It is not printed on a feed, a preview, an embed,
 *   a draft, a page that is not a chosen type, or for a signed-in person.
 * - The script sends the article's number and the host of the referring page to
 *   `POST /referrals/visit` on this site (`Rest\ReferralsController`). Nothing goes to another server.
 *
 * This module also keeps the counts for 90 days (the daily clean-up), forgets an article's counts
 * when the article is deleted, adds the counts to the Privacy screen's list and to the suggested
 * privacy policy text, writes the switch and the deletion to the audit trail, and answers the
 * two forms of the Referrals report (the switch and "Delete the counts"), which post to
 * `admin-post.php` and so work without JavaScript.
 */
final class ReferralsModule implements ModuleInterface {

	public const SWITCH_ACTION = 'estasoft_nd_referrals_switch';
	public const DELETE_ACTION = 'estasoft_nd_referrals_delete';
	public const NONCE         = 'estasoft_nd_referrals';
	public const SCRIPT        = 'estasoft-nd-referrals';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'wp_enqueue_scripts', array( $this, 'script' ) );
		add_action( 'estasoft_nd_maintenance', array( $this, 'clean' ) );
		add_action( 'deleted_post', array( $this, 'forget_post' ), 10, 2 );
		add_filter( 'estasoft_nd_privacy_inventory', array( $this, 'inventory' ) );
		add_filter( 'estasoft_nd_privacy_policy_paragraphs', array( $this, 'policy' ) );
		add_action( 'estasoft_nd_settings_saved', array( $this, 'audit_switch' ), 10, 3 );
		add_action( 'admin_post_' . self::SWITCH_ACTION, array( $this, 'switch_counting' ) );
		add_action( 'admin_post_' . self::DELETE_ACTION, array( $this, 'delete_counts' ) );
	}

	/**
	 * Prints the script on the page of an article, when it applies.
	 *
	 * @return void
	 */
	public function script(): void {
		if ( is_admin() || is_feed() || is_embed() || is_preview() || is_customize_preview() || ! is_singular( $this->plugin->settings()->post_types() ) || is_user_logged_in() ) {
			return;
		}

		$counter = $this->plugin->make( Counter::class );
		$post_id = (int) get_queried_object_id();

		if ( ! $counter->enabled() || ! $counter->counts_for( $post_id ) ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT,
			ESTASOFT_ND_URL . 'assets/js/referrals.js',
			array(),
			Assets::file_version( 'assets/js/referrals.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			self::SCRIPT,
			'window.estasoftNDReferral = ' . wp_json_encode(
				array(
					'post' => $post_id,
					'url'  => esc_url_raw( rest_url( 'estasoft-nd/v1/referrals/visit' ) ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * After the daily clean-up: deletes counts older than the retention period.
	 *
	 * @return void
	 */
	public function clean(): void {
		$this->plugin->make( Counter::class )->purge();
	}

	/**
	 * Deletes an article's counts when the article is deleted.
	 *
	 * @param mixed $post_id Post ID.
	 * @param mixed $post    The post, from WordPress 5.5.
	 * @return void
	 */
	public function forget_post( $post_id = 0, $post = null ): void {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		$this->plugin->make( Counter::class )->delete_for_post( (int) $post_id );
	}

	/**
	 * Adds the counts to the Privacy screen's "What News Distribution stores", before the settings row.
	 *
	 * @param mixed $inventory What `PrivacyInventory::inventory()` built.
	 * @return mixed
	 */
	public function inventory( $inventory ) {
		if ( ! is_array( $inventory ) || ! isset( $inventory['stored'] ) || ! is_array( $inventory['stored'] ) ) {
			return $inventory;
		}

		$counter = $this->plugin->make( Counter::class );
		$counts  = __( 'Aggregate counts by referring site, for each article and day. Each visit adds 1 to a count and the visit itself is not kept. No cookies, no IP addresses and no visitor data.', 'estasoft-news-distribution' );

		if ( ! $counter->enabled() ) {
			$counts = $counter->rows() > 0
				? $counts . ' ' . __( 'Counting is off. The counts already made stay until you delete them.', 'estasoft-news-distribution' )
				: __( 'Nothing, while counting visits by source is off. If you turn it on: aggregate counts by referring site, for each article and day. No cookies, no IP addresses and no visitor data.', 'estasoft-news-distribution' );
		}

		$row = array(
			'id'       => 'referrals',
			'what'     => __( 'Referral counts', 'estasoft-news-distribution' ),
			'why'      => __( 'To show which platforms send readers', 'estasoft-news-distribution' ),
			'contains' => $counts,
			'kept'     => sprintf(
				/* translators: %d: number of days. */
				_n( '%d day', '%d days', Counter::RETENTION_DAYS, 'estasoft-news-distribution' ),
				Counter::RETENTION_DAYS
			),
		);

		$rows = array();
		$done = false;

		foreach ( $inventory['stored'] as $existing ) {
			if ( ! $done && is_array( $existing ) && 'settings' === ( $existing['id'] ?? '' ) ) {
				$rows[] = $row;
				$done   = true;
			}

			$rows[] = $existing;
		}

		if ( ! $done ) {
			$rows[] = $row;
		}

		$inventory['stored'] = $rows;

		return $inventory;
	}

	/**
	 * Adds a paragraph to the suggested privacy policy text while counting is on or counts are kept.
	 *
	 * @param mixed $paragraphs The paragraphs `PrivacyInventory::policy_text()` built.
	 * @return mixed
	 */
	public function policy( $paragraphs ) {
		if ( ! is_array( $paragraphs ) ) {
			return $paragraphs;
		}

		$counter = $this->plugin->make( Counter::class );

		if ( $counter->enabled() ) {
			$text = sprintf(
				/* translators: %d: number of days. */
				__( 'News Distribution counts visits by source. When a reader opens an article, it adds 1 to a count for the site the reader came from, the article and the day, and does not keep the visit itself. It sets no cookie and stores no IP address, browser details or visitor data. A small script on article pages sends the article’s number and the name of the referring site to this site, and to no other server. Counts are kept for %d days.', 'estasoft-news-distribution' ),
				Counter::RETENTION_DAYS
			);
		} elseif ( $counter->rows() > 0 ) {
			$text = sprintf(
				/* translators: %d: number of days. */
				__( 'News Distribution counted visits by source while that was turned on. It keeps only counts for each referring site, article and day, for up to %d days, and nothing else about a visit.', 'estasoft-news-distribution' ),
				Counter::RETENTION_DAYS
			);
		} else {
			return $paragraphs;
		}

		array_splice( $paragraphs, min( 2, count( $paragraphs ) ), 0, array( $text ) );

		return $paragraphs;
	}

	/**
	 * Writes the switch to the audit trail when a save changed it.
	 *
	 * @param mixed $name     The settings section that was saved.
	 * @param mixed $saved    The values now.
	 * @param mixed $previous The values before.
	 * @return void
	 */
	public function audit_switch( $name = '', $saved = array(), $previous = array() ): void {
		if ( 'privacy' !== $name || ! is_array( $saved ) || ! is_array( $previous ) || get_current_user_id() < 1 ) {
			return;
		}

		$now    = ! empty( $saved['count_visits'] );
		$before = ! empty( $previous['count_visits'] );

		if ( $now === $before ) {
			return;
		}

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			$now
				? __( 'Counting visits by source was turned on.', 'estasoft-news-distribution' )
				: __( 'Counting visits by source was turned off. The counts already made were kept.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The switch on the Referrals report: turns counting on or off, then goes back to the report.
	 *
	 * @return void
	 */
	public function switch_counting(): void {
		check_admin_referer( self::NONCE );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$on    = isset( $_POST['on'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['on'] ) );
		$saved = $this->plugin->settings()->save_section( 'privacy', array( 'count_visits' => $on ) );

		if ( is_wp_error( $saved ) ) {
			Flash::set( 'danger', $saved->get_error_message() );
		} elseif ( $on ) {
			Flash::set( 'success', __( 'Counting visits by source is on. If you use a page cache, clear it so article pages carry the counter.', 'estasoft-news-distribution' ) );
		} else {
			Flash::set( 'success', __( 'Counting visits by source is off. The counts already made are kept until you delete them.', 'estasoft-news-distribution' ) );
		}

		$this->back();
	}

	/**
	 * "Delete the counts", after the confirmation page: deletes every count, then goes back to the report.
	 *
	 * @return void
	 */
	public function delete_counts(): void {
		check_admin_referer( self::NONCE );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$this->plugin->make( Counter::class )->delete_all();
		Flash::set( 'success', __( 'The counts were deleted.', 'estasoft-news-distribution' ) );

		$this->back();
	}

	/**
	 * Sends the person back to the Referrals report.
	 *
	 * @return void
	 */
	private function back(): void {
		wp_safe_redirect( Links::admin( 'estasoft-nd-reports', array( 'report' => 'referrals' ) ) );
		exit;
	}
}
