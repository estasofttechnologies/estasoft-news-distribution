<?php
/**
 * Makes and removes the demo's sample articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Demo;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Twelve posts (four stories in English, Kurdish and Arabic), a featured picture for each, and three categories.
 *
 * What makes a sample safe:
 *
 * - Every post, picture and category carries the marker `_estasoft_nd_sample` (post meta, term meta). Removal
 *   reads the marker and deletes nothing without it, so a post of the person's with a similar title is left alone.
 * - A sample is saved as a draft and then set to published with a single update of its status. WordPress and
 *   other plugins therefore never see a "published" event for it: nothing is announced, no ping is scheduled, no
 *   social plugin shares it, and News Distribution queues nothing. Removal does the same in reverse before it deletes.
 * - Samples are dated within the last two days, so they enter the sitemaps' windows. The author is the person who
 *   started the demo.
 * - A sample's language is set through Polylang or WPML when one is active and has the language. Otherwise it is
 *   kept in `_estasoft_nd_sample_language` and answered through the `estasoft_nd_language_resolver` filter (see
 *   `Modules\DemoModule`).
 *
 * `create()` is safe to repeat: a sample that is already there is not made again.
 */
final class SampleArticles {

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether a translation plugin gives the site its languages.
	 *
	 * @return bool
	 */
	public function uses_translation_plugin(): bool {
		return in_array( $this->plugin->languages()->name(), array( 'polylang', 'wpml' ), true );
	}

	/**
	 * The languages the samples are written in, and the code the site knows each by.
	 *
	 * Without a translation plugin all three. With one, only the languages it has; when it has none of them, the
	 * English set in the site's default language.
	 *
	 * @return array<string, string> Sample language => the site's code for it.
	 */
	public function languages(): array {
		if ( ! $this->uses_translation_plugin() ) {
			return array_combine( SampleContent::languages(), SampleContent::languages() );
		}

		$site = array_map( 'strval', $this->plugin->languages()->languages() );
		$out  = array();

		foreach ( SampleContent::languages() as $code ) {
			foreach ( $site as $slug ) {
				if ( $slug === $code || Catalog::primary( $slug ) === $code ) {
					$out[ $code ] = $slug;
					break;
				}
			}
		}

		if ( array() === $out ) {
			$out['en'] = (string) $this->plugin->languages()->default_language();
		}

		return $out;
	}

	/**
	 * The keys of every sample this run would make, such as "festival-en".
	 *
	 * @return string[]
	 */
	public function keys(): array {
		$keys = array();

		foreach ( array_keys( SampleContent::stories() ) as $story ) {
			foreach ( array_keys( $this->languages() ) as $code ) {
				$keys[] = $story . '-' . $code;
			}
		}

		return $keys;
	}

