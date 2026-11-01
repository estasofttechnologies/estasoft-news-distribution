<?php
/**
 * What the Discover report reads and judges.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Integrations\SiteFetch;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Three things are looked at, all on this site: the width of the featured image and whether it is the
 * site logo (from the media library, no request), the title (only structural hints), and the
 * `max-image-preview` rule of the page itself (one request per article page, only when a person presses
 * "Check the pages"). Nothing here says an article will or will not appear in Discover; Google decides
 * that. The facts the checks rest on are in the verify-log, "Report-discover".
 */
final class Discover {

	/**
	 * The narrowest image Google recommends for Discover, in pixels.
	 */
	public const MIN_WIDTH = 1200;

	/**
	 * The most article pages listed and read.
	 */
	public const MAX_PAGES = 24;

	/**
	 * The most seconds one run may take.
	 */
	private const BUDGET = 45;

	/**
	 * A run of this many exclamation marks is a hint.
	 */
	private const EXCLAMATIONS = 3;

	private Plugin $plugin;

	/**
	 * Creates the reader.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The newest published articles of the chosen post types, across languages, newest first.
	 *
	 * @return Article[]
	 */
	public function articles(): array {
		$found = array();
		$seen  = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			foreach ( $this->plugin->reader()->recent( (string) $language, 30 * DAY_IN_SECONDS, self::MAX_PAGES ) as $article ) {
				if ( $article instanceof Article && ! isset( $seen[ $article->id() ] ) && '' !== $article->permalink() ) {
					$seen[ $article->id() ] = true;
					$found[]                = $article;
				}
			}
		}

		usort(
			$found,
			static function ( Article $a, Article $b ): int {
				return $b->published_ts() <=> $a->published_ts();
			}
		);

