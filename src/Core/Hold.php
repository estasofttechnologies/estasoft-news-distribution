<?php
/**
 * Hold and the release plan of one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Core\Control\Plan;
use Estasoft\NewsDistribution\Core\Control\Waiting;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Two per-article choices that decide when, or whether, a channel gets an article.
 *
 * - A hold keeps an article out of partner feeds, open readers and pushes until
 *   somebody releases it. Google News and the search sitemaps are never held,
 *   and neither is your own site. A hold always has a reason.
 * - A release plan says which channel waits and until when: the default (every
 *   push waits the usual index-first delay), right away, one channel first for a
 *   while, or a time per channel. Your own site always goes first.
 *
 * Both live in `_estasoft_nd_options` (keys `hold` and `release`). The channels
 * never read them: the feed builder, the queue and the results ask this class
 * through three internal filters (see Modules\ControlModule). Get it with
 * `Plugin::instance()->make( Hold::class )`.
 *
 * What the plan cannot do is make a platform fetch at a set time. It decides
 * when News Distribution adds the article to a feed or sends a ping.
 */
final class Hold {

	public const OPTIONS = '_estasoft_nd_options';

	/**
	 * The longest a reason can be, in characters.
	 */
	public const REASON_MAX = 300;

	/**
	 * Decision states of one channel for one article.
	 */
	public const OPEN    = 'open';
	public const WAITING = 'waiting';
	public const HELD    = 'held';

	/**
	 * True while this class writes the options itself, so the meta hooks know not to react twice.
	 *
	 * @var bool
	 */
	private static bool $internal = false;

	private Plugin $plugin;

