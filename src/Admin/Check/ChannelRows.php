<?php
/**
 * The rows that say what happened to one article on each channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\SearchSitemap\AbstractSearchSitemapChannel;
use Estasoft\NewsDistribution\Core\ArticleChoices;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One row for each channel that is on and takes the article's language, grouped the way the
 * Channels hub groups them. A sentence is built only from what is known: the delivery log, the
 * fetch log, the stored check and the channel's own feed window. Where a fact is unknown the row
 * says so instead of guessing.
 */
final class ChannelRows {

	private Plugin $plugin;

	private Context $ctx;

	private Links $links;

	/**
	 * The latest fetch of each channel and language, once read.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $fetches = null;

	/**
	 * The latest fetch by crawler name, once read, for the channels that share a sitemap.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $agent_fetches = array();

	/**
	 * @param Plugin  $plugin The running plugin.
	 * @param Context $ctx    The article.
	 * @param Links   $links  Addresses of other screens.
	 */
	public function __construct( Plugin $plugin, Context $ctx, Links $links ) {
		$this->plugin = $plugin;
		$this->ctx    = $ctx;
		$this->links  = $links;
	}

	/**
	 * A heading bar that starts a group of rows.
	 *
	 * @param string $title The group name.
	 * @return string Escaped HTML.
	 */
	public static function strip( string $title ): string {
		return Html::tag( 'h3', array( 'class' => 'estasoft-nd-strip' ), esc_html( $title ) );
	}

	/**
	 * One row: a name with a status under it, the text, and an action on the right.
	 *
	 * @param string $name   Escaped HTML of the name.
	 * @param string $status Escaped HTML of the status pill, or empty.
	 * @param string $body   Escaped HTML of the text.
	 * @param string $action Escaped HTML of a link, or empty.
	 * @return string
	 */
	public static function row( string $name, string $status, string $body, string $action = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-resrow' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-resrow__name' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-resrow__title' ), $name ) . $status
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-resrow__body' ), $body )
			. ( '' === $action ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-resrow__action' ), $action ) )
		);
	}

	/**
	 * The channels that matter to one article, grouped. Channels the person sets up by hand
	 * (listings, site verification) do nothing for a single article and are left out.
	 *
	 * @return array<string, ChannelInterface[]> Group id => channels in hub order.
	 */
	public function groups(): array {
		$groups = array();

		foreach ( $this->ctx->active() as $channel ) {
			if ( ChannelInterface::MODE_MANUAL === $channel->mode() ) {
				continue;
			}

			$groups[ $channel->group() ][] = $channel;
		}

		$out = array();

		foreach ( array_keys( Group::all() ) as $group ) {
			if ( ! isset( $groups[ $group ] ) ) {
				continue;
			}

			usort(
				$groups[ $group ],
				static function ( ChannelInterface $a, ChannelInterface $b ): int {
					return $a->order() <=> $b->order();
				}
			);

			$out[ $group ] = $groups[ $group ];
		}

		return $out;
	}

	/**
	 * The channel rows of the Checks tab, group by group, and the closing note about channels that are off.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$html  = '';
		$names = Group::all();

		foreach ( $this->groups() as $group => $channels ) {
			$html .= self::strip( $names[ $group ]['title'] );

			foreach ( $channels as $channel ) {
				$html .= $this->channel_row( $channel, $group );
			}
		}

		return $html . $this->not_set_up();
	}

	/**
	 * The row of one channel on the Checks tab.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param string           $group   Its group id.
	 * @return string Escaped HTML.
	 */
	private function channel_row( ChannelInterface $channel, string $group ): string {
		$id = $channel->id();

		$choices = $this->plugin->make( ArticleChoices::class );
		$kept_by = array();

		if ( $choices->is_left_out( $this->ctx->id(), $id ) ) {
			if ( $choices->leaves_out( $this->ctx->id(), $channel ) ) {
				return $this->left_out_row( $channel );
			}

			$kept_by = $choices->kept_by( $this->ctx->id(), $channel );
		}

		$findings = $this->ctx->channel_findings( $id );
		$result   = $this->ctx->results()[ $id ] ?? null;
		$latest   = $this->ctx->latest()[ $id ] ?? null;
		$sentence = $this->sentence( $channel, $group, $result, $latest );
		$notes    = $findings;

		// A finding that blocks the channel is already the sentence of a skipped row; show the others as notes.
		if ( null !== $result && Status::SKIPPED === $result['status'] ) {
			$notes = array_values(
				array_filter(
					$findings,
					static function ( array $finding ): bool {
						return Status::BLOCK !== ( $finding['severity'] ?? '' );
					}
				)
			);
		}

		$body = Html::tag( 'p', array(), esc_html( $sentence ) ) . $this->shared_note( $kept_by ) . Findings::list( $notes, $this->ctx->id() ) . $this->rule_source( $id, $findings );

		return self::row( esc_html( $channel->label() ), $this->pill( $result, $findings ), $body, $this->action( $channel, $latest, $findings ) );
	}

