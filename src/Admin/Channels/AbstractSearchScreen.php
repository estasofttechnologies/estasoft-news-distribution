<?php
/**
 * What the Bing and Yandex pages share: the search sitemap and the hand-ticked steps.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\SearchSitemap\AbstractSearchSitemapChannel;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SitemapReport;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Bing and Yandex read the same plain sitemap. Their pages differ in the words
 * and in the steps in the platform's own tools. Those steps are ticked by the
 * person and saved with the channel's settings: News Distribution cannot see
 * inside Bing Webmaster Tools or Yandex Webmaster.
 */
abstract class AbstractSearchScreen extends AbstractSitemapScreen {

	/**
	 * The platform's name, such as "Bing".
	 *
	 * @return string
	 */
	abstract protected function platform(): string;

	/**
	 * The rule sentences for the card, as `rule code => sentence`.
	 *
	 * @return array<string, string>
	 */
	abstract protected function rule_sentences(): array;

	/**
	 * The "What it cannot do" card.
	 *
	 * @return string
	 */
	abstract protected function cannot_card(): string;

	/**
	 * The words of the three steps.
	 *
	 * Keys: `ready` (step 1 text), `verify_title`, `verify_text`, `verify_label` (with `%s` for the
	 * date), `verify_label_plain`, `verify_help`, `verify_link`, `verify_url`, `submit_title`,
	 * `submit_text`, `submit_label`, `submit_label_plain`, `submit_note` (or empty), `robots_help`.
	 *
	 * @return array<string, string>
	 */
	abstract protected function steps_copy(): array;

	protected function what(): string {
		return __( 'search sitemap', 'estasoft-news-distribution' );
	}

	protected function valid_text( int $entries ): string {
		return sprintf(
			/* translators: %s: number of URLs in the sitemap. */
			_n( 'Valid, %s URL', 'Valid, %s URLs', $entries, 'estasoft-news-distribution' ),
			number_format_i18n( $entries )
		);
	}

	public function enqueue( string $tab ): void {
		parent::enqueue( $tab );
		Assets::screen_style( 'search-sitemap' );
	}

	public function render( string $tab ): string {
		$channel = $this->channel instanceof AbstractSearchSitemapChannel ? $this->channel : null;

		if ( null === $channel ) {
			return '';
		}

		$reports = SitemapReport::for_channel( $this->plugin, $channel );
		$fetch   = $channel->fetch_report();
		$main    = $this->sitemap_card( $reports, $fetch ) . $this->steps_card( $reports );
		$side    = $this->rules_card( $channel->id(), $this->platform(), $this->rule_sentences() ) . $this->cannot_card();

		return $this->head() . $this->notice() . $this->form( $this->split( $main, $side ) );
	}

	/**
	 * A message bar under the heading, before the cards. Most pages have none.
	 *
	 * @return string Escaped HTML, or an empty string.
	 */
	protected function notice(): string {
		return '';
	}

	/**
	 * "Your search sitemap": the addresses, how the platform's fetches look and
	 * the choice of which sitemap to submit.
	 *
	 * @param array<string, array<string, mixed>> $reports Language => report.
	 * @param array<string, array<string, mixed>> $fetch   Language => fetch state.
	 * @return string
	 */
	private function sitemap_card( array $reports, array $fetch ): string {
		$body = $this->language_rows( $reports, array() ) . $this->fetch_footer( $fetch ) . $this->rows( array( 'source', 'existing_url' ) );

		return Card::flush(
			'sitemap',
			__( 'Your search sitemap', 'estasoft-news-distribution' ),
			__( 'A plain XML sitemap of your latest 1,000 articles, one per language, with an accurate last-modified time. It is not the Google News sitemap.', 'estasoft-news-distribution' ),
			$body
		);
	}

	/**
	 * The one fetch line under the addresses, shown once the platform has fetched.
	 *
	 * @param array<string, array<string, mixed>> $fetch Language => fetch state.
	 * @return string
	 */
	private function fetch_footer( array $fetch ): string {
		$last   = 0;
		$agent  = '';
		$count  = 0;
		$codes  = array();
		$behind = array();

		foreach ( $fetch as $language => $state ) {
			$count  += (int) $state['fetches_24h'];
			$codes[] = (string) $state['status'];

			if ( Status::ON_TIME !== $state['status'] ) {
				$behind[ FetchSummary::display( (string) $state['status'] ) ][] = Tag::lang( (string) $language );
			}

			if ( (int) $state['last_ts'] > $last ) {
				$last  = (int) $state['last_ts'];
				$agent = (string) $state['agent'];
			}
		}

		if ( $last <= 0 ) {
			return '';
		}

		$text = sprintf(
			/* translators: 1: the name of a crawler such as Bingbot; 2: how long ago, such as "3 h ago"; 3: the number of fetches, such as "6 fetches". */
			__( 'Last fetched by %1$s %2$s, %3$s in the last 24 hours', 'estasoft-news-distribution' ),
			'' !== $agent ? FetchSummary::agent_name( $agent ) : $this->crawler_name(),
			FetchSummary::ago( $last ),
			sprintf(
				/* translators: %s: number of fetches. */
				_n( '%s fetch', '%s fetches', $count, 'estasoft-news-distribution' ),
				number_format_i18n( $count )
			)
		);
		$more = '';

		foreach ( $behind as $code => $languages ) {
			$more .= Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html( Status::word( (string) $code ) . ': ' ) . implode( ' ', $languages )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-address-footer' ),
			StatusView::inline( FetchSummary::display( FetchSummary::worst( $codes ) ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) )
			. $more
		);
	}

