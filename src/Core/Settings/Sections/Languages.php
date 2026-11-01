<?php
/**
 * Settings, Languages.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Logo;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Which content languages News Distribution builds feeds for, and which one is the
 * default.
 *
 * Stored choices:
 *
 * - `off`: the codes of the languages turned off. The screen shows switches that are
 *   on, so a form posts `on[]` (the switches that are on) and `shown[]` (the
 *   switches it printed) and prepare_input() turns that into `off`. A script that
 *   saves through REST sends `off` itself. A language turned off has no feed and no
 *   news sitemap (their addresses answer 410), its waiting pushes are dropped, and
 *   `AbstractChannel::accepts_language()` reads this list.
 * - `default`: the language that answers when an article's language is not known and
 *   that the fallback language link (x-default) points to. Empty follows the
 *   translation plugin.
 *
 * A language the translation plugin does not have cannot be turned on. The
 * default language cannot be turned off.
 *
 * The seven interface languages (English, Kurdish in Kurmanji, Spanish, French,
 * Dutch, Arabic and Hebrew) are listed even when the site does not have them, so
 * the screen says so. The overview (`&view=overview`) shows the interface in each of them.
 */
final class Languages extends AbstractSiteSection {

	public const NAME = 'languages';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'off'     => array(
				'type'      => 'list',
				'label'     => __( 'Languages turned off', 'estasoft-news-distribution' ),
				'default'   => array(),
				'max_items' => 100,
				'item'      => array(
					'type'   => 'string',
					'format' => 'text',
					'max'    => 20,
				),
			),
			'default' => array(
				'type'    => 'enum',
				'label'   => __( 'Default language', 'estasoft-news-distribution' ),
				'default' => '',
				'control' => 'select',
				'width'   => 320,
				'options' => array( self::class, 'language_options' ),
				'help'    => __( 'Used for the fallback language link (technical name: x-default), and when an article’s language is not known.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The languages the site has, for the default language list.
	 *
	 * @return array<string, string> Code => name in its own script.
	 */
	public static function language_options(): array {
		$options = array();

		foreach ( self::site_languages() as $code ) {
			$options[ $code ] = UiLanguages::native_name( $code );
		}

		return $options;
	}

	/**
	 * The codes of the languages the site has.
	 *
	 * @return string[]
	 */
	public static function site_languages(): array {
		return array_values( array_unique( array_filter( array_map( 'strval', Plugin::instance()->languages()->languages() ) ) ) );
	}

	public function title(): string {
		return __( 'Languages', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'site';
	}

	public function order(): int {
		return 20;
	}

	/**
	 * Turns the switches of the form into the list of languages that are off.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		if ( ! array_key_exists( 'shown', $input ) ) {
			return $input;
		}

		$shown = array_map( 'strval', (array) $input['shown'] );
		$on    = array_map( 'strval', (array) ( $input['on'] ?? array() ) );
		$off   = array_map( 'strval', (array) Plugin::instance()->settings()->get( 'languages.off', array() ) );
		$off   = array_values( array_diff( $off, $shown ) );

		foreach ( $shown as $code ) {
			if ( ! in_array( $code, $on, true ) ) {
				$off[] = $code;
			}
		}

		unset( $input['on'], $input['shown'] );

		$input['off'] = array_values( array_unique( $off ) );

		return $input;
	}

	/**
	 * The default language cannot be turned off.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		foreach ( (array) ( $values['off'] ?? array() ) as $code ) {
			// A language code or a slug from the translation plugin: letters, numbers, dashes and underscores.
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_-]{0,19}$/i', (string) $code ) ) {
				return array(
					'off' => sprintf(
						/* translators: 1: the name of the setting, 2: the text that was sent. */
						__( '%1$s lists “%2$s”, which is not a language code. Use a code such as en or ku.', 'estasoft-news-distribution' ),
						__( 'Languages turned off', 'estasoft-news-distribution' ),
						(string) $code
					),
				);
			}
		}

		$default = (string) ( $values['default'] ?? '' );

		if ( '' === $default ) {
			$default = (string) Plugin::instance()->languages()->default_language();
		}

		if ( '' !== $default && in_array( $default, (array) ( $values['off'] ?? array() ), true ) ) {
			return array(
				'off' => sprintf(
					/* translators: %s: a language name such as English (US). */
					__( '%s is the default language, so it cannot be turned off. Choose another default language first.', 'estasoft-news-distribution' ),
					UiLanguages::native_name( $default )
				),
			);
		}

		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function layout(): array {
		$plugin = $this->plugin_label();

		return array(
			'cards'  => array(
				array(
					'id'          => 'languages',
					'title'       => __( 'Languages', 'estasoft-news-distribution' ),
					'description' => __( 'News Distribution builds one feed and one news sitemap for each language you turn on.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Languages', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_list' ),
						),
						array( 'field' => 'default' ),
						array(
							'label' => __( 'News Distribution screens', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_screens' ),
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'If you turn a language off', 'estasoft-news-distribution' ),
					'html'  => self::icon_list(
						array(
							__( 'Its feed and news sitemap stop being built. Their addresses answer “gone” (410), so platforms stop asking for them', 'estasoft-news-distribution' ),
							__( 'Articles in that language stay on your site. Nothing already sent to a platform is taken back', 'estasoft-news-distribution' ),
							__( 'Pushes still waiting for that language are dropped and show as Off in the delivery log', 'estasoft-news-distribution' ),
							__( 'You can undo it: turn the language on again and the same addresses work again', 'estasoft-news-distribution' ),
						),
						'info',
						'neutral'
					),
				),
			),
			'cannot' => array(
				sprintf(
					/* translators: %s: the name of the translation plugin, or "your translation plugin". */
					__( 'Add a language to %s. Add it there first, then turn it on here', 'estasoft-news-distribution' ),
					'' !== $plugin ? $plugin : __( 'your translation plugin', 'estasoft-news-distribution' )
				),
				__( 'Translate your articles. The Translations report shows what is missing', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * A line before the notes: Kurdish means Kurmanji, and a warning when the site's Kurdish looks like Sorani.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string
	 */
	public function render_extra( array $values ): string {
		$html   = '';
		$sorani = UiLanguages::sorani_like( self::site_languages() );

		if ( '' !== $sorani ) {
			$html .= Message::bar(
				'warning',
				Html::tag( 'strong', array(), esc_html__( 'Your Kurdish language looks like Sorani.', 'estasoft-news-distribution' ) )
				. ' ' . esc_html(
					sprintf(
						/* translators: %s: a language code such as ku. */
						__( 'Your translation plugin gives “%s” a Sorani locale or writes it right to left. News Distribution supports Kurmanji, written in Latin letters, so its feed and sitemap would carry the wrong language. Check that language in your translation plugin.', 'estasoft-news-distribution' ),
						$sorani
					)
				)
			);
		}

		return $html . Message::bar(
			'info',
			Html::tag( 'strong', array(), esc_html__( 'Kurdish means Kurmanji.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'Sorani, which is written in Arabic script, is not supported.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The name of the translation plugin as people say it, or an empty string.
	 *
	 * @return string
	 */
	private function plugin_label(): string {
		$name = Plugin::instance()->languages()->name();

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
		$name = Plugin::instance()->languages()->name();

		if ( 'polylang' === $name ) {
			return admin_url( 'admin.php?page=mlang' );
		}

		return 'wpml' === $name ? admin_url( 'admin.php?page=sitepress-multilingual-cms/menu/languages.php' ) : '';
	}

	/**
	 * The codes to list: the seven interface languages, then any other language the site has.
	 *
	 * @return string[]
	 */
	private function listed(): array {
		$codes = UiLanguages::codes();

		foreach ( self::site_languages() as $code ) {
			if ( ! in_array( Catalog::primary( $code ), $codes, true ) && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * The site's code for a language, when the site has it.
	 *
	 * The translation plugin may call Kurdish "ku" or "ku-tr"; the list is by primary code.
	 *
	 * @param string   $code  A listed code.
	 * @param string[] $site  The site's codes.
	 * @return string An empty string when the site does not have it.
	 */
	private function site_code( string $code, array $site ): string {
		if ( in_array( $code, $site, true ) ) {
			return $code;
		}

		foreach ( $site as $candidate ) {
			if ( Catalog::primary( $candidate ) === Catalog::primary( $code ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * The list of languages with a switch each.
	 *
	 * @param array<string, mixed>  $values The values the screen shows.
	 * @param array<string, string> $errors Rejected fields and their messages.
	 * @return string Escaped HTML.
	 */
	public function render_list( array $values = array(), array $errors = array() ): string {
		$plugin  = $this->plugin_label();
		$url     = $this->plugin_url();
		$site    = self::site_languages();
		$default = (string) Plugin::instance()->languages()->default_language();
		$off     = array_map( 'strval', (array) ( $values['off'] ?? array() ) );
		$rows    = '';

		// After a rejected save the form's own switches are shown again, not the stored list.
		if ( isset( $values['shown'] ) ) {
			$on  = array_map( 'strval', (array) ( $values['on'] ?? array() ) );
			$off = array_values( array_diff( array_map( 'strval', (array) $values['shown'] ), $on ) );
		}

		foreach ( $this->listed() as $listed ) {
			$code       = $this->site_code( $listed, $site );
			$found      = '' !== $code;
			$key        = $found ? $code : $listed;
			$is_default = $found && $code === $default;
			$is_on      = $found && ! in_array( $code, $off, true );
			$id         = 'estasoft-nd-lang-' . sanitize_html_class( $key );

			$hidden = '';

			if ( $found && ! $is_default ) {
				$hidden = Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => 'estasoft_nd[shown][]',
						'value' => $code,
					)
				);
			}

			$toggle = Html::void(
				'input',
				array(
					'type'     => 'checkbox',
					'class'    => 'estasoft-nd-toggle',
					'id'       => $id,
					'name'     => 'estasoft_nd[on][]',
					'value'    => $key,
					'checked'  => $is_on,
					'disabled' => ! $found || $is_default,
				)
			);

			$help = '';

			if ( ! $found ) {
				$help = Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						'' !== $plugin
							? sprintf(
								/* translators: %s: the name of the translation plugin, such as Polylang. */
								__( 'Cannot be turned on yet. Add it in %s first.', 'estasoft-news-distribution' ),
								$plugin
							)
							: __( 'Cannot be turned on yet. Add it in a translation plugin first. News Distribution reads Polylang and WPML.', 'estasoft-news-distribution' )
					)
					. ( '' !== $url ? ' <a href="' . esc_url( $url ) . '">' . esc_html(
						sprintf(
							/* translators: %s: the name of the translation plugin, such as Polylang. */
							__( 'Open %s', 'estasoft-news-distribution' ),
							$plugin
						)
					) . '</a>' : '' )
				);
			} elseif ( ! $is_on ) {
				$help = Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html__( 'Turned off. Its feed and news sitemap answer “gone” (410).', 'estasoft-news-distribution' )
				);
			}

			$text = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-langrow__text' ),
				Html::tag(
					'label',
					array(
						'for'   => $id,
						'class' => 'estasoft-nd-langrow__label',
					),
					Html::tag(
						'span',
						array(
							'class' => 'estasoft-nd-strong estasoft-nd-langrow__name',
							'lang'  => Catalog::primary( $key ),
						),
						esc_html( UiLanguages::native_name( $key ) )
					)
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( UiLanguages::meta_line( $key, $is_default ) ) )
				)
				. $help
			);

			$pill = $found
				? Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-badge estasoft-nd-badge--success' ),
					Icon::svg( 'check-circle' ) . esc_html(
						'' !== $plugin ? sprintf(
						/* translators: %s: the name of the translation plugin, such as Polylang. */
							__( 'Found in %s', 'estasoft-news-distribution' ),
							$plugin
						) : __( 'Site language', 'estasoft-news-distribution' )
					)
				)
				: Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-badge estasoft-nd-badge--neutral' ),
					Icon::svg( 'minus-circle' ) . esc_html(
						'' !== $plugin ? sprintf(
						/* translators: %s: the name of the translation plugin, such as Polylang. */
							__( 'Not set up in %s', 'estasoft-news-distribution' ),
							$plugin
						) : __( 'Not set up', 'estasoft-news-distribution' )
					)
				);

			$rows .= Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-langrow',
					'role'  => 'listitem',
				),
				$hidden . $toggle . $text . Tag::lang( $key ) . $pill
			);
		}

		$error = isset( $errors['off'] ) ? (string) $errors['off'] : '';

		return Html::tag(
			'div',
			array(
				'class'      => 'estasoft-nd-langlist',
				'role'       => 'list',
				'aria-label' => __( 'Languages', 'estasoft-news-distribution' ),
			),
			$rows
		) . Html::tag(
			'p',
			array(
				'class'            => 'estasoft-nd-field-error estasoft-nd-langlist__error',
				'role'             => 'alert',
				'data-field-error' => 'off',
				'hidden'           => '' === $error,
			),
			'' === $error ? '' : Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $error ) )
		);
	}

	/**
	 * The "News Distribution screens" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_screens(): string {
		return Html::tag(
			'p',
			array(),
			esc_html__( 'Each person sees News Distribution in their own WordPress language, or English (US) when that language is not one of the seven.', 'estasoft-news-distribution' )
		) . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-strong' ),
			'<a href="' . esc_url( get_edit_profile_url() ) . '">' . esc_html__( 'Change your own language in your profile', 'estasoft-news-distribution' ) . '</a>'
		);
	}

	/**
	 * The overview of the interface in seven languages.
	 *
	 * @param string $tab The `view` or `tab` argument.
	 * @return string|null
	 */
	public function render_tab( string $tab ): ?string {
		if ( 'overview' !== $tab ) {
			return null;
		}

		$site  = self::site_languages();
		$first = array();
		$rest  = array();

		foreach ( UiLanguages::codes() as $code ) {
			if ( '' !== $this->site_code( $code, $site ) ) {
				$first[] = $code;
			} else {
				$rest[] = $code;
			}
		}

		$cards = '';

		foreach ( array_merge( $first, $rest ) as $code ) {
			$cards .= $this->preview_card( $code, in_array( $code, $first, true ) );
		}

		$crumbs = Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-crumbs',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			'<a href="' . esc_url( self::url_of( 'general' ) ) . '">' . esc_html__( 'Settings', 'estasoft-news-distribution' ) . '</a> / <a href="' . esc_url( self::url_of( self::NAME ) ) . '">' . esc_html__( 'Languages', 'estasoft-news-distribution' ) . '</a>'
		);

		return $crumbs
			. PageHeader::render(
				__( 'News Distribution in seven languages', 'estasoft-news-distribution' ),
				__( 'Each card shows the same screen in one interface language. English (US) is the default and the fallback. Arabic and Hebrew mirror the layout; the other five read left to right.', 'estasoft-news-distribution' )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-langgrid' ), $cards . $this->how_chosen( $site ) );
	}

	/**
	 * One card of the overview.
	 *
	 * @param string $code    One of the seven codes.
	 * @param bool   $is_site Whether the site has this language.
	 * @return string
	 */
	private function preview_card( string $code, bool $is_site ): string {
		$preview = UiLanguages::preview( $code );
		$dir     = Catalog::direction( $code );
		$id      = 'estasoft-nd-langcard-' . $code;
		$tabs    = '';

		foreach ( $preview['tabs'] as $index => $label ) {
			$tabs .= Html::tag(
				'li',
				array( 'class' => 0 === $index ? 'is-current' : '' ),
				esc_html( $label )
			);
		}

		$logo = Logo::mark();

		$screen = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-langprev',
				'lang'  => $code,
				'dir'   => $dir,
			),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-langprev__brand' ), $logo . esc_html( 'Estasoft News Distribution' ) )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-langprev__tabs' ), $tabs )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-langprev__title' ), esc_html( $preview['title'] ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-langprev__bar' ),
				Icon::svg( 'warning' )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-langprev__bartext' ),
					Html::tag( 'strong', array(), esc_html( $preview['bar_title'] ) ) . Html::tag( 'span', array(), esc_html( $preview['bar_text'] ) )
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-btn estasoft-nd-btn--sm' ), esc_html( $preview['button'] ) )
			)
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-langcard',
				'aria-labelledby' => $id,
			),
			Html::tag(
				'header',
				array( 'class' => 'estasoft-nd-langcard__head' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => $id,
						'lang'  => $code,
					),
					esc_html( UiLanguages::native_name( $code ) )
				)
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Tag::lang( $code )
					. Tag::plain( 'rtl' === $dir ? __( 'Right to left', 'estasoft-news-distribution' ) : __( 'Left to right', 'estasoft-news-distribution' ) )
					. Tag::plain( $is_site ? __( 'Site language', 'estasoft-news-distribution' ) : __( 'Interface only', 'estasoft-news-distribution' ) )
				)
			) . $screen
		);
	}

	/**
	 * The "How the language is chosen" card.
	 *
	 * @param string[] $site The site's language codes.
	 * @return string
	 */
	private function how_chosen( array $site ): string {
		$plugin  = $this->plugin_label();
		$names   = array();
		$only    = array();
		$site_of = array();

		foreach ( UiLanguages::codes() as $code ) {
			if ( '' !== $this->site_code( $code, $site ) ) {
				$site_of[] = $code;
			} else {
				$only[] = UiLanguages::native_name( $code );
			}
		}

		foreach ( $site as $code ) {
			$names[] = UiLanguages::native_name( $code );
		}

		$second = '' !== $plugin
			? sprintf(
				/* translators: 1: the translation plugin, such as Polylang. 2: the site's name. 3: the site's languages, as a list. */
				__( 'Site languages are different. They are the languages your articles are written in and come from %1$s: for %2$s, %3$s.', 'estasoft-news-distribution' ),
				$plugin,
				get_bloginfo( 'name' ),
				wp_sprintf( '%l', $names )
			)
			: sprintf(
				/* translators: 1: the site's name. 2: the site's languages, as a list. */
				__( 'Site languages are different. They are the languages your articles are written in. No translation plugin is active, so %1$s has one: %2$s.', 'estasoft-news-distribution' ),
				get_bloginfo( 'name' ),
				wp_sprintf( '%l', $names )
			);

		if ( array() !== $only ) {
			$second .= ' ' . sprintf(
				/* translators: 1: the interface-only languages, as a list. 2: where the status is shown. */
				__( '%1$s are interface translations only, so Settings, Languages shows them as %2$s.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $only ),
				'' !== $plugin ? sprintf(
					/* translators: %s: the name of the translation plugin, such as Polylang. */
					__( 'Not set up in %s', 'estasoft-news-distribution' ),
					$plugin
				) : __( 'Not set up', 'estasoft-news-distribution' )
			);
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--note',
				'aria-labelledby' => 'estasoft-nd-how-chosen',
			),
			Html::tag(
				'h2',
				array(
					'id'    => 'estasoft-nd-how-chosen',
					'class' => 'estasoft-nd-title',
				),
				esc_html__( 'How the language is chosen', 'estasoft-news-distribution' )
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Each person sees News Distribution in their own WordPress language, or English (US) when that language is not one of the seven. A person changes it in their profile.', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $second ) )
			. self::icon_list(
				array( __( 'Choosing an interface language does not translate articles or add a site language.', 'estasoft-news-distribution' ) ),
				'minus-circle',
				'neutral'
			)
		);
	}
}
