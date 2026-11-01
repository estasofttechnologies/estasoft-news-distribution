<?php
/**
 * Settings, Republished content.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Dialog;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Switcher;
use Estasoft\NewsDistribution\Content\Republished as RepublishedContent;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Settings\Section;

defined( 'ABSPATH' ) || exit;

/**
 * What to do with an article that was published somewhere else first.
 *
 * Stored as `estasoft_nd_settings.republished`: `enabled` (the editor panel is on),
 * `handling` (what the page tells search engines), `exclude_partner`, `exclude_outlets`
 * and `exclude_news` (what the plugin's own channels leave out), `attribution` (the line
 * at the end of the article, by language, with `{source}`) and `sources` (the sources a
 * site uses: `id`, `name`, `handling` and `site`). The behaviour is in `Content\Republished`
 * and `Modules\RepublishedModule`.
 */
final class Republished extends Section {

	public const NAME = 'republished';

	/**
	 * Handling choices as the form lists them.
	 *
	 * @return array<string, array{label: string, help: string}>
	 */
	public static function handling_choices(): array {
		$out = array();

		foreach ( RepublishedContent::handling_options() as $code => $option ) {
			$out[ $code ] = array(
				'label' => $option['title'],
				'help'  => $option['help'],
			);
		}

		return $out;
	}

