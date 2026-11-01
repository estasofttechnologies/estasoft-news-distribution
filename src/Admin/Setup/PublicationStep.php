<?php
/**
 * Step 1 of the setup: the publication's name, its languages and its post types.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Core\Settings\Sections\General;
use Estasoft\NewsDistribution\Core\Settings\Sections\Languages;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The name is one for all languages (a language can get its own in Settings later). The languages are the
 * ones the translation plugin has; the default language stays on. The post types are the ones News
 * Distribution reads articles from.
 */
final class PublicationStep {

	public const NAME_ID = 'estasoft-nd-setup-name';

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The screen.
	 *
	 * @param array{values: array<string, mixed>, errors: array<string, string>} $flash What a failed save left behind.
	 * @return string Escaped HTML.
	 */
	public function render( array $flash ): string {
		$card = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ),
			$this->name_row( $flash ) . $this->languages_row( $flash ) . $this->post_types_row( $flash )
		);

		$body = $this->intro() . Frame::form( 'publication', $card )
			. Frame::actions( 'publication', array( 'continue' => __( 'Continue', 'estasoft-news-distribution' ) ) );

		return Frame::page(
			'publication',
			__( 'Tell us about your publication', 'estasoft-news-distribution' ),
			__( 'Platforms usually show this name next to your articles.', 'estasoft-news-distribution' ),
			$body
		);
	}

	/**
	 * The name of the translation plugin as people say it, or an empty string.
	 *
	 * @return string
	 */
	private function plugin_label(): string {
		$name = $this->plugin->languages()->name();

		if ( 'polylang' === $name ) {
			return 'Polylang';
		}

		return 'wpml' === $name ? 'WPML' : '';
	}

	/**
	 * Where the translation plugin lists its languages, or an empty string.
	 *
	 * @return string
	 */
	private function plugin_url(): string {
		$name = $this->plugin->languages()->name();

		if ( 'polylang' === $name ) {
			return admin_url( 'admin.php?page=mlang' );
		}

		return 'wpml' === $name ? admin_url( 'admin.php?page=sitepress-multilingual-cms/menu/languages.php' ) : '';
	}

	/**
	 * The blue line above the form: what was found about the site's languages.
	 *
	 * @return string
	 */
	private function intro(): string {
		$label = $this->plugin_label();
		$site  = Languages::site_languages();

		if ( '' === $label || count( $site ) < 2 ) {
			return Message::text( 'info', __( 'Your site publishes in one language, so News Distribution builds one feed. To publish in more languages, add a translation plugin such as Polylang or WPML.', 'estasoft-news-distribution' ) );
		}

		$supported = UiLanguages::codes();
		$have      = 0;

		foreach ( $supported as $code ) {
			if ( '' !== $this->site_code( $code, $site ) ) {
				++$have;
			}
		}

		return Message::text(
			'info',
			sprintf(
				/* translators: 1: the translation plugin, such as Polylang. 2: how many languages the site has that News Distribution supports. 3: how many languages it supports in all. */
				__( 'We found %1$s with %2$d of the %3$d languages News Distribution supports. News Distribution builds one feed per language.', 'estasoft-news-distribution' ),
				$label,
				$have,
				count( $supported )
			)
		);
	}

	/**
	 * The site's code for a language, when the site has it. A plugin may call Kurdish "ku" or "ku-tr".
	 *
	 * @param string   $code A code from the list of supported languages.
	 * @param string[] $site The site's codes.
	 * @return string An empty string when the site does not have it.
	 */
	private function site_code( string $code, array $site ): string {
		if ( in_array( $code, $site, true ) ) {
			return $code;
		}

		foreach ( $site as $candidate ) {
			if ( Catalog::primary( $candidate ) === $code ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * A row of the card.
	 *
	 * @param string $label   The row's name (plain).
	 * @param string $control Trusted HTML of the controls.
	 * @param string $target   The id of the control the label names, or empty.
	 * @param string $label_id The id to give the label when it names a group of controls instead.
	 * @return string
	 */
	private function row( string $label, string $control, string $target = '', string $label_id = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-label' ),
				'' !== $target
					? Html::tag( 'label', array( 'for' => $target ), esc_html( $label ) )
					: Html::tag( 'span', array( 'id' => $label_id ), esc_html( $label ) )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $control )
		);
	}

	/**
	 * The error line under a control.
	 *
	 * @param string $id      The id the control points at with aria-describedby.
	 * @param string $message The message (plain), or empty.
	 * @return string
	 */
	private function error( string $id, string $message ): string {
		if ( '' === $message ) {
			return '';
		}

		return Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-field-error',
				'id'    => $id,
				'role'  => 'alert',
			),
			Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $message ) )
		);
	}

	/**
	 * The name.
	 *
	 * @param array{values: array<string, mixed>, errors: array<string, string>} $flash What a failed save left behind.
	 * @return string
	 */
	private function name_row( array $flash ): string {
		$settings = $this->plugin->settings();
		$name     = array_key_exists( 'publication_name', $flash['values'] )
			? (string) $flash['values']['publication_name']
			: (string) $settings->get( 'general.publication_name', '' );

		if ( '' === $name && array() === $flash['values'] ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$error = $flash['errors']['publication_name'] ?? '';
		$help  = 'estasoft-nd-setup-name-help';
		$alert = 'estasoft-nd-setup-name-error';

		$input = Html::void(
			'input',
			array(
				'type'             => 'text',
				'id'               => self::NAME_ID,
				'name'             => 'publication_name',
				'value'            => $name,
				'maxlength'        => 200,
				'class'            => Html::classes( 'estasoft-nd-field', 'estasoft-nd-w-360', '' !== $error ? 'has-error' : '' ),
				'autocomplete'     => 'organization',
				'aria-required'    => 'true',
				'aria-invalid'     => '' !== $error ? 'true' : null,
				'aria-describedby' => trim( $help . ( '' !== $error ? ' ' . $alert : '' ) ),
				'data-error'       => __( 'Enter a publication name.', 'estasoft-news-distribution' ),
			)
		);

		return $this->row(
			__( 'Publication name', 'estasoft-news-distribution' ),
			$input
			. $this->error( $alert, $error )
			. Html::tag(
				'p',
				array(
					'class' => 'estasoft-nd-muted estasoft-nd-small',
					'id'    => $help,
				),
				esc_html__( 'Required, up to 80 characters. It is used for every language; you can give each language its own name later in Settings. Google News expects the name it shows on your articles.', 'estasoft-news-distribution' )
			),
			self::NAME_ID
		);
	}

	/**
	 * The languages. Left out when the site has one.
	 *
	 * @param array{values: array<string, mixed>, errors: array<string, string>} $flash What a failed save left behind.
	 * @return string
	 */
	private function languages_row( array $flash ): string {
		$site = Languages::site_languages();

		if ( count( $site ) < 2 ) {
			return '';
		}

		$label   = $this->plugin_label();
		$off     = array_map( 'strval', (array) $this->plugin->settings()->get( 'languages.off', array() ) );
		$default = $this->default_code( $site );
		$posted  = isset( $flash['values']['languages'] ) ? array_map( 'strval', (array) $flash['values']['languages'] ) : null;
		$codes   = UiLanguages::codes();

		foreach ( $site as $code ) {
			if ( '' === $this->listed_as( $code, $codes ) ) {
				$codes[] = $code;
			}
		}

		$items = '';

		foreach ( $codes as $code ) {
			$have = $this->site_code( $code, $site );

			if ( '' === $have ) {
				$items .= $this->language_item( $code, '', false, false, '' !== $label ? $label : '' );
				continue;
			}

			$on = null === $posted ? ! in_array( $have, $off, true ) : in_array( $have, $posted, true );

			$items .= $this->language_item( $have, $have, $on || $have === $default, $have === $default, '' );
		}

		$help = sprintf(
			/* translators: 1: a language name such as English (US). */
			__( '%1$s is the default. Kurdish means Kurmanji; Sorani is not supported.', 'estasoft-news-distribution' ),
			'' !== $default ? UiLanguages::native_name( $default ) : ''
		);
		$url = $this->plugin_url();
		$add = '';

		if ( '' !== $label && '' !== $url ) {
			$link = Html::tag(
				'a',
				array(
					'href'   => $url,
					'target' => '_blank',
					'rel'    => 'noopener',
				),
				esc_html(
					sprintf(
						/* translators: %s: the translation plugin, such as Polylang. */
						__( 'open %s, Languages', 'estasoft-news-distribution' ),
						$label
					)
				) . Icon::svg( 'external-link' )
			);
			$add = ' ' . sprintf(
				/* translators: %s: a link such as "open Polylang, Languages". */
				esc_html__( 'To add a language, %s in a new tab, then reload this step.', 'estasoft-news-distribution' ),
				$link
			);
		}

		return $this->row(
			__( 'Languages', 'estasoft-news-distribution' ),
			Html::tag(
				'ul',
				array(
					'class'           => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-setup__langs',
					'aria-labelledby' => 'estasoft-nd-setup-label-languages',
				),
				$items
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $help ) . $add ),
			'',
			'estasoft-nd-setup-label-languages'
		);
	}

	/**
	 * Whether a site code is already covered by a code in the list.
	 *
	 * @param string   $site  A site code.
	 * @param string[] $codes The list.
	 * @return string The code in the list, or an empty string.
	 */
	private function listed_as( string $site, array $codes ): string {
		foreach ( $codes as $code ) {
			if ( $code === $site || Catalog::primary( $site ) === $code ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * The site's code for the default language.
	 *
	 * @param string[] $site The site's codes.
	 * @return string
	 */
	private function default_code( array $site ): string {
		$default = (string) $this->plugin->settings()->get( 'languages.default', '' );

		if ( '' === $default ) {
			$default = (string) $this->plugin->languages()->default_language();
		}

		foreach ( $site as $code ) {
			if ( $code === $default || Catalog::primary( $code ) === Catalog::primary( $default ) ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * One language of the list.
	 *
	 * @param string $code    The language code to show.
	 * @param string $value   The code to post, or empty when the site does not have the language.
	 * @param bool   $checked Whether it is on.
	 * @param bool   $fixed   Whether it is the default, which cannot be turned off.
	 * @param string $missing The translation plugin's name when the language is not set up in it.
	 * @return string
	 */
	private function language_item( string $code, string $value, bool $checked, bool $fixed, string $missing ): string {
		$id   = 'estasoft-nd-setup-lang-' . sanitize_key( $code );
		$note = '';

		if ( '' === $value ) {
			$note = Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					'' !== $missing
						/* translators: %s: the translation plugin, such as Polylang. */
						? sprintf( __( 'Not set up in %s', 'estasoft-news-distribution' ), $missing )
						: __( 'Not set up on this site', 'estasoft-news-distribution' )
				)
			);
		} elseif ( $fixed ) {
			$note = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Default, always on', 'estasoft-news-distribution' ) );
		}

		return Html::tag(
			'li',
			array(),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-check',
					'for'   => $id,
				),
				Html::void(
					'input',
					array(
						'type'     => 'checkbox',
						'id'       => $id,
						'name'     => 'languages[]',
						'value'    => '' !== $value ? $value : $code,
						'checked'  => $checked,
						'disabled' => '' === $value || $fixed,
					)
				)
				. Html::tag( 'span', array( 'lang' => Catalog::primary( $code ) ), esc_html( UiLanguages::native_name( $code ) ) )
				. Tag::lang( $code )
				. $note
			)
		);
	}

	/**
	 * The post types.
	 *
	 * @param array{values: array<string, mixed>, errors: array<string, string>} $flash What a failed save left behind.
	 * @return string
	 */
	private function post_types_row( array $flash ): string {
		$chosen = isset( $flash['values']['post_types'] )
			? array_map( 'strval', (array) $flash['values']['post_types'] )
			: $this->plugin->settings()->post_types();
		$items  = '';

		foreach ( General::post_type_options() as $slug => $name ) {
			$id     = 'estasoft-nd-setup-type-' . sanitize_key( $slug );
			$items .= Html::tag(
				'li',
				array(),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-check',
						'for'   => $id,
					),
					Html::void(
						'input',
						array(
							'type'    => 'checkbox',
							'id'      => $id,
							'name'    => 'post_types[]',
							'value'   => (string) $slug,
							'checked' => in_array( (string) $slug, $chosen, true ),
						)
					)
					. Html::tag( 'span', array(), esc_html( $name ) )
				)
			);
		}

		return $this->row(
			__( 'Post types', 'estasoft-news-distribution' ),
			Html::tag(
				'ul',
				array(
					'class'           => 'estasoft-nd-stack estasoft-nd-gap-8',
					'aria-labelledby' => 'estasoft-nd-setup-label-post-types',
				),
				$items
			) . $this->error( 'estasoft-nd-setup-types-error', $flash['errors']['post_types'] ?? '' ),
			'',
			'estasoft-nd-setup-label-post-types'
		);
	}
}
