<?php
/**
 * Removes plugin data on uninstall, only when the site owner asked for it.
 *
 * The choice is "Delete all News Distribution data when the plugin is deleted"
 * in Settings, Backup and uninstall. Without it nothing is removed.
 *
 * @package Estasoft\NewsDistribution
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes the sample articles that demo mode made on one site: the posts, their pictures and their categories.
 *
 * Only what carries the marker `_estasoft_nd_sample` (Core\Demo\Demo::META) is touched, and the marker is checked
 * again for each one. A post a person wrote is never deleted, whatever its title says. A published sample goes back
 * to a draft first, by a direct update, so deleting it announces nothing to other plugins.
 *
 * @return void
 */
function estasoft_nd_uninstall_samples() {
	global $wpdb;

	$marker = '_estasoft_nd_sample';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One-time clean-up of the plugin's own rows.
	$rows = $wpdb->get_results(
		$wpdb->prepare( "SELECT p.ID AS id, p.post_type AS type FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s ORDER BY p.ID ASC", $marker ),
		ARRAY_A
	);

	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$id = (int) $row['id'];

		if ( '' === (string) get_post_meta( $id, $marker, true ) ) {
			continue;
		}

		if ( 'attachment' === $row['type'] ) {
			wp_delete_attachment( $id, true );
			continue;
		}

		// Samples are posts; anything else that carries the marker was put there by something else.
		if ( 'post' !== $row['type'] ) {
			continue;
		}

		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		wp_delete_post( $id, true );
	}

	$terms = $wpdb->get_results(
		$wpdb->prepare( "SELECT m.term_id AS id, t.taxonomy AS taxonomy FROM {$wpdb->termmeta} m INNER JOIN {$wpdb->term_taxonomy} t ON t.term_id = m.term_id WHERE m.meta_key = %s", $marker ),
		ARRAY_A
	);
	// phpcs:enable

	foreach ( is_array( $terms ) ? $terms : array() as $term ) {
		$term_id  = (int) $term['id'];
		$taxonomy = (string) $term['taxonomy'];

		// A category a person has since filled with their own posts stays; only the marker goes.
		if ( ! taxonomy_exists( $taxonomy ) || array() !== get_objects_in_term( $term_id, $taxonomy ) ) {
			delete_term_meta( $term_id, $marker );
			continue;
		}

		wp_delete_term( $term_id, $taxonomy );
	}
}

/**
 * Removes the data of one site.
 *
 * Everything the plugin stores starts with `estasoft_nd_` (options, user meta,
 * tables, transients) or `_estasoft_nd_` (post meta), so new features need no
 * edit here. The one exception is the sample articles of demo mode: they are posts, so they are deleted by name
 * of their marker first (`estasoft_nd_uninstall_samples()`).
 *
 * @return bool True when the site's data was removed, false when the site owner chose to keep it.
 */
function estasoft_nd_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'estasoft_nd_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['data']['delete_on_uninstall'] ) ) {
		return false;
	}

	// The sample articles of demo mode: they were made for the demo and are not the site's own content.
	estasoft_nd_uninstall_samples();

	// Scheduled actions in the plugin's group. The tables belong to Action Scheduler and stay.
	$groups  = $wpdb->prefix . 'actionscheduler_groups';
	$actions = $wpdb->prefix . 'actionscheduler_actions';
	$logs    = $wpdb->prefix . 'actionscheduler_logs';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- One-time clean-up of the plugin's own rows; table names are built from the prefix.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $groups ) ) === $groups ) {
		$group_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT group_id FROM %i WHERE slug = %s', $groups, 'estasoft_nd' ) );

		if ( $group_id > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE l FROM %i l INNER JOIN %i a ON a.action_id = l.action_id WHERE a.group_id = %d', $logs, $actions, $group_id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $actions, $group_id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE group_id = %d', $groups, $group_id ) );
		}
	}

	// The plugin's own tables.
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'estasoft_nd_' ) . '%' ) );

	foreach ( (array) $tables as $table ) {
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', (string) $table ) );
	}

	// Post meta. The article IDs stay unless the second box in Backup and uninstall was ticked:
	// feeds that read them then keep the same IDs if the plugin is installed again.
	// These two keys are ArticleId::META_ID and ArticleId::META_FIRST_GMT.
	if ( ! empty( $settings['data']['delete_article_ids'] ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_estasoft_nd_' ) . '%' ) );
	} else {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_key NOT IN ( %s, %s )",
				$wpdb->esc_like( '_estasoft_nd_' ) . '%',
				'_estasoft_nd_id',
				'_estasoft_nd_first_published_gmt'
			)
		);
	}

	// User meta, options and transients.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'estasoft_nd_' ) . '%' ) );
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'estasoft_nd_' ) . '%',
			$wpdb->esc_like( '_transient_estasoft_nd_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_estasoft_nd_' ) . '%'
		)
	);
	// phpcs:enable

	wp_cache_flush();

	// The uploads folder.
	$uploads = wp_upload_dir( null, false );
	$folder  = trailingslashit( $uploads['basedir'] ) . 'estasoft-news-distribution';

	if ( is_dir( $folder ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		global $wp_filesystem;

		if ( WP_Filesystem() && $wp_filesystem ) {
			$wp_filesystem->delete( $folder, true );
		}
	}

	return true;
}

if ( is_multisite() ) {
	$estasoft_nd_removed_all = true;

	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $estasoft_nd_site_id ) {
		switch_to_blog( (int) $estasoft_nd_site_id );

		if ( ! estasoft_nd_uninstall_site() ) {
			$estasoft_nd_removed_all = false;
		}

		restore_current_blog();
	}

	// Network-wide data goes only when every site chose to delete its own: the network option, and the
	// kept readings of the Network screens, which are site transients and so live in the sitemeta table.
	if ( $estasoft_nd_removed_all ) {
		global $wpdb;

		delete_site_option( 'estasoft_nd_network' );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- One-time clean-up of the plugin's own rows.
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( '_site_transient_estasoft_nd_' ) . '%',
				$wpdb->esc_like( '_site_transient_timeout_estasoft_nd_' ) . '%'
			)
		);
	}
} else {
	estasoft_nd_uninstall_site();
}
