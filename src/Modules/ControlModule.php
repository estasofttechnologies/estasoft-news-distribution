<?php
/**
 * Makes holds, release plans and takedowns take effect.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\SmartNews\Removals;
use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Control\Bulk;
use Estasoft\NewsDistribution\Core\Control\Waiting;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Fixes;
use Estasoft\NewsDistribution\Core\Hold;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Core\Takedown;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The channels never read a hold, a plan or a takedown. This module answers the
 * internal questions the core asks, so the three stay out of every channel:
 *
 * - `estasoft_nd_internal_delivery_hold`: is a push held, and until when? A hold or
 *   an exclusive without an end keeps the push out of the queue (the delivery log
 *   says Waiting with no time); a plan with a time queues it for that time. A
 *   taken-down article is never sent. Removal notices are never held.
 * - `estasoft_nd_internal_delivery_delay`: how long a push waits.
 * - `estasoft_nd_internal_feed_articles`: which articles a feed or sitemap carries.
 * - `estasoft_nd_internal_article_results`: what the lists say each channel did.
 * - `estasoft_nd_internal_send_now`: Send now takes the plan's wait out of the way.
 *
 * It also watches writes of `_estasoft_nd_options` that did not come from the
 * Hold service (the editor panel saves through post meta) and runs the batches of a
 * bulk action and the cache drop when a wait ends.
 */
