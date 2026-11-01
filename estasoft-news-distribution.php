<?php
/**
 * Plugin Name:       Estasoft News Distribution
 * Description:       News sitemaps, partner feeds and pushes for Google News, Microsoft Start, Yahoo News, Apple News, SmartNews and more, with a check on every article.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            ESTASOFT TECHNOLOGIES
 * Author URI:        https://www.estasoft.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       estasoft-news-distribution
 * Domain Path:       /languages
 *
 * @package Estasoft\NewsDistribution
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTASOFT_ND_VERSION', '1.1.0' );
define( 'ESTASOFT_ND_MIN_PHP', '7.4' );
define( 'ESTASOFT_ND_MIN_WP', '6.6' );
define( 'ESTASOFT_ND_FILE', __FILE__ );
define( 'ESTASOFT_ND_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTASOFT_ND_URL', plugin_dir_url( __FILE__ ) );

/**
 * Explains why the plugin did not start on an unsupported PHP or WordPress version.
 *
 * WordPress refuses to activate a plugin on a PHP version that is too old, so this covers a host that moves the
 * site to an older PHP later. Then no News Distribution screen can open, so the notice is printed where the person
 * will see it: on the Plugins screen and the Dashboard (and the same two in the network admin), for people who can
 * activate plugins.
 *
 * Kept in the main file, and written in syntax that PHP 5.6 can read (no scalar types, no `??`, no `::class`, no arrow
 * functions), so that it runs on versions the rest of the plugin cannot. Nothing from `src/` is loaded first.
 *
 * @return void
 */
function estasoft_nd_requirements_notice() {
	global $wp_version, $pagenow;

	if ( ! in_array( $pagenow, array( 'plugins.php', 'index.php' ), true ) || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( version_compare( PHP_VERSION, ESTASOFT_ND_MIN_PHP, '<' ) ) {
		$what = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'This server now runs PHP %2$s, and News Distribution needs %1$s or newer. Sitemaps and feeds are not served until PHP is updated.', 'estasoft-news-distribution' ),
			ESTASOFT_ND_MIN_PHP,
			PHP_VERSION
		);
		$url  = function_exists( 'wp_get_update_php_url' ) ? wp_get_update_php_url() : 'https://wordpress.org/support/update-php/';
		$link = __( 'How to update PHP', 'estasoft-news-distribution' );
	} else {
		$what = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'This site runs WordPress %2$s, and News Distribution needs %1$s or newer. Sitemaps and feeds are not served until WordPress is updated.', 'estasoft-news-distribution' ),
			ESTASOFT_ND_MIN_WP,
			$wp_version
		);
		$url  = self_admin_url( 'update-core.php' );
		$link = __( 'Update WordPress', 'estasoft-news-distribution' );
	}

	printf(
		'<div class="notice notice-error" role="alert"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a></p></div>',
		esc_html__( 'Estasoft News Distribution is paused.', 'estasoft-news-distribution' ),
		esc_html( $what ),
		esc_url( $url ),
		esc_html( $link )
	);
}

global $wp_version;
if ( version_compare( PHP_VERSION, ESTASOFT_ND_MIN_PHP, '<' ) || version_compare( $wp_version, ESTASOFT_ND_MIN_WP, '<' ) ) {
	add_action( 'admin_notices', 'estasoft_nd_requirements_notice' );
	add_action( 'network_admin_notices', 'estasoft_nd_requirements_notice' );
	return;
}

// Action Scheduler runs the background queue. It ships inside the plugin, unchanged, in vendor/.
$estasoft_nd_action_scheduler = ESTASOFT_ND_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $estasoft_nd_action_scheduler ) ) {
	require_once $estasoft_nd_action_scheduler;
}

require_once ESTASOFT_ND_PATH . 'src/Autoloader.php';
\Estasoft\NewsDistribution\Autoloader::register( 'Estasoft\\NewsDistribution\\', ESTASOFT_ND_PATH . 'src/' );

register_activation_hook( __FILE__, array( 'Estasoft\\NewsDistribution\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Estasoft\\NewsDistribution\\Deactivator', 'deactivate' ) );

// A site added to a network where the plugin is network-active gets its tables at once (priority 900: after WordPress has set the site up).
add_action( 'wp_initialize_site', array( 'Estasoft\\NewsDistribution\\Activator', 'new_site' ), 900 );

// Priority 20 runs after Action Scheduler (1) and the translation plugins have loaded.
add_action( 'plugins_loaded', array( 'Estasoft\\NewsDistribution\\Plugin', 'boot' ), 20 );