	/**
	 * What a source may say about handling: nothing (follow the default) or one of the four.
	 *
	 * @return array<string, string>
	 */
	public static function source_handling_choices(): array {
		$out = array( '' => __( 'Same as the default', 'estasoft-news-distribution' ) );

		foreach ( RepublishedContent::handling_options() as $code => $option ) {
			$out[ $code ] = $option['label'];
		}

		return $out;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'enabled'         => array(
				'type'    => 'bool',
				'label'   => __( 'In the editor', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'handling'        => array(
				'type'    => 'enum',
				'label'   => __( 'Default handling', 'estasoft-news-distribution' ),
				'default' => RepublishedContent::NOINDEX,
				'control' => 'radio',
				'options' => array( self::class, 'handling_choices' ),
				'help'    => __( 'Each article can choose differently. Google’s advice on syndicated content changes, so read its current help page before you rely on a default.', 'estasoft-news-distribution' ),
			),
			'exclude_partner' => array(
				'type'    => 'bool',
				'label'   => __( 'Keep republished articles out of partner feeds', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'exclude_outlets' => array(
				'type'    => 'bool',
				'label'   => __( 'Keep them out of outlets and social posts', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'exclude_news'    => array(
				'type'    => 'bool',
				'label'   => __( 'Keep them out of the Google News sitemap', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'attribution'     => array(
				'type'        => 'map',
				'label'       => __( 'Attribution line', 'estasoft-news-distribution' ),
				'default'     => array(
					'en' => 'Source: {source}',
					'ku' => 'Çavkanî: {source}',
					'ar' => 'المصدر: {source}',
				),
				'item'        => array(
					'type' => 'string',
					'max'  => 160,
				),
				'key_pattern' => '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/',
			),
			'sources'         => array(
				'type'      => 'list',
				'label'     => __( 'Sources you use', 'estasoft-news-distribution' ),
				'default'   => array(),
				'max_items' => 100,
				'item'      => array(
					'type'   => 'group',
					'label'  => __( 'Source', 'estasoft-news-distribution' ),
					'fields' => array(
						'id'       => array(
							'type'    => 'string',
							'label'   => __( 'Source id', 'estasoft-news-distribution' ),
							'format'  => 'key',
							'max'     => 60,
							'default' => '',
						),
						'name'     => array(
							'type'     => 'string',
							'label'    => __( 'Source name', 'estasoft-news-distribution' ),
							'max'      => 80,
							'required' => true,
							'default'  => '',
						),
						'handling' => array(
							'type'    => 'enum',
							'label'   => __( 'Source handling', 'estasoft-news-distribution' ),
							'default' => '',
							'options' => array( self::class, 'source_handling_choices' ),
						),
						'site'     => array(
							'type'    => 'string',
							'label'   => __( 'Source address', 'estasoft-news-distribution' ),
							'max'     => 200,
							'default' => '',
						),
					),
				),
			),
		);
	}

	public function title(): string {
		return __( 'Republished content', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 70;
	}

	/**
	 * Turns the form's extra keys into the stored shape: gives a new source its id, drops a
	 * deleted source and an empty new row, and keeps the Google News switch on while the
	 * handling is a noindex.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		if ( array_key_exists( 'sources', $input ) && is_array( $input['sources'] ) ) {
			$gone = isset( $input['delete_source'] ) && is_scalar( $input['delete_source'] ) ? sanitize_key( (string) $input['delete_source'] ) : '';
			$rows = array();
			$used = array();

			foreach ( $input['sources'] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$name = isset( $row['name'] ) && is_scalar( $row['name'] ) ? trim( (string) $row['name'] ) : '';
				$site = isset( $row['site'] ) && is_scalar( $row['site'] ) ? trim( (string) $row['site'] ) : '';
				$id   = isset( $row['id'] ) && is_scalar( $row['id'] ) ? sanitize_key( (string) $row['id'] ) : '';

				if ( '' === $name && '' === $site ) {
					continue;
				}

				if ( '' !== $gone && $id === $gone ) {
					continue;
				}

				if ( '' === $id ) {
					$id = sanitize_title( $name );
					$id = '' === $id ? 'source' : mb_substr( $id, 0, 50 );
				}

				$base = $id;
				$n    = 2;

				while ( isset( $used[ $id ] ) ) {
					$id = $base . '-' . $n;
					++$n;
				}

				$used[ $id ] = true;
				$row['id']   = $id;
				$rows[]      = $row;
			}

			$input['sources'] = $rows;
		} elseif ( isset( $input['sources_posted'] ) ) {
			// Every row was removed, so the form posted none.
			$input['sources'] = array();
		}

		unset( $input['delete_source'], $input['sources_posted'] );

		$handling = isset( $input['handling'] ) && is_scalar( $input['handling'] ) ? (string) $input['handling'] : $this->current( 'handling' );

		if ( in_array( $handling, array( RepublishedContent::NOINDEX, RepublishedContent::NEWS_NOINDEX ), true ) ) {
			$input['exclude_news'] = true;
		}

		return $input;
	}

	/**
	 * Checks what no single field can: each source needs a usable address when it has one.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		$errors = array();

		foreach ( (array) ( $values['sources'] ?? array() ) as $index => $row ) {
			$site = trim( (string) ( $row['site'] ?? '' ) );

			if ( '' === $site ) {
				continue;
			}

			$parts = wp_parse_url( false === strpos( $site, '://' ) ? 'https://' . $site : $site );

			if ( ! is_array( $parts ) || empty( $parts['host'] ) || false === strpos( (string) $parts['host'], '.' ) ) {
				$errors[ 'sources.' . $index . '.site' ] = sprintf(
					/* translators: %s: the name of a source, such as Example Wire Service. */
					__( 'The address of %s must be a site address such as wire.example.com.', 'estasoft-news-distribution' ),
					(string) ( $row['name'] ?? '' )
				);
			}
		}

		return $errors;
	}

	/**
	 * A value as it is stored now, for when a save leaves it out.
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	private function current( string $field ): string {
		$value = \Estasoft\NewsDistribution\Plugin::instance()->settings()->get( 'republished.' . $field, '' );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'republished',
					'title'       => __( 'Republished content', 'estasoft-news-distribution' ),
					'description' => __( 'Wire and partner copy (an article with the content label Syndicated) should say where it came from, and should not compete with the original.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'In the editor', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_editor_switch' ),
						),
						array( 'field' => 'handling' ),
						array(
							'label' => __( 'Outgoing channels', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_outgoing' ),
						),
						array(
							'label' => __( 'Attribution line', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_attribution' ),
						),
					),
				),
				array(
					'id'          => 'sources',
					'title'       => __( 'Sources you use', 'estasoft-news-distribution' ),
					'description' => __( 'Pick a source in the editor instead of typing it each time. Add one when you first use it.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'html' => array( $this, 'render_sources' ),
							'wide' => true,
						),
					),
				),
			),
			'cannot' => array(
				__( 'Find republished copy by itself. A person marks it, or an import does. The near-duplicate check in Reports can point to likely cases.', 'estasoft-news-distribution' ),
				__( 'Control what other sites do with your articles. This is about copy you publish.', 'estasoft-news-distribution' ),
				__( 'Make a platform accept the copy. Some partners ask for original reporting.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The "In the editor" switch.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_editor_switch( array $values ): string {
		return Switcher::render(
			Field::name( 'enabled' ),
			Field::id( self::NAME, 'enabled' ),
			! empty( $values['enabled'] ),
			__( 'Editors can mark an article as republished content and choose its source.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The "Outgoing channels" row: three check boxes, the last locked while the handling is a noindex.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_outgoing( array $values ): string {
		$locked = in_array( (string) ( $values['handling'] ?? '' ), array( RepublishedContent::NOINDEX, RepublishedContent::NEWS_NOINDEX ), true );
		$boxes  = '';

		foreach ( array( 'exclude_partner', 'exclude_outlets', 'exclude_news' ) as $field ) {
			$spec    = $this->fields()[ $field ];
			$id      = Field::id( self::NAME, $field );
			$checked = $locked && 'exclude_news' === $field ? true : ! empty( $values[ $field ] );
			$box     = Html::void(
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
						'type'     => 'checkbox',
						'id'       => $id,
						'name'     => Field::name( $field ),
						'value'    => '1',
						'checked'  => $checked,
						'disabled' => 'exclude_news' === $field && $locked,
					)
				) . Html::tag( 'span', array(), Html::tag( 'strong', array(), esc_html( (string) $spec['label'] ) ) )
			);

			if ( 'exclude_news' === $field ) {
				$box .= Html::tag(
					'p',
					array(
						'class'          => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-lock-note',
						'data-lock-note' => true,
						'hidden'         => ! $locked,
					),
					Icon::svg( 'lock' ) . Html::tag(
						'span',
						array(),
						esc_html__( 'Locked on while the handling is “Keep it out of search” or “Keep it out of Google News only”.', 'estasoft-news-distribution' )
					)
				);
			}

			$boxes .= Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $box );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $boxes )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'A noindex article is always skipped in the search sitemaps.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The "Attribution line" row: one input for each language the site publishes in.
	 *
	 * @param array<string, mixed>  $values The section's values.
	 * @param array<string, string> $errors Rejected fields.
	 * @return string Escaped HTML.
	 */
	public function render_attribution( array $values, array $errors = array() ): string {
		$lines = is_array( $values['attribution'] ?? null ) ? $values['attribution'] : array();
		$codes = Field::language_codes();

		foreach ( array_keys( $lines ) as $code ) {
			if ( ! in_array( (string) $code, $codes, true ) ) {
				$codes[] = (string) $code;
			}
		}

		if ( ! in_array( 'en', $codes, true ) ) {
			array_unshift( $codes, 'en' );
		}

		$html = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( 'attribution' ),
				'value' => '',
			)
		);

		foreach ( $codes as $code ) {
			$input_id = Field::id( self::NAME, 'attribution' ) . '-' . sanitize_key( $code );
			$html    .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-lang-field' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-lang' ), esc_html( Catalog::tag( $code ) ) )
				. Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-sr',
						'for'   => $input_id,
					),
					esc_html(
						sprintf(
							/* translators: %s: a language name such as English. */
							__( 'Attribution line in %s', 'estasoft-news-distribution' ),
							Catalog::name( $code )
						)
					)
				)
				. Html::void(
					'input',
					array(
						'class'        => 'estasoft-nd-field estasoft-nd-w-360',
						'type'         => 'text',
						'id'           => $input_id,
						'name'         => Field::name( 'attribution', $code ),
						'value'        => isset( $lines[ $code ] ) && is_scalar( $lines[ $code ] ) ? (string) $lines[ $code ] : '',
						'dir'          => Catalog::direction( $code ),
						'maxlength'    => 160,
						'autocomplete' => 'off',
					)
				)
			);
		}

		$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Shown at the end of the article. {source} becomes the source name chosen in the editor. Each article uses the line for its own language, and an empty line falls back to English.', 'estasoft-news-distribution' ) );

		foreach ( $errors as $path => $message ) {
			if ( 0 === strpos( (string) $path, 'attribution' ) ) {
				$html .= Html::tag(
					'p',
					array(
						'class' => 'estasoft-nd-field-error',
						'role'  => 'alert',
					),
					Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $message ) )
				);
			}
		}

		return $html;
	}

	/**
	 * The table of sources, with the Add a source button, a blank row for a browser without a script, and the delete dialog.
	 *
	 * Every row holds its three inputs, so a save posts every source. A script turns a row
	 * into text with an Edit button and back; without one the rows stay as inputs.
	 *
	 * @param array<string, mixed>  $values The section's values.
	 * @param array<string, string> $errors Rejected fields.
	 * @return string Escaped HTML.
	 */
	public function render_sources( array $values, array $errors = array() ): string {
		$sources = is_array( $values['sources'] ?? null ) ? array_values( $values['sources'] ) : array();
		$rows    = array();
		$choices = self::source_handling_choices();

		foreach ( $sources as $index => $row ) {
			$rows[] = $this->source_cells( (int) $index, (array) $row, $choices, false );
		}

		$next  = count( $sources );
		$blank = $this->source_cells( $next, array(), $choices, true );
		$html  = '';

		if ( array() === $rows ) {
			$html .= Html::tag(
				'p',
				array(
					'class'              => 'estasoft-nd-muted estasoft-nd-pad',
					'data-sources-empty' => true,
				),
				esc_html__( 'No source yet. Add one when you first use it.', 'estasoft-news-distribution' )
			);
		}

		$html .= Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( 'sources_posted' ),
				'value' => '1',
			)
		);

		$html .= Html::tag(
			'div',
			array(
				'class'           => 'estasoft-nd-sources',
				'data-sources'    => true,
				'data-next-index' => (string) $next,
			),
			Grid::render( 'estasoft-nd-cols-sources', array( __( 'Name', 'estasoft-news-distribution' ), __( 'Handling', 'estasoft-news-distribution' ), __( 'Address', 'estasoft-news-distribution' ) ), array_merge( $rows, array( $blank ) ), __( 'Sources you use', 'estasoft-news-distribution' ) )
		);

		foreach ( $errors as $path => $message ) {
			if ( 0 === strpos( (string) $path, 'sources' ) ) {
				$html .= Html::tag(
					'p',
					array(
						'class' => 'estasoft-nd-field-error estasoft-nd-pad',
						'role'  => 'alert',
					),
					Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $message ) )
				);
			}
		}

		$html .= Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-sources__foot' ),
			Button::button(
				__( 'Add a source', 'estasoft-news-distribution' ),
				array(
					'attrs' => array(
						'data-add-source' => true,
						'hidden'          => true,
					),
				)
			) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Delete asks you to confirm. Articles that already use a source keep its name.', 'estasoft-news-distribution' ) )
		);

		$html .= Dialog::render(
			'delete-source',
			__( 'Delete this source?', 'estasoft-news-distribution' ),
			__( 'It is removed from the list when you save. Articles that already use it keep its name.', 'estasoft-news-distribution' ),
			Button::button(
				__( 'Delete source', 'estasoft-news-distribution' ),
				array(
					'variant' => 'primary',
					'attrs'   => array(
						'data-confirm-delete-source' => true,
						'data-close-dialog'          => true,
					),
				)
			)
		);

		return $html;
	}

	/**
	 * The three cells of one source row.
	 *
	 * @param int                   $index   Position in the list.
	 * @param array<string, mixed>  $row     The source, or an empty array for a blank row.
	 * @param array<string, string> $choices Handling choices.
	 * @param bool                  $blank   Whether this is the blank row a script copies.
	 * @return array<int, string>
	 */
	private function source_cells( int $index, array $row, array $choices, bool $blank ): array {
		$id       = (string) ( $row['id'] ?? '' );
		$name     = (string) ( $row['name'] ?? '' );
		$handling = (string) ( $row['handling'] ?? '' );
		$site     = (string) ( $row['site'] ?? '' );
		$host     = '' === $site ? '' : (string) wp_parse_url( false === strpos( $site, '://' ) ? 'https://' . $site : $site, PHP_URL_HOST );
		$base     = 'estasoft-nd-source-' . $index;
		$mark     = array(
			'class'                => 'estasoft-nd-source__cell',
			'data-source-row-mark' => true,
			'data-source-id'       => $id,
			'data-source-blank'    => $blank ? 'true' : null,
		);

		$hidden_id = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( 'sources', (string) $index, 'id' ),
				'value' => $id,
			)
		);

		$name_view = Html::tag( 'strong', array( 'class' => 'estasoft-nd-source__view' ), esc_html( $name ) );
		$name_edit = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-source__edit' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => $base . '-name',
				),
				esc_html__( 'Source name', 'estasoft-news-distribution' )
			) . Html::void(
				'input',
				array(
					'class'     => 'estasoft-nd-field',
					'type'      => 'text',
					'id'        => $base . '-name',
					'name'      => Field::name( 'sources', (string) $index, 'name' ),
					'value'     => $name,
					'maxlength' => 80,
				)
			)
		);

		$actions = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8 estasoft-nd-source__actions' ),
			Html::tag(
				'a',
				array(
					'class'            => 'estasoft-nd-btn estasoft-nd-btn--sm',
					'href'             => '#' . $base . '-name',
					'data-edit-source' => true,
					'aria-label'       => sprintf(
						/* translators: %s: the name of a source. */
						__( 'Edit %s', 'estasoft-news-distribution' ),
						$name
					),
					'hidden'           => true,
				),
				esc_html__( 'Edit', 'estasoft-news-distribution' )
			) . Html::tag(
				'button',
				array(
					'class'              => 'estasoft-nd-btn estasoft-nd-btn--sm',
					'type'               => 'submit',
					'name'               => Field::name( 'delete_source' ),
					'value'              => $id,
					'data-open-dialog'   => 'estasoft-nd-dlg-delete-source',
					'data-delete-source' => true,
					'aria-label'         => sprintf(
						/* translators: %s: the name of a source. */
						__( 'Delete %s', 'estasoft-news-distribution' ),
						$name
					),
				),
				esc_html__( 'Delete', 'estasoft-news-distribution' )
			)
		);

		$select = '';

		foreach ( $choices as $value => $label ) {
			$select .= Html::tag(
				'option',
				array(
					'value'    => $value,
					'selected' => (string) $value === $handling,
				),
				esc_html( $label )
			);
		}

		$handling_view = Html::tag( 'span', array( 'class' => 'estasoft-nd-source__view' ), esc_html( $choices[ $handling ] ?? $choices[''] ) );
		$handling_edit = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-source__edit' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => $base . '-handling',
				),
				esc_html__( 'Handling', 'estasoft-news-distribution' )
			) . Html::tag(
				'select',
				array(
					'class' => 'estasoft-nd-field',
					'id'    => $base . '-handling',
					'name'  => Field::name( 'sources', (string) $index, 'handling' ),
				),
				$select
			)
		);

		$site_view = Html::tag( 'code', array( 'class' => 'estasoft-nd-source__view' ), esc_html( $host ) );
		$site_edit = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-source__edit' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => $base . '-site',
				),
				esc_html__( 'Address', 'estasoft-news-distribution' )
			) . Html::void(
				'input',
				array(
					'class'       => 'estasoft-nd-field',
					'type'        => 'text',
					'id'          => $base . '-site',
					'name'        => Field::name( 'sources', (string) $index, 'site' ),
					'value'       => $site,
					'maxlength'   => 200,
					'placeholder' => 'wire.example.com',
					'dir'         => 'ltr',
				)
			)
		);

		return array(
			Html::tag( 'div', $mark, $hidden_id . $name_view . $name_edit . $actions ),
			$handling_view . $handling_edit,
			$site_view . $site_edit,
		);
	}
}
