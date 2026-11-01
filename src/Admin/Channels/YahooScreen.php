<?php
/**
 * The Yahoo News channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=yahoo-news`. Three steps: the feed,
 * getting onboarded and what the feed holds. Yahoo gives its feed specification
 * to partners it onboards and it was not read for this build, so every rule here
 * is News Distribution's own default and the page says so. Ticking "I have sent
 * my feed URLs to Yahoo" moves the application from Not requested to Requested;
 * after that the status is the person's to change on the Applications screen.
 */
final class YahooScreen extends AbstractRssPartnerScreen {

	private const PLATFORM = 'yahoo-news';

	public static function channel_id(): string {
		return self::PLATFORM;
	}

	protected function test_label(): string {
		return __( 'Test feed', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Pulled channel: News Distribution builds the feed. Yahoo decides whether to take it, and it may need to onboard the feed before it starts fetching.', 'estasoft-news-distribution' );
	}

	public function render( string $tab ): string {
		$partner = $this->partner();

		if ( null === $partner ) {
			return '';
		}

		$head = $this->notice()
			. $this->off_bar( __( 'The feed is not served while it is off.', 'estasoft-news-distribution' ) )
			. $this->skipped_bar();
		$main = $this->form( $this->steps_card() );
		$side = $this->check_card( __( 'Latest feed test', 'estasoft-news-distribution' ) )
			. $this->fetches_card()
			. $this->rules_card(
				self::PLATFORM,
				__( 'Yahoo News', 'estasoft-news-distribution' ),
				array(
					'feed.format'    => __( 'RSS 2.0 over HTTPS', 'estasoft-news-distribution' ),
					'date.published' => __( 'Publication date with a time zone, never in the future', 'estasoft-news-distribution' ),
					'body.min_words' => __( 'Body of at least the minimum words you set', 'estasoft-news-distribution' ),
					'html.tags'      => __( 'Common HTML only; scripts and frames are removed', 'estasoft-news-distribution' ),
					'image.lead'     => __( 'Lead image first in the body', 'estasoft-news-distribution' ),
					'guid.stable'    => __( 'Same guid and original date when an article is updated', 'estasoft-news-distribution' ),
				)
			)
			. $this->cannot_card( __( 'It cannot apply to Yahoo for you, see Yahoo’s answer or make Yahoo fetch the feed. Yahoo decides whether to take your feed, and you record the application by hand.', 'estasoft-news-distribution' ) );

		return $head . $this->split( $main, $side ) . $this->forms();
	}

	/**
	 * The three steps.
	 *
	 * @return string
	 */
	private function steps_card(): string {
		$partner = $this->partner();
		$status  = (string) $this->plugin->applications()->get( self::PLATFORM )['status'];
		$done    = array(
			$this->check_passed(),
			Status::NOT_REQUESTED !== $status,
			$this->channel->is_enabled(),
		);
		$now     = array_search( false, $done, true );
		$codes   = null === $partner ? array() : $partner->carried_languages();

		if ( Status::NOT_REQUESTED === $status ) {
			$state_text = __( 'Once you tick the box and save, the status becomes Requested.', 'estasoft-news-distribution' );
		} else {
			$state_text = __( 'News Distribution cannot see Yahoo’s answer, so you change the status on the Applications screen.', 'estasoft-news-distribution' );
		}

		$minutes = (int) round( $this->plugin->settings()->index_first_delay_seconds() / MINUTE_IN_SECONDS );
		$delay   = sprintf(
			/* translators: %s: how long articles wait, such as "15 minutes". */
			__( 'Articles appear in this feed %s after publishing, so your own site is crawled first.', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %d: number of minutes. */
				_n( '%d minute', '%d minutes', $minutes, 'estasoft-news-distribution' ),
				$minutes
			)
		);

		$steps = array(
			array(
				'id'    => 'feed',
				'title' => __( 'Your feed is ready', 'estasoft-news-distribution' ),
				'text'  => sprintf(
					/* translators: %s: the languages the feed is built for, such as "English (US) and Arabic". */
					__( 'News Distribution builds a Yahoo feed for %s. Copy the URLs for the next step.', 'estasoft-news-distribution' ),
					$this->names( $codes )
				),
				'body'  => $this->feed_lines( __( 'Yahoo News feed', 'estasoft-news-distribution' ) ) . $this->toggles( array( 'kurdish' ) ),
			),
			array(
				'id'    => 'onboard',
				'title' => __( 'Get onboarded by Yahoo', 'estasoft-news-distribution' ),
				'text'  => __( 'Yahoo may need to onboard your feed before it starts fetching. Ask your Yahoo contact how to apply, then send the feed URLs. News Distribution cannot apply for you.', 'estasoft-news-distribution' ),
				'body'  => $this->toggles( array( 'urls_sent' ) ) . Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: 1: the application status, such as "Not requested", 2: what happens next. */
							__( 'Status: %1$s. %2$s', 'estasoft-news-distribution' ),
							Status::word( $status ),
							$state_text
						)
					)
				),
			),
			array(
				'id'    => 'receives',
				'title' => __( 'Choose what Yahoo receives', 'estasoft-news-distribution' ),
				'text'  => __( 'These options apply to this feed only.', 'estasoft-news-distribution' ),
				'body'  => $this->toggles(
					array( 'min_length', 'wait_delay' ),
					array( 'wait_delay' => $delay ),
					array( 'min_length' => array( 'min_words' ) )
				) . Html::tag( 'p', array( 'class' => 'estasoft-nd-small' ), $this->page_link( __( 'Change the delay', 'estasoft-news-distribution' ), 'estasoft-nd-settings', array( 'section' => 'general' ) ) ),
			),
		);

		foreach ( $steps as $index => $step ) {
			$steps[ $index ]['state'] = $done[ $index ] ? 'done' : ( $now === $index ? 'now' : 'todo' );
		}

		return $this->steps( 'yahoo-news', __( 'Set up Yahoo News', 'estasoft-news-distribution' ), $steps );
	}
}
