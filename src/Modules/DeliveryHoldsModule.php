<?php
/**
 * Holds pushes back: staging mode, demo mode, a language turned off, a future date.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Dispatcher;
use Estasoft\NewsDistribution\Core\Environment;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Frontend\Response;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the Dispatcher's internal hold question (the filter
 * `estasoft_nd_internal_delivery_hold`), once when a push is queued and again when
 * the queue runs it. The Dispatcher records the answer in the delivery log, so a
 * held push shows up there with its reason, and never calls the channel.
 *
 * - Staging mode and demo mode: nothing is pushed. Status Off, "Not sent: …".
 *   Takedowns are held too; a staging site never sends.
 * - Demo mode also holds what platforms pull. The sample articles must not be served to a crawler as if they were
 *   news, so a feed or sitemap request (`estasoft_nd_internal_feed_hold`, asked by `Frontend\FeedController` and
 *   `Frontend\PartnerFeedController`) is answered 503 "Not sent: demo mode is on" with `Retry-After`, and is not
 *   written to the fetch log. In the lists, an article that would be in a pulled channel's feed says Off, "Not
 *   sent: demo mode is on" (`estasoft_nd_internal_article_results`), as a held push does. Staging mode does not
 *   hold pulled feeds: they still answer, marked noindex, as Settings, Environment says.
 * - A language that was turned off after the push was queued: Off.
 * - An article dated in the future, while "Skip it in feeds, sitemaps and pushes
 *   until its time" is on: the push waits until that time, then runs. A takedown
 *   does not wait.
 *
 * Another answer already given by something else is left as it is.
 *
 * @see Dispatcher
 */
final class DeliveryHoldsModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hook.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'estasoft_nd_internal_delivery_hold', array( $this, 'hold' ), 10, 4 );
		add_filter( 'estasoft_nd_internal_feed_hold', array( $this, 'hold_pulled' ), 10, 1 );
		add_filter( 'estasoft_nd_internal_article_results', array( $this, 'results' ), 30, 1 );
	}

	/**
	 * The answer a platform that pulls a feed or sitemap gets while demo mode is on.
	 *
	 * @param mixed $held An answer given earlier, or null.
	 * @return mixed A Response, or the earlier answer.
	 */
	public function hold_pulled( $held ) {
		if ( null !== $held || ! ( new Environment( $this->plugin ) )->staging()['demo'] ) {
			return $held;
		}

		return new Response(
			503,
			array(
				'Content-Type'  => 'text/plain; charset=UTF-8',
				'X-Robots-Tag'  => 'noindex',
				'Cache-Control' => 'no-store',
				'Retry-After'   => (string) HOUR_IN_SECONDS,
			),
			'Not sent: demo mode is on.'
		);
	}

	/**
	 * Says Off, not In feed, where demo mode keeps a pulled feed from being served.
	 *
	 * @param mixed $results Result per channel.
	 * @return mixed
	 */
	public function results( $results ) {
		if ( ! is_array( $results ) || ! ( new Environment( $this->plugin ) )->staging()['demo'] ) {
			return $results;
		}

		foreach ( $results as $id => $result ) {
			if ( is_array( $result ) && Status::IN_FEED === ( $result['status'] ?? '' ) ) {
				$results[ $id ]['status']  = Status::OFF;
				$results[ $id ]['message'] = __( 'Not sent: demo mode is on.', 'estasoft-news-distribution' );
			}
		}

		return $results;
	}

	/**
	 * Answers whether a push is held.
	 *
	 * @param mixed $hold    An answer given earlier, or null.
	 * @param mixed $channel The channel (ChannelInterface).
	 * @param mixed $event   The event (Event).
	 * @param mixed $article The article, or null when the push is only being queued.
	 * @return mixed An array with `status`, `message` and, to wait, `until`; or the earlier answer.
	 */
	public function hold( $hold, $channel = null, $event = null, $article = null ) {
		if ( null !== $hold || ! $channel instanceof ChannelInterface || ! $event instanceof Event ) {
			return $hold;
		}

		$staging = ( new Environment( $this->plugin ) )->staging();

		if ( $staging['paused'] ) {
			return array(
				'status'  => Status::OFF,
				'message' => 'demo' === $staging['reason']
					? __( 'Not sent: demo mode is on.', 'estasoft-news-distribution' )
					: __( 'Not sent: staging mode is on.', 'estasoft-news-distribution' ),
			);
		}

		if ( ! $article instanceof Article ) {
			return $hold;
		}

		if ( ! $channel->accepts_language( $article->language() ) && ! $event->removes_content() ) {
			return array(
				'status'  => Status::OFF,
				'message' => sprintf(
					/* translators: %s: a language name such as Kurdish. */
					__( 'Not sent: %s is turned off.', 'estasoft-news-distribution' ),
					Catalog::name( $article->language() )
				),
			);
		}

		$now = Clock::now();

		if ( ! $event->removes_content() && $article->published_ts() > $now && (bool) $this->plugin->settings()->get( 'clock.skip_future', true ) ) {
			return array(
				'status'  => Status::SKIPPED,
				'message' => sprintf(
					/* translators: %s: a time such as 15:00. */
					__( 'Skipped until %s: the article is dated in the future.', 'estasoft-news-distribution' ),
					Clock::format( $article->published_ts() )
				),
				'until'   => $article->published_ts(),
			);
		}

		return $hold;
	}
}