	private Waiting $waiting;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin  = $plugin;
		$this->waiting = new Waiting( $plugin );
	}

	/**
	 * The queue helper this service uses.
	 *
	 * @return Waiting
	 */
	public function waiting(): Waiting {
		return $this->waiting;
	}

	/**
	 * Everything stored for the article in `_estasoft_nd_options`.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	public function options( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::OPTIONS, true );

		return is_array( $stored ) ? $stored : array();
	}

	// ---------------------------------------------------------------------
	// Hold.
	// ---------------------------------------------------------------------

	/**
	 * Whether the article is on hold.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	public function is_held( int $post_id ): bool {
		return null !== $this->info( $post_id );
	}

	/**
	 * The hold: why, who and when. Null when the article is not on hold.
	 *
	 * @param int $post_id Article ID.
	 * @return array{reason: string, by: int, at: int}|null
	 */
	public function info( int $post_id ): ?array {
		$hold = $this->options( $post_id )['hold'] ?? null;

		if ( ! is_array( $hold ) ) {
			return null;
		}

		$reason = trim( (string) ( $hold['reason'] ?? '' ) );

		if ( '' === $reason ) {
			return null;
		}

		return array(
			'reason' => $reason,
			'by'     => (int) ( $hold['by'] ?? 0 ),
			'at'     => (int) ( $hold['at'] ?? 0 ),
		);
	}

	/**
	 * Whether a hold applies to a channel: partner feeds, open readers and pushes.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	public function in_hold_scope( ChannelInterface $channel ): bool {
		return ChannelInterface::MODE_MANUAL !== $channel->mode()
			&& in_array( $channel->group(), array( Group::PARTNER, Group::READERS, Group::PUSHES ), true );
	}

	/**
	 * Holds an article.
	 *
	 * @param int      $post_id Article ID.
	 * @param string   $reason  Why. Required.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error The new state.
	 */
	public function hold( int $post_id, string $reason, ?int $user_id = null ) {
		$post   = $this->post( $post_id );
		$reason = trim( mb_substr( sanitize_textarea_field( $reason ), 0, self::REASON_MAX ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( '' === $reason ) {
			return $this->fail( 'estasoft_nd_hold_reason_needed', __( 'Write why you are holding the article.', 'estasoft-news-distribution' ), 422, array( 'reason' ) );
		}

		if ( ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return new \WP_Error( 'estasoft_nd_hold_not_published', __( 'Only a published or scheduled article can be held.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		$this->write(
			$post_id,
			array(
				'hold' => array(
					'reason' => $reason,
					'by'     => $user_id,
					'at'     => Clock::now(),
				),
			)
		);

		$this->plugin->audit()->record(
			Audit::HOLD,
			$post_id,
			sprintf(
				/* translators: %s: the reason the person gave. */
				__( 'Held from partner feeds and pushes. Reason: %s', 'estasoft-news-distribution' ),
				$reason
			),
			$user_id
		);
		$this->plugin->cache()->flush();

		return $this->state( $post_id );
	}

	/**
	 * Releases a hold. The pushes the hold kept back are queued again.
	 *
	 * @param int      $post_id Article ID.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error The new state, with `queued`, the channels that were queued.
	 */
	public function lift( int $post_id, ?int $user_id = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( ! $this->is_held( $post_id ) ) {
			return new \WP_Error( 'estasoft_nd_not_held', __( 'This article is not on hold.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		$this->write( $post_id, array( 'hold' => array() ) );
		$this->plugin->audit()->record( Audit::RELEASE, $post_id, __( 'Released from hold. Partner feeds and pushes carry it again.', 'estasoft-news-distribution' ), $user_id );

		$queued = 'publish' === $post->post_status ? $this->waiting->requeue_held( $post_id, $this->open_pushed( $post_id ) ) : array();

		$this->plugin->cache()->flush();

		$state           = $this->state( $post_id );
		$state['queued'] = $queued;

		return $state;
	}

	// ---------------------------------------------------------------------
	// Release plan.
	// ---------------------------------------------------------------------

	/**
	 * Whether the article carries a decision that a person made: a hold, or a release plan that is not the default.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	public function has_decision( int $post_id ): bool {
		return $this->is_held( $post_id ) || $this->significant( $this->plan( $post_id ) ) !== $this->significant( Plan::normalize( array() ) );
	}

	/**
	 * The stored plan, cleaned.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	public function plan( int $post_id ): array {
		return Plan::normalize( $this->options( $post_id )['release'] ?? array() );
	}

	/**
	 * The plan that decides what happens: the stored one, except that breaking news means right away.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	public function effective_plan( int $post_id ): array {
		$plan     = $this->plan( $post_id );
		$breaking = $this->options( $post_id )['breaking'] ?? false;

		if ( Plan::MODE_DEFAULT === $plan['mode'] && filter_var( $breaking, FILTER_VALIDATE_BOOLEAN ) ) {
			$plan['mode'] = Plan::MODE_NOW;
		}

		return $plan;
	}

	/**
	 * Saves a plan.
	 *
	 * @param int                  $post_id Article ID.
	 * @param array<string, mixed> $input   mode, exclusive, duration, delay_search, times.
	 * @param int|null             $user_id Person, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error The new state.
	 */
	public function save_plan( int $post_id, array $input, ?int $user_id = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$mode = isset( $input['mode'] ) ? sanitize_key( (string) $input['mode'] ) : Plan::MODE_DEFAULT;

		if ( ! in_array( $mode, Plan::modes(), true ) ) {
			return $this->fail( 'estasoft_nd_bad_release', __( 'Choose one of the release options.', 'estasoft-news-distribution' ), 422, array( 'mode' ) );
		}

		$plan         = Plan::defaults();
		$plan['mode'] = $mode;
		$plan['by']   = null === $user_id ? get_current_user_id() : $user_id;

		if ( Plan::MODE_EXCLUSIVE === $mode ) {
			$channel = isset( $input['exclusive'] ) ? sanitize_key( (string) $input['exclusive'] ) : '';

			if ( ! array_key_exists( $channel, $this->exclusive_choices() ) ) {
				return $this->fail( 'estasoft_nd_bad_exclusive', __( 'Choose a channel that can have the article first.', 'estasoft-news-distribution' ), 422, array( 'exclusive' ) );
			}

			$plan['exclusive']    = $channel;
			$plan['delay_search'] = isset( $input['delay_search'] ) && filter_var( $input['delay_search'], FILTER_VALIDATE_BOOLEAN );
			$plan['duration']     = Plan::normalize( array( 'duration' => $input['duration'] ?? Plan::USUAL ) )['duration'];
		}

		if ( Plan::MODE_CUSTOM === $mode ) {
			$plan['times'] = Plan::normalize( array( 'times' => is_array( $input['times'] ?? null ) ? $input['times'] : array() ) )['times'];
		}

		$plan['saved'] = Clock::now();

		$this->write( $post_id, array( 'release' => $plan ) );
		$this->plugin->audit()->record( Audit::PLAN, $post_id, $this->describe( $plan ), $plan['by'] );
		$this->settle( $post_id );

		return $this->state( $post_id );
	}

	/**
	 * Ends an exclusive now: every channel that was waiting for it goes out at once.
	 *
	 * @param int      $post_id Article ID.
	 * @param int|null $user_id Person, or the signed-in user.
	 * @return array<string, mixed>|\WP_Error The new state.
	 */
	public function end_exclusive( int $post_id, ?int $user_id = null ) {
		$post = $this->post( $post_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$plan = $this->plan( $post_id );

		if ( Plan::MODE_EXCLUSIVE !== $plan['mode'] || $plan['ended'] > 0 ) {
			return new \WP_Error( 'estasoft_nd_no_exclusive', __( 'There is no exclusive to end for this article.', 'estasoft-news-distribution' ), array( 'status' => 409 ) );
		}

		$user_id       = null === $user_id ? get_current_user_id() : $user_id;
		$plan['ended'] = Clock::now();

		$this->write( $post_id, array( 'release' => $plan ) );
		$this->plugin->audit()->record(
			Audit::PLAN,
			$post_id,
			sprintf(
				/* translators: %s: a channel name such as Microsoft Start. */
				__( 'Ended the exclusive on %s early. The other channels go out now.', 'estasoft-news-distribution' ),
				$this->channel_name( (string) $plan['exclusive'] )
			),
			$user_id
		);
		$this->settle( $post_id );

		return $this->state( $post_id );
	}

	/**
	 * Takes the channels Send now was pressed for out of the plan's wait.
	 *
	 * @param int      $post_id  Article ID.
	 * @param string[] $channels Channel ids, or an empty array for every pushed channel.
	 * @return void
	 */
	public function release_for_send( int $post_id, array $channels ): void {
		$plan = $this->plan( $post_id );

		if ( ! in_array( $plan['mode'], array( Plan::MODE_EXCLUSIVE, Plan::MODE_CUSTOM ), true ) ) {
			return;
		}

		if ( array() === $channels ) {
			foreach ( $this->plugin->registry()->pushed() as $channel ) {
				$channels[] = $channel->id();
			}
		}

		$plan['sent_now'] = array_values( array_unique( array_merge( (array) $plan['sent_now'], $channels ) ) );

		$this->write( $post_id, array( 'release' => $plan ) );
		$this->plugin->cache()->flush();
	}

	/**
	 * Channels that can have the article first: on, fetched by the platform and past its application.
	 *
	 * @return array<string, string> Channel id => name.
	 */
	public function exclusive_choices(): array {
		$choices = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			if ( ! $this->can_be_exclusive( $channel ) ) {
				continue;
			}

			$choices[ $channel->id() ] = $channel->label();
		}

		return $choices;
	}

	/**
	 * Channels that would be a good exclusive but are still In review, so the plan cannot use them yet.
	 *
	 * @return array<string, string> Channel id => name.
	 */
	public function exclusive_blocked(): array {
		$blocked = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			if ( $this->is_exclusive_kind( $channel ) && $this->in_review( $channel ) ) {
				$blocked[ $channel->id() ] = $channel->label();
			}
		}

		return $blocked;
	}

	/**
	 * Whether a channel is waiting for a platform's approval and has never been fetched.
	 *
	 * Nothing is sent to such a channel, so a plan does not apply to it yet.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	public function in_review( ChannelInterface $channel ): bool {
		if ( ChannelInterface::MODE_PULLED !== $channel->mode() || ! $channel->is_enabled() ) {
			return false;
		}

		$status = $this->plugin->applications()->get( $channel->id() )['status'];

		if ( ! in_array( $status, array( Status::REQUESTED, Status::IN_REVIEW ), true ) ) {
			return false;
		}

		// Read by crawler name for the channels that share a sitemap, so a Baiduspider fetch that Bing answered counts for Baidu.
		return array() === FetchSummary::latest_rows( $this->plugin, $channel, $this->plugin->fetch_log()->latest() );
	}

	// ---------------------------------------------------------------------
	// Decisions.
	// ---------------------------------------------------------------------

	/**
	 * Whether a channel carries articles one by one. Verification and listings do not.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	public function carries_articles( ChannelInterface $channel ): bool {
		return ChannelInterface::MODE_MANUAL !== $channel->mode()
			&& in_array( $channel->group(), array( Group::SITEMAP, Group::PARTNER, Group::READERS, Group::PUSHES ), true );
	}

	/**
	 * The time the plan counts from: when the article was published, or is due to be.
	 *
	 * @param int $post_id Article ID.
	 * @return int Unix time. Now, for an article with no date yet.
	 */
	public function base_time( int $post_id ): int {
		$gmt  = (string) get_post_field( 'post_date_gmt', $post_id );
		$time = Clock::from_gmt( $gmt );

		return $time > 0 ? $time : Clock::now();
	}

	/**
	 * Whether a channel may carry an article now, and if not, until when.
	 *
	 * @param int              $post_id Article ID.
	 * @param ChannelInterface $channel The channel.
	 * @param int|null         $now     Unix time to treat as now.
	 * @return array{state: string, until: int|null, kind: string, reason: string}
	 *         state is open, waiting or held. until is the time a wait ends, or null when it ends only when a person acts.
	 *         kind is hold, exclusive or custom for a wait, else an empty string.
	 */
	public function decide( int $post_id, ChannelInterface $channel, ?int $now = null ): array {
		$now  = null === $now ? Clock::now() : $now;
		$open = array(
			'state'  => self::OPEN,
			'until'  => null,
			'kind'   => '',
			'reason' => '',
		);

		if ( ! $this->carries_articles( $channel ) ) {
			return $open;
		}

		$hold = $this->info( $post_id );

		if ( null !== $hold && $this->in_hold_scope( $channel ) ) {
			return array(
				'state'  => self::HELD,
				'until'  => null,
				'kind'   => 'hold',
				'reason' => $hold['reason'],
			);
		}

		$plan = $this->effective_plan( $post_id );
		$id   = $channel->id();

		if ( in_array( $id, (array) $plan['sent_now'], true ) ) {
			return $open;
		}

		if ( Plan::MODE_EXCLUSIVE === $plan['mode'] ) {
			if ( ( $plan['ended'] > 0 && $plan['ended'] <= $now ) || $id === $plan['exclusive'] ) {
				return $open;
			}

			if ( Group::SITEMAP === $channel->group() && ! $plan['delay_search'] ) {
				return $open;
			}

			$length = Plan::exclusive_seconds( $plan, $this->plugin->settings()->index_first_delay_seconds() );

			if ( null === $length ) {
				return array(
					'state'  => self::WAITING,
					'until'  => null,
					'kind'   => 'exclusive',
					'reason' => '',
				);
			}

			$until = $this->base_time( $post_id ) + $length;

			return $until > $now
				? array(
					'state'  => self::WAITING,
					'until'  => $until,
					'kind'   => 'exclusive',
					'reason' => '',
				)
				: $open;
		}

		if ( Plan::MODE_CUSTOM === $plan['mode'] ) {
			$seconds = Plan::custom_seconds( $plan, $id );

			if ( null === $seconds ) {
				return $open;
			}

			$until = $this->base_time( $post_id ) + $seconds;

			return $until > $now
				? array(
					'state'  => self::WAITING,
					'until'  => $until,
					'kind'   => 'custom',
					'reason' => '',
				)
				: $open;
		}

		return $open;
	}

	/**
	 * The time a push for this event should go out.
	 *
	 * @param int              $post_id Article ID.
	 * @param ChannelInterface $channel The channel.
	 * @param Event            $event   The event.
	 * @return int|null Unix time, or null when it waits until a person acts (a hold, or an exclusive with no end).
	 */
	public function push_time( int $post_id, ChannelInterface $channel, Event $event ): ?int {
		$usual = $channel->delay_seconds( $event );
		$base  = $this->base_time( $post_id );
		$plan  = $this->effective_plan( $post_id );
		$state = $this->decide( $post_id, $channel );

		if ( self::HELD === $state['state'] ) {
			return null;
		}

		if ( Plan::MODE_NOW === $plan['mode'] ) {
			return $base;
		}

		if ( self::WAITING === $state['state'] ) {
			if ( null === $state['until'] ) {
				return null;
			}

			return 'custom' === $state['kind'] ? $state['until'] : max( $base + $usual, $state['until'] );
		}

		if ( Plan::MODE_CUSTOM === $plan['mode'] && ! in_array( $channel->id(), (array) $plan['sent_now'], true ) ) {
			$seconds = Plan::custom_seconds( $plan, $channel->id() );

			if ( null !== $seconds ) {
				return $base + $seconds;
			}
		}

		return $base + $usual;
	}

	/**
	 * How long a push waits, in seconds, once the plan has had its say.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param Event            $event   The event.
	 * @param int              $usual   What the channel asked for.
	 * @return int
	 */
	public function delay_for( ChannelInterface $channel, Event $event, int $usual ): int {
		if ( $event->removes_content() ) {
			return $usual;
		}

		$time = $this->push_time( $event->post_id(), $channel, $event );

		return null === $time ? $usual : max( 0, $time - Clock::now() );
	}

	/**
	 * Takes articles out of one channel's document.
	 *
	 * @param array<int, Article> $articles The articles.
	 * @param ChannelInterface    $channel  The channel.
	 * @return array<int, Article>
	 */
	public function filter_feed( array $articles, ChannelInterface $channel ): array {
		if ( array() === $articles ) {
			return $articles;
		}

		$ids = array();

		foreach ( $articles as $article ) {
			$ids[] = $article->id();
		}

		update_meta_cache( 'post', $ids );

		$takedown = $this->plugin->make( Takedown::class );
		$kept     = array();

		$takedown->prime( $ids );

		foreach ( $articles as $article ) {
			if ( $takedown->is_down( $article->id() ) ) {
				continue;
			}

			if ( self::OPEN !== $this->decide( $article->id(), $channel )['state'] ) {
				continue;
			}

			$kept[] = $article;
		}

		$takedown->release_prime();

		return $kept;
	}

	// ---------------------------------------------------------------------
	// What the screens and routes read.
	// ---------------------------------------------------------------------

	/**
	 * The state of an article for the Release plan tab and the routes.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	public function state( int $post_id ): array {
		$plan    = $this->plan( $post_id );
		$usual   = $this->plugin->settings()->index_first_delay_seconds();
		$base    = $this->base_time( $post_id );
		$length  = Plan::exclusive_seconds( $plan, $usual );
		$active  = Plan::MODE_EXCLUSIVE === $plan['mode'] && 0 === $plan['ended'];
		$ends    = $active && null !== $length ? $base + $length : null;
		$options = $this->options( $post_id );

		return array(
			'post_id'   => $post_id,
			'held'      => $this->info( $post_id ),
			'plan'      => $plan,
			'breaking'  => filter_var( $options['breaking'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			'base'      => $base,
			'usual'     => $usual,
			'exclusive' => array(
				'active'  => $active,
				'ends'    => $ends,
				'seconds' => $length,
				'open'    => $active && null === $length,
				'over'    => $active && null !== $ends && $ends <= Clock::now(),
			),
		);
	}

	/**
	 * The decision for every channel that carries articles in the article's language.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, array{id: string, label: string, group: string, mode: string, state: string, until: int|null, kind: string, reason: string, review: bool, left_out: bool}>
	 */
	public function channels_state( int $post_id ): array {
		$language = $this->plugin->languages()->language_of_post( $post_id );
		$choices  = $this->plugin->make( ArticleChoices::class );
		$out      = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			if ( ! $channel->is_enabled() || ! $this->carries_articles( $channel ) || ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$decision = $this->decide( $post_id, $channel );
			$out[]    = array(
				'id'       => $channel->id(),
				'label'    => $channel->label(),
				'group'    => $channel->group(),
				'mode'     => $channel->mode(),
				'state'    => $decision['state'],
				'until'    => $decision['until'],
				'kind'     => $decision['kind'],
				'reason'   => $decision['reason'],
				'review'   => $this->in_review( $channel ),
				'left_out' => $choices->is_left_out( $post_id, $channel->id() ) && $choices->leaves_out( $post_id, $channel ),
			);
		}

		return $out;
	}

	/**
	 * One line about a plan, for the audit table.
	 *
	 * @param array<string, mixed> $plan A normalized plan.
	 * @return string
	 */
	public function describe( array $plan ): string {
		if ( Plan::MODE_NOW === $plan['mode'] ) {
			return __( 'Release plan: every channel right away.', 'estasoft-news-distribution' );
		}

		if ( Plan::MODE_EXCLUSIVE === $plan['mode'] ) {
			$length = Plan::exclusive_seconds( $plan, $this->plugin->settings()->index_first_delay_seconds() );

			return sprintf(
				/* translators: 1: a channel name such as Microsoft Start, 2: how long, such as "15 minutes" or "until released by hand". */
				__( 'Release plan: %1$s has the article first, %2$s.', 'estasoft-news-distribution' ),
				$this->channel_name( (string) $plan['exclusive'] ),
				null === $length ? __( 'until released by hand', 'estasoft-news-distribution' ) : sprintf(
					/* translators: %s: a length of time such as "15 minutes". */
					__( 'for %s', 'estasoft-news-distribution' ),
					Plan::words( $length )
				)
			);
		}

		if ( Plan::MODE_CUSTOM === $plan['mode'] ) {
			return __( 'Release plan: a time for each channel.', 'estasoft-news-distribution' );
		}

		return __( 'Release plan: the default, every push waits the usual delay.', 'estasoft-news-distribution' );
	}

	/**
	 * A channel's name, or its id when it is unknown.
	 *
	 * @param string $channel_id Channel id.
	 * @return string
	 */
	public function channel_name( string $channel_id ): string {
		$channel = $this->plugin->registry()->get( $channel_id );

		return null === $channel ? $channel_id : $channel->label();
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * Ids of the pushed channels that may carry the article now.
	 *
	 * @param int $post_id Article ID.
	 * @return string[] At least one value, so an empty list never means "all".
	 */
	private function open_pushed( int $post_id ): array {
		$open = array();

		foreach ( $this->plugin->registry()->pushed() as $channel ) {
			if ( self::OPEN === $this->decide( $post_id, $channel )['state'] ) {
				$open[] = $channel->id();
			}
		}

		return array() === $open ? array( '-' ) : $open;
	}

	/**
	 * Whether a channel is the kind that can have an article to itself first.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	private function is_exclusive_kind( ChannelInterface $channel ): bool {
		return $channel->is_enabled()
			&& ChannelInterface::MODE_PULLED === $channel->mode()
			&& ( Group::PARTNER === $channel->group() || 'flipboard' === $channel->id() );
	}

	/**
	 * Whether a channel can be picked as the exclusive.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return bool
	 */
	private function can_be_exclusive( ChannelInterface $channel ): bool {
		return $this->is_exclusive_kind( $channel ) && ! $this->in_review( $channel );
	}

	/**
	 * A plain-language error.
	 *
	 * @param string   $code    Stable code.
	 * @param string   $message Sentence the screen can show as written.
	 * @param int      $status  HTTP status.
	 * @param string[] $fields  Fields the message is about.
	 * @return \WP_Error
	 */
	private function fail( string $code, string $message, int $status, array $fields = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), array() === $fields ? array() : array( 'fields' => $fields ) ) );
	}

	/**
	 * The article, if it exists and is of a type the plugin sends.
	 *
	 * @param int $post_id Article ID.
	 * @return \WP_Post|\WP_Error
	 */
	private function post( int $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return new \WP_Error( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * Merges keys into the article's options.
	 *
	 * @param int                  $post_id Article ID.
	 * @param array<string, mixed> $changes Keys to set.
	 * @return void
	 */
	private function write( int $post_id, array $changes ): void {
		$before         = self::$internal;
		self::$internal = true;

		update_post_meta( $post_id, self::OPTIONS, array_merge( $this->options( $post_id ), $changes ) );

		self::$internal = $before;
	}

	/**
	 * Reacts to a write of the article's options that did not come from this class.
	 *
	 * The block editor panel saves `hold` and `release` through the post meta. Such a write gets
	 * the same care as one made here: a person who may not hold or plan loses the change, a hold
	 * gets its person and time and is recorded, and the queue and the cached feeds follow the plan.
	 *
	 * @param int   $post_id Article ID.
	 * @param mixed $old     The options before the write.
	 * @param mixed $after   The options after it.
	 * @return void
	 */
	public function reconcile( int $post_id, $old, $after ): void {
		if ( self::$internal ) {
			return;
		}

		$old   = is_array( $old ) ? $old : array();
		$after = is_array( $after ) ? $after : array();

		$hold_was = $this->hold_in( $old );
		$hold_now = $this->hold_in( $after );
		$held     = ( $hold_was['reason'] ?? '' ) !== ( $hold_now['reason'] ?? '' );
		$plan_was = $this->significant( Plan::normalize( $old['release'] ?? array() ) );
		$plan_now = $this->significant( Plan::normalize( $after['release'] ?? array() ) );
		$planned  = $plan_was !== $plan_now;

		if ( ! $held && ! $planned ) {
			return;
		}

		$user = get_current_user_id();

		if ( $user > 0 && ! $this->plugin->access()->can( Access::VIEW, null, $user ) ) {
			$this->write(
				$post_id,
				array(
					'hold'    => $old['hold'] ?? array(),
					'release' => $old['release'] ?? array(),
				)
			);

			return;
		}

		if ( $held ) {
			if ( null === $hold_now ) {
				$this->write( $post_id, array( 'hold' => array() ) );
				$this->plugin->audit()->record( Audit::RELEASE, $post_id, __( 'Released from hold. Partner feeds and pushes carry it again.', 'estasoft-news-distribution' ), $user );

				if ( 'publish' === get_post_status( $post_id ) ) {
					$this->waiting->requeue_held( $post_id, $this->open_pushed( $post_id ) );
				}
			} else {
				$this->write(
					$post_id,
					array(
						'hold' => array(
							'reason' => $hold_now['reason'],
							'by'     => $user,
							'at'     => Clock::now(),
						),
					)
				);
				$this->plugin->audit()->record(
					Audit::HOLD,
					$post_id,
					sprintf(
						/* translators: %s: the reason the person gave. */
						__( 'Held from partner feeds and pushes. Reason: %s', 'estasoft-news-distribution' ),
						$hold_now['reason']
					),
					$user
				);
			}
		}

		if ( $planned ) {
			$plan          = Plan::normalize( $after['release'] ?? array() );
			$plan['by']    = $user;
			$plan['saved'] = Clock::now();

			$this->write( $post_id, array( 'release' => $plan ) );
			$this->plugin->audit()->record( Audit::PLAN, $post_id, $this->describe( $plan ), $user );
		}

		$this->settle( $post_id );
	}

	/**
	 * The hold in a set of options, with its reason cleaned. Null when there is none.
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array{reason: string}|null
	 */
	private function hold_in( array $options ): ?array {
		$hold   = $options['hold'] ?? null;
		$reason = is_array( $hold ) && isset( $hold['reason'] ) && is_scalar( $hold['reason'] ) ? trim( mb_substr( sanitize_textarea_field( (string) $hold['reason'] ), 0, self::REASON_MAX ) ) : '';

		return '' === $reason ? null : array( 'reason' => $reason );
	}

	/**
	 * The parts of a plan a person chose, without the bookkeeping the class adds.
	 *
	 * @param array<string, mixed> $plan A normalized plan.
	 * @return array<string, mixed>
	 */
	private function significant( array $plan ): array {
		$times = (array) $plan['times'];
		ksort( $times );

		return array(
			'mode'         => $plan['mode'],
			'exclusive'    => $plan['exclusive'],
			'duration'     => $plan['duration'],
			'delay_search' => $plan['delay_search'],
			'times'        => $times,
		);
	}

	/**
	 * Brings the queue and the cached feeds in line with the plan that was just saved.
	 *
	 * @param int $post_id Article ID.
	 * @return void
	 */
	private function settle( int $post_id ): void {
		$language = $this->plugin->languages()->language_of_post( $post_id );

		foreach ( $this->waiting->pending( $post_id ) as $item ) {
			$channel = $this->plugin->registry()->get( $item['channel'] );

			if ( null === $channel || ! in_array( $item['event'], array( Event::PUBLISH, Event::UPDATE ), true ) ) {
				continue;
			}

			$target = $this->push_time( $post_id, $channel, new Event( $item['event'], $post_id, $language ) );

			if ( null === $target ) {
				$this->waiting->park( $item );
			} elseif ( abs( $target - $item['at'] ) > 5 ) {
				$this->waiting->move( $item, max( Clock::now(), $target ) );
			}
		}

		// Pushes that were kept back and that the plan now lets through.
		$this->waiting->requeue_held( $post_id, $this->open_pushed( $post_id ) );

		$this->schedule_flushes( $post_id );
		$this->plugin->cache()->flush();
	}

	/**
	 * Asks the queue to drop the cached feeds when each wait of the article ends, so it joins a feed on time.
	 *
	 * @param int $post_id Article ID.
	 * @return void
	 */
	public function schedule_flushes( int $post_id ): void {
		$times = array();

		foreach ( $this->channels_state( $post_id ) as $row ) {
			if ( null !== $row['until'] && ! $row['left_out'] ) {
				$times[] = $row['until'];
			}
		}

		$this->waiting->schedule_flush( $post_id, $times );
	}
}
