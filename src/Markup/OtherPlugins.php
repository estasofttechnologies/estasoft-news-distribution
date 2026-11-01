<?php
/**
 * Asks SEO plugins to leave out a tag or the article data that News Distribution prints.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

defined( 'ABSPATH' ) || exit;

/**
 * Used only when the person chose "Replace it, where the other plugin allows" on
 * the Page markup screen.
 *
 * It does not edit another plugin's settings, its files or its output. It adds a
 * filter, for the one page being printed, to a hook that the other plugin offers,
 * and the other plugin decides what to do with it. If a plugin has no such hook,
 * or the hook below no longer exists in its current release, nothing happens, its
 * tag stays on the page, and News Distribution does not print a second copy.
 *
 * The hook names below are the ones the plugins documented when this was written.
 * They were not checked against each current release in this build, which the
 * Page markup screen does not hide: it reports what the page really contains.
 */
final class OtherPlugins {

	/**
	 * Hooks that let a plugin leave one tag out, by plugin id then by tag id.
	 * `value` is what the filter answers: an empty string or false means "print nothing".
	 *
	 * @return array<string, array<string, array{filter: string, value: mixed}>>
	 */
	public static function asks(): array {
		return array(
			'yoast'     => array(
				'image'     => array(
					'filter' => 'wpseo_opengraph_image',
					'value'  => '',
				),
				'locale'    => array(
					'filter' => 'wpseo_og_locale',
					'value'  => '',
				),
				'published' => array(
					'filter' => 'wpseo_og_article_published_time',
					'value'  => '',
				),
				'modified'  => array(
					'filter' => 'wpseo_og_article_modified_time',
					'value'  => '',
				),
				'canonical' => array(
					'filter' => 'wpseo_canonical',
					'value'  => false,
				),
			),
			'rank-math' => array(
				'image'     => array(
					'filter' => 'rank_math/opengraph/facebook/image',
					'value'  => '',
				),
				'locale'    => array(
					'filter' => 'rank_math/opengraph/facebook/og_locale',
					'value'  => '',
				),
				'published' => array(
					'filter' => 'rank_math/opengraph/facebook/article_published_time',
					'value'  => '',
				),
				'modified'  => array(
					'filter' => 'rank_math/opengraph/facebook/article_modified_time',
					'value'  => '',
				),
				'canonical' => array(
					'filter' => 'rank_math/frontend/canonical',
					'value'  => false,
				),
			),
		);
	}

	/**
	 * Adds the requests for the plugins that are active.
	 *
	 * @param string[] $plugin_ids The ids of the active plugins (see Integrations\KnownPlugins).
	 * @param string[] $tags       The tag ids News Distribution prints.
	 * @param bool     $jsonld     Whether News Distribution prints the article data.
	 * @return void
	 */
	public static function ask( array $plugin_ids, array $tags, bool $jsonld ): void {
		foreach ( self::asks() as $plugin_id => $asks ) {
			if ( ! in_array( $plugin_id, $plugin_ids, true ) ) {
				continue;
			}

			foreach ( $tags as $tag ) {
				if ( ! isset( $asks[ $tag ] ) ) {
					continue;
				}

				$value = $asks[ $tag ]['value'];

				add_filter(
					$asks[ $tag ]['filter'],
					static function () use ( $value ) {
						return $value;
					},
					99
				);
			}
		}

		if ( $jsonld && in_array( 'yoast', $plugin_ids, true ) ) {
			add_filter( 'wpseo_schema_graph_pieces', array( self::class, 'without_yoast_article' ), 99 );
		}

		if ( $jsonld && in_array( 'rank-math', $plugin_ids, true ) ) {
			add_filter( 'rank_math/json_ld', array( self::class, 'without_rank_math_article' ), 99 );
		}
	}

	/**
	 * Takes the article piece out of Yoast SEO's schema graph.
	 *
	 * @param mixed $pieces The graph pieces.
	 * @return mixed
	 */
	public static function without_yoast_article( $pieces ) {
		if ( ! is_array( $pieces ) ) {
			return $pieces;
		}

		return array_values(
			array_filter(
				$pieces,
				static function ( $piece ): bool {
					return ! ( is_object( $piece ) && '\Schema\Article' === substr( get_class( $piece ), -15 ) );
				}
			)
		);
	}

	/**
	 * Takes the article out of Rank Math's structured data.
	 *
	 * @param mixed $data Rank Math's structured data.
	 * @return mixed
	 */
	public static function without_rank_math_article( $data ) {
		if ( is_array( $data ) && isset( $data['richSnippet'] ) && PageTags::has_article_type( $data['richSnippet'] ) ) {
			unset( $data['richSnippet'] );
		}

		return $data;
	}
}
