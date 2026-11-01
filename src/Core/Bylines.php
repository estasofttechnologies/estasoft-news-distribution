<?php
/**
 * Works out who an article is by.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Platforms often ask for an author on every article, and many sites publish
 * under an account such as "admin". The Authors settings say where the name
 * comes from (co-author plugin, the post's author, a custom field, the newsroom
 * name, in that order by default) and which names are not people.
 *
 * Channels and the byline check ask here, so they agree. Get it with
 * `Plugin::instance()->make( Bylines::class )`.
 *
 * `resolve()` returns:
 *
 *     array(
 *       'names'     => string[],  // what feeds and sitemaps send; never empty unless nothing is known
 *       'joined'    => string,    // the names joined with ", " (for a platform that takes one value)
 *       'source'    => string,    // coauthors, post_author, custom_field or newsroom
 *       'replaced'  => bool,      // true when only names that are not people were found and the newsroom name was used
 *       'originals' => string[],  // the names that were replaced
 *     )
 */
final class Bylines {

	public const SOURCE_COAUTHORS    = 'coauthors';
	public const SOURCE_POST_AUTHOR  = 'post_author';
	public const SOURCE_CUSTOM_FIELD = 'custom_field';
	public const SOURCE_NEWSROOM     = 'newsroom';

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Every source, in the default order.
	 *
	 * @return string[]
	 */
	public static function sources(): array {
		return array( self::SOURCE_COAUTHORS, self::SOURCE_POST_AUTHOR, self::SOURCE_CUSTOM_FIELD, self::SOURCE_NEWSROOM );
	}

	/**
	 * Names that are accounts, not people, until the owner changes the list.
	 *
	 * @return string[]
	 */
	public static function default_not_people(): array {
		return array( 'admin', 'editor', 'webmaster', 'staff' );
	}

	/**
	 * The author of an article as feeds and sitemaps should send it.
	 *
	 * @param Article $article The article.
	 * @return array{names: string[], joined: string, source: string, replaced: bool, originals: string[]}
	 */
	public function resolve( Article $article ): array {
		$originals = array();

		foreach ( $this->order() as $source ) {
			if ( self::SOURCE_NEWSROOM === $source ) {
				continue;
			}

			$names = $this->names_from( $source, $article );

			if ( array() === $names ) {
				continue;
			}

			$people = array();

			foreach ( $names as $name ) {
				if ( $this->is_not_a_person( $name ) ) {
					$originals[] = $name;
				} else {
					$people[] = $name;
				}
			}

			if ( array() !== $people ) {
				return $this->result( $people, $source, false, array() );
			}
		}

		$newsroom = $this->newsroom_name();

		return $this->result(
			'' === $newsroom ? array() : array( $newsroom ),
			self::SOURCE_NEWSROOM,
			array() !== $originals,
			array_values( array_unique( $originals ) )
		);
	}

	/**
	 * What the newest published articles are by, for the Authors screen and the trust check.
	 *
	 * `real` counts articles with a person's name, `bare` those with no author at all (the
	 * newsroom name is sent), `replaced` those whose only names were not people. `names` lists
	 * the replaced names, each once. `latest` is the newest article, or null when there is none.
	 *
	 * @param int $sample How many of the newest published articles to read.
	 * @return array{checked: int, real: int, bare: int, replaced: int, names: string[], latest: ?Article}
	 */
	public function survey( int $sample = 18 ): array {
		$posts = get_posts(
			array(
				'post_type'           => $this->plugin->settings()->post_types(),
				'post_status'         => 'publish',
				'posts_per_page'      => max( 1, $sample ),
				'orderby'             => 'date',
				'order'               => 'DESC',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'lang'                => '',
				'suppress_filters'    => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The sample covers every language; another plugin's filters must not narrow it.
			)
		);
		$out   = array(
			'checked'  => 0,
			'real'     => 0,
			'bare'     => 0,
			'replaced' => 0,
			'names'    => array(),
			'latest'   => null,
		);

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$article = $this->plugin->reader()->find( (int) $post->ID );

			if ( null === $article ) {
				continue;
			}

			$byline = $this->resolve( $article );

			++$out['checked'];

			if ( null === $out['latest'] ) {
				$out['latest'] = $article;
			}

			if ( $byline['replaced'] ) {
				++$out['replaced'];
				$out['names'] = array_values( array_unique( array_merge( $out['names'], $byline['originals'] ) ) );
			} elseif ( self::SOURCE_NEWSROOM === $byline['source'] ) {
				++$out['bare'];
			} else {
				++$out['real'];
			}
		}

