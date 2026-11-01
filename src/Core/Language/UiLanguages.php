<?php
/**
 * The seven interface languages, with names in their own script.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Language;

defined( 'ABSPATH' ) || exit;

/**
 * News Distribution ships in English, Kurdish (Kurmanji), Arabic, Spanish, French,
 * Dutch and Hebrew. Only languages a translation plugin knows are site languages:
 * the others are interface translations only.
 *
 * Names are kept in the language's own script and are never translated, because a
 * person must be able to find their language whatever language the screen is in.
 *
 * The preview texts are sample sentences for the Languages overview. They show how
 * a screen reads in each language and are not about the site. Every text other than
 * English is a draft that a native reviewer has not read yet.
 */
final class UiLanguages {

	/**
	 * The seven codes, in the order the screens list them.
	 *
	 * @return string[]
	 */
	public static function codes(): array {
		return array( 'en', 'ku', 'es', 'fr', 'nl', 'ar', 'he' );
	}

	/**
	 * Whether a code is one of the seven interface languages.
	 *
	 * @param string $code A language code such as en or ku-Latn.
	 * @return bool
	 */
	public static function is_interface_language( string $code ): bool {
		return in_array( Catalog::primary( $code ), self::codes(), true );
	}

	/**
	 * A language's name in its own script, such as Español or العربية.
	 *
	 * @param string $code A language code.
	 * @return string The English name from the catalog when the own-script name is not known.
	 */
	public static function native_name( string $code ): string {
		$names = array(
			'en' => 'English (US)',
			'ku' => 'Kurdî (Kurmanji)',
			'es' => 'Español',
			'fr' => 'Français',
			'nl' => 'Nederlands',
			'ar' => 'العربية',
			'he' => 'עברית',
			'de' => 'Deutsch',
			'tr' => 'Türkçe',
			'fa' => 'فارسی',
			'ru' => 'Русский',
			'it' => 'Italiano',
			'pt' => 'Português',
			'pl' => 'Polski',
			'sv' => 'Svenska',
			'uk' => 'Українська',
			'id' => 'Bahasa Indonesia',
			'hi' => 'हिन्दी',
			'ur' => 'اردو',
			'zh' => '中文',
			'ja' => '日本語',
			'ko' => '한국어',
		);

		$primary = Catalog::primary( $code );

		return $names[ $primary ] ?? Catalog::name( $code );
	}

	/**
	 * The language's usual locale, as shown next to its name.
	 *
	 * @param string $code A language code.
	 * @return string For example en_US, or the code itself when no locale is listed.
	 */
	public static function locale_label( string $code ): string {
		$locales = array(
			'en' => 'en_US',
			'es' => 'es_ES',
			'fr' => 'fr_FR',
			'nl' => 'nl_NL',
			'he' => 'he_IL',
		);

		$primary = Catalog::primary( $code );

		return $locales[ $primary ] ?? $primary;
	}

