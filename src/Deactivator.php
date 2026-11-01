<?php
/**
 * Runs on plugin deactivation.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution;

use Estasoft\NewsDistribution\Core\Cache;
use Estasoft\NewsDistribution\Core\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Leaves data in place and only clears cached output and the feed addresses.
 * Deactivating never deletes anything.
 */
final class Deactivator {

	/**
	 * Deactivation callback.
	 *
	 * @param bool $network_wide Whether the plugin was deactivated for the whole network.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Stops one site's work: the cached output, the waiting deliveries and the feed addresses.
	 *
	 * @return void
	 */
	private static function deactivate_site(): void {
		( new Cache() )->flush();

		// Pending pushes, checks and mails stop with the plugin. Backup and uninstall says so.
		if ( function_exists( 'as_unschedule_all_actions' ) && self::has_queue_table() ) {
			as_unschedule_all_actions( '', array(), Queue::GROUP );
		}

		self::forget_addresses();
	}

	/**
	 * Whether this site has the queue's table. A site of a network that nobody has opened since the plugin was
	 * activated for the whole network does not have it yet, and there is nothing to cancel there.
	 *
	 * @return bool
	 */
	private static function has_queue_table(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'actionscheduler_actions';

		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Takes the plugin's addresses out of the stored rewrite rules.
	 *
	 * The plugin is still loaded while it is being deactivated, and its rules were added to WordPress's list when the
	 * request started, so a flush alone would write them out again. They are taken out of the list first. The stored
	 * rules are then deleted, which makes WordPress build them again on the next request, without the plugin's.
	 *
	 * @return void
	 */
	private static function forget_addresses(): void {
		global $wp_rewrite;

		if ( $wp_rewrite instanceof \WP_Rewrite ) {
			foreach ( array( 'extra_rules_top', 'extra_rules' ) as $list ) {
				if ( ! is_array( $wp_rewrite->$list ) ) {
					continue;
				}

				foreach ( $wp_rewrite->$list as $pattern => $query ) {
					if ( is_string( $query ) && false !== strpos( $query, 'estasoft_nd_' ) ) {
						unset( $wp_rewrite->{$list}[ $pattern ] );
					}
				}
			}
		}

		delete_option( 'rewrite_rules' );

		// Another site of a network is not flushed here: the rules WordPress would write are those of this site's own post types.
		if ( $wp_rewrite instanceof \WP_Rewrite && ! ( function_exists( 'ms_is_switched' ) && ms_is_switched() ) ) {
			flush_rewrite_rules( false );
		}
	}
}
