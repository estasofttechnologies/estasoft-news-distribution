<?php
/**
 * Settings, Article dates.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\DateGuard;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The modified-date guard: what counts as a real change, and the saves it decided.
 *
 * Stored as `estasoft_nd_settings.dates`: `guard`, `counts` and `min_body_chars`.
 * The guard itself is `Core\DateGuard`.
 */
final class Dates extends Section {

	public const NAME = 'dates';

	/**
	 * What a person can count as a real change, with the words the form shows.
	 *
	 * @return array<string, array{label: string, help: string}>
	 */
	public static function change_options(): array {
		return array(
			'headline' => array(
				'label' => __( 'Headline', 'estasoft-news-distribution' ),
				'help'  => __( 'Any change to the words.', 'estasoft-news-distribution' ),
			),
			'body'     => array(
				'label' => __( 'Body text', 'estasoft-news-distribution' ),
				'help'  => __( 'A change of at least the smallest size set in this card.', 'estasoft-news-distribution' ),
			),
			'markup'   => array(
				'label' => __( 'Structured data', 'estasoft-news-distribution' ),
				'help'  => __( 'The author or the published date of the article, which the page markup carries.', 'estasoft-news-distribution' ),
			),
			'image'    => array(
				'label' => __( 'Featured image', 'estasoft-news-distribution' ),
				'help'  => __( 'A different image, not a re-crop of the same one.', 'estasoft-news-distribution' ),
			),
			'links'    => array(
				'label' => __( 'Links in the body', 'estasoft-news-distribution' ),
				'help'  => __( 'A link added, removed or pointed somewhere else.', 'estasoft-news-distribution' ),
			),
			'terms'    => array(
				'label' => __( 'Categories and tags', 'estasoft-news-distribution' ),
				'help'  => __( 'Off by default. Moving an article between sections rarely changes what it says.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'guard'          => array(
				'type'      => 'bool',
				'label'     => __( 'Date guard', 'estasoft-news-distribution' ),
				'default'   => true,
				'control'   => 'checkbox',
				'title'     => __( 'Keep the modified date when nothing important changed', 'estasoft-news-distribution' ),
				'help'      => __( 'A save that changes none of the things listed in this card leaves the modified date as it was.', 'estasoft-news-distribution' ),
				'row_label' => __( 'Date guard', 'estasoft-news-distribution' ),
			),
			'counts'         => array(
				'type'      => 'list',
				'label'     => __( 'What counts as a real change', 'estasoft-news-distribution' ),
				'default'   => array( 'headline', 'body', 'markup', 'image', 'links' ),
				'control'   => 'checkboxes',
				'item'      => array(
					'type'    => 'enum',
					'options' => array( self::class, 'change_options' ),
				),
				'options'   => array( self::class, 'change_options' ),
				'max_items' => 6,
			),
			'min_body_chars' => array(
				'type'    => 'int',
				'label'   => __( 'Smallest body change', 'estasoft-news-distribution' ),
				'default' => 20,
				'min'     => 1,
				'max'     => 1000,
				'unit'    => __( 'characters', 'estasoft-news-distribution' ),
				'width'   => 90,
				'help'    => __( 'Fixing a typo does not count. Adding a sentence does. Whole characters, 1 to 1,000.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'Article dates', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 30;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'modified',
					'title'       => __( 'Modified date', 'estasoft-news-distribution' ),
					'description' => __( 'Google says it uses the modified date in a sitemap only when it is consistently accurate. News Distribution moves it only when the article really changed.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'guard' ),
						array( 'field' => 'counts' ),
						array( 'field' => 'min_body_chars' ),
						array(
							'label' => __( 'Always counts', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_always' ),
						),
						array(
							'label' => __( 'Never counts', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_never' ),
						),
						array(
							'label' => __( 'Where the date is used', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_used' ),
						),
					),
				),
				array(
					'id'          => 'decision',
					'title'       => __( 'Where you see the decision', 'estasoft-news-distribution' ),
					'description' => __( 'The guard decides on every save. There is no switch to overrule it for one save, so an editor sees the result in two places.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'In the Publish box', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_publish_box' ),
						),
						array(
							'label' => __( 'In Article check', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_article_check' ),
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'Why it matters', 'estasoft-news-distribution' ),
					'text'  => __( 'A date that moves on every typo teaches crawlers to stop believing it. A date that stays put when the story changed hides the update. The published date never moves here. Only the modified date can, and only when the article really changed.', 'estasoft-news-distribution' ),
				),
			),
			'cannot' => array(
				__( 'Make Google trust the date. Google uses lastmod only when it is consistently and verifiably accurate. The guard keeps it honest, and Google decides the rest', 'estasoft-news-distribution' ),
				__( 'See changes made outside WordPress, such as in the database', 'estasoft-news-distribution' ),
				__( 'Judge a story. It compares what was saved, not what it means', 'estasoft-news-distribution' ),
				__( 'Overrule a single save. The date follows the rules above, and a correction note always moves it', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The "Recent saves" card, after the form cards.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_extra( array $values ): string {
		$rows = array();

		foreach ( Plugin::instance()->make( DateGuard::class )->recent( 5 ) as $save ) {
			$post_id = (int) $save['post_id'];
			$title   = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
			$title   = '' === $title ? __( '(no title)', 'estasoft-news-distribution' ) : $title;
			$link    = add_query_arg(
				array(
					'page' => 'estasoft-nd-check',
					'post' => $post_id,
				),
				admin_url( 'admin.php' )
			);
			$rows[]  = array(
				Html::tag( 'a', array( 'href' => $link ), esc_html( $title ) ),
				StatusView::pill( DateGuard::status_of( (string) $save['decision'] ) ),
				esc_html( $this->when( (int) $save['at'] ) ),
				esc_html( DateGuard::describe( (string) $save['reason'], (int) $save['chars'] ) ),
			);
		}

		if ( array() === $rows ) {
			$body = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-pad' ), esc_html__( 'No save has been decided yet. The next time a published article is saved, it appears here.', 'estasoft-news-distribution' ) );
		} else {
			$body = Grid::render(
				'estasoft-nd-cols-dates',
				array(
					__( 'Article', 'estasoft-news-distribution' ),
					__( 'Modified date', 'estasoft-news-distribution' ),
					__( 'Saved', 'estasoft-news-distribution' ),
					__( 'What changed', 'estasoft-news-distribution' ),
				),
				$rows,
				__( 'Recent saves', 'estasoft-news-distribution' )
			);
		}

		return Card::flush(
			'dates-recent',
			__( 'Recent saves', 'estasoft-news-distribution' ),
			__( 'What the guard decided on the last saves.', 'estasoft-news-distribution' ),
			$body
		);
	}

	/**
	 * The "Always counts" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_always(): string {
		return $this->list(
			array(
				__( 'A correction note is added to the article', 'estasoft-news-distribution' ),
				__( 'A change to the text of an existing correction note', 'estasoft-news-distribution' ),
			),
			'check'
		);
	}

	/**
	 * The "Never counts" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_never(): string {
		return $this->list(
			array(
				__( 'Widgets, sidebars and related-article lists', 'estasoft-news-distribution' ),
				__( 'Comment and view counts', 'estasoft-news-distribution' ),
				__( 'A new copyright year in the footer', 'estasoft-news-distribution' ),
			),
			'minus-circle'
		);
	}

	/**
	 * The "Where the date is used" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_used(): string {
		$items = array(
			array(
				__( 'Sitemaps', 'estasoft-news-distribution' ),
				__( 'The lastmod time in the search sitemap. The news sitemap does not carry one.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'Article markup', 'estasoft-news-distribution' ),
				__( 'The modified time in the page’s structured data and page tags (dateModified, article:modified_time).', 'estasoft-news-distribution' ),
			),
			array(
				__( 'Feeds', 'estasoft-news-distribution' ),
				__( 'The updated date in every language feed. The published date never changes.', 'estasoft-news-distribution' ),
			),
		);
		$html  = '';

		foreach ( $items as $item ) {
			$html .= Html::tag(
				'li',
				array(),
				Html::tag( 'strong', array(), esc_html( $item[0] ) ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-block' ), esc_html( $item[1] ) )
			);
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $html )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'All of these read WordPress’s modified date. The guard keeps it, so the posts list, the editor and every place above show the same date.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The "In the Publish box" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_publish_box(): string {
		$boxes = array(
			array(
				__( 'After a small edit', 'estasoft-news-distribution' ),
				__( 'kept at 13:41', 'estasoft-news-distribution' ),
			),
			array(
				__( 'After a real change', 'estasoft-news-distribution' ),
				__( 'moved to 13:05', 'estasoft-news-distribution' ),
			),
		);
		$html  = '';

		foreach ( $boxes as $box ) {
			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-example' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html( $box[0] ) )
				. Html::tag( 'span', array(), Html::tag( 'strong', array(), esc_html__( 'Modified date:', 'estasoft-news-distribution' ) ) . ' ' . esc_html( $box[1] ) )
			);
		}

		return Html::tag(
			'p',
			array(),
			esc_html__( 'In the classic editor, a line under the News Distribution note says what happened to the modified date.', 'estasoft-news-distribution' )
			. ' ' . Html::tag( 'span', array( 'class' => 'estasoft-nd-tag' ), esc_html__( 'Example', 'estasoft-news-distribution' ) )
		) . Html::tag( 'div', array( 'class' => 'estasoft-nd-examples' ), $html );
	}

	/**
	 * The "In Article check" row, with the note about the minor edit switch.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_article_check(): string {
		return Html::tag(
			'p',
			array(),
			esc_html__( 'The History tab lists every save under Versions, each with the words Date kept or Date moved and the reason.', 'estasoft-news-distribution' )
		) . Message::bar(
			'info',
			Html::tag( 'strong', array(), esc_html__( 'Minor edit is a different switch.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'It stops pushes for one save. The guard decides only whether the date moves. Use either, or both.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * A short list with an icon in front of each line.
	 *
	 * @param string[] $items Plain sentences.
	 * @param string   $icon  Icon name.
	 * @return string Escaped HTML.
	 */
	private function list( array $items, string $icon ): string {
		$html = '';

		foreach ( $items as $item ) {
			$html .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $html );
	}

	/**
	 * A time as people say it: "Today, 14:32", "Yesterday, 17:48" or "19 Oct, 09:12".
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function when( int $timestamp ): string {
		$day = Clock::format( $timestamp, 'Y-m-d' );
		$now = Clock::now();

		if ( Clock::format( $now, 'Y-m-d' ) === $day ) {
			/* translators: %s: a time such as 14:32. */
			return sprintf( __( 'Today, %s', 'estasoft-news-distribution' ), Clock::format( $timestamp, 'H:i' ) );
		}

		if ( Clock::format( $now - DAY_IN_SECONDS, 'Y-m-d' ) === $day ) {
			/* translators: %s: a time such as 17:48. */
			return sprintf( __( 'Yesterday, %s', 'estasoft-news-distribution' ), Clock::format( $timestamp, 'H:i' ) );
		}

		return Clock::format( $timestamp, 'j M, H:i' );
	}
}
