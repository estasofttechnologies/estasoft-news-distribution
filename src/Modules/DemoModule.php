<?php
/**
 * Demo mode, the parts that run on every request: who sees the samples, and what language they are in.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Demo\Demo;
use Estasoft\NewsDistribution\Core\Demo\SampleArticles;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The samples exist only while the demo option `estasoft_nd_demo` does, and every hook here starts with that
 * check, so a site that never used the demo pays one cached option read.
 *
 * - **Hidden from visitors.** A query that comes from someone who may not view News Distribution (a visitor, a
 *   subscriber, an author) never returns a sample post or picture: the front page, archives, search, the site's
 *   own feeds, the REST list of posts and media, WordPress's sitemaps and the "next article" links. Reading one
 *   sample by its REST address (`/wp/v2/posts/12`, `/wp/v2/media/13`) is answered "not found", as a post that does not
 *   exist is. A person who may view News Distribution sees them all, marked "Sample:".
 * - **Not advertised.** While demo mode is on, `robots.txt` does not list the plugin's own sitemap addresses
 *   (`Modules\RobotsModule`), because they answer 503 until the demo ends.
 * - **Never in a served feed.** While demo mode is on, a feed is not served at all. If someone turns the demo flag
 *   off by hand and the samples are still there, they are taken out of every feed and sitemap document, so a
 *   platform never reads a sample article as news. (Previews that a person asks for while demo mode is on do show them.)
 * - **While the plugin is off.** Deactivating the plugin makes the samples drafts (nothing is deleted), because the
 *   plugin is what hides them; activating it publishes them again, on the first request after. Uninstalling with
 *   "delete data" deletes them (`uninstall.php`).
 * - **Languages.** With no translation plugin the site has one language, so while samples exist the site also
 *   has the languages of the samples (English, Kurdish and Arabic), a post marked as a sample answers with its
 *   own language, and a query for one language is told how to find them. With Polylang or WPML nothing is added:
 *   the samples were given a language through the plugin, in the languages it has.
 *
 * The first-run choice, the banner and the exit button are in `SetupModule` and `EnvironmentModule`; the hold of
 * pushes, feeds and sitemaps is in `DeliveryHoldsModule`.
 */
