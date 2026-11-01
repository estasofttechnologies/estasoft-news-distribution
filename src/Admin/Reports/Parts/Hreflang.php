<?php
/**
 * Reads the language links (hreflang) of the pages of each article's translations, and judges them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Translations;
use Estasoft\NewsDistribution\Integrations\SiteFetch;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * For the Hreflang report. Two parts:
 *
 * - `scan()` reads the `<link rel="alternate" hreflang>` tags in the head of each published version
 *   of the recent articles. It asks only this site's own pages, through `SiteFetch`, and only when
 *   a person starts it. It never changes anything.
 * - `analyse()` judges what the scan stored, with no request. It is a plain function of the stored
 *   figures, so the judging can be tested with fixed data.
 *
 * The rules are the ones the screen lists: every version lists itself and every other version,
 * a language code is two letters (ISO 639-1) with an optional script or region, Kurmanji is
 * ku-Latn and never kmr, and the address in each tag is that version's own address. Google's
 * page on localized versions says the tags may be ignored when a page does not link back, and
 * that a code is ISO 639-1 with an optional region or script; it does not name ku-Latn, so
 * that part follows the same pattern.
 *
 * Shape of a stored group:
 *
 *     array(
 *       'group'        => int,
 *       'title'        => string,
 *       'published_ts' => int,
 *       'versions'     => array( 'en' => array(
 *         'id'      => int,     // 0 when the language has no version
 *         'state'   => string,  // published, draft or missing
 *         'url'     => string,  // the address of the version
 *         'status'  => int,     // HTTP status of the page, 0 when it was not read
 *         'error'   => string,  // why it was not read, or empty
 *         'tags'    => array( array( 'code' => string, 'href' => string ) ),
 *         'arabic'  => bool,    // a Kurdish version written in Arabic letters
 *       ) ),
 *     )
 */
final class Hreflang {

	/**
	 * The most articles read in one run.
	 */
	public const MAX_GROUPS = 24;

	/**
	 * The most seconds one run may take.
	 */
	private const BUDGET = 45;

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
	 * The translation groups to read: the newest articles, with the versions each has.
	 *
	 * @return array{languages: string[], rows: array<int, array<string, mixed>>}
	 */
	public function plan(): array {
		$coverage  = $this->plugin->make( Translations::class )->coverage( 30, 50 );
		$languages = array();

		foreach ( $coverage['languages'] as $language ) {
			if ( empty( $language['off'] ) ) {
				$languages[] = (string) $language['code'];
			}
		}

		return array(
			'languages' => $languages,
			'rows'      => array_slice( (array) $coverage['rows'], 0, self::MAX_GROUPS ),
		);
	}

	/**
	 * The pages one run will read.
	 *
	 * @return int
	 */
	public function units(): int {
		$units = 0;

		foreach ( $this->plan()['rows'] as $row ) {
			foreach ( (array) $row['posts'] as $post_id ) {
				$units += (int) $post_id > 0 ? 1 : 0;
			}
		}

		return max( 1, $units );
	}

	/**
	 * Reads every group.
	 *
	 * @param callable $progress Called with the pages read so far.
	 * @return array<string, mixed> `ran_at`, `languages`, `groups` (stored groups, newest first) and `unchecked` (versions not read for lack of time).
	 */
	public function scan( callable $progress ): array {
		$plan    = $this->plan();
		$started = microtime( true );
		$done    = 0;
		$groups  = array();
		$skipped = 0;

		foreach ( $plan['rows'] as $row ) {
			$group    = $this->read_group( $row, $plan['languages'], $started );
			$skipped += $group['skipped'];
			$done    += $group['read'];
			$groups[] = $group['group'];

			$progress( $done );
		}

		return array(
			'ran_at'    => Clock::now(),
			'languages' => $plan['languages'],
			'groups'    => $groups,
			'unchecked' => $skipped,
		);
	}

