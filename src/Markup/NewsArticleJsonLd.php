<?php
/**
 * The NewsArticle structured data of an article page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

use Estasoft\NewsDistribution\Checks\CreditCheck;
use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the JSON-LD that describes one article, and encodes it for a page.
 *
 * What it prints is what the article already has: its headline, dates, author,
 * language, the publication and the featured image with its caption, credit and rights note.
 * It prints nothing that the article does not have, and it never prints
 * `news_keywords`, because no platform document confirms that tag.
 *
 * Paywall markup (`isAccessibleForFree` and `hasPart` with a `cssSelector`) is
 * added only when the setting is on and the article is set to Subscribers only,
 * Registration required or Metered in the editor. The editor keeps that choice
 * in the article's `_estasoft_nd_options` under `access`; the internal filter
 * `estasoft_nd_internal_article_access` lets the editor feature answer another way.
 *
 * The output is escaped for a script element: `<`, `>` and `&` become \u003C,
 * \u003E and \u0026, so an article title can never close the script tag.
 */
final class NewsArticleJsonLd {

	/**
	 * The article's own options: the key of the editor's access choice.
	 */
	private const OPTIONS = '_estasoft_nd_options';

	/**
	 * Flags for `wp_json_encode`: safe inside a script element, readable for people.
	 */
	public const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Access choices that put part of the article behind a limit.
	 */
	public const PAYWALLED = array( 'subscribers', 'registration', 'metered' );

	private Plugin $plugin;

