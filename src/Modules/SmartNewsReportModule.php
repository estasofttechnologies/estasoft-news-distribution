<?php
/**
 * Hooks for the SmartNews channel: the shared report page and the deleted-article markers.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Channels\SmartNews\Removals;
use Estasoft\NewsDistribution\Channels\SmartNews\ReportPage;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Two small jobs that need WordPress hooks, both for the SmartNews channel:
 *
 * - the address a readiness report link opens, `/news-distribution-report/{token}/`
 *   (a rewrite rule, one query variable and one answer on `parse_request`; it
 *   answers only a token that works and does nothing for any other request);
 * - remembering an article that was unpublished or deleted, so the SmartNews feed
 *   can mark it deleted for a few days (written from the plugin's post event,
 *   only while the channel is on).
 *
 * It reads nothing and calls no other server while it registers.
 */
final class SmartNewsReportModule implements ModuleInterface {

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		unset( $plugin );

		add_action( 'init', array( ReportPage::class, 'add_rule' ) );
		add_filter( 'query_vars', array( ReportPage::class, 'query_vars' ) );
		add_action( 'parse_request', array( ReportPage::class, 'route' ), 1 );
		add_action( 'estasoft_nd_post_event', array( Removals::class, 'on_event' ), 5 );
	}
}
