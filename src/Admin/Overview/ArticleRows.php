<?php
/**
 * The latest articles with what each channel group did with them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the same per-article results as the Article check (`Core\ArticleService`)
 * and folds the channels of each group into one result per group.
 *
 * A row is:
 *
 *     {
 *       id, title, language, published (ISO), edit_link,
 *       groups: { sitemap|partner|readers|pushes: {
 *         status,   // in_feed, sent, queued, retry, skipped, failed or off
 *         done,     // channels in the group that have the article (in feed or sent)
 *         total,    // channels in the group that are on and take the language
 *         at,       // ISO time the waiting or retrying channel goes out, or null
 *         channels: { id: { label, status, at, attempt, message } }
 *       } },
 *       overall: { status, ok, group, channel, label, message, at, attempt, waiting_groups[] }
 *     }
 *
 * `overall.status` is the worst result on any channel. When nothing is wrong it is
 * `in_feed` or `sent`, and `ok` says which kinds were seen: in_feed, sent, or both.
 */
final class ArticleRows {

	/**
	 * The groups an article has a result in, in column order.
	 *
	 * @return string[]
	 */
	public static function groups(): array {
		return array( Group::SITEMAP, Group::PARTNER, Group::READERS, Group::PUSHES );
	}

	/**
	 * How bad each result is, worst first, for choosing the one to show.
	 *
	 * @var string[]
	 */
	private const WORST_FIRST = array(
		Status::FAILED,
		Status::RETRY,
		Status::SKIPPED,
		Status::QUEUED,
		Status::IN_FEED,
		Status::SENT,
		Status::OFF,
	);

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The newest published articles.
	 *
	 * @param string $language A language code, or an empty string for all.
	 * @param int    $limit    How many.
	 * @return array<int, array<string, mixed>>
	 */
	public function latest( string $language, int $limit ): array {
		$page = $this->plugin->make( ArticleService::class )->list(
			array(
				'language' => $language,
				'status'   => 'publish',
				'per_page' => $limit,
			)
		);

		$rows = array();

		foreach ( $page['items'] as $summary ) {
			$rows[] = $this->row( $summary );
		}

		return $rows;
	}

	/**
	 * One article's row.
	 *
	 * @param array<string, mixed> $summary What `ArticleService::summary()` returns.
	 * @return array<string, mixed>
	 */
	private function row( array $summary ): array {
		return $this->build( $summary, $this->plugin->log()->latest_for_post( (int) $summary['id'] ) );
	}

	/**
	 * One article's row from what is already read, so a screen that lists many articles can read the
	 * delivery log once for all of them.
	 *
	 * @param array<string, mixed>                $summary An article as `ArticleService::summary()` returns it: id, title, language, published, edit_link and results.
	 * @param array<string, array<string, mixed>> $latest  The newest delivery row of each channel for this article, as `DeliveryLog::latest_for_post()` returns them.
	 * @return array<string, mixed>
	 */
	public function build( array $summary, array $latest ): array {
		$groups = array();

		foreach ( self::groups() as $group ) {
			$groups[ $group ] = array(
				'status'   => Status::OFF,
				'done'     => 0,
				'total'    => 0,
				'at'       => null,
				'channels' => array(),
			);
		}

		foreach ( (array) $summary['results'] as $channel_id => $result ) {
			$channel = $this->plugin->registry()->get( (string) $channel_id );

			if ( null === $channel || ! isset( $groups[ $channel->group() ] ) ) {
				continue;
			}

			$status  = (string) $result['status'];
			$logged  = $latest[ (string) $channel_id ] ?? null;
			$run_at  = null !== $logged && in_array( $status, array( Status::QUEUED, Status::RETRY ), true ) ? (int) $logged['run_at_ts'] : 0;
			$attempt = null === $logged ? 0 : (int) $logged['attempt'];
			$group   = $channel->group();

			$groups[ $group ]['channels'][ (string) $channel_id ] = array(
				'label'   => $channel->label(),
				'status'  => $status,
				'at'      => $run_at > 0 ? Clock::iso( $run_at ) : null,
				'attempt' => $attempt,
				'message' => (string) $result['message'],
			);

			if ( Status::OFF === $status ) {
				continue;
			}

			++$groups[ $group ]['total'];

			if ( in_array( $status, array( Status::IN_FEED, Status::SENT ), true ) ) {
				++$groups[ $group ]['done'];
			}
		}

		foreach ( $groups as $group => $cell ) {
			$groups[ $group ]['status'] = $this->worst( array_column( $cell['channels'], 'status' ) );
			$groups[ $group ]['at']     = $this->earliest( $cell['channels'] );
		}

		return array(
			'id'        => (int) $summary['id'],
			'title'     => (string) $summary['title'],
			'language'  => (string) $summary['language'],
			'published' => $summary['published'],
			'edit_link' => (string) $summary['edit_link'],
			'groups'    => $groups,
			'overall'   => $this->overall( $groups ),
		);
	}

	/**
	 * The worst of several results. `off` when there are none.
	 *
	 * @param string[] $statuses Result codes.
	 * @return string
	 */
	private function worst( array $statuses ): string {
		foreach ( self::WORST_FIRST as $code ) {
			if ( in_array( $code, $statuses, true ) ) {
				return $code;
			}
		}

		return Status::OFF;
	}

	/**
	 * The earliest time a waiting or retrying channel goes out.
	 *
	 * @param array<string, array<string, mixed>> $channels A group's channels.
	 * @return string|null ISO time.
	 */
	private function earliest( array $channels ): ?string {
		$times = array();

		foreach ( $channels as $channel ) {
			if ( null !== $channel['at'] && in_array( $channel['status'], array( Status::QUEUED, Status::RETRY ), true ) ) {
				$times[] = (int) strtotime( (string) $channel['at'] );
			}
		}

		return array() === $times ? null : Clock::iso( min( $times ) );
	}

	/**
	 * The one result that stands for the whole article: the worst channel, with its message.
	 *
	 * @param array<string, array<string, mixed>> $groups The article's groups.
	 * @return array<string, mixed>
	 */
	private function overall( array $groups ): array {
		$all     = array();
		$waiting = array();
		$seen    = array();

		foreach ( $groups as $group => $cell ) {
			foreach ( $cell['channels'] as $channel_id => $channel ) {
				$all[]                      = $channel['status'];
				$seen[ $channel['status'] ] = true;

				if ( Status::QUEUED === $channel['status'] && ! in_array( $group, $waiting, true ) ) {
					$waiting[] = $group;
				}
			}
		}

		$status = $this->worst( $all );
		$out    = array(
			'status'         => $status,
			'ok'             => isset( $seen[ Status::IN_FEED ], $seen[ Status::SENT ] ) ? 'both' : ( isset( $seen[ Status::SENT ] ) ? Status::SENT : Status::IN_FEED ),
			'group'          => '',
			'channel'        => '',
			'label'          => '',
			'message'        => '',
			'at'             => null,
			'attempt'        => 0,
			'waiting_groups' => $waiting,
		);

		foreach ( $groups as $group => $cell ) {
			foreach ( $cell['channels'] as $channel_id => $channel ) {
				if ( $channel['status'] !== $status ) {
					continue;
				}

				$out['group']   = (string) $group;
				$out['channel'] = (string) $channel_id;
				$out['label']   = (string) $channel['label'];
				$out['message'] = (string) $channel['message'];
				$out['at']      = $channel['at'];
				$out['attempt'] = (int) $channel['attempt'];

				return $out;
			}
		}

		return $out;
	}
}