final class DemoModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'pre_get_posts', array( $this, 'hide_from_visitors' ) );
		add_filter( 'get_previous_post_where', array( $this, 'hide_from_adjacent' ) );
		add_filter( 'get_next_post_where', array( $this, 'hide_from_adjacent' ) );
		add_filter( 'rest_request_before_callbacks', array( $this, 'hide_from_rest_reads' ), 10, 3 );
		add_filter( 'estasoft_nd_internal_feed_articles', array( $this, 'keep_samples_out_of_feeds' ), 5, 1 );
		add_action( 'deactivate_' . plugin_basename( ESTASOFT_ND_FILE ), array( $this, 'hold_back' ), 5, 1 );
		add_action( 'init', array( $this, 'resume' ), 1 );
		add_filter( 'estasoft_nd_language_resolver', array( $this, 'language_of_sample' ), 10, 2 );
		add_filter( 'estasoft_nd_internal_site_languages', array( $this, 'site_languages' ) );
		add_filter( 'estasoft_nd_internal_language_query_args', array( $this, 'language_args' ), 10, 2 );
	}

	/**
	 * Keeps the samples out of a query asked by someone who may not view them.
	 *
	 * @param mixed $query The query.
	 * @return void
	 */
	public function hide_from_visitors( $query ): void {
		$ids = $this->hidden_ids_for_this_person();

		if ( ! $query instanceof \WP_Query || array() === $ids ) {
			return;
		}

		$already = $query->get( 'post__not_in' );
		$already = is_array( $already ) ? $already : array();

		$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', $already ), $ids ) ) ) );
	}

	/**
	 * Keeps the samples out of "previous article" and "next article".
	 *
	 * @param mixed $where The WHERE clause WordPress built.
	 * @return mixed
	 */
	public function hide_from_adjacent( $where ) {
		$ids = $this->hidden_ids_for_this_person();

		if ( ! is_string( $where ) || array() === $ids ) {
			return $where;
		}

		return $where . ' AND p.ID NOT IN (' . implode( ',', array_map( 'absint', $ids ) ) . ')';
	}

	/**
	 * Answers "not found" to a read of one sample post or picture by its REST address, for someone who may not view it.
	 *
	 * The lists are filtered by the query (`hide_from_visitors`); one item is fetched by ID and never goes through a
	 * query, so it is answered here. The answer is the one WordPress gives for an ID that does not exist.
	 *
	 * @param mixed $response The response so far, or an error.
	 * @param mixed $handler  The handler of the route.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public function hide_from_rest_reads( $response, $handler = null, $request = null ) {
		unset( $handler );

		if ( ! $request instanceof \WP_REST_Request || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $response;
		}

		if ( 1 !== preg_match( '#^/wp/v2/[a-z0-9_-]+/(\d+)(?:/|$)#i', $request->get_route(), $found ) ) {
			return $response;
		}

		$ids = $this->hidden_ids_for_this_person();

		if ( array() === $ids || ! in_array( (int) $found[1], $ids, true ) ) {
			return $response;
		}

		return new \WP_Error(
			'rest_post_invalid_id',
			__( 'Invalid post ID.', 'estasoft-news-distribution' ), // The words WordPress uses, so a sample answers as a post that does not exist does.
			array( 'status' => 404 )
		);
	}

	/**
	 * Makes the samples drafts when the plugin is deactivated, on every site of the network when it is deactivated for all.
	 *
	 * @param mixed $network_wide Whether the plugin is being deactivated for the whole network.
	 * @return void
	 */
	public function hold_back( $network_wide = false ): void {
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				$this->hold_back_here();
				restore_current_blog();
			}

			return;
		}

		$this->hold_back_here();
	}

	/**
	 * Does it for the site that is current.
	 *
	 * @return void
	 */
	private function hold_back_here(): void {
		if ( $this->active() ) {
			( new Demo( $this->plugin ) )->hold_back();
		}
	}

	/**
	 * Publishes the samples again, once, when the plugin is on again.
	 *
	 * @return void
	 */
	public function resume(): void {
		if ( $this->active() ) {
			( new Demo( $this->plugin ) )->resume();
		}
	}

	/**
	 * Takes the samples out of a feed or sitemap document when demo mode is not what holds the feeds back.
	 *
	 * @param mixed $articles The articles of one document (Article[]).
	 * @return mixed
	 */
	public function keep_samples_out_of_feeds( $articles ) {
		if ( ! is_array( $articles ) || ! $this->active() || ( new Demo( $this->plugin ) )->is_on() ) {
			return $articles;
		}

		$ids = ( new Demo( $this->plugin ) )->hidden_ids();

		if ( array() === $ids ) {
			return $articles;
		}

		return array_values(
			array_filter(
				$articles,
				static function ( $article ) use ( $ids ): bool {
					return ! ( $article instanceof Article ) || ! in_array( $article->id(), $ids, true );
				}
			)
		);
	}

	/**
	 * The language of a post the demo made without a translation plugin.
	 *
	 * @param mixed $language The language found so far.
	 * @param mixed $post_id  The post.
	 * @return mixed
	 */
	public function language_of_sample( $language, $post_id = 0 ) {
		if ( ! $this->active() || 'own' !== (string) get_post_meta( (int) $post_id, Demo::META_LANG_BY, true ) ) {
			return $language;
		}

		$own = (string) get_post_meta( (int) $post_id, Demo::META_LANG, true );

		return '' !== $own ? $own : $language;
	}

	/**
	 * Adds the languages of the samples to a site with no translation plugin.
	 *
	 * @param mixed $languages The language codes the site has.
	 * @return mixed
	 */
	public function site_languages( $languages ) {
		if ( ! is_array( $languages ) || ! $this->own_languages() ) {
			return $languages;
		}

		foreach ( ( new Demo( $this->plugin ) )->state()['languages'] as $code ) {
			if ( ! in_array( $code, $languages, true ) ) {
				$languages[] = $code;
			}
		}

		return $languages;
	}

	/**
	 * How to find the posts of one language when the language is in a sample's own meta.
	 *
	 * The site's default language also takes every post that is not a sample, since those carry no marker.
	 *
	 * @param mixed $args     The query arguments so far.
	 * @param mixed $language The language code.
	 * @return mixed
	 */
	public function language_args( $args, $language = '' ) {
		if ( ! is_array( $args ) || ! is_string( $language ) || '' === $language || ! $this->own_languages() ) {
			return $args;
		}

		$clause = array(
			'key'   => Demo::META_LANG,
			'value' => $language,
		);

		if ( $language === $this->plugin->languages()->default_language() ) {
			$clause = array(
				'relation' => 'OR',
				array(
					'key'     => Demo::META_LANG,
					'compare' => 'NOT EXISTS',
				),
				$clause,
			);
		}

		$meta   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$meta[] = $clause;

		$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Only while the demo is on, and one clause.

		return $args;
	}

	/**
	 * Whether this site has used the demo and still has its record.
	 *
	 * @return bool
	 */
	private function active(): bool {
		return false !== get_option( Demo::OPTION, false );
	}

	/**
	 * Whether the samples are in languages kept in their own meta: samples exist and no translation plugin is in use.
	 *
	 * @return bool
	 */
	private function own_languages(): bool {
		return $this->active() && ( new Demo( $this->plugin ) )->has_samples() && ! ( new SampleArticles( $this->plugin ) )->uses_translation_plugin();
	}

	/**
	 * The IDs to hide from the person asking, or none when they may view News Distribution.
	 *
	 * @return int[]
	 */
	private function hidden_ids_for_this_person(): array {
		if ( ! $this->active() ) {
			return array();
		}

		$ids = ( new Demo( $this->plugin ) )->hidden_ids();

		if ( array() === $ids || $this->plugin->access()->can( Access::VIEW ) ) {
			return array();
		}

		return $ids;
	}
}