	/**
	 * Creates the builder.
	 *
	 * @param Plugin $plugin The plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The structured data of an article.
	 *
	 * @param Article $article The article.
	 * @return array<string, mixed>
	 */
	public function build( Article $article ): array {
		$language = $article->language();
		$data     = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'NewsArticle',
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => $article->permalink(),
			),
			'headline'         => $article->title(),
		);

		$published = $article->published_ts();
		$modified  = max( $published, $article->modified_ts() );

		if ( $published > 0 ) {
			$data['datePublished'] = wp_date( 'c', $published, Clock::zone() );
			$data['dateModified']  = wp_date( 'c', $modified, Clock::zone() );
		}

		if ( '' !== $article->author_name() ) {
			$data['author'] = array_filter(
				array(
					'@type' => 'Person',
					'name'  => $article->author_name(),
					'url'   => $article->author_url(),
				),
				static function ( $value ): bool {
					return '' !== $value;
				}
			);
		}

		$publisher = $this->plugin->settings()->publication_name( $language );

		if ( '' !== $publisher ) {
			$data['publisher'] = array(
				'@type' => 'Organization',
				'name'  => $publisher,
			);
		}

		$image = $this->image( $article );

		if ( array() !== $image ) {
			$data['image'] = $image;
		}

		if ( '' !== $language ) {
			$data['inLanguage'] = str_replace( '_', '-', $language );
		}

		$paywall = $this->paywall_for( $article->id() );

		if ( array() !== $paywall ) {
			$data = array_merge( $data, $paywall );
		}

		return $data;
	}

	/**
	 * The JSON text of an article's structured data, safe inside a script element.
	 *
	 * @param Article $article The article.
	 * @return string An empty string when the data cannot be encoded.
	 */
	public function json( Article $article ): string {
		return $this->encode( $this->build( $article ) );
	}

	/**
	 * Encodes data for a script element.
	 *
	 * @param array<string, mixed> $data The data.
	 * @return string An empty string when the data cannot be encoded.
	 */
	public function encode( array $data ): string {
		$json = wp_json_encode( $data, self::FLAGS );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Data laid out for a person to read, two spaces to a level, as the Page markup screen shows it.
	 *
	 * It is for display only. The page gets the compact text from json().
	 *
	 * @param array<string, mixed> $data The data.
	 * @return string An empty string when the data cannot be encoded.
	 */
	public static function pretty( array $data ): string {
		$json = wp_json_encode( $data, self::FLAGS | JSON_PRETTY_PRINT );

		if ( ! is_string( $json ) ) {
			return '';
		}

		return (string) preg_replace_callback(
			'/^( +)/m',
			static function ( array $found ): string {
				return str_repeat( '  ', intdiv( strlen( $found[1] ), 4 ) );
			},
			$json
		);
	}

	/**
	 * The whole script element, or an empty string.
	 *
	 * @param Article $article The article.
	 * @return string HTML that is already safe to print.
	 */
	public function script( Article $article ): string {
		$json = $this->json( $article );

		if ( '' === $json ) {
			return '';
		}

		return '<script type="application/ld+json" class="estasoft-nd-jsonld">' . $json . "</script>\n";
	}

	/**
	 * The paywall part of the data, for the article that has one.
	 *
	 * @param int $post_id The article.
	 * @return array<string, mixed> Empty when the setting is off, the article is free or no class is set.
	 */
	public function paywall_for( int $post_id ): array {
		$settings = $this->plugin->settings();

		if ( ! (bool) $settings->get( 'markup.paywall', true ) || ! in_array( $this->access_of( $post_id ), self::PAYWALLED, true ) ) {
			return array();
		}

		return self::paywall_fragment( (string) $settings->get( 'markup.paywall_selector', '.paywall' ) );
	}

	/**
	 * The paywall part for a list of classes.
	 *
	 * One class gives a single `hasPart`; several give a list of them, one for each
	 * class, because Google asks for class selectors only.
	 *
	 * @param string $selectors Class selectors separated by commas, such as ".paywall, .members".
	 * @return array<string, mixed> Empty when there is no usable class.
	 */
	public static function paywall_fragment( string $selectors ): array {
		$parts = array();

		foreach ( self::selectors( $selectors ) as $selector ) {
			$parts[] = array(
				'@type'               => 'WebPageElement',
				'isAccessibleForFree' => false,
				'cssSelector'         => $selector,
			);
		}

		if ( array() === $parts ) {
			return array();
		}

		return array(
			'isAccessibleForFree' => false,
			'hasPart'             => 1 === count( $parts ) ? $parts[0] : $parts,
		);
	}

	/**
	 * Splits a list of class selectors.
	 *
	 * @param string $selectors Text such as ".paywall, .members".
	 * @return string[] Each entry is a dot followed by a class name. Anything else is left out.
	 */
	public static function selectors( string $selectors ): array {
		$found = array();

		foreach ( explode( ',', $selectors ) as $selector ) {
			$selector = trim( $selector );

			if ( self::is_class_selector( $selector ) && ! in_array( $selector, $found, true ) ) {
				$found[] = $selector;
			}
		}

		return $found;
	}

	/**
	 * Whether a value is one class selector, such as ".paywall".
	 *
	 * @param string $selector The value.
	 * @return bool
	 */
	public static function is_class_selector( string $selector ): bool {
		return 1 === preg_match( '/^\.-?[_a-zA-Z][_a-zA-Z0-9-]*$/', $selector );
	}

	/**
	 * What the editor says about who can read an article.
	 *
	 * @param int $post_id The article.
	 * @return string everyone, subscribers, registration or metered.
	 */
	public function access_of( int $post_id ): string {
		$options = get_post_meta( $post_id, self::OPTIONS, true );
		$stored  = is_array( $options ) && isset( $options['access'] ) && is_scalar( $options['access'] ) ? (string) $options['access'] : '';

		/**
		 * Answers who can read an article, when the editor keeps that choice somewhere else.
		 *
		 * Internal. Return a string such as "subscribers", "registration", "metered" or "everyone", or null to read the article's options.
		 *
		 * @param string|null $access  The answer, or null.
		 * @param int         $post_id The article.
		 */
		$answer = apply_filters( 'estasoft_nd_internal_article_access', null, $post_id );

		if ( is_scalar( $answer ) ) {
			$stored = (string) $answer;
		}

		return self::normalise_access( $stored );
	}

	/**
	 * Turns an access choice into one of the four words.
	 *
	 * @param string $value A stored choice, such as "subscribers_only" or "Metered".
	 * @return string everyone, subscribers, registration or metered.
	 */
	public static function normalise_access( string $value ): string {
		$value = strtolower( (string) preg_replace( '/[^a-zA-Z]/', '', $value ) );

		if ( 0 === strpos( $value, 'subscri' ) ) {
			return 'subscribers';
		}

		if ( 0 === strpos( $value, 'regist' ) ) {
			return 'registration';
		}

		if ( 0 === strpos( $value, 'meter' ) ) {
			return 'metered';
		}

		return 'everyone';
	}

	/**
	 * The featured image as an ImageObject, with its caption, its credit (`creditText`) and its rights note
	 * (`copyrightNotice`), each only when the photo has one.
	 *
	 * @param Article $article The article.
	 * @return array<string, mixed> Empty when the article has no featured image.
	 */
	private function image( Article $article ): array {
		if ( '' === $article->thumbnail_url() ) {
			return array();
		}

		$image = array(
			'@type' => 'ImageObject',
			'url'   => $article->thumbnail_url(),
		);

		if ( $article->thumbnail_width() > 0 && $article->thumbnail_height() > 0 ) {
			$image['width']  = $article->thumbnail_width();
			$image['height'] = $article->thumbnail_height();
		}

		$attachment = (int) get_post_thumbnail_id( $article->id() );

		if ( $attachment < 1 ) {
			return $image;
		}

		$caption = trim( wp_strip_all_tags( (string) wp_get_attachment_caption( $attachment ) ) );
		$credit  = $this->credit_of( $attachment );

		if ( '' !== $caption ) {
			$image['caption'] = $caption;
		}

		if ( '' !== $credit ) {
			$image['creditText'] = $credit;
		}

		$notice = $this->notice_of( $attachment );

		if ( '' !== $notice ) {
			$image['copyrightNotice'] = $notice;
		}

		return $image;
	}

	/**
	 * The rights note a person wrote for an image, such as a copyright line.
	 *
	 * @param int $attachment Attachment ID.
	 * @return string Empty when the photo has no note.
	 */
	private function notice_of( int $attachment ): string {
		$note = $this->plugin->make( ImageCredits::class )->stored( $attachment )['note'];

		return trim( wp_strip_all_tags( $note ) );
	}

	/**
	 * The credit written for an image: the same answer the credit check reads.
	 *
	 * @param int $attachment Attachment ID.
	 * @return string
	 */
	private function credit_of( int $attachment ): string {
		// The same internal filter as the credit check, so one feature answers for both.
		$credit = apply_filters( 'estasoft_nd_internal_image_credit', null, $attachment );

		if ( null === $credit ) {
			$credit = get_post_meta( $attachment, CreditCheck::META, true );
		}

		return is_scalar( $credit ) ? trim( wp_strip_all_tags( (string) $credit ) ) : '';
	}
}
