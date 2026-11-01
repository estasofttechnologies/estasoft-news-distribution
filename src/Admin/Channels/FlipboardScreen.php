<?php
/**
 * Flipboard has no page of its own: it is part of the feed readers page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * The hub links a Flipboard row to `channel=flipboard`. Its feed address, its
 * topics and its fetch time are on the feed readers page, so this page sends
 * the person there.
 */
final class FlipboardScreen extends AbstractChannelScreen {

	public static function channel_id(): string {
		return 'flipboard';
	}

	/**
	 * Sends the person to the feed readers page, before any output.
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function handle( string $tab ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'estasoft-nd-channels',
					'channel' => 'feed-readers',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render( string $tab ): string {
		return '';
	}
}
