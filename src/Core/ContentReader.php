<?php
/**
 * Reads posts and returns lean Article objects.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Language\LanguageResolverInterface;

defined( 'ABSPATH' ) || exit;

/**
 * One place that touches WP_Post, so channels never run their own queries.
 *
 * Queries skip the found-rows count and prime term and meta caches in one go,
 * which avoids per-post queries. If profiling ever shows this is still too
 * heavy, replace the query inside recent() with a direct SELECT of the needed
 * columns; nothing else has to change.
 */
final class ContentReader {

	private LanguageResolverInterface $languages;
	private Settings $settings;

	public function __construct( LanguageResolverInterface $languages, Settings $settings ) {
		$this->languages = $languages;
		$this->settings  = $settings;
	}

	/**
	 * Reads one post.
	 *
	 * @param int $post_id Post ID.
	 * @return Article|null Null when the post does not exist.
	 */
	public function find( int $post_id ): ?Article {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return $this->to_article( $post );
	}

	/**
	 * Reads recently published posts in one language, newest first.
	 *
	 * @param string $language        Language code.
	 * @param int    $max_age_seconds How far back to look.
	 * @param int    $limit           Maximum posts.
	 * @return Article[]
	 */
	public function recent( string $language, int $max_age_seconds, int $limit ): array {
		$args = array(
			'post_type'              => $this->settings->post_types(),
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => max( 1, $limit ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => true,
			'date_query'             => array(
				array(
					'column'    => 'post_date_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', Clock::now() - $max_age_seconds ),
					'inclusive' => true,
				),
			),
		);

		$args = array_merge( $args, $this->languages->query_args( $language ) );

		return $this->languages->with_language(
			$language,
			function () use ( $args ): array {
				$query       = new \WP_Query( $args );
				$articles    = array();
				$skip_future = (bool) $this->settings->get( 'clock.skip_future', true );
				$now         = Clock::now();

				foreach ( $query->posts as $post ) {
					if ( ! $post instanceof \WP_Post ) {
						continue;
					}

					$article = $this->to_article( $post );

					// An article dated in the future joins the feed by itself once its time has come (Settings, Clock and time zone).
					if ( $skip_future && $article->published_ts() > $now ) {
						continue;
					}

					$articles[] = $article;
				}

				return $articles;
			}
		);
	}

	/**
	 * Converts a post to an Article.
	 *
	 * @param \WP_Post $post The post.
	 * @return Article
	 */
	private function to_article( \WP_Post $post ): Article {
		$author_id = (int) $post->post_author;
		$thumb_id  = (int) get_post_thumbnail_id( $post );
		$thumb     = $thumb_id ? wp_get_attachment_image_src( $thumb_id, 'full' ) : false;

		return new Article(
			array(
				'id'               => (int) $post->ID,
				/**
				 * Changes the title channels read, such as a title a person chose to use in feeds only (internal).
				 *
				 * @param string   $title The post's title without tags.
				 * @param \WP_Post $post  The post.
				 */
				'title'            => (string) apply_filters( 'estasoft_nd_internal_article_title', wp_strip_all_tags( (string) $post->post_title ), $post ),
				/**
				 * Changes the stored text channels read, such as adding a saved correction note at its end (internal).
				 *
				 * @param string   $content The post's stored text.
				 * @param \WP_Post $post    The post.
				 */
				'content'          => (string) apply_filters( 'estasoft_nd_internal_article_content', (string) $post->post_content, $post ),
				'permalink'        => (string) get_permalink( $post ),
				'language'         => $this->languages->language_of_post( (int) $post->ID ),
				'author_name'      => (string) get_the_author_meta( 'display_name', $author_id ),
				'author_url'       => (string) get_author_posts_url( $author_id ),
				'thumbnail_id'     => $thumb ? $thumb_id : 0,
				'thumbnail_url'    => $thumb ? (string) $thumb[0] : '',
				'thumbnail_width'  => $thumb ? (int) $thumb[1] : 0,
				'thumbnail_height' => $thumb ? (int) $thumb[2] : 0,
				'categories'       => $this->term_names( $post, 'category' ),
				'tags'             => $this->term_names( $post, 'post_tag' ),
				'published_ts'     => (int) strtotime( $post->post_date_gmt . ' UTC' ),
				'modified_ts'      => (int) strtotime( $post->post_modified_gmt . ' UTC' ),
				'post_type'        => (string) $post->post_type,
				'status'           => (string) $post->post_status,
				'author_id'        => $author_id,
				'protected'        => '' !== (string) $post->post_password,
				/**
				 * Gives the short text platforms print under the headline, such as one a person chose to use in feeds only (internal).
				 *
				 * @param string   $summary The summary; empty when none was chosen.
				 * @param \WP_Post $post    The post.
				 */
				'summary'          => (string) apply_filters( 'estasoft_nd_internal_article_summary', '', $post ),
			)
		);
	}

	/**
	 * @param \WP_Post $post     The post.
	 * @param string   $taxonomy Taxonomy slug.
	 * @return string[]
	 */
	private function term_names( \WP_Post $post, string $taxonomy ): array {
		$terms = get_the_terms( $post, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values(
			array_map(
				static function ( $term ): string {
					return (string) $term->name;
				},
				$terms
			)
		);
	}
}
