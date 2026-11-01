<?php
/**
 * Translation coverage: which recent articles have a version in each language.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * For the Translations report. It reads the recent articles of every site language,
 * finds the versions of each in the translation plugin, and counts what is missing.
 *
 * Shape of `coverage()`:
 *
 *     array(
 *       'available' => bool,     // false when the site has one language only
 *       'reason'    => string,   // when not available: 'single_language'
 *       'days'      => int,      // how far back "recent" reaches
 *       'languages' => array( array( 'code' => 'en', 'name' => 'English', 'default' => bool, 'off' => bool ), ... ),
 *       'rows'      => array(    // one row per article, with all its versions
 *         array(
 *           'group'        => int,                       // the smallest article ID of the versions
 *           'title'        => string,                    // title of the default-language version, or the first one
 *           'published_ts' => int,                       // Unix time the first version was published
 *           'posts'        => array( 'en' => 12, 'ku' => 0, 'ar' => 15 ), // article ID per language, 0 when missing
 *           'missing'      => array( 'ku' ),             // languages without a version
 *         ),
 *       ),
 *       'totals'    => array( 'ku' => array( 'have' => 4, 'missing' => 5, 'percent' => 44 ), ... ),
 *       'articles'  => int,      // number of rows
 *       'complete'  => int,      // rows that have a version in every language
 *     )
 *
 * Articles and times are Unix times in UTC, as everywhere in the plugin.
 */
final class Translations {

	private Plugin $plugin;

	/**
	 * Replaces the lookup of an article's versions, for tests. Receives an article ID, returns language => ID.
	 *
	 * @var callable|null
	 */
	private $lookup;

	/**
	 * Creates the service.
	 *
	 * @param Plugin        $plugin The plugin.
	 * @param callable|null $lookup Optional lookup of an article's versions (article ID to language => article ID).
	 */
	public function __construct( Plugin $plugin, ?callable $lookup = null ) {
		$this->plugin = $plugin;
		$this->lookup = $lookup;
	}

	/**
	 * Translation coverage of the recent articles.
	 *
	 * @param int $days         How many days back counts as recent.
	 * @param int $per_language The most articles read for each language.
	 * @return array<string, mixed> See the class description.
	 */
	public function coverage( int $days = 30, int $per_language = 50 ): array {
		$days     = max( 1, min( 365, $days ) );
		$resolver = $this->plugin->languages();
		$codes    = array_values( array_unique( array_map( 'strval', $resolver->languages() ) ) );
		$default  = $resolver->default_language();
		$off      = array_map( 'strval', (array) $this->plugin->settings()->get( 'languages.off', array() ) );

		$languages = array();

		foreach ( $codes as $code ) {
			$languages[] = array(
				'code'    => $code,
				'name'    => Catalog::name( $code ),
				'default' => $code === $default,
				'off'     => in_array( $code, $off, true ),
			);
		}

		$empty = array(
			'available' => count( $codes ) > 1,
			'reason'    => count( $codes ) > 1 ? '' : 'single_language',
			'days'      => $days,
			'languages' => $languages,
			'rows'      => array(),
			'totals'    => array(),
			'articles'  => 0,
			'complete'  => 0,
		);

		if ( count( $codes ) < 2 ) {
			return $empty;
		}

		$groups = array();

		foreach ( $codes as $code ) {
			foreach ( $this->plugin->reader()->recent( $code, $days * DAY_IN_SECONDS, max( 1, $per_language ) ) as $article ) {
				$versions = $this->versions_of( $article->id(), $code );
				$key      = (int) min( $versions );

				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'versions' => $versions,
						'titles'   => array(),
						'times'    => array(),
					);
				}

				$groups[ $key ]['titles'][ $code ] = $article->title();
				$groups[ $key ]['times'][]         = $article->published_ts();
			}
		}

		ksort( $groups );

		$rows     = array();
		$totals   = array();
		$complete = 0;

		foreach ( $codes as $code ) {
			$totals[ $code ] = array(
				'have'    => 0,
				'missing' => 0,
				'percent' => 0,
			);
		}

		foreach ( $groups as $key => $group ) {
			$posts   = array();
			$missing = array();

			foreach ( $codes as $code ) {
				$id             = (int) ( $group['versions'][ $code ] ?? 0 );
				$posts[ $code ] = $id;

				if ( $id > 0 ) {
					++$totals[ $code ]['have'];
				} else {
					++$totals[ $code ]['missing'];
					$missing[] = $code;
				}
			}

			if ( array() === $missing ) {
				++$complete;
			}

			$title = $group['titles'][ $default ] ?? (string) reset( $group['titles'] );

			$rows[] = array(
				'group'        => (int) $key,
				'title'        => (string) $title,
				'published_ts' => (int) min( $group['times'] ),
				'posts'        => $posts,
				'missing'      => $missing,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['published_ts'] <=> $a['published_ts'];
			}
		);

		$count = count( $rows );

		foreach ( $totals as $code => $total ) {
			$totals[ $code ]['percent'] = $count > 0 ? (int) round( 100 * $total['have'] / $count ) : 0;
		}

		return array_merge(
			$empty,
			array(
				'rows'     => $rows,
				'totals'   => $totals,
				'articles' => $count,
				'complete' => $complete,
			)
		);
	}

	/**
	 * The versions of one article, language => article ID, always including the article itself.
	 *
	 * @param int    $post_id  Article ID.
	 * @param string $language The language the article is in.
	 * @return array<string, int>
	 */
	public function versions_of( int $post_id, string $language ): array {
		$found = array();

		if ( null !== $this->lookup ) {
			$found = (array) call_user_func( $this->lookup, $post_id );
		} else {
			/**
			 * Answers the versions of an article for the translations report.
			 *
			 * Internal. Return language => article ID, or null to use the detected
			 * translation plugin (Polylang or WPML).
			 *
			 * @param array<string, int>|null $versions Versions, or null.
			 * @param int                     $post_id  Article ID.
			 */
			$filtered = apply_filters( 'estasoft_nd_internal_post_translations', null, $post_id );
			$found    = is_array( $filtered ) ? $filtered : $this->from_plugin( $post_id );
		}

		$clean = array();

		foreach ( $found as $code => $id ) {
			if ( (int) $id > 0 ) {
				$clean[ (string) $code ] = (int) $id;
			}
		}

		$clean[ $language ] = $post_id;

		return $clean;
	}

	/**
	 * Reads an article's versions from the translation plugin.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, int>
	 */
	private function from_plugin( int $post_id ): array {
		$name = $this->plugin->languages()->name();

		if ( 'polylang' === $name && function_exists( 'pll_get_post_translations' ) ) {
			$list = pll_get_post_translations( $post_id );

			return is_array( $list ) ? array_map( 'intval', $list ) : array();
		}

		if ( 'wpml' === $name ) {
			$type  = (string) get_post_type( $post_id );
			$found = array();

			foreach ( $this->plugin->languages()->languages() as $code ) {
				$id = apply_filters( 'wpml_object_id', $post_id, $type, false, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.

				if ( is_numeric( $id ) && (int) $id > 0 ) {
					$found[ (string) $code ] = (int) $id;
				}
			}

			return $found;
		}

		return array();
	}
}