	/**
	 * Reads the pages of one article again and gives back the fresh group.
	 *
	 * @param int $group_id The group ID (the smallest article ID of the versions).
	 * @return array<string, mixed>|null Null when the article is no longer in the recent list.
	 */
	public function recheck( int $group_id ): ?array {
		$plan = $this->plan();

		foreach ( $plan['rows'] as $row ) {
			if ( (int) $row['group'] === $group_id ) {
				return $this->read_group( $row, $plan['languages'], microtime( true ) )['group'];
			}
		}

		return null;
	}

	/**
	 * Reads the published versions of one article.
	 *
	 * @param array<string, mixed> $row       A row of the coverage.
	 * @param string[]             $languages The languages that are on.
	 * @param float                $started   When the run began, for the time limit.
	 * @return array{group: array<string, mixed>, read: int, skipped: int}
	 */
	private function read_group( array $row, array $languages, float $started ): array {
		$versions = array();
		$read     = 0;
		$skipped  = 0;

		foreach ( $languages as $code ) {
			$post_id = (int) ( $row['posts'][ $code ] ?? 0 );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;

			if ( ! $post instanceof \WP_Post ) {
				$versions[ $code ] = $this->version( 0, 'missing' );
				continue;
			}

			if ( 'publish' !== $post->post_status ) {
				$versions[ $code ] = $this->version( $post_id, 'draft' );
				++$read;
				continue;
			}

			$version           = $this->version( $post_id, 'published' );
			$version['url']    = (string) get_permalink( $post );
			$version['arabic'] = Scripts::kurdish_in_arabic_letters( $code, (string) get_the_title( $post ), (string) $post->post_content );

			if ( microtime( true ) - $started > self::BUDGET ) {
				$version['error'] = 'not_checked';
				++$skipped;
			} else {
				$page = SiteFetch::get( $version['url'], 8 );

				$version['status'] = (int) $page['status'];
				$version['error']  = $page['ok'] ? '' : (string) $page['error'];
				$version['tags']   = $page['ok'] && 200 === $page['status'] ? self::tags( (string) $page['body'] ) : array();
			}

			$versions[ $code ] = $version;
			++$read;
		}

		return array(
			'group'   => array(
				'group'        => (int) $row['group'],
				'title'        => (string) $row['title'],
				'published_ts' => (int) $row['published_ts'],
				'versions'     => $versions,
			),
			'read'    => $read,
			'skipped' => $skipped,
		);
	}

	/**
	 * A blank stored version.
	 *
	 * @param int    $post_id Article ID, or 0.
	 * @param string $state   published, draft or missing.
	 * @return array<string, mixed>
	 */
	private function version( int $post_id, string $state ): array {
		return array(
			'id'     => $post_id,
			'state'  => $state,
			'url'    => '',
			'status' => 0,
			'error'  => '',
			'tags'   => array(),
			'arabic' => false,
		);
	}

