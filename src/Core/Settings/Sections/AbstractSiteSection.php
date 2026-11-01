<?php
/**
 * Shared pieces of the site setup sections.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Core\Settings\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Languages, Clock and time zone, Environment, Compatibility, Page markup and
 * Developers share a few things: the address of their screen, a list of rows that
 * runs inside a card, a button that runs a check, and a row of finding. This
 * class holds those. It is not a section itself, so the settings framework skips it.
 */
abstract class AbstractSiteSection extends Section {

	/**
	 * @return string
	 */
	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	/**
	 * The address of a section's screen.
	 *
	 * @param string               $section The section name.
	 * @param array<string, mixed> $args    More query arguments, such as `tab`.
	 * @return string
	 */
	public static function url_of( string $section, array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'    => 'estasoft-nd-settings',
					'section' => $section,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A link that runs one of the checks, with its nonce.
	 *
	 * The settings form wraps the whole screen, and a form cannot sit inside a form,
	 * so a check on those screens is a link. Every such action only reads the site
	 * and keeps what it read; it changes no setting.
	 *
	 * @param string               $action The admin-post action, which is also the nonce name.
	 * @param array<string, mixed> $args   More query arguments.
	 * @return string An address, escaped for an attribute by the caller.
	 */
	public static function action_url( string $action, array $args = array() ): string {
		return wp_nonce_url(
			add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ),
			$action
		);
	}

	/**
	 * Rows of a table that sit inside a card, edge to edge.
	 *
	 * @param string               $cols_class A class from the screen's stylesheet that sets `--cols`.
	 * @param array<int, string>   $headers    Column headings (plain).
	 * @param array<int, string[]> $rows       Rows of cells; each cell is trusted HTML.
	 * @param string               $caption    What the table lists, for screen readers.
	 * @param bool                 $bold_first Whether the first cell is a bold row heading.
	 * @return string
	 */
	public static function grid( string $cols_class, array $headers, array $rows, string $caption, bool $bold_first = true ): string {
		$head = '';

		foreach ( $headers as $header ) {
			$head .= Html::tag( 'div', array( 'role' => 'columnheader' ), esc_html( $header ) );
		}

		$body = Html::tag(
			'div',
			array(
				'class' => Html::classes( 'estasoft-nd-grid-row', 'estasoft-nd-grid-row--head', $cols_class ),
				'role'  => 'row',
			),
			$head
		);

		foreach ( $rows as $cells ) {
			$row = '';

			foreach ( $cells as $index => $cell ) {
				$row .= Html::tag(
					'div',
					array(
						'role'  => 0 === $index ? 'rowheader' : 'cell',
						'class' => 0 === $index ? 'estasoft-nd-strong' : '',
					),
					$cell
				);
			}

			$body .= Html::tag(
				'div',
				array(
					'class' => Html::classes( 'estasoft-nd-grid-row', $cols_class ),
					'role'  => 'row',
				),
				$row
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-scroll' ),
			Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-table',
					'role'       => 'table',
					'aria-label' => $caption,
				),
				$body
			)
		);
	}

	/**
	 * One result with an icon and its words, for a table cell or a line.
	 *
	 * @param string $tone success, warning, danger, info or neutral.
	 * @param string $text Plain text.
	 * @param string $icon An icon name; the tone's usual one when empty.
	 * @return string
	 */
	public static function result( string $tone, string $text, string $icon = '' ): string {
		$icons = array(
			'success' => 'check-circle',
			'warning' => 'warning',
			'danger'  => 'x-circle',
			'info'    => 'info',
			'neutral' => 'minus-circle',
		);

		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . $tone ),
			Icon::svg( '' !== $icon ? $icon : ( $icons[ $tone ] ?? 'info' ) ) . Html::tag( 'span', array(), esc_html( $text ) )
		);
	}

	/**
	 * A list of plain sentences with a small icon in front of each.
	 *
	 * @param string[] $items An icon name is not needed; every line gets the same one.
	 * @param string   $icon  The icon name.
	 * @param string   $tone  success or neutral: the icon's colour.
	 * @return string
	 */
	public static function icon_list( array $items, string $icon = 'check', string $tone = 'success' ): string {
		$list = '';

		foreach ( $items as $item ) {
			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--' . $tone ),
				Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( (string) $item ) )
			);
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list );
	}

	/**
	 * A row that states one finding: an icon, a bold line, what it means, and an action.
	 *
	 * @param string $tone    success, warning, danger, info or neutral.
	 * @param string $title   The bold line (plain).
	 * @param string $text    What it means (plain).
	 * @param string $actions Trusted HTML for buttons at the end, or empty.
	 * @return string
	 */
	public static function finding( string $tone, string $title, string $text, string $actions = '' ): string {
		$icons = array(
			'success' => 'check-circle',
			'warning' => 'warning',
			'danger'  => 'x-circle',
			'info'    => 'info',
			'neutral' => 'minus-circle',
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-finding' ),
			Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . $tone . ' estasoft-nd-finding__icon' ),
				Icon::svg( $icons[ $tone ] ?? 'info' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-finding__text' ),
				Html::tag( 'strong', array(), esc_html( $title ) ) . ( '' !== $text ? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) ) : '' )
			)
			. ( '' !== $actions ? Html::tag( 'div', array( 'class' => 'estasoft-nd-finding__actions' ), $actions ) : '' )
		);
	}
}
