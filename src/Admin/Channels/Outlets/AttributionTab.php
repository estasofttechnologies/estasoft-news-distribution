<?php
/**
 * Outlets, Attribution.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Outlets\Attribution;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * The line that credits the original article, one per language: "Originally
 * published at {site}." in the article's own language. `{site}` becomes the
 * publication's name and links back to the original. A language the site does
 * not publish in is listed, so its line is ready if it is added, but it cannot
 * be edited here.
 */
final class AttributionTab extends AbstractTab {

	public function slug(): string {
		return 'attribution';
	}

	public function render(): string {
		$saved = $this->saved_lines();
		$main  = $this->lines_card( $saved ) . $this->side_card(
			__( 'What it cannot do', 'estasoft-news-distribution' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'It cannot edit a story after an outlet has it, and it cannot translate a line for you: each line is exactly the text you type for that language.', 'estasoft-news-distribution' ) )
		);

		return $this->form(
			$this->split( $main . $this->savebar( __( 'Save lines', 'estasoft-news-distribution' ) ), $this->medium_preview( $saved ) ),
			__( 'Attribution lines', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The lines saved so far, by language. A rejected save is shown again as typed.
	 *
	 * @return array<string, string>
	 */
	private function saved_lines(): array {
		$values = $this->values();
		$lines  = isset( $values['attribution'] ) && is_array( $values['attribution'] ) ? $values['attribution'] : array();
		$out    = array();

		foreach ( $lines as $code => $line ) {
			if ( is_scalar( $line ) ) {
				$out[ (string) $code ] = (string) $line;
			}
		}

		return $out;
	}

	/**
	 * The languages to list: the ones the site publishes in, then the others that have a draft line.
	 *
	 * @return array<int, array{code: string, active: bool}>
	 */
	private function languages(): array {
		$site = $this->channel->site_languages();
		$rows = array();
		$have = array();

		foreach ( $site as $code ) {
			$rows[]                            = array(
				'code'   => $code,
				'active' => true,
			);
			$have[ Catalog::primary( $code ) ] = true;
		}

		foreach ( array_keys( Attribution::defaults() ) as $primary ) {
			if ( ! isset( $have[ $primary ] ) ) {
				$rows[] = array(
					'code'   => (string) $primary,
					'active' => false,
				);
			}
		}

		return $rows;
	}

	/**
	 * Why a language cannot be edited: it is not one of the site's.
	 *
	 * @return string
	 */
	private function not_set_up(): string {
		$source = $this->plugin->languages()->name();

		if ( 'polylang' === $source ) {
			return __( 'Not set up in Polylang', 'estasoft-news-distribution' );
		}

		if ( 'wpml' === $source ) {
			return __( 'Not set up in WPML', 'estasoft-news-distribution' );
		}

		return __( 'Not a language of this site', 'estasoft-news-distribution' );
	}

	/**
	 * The card with one line per language.
	 *
	 * @param array<string, string> $saved Saved lines.
	 * @return string
	 */
	private function lines_card( array $saved ): string {
		$id     = 'estasoft-nd-card-attribution';
		$rows   = '';
		$errors = $this->screen->field_errors();
		$active = array();

		foreach ( $this->languages() as $row ) {
			$code  = $row['code'];
			$name  = $this->language_name( $code );
			$input = Field::id( $this->screen->scope_id(), 'attribution-' . $code );
			$error = (string) ( $errors[ 'attribution.' . $code ] ?? '' );
			$line  = Attribution::line( $code, $saved );

			if ( $row['active'] ) {
				$active[] = $code;
			}

			$control = Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => $input,
				),
				esc_html(
					sprintf(
						/* translators: %s: a language name such as English. */
						__( '%s attribution', 'estasoft-news-distribution' ),
						$name
					)
				)
			) . Html::void(
				'input',
				array(
					'class'            => 'estasoft-nd-field estasoft-nd-outlets-line',
					'type'             => 'text',
					'id'               => $input,
					'name'             => $row['active'] ? Field::name( 'attribution', $code ) : null,
					'value'            => $line,
					'lang'             => $code,
					'dir'              => Catalog::direction( $code ),
					'maxlength'        => 300,
					'autocomplete'     => 'off',
					'disabled'         => ! $row['active'],
					'aria-describedby' => '' !== $error ? $input . '-error' : null,
					'aria-invalid'     => '' !== $error ? 'true' : null,
				)
			) . $this->error_line( $input, $error );

			if ( ! $row['active'] ) {
				$control .= Html::tag( 'p', array(), StatusView::inline( Status::NOT_SET_UP, $this->not_set_up() ) );
			}

			$rows .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-row' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-form-label' ),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-lang estasoft-nd-outlets-tag' ), esc_html( Catalog::tag( $code ) ) )
					. Html::tag( 'span', array(), esc_html( $name ) )
				)
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $control )
			);
		}

		$counts = Attribution::counts( $active, $saved );

		$note = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-row' ),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				Html::tag( 'strong', array(), esc_html( Attribution::TOKEN ) ) . ' '
				. esc_html__( 'becomes your site’s name and links to the original article. A line without {site} is saved as written, with a warning, and carries no link. Leave a line empty to send articles without one. The Kurmanji, Arabic and Hebrew lines are drafts for a native reviewer.', 'estasoft-news-distribution' )
				. ' ' . esc_html( $this->not_set_up_note() )
			)
			. ( array() !== $active ? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( Attribution::summary( $counts['linked'], $counts['total'] ) ) ) : '' )
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $id,
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card-head' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => $id,
					),
					esc_html__( 'Attribution line', 'estasoft-news-distribution' )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Added to an article sent to an outlet, so readers see where it first appeared. It links to the original and is written in the article’s own language.', 'estasoft-news-distribution' ) )
			) . $rows . $note
		);
	}

	/**
	 * The sentence that says a language that is not set up cannot be edited.
	 *
	 * @return string
	 */
	private function not_set_up_note(): string {
		$source = $this->plugin->languages()->name();

		if ( 'polylang' === $source ) {
			return __( 'Languages that are not set up in Polylang cannot be edited.', 'estasoft-news-distribution' );
		}

		if ( 'wpml' === $source ) {
			return __( 'Languages that are not set up in WPML cannot be edited.', 'estasoft-news-distribution' );
		}

		return __( 'Languages the site does not publish in cannot be edited.', 'estasoft-news-distribution' );
	}

	/**
	 * How a line reads once a story is in Medium, for the first right-to-left language, else the first language.
	 *
	 * @param array<string, string> $saved Saved lines.
	 * @return string
	 */
	private function medium_preview( array $saved ): string {
		$languages = $this->channel->site_languages();
		$language  = (string) ( $languages[0] ?? '' );

		foreach ( $languages as $code ) {
			if ( 'rtl' === Catalog::direction( $code ) ) {
				$language = $code;
				break;
			}
		}

		if ( '' === $language ) {
			return '';
		}

		$found  = $this->plugin->reader()->recent( $language, 30 * DAY_IN_SECONDS, 1 );
		$title  = array() !== $found ? $found[0]->title() : __( 'Ferry service to the island starts next week', 'estasoft-news-distribution' );
		$url    = array() !== $found ? $found[0]->permalink() : home_url( '/' );
		$site   = $this->plugin->settings()->publication_name( $language );
		$line   = Attribution::line( $language, $saved );
		$credit = '' === $line ? '' : Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), Attribution::html( $line, $site, $url ) );
		$sample = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-outlets-sample',
				'lang'  => $language,
				'dir'   => Catalog::direction( $language ),
			),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html( $title ) ) . $credit
		);

		return $this->side_card(
			__( 'How it looks in Medium', 'estasoft-news-distribution' ),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %s: a language name such as Arabic. */
						__( 'An article in %s after you import it into Medium.', 'estasoft-news-distribution' ),
						$this->language_name( $language )
					)
				)
			) . $sample . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Medium’s import tool adds a canonical link back to your site, which search engines can use to credit it.', 'estasoft-news-distribution' ) )
		);
	}
}