		return array_slice( $found, 0, self::MAX_PAGES );
	}

	/**
	 * The pages one run reads.
	 *
	 * @return int
	 */
	public function units(): int {
		return max( 1, count( $this->articles() ) );
	}

	/**
	 * Reads the page of every article and keeps what its preview rule says.
	 *
	 * @param callable $progress Called with the pages read so far.
	 * @return array<string, mixed> `ran_at`, `pages` (by article ID: `state`, `directive`, `status`) and `unchecked`.
	 */
	public function scan( callable $progress ): array {
		$started = microtime( true );
		$pages   = array();
		$skipped = 0;
		$done    = 0;

		foreach ( $this->articles() as $article ) {
			if ( microtime( true ) - $started > self::BUDGET ) {
				$pages[ $article->id() ] = array(
					'state'     => 'unchecked',
					'directive' => '',
					'status'    => 0,
				);
				++$skipped;
			} else {
				$pages[ $article->id() ] = $this->read( $article->permalink() );
			}

			++$done;
			$progress( $done );
		}

		return array(
			'ran_at'    => Clock::now(),
			'pages'     => $pages,
			'unchecked' => $skipped,
		);
	}

	/**
	 * Reads one page.
	 *
	 * @param string $url Address of the page on this site.
	 * @return array{state: string, directive: string, status: int}
	 */
	private function read( string $url ): array {
		$page = SiteFetch::get( $url, 8 );

		if ( ! $page['ok'] || 200 !== (int) $page['status'] ) {
			return array(
				'state'     => 'error',
				'directive' => '',
				'status'    => (int) $page['status'],
			);
		}

		$rule = self::preview_of( (string) $page['body'], (string) ( $page['headers']['x-robots-tag'] ?? '' ) );

		return array(
			'state'     => $rule['state'],
			'directive' => $rule['directive'],
			'status'    => 200,
		);
	}

	/**
	 * What a page says about the size of an image preview.
	 *
	 * Read from the robots and googlebot meta tags in the head and from the X-Robots-Tag header.
	 * `large` allows a large image. `none` and `standard` keep it small. A page that says nothing
	 * leaves the choice to Google, which the report calls "Not set".
	 *
	 * @param string $html   The page, or its head.
	 * @param string $header The X-Robots-Tag header, or an empty string.
	 * @return array{state: string, directive: string} state is allowed, blocked or not_set; directive is what was found, such as "none".
	 */
	public static function preview_of( string $html, string $header = '' ): array {
		$end    = stripos( $html, '</head>' );
		$head   = false === $end ? $html : substr( $html, 0, $end );
		$values = array();

		$processor = new \WP_HTML_Tag_Processor( $head );

		while ( $processor->next_tag( 'meta' ) ) {
			$name    = $processor->get_attribute( 'name' );
			$content = $processor->get_attribute( 'content' );

			if ( ! is_string( $name ) || ! is_string( $content ) || ! in_array( strtolower( trim( $name ) ), array( 'robots', 'googlebot' ), true ) ) {
				continue;
			}

			$values = array_merge( $values, self::directives( $content ) );
		}

		$values = array_merge( $values, self::directives( $header ) );

		if ( array() === $values ) {
			return array(
				'state'     => 'not_set',
				'directive' => '',
			);
		}

		foreach ( $values as $value ) {
			if ( 'large' !== $value ) {
				return array(
					'state'     => 'blocked',
					'directive' => $value,
				);
			}
		}

		return array(
			'state'     => 'allowed',
			'directive' => 'large',
		);
	}

	/**
	 * The `max-image-preview` values in a robots string.
	 *
	 * @param string $text A robots meta content or an X-Robots-Tag header.
	 * @return string[] Lower-case values such as "large" or "none".
	 */
	private static function directives( string $text ): array {
		if ( '' === $text || ! preg_match_all( '/max-image-preview\s*:\s*([a-z]+)/i', $text, $matches ) ) {
			return array();
		}

		return array_map( 'strtolower', $matches[1] );
	}

	/**
	 * Hints about a title. They are hints only: no title is ever judged bad.
	 *
	 * @param string $title The title.
	 * @return string[] Plain sentences such as "Hint: three exclamation marks"; empty when there are none.
	 */
	public static function hints( string $title ): array {
		$hints = array();

		if ( preg_match( '/!{' . self::EXCLAMATIONS . ',}/', $title, $run ) ) {
			$hints[] = strlen( $run[0] ) === self::EXCLAMATIONS
				? __( 'Hint: three exclamation marks', 'estasoft-news-distribution' )
				: sprintf(
					/* translators: %s: number of exclamation marks in a row. */
					__( 'Hint: %s exclamation marks in a row', 'estasoft-news-distribution' ),
					number_format_i18n( strlen( $run[0] ) )
				);
		}

		$letters = (string) preg_replace( '/[^\p{L}]/u', '', $title );
		$upper   = (string) preg_replace( '/[^\p{Lu}]/u', '', $title );

		// Only scripts that have capitals can be all capitals; Arabic letters have neither case.
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $letters ) >= 12 && mb_strlen( $upper ) === mb_strlen( $letters ) ) {
			$hints[] = __( 'Hint: all capital letters', 'estasoft-news-distribution' );
		}

		return $hints;
	}

	/**
	 * The featured image of an article, as far as the media library tells.
	 *
	 * @param Article $article The article.
	 * @return array{state: string, width: int} state is none, logo, small or fine.
	 */
	public function image_of( Article $article ): array {
		$width = $article->thumbnail_width();

		if ( '' === $article->thumbnail_url() || $width < 1 ) {
			return array(
				'state' => 'none',
				'width' => 0,
			);
		}

		$thumb = (int) get_post_thumbnail_id( $article->id() );
		$logos = array_filter(
			array(
				(int) get_theme_mod( 'custom_logo' ),
				(int) get_option( 'site_icon' ),
			)
		);

		if ( $thumb > 0 && in_array( $thumb, $logos, true ) ) {
			return array(
				'state' => 'logo',
				'width' => $width,
			);
		}

		return array(
			'state' => $width < self::MIN_WIDTH ? 'small' : 'fine',
			'width' => $width,
		);
	}
}
