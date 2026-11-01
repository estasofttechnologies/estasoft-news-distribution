<?php
/**
 * The tags in the head of an article page that platforms read.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\SingleLanguageResolver;
use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Knows which tags there are, can read them out of a page's head, and can write
 * the ones a page lacks.
 *
 * It only adds. A tag that a theme or another plugin already prints is never
 * removed, replaced or edited here; the head is read as printed and what is
 * missing is added after it. The tags News Distribution adds carry the attribute
 * `data-estasoft-nd`, so the Page markup screen can tell its own tags from
 * the ones another plugin prints.
 *
 * Apple News reads the author, image and language from the page (author from
 * `meta name="author"` or `article:author`, picture from `og:image`, language
 * from `og:locale`); that is in the verify log. No platform document in the
 * verify log says that a platform reads `article:published_time` or
 * `article:modified_time`, so those two are for search engines and sharing and
 * the screen does not claim more.
 */
final class PageTags {

	/**
	 * The attribute that marks a tag News Distribution printed.
	 */
	public const MARKER = 'data-estasoft-nd';

	/**
	 * Tag ids, in the order the screen lists them, with the setting that turns each on.
	 *
	 * @return array<string, array{setting: string, label: string}>
	 */
	public static function definitions(): array {
		return array(
			'image'     => array(
				'setting' => 'tag_image',
				'label'   => 'og:image',
			),
			'author'    => array(
				'setting' => 'tag_author',
				'label'   => 'author',
			),
			'locale'    => array(
				'setting' => 'tag_locale',
				'label'   => 'og:locale',
			),
			'published' => array(
				'setting' => 'tag_published_time',
				'label'   => 'article:published_time',
			),
			'modified'  => array(
				'setting' => 'tag_modified_time',
				'label'   => 'article:modified_time',
			),
			'canonical' => array(
				'setting' => 'tag_canonical',
				'label'   => 'canonical',
			),
			'preview'   => array(
				'setting' => 'tag_max_image_preview',
				'label'   => 'max-image-preview',
			),
		);
	}

	private Plugin $plugin;

	/**
	 * Creates the helper.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether the person turned a tag on.
	 *
	 * @param string $id A key of definitions().
	 * @return bool
	 */
	public function enabled( string $id ): bool {
		$definitions = self::definitions();

		return isset( $definitions[ $id ] ) && (bool) $this->plugin->settings()->get( 'markup.' . $definitions[ $id ]['setting'], true );
	}

	/**
	 * Reads the tags out of a page.
	 *
	 * Meta and link tags are read from the head only. The structured data is read
	 * from everything given, because some themes print it in the body.
	 *
	 * Shape:
	 *
	 *     array(
	 *       'present'      => array<string, bool>, // by tag id: a tag with a value is on the page
	 *       'ours'         => array<string, bool>, // by tag id: the tag carries data-estasoft-nd
	 *       'article_data' => bool,                // some structured data names an article type
	 *       'ours_jsonld'  => bool,                // the structured data News Distribution prints is on the page
	 *     )
	 *
	 * @param string $html A page, or only its head.
	 * @return array<string, mixed>
	 */
	public function parse( string $html ): array {
		$ids     = array_keys( self::definitions() );
		$present = array_fill_keys( $ids, false );
		$ours    = array_fill_keys( $ids, false );
		$end     = stripos( $html, '</head>' );
		$head    = false === $end ? $html : substr( $html, 0, $end );

		$processor = new \WP_HTML_Tag_Processor( $head );

		while ( $processor->next_tag() ) {
			$tag = strtolower( (string) $processor->get_tag() );

			if ( 'meta' !== $tag && 'link' !== $tag ) {
				continue;
			}

			$id = $this->id_of( $processor, $tag );

			if ( '' === $id ) {
				continue;
			}

			$present[ $id ] = true;

			if ( null !== $processor->get_attribute( self::MARKER ) ) {
				$ours[ $id ] = true;
			}
		}

		$article_data = false;
		$ours_jsonld  = false;

		foreach ( $this->json_blocks( $html ) as $block ) {
			if ( self::has_article_type( $block['data'] ) ) {
				$article_data = true;
			}

			if ( $block['ours'] ) {
				$ours_jsonld = true;
			}
		}

		return array(
			'present'      => $present,
			'ours'         => $ours,
			'article_data' => $article_data,
			'ours_jsonld'  => $ours_jsonld,
		);
	}

