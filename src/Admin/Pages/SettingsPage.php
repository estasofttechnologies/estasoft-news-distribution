<?php
/**
 * Settings.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Pages;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\SaveBar;
use Estasoft\NewsDistribution\Admin\Ui\SectionForm;
use Estasoft\NewsDistribution\Admin\Ui\SideNav;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * One screen for every settings section. The side list holds the sections the
 * person may use; the body is built from the chosen section's layout.
 */
final class SettingsPage extends Page {

	public function slug(): string {
		return 'estasoft-nd-settings';
	}

	public function title(): string {
		return __( 'Settings', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 60;
	}

	public function permission(): string {
		return Access::MANAGE;
	}

	/**
	 * The sections this person may use, by group and position.
	 *
	 * @return array<string, Section>
	 */
	private function sections(): array {
		$list = array();

		foreach ( $this->plugin->settings()->sections() as $name => $section ) {
			if ( $section->available() && $this->plugin->access()->can( $section->permission() ) ) {
				$list[ $name ] = $section;
			}
		}

		$groups = array_flip( array( 'site', 'content', 'operations' ) );

		uasort(
			$list,
			static function ( Section $a, Section $b ) use ( $groups ): int {
				$by_group = ( $groups[ $a->group() ] ?? 9 ) <=> ( $groups[ $b->group() ] ?? 9 );

				return 0 !== $by_group ? $by_group : $a->order() <=> $b->order();
			}
		);

		return $list;
	}

	/**
	 * The section being opened: the `section` argument, else the first one.
	 *
	 * @param array<string, Section> $sections Sections the person may use.
	 * @return Section|null
	 */
	private function current( array $sections ): ?Section {
		$name = Query::key( 'section' );

		if ( isset( $sections[ $name ] ) ) {
			return $sections[ $name ];
		}

		$first = reset( $sections );

		return false === $first ? null : $first;
	}

	public function render(): string {
		$sections = $this->sections();
		$section  = $this->current( $sections );

		if ( null === $section ) {
			return PageHeader::render( __( 'Settings', 'estasoft-news-distribution' ) )
				. Message::text( 'info', __( 'There are no settings you can change.', 'estasoft-news-distribution' ) );
		}

		$name = $section->name();
		$tab  = Query::key( 'tab' );

		if ( '' === $tab ) {
			// A section can also be opened as a view, such as &view=overview on Languages.
			$tab = Query::key( 'view' );
		}

		// A section can own a whole tab of its own, such as Alerts, Emails.
		$own_page = '' === $tab ? null : $section->render_tab( $tab );

		if ( null !== $own_page ) {
			return $own_page;
		}

		$values = $this->plugin->settings()->section_for_screen( $name );
		$errors = array();

		$flag = static function ( string $key ): bool {
			return Query::is( $key, '1' );
		};

		$message = '';

		if ( $flag( 'saved' ) ) {
			$message = Message::text( 'success', __( 'Settings saved.', 'estasoft-news-distribution' ) );
		} elseif ( $flag( 'error' ) ) {
			$stash = get_transient( Admin::FORM_KEY . get_current_user_id() );
			delete_transient( Admin::FORM_KEY . get_current_user_id() );

			if ( is_array( $stash ) && ( $stash['section'] ?? '' ) === $name ) {
				$errors  = (array) ( $stash['errors'] ?? array() );
				$values  = array_merge( $values, (array) ( $stash['values'] ?? array() ) );
				$message = Message::text( 'danger', __( 'Nothing was saved. Fix the highlighted settings and save again.', 'estasoft-news-distribution' ) );
			}
		}

		$form = new SectionForm( $section, $values, $errors );

		$body = $form->html();

		if ( $section->has_save_bar() ) {
			$body .= SaveBar::render();
		}

		if ( ! $section->in_form() ) {
			// A section made of separate actions prints its own forms; a form cannot sit inside a form.
			$form_html = Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $message . $body );

			return PageHeader::render( __( 'Settings', 'estasoft-news-distribution' ), $section->description() )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-split' ),
					Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side' ), SideNav::render( __( 'Settings sections', 'estasoft-news-distribution' ), $this->nav_groups( $sections, $name ) ) )
					. $form_html
				);
		}

		$form_html = Html::tag(
			'form',
			array(
				'method'             => 'post',
				'action'             => admin_url( 'admin-post.php' ),
				'class'              => 'estasoft-nd-split__main',
				'novalidate'         => true,
				'data-settings-form' => true,
				'data-section'       => $name,
				'data-dirty-guard'   => true,
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => 'estasoft_nd_save_section',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'section',
					'value' => $name,
				)
			)
			. wp_nonce_field( Admin::NONCE_SECTION, '_wpnonce', true, false )
			. $message
			. $body
		);

		return PageHeader::render( __( 'Settings', 'estasoft-news-distribution' ), $section->description() )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-split' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side' ), SideNav::render( __( 'Settings sections', 'estasoft-news-distribution' ), $this->nav_groups( $sections, $name ) ) )
				. $form_html
			);
	}

	/**
	 * The side list.
	 *
	 * @param array<string, Section> $sections Sections the person may use.
	 * @param string                 $current  Name of the open section.
	 * @return array<int, array{label: string, items: array<int, array{label: string, url: string, current: bool}>}>
	 */
	private function nav_groups( array $sections, string $current ): array {
		$labels = array(
			'site'       => __( 'Site', 'estasoft-news-distribution' ),
			'content'    => __( 'Content', 'estasoft-news-distribution' ),
			'operations' => __( 'Operations', 'estasoft-news-distribution' ),
		);

		$groups = array();

		foreach ( $labels as $key => $label ) {
			$groups[ $key ] = array(
				'label' => $label,
				'items' => array(),
			);
		}

		foreach ( $sections as $name => $section ) {
			$key = isset( $groups[ $section->group() ] ) ? $section->group() : 'site';

			$groups[ $key ]['items'][] = array(
				'label'   => $section->title(),
				'url'     => $this->url( array( 'section' => $name ) ),
				'current' => $name === $current,
			);
		}

		return array_values( $groups );
	}
}