		return $out;
	}

	/**
	 * Whether a name is one the owner listed as not a person. Whole words, any capitals.
	 *
	 * @param string $name A display name.
	 * @return bool
	 */
	public function is_not_a_person( string $name ): bool {
		foreach ( $this->not_people() as $listed ) {
			if ( self::contains_word( $name, $listed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The names the owner listed, cleaned.
	 *
	 * @return string[]
	 */
	public function not_people(): array {
		$listed = $this->plugin->settings()->get( 'authors.not_people', self::default_not_people() );
		$clean  = array();

		foreach ( is_array( $listed ) ? $listed : array() as $name ) {
			$name = trim( (string) $name );

			if ( '' !== $name ) {
				$clean[] = $name;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * The sources in the owner's order. Every source appears once.
	 *
	 * @return string[]
	 */
	public function order(): array {
		$stored = $this->plugin->settings()->get( 'authors.order', self::sources() );
		$order  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $source ) {
			if ( in_array( $source, self::sources(), true ) && ! in_array( $source, $order, true ) ) {
				$order[] = (string) $source;
			}
		}

		foreach ( self::sources() as $source ) {
			if ( ! in_array( $source, $order, true ) ) {
				$order[] = $source;
			}
		}

		return $order;
	}

	/**
	 * The name used for the newsroom and for replaced names: the owner's text,
	 * else "<publication name> staff".
	 *
	 * @return string
	 */
	public function newsroom_name(): string {
		$name = trim( (string) $this->plugin->settings()->get( 'authors.newsroom_name', '' ) );

		if ( '' !== $name ) {
			return $name;
		}

		$publication = $this->plugin->settings()->publication_name();

		if ( '' === $publication ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the publication name, such as Example Times. The result is used as an author name in feeds. */
			__( '%s staff', 'estasoft-news-distribution' ),
			$publication
		);
	}

	/**
	 * Names from a co-author plugin for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function coauthors( int $post_id ): array {
		/**
		 * Lets another plugin or a test supply the co-authors of a post.
		 *
		 * Internal. Return a list of display names, or null to use the detected co-author plugin.
		 *
		 * @param array|null $names   Display names, or null.
		 * @param int        $post_id Post ID.
		 */
		$names = apply_filters( 'estasoft_nd_internal_coauthors', null, $post_id );

		if ( is_array( $names ) ) {
			return $this->clean( $names );
		}

		if ( ! function_exists( 'get_coauthors' ) ) {
			return array();
		}

		$found = array();

		foreach ( (array) get_coauthors( $post_id ) as $author ) {
			if ( is_object( $author ) && isset( $author->display_name ) ) {
				$found[] = (string) $author->display_name;
			}
		}

		return $this->clean( $found );
	}

	/**
	 * The name of the co-author plugin on this site, or an empty string.
	 *
	 * @return string
	 */
	public function coauthor_plugin(): string {
		if ( class_exists( 'CoAuthors_Plus' ) ) {
			return 'Co-Authors Plus';
		}

		if ( defined( 'PP_AUTHORS_VERSION' ) ) {
			return 'PublishPress Authors';
		}

		return function_exists( 'get_coauthors' ) ? __( 'A co-authors plugin', 'estasoft-news-distribution' ) : '';
	}

	/**
	 * Whether a text contains a listed name as a whole word, in any capitals.
	 *
	 * @param string $text Text to search.
	 * @param string $word The name.
	 * @return bool
	 */
	public static function contains_word( string $text, string $word ): bool {
		$word = trim( $word );

		if ( '' === $word ) {
			return false;
		}

		return 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $word, '/' ) . '(?![\p{L}\p{N}])/iu', $text );
	}

	/**
	 * Names one source offers for one article.
	 *
	 * @param string  $source  A source key.
	 * @param Article $article The article.
	 * @return string[]
	 */
	private function names_from( string $source, Article $article ): array {
		switch ( $source ) {
			case self::SOURCE_COAUTHORS:
				return $this->coauthors( $article->id() );

			case self::SOURCE_POST_AUTHOR:
				return $this->clean( array( $article->author_name() ) );

			case self::SOURCE_CUSTOM_FIELD:
				$field = trim( (string) $this->plugin->settings()->get( 'authors.custom_field', '' ) );

				if ( '' === $field ) {
					return array();
				}

				$value = get_post_meta( $article->id(), $field, true );

				$parts = is_scalar( $value ) ? preg_split( '/[;\r\n]+/', (string) $value ) : false;

				return is_array( $parts ) ? $this->clean( $parts ) : array();
		}

		return array();
	}

	/**
	 * Trims names and drops empty and repeated ones.
	 *
	 * @param array<int, mixed> $names Raw names.
	 * @return string[]
	 */
	private function clean( array $names ): array {
		$clean = array();

		foreach ( $names as $name ) {
			$name = trim( wp_strip_all_tags( (string) $name ) );

			if ( '' !== $name && ! in_array( $name, $clean, true ) ) {
				$clean[] = $name;
			}
		}

		return $clean;
	}

	/**
	 * Builds the result array.
	 *
	 * @param string[] $names     Names to send.
	 * @param string   $source    Source key.
	 * @param bool     $replaced  Whether names that are not people were replaced.
	 * @param string[] $originals The replaced names.
	 * @return array{names: string[], joined: string, source: string, replaced: bool, originals: string[]}
	 */
	private function result( array $names, string $source, bool $replaced, array $originals ): array {
		return array(
			'names'     => $names,
			'joined'    => implode( ', ', $names ),
			'source'    => $source,
			'replaced'  => $replaced,
			'originals' => $originals,
		);
	}
}