final class ControlModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * The options an article had before a write, by article.
	 *
	 * @var array<int, mixed>
	 */
	private array $before = array();

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'estasoft_nd_internal_delivery_hold', array( $this, 'hold' ), 5, 4 );
		add_filter( 'estasoft_nd_internal_delivery_delay', array( $this, 'delay' ), 10, 3 );
		add_filter( 'estasoft_nd_internal_feed_articles', array( $this, 'feed_articles' ), 10, 3 );
		add_filter( 'estasoft_nd_internal_article_results', array( $this, 'results' ), 10, 3 );
		add_action( 'estasoft_nd_internal_send_now', array( $this, 'send_now' ), 10, 2 );
		add_filter( 'estasoft_nd_internal_options_schema', array( $this, 'schema' ) );
		add_filter( 'estasoft_nd_internal_alert_still_open', array( $this, 'still_open' ), 10, 3 );

		add_action( 'estasoft_nd_post_event', array( $this, 'on_event' ), 20 );
		add_action( Waiting::RELEASE_DUE, array( $this, 'release_due' ) );
		add_action( Bulk::HOOK, array( $this, 'bulk_batch' ), 10, 2 );

		add_action( 'update_postmeta', array( $this, 'remember_options' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'options_added' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'options_updated' ), 10, 4 );

		add_action( 'before_delete_post', array( $this, 'forget_fixes' ) );
		add_action( 'estasoft_nd_takedown_started', array( $this, 'takedown_started' ), 10, 2 );
		add_action( 'estasoft_nd_takedown_restored', array( $this, 'takedown_restored' ), 10, 2 );
	}

	/**
	 * Answers whether a push is held.
	 *
	 * @param mixed $hold    An answer given earlier, or null.
	 * @param mixed $channel The channel.
	 * @param mixed $event   The event.
	 * @param mixed $article The article, or null when the push is only being queued.
	 * @return mixed
	 */
	public function hold( $hold, $channel = null, $event = null, $article = null ) {
		unset( $article );

		if ( null !== $hold || ! $channel instanceof ChannelInterface || ! $event instanceof Event || $event->removes_content() ) {
			return $hold;
		}

		$post_id = $event->post_id();

		if ( $this->takedown()->is_down( $post_id ) ) {
			return array(
				'status'  => Status::SKIPPED,
				'message' => __( 'Not sent: the article was taken down.', 'estasoft-news-distribution' ),
			);
		}

		$state = $this->holds()->decide( $post_id, $channel );

		if ( Hold::OPEN === $state['state'] ) {
			return $hold;
		}

		if ( Hold::HELD === $state['state'] ) {
			return array(
				'status'  => Status::QUEUED,
				'message' => sprintf(
					/* translators: %s: the reason the person typed when they held the article. */
					__( 'Held: %s', 'estasoft-news-distribution' ),
					$state['reason']
				),
			);
		}

		if ( null === $state['until'] ) {
			return array(
				'status'  => Status::QUEUED,
				'message' => __( 'Waiting until you end the exclusive.', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'status'  => Status::QUEUED,
			'message' => sprintf(
				/* translators: %s: a time such as 14:38. */
				__( 'Waiting until %s for the release plan.', 'estasoft-news-distribution' ),
				$this->when( (int) $state['until'] )
			),
			'until'   => (int) $state['until'],
		);
	}

	/**
	 * Gives the wait a push has under the article's release plan.
	 *
	 * @param mixed $delay   Seconds the channel asks for.
	 * @param mixed $channel The channel.
	 * @param mixed $event   The event.
	 * @return mixed
	 */
	public function delay( $delay, $channel = null, $event = null ) {
		if ( ! $channel instanceof ChannelInterface || ! $event instanceof Event ) {
			return $delay;
		}

		return $this->holds()->delay_for( $channel, $event, (int) $delay );
	}

	/**
	 * Takes out of a feed the articles it must not carry yet, or any more.
	 *
	 * @param mixed $articles The articles.
	 * @param mixed $channel  The channel.
	 * @return mixed
	 */
	public function feed_articles( $articles, $channel = null ) {
		if ( ! is_array( $articles ) || ! $channel instanceof ChannelInterface ) {
			return $articles;
		}

		return $this->holds()->filter_feed( $articles, $channel );
	}

	/**
	 * Says Waiting, or Removed, where the article is not in the feed because of a hold, a plan or a takedown.
	 *
	 * @param mixed $out      Result per channel.
	 * @param mixed $post     The post.
	 * @param mixed $language Language code.
	 * @return mixed
	 */
	public function results( $out, $post = null, $language = '' ) {
		unset( $language );

		if ( ! is_array( $out ) || ! $post instanceof \WP_Post ) {
			return $out;
		}

		$post_id = (int) $post->ID;
		$down    = $this->takedown()->is_down( $post_id );
		$holds   = $this->holds();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			$id = $channel->id();

			if ( ! isset( $out[ $id ] ) ) {
				continue;
			}

			$status = (string) $out[ $id ]['status'];

			if ( $down ) {
				if ( ChannelInterface::MODE_PULLED === $channel->mode() && Status::IN_FEED === $status ) {
					$out[ $id ]['status']  = Status::REMOVED;
					$out[ $id ]['message'] = __( 'Removed from the feed.', 'estasoft-news-distribution' );
				}

				continue;
			}

			$state = $holds->decide( $post_id, $channel );

			if ( Hold::OPEN === $state['state'] ) {
				continue;
			}

			$empty_off = Status::OFF === $status && '' === (string) $out[ $id ]['message'];

			if ( ! in_array( $status, array( Status::IN_FEED, Status::QUEUED ), true ) && ! $empty_off ) {
				continue;
			}

			$out[ $id ]['status'] = Status::QUEUED;

			if ( Hold::HELD === $state['state'] ) {
				$out[ $id ]['message'] = sprintf(
					/* translators: %s: the reason the person typed when they held the article. */
					__( 'Held: %s', 'estasoft-news-distribution' ),
					$state['reason']
				);
			} elseif ( null === $state['until'] ) {
				$out[ $id ]['message'] = __( 'Waiting until you end the exclusive.', 'estasoft-news-distribution' );
			} else {
				$out[ $id ]['message'] = sprintf(
					/* translators: %s: a time such as 14:38. */
					__( 'Waiting until %s for the release plan.', 'estasoft-news-distribution' ),
					$this->when( (int) $state['until'] )
				);
			}
		}

		return $out;
	}

	/**
	 * Send now: takes the plan's wait out of the way for the channels asked for, unless the article is on hold.
	 *
	 * @param mixed $post_id Article ID.
	 * @param mixed $only    Channel ids, or an empty array for every pushed channel.
	 * @return void
	 */
	public function send_now( $post_id = 0, $only = array() ): void {
		$post_id = (int) $post_id;
		$only    = array_values( array_filter( array_map( 'sanitize_key', (array) $only ) ) );
		$holds   = $this->holds();

		if ( $post_id < 1 || $holds->is_held( $post_id ) || $this->takedown()->is_down( $post_id ) ) {
			return;
		}

		$holds->waiting()->cancel( $post_id, $only );
		$holds->release_for_send( $post_id, $only );

		$names = array();

		foreach ( $only as $id ) {
			$names[] = $holds->channel_name( $id );
		}

		$this->plugin->audit()->record(
			Audit::SEND_NOW,
			$post_id,
			array() === $names
				? __( 'Send now: every push channel, without the wait.', 'estasoft-news-distribution' )
				: sprintf(
					/* translators: %s: channel names such as "WebSub, IndexNow". */
					__( 'Send now: %s, without the wait.', 'estasoft-news-distribution' ),
					implode( ', ', $names )
				)
		);
	}

	/**
	 * Declares `hold` and `release` in the JSON schema of the per-article options.
	 *
	 * @param mixed $properties The properties.
	 * @return mixed
	 */
	public function schema( $properties ) {
		if ( ! is_array( $properties ) ) {
			return $properties;
		}

		$properties['hold']    = array(
			'type'       => 'object',
			'properties' => array(
				'reason' => array( 'type' => 'string' ),
				'by'     => array( 'type' => 'integer' ),
				'at'     => array( 'type' => 'integer' ),
			),
		);
		$properties['release'] = array(
			'type'       => 'object',
			'properties' => array(
				'mode'         => array( 'type' => 'string' ),
				'exclusive'    => array( 'type' => 'string' ),
				'duration'     => array( 'type' => 'integer' ),
				'delay_search' => array( 'type' => 'boolean' ),
				'times'        => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
			),
		);

		return $properties;
	}

	/**
	 * Tells the alert reminders whether a fix or a takedown is still open.
	 *
	 * @param mixed $open    Whether the reminder is still needed.
	 * @param mixed $type    Alert type.
	 * @param mixed $context What the reminder was scheduled with.
	 * @return mixed
	 */
	public function still_open( $open, $type = '', $context = array() ) {
		$context = is_array( $context ) ? $context : array();

		if ( Alerts::FIX_ASSIGNED === $type && ! empty( $context['fix_id'] ) ) {
			return $this->plugin->make( Fixes::class )->is_open( (int) $context['fix_id'] );
		}

		if ( Alerts::TAKEDOWN === $type && ! empty( $context['article_id'] ) ) {
			$read = $this->takedown()->read( (int) $context['article_id'] );

			return 'down' === $read['status'] && array() !== $read['summary']['needs'];
		}

		return $open;
	}

	/**
	 * After a publish or an update, asks the queue to drop the cached feeds when a wait ends.
	 *
	 * @param mixed $event The event.
	 * @return void
	 */
	public function on_event( $event ): void {
		if ( ! $event instanceof Event || $event->removes_content() ) {
			return;
		}

		$this->holds()->schedule_flushes( $event->post_id() );
	}

	/**
	 * Drops the cached feeds when a wait ended.
	 *
	 * @param mixed $post_id Article ID.
	 * @return void
	 */
	public function release_due( $post_id = 0 ): void {
		$this->holds()->waiting()->run_flush( $post_id );
	}

	/**
	 * Does one batch of a bulk action.
	 *
	 * @param mixed $job   Job id.
	 * @param mixed $batch Batch number.
	 * @return void
	 */
	public function bulk_batch( $job = '', $batch = 0 ): void {
		$this->plugin->make( Bulk::class )->process( $job, $batch );
	}

	/**
	 * Keeps what the options were before a write.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $value     The new value.
	 * @return void
	 */
	public function remember_options( $meta_id = 0, $object_id = 0, $meta_key = '', $value = null ): void {
		unset( $meta_id, $value );

		if ( ArticleOptions::META === $meta_key ) {
			$this->before[ (int) $object_id ] = get_post_meta( (int) $object_id, ArticleOptions::META, true );
		}
	}

	/**
	 * A first write of the options.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $value     The value.
	 * @return void
	 */
	public function options_added( $meta_id = 0, $object_id = 0, $meta_key = '', $value = null ): void {
		unset( $meta_id );

		if ( ArticleOptions::META === $meta_key ) {
			$this->holds()->reconcile( (int) $object_id, array(), $value );
		}
	}

	/**
	 * A change of the options.
	 *
	 * @param mixed $meta_id   Meta ID.
	 * @param mixed $object_id Post ID.
	 * @param mixed $meta_key  Meta key.
	 * @param mixed $value     The new value.
	 * @return void
	 */
	public function options_updated( $meta_id = 0, $object_id = 0, $meta_key = '', $value = null ): void {
		unset( $meta_id );

		if ( ArticleOptions::META !== $meta_key ) {
			return;
		}

		$post_id = (int) $object_id;
		$old     = $this->before[ $post_id ] ?? array();

		unset( $this->before[ $post_id ] );
		$this->holds()->reconcile( $post_id, $old, $value );
	}

	/**
	 * A takedown started: SmartNews lists the article as removed.
	 *
	 * @param mixed $post_id Article ID.
	 * @param mixed $event   The unpublish event.
	 * @return void
	 */
	public function takedown_started( $post_id = 0, $event = null ): void {
		unset( $post_id );

		if ( class_exists( Removals::class ) ) {
			Removals::on_event( $event );
		}
	}

	/**
	 * `before_delete_post`: an article deleted for good takes its assigned fixes with it.
	 *
	 * @param mixed $post_id Post ID.
	 * @return void
	 */
	public function forget_fixes( $post_id = 0 ): void {
		$this->plugin->make( Fixes::class )->forget( (int) $post_id );
	}

	/**
	 * A takedown was undone: SmartNews stops listing the article as removed.
	 *
	 * @param mixed $post_id Article ID.
	 * @param mixed $event   The publish event.
	 * @return void
	 */
	public function takedown_restored( $post_id = 0, $event = null ): void {
		unset( $post_id );

		if ( class_exists( Removals::class ) ) {
			Removals::on_event( $event );
		}
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * The hold and release plan service.
	 *
	 * @return Hold
	 */
	private function holds(): Hold {
		return $this->plugin->make( Hold::class );
	}

	/**
	 * The takedown service.
	 *
	 * @return Takedown
	 */
	private function takedown(): Takedown {
		return $this->plugin->make( Takedown::class );
	}

	/**
	 * A time for a delivery log message: the hour, with the day when it is not today.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function when( int $time ): string {
		return Clock::format( $time, 'Y-m-d' ) === Clock::format( Clock::now(), 'Y-m-d' ) ? Clock::format( $time ) : Clock::datetime( $time );
	}
}
