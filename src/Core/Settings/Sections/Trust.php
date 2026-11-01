<?php
/**
 * Settings, Publisher trust.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Core\Trust as TrustCheck;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The transparency check and the pages the site points to.
 *
 * Stored as `estasoft_nd_settings.trust`: `ownership_page`, `corrections_page` and
 * `ethics_page`, each a page ID as text, or "0" for none. The check and the markup are
 * `Core\Trust`. Everything is read on this site. Nothing is sent anywhere, and no gap
 * stops an article.
 */
final class Trust extends Section {

	public const NAME = 'trust';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$publication = Plugin::instance()->settings()->publication_name();
		$options     = array( $this->service(), 'page_options' );

		return array(
			'ownership_page'   => array(
				'type'    => 'enum',
				'label'   => __( 'Ownership and funding page', 'estasoft-news-distribution' ),
				'default' => '0',
				'options' => $options,
				'width'   => 360,
				'help'    => '' === $publication
					? __( 'Answers “the company or network behind the content”: a page that says who owns the publication and how it is paid for.', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: the publication name, such as Example Times. */
						__( 'Answers “the company or network behind the content”: a page that says who owns %s and how it is paid for.', 'estasoft-news-distribution' ),
						$publication
					),
			),
			'corrections_page' => array(
				'type'    => 'enum',
				'label'   => __( 'Corrections policy page', 'estasoft-news-distribution' ),
				'default' => '0',
				'options' => $options,
				'width'   => 360,
				'help'    => __( 'Optional. How you correct mistakes. Google’s news policies do not list it.', 'estasoft-news-distribution' ),
			),
			'ethics_page'      => array(
				'type'    => 'enum',
				'label'   => __( 'Ethics and standards page', 'estasoft-news-distribution' ),
				'default' => '0',
				'options' => $options,
				'width'   => 360,
				'help'    => __( 'Optional. Your editorial standards. Google’s news policies do not list it.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'Publisher trust', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 50;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		$counts = $this->service()->counts();
		$chosen = 0;

		foreach ( array_keys( TrustCheck::PAGE_PROPERTIES ) as $field ) {
			if ( null !== $this->service()->chosen( $field ) ) {
				++$chosen;
			}
		}

		$pages = __( 'News Distribution cannot tell which of your pages is a policy page, so you choose them. Each list shows your published pages, newest first.', 'estasoft-news-distribution' );

		if ( 0 === $chosen ) {
			$pages .= ' ' . __( 'Nothing is chosen yet.', 'estasoft-news-distribution' );
		} else {
			$pages .= ' ' . sprintf(
				/* translators: 1: number of pages chosen, 2: number of pages that can be chosen. */
				__( '%1$s of %2$s are chosen.', 'estasoft-news-distribution' ),
				number_format_i18n( $chosen ),
				number_format_i18n( count( TrustCheck::PAGE_PROPERTIES ) )
			);
		}

		return array(
			'cards'  => array(
				array(
					'id'          => 'transparency',
					'title'       => __( 'Transparency', 'estasoft-news-distribution' ),
					'description' => __( 'Google’s news policies ask news sources for clear dates and bylines, information about the authors, the publication and the publisher, information about the company or network behind the content, and contact information.', 'estasoft-news-distribution' )
						. ' ' . $this->summary( $counts ),
					'rows'        => array(
						array(
							'label' => '',
							'html'  => array( $this, 'render_table' ),
						),
					),
				),
				array(
					'id'          => 'pages',
					'title'       => __( 'Pages to point to', 'estasoft-news-distribution' ),
					'description' => $pages,
					'rows'        => array(
						array( 'field' => 'ownership_page' ),
						array( 'field' => 'corrections_page' ),
						array( 'field' => 'ethics_page' ),
					),
				),
				array(
					'id'          => 'markup',
					'title'       => __( 'What News Distribution adds to your pages', 'estasoft-news-distribution' ),
					'description' => $this->markup_description( $chosen ),
					'rows'        => array(
						array(
							'label' => '',
							'html'  => array( $this, 'render_markup' ),
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'What the check reads', 'estasoft-news-distribution' ),
					'html'  => $this->reads_body(),
				),
			),
			'cannot' => array(
				__( 'Write your policy pages. It checks the pages you choose and links to them.', 'estasoft-news-distribution' ),
				__( 'Judge what a page says. It cannot tell a good policy from a weak one.', 'estasoft-news-distribution' ),
				__( 'Check that a contact address works or reaches a person.', 'estasoft-news-distribution' ),
				__( 'Make Google or any other platform use the markup or treat your site differently.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The table of what Google asks for, what was found and what to do.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_table(): string {
		$rows = array();

		foreach ( $this->service()->rows() as $row ) {
			$action = '';

			if ( '' !== $row['action_label'] && '' !== $row['action_url'] ) {
				$action .= Html::tag( 'a', array( 'href' => $row['action_url'] ), esc_html( $row['action_label'] ) );
			} elseif ( '' !== $row['action_label'] ) {
				$action .= esc_html( $row['action_label'] );
			}

			if ( '' !== $row['action_note'] ) {
				$action .= Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-block' ), esc_html( $row['action_note'] ) );
			}

			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( $row['asks'] ) ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $row['found'] ) ),
				StatusView::inline( $row['status'] ),
				$action,
			);
		}

		return Grid::render(
			'estasoft-nd-cols-trust',
			array(
				__( 'What Google asks for', 'estasoft-news-distribution' ),
				__( 'What News Distribution found', 'estasoft-news-distribution' ),
				__( 'Status', 'estasoft-news-distribution' ),
				__( 'What to do', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Transparency check', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The markup a site would print: the chosen pages, with an example for the rest.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_markup(): string {
		$data = $this->service()->markup( true );
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			Tag::plain( __( 'Example', 'estasoft-news-distribution' ) ) . ' ' . esc_html__( 'Addresses of pages you have not chosen are made up.', 'estasoft-news-distribution' )
		) . Code::block( is_string( $json ) ? $json : '', __( 'Structured data example', 'estasoft-news-distribution' ) );
	}

	/**
	 * The sentence that says how many rows are clean.
	 *
	 * @param array{clean: int, attention: int, total: int} $counts Counts from the check.
	 * @return string
	 */
	private function summary( array $counts ): string {
		$intro = __( 'This table checks your site against that list:', 'estasoft-news-distribution' );

		if ( 0 === $counts['attention'] ) {
			return $intro . ' ' . sprintf(
				/* translators: %s: how many items there are, such as 6. */
				_n( '%s is clean.', 'All %s are clean.', $counts['total'], 'estasoft-news-distribution' ),
				number_format_i18n( $counts['total'] )
			);
		}

		return $intro . ' ' . sprintf(
			/* translators: 1: how many items are clean, 2: how many items there are, 3: how many items need attention. */
			__( '%1$s of %2$s are clean, %3$s need attention.', 'estasoft-news-distribution' ),
			number_format_i18n( $counts['clean'] ),
			number_format_i18n( $counts['total'] ),
			number_format_i18n( $counts['attention'] )
		);
	}

	/**
	 * The line that says what is printed today.
	 *
	 * @param int $chosen How many pages are chosen.
	 * @return string
	 */
	private function markup_description( int $chosen ): string {
		$base = __( 'For each page you choose, News Distribution prints a link in the structured data of your site, using schema.org properties. This is what it would print once all three pages are chosen.', 'estasoft-news-distribution' );

		if ( 0 === $chosen ) {
			return $base . ' ' . __( 'Today nothing is printed, because no page is chosen.', 'estasoft-news-distribution' );
		}

		return $base . ' ' . sprintf(
			/* translators: %s: how many pages are chosen, such as 2. */
			_n( 'Today it prints the %s page you chose.', 'Today it prints the %s pages you chose.', $chosen, 'estasoft-news-distribution' ),
			number_format_i18n( $chosen )
		);
	}

	/**
	 * The body of the "What the check reads" card.
	 *
	 * @return string Escaped HTML.
	 */
	private function reads_body(): string {
		$items = array(
			array(
				__( 'Dates and bylines', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: number of articles, such as 18. */
					__( 'The last %s articles in your feeds.', 'estasoft-news-distribution' ),
					number_format_i18n( TrustCheck::SAMPLE )
				),
			),
			array(
				__( 'Author bios', 'estasoft-news-distribution' ),
				__( 'The public author page of each author in WordPress.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'About and Contact', 'estasoft-news-distribution' ),
				__( 'The pages at /about/ and /contact/.', 'estasoft-news-distribution' ),
			),
		);
		$html  = '';

		foreach ( $items as $item ) {
			$html .= Html::tag(
				'div',
				array(),
				Html::tag( 'strong', array(), esc_html( $item[0] ) ) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $item[1] ) )
			);
		}

		return $html . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'Everything is read on your own site. Nothing is sent to anyone. A gap is a hint for you, not an error: News Distribution never holds an article back because of it.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The trust check and markup service.
	 *
	 * @return TrustCheck
	 */
	private function service(): TrustCheck {
		return Plugin::instance()->make( TrustCheck::class );
	}
}
