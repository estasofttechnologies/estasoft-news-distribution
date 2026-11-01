<?php
/**
 * Every update to one article and where each one went.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Admin\Check\ChannelRows;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Check\Links;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Content\CorrectionCard;
use Estasoft\NewsDistribution\Core\AddressChange;
use Estasoft\NewsDistribution\Core\ArticleChoices;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\DateGuard;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `&tab=history`, and `&version=<key>` for one version. The versions are the date guard's
 * stored decisions, the WordPress revisions and the address changes, matched by time, plus the publish. For the version
 * chosen the card says what each channel did afterwards, from the delivery log and the fetch
 * log only. Nothing here is guessed: where the log has no row the line says so.
 *
 * The correction note card belongs to `Content\CorrectionCard` and is printed under the channels.
 */
final class HistoryTab extends AbstractCheckTab {

	/**
	 * Most versions listed, not counting the publish.
	 */
	private const MAX_VERSIONS = 12;

	/**
	 * Seconds within which a revision and a stored decision count as the same save.
	 */
	private const SAME_SAVE = 180;

	public function slug(): string {
		return 'history';
	}

	public function title(): string {
		return __( 'History', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Every update to this article and where it went.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 30;
	}

	public function render( int $post_id ): string {
		$ctx = new Context( $this->plugin, $post_id );

		if ( ! $ctx->exists() || null === $ctx->article() ) {
			return Message::text( 'info', __( 'This is not an article News Distribution sends, so it has no history.', 'estasoft-news-distribution' ) );
		}

		$deliveries = $this->deliveries( $post_id );
		$versions   = $this->versions( $ctx, $deliveries );
		$chosen     = $this->chosen( $versions );
		$main       = $this->where_card( $ctx, $chosen, $deliveries );

		if ( class_exists( CorrectionCard::class ) ) {
			$main .= CorrectionCard::render( $this->plugin, $post_id, $this->url( $post_id ) );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-split' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $main )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side estasoft-nd-split__side--wide' ), $this->versions_card( $post_id, $versions, $chosen ) )
		);
	}

	/**
	 * Every delivery row of the article, newest first, by channel.
	 *
	 * @param int $post_id The article.
	 * @return array<string, array<int, array<string, mixed>>> Channel id => rows.
	 */
	private function deliveries( int $post_id ): array {
		$rows = $this->plugin->log()->query(
			array(
				'post'     => $post_id,
				'per_page' => 100,
			)
		)['rows'];
		$out  = array();

		foreach ( $rows as $row ) {
			$out[ (string) $row['channel'] ][] = $row;
		}

		return $out;
	}

	/**
	 * The versions of the article, newest first: each stored decision of the date guard, each
	 * revision that is not one of those, and the publish last.
	 *
	 * Each has `key`, `kind` (update or publish), `at`, `until` (when the next version begins),
	 * `title`, `text`, `decision` (kept, moved or empty), `kept` (the date the article carries),
	 * `revision` (a revision id or 0) and `tags`.
	 *
	 * @param Context                                         $ctx        The article.
	 * @param array<string, array<int, array<string, mixed>>> $deliveries The delivery rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function versions( Context $ctx, array $deliveries ): array {
		$article   = $ctx->article();
		$guard     = $this->plugin->make( DateGuard::class );
		$revisions = array();

		foreach ( array_values( wp_get_post_revisions( $ctx->id() ) ) as $revision ) {
			if ( wp_is_post_autosave( $revision ) ) {
				continue;
			}

			$revisions[] = array(
				'id'     => (int) $revision->ID,
				'at'     => $this->gmt( (string) $revision->post_modified_gmt ),
				'author' => (string) get_the_author_meta( 'display_name', (int) $revision->post_author ),
			);
		}

		$used     = array();
		$versions = array();
		$changes  = $this->plugin->make( AddressChange::class )->history( $ctx->id() );
		$merged   = array();

		foreach ( $guard->events( $ctx->id() ) as $event ) {
			$at = (int) ( $event['at'] ?? 0 );

			if ( $at < 1 ) {
				continue;
			}

			$match    = $this->revision_near( $revisions, $at, $used );
			$decision = (string) ( $event['decision'] ?? '' );
			$date     = $this->gmt( (string) ( $event['modified_gmt'] ?? '' ) );
			$same     = $this->change_near( $changes, $at, $merged );

			// The save that changed the address is one version, not two: the address entry takes the decision and the revision.
			if ( null !== $same ) {
				$merged[ $same ] = array(
					'revision' => $match,
					'decision' => $decision,
					'date'     => $date,
				);
				continue;
			}

			$text = DateGuard::describe( (string) ( $event['reason'] ?? '' ), (int) ( $event['chars'] ?? 0 ) ) . '.';

			if ( $date > 0 && DateGuard::KEPT === $decision ) {
				/* translators: %s: a time such as "13:41". */
				$text .= ' ' . sprintf( __( 'The date was kept at %s.', 'estasoft-news-distribution' ), Times::at( $date ) );
			} elseif ( $date > 0 && DateGuard::MOVED === $decision ) {
				/* translators: %s: a time such as "14:32". */
				$text .= ' ' . sprintf( __( 'The date moved to %s.', 'estasoft-news-distribution' ), Times::at( $date ) );
			}

			$versions[] = $this->version( 'update', $at, $text, $decision, $match, $date );
		}

		foreach ( $changes as $index => $change ) {
			$versions[] = $this->address_version( $index, $change, $merged[ $index ] ?? null, isset( $merged[ $index ] ) ? null : $this->revision_near( $revisions, (int) $change['at'], $used ) );
		}

		foreach ( $revisions as $index => $revision ) {
			if ( isset( $used[ $index ] ) || $revision['at'] < 1 ) {
				continue;
			}

			$published = null === $article ? 0 : $article->published_ts();

			// The save that published the article is the publish, not an update.
			if ( $published > 0 && abs( $revision['at'] - $published ) <= self::SAME_SAVE ) {
				continue;
			}

			$versions[] = $this->version( 'update', (int) $revision['at'], '', '', $revision );
		}

		usort(
			$versions,
			static function ( array $a, array $b ): int {
				return $b['at'] <=> $a['at'];
			}
		);

		$versions = array_slice( $versions, 0, self::MAX_VERSIONS );

		if ( null !== $article && $article->published_ts() > 0 ) {
			$by = $article->author_name();

			$versions[] = array(
				'key'      => 'published',
				'kind'     => 'publish',
				'at'       => $article->published_ts(),
				'until'    => 0,
				/* translators: %s: a time such as "13:41". */
				'title'    => sprintf( __( 'Published %s', 'estasoft-news-distribution' ), Times::at( $article->published_ts() ) ),
				'text'     => '' === $by ? '' : $by . '.',
				'decision' => '',
				'date'     => 0,
				'revision' => 0,
				'tags'     => array(),
			);
		}

		foreach ( $versions as $index => $version ) {
			$versions[ $index ]['until'] = 0 === $index ? PHP_INT_MAX : (int) $versions[ $index - 1 ]['at'];
			$versions[ $index ]['tags']  = $this->tags( $versions[ $index ], $deliveries );
		}

		return $versions;
	}

	/**
	 * One update version.
	 *
	 * @param string                    $kind     update.
	 * @param int                       $at       When it was saved.
	 * @param string                    $text     What changed, as a sentence.
	 * @param string                    $decision kept, moved or empty.
	 * @param array<string, mixed>|null $revision The revision saved at the same time, if one was found.
	 * @param int                       $date     The date the article carried after the save, or 0.
	 * @return array<string, mixed>
	 */
	private function version( string $kind, int $at, string $text, string $decision, ?array $revision, int $date = 0 ): array {
		$by = null === $revision ? '' : (string) $revision['author'];

		if ( '' !== $by ) {
			/* translators: %s: a person's name. */
			$text = trim( $text . ' ' . sprintf( __( 'By %s.', 'estasoft-news-distribution' ), $by ) );
		}

		return array(
			'key'      => (string) $at,
			'kind'     => $kind,
			'at'       => $at,
			'until'    => 0,
			/* translators: %s: a time such as "14:32". */
			'title'    => sprintf( __( 'Updated %s', 'estasoft-news-distribution' ), Times::at( $at ) ),
			'text'     => $text,
			'decision' => $decision,
			'date'     => $date,
			'revision' => null === $revision ? 0 : (int) $revision['id'],
			'tags'     => array(),
		);
	}

	/**
	 * The address change saved within a few minutes of a time, not yet matched to a stored decision.
	 *
	 * @param array<int, array<string, mixed>> $changes The address changes.
	 * @param int                              $at      Unix time.
	 * @param array<int, mixed>                $merged  Changes already matched, by index.
	 * @return int|null The index, or null.
	 */
	private function change_near( array $changes, int $at, array $merged ): ?int {
		$best = null;
		$gap  = self::SAME_SAVE + 1;

		foreach ( $changes as $index => $change ) {
			$distance = abs( (int) $change['at'] - $at );

			if ( ! isset( $merged[ $index ] ) && $distance < $gap ) {
				$best = $index;
				$gap  = $distance;
			}
		}

		return $best;
	}

	/**
	 * The version for a save that changed the article's address.
	 *
	 * It says the ID stayed the same, so a platform sees one article, and gives both addresses. The redirect is
	 * named only when the slug changed, which is the one change WordPress redirects by itself.
	 *
	 * @param int                       $index    Position among the address changes, so two in one second stay apart.
	 * @param array<string, mixed>      $change   The audit entry: at, old, new, redirect, user.
	 * @param array<string, mixed>|null $merged   The stored decision and revision of the same save, if the date guard saw it.
	 * @param array<string, mixed>|null $revision A revision saved at the same time, if one was found.
	 * @return array<string, mixed>
	 */
	private function address_version( int $index, array $change, ?array $merged, ?array $revision ): array {
		$revision = null === $merged ? $revision : ( $merged['revision'] ?? null );
		$by       = null === $revision ? '' : (string) $revision['author'];

		if ( '' === $by && (int) $change['user'] > 0 ) {
			$by = (string) get_the_author_meta( 'display_name', (int) $change['user'] );
		}

		$text = __( 'The ID stayed the same, so platforms see one article.', 'estasoft-news-distribution' );

		if ( ! empty( $change['redirect'] ) ) {
			$text .= ' ' . __( 'The old address redirects to the new one.', 'estasoft-news-distribution' );
		}

		if ( '' !== $by ) {
			/* translators: %s: a person's name. */
			$text .= ' ' . sprintf( __( 'By %s.', 'estasoft-news-distribution' ), $by );
		}

		return array(
			'key'      => 'address-' . (int) $change['at'] . '-' . $index,
			'kind'     => 'address',
			'at'       => (int) $change['at'],
			'until'    => 0,
			/* translators: %s: a time such as "13:46". */
			'title'    => sprintf( __( 'Address changed %s', 'estasoft-news-distribution' ), Times::at( (int) $change['at'] ) ),
			'text'     => $text,
			'lines'    => array(
				/* translators: %s: a web address. */
				sprintf( __( 'Was: %s', 'estasoft-news-distribution' ), (string) $change['old'] ),
				/* translators: %s: a web address. */
				sprintf( __( 'Now: %s', 'estasoft-news-distribution' ), (string) $change['new'] ),
			),
			'decision' => null === $merged ? '' : (string) $merged['decision'],
			'date'     => null === $merged ? 0 : (int) $merged['date'],
			'revision' => null === $revision ? 0 : (int) $revision['id'],
			'tags'     => array(),
		);
	}

	/**
	 * The revision saved within a few minutes of a time, not yet matched to another version.
	 *
	 * @param array<int, array<string, mixed>> $revisions The revisions.
	 * @param int                              $at        Unix time.
	 * @param array<int, bool>                 $used      Indexes already matched; updated here.
	 * @return array<string, mixed>|null
	 */
	private function revision_near( array $revisions, int $at, array &$used ): ?array {
		$best = null;
		$gap  = self::SAME_SAVE + 1;

		foreach ( $revisions as $index => $revision ) {
			$distance = abs( (int) $revision['at'] - $at );

			if ( ! isset( $used[ $index ] ) && $distance < $gap ) {
				$best = $index;
				$gap  = $distance;
			}
		}

		if ( null === $best ) {
			return null;
		}

		$used[ $best ] = true;

		return $revisions[ $best ];
	}

	/**
	 * The small tags under a version: a minor edit that sent nothing.
	 *
	 * @param array<string, mixed>                            $version    The version.
	 * @param array<string, array<int, array<string, mixed>>> $deliveries The delivery rows.
	 * @return string[] Plain text.
	 */
	private function tags( array $version, array $deliveries ): array {
		$skipped = false;

		foreach ( $deliveries as $rows ) {
			foreach ( $this->in_window( $rows, $version ) as $row ) {
				if ( Status::SKIPPED === $row['status'] && ArticleOptions::is_minor_edit_message( (string) $row['message'] ) ) {
					$skipped = true;
				}
			}
		}

		return $skipped ? array( __( 'Minor edit: pushes skipped', 'estasoft-news-distribution' ) ) : array();
	}

	/**
	 * The delivery rows of a channel made while a version was the newest, newest first.
	 *
	 * @param array<int, array<string, mixed>> $rows    Rows of one channel.
	 * @param array<string, mixed>             $version The version.
	 * @return array<int, array<string, mixed>>
	 */
	private function in_window( array $rows, array $version ): array {
		$found = array();

		foreach ( $rows as $row ) {
			$at = (int) $row['created_ts'];

			// A push is made a moment after the save that asks for it, so the window opens a little early.
			if ( $at >= (int) $version['at'] - 5 && $at < (int) $version['until'] - 5 ) {
				$found[] = $row;
			}
		}

		return $found;
	}

	/**
	 * The version the address names, or the newest.
	 *
	 * @param array<int, array<string, mixed>> $versions The versions.
	 * @return array<string, mixed>|null Null when there are none.
	 */
	private function chosen( array $versions ): ?array {
		$key = Query::text( 'version' );

		foreach ( $versions as $version ) {
			if ( $version['key'] === $key ) {
				return $version;
			}
		}

		return $versions[0] ?? null;
	}

	/**
	 * "Where the 14:32 edit went": a row for each channel that is on.
	 *
	 * @param Context                                         $ctx        The article.
	 * @param array<string, mixed>|null                       $version    The chosen version.
	 * @param array<string, array<int, array<string, mixed>>> $deliveries The delivery rows.
	 * @return string Escaped HTML.
	 */
	private function where_card( Context $ctx, ?array $version, array $deliveries ): string {
		if ( null === $version ) {
			return Card::note(
				'history-where',
				__( 'Where the article went', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The article is not published yet, so nothing has gone to any channel.', 'estasoft-news-distribution' ) )
			);
		}

		$title = 'publish' === $version['kind']
			? sprintf(
				/* translators: %s: a time such as "13:41". */
				__( 'Where the %s publish went', 'estasoft-news-distribution' ),
				Times::at( (int) $version['at'] )
			)
			: sprintf(
				/* translators: %s: a time such as "14:32". */
				__( 'Where the %s edit went', 'estasoft-news-distribution' ),
				Times::at( (int) $version['at'] )
			);
		$rows   = new ChannelRows( $this->plugin, $ctx, new Links( $this->plugin ) );
		$pull   = '';
		$push   = '';
		$newest = PHP_INT_MAX === (int) $version['until'];

		foreach ( $rows->groups() as $group => $channels ) {
			foreach ( $channels as $channel ) {
				if ( ChannelInterface::MODE_PUSHED === $channel->mode() ) {
					$push .= $this->pushed_row( $rows, $channel, $version, $deliveries[ $channel->id() ] ?? array() );
				} else {
					$pull .= $this->pulled_row( $ctx, $rows, $channel, $group, $version, $newest );
				}
			}
		}

		$body = '';

		if ( '' !== $pull ) {
			$body .= ChannelRows::strip( __( 'Feeds and sitemaps', 'estasoft-news-distribution' ) ) . $pull;
		}

		if ( '' !== $push ) {
			$body .= ChannelRows::strip( __( 'Pushes', 'estasoft-news-distribution' ) ) . $push;
		}

		if ( '' === $body ) {
			$body = Html::tag( 'p', array( 'class' => 'estasoft-nd-pad estasoft-nd-muted' ), esc_html__( 'No channel is on for this article’s language.', 'estasoft-news-distribution' ) );
		}

		return Card::flush(
			'history-where',
			$title,
			__( 'How each channel learns about an update, and when the platform last read it.', 'estasoft-news-distribution' ),
			$body
		);
	}

	/**
	 * The row of a feed or sitemap.
	 *
	 * @param Context              $ctx     The article.
	 * @param ChannelRows          $rows    The row helper, for the fetch log.
	 * @param ChannelInterface     $channel The channel.
	 * @param string               $group   Its group id.
	 * @param array<string, mixed> $version The chosen version.
	 * @param bool                 $newest  Whether the version is the newest, so the channel's present state is its state.
	 * @return string Escaped HTML.
	 */
	private function pulled_row( Context $ctx, ChannelRows $rows, ChannelInterface $channel, string $group, array $version, bool $newest ): string {
		$result = $ctx->results()[ $channel->id() ] ?? null;
		$pill   = $newest && null !== $result ? StatusView::pill( (string) $result['status'] ) : '';
		$fetch  = $this->fetch_after( $rows, $channel, $ctx->language(), (int) $version['at'] );
		$parts  = array();

		if ( 'publish' === $version['kind'] ) {
			$parts[] = __( 'Added to the feed or sitemap when the article was published.', 'estasoft-news-distribution' );
		} elseif ( Group::SITEMAP === $group ) {
			$date = (int) $version['date'];

			if ( DateGuard::KEPT === $version['decision'] && $date > 0 ) {
				/* translators: %s: a time such as "13:41". */
				$parts[] = sprintf( __( 'The sitemap entry keeps its last-modified time, %s, because the date was kept.', 'estasoft-news-distribution' ), Times::at( $date ) );
			} elseif ( DateGuard::MOVED === $version['decision'] && $date > 0 ) {
				/* translators: %s: a time such as "14:32". */
				$parts[] = sprintf( __( 'The sitemap entry’s last-modified time moved to %s.', 'estasoft-news-distribution' ), Times::at( $date ) );
			} else {
				$parts[] = __( 'The sitemap entry is rebuilt from the article each time it is read.', 'estasoft-news-distribution' );
			}
		} else {
			$parts[] = __( 'Same ID, so the feed carries one updated item.', 'estasoft-news-distribution' );
		}

		$parts[] = $fetch['sentence'];

		// The newest version is what the document carries now: a channel switched off for this article does not carry it.
		if ( $newest && $this->plugin->make( ArticleChoices::class )->leaves_out( $ctx->id(), $channel ) ) {
			$parts = array( ArticleChoices::message() );
		}

		return ChannelRows::row(
			esc_html( $channel->label() ),
			$pill,
			Html::tag( 'p', array(), esc_html( implode( ' ', $parts ) ) ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $fetch['label'] ) )
		);
	}

	/**
	 * What the fetch log says about one channel and one edit.
	 *
	 * @param ChannelRows      $rows     The row helper.
	 * @param ChannelInterface $channel  The channel.
	 * @param string           $language The article's language.
	 * @param int              $since    When the edit was saved.
	 * @return array{sentence: string, label: string}
	 */
	private function fetch_after( ChannelRows $rows, ChannelInterface $channel, string $language, int $since ): array {
		$found = null;

		foreach ( array_reverse( $rows->fetches_since( $channel->id(), $since, 200 ) ) as $fetch ) {
			if ( ! in_array( (string) $fetch['status'], array( 'ok', 'not_modified' ), true ) ) {
				continue;
			}

			if ( '' !== (string) $fetch['language'] && $language !== (string) $fetch['language'] ) {
				continue;
			}

			$found = $fetch;
			break;
		}

		if ( null !== $found ) {
			$time = Times::at( (int) $found['created_ts'] );

			return array(
				'sentence' => 'not_modified' === $found['status']
					? sprintf(
						/* translators: 1: a channel name, 2: a time such as "14:31". */
						__( '%1$s asked at %2$s, after this edit, and was told the feed had not changed.', 'estasoft-news-distribution' ),
						$channel->label(),
						$time
					)
					: sprintf(
						/* translators: 1: a channel name, 2: a time such as "14:31". */
						__( '%1$s fetched the feed at %2$s, after this edit.', 'estasoft-news-distribution' ),
						$channel->label(),
						$time
					),
				/* translators: %s: a time such as "14:31". */
				'label'    => sprintf( __( 'Fetched %s', 'estasoft-news-distribution' ), $time ),
			);
		}

		$last = $rows->last_fetch( $channel->id() );

		if ( null === $last ) {
			return array(
				'sentence' => sprintf(
					/* translators: %s: a channel name. */
					__( '%s has not fetched yet.', 'estasoft-news-distribution' ),
					$channel->label()
				),
				'label'    => __( 'Not fetched yet', 'estasoft-news-distribution' ),
			);
		}

		$time = Times::at( (int) $last['created_ts'] );

		return array(
			'sentence' => sprintf(
				/* translators: 1: a channel name, 2: a time such as "14:31". */
				__( '%1$s last fetched at %2$s, before this edit, and reads the feed again on its own schedule.', 'estasoft-news-distribution' ),
				$channel->label(),
				$time
			),
			/* translators: %s: a time such as "14:31". */
			'label'    => sprintf( __( 'Fetched %s', 'estasoft-news-distribution' ), $time ),
		);
	}

	/**
	 * The row of a pushed channel.
	 *
	 * @param ChannelRows                      $rows    The row helper, for the delivery sentence.
	 * @param ChannelInterface                 $channel The channel.
	 * @param array<string, mixed>             $version The chosen version.
	 * @param array<int, array<string, mixed>> $log     The channel's delivery rows, newest first.
	 * @return string Escaped HTML.
	 */
	private function pushed_row( ChannelRows $rows, ChannelInterface $channel, array $version, array $log ): string {
		$in   = $this->in_window( $log, $version );
		$last = null;

		foreach ( $log as $row ) {
			if ( Status::SENT === $row['status'] ) {
				$last = $row;
				break;
			}
		}

		if ( array() !== $in ) {
			$row  = $in[0];
			$pill = StatusView::pill( (string) $row['status'] );
			$text = $rows->delivery_sentence( $row );
		} else {
			$pill = StatusView::pill( Status::SKIPPED );
			$text = 'publish' !== $version['kind']
				? __( 'Nothing was sent for this edit. The channel may have been off then, or may not send updates.', 'estasoft-news-distribution' )
				: __( 'Nothing was sent when the article was published. The channel may have been off then.', 'estasoft-news-distribution' );

			if ( DateGuard::KEPT === $version['decision'] && null !== $last ) {
				$text = sprintf(
					/* translators: %s: a time such as "13:41". */
					__( 'A minor edit with the date kept, so no update was sent. The %s send stands.', 'estasoft-news-distribution' ),
					Times::at( (int) $last['created_ts'] )
				);
			}
		}

		return ChannelRows::row(
			esc_html( $channel->label() ),
			$pill,
			Html::tag( 'p', array(), esc_html( $text ) ),
			Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					null === $last
						? __( 'Not sent yet', 'estasoft-news-distribution' )
						/* translators: %s: a time such as "13:41". */
						: sprintf( __( 'Last sent %s', 'estasoft-news-distribution' ), Times::at( (int) $last['created_ts'] ) )
				)
			)
		);
	}

	/**
	 * The "Versions" card beside the channels.
	 *
	 * @param int                              $post_id  The article.
	 * @param array<int, array<string, mixed>> $versions The versions.
	 * @param array<string, mixed>|null        $chosen   The chosen one.
	 * @return string Escaped HTML.
	 */
	private function versions_card( int $post_id, array $versions, ?array $chosen ): string {
		$help = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'Select a version to see where it went. This does not restore it; use the WordPress revisions screen for that.', 'estasoft-news-distribution' )
		);

		if ( array() === $versions ) {
			return Card::note( 'history-versions', __( 'Versions', 'estasoft-news-distribution' ), $help . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No version yet. The first one is made when the article is published.', 'estasoft-news-distribution' ) ) );
		}

		$items = '';

		foreach ( $versions as $version ) {
			$on      = null !== $chosen && $chosen['key'] === $version['key'];
			$actions = $on
				? Tag::highlight( __( 'Selected', 'estasoft-news-distribution' ) )
				: Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-strong',
						'href'  => $this->url( $post_id, array( 'version' => $version['key'] ) ),
					),
					esc_html__( 'Select', 'estasoft-news-distribution' )
				);

			if ( (int) $version['revision'] > 0 && current_user_can( 'edit_post', $post_id ) ) {
				$actions .= ' ' . Html::tag(
					'a',
					array( 'href' => admin_url( 'revision.php?revision=' . (int) $version['revision'] ) ),
					esc_html__( 'Open revision', 'estasoft-news-distribution' )
				);
			}

			$tags = '';

			foreach ( (array) $version['tags'] as $tag ) {
				$tags .= Tag::plain( (string) $tag ) . ' ';
			}

			$items .= Html::tag(
				'li',
				array( 'aria-current' => $on ? 'true' : null ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-timeline__dot' . ( $on ? ' estasoft-nd-timeline__dot--now' : '' ) ), '' )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-grow' ),
					Html::tag( 'strong', array(), esc_html( (string) $version['title'] ) )
					. ( '' === $version['text'] ? '' : Html::tag( 'span', array(), esc_html( (string) $version['text'] ) ) )
					. $this->lines( (array) ( $version['lines'] ?? array() ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $tags . $actions )
				)
			);
		}

		return Card::note( 'history-versions', __( 'Versions', 'estasoft-news-distribution' ), $help . Html::tag( 'ol', array( 'class' => 'estasoft-nd-timeline' ), $items ) );
	}

	/**
	 * Small lines under a version's sentence, such as the old and the new address.
	 *
	 * @param string[] $lines Plain text.
	 * @return string Escaped HTML.
	 */
	private function lines( array $lines ): string {
		$out = '';

		foreach ( $lines as $line ) {
			$out .= Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $line ) );
		}

		return $out;
	}

	/**
	 * Unix time from a GMT date and time.
	 *
	 * @param string $value A `Y-m-d H:i:s` value in GMT.
	 * @return int Zero when there is none.
	 */
	private function gmt( string $value ): int {
		return '' === $value || 0 === strpos( $value, '0000' ) ? 0 : Clock::from_gmt( $value );
	}
}