	/**
	 * What the translation plugin says about a language's locale, such as en_US.
	 *
	 * Reads Polylang's `pll_languages_list` and WPML's `wpml_active_languages`; the
	 * plugins are only read, never changed.
	 *
	 * @param string $code A language code.
	 * @return string An empty string when no translation plugin knows the language.
	 */
	public static function plugin_locale( string $code ): string {
		$code = strtolower( str_replace( '_', '-', trim( $code ) ) );

		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs   = array_map( 'strtolower', array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'slug' ) ) ) );
			$locales = array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'locale' ) ) );
			$at      = array_search( $code, $slugs, true );

			if ( false !== $at && isset( $locales[ $at ] ) ) {
				return $locales[ $at ];
			}
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

			if ( is_array( $languages ) && isset( $languages[ $code ]['default_locale'] ) ) {
				return (string) $languages[ $code ]['default_locale'];
			}
		}

		return '';
	}

	/**
	 * Whether a language the site has as Kurdish looks like Sorani instead of Kurmanji.
	 *
	 * Sorani (code ckb) is written in Arabic script and is not supported. A language
	 * counts as Sorani when its code is ckb, or when it is `ku` and the translation
	 * plugin gives it a ckb locale or marks it as written right to left (Kurmanji is
	 * written left to right, in Latin letters).
	 *
	 * @param string[] $codes The site's language codes.
	 * @return string The code that looks like Sorani, or an empty string.
	 */
	public static function sorani_like( array $codes ): string {
		foreach ( $codes as $code ) {
			$primary = Catalog::primary( (string) $code );

			if ( 'ckb' === $primary ) {
				return (string) $code;
			}

			if ( 'ku' !== $primary ) {
				continue;
			}

			if ( 0 === strpos( strtolower( self::plugin_locale( (string) $code ) ), 'ckb' ) || self::polylang_is_rtl( (string) $code ) ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * Whether Polylang marks a language as written right to left.
	 *
	 * @param string $code A language code.
	 * @return bool
	 */
	private static function polylang_is_rtl( string $code ): bool {
		if ( ! function_exists( 'PLL' ) ) {
			return false;
		}

		$polylang = PLL();

		if ( ! is_object( $polylang ) || ! isset( $polylang->model ) || ! is_object( $polylang->model ) || ! method_exists( $polylang->model, 'get_language' ) ) {
			return false;
		}

		$language = $polylang->model->get_language( $code );

		return is_object( $language ) && ! empty( $language->is_rtl );
	}

	/**
	 * The line under a language's name: locale, direction and, for Kurdish, the script.
	 *
	 * @param string $code       A language code.
	 * @param bool   $is_default Whether it is the site's default language.
	 * @return string
	 */
	public static function meta_line( string $code, bool $is_default = false ): string {
		$primary = Catalog::primary( $code );
		$rtl     = 'rtl' === Catalog::direction( $code );
		$parts   = array( self::locale_label( $code ) );

		if ( 'ku' === $primary ) {
			$parts[] = __( 'locale kmr', 'estasoft-news-distribution' );
			$parts[] = __( 'Latin letters', 'estasoft-news-distribution' );
		}

		$parts[] = $rtl ? __( 'right to left', 'estasoft-news-distribution' ) : __( 'left to right', 'estasoft-news-distribution' );

		$line = implode( ' · ', $parts );

		if ( 'ku' === $primary ) {
			$line .= '. ' . __( 'Technical name: ku-Latn', 'estasoft-news-distribution' );
		}

		if ( $is_default ) {
			$line .= ' · ' . __( 'default language', 'estasoft-news-distribution' );
		}

		return $line;
	}

	/**
	 * The sample screen shown for one interface language on the Languages overview.
	 *
	 * @param string $code One of codes().
	 * @return array{tabs: string[], title: string, bar_title: string, bar_text: string, button: string}
	 */
	public static function preview( string $code ): array {
		$previews = array(
			'en' => array(
				'tabs'      => array( 'Overview', 'Channels', 'Article check', 'Reports', 'Delivery log', 'Settings' ),
				'title'     => 'Overview',
				'bar_title' => 'Microsoft Start will skip 3 articles',
				'bar_text'  => 'No featured image. It needs one thumbnail on every item.',
				'button'    => 'Review articles',
			),
			'ku' => array(
				'tabs'      => array( 'Nêrîna giştî', 'Kanalan', 'Kontrola gotaran', 'Raporan', 'Tomara şandinê', 'Mîheng' ),
				'title'     => 'Nêrîna giştî',
				'bar_title' => 'Microsoft Start dê 3 gotaran derbas bike',
				'bar_text'  => 'Wêneyê sereke tune ye. Ji bo her babetê pêdivî bi wêneyekî biçûk heye.',
				'button'    => 'Gotaran kontrol bike',
			),
			'es' => array(
				'tabs'      => array( 'Resumen', 'Canales', 'Revisión de artículos', 'Informes', 'Registro de envíos', 'Ajustes' ),
				'title'     => 'Resumen',
				'bar_title' => 'Microsoft Start omitirá 3 artículos',
				'bar_text'  => 'No hay imagen destacada. Necesita una miniatura en cada elemento.',
				'button'    => 'Revisar artículos',
			),
			'fr' => array(
				'tabs'      => array( 'Vue d’ensemble', 'Canaux', 'Vérification des articles', 'Rapports', 'Journal des envois', 'Réglages' ),
				'title'     => 'Vue d’ensemble',
				'bar_title' => 'Microsoft Start ignorera 3 articles',
				'bar_text'  => 'Aucune image à la une. Chaque élément doit avoir une miniature.',
				'button'    => 'Examiner les articles',
			),
			'nl' => array(
				'tabs'      => array( 'Overzicht', 'Kanalen', 'Artikelcontrole', 'Rapporten', 'Verzendlogboek', 'Instellingen' ),
				'title'     => 'Overzicht',
				'bar_title' => 'Microsoft Start slaat 3 artikelen over',
				'bar_text'  => 'Geen uitgelichte afbeelding. Elk item heeft één miniatuur nodig.',
				'button'    => 'Artikelen bekijken',
			),
			'ar' => array(
				'tabs'      => array( 'نظرة عامة', 'القنوات', 'فحص المقالات', 'التقارير', 'سجل التسليم', 'الإعدادات' ),
				'title'     => 'نظرة عامة',
				'bar_title' => 'ستتخطى Microsoft Start 3 مقالات',
				'bar_text'  => 'لا توجد صورة بارزة. تحتاج إلى صورة مصغّرة واحدة في كل عنصر.',
				'button'    => 'مراجعة المقالات',
			),
			'he' => array(
				'tabs'      => array( 'סקירה כללית', 'ערוצים', 'בדיקת מאמרים', 'דוחות', 'יומן שליחה', 'הגדרות' ),
				'title'     => 'סקירה כללית',
				'bar_title' => 'Microsoft Start ידלג על 3 מאמרים',
				'bar_text'  => 'אין תמונה ראשית. נדרשת תמונה ממוזערת אחת בכל פריט.',
				'button'    => 'סקירת מאמרים',
			),
		);

		return $previews[ Catalog::primary( $code ) ] ?? $previews['en'];
	}
}
