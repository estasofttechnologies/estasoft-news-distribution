<?php
/**
 * Checks an article each time it is saved.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Notes each article that is saved and, when the request is ending (`shutdown`), runs
 * the checks once for each and stores the result on the article. That is after the block
 * editor has set the featured image and the categories, so the check reads the article as it
 * now is, and the person saving never waits for it.
 *
 * The checks read this site's own database only. Nothing is fetched from another server,
 * so saving cannot be slowed or broken by a platform. Autosaves, revisions, imports, new
 * empty drafts, the trash and post types the site does not send are skipped, and no more
 * than 20 articles are checked in one request.
 */
final class CheckOnSaveModule implements ModuleInterface {

	private const LIMIT = 20;

	private Plugin $plugin;

	/**
	 * Articles saved during this request, by ID.
	 *
	 * @var array<int, true>
	 */
	private array $marked = array();

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'save_post', array( $this, 'mark' ), 99, 2 );
		add_action( 'shutdown', array( $this, 'flush' ), 5 );
	}

	/**
	 * `save_post`: remembers the article.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The post.
	 * @return void
	 */
	public function mark( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		$post    = $post instanceof \WP_Post ? $post : get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->applies( $post ) ) {
			return;
		}

		$this->marked[ $post_id ] = true;
	}

	/**
	 * Runs the checks for the articles saved in this request.
	 *
	 * @return int How many articles were checked.
	 */
	public function flush(): int {
		// Nothing saved, nothing to load. This also keeps a request that deletes the plugin (the files are gone by
		// `shutdown`) from asking the class loader for a class that is no longer there.
		if ( array() === $this->marked ) {
			return 0;
		}

		$checker = $this->plugin->make( ArticleChecker::class );
		$ids     = array_slice( array_keys( $this->marked ), 0, self::LIMIT );
		$done    = 0;

		$this->marked = array();

		foreach ( $ids as $post_id ) {
			$post = get_post( (int) $post_id );

			// It may have been trashed or removed later in the same request.
			if ( ! $post instanceof \WP_Post || ! $this->applies( $post ) ) {
				continue;
			}

			$checker->check( (int) $post_id, true );
			++$done;
		}

		return $done;
	}

	/**
	 * Whether a saved post is an article to check.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	private function applies( \WP_Post $post ): bool {
		if ( wp_is_post_autosave( $post ) || wp_is_post_revision( $post ) || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return false;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) ) {
			return false;
		}

		return in_array( $post->post_type, $this->plugin->settings()->post_types(), true );
	}
}