	/**
	 * Whether structured data names an article type.
	 *
	 * Any type that ends in "Article" (NewsArticle, Article, TechArticle and the
	 * kinds of news article) or in "BlogPosting" counts, because two articles
	 * described for one page can disagree.
	 *
	 * @param mixed $data Decoded JSON-LD.
	 * @return bool
	 */
	public static function has_article_type( $data ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}

		if ( isset( $data['@type'] ) ) {
			foreach ( (array) $data['@type'] as $type ) {
				if ( is_string( $type ) && 1 === preg_match( '/(Article|BlogPosting)$/', $type ) ) {
					return true;
				}
			}
		}

		foreach ( $data as $child ) {
			if ( is_array( $child ) && self::has_article_type( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The tags to print for an article: the ones that are on, absent from the page,
	 * and that the article has a value for.
	 *
	 * @param Article             $article The article.
	 * @param array<string, bool> $present Tags already on the page, from parse().
	 * @return array<string, string> Tag id => HTML that is safe to print.
	 */
	public function missing( Article $article, array $present ): array {
		$out = array();

		foreach ( array_keys( self::definitions() ) as $id ) {
			if ( 'preview' === $id || ! $this->enabled( $id ) || ! empty( $present[ $id ] ) ) {
				continue;
			}

			$html = $this->html_for( $id, $article );

			if ( '' !== $html ) {
				$out[ $id ] = $html;
			}
		}

		return $out;
	}

	/**
	 * Whether the article has nothing to put in a tag, such as no featured image for og:image.
	 *
	 * @param string  $id      A key of definitions().
	 * @param Article $article The article.
	 * @return bool
	 */
	public function nothing_to_add( string $id, Article $article ): bool {
		return 'preview' !== $id && '' === $this->html_for( $id, $article );
	}

	/**
	 * One tag for an article.
	 *
	 * @param string  $id      A key of definitions().
	 * @param Article $article The article.
	 * @return string HTML, or an empty string when the article has no value for it.
	 */
	public function html_for( string $id, Article $article ): string {
		switch ( $id ) {
			case 'image':
				return $this->meta( 'property', 'og:image', $article->thumbnail_url(), true );

			case 'author':
				return $this->meta( 'name', 'author', $article->author_name(), false );

			case 'locale':
				return $this->meta( 'property', 'og:locale', $this->locale_of( $article->language() ), false );

			case 'published':
				return $this->meta( 'property', 'article:published_time', $article->published_ts() > 0 ? wp_date( 'c', $article->published_ts(), Clock::zone() ) : '', false );

			case 'modified':
				$modified = max( $article->published_ts(), $article->modified_ts() );

				return $this->meta( 'property', 'article:modified_time', $modified > 0 ? wp_date( 'c', $modified, Clock::zone() ) : '', false );

			case 'canonical':
				if ( '' === $article->permalink() ) {
					return '';
				}

				return '<link rel="canonical" href="' . esc_url( $article->permalink() ) . '" ' . self::MARKER . " />\n";
		}

		return '';
	}

	/**
	 * The locale an article's language goes by in `og:locale`, such as en_US.
	 *
	 * The translation plugin's own locale is used when it has one. Without it, the
	 * site's locale answers for the site's language, and other languages are given
	 * their usual locale or, when none is known, the bare language code.
	 *
	 * @param string $language Language code.
	 * @return string An empty string when no language is known.
	 */
	public function locale_of( string $language ): string {
		$code = strtolower( str_replace( '_', '-', trim( $language ) ) );

		if ( '' === $code ) {
			return '';
		}

		$locale = $this->plugin_locale( $code );

		if ( '' === $locale && SingleLanguageResolver::code_from_locale( (string) get_locale() ) === SingleLanguageResolver::code_from_locale( $code ) ) {
			$locale = (string) get_locale();
		}

		if ( '' === $locale ) {
			$locale = UiLanguages::locale_label( $code );
		}

		return str_replace( '-', '_', $locale );
	}

	/**
	 * What the translation plugin says about a language's locale.
	 *
	 * @param string $code Lower-case language code.
	 * @return string An empty string when no plugin knows it.
	 */
	private function plugin_locale( string $code ): string {
		return UiLanguages::plugin_locale( $code );
	}

	/**
	 * A meta tag, with the marker.
	 *
	 * @param string $attribute `property` or `name`.
	 * @param string $key       The tag's name.
	 * @param string $value     Its content.
	 * @param bool   $is_url    Whether the content is an address.
	 * @return string An empty string when there is no value.
	 */
	private function meta( string $attribute, string $key, string $value, bool $is_url ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		return '<meta ' . $attribute . '="' . esc_attr( $key ) . '" content="' . ( $is_url ? esc_url( $value ) : esc_attr( $value ) ) . '" ' . self::MARKER . " />\n";
	}

	/**
	 * Which tag id a meta or link tag is, or an empty string when none of ours.
	 *
	 * A tag counts only when it has a value.
	 *
	 * @param \WP_HTML_Tag_Processor $processor The processor, at a tag.
	 * @param string                 $tag       `meta` or `link`.
	 * @return string
	 */
	private function id_of( \WP_HTML_Tag_Processor $processor, string $tag ): string {
		if ( 'link' === $tag ) {
			$rel  = strtolower( (string) $this->attribute( $processor, 'rel' ) );
			$href = trim( (string) $this->attribute( $processor, 'href' ) );

			return in_array( 'canonical', preg_split( '/\s+/', $rel ), true ) && '' !== $href ? 'canonical' : '';
		}

		$key     = strtolower( trim( (string) $this->attribute( $processor, 'property' ) ) );
		$name    = strtolower( trim( (string) $this->attribute( $processor, 'name' ) ) );
		$content = trim( (string) $this->attribute( $processor, 'content' ) );

		if ( '' === $content ) {
			return '';
		}

		if ( 'og:image' === $key ) {
			return 'image';
		}

		if ( 'author' === $name || 'article:author' === $key ) {
			return 'author';
		}

		if ( 'og:locale' === $key ) {
			return 'locale';
		}

		if ( 'article:published_time' === $key ) {
			return 'published';
		}

		if ( 'article:modified_time' === $key ) {
			return 'modified';
		}

		if ( 'robots' === $name && false !== strpos( strtolower( $content ), 'max-image-preview' ) ) {
			return 'preview';
		}

		return '';
	}

	/**
	 * An attribute as a string.
	 *
	 * @param \WP_HTML_Tag_Processor $processor The processor, at a tag.
	 * @param string                 $name      The attribute.
	 * @return string An empty string when it is missing or has no value.
	 */
	private function attribute( \WP_HTML_Tag_Processor $processor, string $name ): string {
		$value = $processor->get_attribute( $name );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The JSON-LD blocks of a page.
	 *
	 * @param string $html A page or its head.
	 * @return array<int, array{data: mixed, ours: bool}>
	 */
	private function json_blocks( string $html ): array {
		$blocks = array();

		if ( ! preg_match_all( '#<script\b([^>]*)>(.*?)</script>#is', $html, $matches, PREG_SET_ORDER ) ) {
			return $blocks;
		}

		foreach ( $matches as $match ) {
			if ( ! preg_match( '#type\s*=\s*["\']?application/ld\+json#i', $match[1] ) ) {
				continue;
			}

			$data = json_decode( trim( html_entity_decode( $match[2], ENT_QUOTES ) ), true );

			if ( null === $data ) {
				continue;
			}

			$blocks[] = array(
				'data' => $data,
				'ours' => false !== strpos( $match[1], 'estasoft-nd-jsonld' ),
			);
		}

		return $blocks;
	}
}
