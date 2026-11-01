<?php
/**
 * The Microsoft Start channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=microsoft-start`. Four steps: the
 * feeds, the invitation, adding the feeds in Partner Hub and recording the
 * result. Microsoft Partner Hub is invitation-only and News Distribution cannot
 * see Microsoft's answer, so the application status is set by hand here, with the
 * page's own Save bar. It is the same status the Applications screen shows.
 */
final class MicrosoftStartScreen extends AbstractRssPartnerScreen {

	private const PLATFORM = 'microsoft-start';

	/**
	 * When Microsoft last fetched a feed, read once per page.
	 *
	 * @var int|null
	 */
	private ?int $fetched = null;

	public static function channel_id(): string {
		return self::PLATFORM;
	}

	protected function test_label(): string {
		return __( 'Test feeds', 'estasoft-news-distribution' );
	}

	public function description(): string {
		$text = __( 'News Distribution builds one feed per language. Microsoft decides what Start shows and when it reads the feeds.', 'estasoft-news-distribution' );
		$last = $this->fetched();

		if ( $last > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %s: how long ago, such as "4 mins". */
				__( 'It fetched them %s ago.', 'estasoft-news-distribution' ),
				human_time_diff( $last, Clock::now() )
			);
		}

		return $text;
	}

	/**
	 * When Microsoft last fetched a feed.
	 *
	 * @return int
	 */
	private function fetched(): int {
		if ( null === $this->fetched ) {
			$this->fetched = $this->last_fetch();
		}

		return $this->fetched;
	}

	public function render( string $tab ): string {
		$partner = $this->partner();

		if ( null === $partner ) {
			return '';
		}

		$head = $this->notice()
			. $this->off_bar( __( 'The feeds are not served while it is off.', 'estasoft-news-distribution' ) )
			. $this->skipped_bar();
		$main = $this->form( $this->steps_card() . $this->receives_card() );
		$side = $this->check_card( __( 'Latest feed check', 'estasoft-news-distribution' ) )
			. $this->fetches_card()
			. $this->thumbnail_card()
			. $this->rules_card(
				self::PLATFORM,
				__( 'Microsoft Start', 'estasoft-news-distribution' ),
				array(
					'feed.format'             => __( 'RSS 2.0, one feed per language', 'estasoft-news-distribution' ),
					'item.unique_id'          => __( 'A unique ID on every item, the same after an edit', 'estasoft-news-distribution' ),
					'item.updated_element'    => __( 'The Updated date moves only for a real change', 'estasoft-news-distribution' ),
					'image.missing'           => __( 'A thumbnail on every item; articles without a featured image are left out', 'estasoft-news-distribution' ),
					'image.below_recommended' => sprintf(
						/* translators: 1: recommended width, 2: recommended height, in pixels. */
						__( 'A thumbnail of %1$s × %2$s px recommended', 'estasoft-news-distribution' ),
						number_format_i18n( $this->limit( 'image_recommended_width', 1280 ) ),
						number_format_i18n( $this->limit( 'image_recommended_height', 720 ) )
					),
				)
			)
			. $this->cannot_card( __( 'It cannot ask Microsoft for an invitation, see Microsoft’s answer, make Microsoft fetch the feeds sooner, or choose what Start shows. You record the application by hand.', 'estasoft-news-distribution' ) );

		return $head . $this->split( $main, $side ) . $this->forms();
	}

	/**
	 * A number from the rule pack.
	 *
	 * @param string $name    Limit name.
	 * @param int    $fallback Used when the pack does not have it.
	 * @return int
	 */
	private function limit( string $name, int $fallback ): int {
		return (int) $this->plugin->rules()->limit( self::PLATFORM, $name, $fallback );
	}

	/**
	 * The four steps.
	 *
	 * @return string
	 */
	private function steps_card(): string {
		$row    = $this->plugin->applications()->get( self::PLATFORM );
		$status = (string) $row['status'];
		$listed = Status::LISTED === $status;
		$done   = array(
			$this->check_passed(),
			$listed,
			$listed || $this->fetched() > 0,
			Status::NOT_REQUESTED !== $status,
		);
		$now    = array_search( false, $done, true );
		$source = (string) ( $this->plugin->rules()->pack( self::PLATFORM )['source_url'] ?? '' );
		$codes  = $this->partner() ? $this->partner()->carried_languages() : array();

		$steps = array(
			array(
				'id'    => 'feeds',
				'title' => __( 'Your feeds are ready', 'estasoft-news-distribution' ),
				'text'  => __( 'One RSS feed per language. Copy each address when you add a feed in Microsoft’s Partner Hub.', 'estasoft-news-distribution' ),
				'body'  => $this->feed_lines( __( 'Microsoft Start feed', 'estasoft-news-distribution' ) ),
			),
			array(
				'id'    => 'invitation',
				'title' => __( 'Get an invitation from Microsoft', 'estasoft-news-distribution' ),
				'text'  => __( 'Partner Hub is invitation-only. Microsoft sends an invitation code after it approves your site. News Distribution cannot ask for or see the answer.', 'estasoft-news-distribution' ),
				'body'  => Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						$listed
							? __( 'News Distribution cannot see this step. It counts it as done because the application status in step 4 is Listed.', 'estasoft-news-distribution' )
							: __( 'News Distribution cannot see this step. Record the result in step 4 when Microsoft answers.', 'estasoft-news-distribution' )
					)
				),
			),
			array(
				'id'    => 'hub',
				'title' => __( 'Add your feeds in Partner Hub', 'estasoft-news-distribution' ),
				'text'  => sprintf(
					/* translators: %s: the languages the feeds are built for, such as "English (US) and Arabic". */
					__( 'Create one feed for each language (%s) and paste the matching address from step 1. Microsoft decides when it starts reading them.', 'estasoft-news-distribution' ),
					$this->names( $codes )
				),
				'body'  => '' !== $source
					? $this->external_link( __( 'Microsoft Support: Feed content specifications in Partner Hub', 'estasoft-news-distribution' ), $source )
					: '',
			),
			array(
				'id'    => 'record',
				'title' => __( 'Record the result', 'estasoft-news-distribution' ),
				'text'  => __( 'Microsoft does not tell News Distribution whether you are listed, so you record it here.', 'estasoft-news-distribution' ),
				'body'  => $this->record_body( $row ),
			),
		);

		foreach ( $steps as $index => $step ) {
			$steps[ $index ]['state'] = $done[ $index ] ? 'done' : ( $now === $index ? 'now' : 'todo' );
		}

		return $this->steps( 'microsoft-start', __( 'Set up Microsoft Start', 'estasoft-news-distribution' ), $steps );
	}

	/**
	 * Step 4: the status and the date, saved with the page's own Save bar.
	 *
	 * @param array<string, string> $row The application record.
	 * @return string
	 */
	private function record_body( array $row ): string {
		$words = array();

		foreach ( Applications::statuses() as $code ) {
			$words[ $code ] = Status::word( $code );
		}

		$errors = $this->errors();
		$typed  = $this->values();
		$chosen = isset( $typed['application_status'] ) && is_scalar( $typed['application_status'] ) ? (string) $typed['application_status'] : (string) $row['status'];
		$day    = isset( $typed['applied_on'] ) && is_scalar( $typed['applied_on'] ) ? (string) $typed['applied_on'] : Applications::show_date( (string) $row['applied_on'] );
		$status = Field::render(
			$this->scope(),
			'application_status',
			array(
				'type'    => 'enum',
				'label'   => __( 'Application status', 'estasoft-news-distribution' ),
				'help'    => __( 'Set by hand. Choosing a status keeps the feeds as they are; nothing is sent to Microsoft.', 'estasoft-news-distribution' ),
				'options' => $words,
				'width'   => 200,
			),
			$chosen,
			(string) ( $errors['application_status'] ?? '' )
		);
		$date   = Field::render(
			$this->scope(),
			'applied_on',
			array(
				'type'        => 'string',
				'label'       => __( 'Applied on', 'estasoft-news-distribution' ),
				'help'        => __( 'Day, month and year, for example 12 Sep 2026. The Applications screen shows the same status.', 'estasoft-news-distribution' ),
				'width'       => 200,
				'max'         => 20,
				'placeholder' => __( '12 Sep 2026', 'estasoft-news-distribution' ),
			),
			$day,
			(string) ( $errors['applied_on'] ?? '' )
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-record' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-strong',
						'for'   => Field::id( $this->scope(), 'application_status' ),
					),
					esc_html__( 'Application status', 'estasoft-news-distribution' )
				) . $status
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-strong',
						'for'   => Field::id( $this->scope(), 'applied_on' ),
					),
					esc_html__( 'Applied on', 'estasoft-news-distribution' )
				) . $date
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-small' ), $this->page_link( __( 'Open Applications', 'estasoft-news-distribution' ), 'estasoft-nd-channels', array( 'tab' => 'applications' ) ) )
			)
		);
	}

	/**
	 * "What Microsoft Start receives": the exclusive option and how edits are read.
	 *
	 * @return string
	 */
	private function receives_card(): string {
		$minutes = (int) round( $this->plugin->settings()->index_first_delay_seconds() / MINUTE_IN_SECONDS );
		$wait    = sprintf(
			/* translators: %d: number of minutes. */
			_n( '%d minute', '%d minutes', $minutes, 'estasoft-news-distribution' ),
			$minutes
		);
		$help = sprintf(
			/* translators: %s: how long the other channels wait, such as "15 minutes". */
			__( 'Microsoft Start gets each new article when it is published. Other pushed channels, such as WebSub and IndexNow, wait %s.', 'estasoft-news-distribution' ),
			$wait
		);
		$plan = sprintf(
			/* translators: %s: a link with the words "release plan". */
			esc_html__( 'Change the time or the channel for one article in its %s.', 'estasoft-news-distribution' ),
			Html::tag( 'a', array( 'href' => Links::check() ), esc_html__( 'release plan', 'estasoft-news-distribution' ) )
		);
		$dates = sprintf(
			/* translators: %s: a link with the words "Article dates". */
			esc_html__( 'Microsoft Start reads items with the same ID as one article and skips the item unless its Updated or Published date changes. News Distribution keeps the ID and moves the Updated date only for a real change. A minor edit keeps the date. See %s in Settings.', 'estasoft-news-distribution' ),
			Html::tag( 'a', array( 'href' => Links::settings( 'dates' ) ), esc_html__( 'Article dates', 'estasoft-news-distribution' ) )
		);

		return Card::note(
			'receives',
			__( 'What Microsoft Start receives', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'These options apply to the Microsoft Start feeds only.', 'estasoft-news-distribution' ) )
				. $this->toggles( array( 'exclusive' ), array( 'exclusive' => $help ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), $plan )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html__( 'When an article changes', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), $dates )
			)
		);
	}

	/**
	 * "Thumbnail size".
	 *
	 * @return string
	 */
	private function thumbnail_card(): string {
		$text = sprintf(
			/* translators: 1: recommended width, 2: recommended height, 3: smallest width, 4: smallest height, all in pixels. */
			__( 'Microsoft recommends %1$s × %2$s px and at least %3$s × %4$s px. Image presets can make a 16:9 copy for Microsoft Start and never change the original.', 'estasoft-news-distribution' ),
			number_format_i18n( $this->limit( 'image_recommended_width', 1280 ) ),
			number_format_i18n( $this->limit( 'image_recommended_height', 720 ) ),
			number_format_i18n( $this->limit( 'image_min_width', 640 ) ),
			number_format_i18n( $this->limit( 'image_min_height', 360 ) )
		);

		return Card::note(
			'thumbnail',
			__( 'Thumbnail size', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
				. Html::tag( 'a', array( 'href' => Links::check( 0, 'images' ) ), esc_html__( 'Open Image presets', 'estasoft-news-distribution' ) )
			)
		);
	}
}
