<?php
/**
 * The timeline of the Release plan tab: when each channel gets the article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Control;

use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\ArticleChoices;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Control\Plan;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Hold;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One row for your own site, one for the exclusive, one for the search sitemaps, then one for each
 * other channel or group of channels. Each row says when the article goes out, why, and draws the
 * first two hours as a bar. Everything is worked out from `Core\Hold` and the delivery log, so the
 * screen says what News Distribution will do. It never says when a platform will fetch.
 */
final class Timeline {

	/**
	 * Seconds the bars cover, counted from publishing.
	 */
	public const WINDOW = 7200;

	private Plugin $plugin;

	private Hold $hold;

	private int $post_id;

	private int $base;

	private string $language;

	/**
	 * Creates the timeline of one article.
	 *
	 * @param Plugin $plugin  The running plugin.
	 * @param int    $post_id Article ID.
	 */
	public function __construct( Plugin $plugin, int $post_id ) {
		$this->plugin   = $plugin;
		$this->post_id  = $post_id;
		$this->hold     = $plugin->make( Hold::class );
		$this->base     = $this->hold->base_time( $post_id );
		$this->language = $plugin->languages()->language_of_post( $post_id );
	}

	/**
	 * The rows, in the order they are shown.
	 *
	 * @return array<int, array{label: string, status: string, text: string, why: string, go: int|null, tone: string}>
	 */
	public function rows(): array {
		$rows   = array( $this->site_row() );
		$groups = array();
		$plan   = $this->hold->effective_plan( $this->post_id );

		foreach ( $this->hold->channels_state( $this->post_id ) as $state ) {
			$groups[ $this->bucket( $state ) ][] = $state;
		}

		$order = array();

		foreach ( $groups as $key => $members ) {
			$order[ $key ] = $this->rank( $key, $members, $plan );
		}

		asort( $order );

		foreach ( array_keys( $order ) as $key ) {
			$rows[] = $this->row( $groups[ $key ], $plan );
		}

		return $rows;
	}

	/**
	 * The whole timeline as escaped HTML: the sentence above it and the rows.
	 *
	 * @return string
	 */
	public function render(): string {
		$rows = $this->rows();
		$post = get_post( $this->post_id );
		$live = $post instanceof \WP_Post && 'publish' === $post->post_status;

		$intro = $live
			? sprintf(
				/* translators: 1: a time such as 14:23, 2: a time such as 14:35. */
				__( 'Published %1$s. It is %2$s now. Times are your site’s time.', 'estasoft-news-distribution' ),
				Times::at( $this->base ),
				Times::clock( Clock::now() )
			)
			: __( 'This article is not published yet. The times below count from the moment it is published.', 'estasoft-news-distribution' );

		$intro .= ' ' . __( 'In the bars, green is your own site, blue is a channel that receives the article, and gray is time before it goes out, or all of it when nothing is sent.', 'estasoft-news-distribution' );

		$cells = array();

		foreach ( $rows as $row ) {
			$cells[] = array(
				Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $row['label'] ) ),
				$this->status( $row ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-small' ), esc_html( $row['why'] ) ),
				$this->bar( $row ),
			);
		}

		$grid = Grid::render(
			'estasoft-nd-cols-timeline',
			array(
				__( 'Channel', 'estasoft-news-distribution' ),
				__( 'Goes out', 'estasoft-news-distribution' ),
				__( 'Why', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: 1: a time such as 14:23, 2: a time such as 16:23. */
					__( 'First 2 hours, %1$s to %2$s', 'estasoft-news-distribution' ),
					Times::clock( $this->base ),
					Times::clock( $this->base + self::WINDOW )
				),
			),
			$cells,
			__( 'When each channel gets the article', 'estasoft-news-distribution' )
		);

