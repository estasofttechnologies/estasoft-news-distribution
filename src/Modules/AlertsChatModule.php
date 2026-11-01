<?php
/**
 * The Monday summary, for Slack and Microsoft Teams.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Digest;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `Core\Digest` builds and emails the weekly summary, and `AlertsModule` runs it on Monday. This adds the chat
 * part: when the Weekly summary is ticked for Slack or Teams, the same job also posts one short message with
 * two counts and a link to the Weekly summary page. It runs after the email, on the same queue job, so a
 * chat service that is slow or down never delays the email and never runs while a person waits.
 *
 * It holds no article text. While demo mode is on, nothing is sent, as with the email.
 *
 * Nothing here runs on publish, and `register()` does no work.
 */
final class AlertsChatModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		// After `AlertsModule::weekly()` (10), which sends the email and books the next Monday.
		add_action( Digest::HOOK, array( $this, 'weekly' ), 11 );

		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'schedule' ) );
		}
	}

	/**
	 * The Monday job's chat part.
	 *
	 * @return void
	 */
	public function weekly(): void {
		if ( (bool) ( new Environment( $this->plugin ) )->staging()['demo'] ) {
			return;
		}

		$alerts = $this->plugin->make( Alerts::class );

		if ( array() === $alerts->chat_places( Alerts::WEEKLY ) ) {
			return;
		}

		$alerts->send_weekly_chat( $this->sentence() );
	}

	/**
	 * Makes sure the Monday job is booked when the summary goes to chat only. (`AlertsModule` books it for email.)
	 *
	 * @return void
	 */
	public function schedule(): void {
		if ( array() !== $this->plugin->make( Alerts::class )->chat_places( Alerts::WEEKLY ) ) {
			$this->plugin->make( Digest::class )->schedule();
		}
	}

	/**
	 * What last week was, in the site's language: how many articles were published and how many a channel skips.
	 *
	 * @return string
	 */
	private function sentence(): string {
		$locale = get_locale();
		$switch = '' !== $locale && switch_to_locale( $locale );

		try {
			$digest    = $this->plugin->make( Digest::class )->build( null, 'last_week' );
			$counts    = (array) $digest['counts'];
			$published = (int) $counts['published'];
			$skipped   = (int) $counts['skipped'];

			if ( 0 === $published ) {
				return __( 'Nothing was published last week.', 'estasoft-news-distribution' );
			}

			$first = sprintf(
				/* translators: %d: number of articles. */
				_n( '%d article was published last week.', '%d articles were published last week.', $published, 'estasoft-news-distribution' ),
				$published
			);

			if ( 0 === $skipped ) {
				return $first . ' ' . __( 'None were skipped.', 'estasoft-news-distribution' );
			}

			return $first . ' ' . sprintf(
				/* translators: %d: number of articles. */
				_n( '%d was skipped by at least one channel.', '%d were skipped by at least one channel.', $skipped, 'estasoft-news-distribution' ),
				$skipped
			);
		} finally {
			if ( $switch ) {
				restore_previous_locale();
			}
		}
	}
}