	/**
	 * The language links in the head of a page.
	 *
	 * @param string $html A page, or its head.
	 * @return array<int, array{code: string, href: string}>
	 */
	public static function tags( string $html ): array {
		$end  = stripos( $html, '</head>' );
		$head = false === $end ? $html : substr( $html, 0, $end );
		$out  = array();

		$processor = new \WP_HTML_Tag_Processor( $head );

		while ( $processor->next_tag( 'link' ) ) {
			$rel      = $processor->get_attribute( 'rel' );
			$hreflang = $processor->get_attribute( 'hreflang' );
			$href     = $processor->get_attribute( 'href' );

			if ( ! is_string( $rel ) || ! is_string( $hreflang ) || ! is_string( $href ) ) {
				continue;
			}

			if ( ! in_array( 'alternate', (array) preg_split( '/\s+/', strtolower( trim( $rel ) ) ), true ) ) {
				continue;
			}

			$out[] = array(
				'code' => trim( $hreflang ),
				'href' => trim( $href ),
			);

			if ( count( $out ) >= 40 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Whether a language code is one the tags may use: `x-default`, or two letters (ISO 639-1)
	 * with an optional four-letter script and an optional two-letter region.
	 *
	 * @param string $code The code.
	 * @return bool
	 */
	public static function valid_code( string $code ): bool {
		if ( 'x-default' === strtolower( $code ) ) {
			return true;
		}

		return 1 === preg_match( '/^[A-Za-z]{2}(-[A-Za-z]{4})?(-[A-Za-z]{2})?$/', $code );
	}

	/**
	 * What a wrong code should be, when that is known.
	 *
	 * @param string $code The code a tag uses.
	 * @return string The code to use, or an empty string when it is not known.
	 */
	public static function fix_for( string $code ): string {
		$known = array(
			'kmr' => 'ku-Latn',
			'ara' => 'ar',
			'eng' => 'en',
			'kur' => 'ku',
		);

		return $known[ strtolower( $code ) ] ?? '';
	}

	/**
	 * An address as it is compared: no scheme, no fragment, no closing slash, lower case.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function address( string $url ): string {
		$url = strtolower( trim( $url ) );
		$url = (string) preg_replace( '#^https?://#', '', $url );
		$url = (string) preg_replace( '/#.*$/', '', $url );

		return rtrim( $url, '/' );
	}

	/**
	 * Judges one stored group.
	 *
	 * @param array<string, mixed> $group     A stored group.
	 * @param string[]             $languages The languages that are on, in screen order.
	 * @return array{cells: array<string, array<string, mixed>>, result: string, result_text: string, problem: bool, fix: string}
	 *         Each cell has `state`, `tone` (success, warning, danger, neutral), `text` and, for a wrong code, `code` and `use`.
	 *         `result` is complete, unchecked, unreachable, same_address, wrong_code, script, missing_self or no_return.
	 *         `fix` is the language whose page the tags card should show, or an empty string.
	 */
	public static function analyse( array $group, array $languages ): array {
		$versions  = (array) $group['versions'];
		$published = array();

		foreach ( $languages as $code ) {
			if ( isset( $versions[ $code ] ) && 'published' === $versions[ $code ]['state'] ) {
				$published[ $code ] = $versions[ $code ];
			}
		}

		$cells = array();
		$found = array();

		foreach ( $languages as $code ) {
			$version = $versions[ $code ] ?? array( 'state' => 'missing' );

			if ( 'draft' === $version['state'] ) {
				$cells[ $code ] = self::cell( 'draft', 'neutral', __( 'Draft, not checked', 'estasoft-news-distribution' ) );
				continue;
			}

			if ( 'published' !== $version['state'] ) {
				$cells[ $code ] = self::cell( 'no_translation', 'neutral', __( 'No translation', 'estasoft-news-distribution' ) );
				continue;
			}

			$cells[ $code ] = self::judge( $code, $version, $published, $languages );

			if ( ! in_array( $cells[ $code ]['state'], array( 'lists_all', 'lists_some', 'lists_itself', 'no_tags' ), true ) && ! isset( $found[ $cells[ $code ]['state'] ] ) ) {
				$found[ $cells[ $code ]['state'] ] = $code;
			}
		}

		return self::result( $cells, $found, $languages );
	}

	/**
	 * Judges the page of one published version.
	 *
	 * @param string                              $code      Language of the version.
	 * @param array<string, mixed>                $version   The stored version.
	 * @param array<string, array<string, mixed>> $published Every published version of the article.
	 * @param string[]                            $languages The languages that are on.
	 * @return array<string, mixed>
	 */
	private static function judge( string $code, array $version, array $published, array $languages ): array {
		if ( 'not_checked' === $version['error'] ) {
			return self::cell( 'unchecked', 'neutral', __( 'Not checked yet', 'estasoft-news-distribution' ) );
		}

		if ( '' !== $version['error'] || 200 !== (int) $version['status'] ) {
			return self::cell(
				'unreachable',
				'danger',
				0 === (int) $version['status']
					? __( 'Page did not answer', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: an HTTP status such as 404. */
						__( 'Page answered %s', 'estasoft-news-distribution' ),
						number_format_i18n( (int) $version['status'] )
					)
			);
		}

		$own   = self::address( (string) $version['url'] );
		$twins = array();

		foreach ( $published as $other_code => $other ) {
			if ( $other_code !== $code && self::address( (string) $other['url'] ) === $own ) {
				$twins[] = View::language( (string) $other_code );
			}
		}

		if ( array() !== $twins ) {
			return self::cell(
				'same_address',
				'danger',
				sprintf(
					/* translators: %s: language names such as "Arabic". */
					__( 'Same address as %s', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $twins )
				)
			);
		}

		$listed = array();
		$bad    = array();

		foreach ( (array) $version['tags'] as $tag ) {
			$target = self::target( (string) $tag['code'], (string) $tag['href'], $published );

			if ( '' !== $target ) {
				$listed[ $target ] = true;
			}

			if ( ! self::valid_code( (string) $tag['code'] ) && null === ( $bad[ (string) $tag['code'] ] ?? null ) ) {
				$bad[ (string) $tag['code'] ] = $target;
			}
		}

		foreach ( $bad as $wrong => $target_code ) {
			$use  = self::fix_for( (string) $wrong );
			$cell = self::cell(
				'wrong_code',
				'danger',
				'' === $use
					? sprintf(
						/* translators: %s: a language code such as eng. */
						__( 'Uses %s, which is not a two-letter code', 'estasoft-news-distribution' ),
						$wrong
					)
					: sprintf(
						/* translators: 1: the code in the page such as kmr, 2: the code to use such as ku-Latn. */
						__( 'Uses %1$s, not %2$s', 'estasoft-news-distribution' ),
						$wrong,
						$use
					)
			);

			$cell['code'] = (string) $wrong;
			$cell['use']  = $use;
			$cell['lang'] = '' === $target_code ? $code : $target_code;

			return $cell;
		}

		foreach ( (array) $version['tags'] as $tag ) {
			$target = self::target( (string) $tag['code'], (string) $tag['href'], $published );

			if ( '' === $target || 'ku' !== Catalog::primary( $target ) ) {
				continue;
			}

			$arabic_tag = false !== stripos( (string) $tag['code'], '-Arab' );
			$latin_tag  = false !== stripos( (string) $tag['code'], '-Latn' );
			$text_arab  = ! empty( $published[ $target ]['arabic'] );

			if ( ( $latin_tag && $text_arab ) || ( $arabic_tag && ! $text_arab ) ) {
				$cell         = self::cell(
					'script',
					'warning',
					$latin_tag
						? __( 'Says ku-Latn, text is in Arabic letters', 'estasoft-news-distribution' )
						: __( 'Says ku-Arab, text is in Latin letters', 'estasoft-news-distribution' )
				);
				$cell['lang'] = $target;

				return $cell;
			}
		}

		if ( 1 === count( $published ) ) {
			return isset( $listed[ $code ] )
				? self::cell( 'lists_itself', 'success', __( 'Lists itself', 'estasoft-news-distribution' ) )
				: self::cell( 'no_tags', 'neutral', __( 'No tags, none needed', 'estasoft-news-distribution' ) );
		}

		if ( ! isset( $listed[ $code ] ) ) {
			return self::cell( 'missing_self', 'danger', __( 'Does not list itself', 'estasoft-news-distribution' ) );
		}

		$missing = array();

		foreach ( array_keys( $published ) as $other_code ) {
			if ( $other_code !== $code && ! isset( $listed[ $other_code ] ) ) {
				$missing[] = strtoupper( Catalog::primary( (string) $other_code ) );
			}
		}

		if ( array() !== $missing ) {
			return self::cell(
				'missing_others',
				'danger',
				sprintf(
					/* translators: %s: language codes such as "KU and AR". */
					__( 'Does not list %s', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $missing )
				)
			);
		}

		if ( count( $published ) === count( $languages ) ) {
			return self::cell(
				'lists_all',
				'success',
				sprintf(
					/* translators: %s: number of versions. */
					__( 'Lists all %s', 'estasoft-news-distribution' ),
					number_format_i18n( count( $published ) )
				)
			);
		}

		$others = array();

		foreach ( array_keys( $published ) as $other_code ) {
			if ( $other_code !== $code ) {
				$others[] = strtoupper( Catalog::primary( (string) $other_code ) );
			}
		}

		return self::cell(
			'lists_some',
			'success',
			sprintf(
				/* translators: %s: language codes such as "AR" or "KU and AR". */
				__( 'Lists itself and %s', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $others )
			)
		);
	}

	/**
	 * Which published version a tag points at: by its address first, then by its language code.
	 *
	 * @param string                              $hreflang  The code in the tag.
	 * @param string                              $href      The address in the tag.
	 * @param array<string, array<string, mixed>> $published Every published version.
	 * @return string The language code of the version, or an empty string.
	 */
	private static function target( string $hreflang, string $href, array $published ): string {
		$address = self::address( $href );

		foreach ( $published as $code => $version ) {
			if ( '' !== $address && self::address( (string) $version['url'] ) === $address ) {
				return (string) $code;
			}
		}

		$primary = strtolower( Catalog::primary( $hreflang ) );

		foreach ( array_keys( $published ) as $code ) {
			if ( strtolower( Catalog::primary( (string) $code ) ) === $primary ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * A cell.
	 *
	 * @param string $state The state.
	 * @param string $tone  success, warning, danger or neutral.
	 * @param string $text  Plain words.
	 * @return array<string, mixed>
	 */
	private static function cell( string $state, string $tone, string $text ): array {
		return array(
			'state' => $state,
			'tone'  => $tone,
			'text'  => $text,
		);
	}

	/**
	 * The result of an article, from its cells.
	 *
	 * @param array<string, array<string, mixed>> $cells     The cells.
	 * @param array<string, string>               $found     The first language for each state that is a finding.
	 * @param string[]                            $languages The languages that are on.
	 * @return array{cells: array<string, array<string, mixed>>, result: string, result_text: string, problem: bool, fix: string}
	 */
	private static function result( array $cells, array $found, array $languages ): array {
		unset( $languages );

		$order = array( 'unreachable', 'same_address', 'wrong_code', 'script', 'missing_self', 'missing_others', 'unchecked' );

		foreach ( $order as $state ) {
			if ( ! isset( $found[ $state ] ) ) {
				continue;
			}

			$code = $found[ $state ];
			$lang = (string) ( $cells[ $code ]['lang'] ?? $code );

			switch ( $state ) {
				case 'unreachable':
					$text = __( 'A page does not answer', 'estasoft-news-distribution' );
					break;
				case 'same_address':
					$text = __( 'Two pages share one address', 'estasoft-news-distribution' );
					break;
				case 'wrong_code':
					$text = sprintf(
						/* translators: %s: a language name such as Kurdish. */
						__( 'Wrong code on the %s page', 'estasoft-news-distribution' ),
						View::language_short( $lang )
					);
					break;
				case 'script':
					$text = sprintf(
						/* translators: %s: a language name such as Kurdish. */
						__( 'Wrong script in the %s tag', 'estasoft-news-distribution' ),
						View::language_short( $lang )
					);
					break;
				case 'missing_self':
					$text = __( 'A page does not list itself', 'estasoft-news-distribution' );
					break;
				case 'missing_others':
					$count = count(
						array_filter(
							$cells,
							static function ( array $cell ): bool {
								return 'missing_others' === $cell['state'];
							}
						)
					);
					$text  = sprintf(
						/* translators: %s: number of pages. */
						_n( '%s page does not link back', '%s pages do not link back', $count, 'estasoft-news-distribution' ),
						number_format_i18n( $count )
					);
					break;
				default:
					$text = __( 'Not checked yet', 'estasoft-news-distribution' );
			}

			return array(
				'cells'       => $cells,
				'result'      => $state,
				'result_text' => $text,
				'problem'     => 'unchecked' !== $state,
				'fix'         => in_array( $state, array( 'wrong_code', 'script', 'missing_self', 'missing_others' ), true ) ? $code : '',
			);
		}

		return array(
			'cells'       => $cells,
			'result'      => 'complete',
			'result_text' => __( 'Complete', 'estasoft-news-distribution' ),
			'problem'     => false,
			'fix'         => '',
		);
	}
}