	/**
	 * Everything that carries the marker: posts, pictures.
	 *
	 * @return array<int, array{id: int, type: string, key: string}>
	 */
	public function marked(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_type AS type, m.meta_value AS marker FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = %s ORDER BY p.ID ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Demo::META
			),
			ARRAY_A
		);
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'id'   => (int) $row['id'],
				'type' => (string) $row['type'],
				'key'  => (string) $row['marker'],
			);
		}

		return $out;
	}

	/**
	 * How many samples are in the site now.
	 *
	 * @return array{articles: int, images: int}
	 */
	public function counts(): array {
		$articles = 0;
		$images   = 0;

		foreach ( $this->marked() as $row ) {
			if ( 'attachment' === $row['type'] ) {
				++$images;
			} else {
				++$articles;
			}
		}

		return array(
			'articles' => $articles,
			'images'   => $images,
		);
	}

	/**
	 * Makes the samples that are not there yet.
	 *
	 * @param int $author The person the samples belong to.
	 * @return array{ids: int[], terms: int[], created: int, images: int, languages: string[]} IDs of every post and picture made now, the categories made now, how many posts were made, how many pictures, and the languages used.
	 */
	public function create( int $author ): array {
		$have      = array();
		$out       = array(
			'ids'       => array(),
			'terms'     => array(),
			'created'   => 0,
			'images'    => 0,
			'languages' => array_keys( $this->languages() ),
		);
		$languages = $this->languages();
		$terms     = $this->categories( $out['terms'] );
		$now       = Clock::now();
		$checker   = $this->plugin->make( ArticleChecker::class );
		$by_plugin = $this->uses_translation_plugin();

		$orphans = array();

		foreach ( $this->marked() as $row ) {
			if ( 'attachment' === $row['type'] ) {
				$orphans[ $row['key'] ][] = $row['id'];
				continue;
			}

			$have[ $row['key'] ] = true;
		}

		$offsets = array_flip( array_keys( $languages ) );

		foreach ( SampleContent::stories() as $story_key => $story ) {
			foreach ( $languages as $code => $slug ) {
				$key = $story_key . '-' . $code;

				if ( isset( $have[ $key ] ) ) {
					continue;
				}

				// A picture whose post was deleted by hand is left over; the new post gets a new one.
				foreach ( $orphans[ $key ] ?? array() as $stale ) {
					wp_delete_attachment( $stale, true );
				}

				$flag      = (string) ( $story['flags'][ $code ] ?? '' );
				$timestamp = $now - ( (int) $story['minutes'] + 7 * (int) $offsets[ $code ] ) * MINUTE_IN_SECONDS;
				$title     = SampleContent::PREFIX . $story['titles'][ $code ];
				$post_id   = $this->insert( $key, $title, $this->blocks( $story['body'][ $code ], 'empty_paragraph' === $flag ), $author, $timestamp );

				if ( $post_id < 1 ) {
					continue;
				}

				$out['ids'][] = $post_id;
				++$out['created'];

				$by = $by_plugin && $this->set_language( $post_id, $slug ) ? 'plugin' : 'own';

				update_post_meta( $post_id, Demo::META_LANG, $slug );
				update_post_meta( $post_id, Demo::META_LANG_BY, $by );

				$this->publish( $post_id );

				if ( isset( $terms[ $story['category'] ] ) ) {
					wp_set_post_terms( $post_id, array( $terms[ $story['category'] ] ), 'category' );
				}

				if ( SampleImages::available() ) {
					$picture = SampleImages::add(
						$key,
						$post_id,
						$author,
						$story['color'],
						'alt_missing' === $flag ? '' : (string) $story['alt'][ $code ],
						$title,
						$timestamp
					);

					if ( $picture > 0 ) {
						set_post_thumbnail( $post_id, $picture );
						$out['ids'][] = $picture;
						++$out['images'];
					}
				}

				$this->plugin->article_ids()->ensure( $post_id );
				$checker->check( $post_id );
			}
		}

		return $out;
	}

	/**
	 * Removes every sample, and nothing else.
	 *
	 * Posts go first (set back to a draft so nothing hears of a deletion), then pictures, then the categories the
	 * demo made, but only a category nobody else uses.
	 *
	 * @return array{articles: int, images: int, terms: int}
	 */
	public function remove(): array {
		$out = array(
			'articles' => 0,
			'images'   => 0,
			'terms'    => 0,
		);

		$rows     = $this->marked();
		$pictures = array();

		foreach ( $rows as $row ) {
			if ( 'attachment' === $row['type'] ) {
				$pictures[] = $row['id'];
				continue;
			}

			// The marker is read again, so only a post that carries it is ever deleted. Samples are posts; a page or other
			// type that carries the marker (copied there by a duplicator or an import) is left alone.
			if ( '' === (string) get_post_meta( $row['id'], Demo::META, true ) || 'post' !== $row['type'] ) {
				continue;
			}

			$this->unpublish( $row['id'] );

			if ( false !== wp_delete_post( $row['id'], true ) ) {
				++$out['articles'];
			}
		}

		foreach ( $pictures as $id ) {
			if ( '' === (string) get_post_meta( $id, Demo::META, true ) ) {
				continue;
			}

			// A picture that a real article now uses as its image stays; it only stops being a sample.
			if ( $this->used_by_a_real_post( $id ) ) {
				delete_post_meta( $id, Demo::META );
				continue;
			}

			if ( false !== wp_delete_attachment( $id, true ) ) {
				++$out['images'];
			}
		}

		$out['terms'] = $this->remove_categories();

		return $out;
	}

	/**
	 * Whether a post that is not a sample uses the picture as its featured image.
	 *
	 * @param int $attachment_id The picture.
	 * @return bool
	 */
	private function used_by_a_real_post( int $attachment_id ): bool {
		global $wpdb;

		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $attachment_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( is_array( $post_ids ) ? $post_ids : array() as $post_id ) {
			if ( '' === (string) get_post_meta( (int) $post_id, Demo::META, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The categories of the samples, made when they are not there yet.
	 *
	 * @param int[] $made Receives the IDs of the categories made now.
	 * @return array<string, int> Key => term ID.
	 */
	private function categories( array &$made ): array {
		$ids = array();

		foreach ( SampleContent::categories() as $key => $name ) {
			$term = term_exists( $name, 'category' );

			if ( is_array( $term ) ) {
				$ids[ $key ] = (int) $term['term_id'];
				continue;
			}

			$new = wp_insert_term( $name, 'category' );

			if ( is_wp_error( $new ) ) {
				continue;
			}

			$ids[ $key ] = (int) $new['term_id'];
			$made[]      = (int) $new['term_id'];

			add_term_meta( (int) $new['term_id'], Demo::META, '1', true );
		}

		return $ids;
	}

	/**
	 * Removes the categories that carry the marker and that no post uses.
	 *
	 * A category someone has started to use stays, and loses its marker, so it is theirs now.
	 *
	 * @return int How many were removed.
	 */
	private function remove_categories(): int {
		$removed = 0;
		$terms   = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'fields'     => 'ids',
				'meta_key'   => Demo::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A handful of terms, read once when the demo ends.
			)
		);

		foreach ( is_array( $terms ) ? $terms : array() as $term_id ) {
			$term_id = (int) $term_id;

			if ( array() !== get_objects_in_term( $term_id, 'category' ) ) {
				delete_term_meta( $term_id, Demo::META );
				continue;
			}

			if ( true === wp_delete_term( $term_id, 'category' ) ) {
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Saves one post as a draft with the marker.
	 *
	 * @param string $key       The sample's key.
	 * @param string $title     The title, with the prefix.
	 * @param string $content   The body as blocks.
	 * @param int    $author    The author.
	 * @param int    $timestamp Unix time of the date.
	 * @return int The post ID, or 0.
	 */
	private function insert( string $key, string $title, string $content, int $author, int $timestamp ): int {
		$id = wp_insert_post(
			array(
				'post_type'      => 'post',
				'post_status'    => 'draft',
				'post_title'     => $title,
				'post_name'      => 'sample-' . $key,
				'post_content'   => $content,
				'post_author'    => $author,
				'post_date'      => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) ),
				'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'to_ping'        => '',
				'meta_input'     => array( Demo::META => $key ),
			),
			true
		);

		return is_int( $id ) && $id > 0 ? $id : 0;
	}

	/**
	 * Sets every sample post to a draft, or back to published, without announcing either.
	 *
	 * Used while the plugin is switched off: nothing is then hiding the samples from visitors, so they are drafts
	 * until the plugin is on again. Pictures keep their state, since they are only shown through a post.
	 *
	 * @param bool $published True to publish them, false to make them drafts.
	 * @return int How many posts were changed.
	 */
	public function set_published( bool $published ): int {
		$changed = 0;

		foreach ( $this->marked() as $row ) {
			if ( 'attachment' === $row['type'] || '' === (string) get_post_meta( $row['id'], Demo::META, true ) ) {
				continue;
			}

			$now = get_post_status( $row['id'] );

			if ( $published && 'draft' === $now ) {
				$this->publish( $row['id'] );
				++$changed;
			} elseif ( ! $published && 'publish' === $now ) {
				$this->unpublish( $row['id'] );
				++$changed;
			}
		}

		return $changed;
	}

	/**
	 * Sets a post to published with one update of its status, so no "published" event is announced.
	 *
	 * @param int $post_id The post.
	 * @return void
	 */
	private function publish( int $post_id ): void {
		$this->flip( $post_id, 'publish' );
	}

	/**
	 * Sets a post back to a draft the same way, before it is deleted.
	 *
	 * @param int $post_id The post.
	 * @return void
	 */
	private function unpublish( int $post_id ): void {
		$this->flip( $post_id, 'draft' );
	}

	/**
	 * Changes the status in the database, then clears what WordPress remembers about the post.
	 *
	 * @param int    $post_id The post.
	 * @param string $status  publish or draft.
	 * @return void
	 */
	private function flip( int $post_id, string $status ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See the class description: a status change that announces nothing.

		clean_post_cache( $post_id );

		if ( function_exists( '_count_posts_cache_key' ) ) {
			wp_cache_delete( _count_posts_cache_key( 'post' ), 'counts' );
		}
	}

	/**
	 * The body as paragraph blocks, with an empty one after the first when asked.
	 *
	 * @param string[] $paragraphs The paragraphs.
	 * @param bool     $with_empty Whether to add an empty paragraph after the first.
	 * @return string
	 */
	private function blocks( array $paragraphs, bool $with_empty ): string {
		$blocks = array();

		foreach ( array_values( $paragraphs ) as $index => $text ) {
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";

			if ( $with_empty && 0 === $index ) {
				$blocks[] = "<!-- wp:paragraph -->\n<p></p>\n<!-- /wp:paragraph -->";
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Gives a post its language through the translation plugin.
	 *
	 * @param int    $post_id The post.
	 * @param string $slug    The plugin's code for the language.
	 * @return bool Whether the plugin took it.
	 */
	private function set_language( int $post_id, string $slug ): bool {
		if ( 'polylang' === $this->plugin->languages()->name() ) {
			if ( ! function_exists( 'pll_set_post_language' ) ) {
				return false;
			}

			pll_set_post_language( $post_id, $slug );

			return true;
		}

		if ( ! has_action( 'wpml_set_element_language_details' ) ) {
			return false;
		}

		// WPML's own documented action for giving a post a language.
		$element = array(
			'element_id'           => $post_id,
			'element_type'         => 'post_post',
			'trid'                 => false,
			'language_code'        => $slug,
			'source_language_code' => null,
		);

		do_action( 'wpml_set_element_language_details', $element ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action.

		return true;
	}
}
