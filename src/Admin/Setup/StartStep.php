<?php
/**
 * The welcome screen of the setup: set up the publication, or look around in demo mode.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Admin\Ui\GlobalBanner;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Demo\SampleArticles;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Two cards and a card that says what demo mode does. Choosing "Try it with sample articles" starts demo
 * mode (`Core\Demo\Demo`): it holds every push back, serves no feed, shows a banner and makes the sample
 * articles. After "Exit demo" this screen opens with a note that says how many samples were removed.
 */
final class StartStep {

	/**
	 * The screen.
	 *
	 * @return string Escaped HTML.
	 */
	public static function render(): string {
		$samples = count( ( new SampleArticles( Plugin::instance() ) )->keys() );
		$cards   = Html::tag(
			'fieldset',
			array( 'class' => 'estasoft-nd-setup__modes' ),
			Html::tag( 'legend', array( 'class' => 'estasoft-nd-sr' ), esc_html__( 'How do you want to start?', 'estasoft-news-distribution' ) )
			. self::card(
				'setup',
				true,
				__( 'Set up my publication', 'estasoft-news-distribution' ),
				__( 'Takes about 5 minutes. You choose the channels, then News Distribution checks your latest articles.', 'estasoft-news-distribution' ),
				Tag::highlight( __( 'Recommended', 'estasoft-news-distribution' ) )
			)
			. self::card(
				'demo',
				false,
				__( 'Try it with sample articles', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: a number of sample articles, such as 12. */
					_n(
						'News Distribution fills every screen with %s sample article, so you can look around first. Nothing is sent to anyone, and your own posts are not touched.',
						'News Distribution fills every screen with %s sample articles, so you can look around first. Nothing is sent to anyone, and your own posts are not touched.',
						$samples,
						'estasoft-news-distribution'
					),
					number_format_i18n( $samples )
				),
				''
			)
		);

		$body = self::left_note()
			. Frame::form( Onboarding::START, $cards . self::demo_card() )
			. Frame::actions(
				Onboarding::START,
				array(
					'skip'     => array(
						'label' => __( 'Skip the setup', 'estasoft-news-distribution' ),
						'go'    => 'skip_setup',
					),
					'continue' => __( 'Continue', 'estasoft-news-distribution' ),
					'note'     => __( 'Continue starts step 1. With sample articles selected, it opens the Overview in demo mode instead. Skip the setup opens the Overview; you can run the setup later from there.', 'estasoft-news-distribution' ),
				)
			);

		return Frame::page(
			Onboarding::START,
			__( 'Welcome to Estasoft News Distribution', 'estasoft-news-distribution' ),
			__( 'Build feeds that follow each platform’s rules, and see which articles break one.', 'estasoft-news-distribution' ),
			$body
		);
	}

	/**
	 * A choice card with a round button.
	 *
	 * @param string $value   The posted value.
	 * @param bool   $checked Whether it starts chosen.
	 * @param string $title   Name (plain).
	 * @param string $text    What it does (plain).
	 * @param string $tag     Trusted HTML for a tag after the name, or empty.
	 * @return string
	 */
	private static function card( string $value, bool $checked, string $title, string $text, string $tag ): string {
		return Html::tag(
			'label',
			array( 'class' => Html::classes( 'estasoft-nd-choice', 'estasoft-nd-setup__choice', $checked ? 'estasoft-nd-choice--on' : '' ) ),
			Html::void(
				'input',
				array(
					'type'    => 'radio',
					'name'    => 'mode',
					'value'   => $value,
					'checked' => $checked,
				)
			)
			. Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-setup__choice-body' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-setup__choice-title' ), Html::tag( 'strong', array(), esc_html( $title ) ) . $tag )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
			)
		);
	}

	/**
	 * The card that says what demo mode does, with the banner as it will look.
	 *
	 * @return string
	 */
	private static function demo_card(): string {
		$effects = array(
			__( 'Sample articles are kept apart from your posts and stay hidden from your visitors', 'estasoft-news-distribution' ),
			__( 'Every screen shows a banner, and sample articles start with “Sample:”', 'estasoft-news-distribution' ),
			__( 'Everything stays on your site: sitemaps, feeds and pushes are not sent out', 'estasoft-news-distribution' ),
			__( 'Exit any time. News Distribution deletes the samples and takes you to the setup', 'estasoft-news-distribution' ),
		);
		$list    = '';

		foreach ( $effects as $effect ) {
			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-rule' ),
				Icon::svg( 'check' ) . Html::tag( 'span', array(), esc_html( $effect ) )
			);
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--padded estasoft-nd-setup__demo',
				'aria-labelledby' => 'estasoft-nd-setup-demo-title',
			),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => 'estasoft-nd-setup-demo-title',
				),
				esc_html__( 'How demo mode works', 'estasoft-news-distribution' )
			)
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-label' ), esc_html__( 'How the banner looks', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-setup__banner' ),
				GlobalBanner::render(
					__( 'Demo mode.', 'estasoft-news-distribution' ),
					__( 'These are sample articles. Nothing is sent to any platform.', 'estasoft-news-distribution' ),
					esc_html__( 'Exit demo', 'estasoft-news-distribution' )
				)
			)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html__( 'Sample articles start with “Sample:”, as in: Sample: Ferry service to the island starts next week', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * The note that opens this screen after "Exit demo".
	 *
	 * @return string Escaped HTML, or an empty string.
	 */
	private static function left_note(): string {
		if ( ! Query::has( 'demo_left' ) ) {
			return '';
		}

		$removed = Query::int( 'demo_left' );

		return Message::text(
			'success',
			sprintf(
				/* translators: %s: a number of sample articles. */
				_n(
					'Demo mode is off. %s sample article was removed, and your own posts were not touched.',
					'Demo mode is off. %s sample articles were removed, and your own posts were not touched.',
					$removed,
					'estasoft-news-distribution'
				),
				number_format_i18n( $removed )
			)
		);
	}
}