	/**
	 * Says why a channel that was switched off still carries the article: its sitemap is shared.
	 *
	 * @param string[] $names The other channels that read the same sitemap and are still on for this article.
	 * @return string Escaped HTML, or an empty string.
	 */
	private function shared_note( array $names ): string {
		if ( array() === $names ) {
			return '';
		}

		return Html::tag(
			'p',
			array(),
			esc_html(
				sprintf(
					/* translators: %s: channel names such as "Bing, Baidu". */
					__( 'This channel is switched off for this article, but it reads a sitemap shared with %s, which are still on. The sitemap carries the article until all of them are switched off.', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $names )
				)
			)
		);
	}

	/**
	 * The row of a channel the person switched off for this article: Off, with the reason. It is a choice, not a
	 * problem, so no finding is listed under it.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return string Escaped HTML.
	 */
	private function left_out_row( ChannelInterface $channel ): string {
		return self::row(
			esc_html( $channel->label() ),
			StatusView::pill( Status::OFF ),
			Html::tag( 'p', array(), esc_html__( 'Left out by a choice for this article. Turn the channel back on in the News Distribution panel of the editor.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The status pill of a channel row.
	 *
	 * @param array{status: string, at: string|null, message: string}|null $result   The result, or null for an article that is not published.
	 * @param array<int, array<string, mixed>>                             $findings Findings for the channel.
	 * @return string Escaped HTML.
	 */
	private function pill( ?array $result, array $findings ): string {
		if ( null !== $result ) {
			return StatusView::pill( $result['status'] );
		}

		$worst = Findings::worst( $findings );

		if ( Status::BLOCK === $worst || Status::WARN === $worst ) {
			return StatusView::pill( $worst );
		}

		return StatusView::pill( Status::QUEUED, __( 'Not published yet', 'estasoft-news-distribution' ) );
	}

	/**
	 * The sentence of a channel row.
	 *
	 * @param ChannelInterface                                             $channel The channel.
	 * @param string                                                       $group   Its group id.
	 * @param array{status: string, at: string|null, message: string}|null $result  The result.
	 * @param array<string, mixed>|null                                    $latest  The latest delivery row.
	 * @return string Plain text.
	 */
	private function sentence( ChannelInterface $channel, string $group, ?array $result, ?array $latest ): string {
		if ( null === $result ) {
			return $this->draft_sentence( $channel );
		}

		$status = $result['status'];

		if ( Status::IN_FEED === $status ) {
			return trim( $this->window_sentence( $channel, $group ) . ' ' . $this->fetch_sentence( $channel ) );
		}

		if ( Status::OFF === $status && ChannelInterface::MODE_PULLED === $channel->mode() && null === $latest ) {
			$window = $channel->feed_window();

			return sprintf(
				/* translators: 1: a channel name, 2: a length of time such as "2 days". */
				__( '%1$s keeps an article for %2$s after publishing, and this one is past that.', 'estasoft-news-distribution' ),
				$channel->label(),
				$this->length( (int) $window['max_age'] )
			);
		}

		if ( Status::OFF === $status && ChannelInterface::MODE_PUSHED === $channel->mode() && null === $latest ) {
			return sprintf(
				/* translators: %s: a channel name. */
				__( 'Nothing was sent to %s for this article. It may have been turned on after the article was published.', 'estasoft-news-distribution' ),
				$channel->label()
			);
		}

		if ( null !== $latest && $latest['status'] === $status ) {
			return $this->delivery_sentence( $latest );
		}

		return '' !== $result['message'] ? $result['message'] : Status::word( $status ) . '.';
	}

	/**
	 * What a channel row says about an article that is not published.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return string Plain text.
	 */
	private function draft_sentence( ChannelInterface $channel ): string {
		if ( ! $this->ctx->eligible( $channel->id() ) ) {
			$first = '';

			foreach ( $this->ctx->channel_findings( $channel->id() ) as $finding ) {
				if ( Status::BLOCK === ( $finding['severity'] ?? '' ) ) {
					$first = (string) $finding['message'];
					break;
				}
			}

			return sprintf(
				/* translators: 1: a channel name, 2: a reason in one sentence. */
				__( '%1$s would skip this article as it is. %2$s', 'estasoft-news-distribution' ),
				$channel->label(),
				$first
			);
		}

		return sprintf(
			/* translators: %s: a channel name. */
			__( 'The article is not published yet, so %s has nothing to read.', 'estasoft-news-distribution' ),
			$channel->label()
		);
	}

	/**
	 * How long an article stays in a feed or sitemap, and until when.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param string           $group   Its group id.
	 * @return string Plain text.
	 */
	private function window_sentence( ChannelInterface $channel, string $group ): string {
		$window  = $channel->feed_window();
		$article = $this->ctx->article();

		if ( null === $article || $article->published_ts() < 1 ) {
			return __( 'Included.', 'estasoft-news-distribution' );
		}

		$where = Group::SITEMAP === $group ? __( 'the sitemap', 'estasoft-news-distribution' ) : __( 'the feed', 'estasoft-news-distribution' );

		// A search sitemap holds an article for as long as it fits; the channel says so with a window of decades.
		if ( (int) $window['max_age'] >= 10 * YEAR_IN_SECONDS ) {
			/* translators: %s: "the sitemap" or "the feed". */
			return sprintf( __( 'Included in %s, with no end date.', 'estasoft-news-distribution' ), $where );
		}

		$ends = $article->published_ts() + (int) $window['max_age'];

		return sprintf(
			/* translators: 1: "the sitemap" or "the feed", 2: a date and time such as "23 Oct, 13:41", 3: a length of time such as "2 days". */
			__( 'Included in %1$s until %2$s, %3$s after publishing.', 'estasoft-news-distribution' ),
			$where,
			Times::short( $ends ),
			$this->length( (int) $window['max_age'] )
		);
	}

	/**
	 * When the platform last read the feed, compared with the article's last edit.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return string Plain text.
	 */
	private function fetch_sentence( ChannelInterface $channel ): string {
		$fetch = $this->last_fetch( $channel->id() );

		if ( null === $fetch ) {
			return __( 'It has not fetched yet.', 'estasoft-news-distribution' );
		}

		$at = (int) $fetch['created_ts'];

		if ( ! in_array( (string) $fetch['status'], array( 'ok', 'not_modified' ), true ) ) {
			return sprintf(
				/* translators: %s: a time such as "14:31". */
				__( 'Its last request, at %s, did not get the feed.', 'estasoft-news-distribution' ),
				Times::at( $at )
			);
		}

		$article   = $this->ctx->article();
		$published = null === $article ? 0 : $article->published_ts();
		$modified  = null === $article ? 0 : $article->modified_ts();

		if ( $published > 0 && $at < $published ) {
			/* translators: %s: a time such as "14:31". */
			return sprintf( __( 'Last fetched at %s, before this article was published.', 'estasoft-news-distribution' ), Times::at( $at ) );
		}

		if ( $modified > $published && $at < $modified ) {
			return sprintf(
				/* translators: 1: a time such as "14:31", 2: a time such as "14:32". */
				__( 'Last fetched at %1$s, before the %2$s edit.', 'estasoft-news-distribution' ),
				Times::at( $at ),
				Times::at( $modified )
			);
		}

		/* translators: %s: a time such as "14:31". */
		return sprintf( __( 'Last fetched at %s, after the last edit.', 'estasoft-news-distribution' ), Times::at( $at ) );
	}

	/**
	 * The latest fetch of a channel, for the article's language.
	 *
	 * Bing, Yandex and Baidu read one sitemap, and the log names the channel that answered, so their fetches are read by
	 * crawler name: a Baiduspider fetch that Bing answered still counts as Baidu's.
	 *
	 * @param string $channel_id Channel id.
	 * @return array<string, mixed>|null
	 */
	public function last_fetch( string $channel_id ): ?array {
		$crawl = $this->shared_crawl( $channel_id );

		if ( null !== $crawl ) {
			if ( ! isset( $this->agent_fetches[ $channel_id ] ) ) {
				$this->agent_fetches[ $channel_id ] = $this->plugin->fetch_log()->latest_by_agent( $crawl[0], $crawl[1] );
			}

			$rows = $this->agent_fetches[ $channel_id ];

			return $rows[ $this->ctx->language() ] ?? ( $rows[''] ?? null );
		}

		if ( null === $this->fetches ) {
			$this->fetches = $this->plugin->fetch_log()->latest();
		}

		$key = $channel_id . '|' . $this->ctx->language();

		if ( isset( $this->fetches[ $key ] ) ) {
			return $this->fetches[ $key ];
		}

		$found = null;

		foreach ( $this->fetches as $name => $row ) {
			if ( 0 === strpos( (string) $name, $channel_id . '|' ) && ( null === $found || (int) $row['created_ts'] > (int) $found['created_ts'] ) ) {
				$found = $row;
			}
		}

		return $found;
	}

	/**
	 * Fetches of a channel since a time, newest first: by crawler name for the channels that share a sitemap.
	 *
	 * @param string $channel_id Channel id.
	 * @param int    $since      Unix time.
	 * @param int    $limit      Most rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetches_since( string $channel_id, int $since, int $limit = 200 ): array {
		$crawl = $this->shared_crawl( $channel_id );

		return null === $crawl
			? $this->plugin->fetch_log()->for_channel( $channel_id, $since, $limit )
			: $this->plugin->fetch_log()->for_agents( $crawl[0], $crawl[1], $since, $limit );
	}

	/**
	 * The channels that can answer a shared search sitemap and the crawler names of one channel, or null when the
	 * channel has a document of its own.
	 *
	 * @param string $channel_id Channel id.
	 * @return array{0: string[], 1: string[]}|null
	 */
	private function shared_crawl( string $channel_id ): ?array {
		$channel = $this->plugin->registry()->get( $channel_id );

		if ( ! $channel instanceof AbstractSearchSitemapChannel ) {
			return null;
		}

		$agents = $channel->fetch_agents();

		return array() === $agents ? null : array( AbstractSearchSitemapChannel::ANSWERING, $agents );
	}

	/**
	 * One delivery row as a sentence: what was sent, when, and what the platform answered.
	 *
	 * @param array<string, mixed> $row A delivery row from the log.
	 * @return string Plain text.
	 */
	public function delivery_sentence( array $row ): string {
		$event   = self::event_word( (string) $row['event'] );
		$status  = (string) $row['status'];
		$code    = (int) $row['response_code'];
		$attempt = (int) $row['attempt'];
		$at      = Times::at( (int) $row['created_ts'] );
		$run_at  = (int) ( $row['run_at_ts'] ?? 0 );
		$message = trim( (string) $row['message'] );
		$answer  = $code > 0 && false === strpos( $message, (string) $code ) ? sprintf(
			/* translators: %d: an HTTP status code such as 200. */
			__( ' and answered %d', 'estasoft-news-distribution' ),
			$code
		) : '';

		switch ( $status ) {
			case Status::SENT:
				/* translators: 1: an event such as "Update", 2: a time, 3: empty or " and answered 200". */
				$text = sprintf( __( '%1$s sent at %2$s%3$s.', 'estasoft-news-distribution' ), $event, $at, $answer );
				break;
			case Status::QUEUED:
				$text = $run_at > 0
					/* translators: 1: an event such as "Update", 2: a time such as "14:38". */
					? sprintf( __( '%1$s waiting until %2$s.', 'estasoft-news-distribution' ), $event, Times::at( $run_at ) )
					/* translators: %s: an event such as "Update". */
					: sprintf( __( '%s waiting in the queue.', 'estasoft-news-distribution' ), $event );
				break;
			case Status::RETRY:
				$text = sprintf(
					/* translators: 1: an event such as "Update", 2: an attempt number, 3: a time such as "14:38". */
					__( '%1$s attempt %2$d did not go through. The next try is at %3$s.', 'estasoft-news-distribution' ),
					$event,
					$attempt,
					$run_at > 0 ? Times::at( $run_at ) : $at
				);
				break;
			case Status::FAILED:
				$text = sprintf(
					/* translators: 1: an event such as "Update", 2: a number of attempts, 3: a time such as "14:17". */
					_n( '%1$s failed after %2$d attempt (last at %3$s).', '%1$s failed after %2$d attempts (last at %3$s).', max( 1, $attempt ), 'estasoft-news-distribution' ),
					$event,
					$attempt,
					$at
				);
				break;
			case Status::SKIPPED:
				/* translators: 1: an event such as "Update", 2: a time such as "14:32". */
				$text = sprintf( __( '%1$s skipped at %2$s.', 'estasoft-news-distribution' ), $event, $at );
				break;
			default:
				/* translators: 1: an event such as "Update", 2: a time such as "14:32". */
				$text = sprintf( __( '%1$s not sent at %2$s.', 'estasoft-news-distribution' ), $event, $at );
		}

		return '' === $message ? $text : $text . ' ' . $message;
	}

	/**
	 * The word for an event in a sentence.
	 *
	 * @param string $type An `Core\Event` type, `test` or `catchup`.
	 * @return string
	 */
	public static function event_word( string $type ): string {
		switch ( $type ) {
			case Event::PUBLISH:
				return __( 'Publish', 'estasoft-news-distribution' );
			case Event::UPDATE:
				return __( 'Update', 'estasoft-news-distribution' );
			case Event::UNPUBLISH:
				return __( 'Takedown', 'estasoft-news-distribution' );
			case Event::DELETE:
				return __( 'Delete', 'estasoft-news-distribution' );
		}

		return __( 'Send', 'estasoft-news-distribution' );
	}

	/**
	 * A length of time in words: "2 days".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private function length( int $seconds ): string {
		return human_time_diff( 0, max( 60, $seconds ) );
	}

	/**
	 * The link on the right of a channel row.
	 *
	 * @param ChannelInterface                 $channel  The channel.
	 * @param array<string, mixed>|null        $latest   The latest delivery row.
	 * @param array<int, array<string, mixed>> $findings Findings for the channel.
	 * @return string Escaped HTML.
	 */
	private function action( ChannelInterface $channel, ?array $latest, array $findings ): string {
		if ( null !== $latest ) {
			$url = $this->links->page(
				'estasoft-nd-log',
				array(
					'post'    => $this->ctx->id(),
					'channel' => $channel->id(),
				)
			);

			if ( '' !== $url ) {
				return Html::tag( 'a', array( 'href' => $url ), esc_html__( 'View in delivery log', 'estasoft-news-distribution' ) );
			}
		}

		foreach ( $findings as $finding ) {
			if ( in_array( (string) ( $finding['code'] ?? '' ), array( 'image.too_small', 'image.bad_ratio' ), true ) ) {
				$url = $this->links->tab( 'images', 0 );

				if ( '' !== $url ) {
					return Html::tag( 'a', array( 'href' => $url ), esc_html__( 'Open image presets', 'estasoft-news-distribution' ) );
				}
			}
		}

		return '';
	}

	/**
	 * The line that says where the first rule of a channel's findings comes from.
	 *
	 * Only a rule with a web address as its source is linked. A rule News Distribution wrote
	 * itself says so, since it is not something the platform published.
	 *
	 * @param string                           $channel_id Channel id.
	 * @param array<int, array<string, mixed>> $findings   Findings for the channel.
	 * @return string Escaped HTML, or an empty string.
	 */
	private function rule_source( string $channel_id, array $findings ): string {
		$service = $this->plugin->make( ArticleService::class );

		foreach ( Findings::sorted( $findings ) as $finding ) {
			$rule = $service->rule_for( $channel_id, (string) ( $finding['code'] ?? '' ) );

			if ( null === $rule || 0 !== strpos( $rule['source'], 'http' ) ) {
				continue;
			}

			return self::source_line( $rule, $this->plugin->rules()->pack( $rule['pack'] ) );
		}

		return '';
	}

	/**
	 * "Rule source: …" with the source page, and when it was read or that it was not confirmed.
	 *
	 * @param array<string, mixed> $rule The rule from `ArticleService::rule_for()`.
	 * @param array<string, mixed> $pack The rule pack it comes from.
	 * @return string Escaped HTML.
	 */
	public static function source_line( array $rule, array $pack ): string {
		$title = isset( $pack['source_url'], $pack['source_title'] ) && (string) $pack['source_url'] === (string) $rule['source']
			? (string) $pack['source_title']
			: (string) wp_parse_url( (string) $rule['source'], PHP_URL_HOST );
		$link  = Html::tag(
			'a',
			array(
				'href'   => (string) $rule['source'],
				'target' => '_blank',
				'rel'    => 'noopener noreferrer',
			),
			esc_html( $title )
		);
		$read  = Times::from_iso( ! empty( $rule['read_on'] ) ? $rule['read_on'] . 'T00:00:00Z' : null );

		if ( 'confirmed' === $rule['confidence'] && $read > 0 ) {
			$note = sprintf(
				/* translators: %s: a date such as "21 Oct 2026". */
				__( 'read on %s', 'estasoft-news-distribution' ),
				Clock::format( $read, 'j M Y' )
			);
		} elseif ( 'softened' === $rule['confidence'] ) {
			$note = __( 'the platform’s wording is softer than the rule', 'estasoft-news-distribution' );
		} else {
			$note = __( 'not confirmed', 'estasoft-news-distribution' );
		}

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-source' ),
			esc_html__( 'Rule source:', 'estasoft-news-distribution' ) . ' ' . $link . ' ' . esc_html( '(' . $note . ')' )
		);
	}

	/**
	 * The closing note: channels that are off, and channels that do not take the language.
	 *
	 * @return string Escaped HTML, or an empty string when every channel is on.
	 */
	private function not_set_up(): string {
		$off      = array();
		$language = array();

		foreach ( $this->ctx->off() as $channel ) {
			$off[] = $channel->label();
		}

		foreach ( $this->ctx->other_language() as $channel ) {
			$language[] = $channel->label();
		}

		$text = '';

		if ( array() !== $off ) {
			$text .= sprintf(
				/* translators: %s: a list of channel names such as "Baidu, News Break and Yahoo News". */
				_n( '%s is not turned on, so this article is not sent there.', '%s are not turned on, so this article is not sent there.', count( $off ), 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $off )
			);
		}

		if ( array() !== $language ) {
			$text .= ' ' . sprintf(
				/* translators: 1: a list of channel names, 2: a language name such as "Kurdish". */
				__( '%1$s does not take %2$s.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $language ),
				Catalog::name( $this->ctx->language() )
			);
		}

		if ( '' === trim( $text ) ) {
			return '';
		}

		return self::strip( __( 'Not set up', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-note-row' ), esc_html( trim( $text ) ) );
	}
}
