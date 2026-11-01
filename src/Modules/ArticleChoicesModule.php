<?php
/**
 * Makes the per-article channel switches reach the feeds and the screens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\ArticleChoices;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The channels never read a switch. This module answers the internal questions the core asks, so one
 * article switched off for one channel is handled in one place:
 *
 * - `estasoft_nd_internal_feed_articles`: which articles a sitemap or feed carries. An article switched
 *   off for the channel is left out of its document, the reports that count a feed's items, and the
 *   named partner feeds, which are the open-readers feed with a key and follow its switch as they follow its hold.
 * - `estasoft_nd_internal_article_results`: what the lists say each channel did. A channel left out by a
 *   choice is Off, with the reason, and is never a skip or a failure.
 *
 * It also watches the writes of `_estasoft_nd_options` (the editor panel saves through the post meta). When
 * the set of switched-off channels changes, the feed cache is dropped, so a feed never keeps serving an
 * article that was just switched off, or leaves out one that was switched on again.
 */
final class ArticleChoicesModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * The channels that were switched off before a write, by article.
	 *
	 * @var array<int, string[]>
	 */
	private array $before = array();

	/**
	 * Articles whose saved check is read again when the request ends, because a choice about them changed.
	 *
	 * @var array<int, true>
	 */
	private array $recheck = array();

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'estasoft_nd_internal_feed_articles', array( $this, 'feed_articles' ), 15, 2 );
		add_filter( 'estasoft_nd_internal_article_results', array( $this, 'results' ), 20, 2 );

		add_action( 'update_postmeta', array( $this, 'remember' ), 10, 3 );
		add_action( 'delete_post_meta', array( $this, 'remember_delete' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'added' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'updated' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $this, 'deleted' ), 10, 3 );
	}

	/**
	 * Takes out of a feed or sitemap the articles switched off for its channel.
	 *
	 * @param mixed $articles The articles.
	 * @param mixed $channel  The channel the document is for.
	 * @return mixed
	 */
	public function feed_articles( $articles, $channel = null ) {
		if ( ! is_array( $articles ) || ! $channel instanceof ChannelInterface ) {
			return $articles;
		}

		return $this->choices()->filter( $articles, $channel );
	}

	/**
	 * Says Off, with the reason, where a channel was switched off for the article.
	 *
	 * A pulled channel is Off whatever else is true of it, unless the article was taken down. A pushed channel is
	 * Off when its delivery was skipped because of the switch, or when nothing was ever sent to it; a delivery that
	 * did happen before the switch keeps its own word.
	 *
	 * @param mixed $out  Result per channel.
	 * @param mixed $post The post.
	 * @return mixed
	 */
	public function results( $out, $post = null ) {
		if ( ! is_array( $out ) || ! $post instanceof \WP_Post ) {
			return $out;
		}

		$off = $this->choices()->left_out( (int) $post->ID );

		if ( array() === $off ) {
			return $out;
		}

		$editor_skip = __( 'Turned off for this article in the editor.', 'estasoft-news-distribution' );

		foreach ( $off as $id ) {
			if ( ! isset( $out[ $id ] ) ) {
				continue;
			}

			$channel = $this->plugin->registry()->get( $id );

			if ( null === $channel ) {
				continue;
			}

			$status  = (string) $out[ $id ]['status'];
			$message = (string) $out[ $id ]['message'];

			if ( ChannelInterface::MODE_PULLED === $channel->mode() ) {
				// A sitemap that other channels share still carries the article until each of them is switched off.
				if ( ! $this->choices()->leaves_out( (int) $post->ID, $channel ) ) {
					continue;
				}

				$changeable = array( Status::IN_FEED, Status::QUEUED, Status::OFF, Status::SKIPPED );
			} else {
				$changeable = array( Status::OFF );

				if ( Status::SKIPPED === $status && 0 === strpos( $message, $editor_skip ) ) {
					$changeable[] = Status::SKIPPED;
				}
			}

			if ( ! in_array( $status, $changeable, true ) ) {
				continue;
			}

			$out[ $id ]['status']  = Status::OFF;
			$out[ $id ]['message'] = ArticleChoices::message();
		}

		return $out;
	}

	/**
	 * `update_postmeta`: keeps the switched-off channels as they were before the write.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public function remember( $meta_id = 0, $object_id = 0, $meta_key = '' ): void {
		unset( $meta_id );

		if ( ArticleOptions::META === $meta_key ) {
			$this->before[ (int) $object_id ] = $this->choices()->left_out( (int) $object_id );
		}
	}

	/**
	 * `delete_post_meta`: the same, for a deletion.
	 *
	 * @param mixed $meta_ids  Meta IDs.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public function remember_delete( $meta_ids = array(), $object_id = 0, $meta_key = '' ): void {
		$this->remember( 0, $object_id, $meta_key );
		unset( $meta_ids );
	}

	/**
	 * `added_post_meta`: a first write of the options.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $value     The value.
	 * @return void
	 */
	public function added( $meta_id = 0, $object_id = 0, $meta_key = '', $value = null ): void {
		unset( $meta_id );

		if ( ArticleOptions::META === $meta_key && array() !== ArticleChoices::off_ids( $value ) ) {
			$this->choices()->changed();
			$this->check_again( (int) $object_id );
		}
	}

	/**
	 * `updated_post_meta`: a change of the options.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $value     The new value.
	 * @return void
	 */
	public function updated( $meta_id = 0, $object_id = 0, $meta_key = '', $value = null ): void {
		unset( $meta_id );

		if ( ArticleOptions::META !== $meta_key ) {
			return;
		}

		$post_id = (int) $object_id;
		$was     = $this->before[ $post_id ] ?? array();

		unset( $this->before[ $post_id ] );

		if ( ArticleChoices::off_ids( $value ) !== $was ) {
			$this->choices()->changed();
			$this->check_again( $post_id );
		}
	}

	/**
	 * `deleted_post_meta`: the options were removed, so every switch is on again.
	 *
	 * @param mixed $meta_ids  Meta IDs.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @return void
	 */
	public function deleted( $meta_ids = array(), $object_id = 0, $meta_key = '' ): void {
		unset( $meta_ids );

		if ( ArticleOptions::META !== $meta_key ) {
			return;
		}

		$post_id = (int) $object_id;
		$was     = $this->before[ $post_id ] ?? array();

		unset( $this->before[ $post_id ] );

		if ( array() !== $was ) {
			$this->choices()->changed();
			$this->check_again( $post_id );
		}
	}

	/**
	 * Asks for the saved check of an article to be made again when the request ends.
	 *
	 * A channel switched off for an article is not in its check, so the totals, the gate and the lists that read the saved
	 * check change with the switch. Only an article that already has a saved check is made again, and the hook is added
	 * only when there is something to do, so a request that changes no choice loads nothing at shutdown.
	 *
	 * @param int $post_id Article ID.
	 * @return void
	 */
	private function check_again( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}

		// No flush waiting means any list left over belongs to a request that never reached its end.
		if ( false === has_action( 'shutdown', array( $this, 'flush_rechecks' ) ) ) {
			$this->recheck = array();

			add_action( 'shutdown', array( $this, 'flush_rechecks' ), 8 );
		}

		$this->recheck[ $post_id ] = true;
	}

	/**
	 * `shutdown`: makes the saved check of each article whose choices changed.
	 *
	 * @return int How many were made again.
	 */
	public function flush_rechecks(): int {
		$ids           = array_slice( array_keys( $this->recheck ), 0, 20 );
		$this->recheck = array();
		$made          = 0;

		if ( array() === $ids ) {
			return 0;
		}

		$checker = $this->plugin->make( ArticleChecker::class );

		foreach ( $ids as $post_id ) {
			if ( null !== $checker->stored( (int) $post_id ) && null !== $checker->check( (int) $post_id ) ) {
				++$made;
			}
		}

		return $made;
	}

	/**
	 * The service.
	 *
	 * @return ArticleChoices
	 */
	private function choices(): ArticleChoices {
		return $this->plugin->make( ArticleChoices::class );
	}
}