		return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-ctl-pad' ), esc_html( $intro ) ) . $grid;
	}

	// ---------------------------------------------------------------------
	// Rows.
	// ---------------------------------------------------------------------

	/**
	 * The row for your own site.
	 *
	 * @return array{label: string, status: string, text: string, why: string, go: int|null, tone: string}
	 */
	private function site_row(): array {
		$post = get_post( $this->post_id );
		$live = $post instanceof \WP_Post && 'publish' === $post->post_status;

		return array(
			'label'  => __( 'Your own site', 'estasoft-news-distribution' ),
			'status' => $live ? Status::IN_FEED : Status::QUEUED,
			'text'   => $live
				? sprintf(
					/* translators: %s: a time such as 14:23. */
					__( 'Published %s', 'estasoft-news-distribution' ),
					Times::at( $this->base )
				)
				: __( 'When you publish', 'estasoft-news-distribution' ),
			'why'    => __( 'Always first, so it is crawled first.', 'estasoft-news-distribution' ),
			'go'     => $this->base,
			'tone'   => 'site',
		);
	}

	/**
	 * Which row a channel belongs to. Search sitemaps and open readers share one row each.
	 *
	 * @param array<string, mixed> $state One entry of `Hold::channels_state()`.
	 * @return string
	 */
	private function bucket( array $state ): string {
		// A channel left out of this article has a row of its own, even where it would share one.
		if ( ! empty( $state['left_out'] ) ) {
			return (string) $state['id'];
		}

		if ( Group::SITEMAP === $state['group'] ) {
			return 'sitemap';
		}

		if ( Group::READERS === $state['group'] ) {
			return 'readers';
		}

		return (string) $state['id'];
	}

	/**
	 * Where a row sits: the exclusive, the sitemaps, partner feeds, readers, pushes, then channels still in review.
	 *
	 * @param string                           $key     The bucket.
	 * @param array<int, array<string, mixed>> $members The channels in it.
	 * @param array<string, mixed>             $plan    The plan.
	 * @return int
	 */
	private function rank( string $key, array $members, array $plan ): int {
		$first = $members[0];

		if ( $first['review'] ) {
			return 9000;
		}

		if ( Plan::MODE_EXCLUSIVE === $plan['mode'] && $key === $plan['exclusive'] ) {
			return 10;
		}

		$groups = array(
			Group::SITEMAP => 20,
			Group::PARTNER => 30,
			Group::READERS => 40,
			Group::PUSHES  => 50,
		);

		return ( $groups[ $first['group'] ] ?? 60 ) * 100 + (int) $this->order_of( (string) $first['id'] );
	}

	/**
	 * A channel's own order inside its group.
	 *
	 * @param string $id Channel id.
	 * @return int
	 */
	private function order_of( string $id ): int {
		$channel = $this->plugin->registry()->get( $id );

		return null === $channel ? 0 : $channel->order();
	}

	/**
	 * One row for a channel, or for the channels that share one.
	 *
	 * @param array<int, array<string, mixed>> $members The channels in the row.
	 * @param array<string, mixed>             $plan    The plan.
	 * @return array{label: string, status: string, text: string, why: string, go: int|null, tone: string}
	 */
	private function row( array $members, array $plan ): array {
		$state   = $members[0];
		$channel = $this->plugin->registry()->get( (string) $state['id'] );
		$label   = $this->label( $members );

		if ( ! empty( $state['left_out'] ) ) {
			return array(
				'label'  => $label,
				'status' => Status::OFF,
				'text'   => Status::word( Status::OFF ),
				'why'    => ArticleChoices::message(),
				'go'     => null,
				'tone'   => 'none',
			);
		}

		if ( $state['review'] ) {
			return array(
				'label'  => $label,
				'status' => Status::IN_REVIEW,
				'text'   => Status::word( Status::IN_REVIEW ),
				'why'    => sprintf(
					/* translators: %s: a channel name such as Apple News. */
					__( '%s has not approved the channel, so nothing is sent to it and this plan does not apply yet.', 'estasoft-news-distribution' ),
					$label
				),
				'go'     => null,
				'tone'   => 'none',
			);
		}

		if ( Hold::HELD === $state['state'] ) {
			return array(
				'label'  => $label,
				'status' => Status::QUEUED,
				'text'   => __( 'On hold', 'estasoft-news-distribution' ),
				'why'    => sprintf(
					/* translators: %s: the reason the person typed when they held the article. */
					__( 'Held: %s. Nothing goes out until you release the hold.', 'estasoft-news-distribution' ),
					(string) $state['reason']
				),
				'go'     => null,
				'tone'   => 'none',
			);
		}

		if ( null === $channel ) {
			return $this->unknown( $label );
		}

		return ChannelInterface::MODE_PUSHED === $channel->mode()
			? $this->push_row( $channel, $label, $state, $plan )
			: $this->feed_row( $channel, $label, $state, $plan );
	}

	/**
	 * A row for a channel that fetches a document.
	 *
	 * @param ChannelInterface     $channel The channel.
	 * @param string               $label   The row's name.
	 * @param array<string, mixed> $state   Its decision.
	 * @param array<string, mixed> $plan    The plan.
	 * @return array{label: string, status: string, text: string, why: string, go: int|null, tone: string}
	 */
	private function feed_row( ChannelInterface $channel, string $label, array $state, array $plan ): array {
		$sitemap   = Group::SITEMAP === $channel->group();
		$exclusive = Plan::MODE_EXCLUSIVE === $plan['mode'] && $channel->id() === $plan['exclusive'];

		if ( Hold::WAITING === $state['state'] ) {
			$until = null === $state['until'] ? null : (int) $state['until'];

			return array(
				'label'  => $label,
				'status' => Status::QUEUED,
				'text'   => null === $until
					? __( 'Waiting', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: a time such as 14:38. */
						__( 'Waiting %s', 'estasoft-news-distribution' ),
						Times::at( $until )
					),
				'why'    => $this->wait_reason( $channel, $label, $plan, $sitemap ),
				'go'     => $until,
				'tone'   => 'feed',
			);
		}

		if ( $exclusive ) {
			$why = sprintf(
				/* translators: %s: a channel name such as Microsoft Start. */
				__( 'The exclusive: it is in the feed from the moment the article is published. %s fetches on its own schedule.', 'estasoft-news-distribution' ),
				$label
			);
		} elseif ( $sitemap ) {
			$why = __( 'Not delayed: crawlers read your site anyway.', 'estasoft-news-distribution' );
		} else {
			$why = $this->open_reason( $label, $plan );
		}

		$when = max( $this->base, $this->ended_at( $plan ) );

		return array(
			'label'  => $label,
			'status' => Status::IN_FEED,
			'text'   => sprintf(
				/* translators: %s: a time such as 14:23. */
				__( 'In feed %s', 'estasoft-news-distribution' ),
				Times::at( $when )
			),
			'why'    => $why,
			'go'     => $when,
			'tone'   => 'feed',
		);
	}

	/**
	 * A row for a channel News Distribution sends to.
	 *
	 * @param ChannelInterface     $channel The channel.
	 * @param string               $label   The row's name.
	 * @param array<string, mixed> $state   Its decision.
	 * @param array<string, mixed> $plan    The plan.
	 * @return array{label: string, status: string, text: string, why: string, go: int|null, tone: string}
	 */
	private function push_row( ChannelInterface $channel, string $label, array $state, array $plan ): array {
		$latest  = $this->plugin->log()->latest_for_post( $this->post_id )[ $channel->id() ] ?? null;
		$latest  = null !== $latest && Event::UNPUBLISH !== $latest['event'] ? $latest : null;
		$waiting = Hold::WAITING === $state['state'];

		if ( ! $waiting && null !== $latest && in_array( $latest['status'], array( Status::SENT, Status::FAILED, Status::RETRY, Status::SKIPPED ), true ) ) {
			$when = (int) $latest['created_ts'];
			$code = (string) $latest['status'];

			return array(
				'label'  => $label,
				'status' => $code,
				'text'   => Status::SENT === $code
					? sprintf(
						/* translators: %s: a time such as 14:38. */
						__( 'Sent %s', 'estasoft-news-distribution' ),
						Times::at( $when )
					)
					: Status::word( $code ),
				'why'    => Status::SENT === $code ? __( 'Already sent. A plan changes only what has not gone out.', 'estasoft-news-distribution' ) : (string) $latest['message'],
				'go'     => Status::SENT === $code ? $when : null,
				'tone'   => Status::SENT === $code ? 'feed' : 'none',
			);
		}

		$time   = $this->hold->push_time( $this->post_id, $channel, new Event( Event::PUBLISH, $this->post_id, $this->language ) );
		$queued = null !== $latest && Status::QUEUED === $latest['status'];

		if ( ! $waiting && $queued && (int) $latest['run_at_ts'] > 0 ) {
			$time = (int) $latest['run_at_ts'];
		}

		if ( null === $time ) {
			return array(
				'label'  => $label,
				'status' => Status::QUEUED,
				'text'   => __( 'Waiting', 'estasoft-news-distribution' ),
				'why'    => $this->wait_reason( $channel, $label, $plan, false ),
				'go'     => null,
				'tone'   => 'none',
			);
		}

		if ( $time <= Clock::now() && ! $queued && ! $waiting ) {
			return array(
				'label'  => $label,
				'status' => Status::OFF,
				'text'   => __( 'Not sent yet', 'estasoft-news-distribution' ),
				'why'    => __( 'No delivery is recorded for this article. A channel only sends articles published while it is on.', 'estasoft-news-distribution' ),
				'go'     => null,
				'tone'   => 'none',
			);
		}

		return array(
			'label'  => $label,
			'status' => Status::QUEUED,
			'text'   => sprintf(
				/* translators: %s: a time such as 14:38. */
				__( 'Waiting %s', 'estasoft-news-distribution' ),
				Times::at( $time )
			),
			'why'    => $waiting ? $this->wait_reason( $channel, $label, $plan, false ) : $this->push_reason( $channel, $plan, $time ),
			'go'     => $time,
			'tone'   => 'feed',
		);
	}

	/**
	 * A row for a channel the registry no longer knows.
	 *
	 * @param string $label The row's name.
	 * @return array{label: string, status: string, text: string, why: string, go: int|null, tone: string}
	 */
	private function unknown( string $label ): array {
		return array(
			'label'  => $label,
			'status' => Status::OFF,
			'text'   => Status::word( Status::OFF ),
			'why'    => '',
			'go'     => null,
			'tone'   => 'none',
		);
	}

	// ---------------------------------------------------------------------
	// Words.
	// ---------------------------------------------------------------------

	/**
	 * The name on a row: one channel's name, or the name of a group of channels that share it.
	 *
	 * @param array<int, array<string, mixed>> $members The channels in the row.
	 * @return string
	 */
	private function label( array $members ): string {
		if ( ! empty( $members[0]['left_out'] ) ) {
			return (string) $members[0]['label'];
		}

		if ( count( $members ) < 2 && Group::SITEMAP !== $members[0]['group'] ) {
			return (string) $members[0]['label'];
		}

		if ( Group::SITEMAP === $members[0]['group'] ) {
			return __( 'Google News and search sitemaps', 'estasoft-news-distribution' );
		}

		if ( Group::READERS === $members[0]['group'] ) {
			return __( 'Flipboard and feed readers', 'estasoft-news-distribution' );
		}

		return wp_sprintf( '%l', array_column( $members, 'label' ) );
	}

	/**
	 * Why a channel is waiting.
	 *
	 * @param ChannelInterface     $channel The channel.
	 * @param string               $label   The row's name.
	 * @param array<string, mixed> $plan    The plan.
	 * @param bool                 $sitemap Whether it is a search sitemap.
	 * @return string
	 */
	private function wait_reason( ChannelInterface $channel, string $label, array $plan, bool $sitemap ): string {
		if ( Plan::MODE_CUSTOM === $plan['mode'] ) {
			$seconds = Plan::custom_seconds( $plan, $channel->id() );

			return null === $seconds
				? __( 'Your plan says nothing about it, so it goes out as usual.', 'estasoft-news-distribution' )
				: sprintf(
					/* translators: %s: a length of time such as "1 hour". */
					__( 'Your plan: %s after publishing.', 'estasoft-news-distribution' ),
					Plan::words( $seconds )
				);
		}

		if ( $sitemap ) {
			return __( 'Added to the sitemaps when the exclusive ends, as you asked.', 'estasoft-news-distribution' );
		}

		if ( ChannelInterface::MODE_PUSHED === $channel->mode() ) {
			$words = array(
				'websub'   => __( 'Pinged when the exclusive ends.', 'estasoft-news-distribution' ),
				'indexnow' => __( 'Sent when the exclusive ends.', 'estasoft-news-distribution' ),
				'outlets'  => __( 'Posts to your connected outlets go out when the exclusive ends. Medium is imported by hand.', 'estasoft-news-distribution' ),
			);

			return $words[ $channel->id() ] ?? __( 'Goes out when the exclusive ends.', 'estasoft-news-distribution' );
		}

		if ( Group::READERS === $channel->group() ) {
			return __( 'Added to the feed when the exclusive ends.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: a channel name such as SmartNews. */
			__( 'Added to your %s feed when the exclusive ends.', 'estasoft-news-distribution' ),
			$label
		);
	}

	/**
	 * Why a feed has the article already.
	 *
	 * @param string               $label The row's name.
	 * @param array<string, mixed> $plan  The plan.
	 * @return string
	 */
	private function open_reason( string $label, array $plan ): string {
		if ( Plan::MODE_EXCLUSIVE === $plan['mode'] && $plan['ended'] > 0 ) {
			return __( 'Added when the exclusive ended.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: a channel name such as SmartNews. */
			__( 'In the feed from the moment the article is published. %s fetches on its own schedule.', 'estasoft-news-distribution' ),
			$label
		);
	}

	/**
	 * Why a push goes out when it does, when no plan is making it wait.
	 *
	 * @param ChannelInterface     $channel The channel.
	 * @param array<string, mixed> $plan    The plan.
	 * @param int                  $time    The time it goes out.
	 * @return string
	 */
	private function push_reason( ChannelInterface $channel, array $plan, int $time ): string {
		if ( Plan::MODE_NOW === $plan['mode'] ) {
			return __( 'Right away, as you chose.', 'estasoft-news-distribution' );
		}

		if ( Plan::MODE_CUSTOM === $plan['mode'] && null !== Plan::custom_seconds( $plan, $channel->id() ) ) {
			return $this->wait_reason( $channel, $channel->label(), $plan, false );
		}

		$gap = $time - $this->base;

		return $gap > 0
			? sprintf(
				/* translators: %s: a length of time such as "15 minutes". */
				__( 'Sent %s after publishing, so your site is crawled first.', 'estasoft-news-distribution' ),
				Plan::words( $gap )
			)
			: __( 'Sent as soon as the article is published.', 'estasoft-news-distribution' );
	}

	/**
	 * When the exclusive ended, if it ended before its time, so a channel's "In feed" shows the right moment.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @return int Unix time, or 0.
	 */
	private function ended_at( array $plan ): int {
		if ( Plan::MODE_EXCLUSIVE !== $plan['mode'] ) {
			return 0;
		}

		return $plan['ended'] > 0 ? (int) $plan['ended'] : 0;
	}

	// ---------------------------------------------------------------------
	// Drawing.
	// ---------------------------------------------------------------------

	/**
	 * The status cell: icon and words, with the time.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function status( array $row ): string {
		if ( Status::IN_REVIEW === $row['status'] ) {
			return StatusView::pill( Status::IN_REVIEW );
		}

		return StatusView::inline( (string) $row['status'], (string) $row['text'] );
	}

	/**
	 * The bar: gray for the time before the channel has the article, then blue (green for your site).
	 *
	 * Two spans in a row, so a right-to-left page draws it from the right without extra rules.
	 *
	 * @param array<string, mixed> $row A row.
	 * @return string
	 */
	private function bar( array $row ): string {
		$go = $row['go'];

		if ( null === $go ) {
			$start = 100.0;
			$words = __( 'Nothing is sent.', 'estasoft-news-distribution' );
		} else {
			$start = max( 0.0, min( 100.0, ( ( (int) $go - $this->base ) / self::WINDOW ) * 100 ) );
			$words = sprintf(
				/* translators: %s: a time such as 14:38. */
				__( 'Has the article from %s.', 'estasoft-news-distribution' ),
				Times::at( (int) $go )
			);
		}

		return Html::tag(
			'span',
			array(
				'class'      => 'estasoft-nd-ctl-bar',
				'role'       => 'img',
				'aria-label' => $words,
			),
			Html::tag(
				'span',
				array(
					'class' => 'estasoft-nd-ctl-bar__wait',
					'style' => 'width:' . round( $start, 2 ) . '%',
				),
				''
			)
			. Html::tag(
				'span',
				array(
					'class' => 'estasoft-nd-ctl-bar__go estasoft-nd-ctl-bar__go--' . ( 'site' === $row['tone'] ? 'site' : 'feed' ),
					'style' => 'width:' . round( 100 - $start, 2 ) . '%',
				),
				''
			)
		);
	}
}
