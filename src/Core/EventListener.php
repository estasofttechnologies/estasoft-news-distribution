<?php
/**
 * Turns WordPress post lifecycle hooks into one normalized event.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Core\Language\LanguageResolverInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to status transitions and deletions, and fires `estasoft_nd_post_event`.
 *
 * It uses transition_post_status instead of save_post because that hook says
 * what changed (published, updated, unpublished) and not only that a save ran.
 * It never calls out: the work happens later, in the queue.
 */
final class EventListener {

	private Settings $settings;
	private LanguageResolverInterface $languages;
	private ArticleId $ids;

	/**
	 * Addresses captured while an article was still public, by post ID.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $public_state = array();

	/**
	 * Creates the listener.
	 *
	 * @param Settings                  $settings  Plugin settings.
	 * @param LanguageResolverInterface $languages Language lookups.
	 * @param ArticleId                 $ids       Stable article IDs.
	 */
	public function __construct( Settings $settings, LanguageResolverInterface $languages, ArticleId $ids ) {
		$this->settings  = $settings;
		$this->languages = $languages;
		$this->ids       = $ids;
	}

	/**
	 * Hooks into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_post_update', array( $this, 'capture_public_state' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'capture_public_state' ), 10, 1 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'on_saved' ), 10, 4 );
	}

	/**
	 * Maps a status change to an event type.
	 *
	 * @param string $new_status New post status.
	 * @param string $old_status Old post status.
	 * @return string|null Event type, or null when the change does not affect public content.
	 */
	public static function type_for_transition( string $new_status, string $old_status ): ?string {
		if ( 'publish' === $new_status ) {
			return 'publish' === $old_status ? Event::UPDATE : Event::PUBLISH;
		}

		if ( 'publish' === $old_status ) {
			return Event::UNPUBLISH;
		}

		return null;
	}

	/**
	 * Remembers the address and title of a published article before an edit or
	 * a trashing changes them, so a removal can still name the old address.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function capture_public_state( $post_id ): void {
		$post = get_post( (int) $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || ! $this->is_supported( $post ) ) {
			return;
		}

		$this->public_state[ (int) $post->ID ] = array(
			'url'   => (string) get_permalink( $post ),
			'title' => wp_strip_all_tags( (string) $post->post_title ),
		);
	}

	/**
	 * Handles transition_post_status.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ! $this->is_supported( $post ) ) {
			return;
		}

		$type = self::type_for_transition( (string) $new_status, (string) $old_status );

		if ( null !== $type ) {
			$this->emit( $type, $post );
		}
	}

	/**
	 * Handles wp_after_insert_post: tells the plugin when a published article keeps its ID and gets a new address.
	 *
	 * This hook, not `post_updated`, because the categories, the language and the meta that make up an address are
	 * saved after `post_updated` in the block editor's save. The old address was captured before anything changed
	 * (`capture_public_state`). Nothing is recorded for a draft, for the save that publishes the article, for a post
	 * behind a password, or when the address is the same (plain permalinks never change with the slug).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The post as saved.
	 * @param bool          $update  Whether this was an update.
	 * @param \WP_Post|null $before  The post before the save.
	 * @return void
	 */
	public function on_saved( $post_id, $post = null, $update = false, $before = null ): void {
		$post_id = (int) $post_id;
		$state   = $this->public_state[ $post_id ] ?? array();

		unset( $this->public_state[ $post_id ] );

		if ( ! $update || ! $post instanceof \WP_Post || ! $before instanceof \WP_Post || ! $this->is_supported( $post ) ) {
			return;
		}

		if ( 'publish' !== $before->post_status || 'publish' !== $post->post_status || '' !== (string) $post->post_password || '' !== (string) $before->post_password ) {
			return;
		}

		$old = isset( $state['url'] ) ? (string) $state['url'] : (string) get_permalink( $before );
		$new = (string) get_permalink( $post );

		if ( '' === $old || '' === $new || $old === $new ) {
			return;
		}

		/**
		 * A published article got a new address and kept its ID (internal).
		 *
		 * @param int    $post_id  Post ID.
		 * @param string $old      The address before the save.
		 * @param string $new      The address now.
		 * @param bool   $redirect Whether WordPress sends the old address to the new one by itself: the slug changed.
		 */
		do_action( 'estasoft_nd_internal_address_changed', $post_id, $old, $new, $before->post_name !== $post->post_name && ! is_post_type_hierarchical( $post->post_type ) );
	}

	/**
	 * Handles before_delete_post. Trashed posts already fired an unpublish event,
	 * so only posts deleted straight from the published state matter here.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    The post, passed by WordPress 5.5 and later.
	 * @return void
	 */
	public function on_delete( $post_id, $post = null ): void {
		$post = $post instanceof \WP_Post ? $post : get_post( (int) $post_id );

		if ( $post instanceof \WP_Post && 'publish' === $post->post_status && $this->is_supported( $post ) ) {
			$this->capture_public_state( (int) $post->ID );
			$this->emit( Event::DELETE, $post );
		}
	}

	/**
	 * Whether the post's type is one the site chose.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	private function is_supported( \WP_Post $post ): bool {
		return in_array( $post->post_type, $this->settings->post_types(), true );
	}

	/**
	 * Builds and fires the event.
	 *
	 * @param string   $type Event type.
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	private function emit( string $type, \WP_Post $post ): void {
		// A post behind a password is not public: nothing about it goes to a feed, a sitemap or a platform.
		if ( '' !== (string) $post->post_password && in_array( $type, array( Event::PUBLISH, Event::UPDATE ), true ) ) {
			return;
		}

		$post_id  = (int) $post->ID;
		$language = $this->languages->language_of_post( $post_id );
		$snapshot = array();

		if ( in_array( $type, array( Event::PUBLISH, Event::UPDATE ), true ) ) {
			$this->ids->ensure( $post_id );
		} else {
			$snapshot = $this->public_state[ $post_id ] ?? array(
				'url'   => (string) get_permalink( $post ),
				'title' => wp_strip_all_tags( (string) $post->post_title ),
			);
			Removed::remember( $post_id, $snapshot + array( 'language' => $language ) );
		}

		$event = new Event( $type, $post_id, $language, null, $snapshot );

		/**
		 * Fires when a supported post is published, updated, unpublished or deleted.
		 *
		 * @param Event $event The normalized event.
		 */
		do_action( 'estasoft_nd_post_event', $event );
	}
}