	/**
	 * The three steps. The first is done when every sitemap is valid; the other
	 * two are the person's own word. A page with more steps (Baidu) replaces this.
	 *
	 * @param array<string, array<string, mixed>> $reports Language => report.
	 * @return string
	 */
	protected function steps_card( array $reports ): string {
		$copy     = $this->steps_copy();
		$values   = $this->values();
		$all_good = array() !== $reports;

		foreach ( $reports as $report ) {
			$all_good = $all_good && $report['valid'];
		}

		$verified  = ! empty( $values['verified'] );
		$submitted = ! empty( $values['submitted'] );

		$step1 = $this->step(
			1,
			$all_good,
			__( 'Your sitemap is ready', 'estasoft-news-distribution' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $copy['ready'] ) )
		);

		$step2 = $this->step(
			2,
			$verified,
			$copy['verify_title'],
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $copy['verify_text'] ) )
			. $this->tick( 'verified', $verified && '' !== $this->day( (string) ( $values['verified_on'] ?? '' ) ) ? sprintf( $copy['verify_label'], $this->day( (string) $values['verified_on'] ) ) : $copy['verify_label_plain'], $verified )
			. $this->date_field( 'verified_on', $verified, (string) ( $values['verified_on'] ?? '' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $copy['verify_help'] ) )
			. $this->external_link( $copy['verify_link'], $copy['verify_url'] )
		);

		$submit_label = $submitted && '' !== $copy['submit_label'] && '' !== $this->day( (string) ( $values['submitted_on'] ?? '' ) )
			? sprintf( $copy['submit_label'], $this->day( (string) $values['submitted_on'] ) )
			: $copy['submit_label_plain'];

		$step3 = $this->step(
			3,
			$submitted,
			$copy['submit_title'],
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $copy['submit_text'] ) )
			. $this->tick( 'submitted', $submit_label, $submitted )
			. $this->date_field( 'submitted_on', $submitted, (string) ( $values['submitted_on'] ?? '' ) )
			. ( '' !== $copy['submit_note'] ? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $copy['submit_note'] ) ) : '' )
			. $this->tick( 'robots', __( 'Also list the sitemaps in robots.txt', 'estasoft-news-distribution' ), ! empty( $values['robots'] ), $copy['robots_help'] )
		);

		return Html::tag(
			'section',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-label' => __( 'Steps to set up the sitemap', 'estasoft-news-distribution' ),
			),
			$step1 . $step2 . $step3
		);
	}

	/**
	 * One numbered step: a circle that shows a check when done, a title and the body.
	 *
	 * @param int    $number Position, from 1.
	 * @param bool   $done   Whether the step is done.
	 * @param string $title  Plain title.
	 * @param string $body   Trusted HTML.
	 * @param bool   $now    Whether this is the step to do next, which is drawn with a tint.
	 * @return string
	 */
	protected function step( int $number, bool $done, string $title, string $body, bool $now = false ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-step' . ( $now && ! $done ? ' estasoft-nd-step--now' : '' ) ),
			Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-step__num' . ( $done ? ' estasoft-nd-step__num--done' : ( $now ? ' estasoft-nd-step__num--now' : '' ) ) ),
				$done ? Icon::svg( 'check' ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html__( 'Done', 'estasoft-news-distribution' ) ) : esc_html( (string) $number )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-grow' ),
				Html::tag( 'h2', array( 'class' => 'estasoft-nd-title' ), esc_html( $title ) ) . $body
			)
		);
	}

	/**
	 * A check box the person ticks, with an optional line of help under its label.
	 *
	 * @param string $field   Field name.
	 * @param string $label   Plain label.
	 * @param bool   $checked Whether it is ticked.
	 * @param string $help    Plain help text, or an empty string.
	 * @return string
	 */
	protected function tick( string $field, string $label, bool $checked, string $help = '' ): string {
		$id   = Field::id( $this->scope(), $field );
		$text = esc_html( $label );

		if ( '' !== $help ) {
			$text .= '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $help ) );
		}

		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( $field ),
				'value' => '0',
			)
		) . Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-check estasoft-nd-check--top',
				'for'   => $id,
			),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'id'      => $id,
					'name'    => Field::name( $field ),
					'value'   => '1',
					'checked' => $checked,
				)
			) . Html::tag( 'span', array(), $text )
		);
	}

	/**
	 * The day a step was ticked, sent with the form. A step ticked before keeps its
	 * day; a step ticked now gets today.
	 *
	 * @param string $field  Field name.
	 * @param bool   $ticked Whether the step is ticked now.
	 * @param string $stored The day stored, or an empty string.
	 * @return string
	 */
	protected function date_field( string $field, bool $ticked, string $stored ): string {
		$value = $ticked && '' !== $this->day( $stored ) ? $stored : Clock::format( Clock::now(), 'Y-m-d' );

		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( $field ),
				'value' => $value,
			)
		);
	}

	/**
	 * A stored day as "24 Sep", or an empty string when there is none.
	 *
	 * @param string $stored The stored day.
	 * @return string
	 */
	protected function day( string $stored ): string {
		$ts = AbstractSearchSitemapChannel::date_to_ts( $stored );

		return $ts > 0 ? Clock::format( $ts, 'j M' ) : '';
	}
}
