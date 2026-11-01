<?php
/**
 * Settings, Page labels.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Markup\LabelMarkup;

defined( 'ABSPATH' ) || exit;

/**
 * Which labels the article page shows, and where.
 *
 * Stored as `estasoft_nd_settings.labels`: `updated`, `correction`, `ai` and `source` (each
 * label is on or off) and `placement` (`byline`: the Updated line and the AI-assisted label
 * first, the correction and the source line last; `before`: all of them first; `manual`:
 * nowhere on their own, only where the block or the shortcode is placed). The labels
 * themselves are printed by `Markup\LabelMarkup` through `Modules\LabelsModule`.
 */
final class Labels extends Section {

	public const NAME = 'labels';

	public const BYLINE = 'byline';
	public const BEFORE = 'before';
	public const MANUAL = 'manual';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'updated'    => array(
				'type'    => 'bool',
				'label'   => __( 'Updated line', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'Updated line', 'estasoft-news-distribution' ),
				'help'    => __( 'Shows “Updated 21 October 2026, 14:21” under the date, only when the modified date moved. Articles whose date was kept show nothing.', 'estasoft-news-distribution' ),
			),
			'correction' => array(
				'type'    => 'bool',
				'label'   => __( 'Correction note', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'Correction note', 'estasoft-news-distribution' ),
				'help'    => __( 'Shows the note an editor writes in the article panel, at the end of the article, with its date and time.', 'estasoft-news-distribution' ),
			),
			'ai'         => array(
				'type'    => 'bool',
				'label'   => __( 'AI-assisted label', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'AI-assisted label', 'estasoft-news-distribution' ),
				'help'    => __( 'Shows next to the byline when the content label is AI-assisted. The label is set automatically when AI writes text, and a person can only remove it by changing the content label.', 'estasoft-news-distribution' ),
			),
			'source'     => array(
				'type'    => 'bool',
				'label'   => __( 'Source line', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'Source line', 'estasoft-news-distribution' ),
				'help'    => __( 'Shows “Source: …” with a link when the content label is Syndicated and a source is filled in.', 'estasoft-news-distribution' ),
			),
			'placement'  => array(
				'type'    => 'enum',
				'label'   => __( 'Where they go', 'estasoft-news-distribution' ),
				'default' => self::BYLINE,
				'control' => 'select',
				'options' => array(
					self::BYLINE => __( 'Under the headline, and the correction at the end', 'estasoft-news-distribution' ),
					self::BEFORE => __( 'Before the article text', 'estasoft-news-distribution' ),
					self::MANUAL => __( 'Nowhere: I place them with the block or the shortcode', 'estasoft-news-distribution' ),
				),
				'width'   => 440,
				'help'    => __( 'The block is called News Distribution labels. The shortcode is [estasoft_nd_labels].', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'Page labels', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Small lines that tell readers what changed or where text came from.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 20;
	}

	/**
	 * A label on the page changes the page and not a feed, so no feed is rebuilt for it.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return false;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'which',
					'title'       => __( 'Which labels show on the page', 'estasoft-news-distribution' ),
					'description' => __( 'Each one shows only when it applies to the article. Turning one off hides it on the page. It does not change what the feeds carry.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Labels', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_switches' ),
						),
						array( 'field' => 'placement' ),
						array(
							'label' => __( 'Wording', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_wording' ),
						),
					),
				),
				array(
					'id'          => 'look',
					'title'       => __( 'How they look', 'estasoft-news-distribution' ),
					'description' => __( 'Sample data. The same page in English and in Arabic, which reads right to left. The sample shows every label at once; a real article has one content label, so it shows the AI-assisted label or the source line, not both.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'html' => array( $this, 'render_samples' ),
							'wide' => true,
						),
					),
				),
				array(
					'id'          => 'style',
					'title'       => __( 'Style', 'estasoft-news-distribution' ),
					'description' => __( 'The labels use your theme’s text color and fonts. You can restyle them with these classes and variables, in your theme or in Additional CSS.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'html' => array( $this, 'render_style' ),
							'wide' => true,
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'Good to know', 'estasoft-news-distribution' ),
					'html'  => $this->good_to_know(),
				),
			),
			'cannot' => array(
				__( 'Hide a label from platforms. The feeds carry their own marks, and hiding the label on the page does not remove them.', 'estasoft-news-distribution' ),
				__( 'Decide what is a correction. An editor writes the note.', 'estasoft-news-distribution' ),
				__( 'Style a theme that prints its own labels. It adds its own only when none is there.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The four switches in one row.
	 *
	 * @param array<string, mixed>  $values The section's values.
	 * @param array<string, string> $errors Rejected fields.
	 * @return string Escaped HTML.
	 */
	public function render_switches( array $values, array $errors = array() ): string {
		$html   = '';
		$fields = $this->fields();

		foreach ( array( 'updated', 'correction', 'ai', 'source' ) as $name ) {
			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Field::render( self::NAME, $name, $fields[ $name ], $values[ $name ] ?? true, $errors[ $name ] ?? '' )
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $html );
	}

	/**
	 * The "Wording" row: where the words of each label come from.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_wording(): string {
		return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Each language uses its own words, taken from the plugin’s language files. Change them with any WordPress translation tool.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The English and Arabic samples, drawn with the same builder as the page.
	 *
	 * The words are sample data in two fixed languages, so they are not translated.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_samples(): string {
		$english = array(
			'ai'         => array( 'text' => 'AI-assisted' ),
			'updated'    => array(
				'text' => 'Updated 21 October 2026, 14:21',
				'iso'  => '2026-10-21T14:21:00+00:00',
			),
			'correction' => array(
				'heading' => 'Correction, 21 October, 14:21.',
				'note'    => 'An earlier version gave the start date as 17 April. It is 18 April.',
			),
			'source'     => array(
				'text' => 'Source: Example Wire Service.',
				'url'  => 'https://wire.example.com/',
				'link' => 'Read the original',
			),
		);
		$arabic  = array(
			'ai'         => array( 'text' => 'بمساعدة الذكاء الاصطناعي' ),
			'updated'    => array(
				'text' => 'حُدّث في 21 أكتوبر 2026، 14:21',
				'iso'  => '2026-10-21T14:21:00+00:00',
			),
			'correction' => array(
				'heading' => 'تصحيح، 21 أكتوبر، 14:21.',
				'note'    => 'ذكرت نسخة سابقة أن الموعد 17 أبريل. الموعد الصحيح 18 أبريل.',
			),
			'source'     => array(
				'text' => 'المصدر: Example Wire Service.',
				'url'  => 'https://wire.example.com/',
				'link' => 'اقرأ النص الأصلي',
			),
		);

		$cards = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-sample',
				'lang'  => 'en',
				'dir'   => 'ltr',
			),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html__( 'English', 'estasoft-news-distribution' ) )
			. Html::tag( 'h3', array( 'class' => 'estasoft-nd-sample__title' ), 'Harbor festival returns this spring' )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-sample__byline' ), '<span>By Sara Haddad</span> <span>Published 19 October 2026, 09:12</span>' )
			. LabelMarkup::top( $english )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-sample__text' ), 'The harbor festival returns on 18 April, with the ferry running late on both nights…' )
			. LabelMarkup::end( $english )
		) . Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-sample',
				'lang'  => 'ar',
				'dir'   => 'rtl',
			),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), 'العربية' )
			. Html::tag( 'h3', array( 'class' => 'estasoft-nd-sample__title' ), 'مهرجان الميناء يعود هذا الربيع' )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-sample__byline' ), '<span>بقلم سارة حداد</span> <span>نُشر في 19 أكتوبر 2026، 09:12</span>' )
			. LabelMarkup::top( $arabic )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-sample__text' ), 'يعود مهرجان الميناء في 18 أبريل، مع تأخّر رحلات العبّارة في الليلتين…' )
			. LabelMarkup::end( $arabic )
		);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-samples' ), $cards );
	}

	/**
	 * The "Style" card: the classes and the variables.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_style(): string {
		$code = "<p class=\"estasoft-nd-label estasoft-nd-label--ai\">AI-assisted</p>\n"
			. "<p class=\"estasoft-nd-updated\">Updated 21 October 2026, 14:21</p>\n"
			. "<aside class=\"estasoft-nd-correction\"><strong>Correction, 21 October, 14:21.</strong> …</aside>\n"
			. "\n"
			. ":root {\n"
			. "  --estasoft-nd-accent: #b45309;      /* correction bar */\n"
			. "  --estasoft-nd-label-bg: #eef2f7;    /* AI-assisted chip */\n"
			. "  --estasoft-nd-label-fg: inherit;\n"
			. '}';

		return Code::block( $code, __( 'Classes and variables of the page labels', 'estasoft-news-distribution' ) );
	}

	/**
	 * The "Good to know" card's list.
	 *
	 * @return string Escaped HTML.
	 */
	private function good_to_know(): string {
		$items = array(
			__( 'Every label has a text version for screen readers. A label is never an image or a color alone.', 'estasoft-news-distribution' ),
			__( 'The block, the shortcode and the automatic placement print the same markup.', 'estasoft-news-distribution' ),
		);
		$list  = '';

		foreach ( $items as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--ok' ), Icon::svg( 'check' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list );
	}
}
