<?php
/**
 * The stored result of checking one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * What the Overview, the Article check, the editor panel, the Posts list column,
 * the pre-publish gate and the weekly summary all read. It is stored in the post
 * meta `_estasoft_nd_check` and returned by `GET /articles/{id}/check`.
 *
 * Array form (what is stored and sent over REST):
 *
 *   array(
 *     'post_id'  => 123,
 *     'checked'  => 1792000000,            // Unix time of the run (Clock::now())
 *     'language' => 'en',
 *     'channels' => array(
 *       'google-news' => array(
 *         'eligible' => true,              // false when a finding blocks the channel
 *         'findings' => array( <finding>, ... ),
 *       ),
 *       ...                                // only channels that are on and take the language
 *     ),
 *     'findings' => array( <finding>, ... ), // every distinct finding once, most severe first,
 *                                            // each with 'channels' listing where it applies
 *     'counts'   => array( 'block' => 1, 'warn' => 2, 'info' => 0 ),
 *   )
 *
 * `<finding>` is the shape documented on CheckInterface.
 */
final class CheckReport {

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Wraps the array form.
	 *
	 * @param array<string, mixed> $data Array form.
	 */
	public function __construct( array $data ) {
		$this->data = array_merge(
			array(
				'post_id'  => 0,
				'checked'  => 0,
				'language' => '',
				'channels' => array(),
				'findings' => array(),
				'counts'   => array(
					Status::BLOCK => 0,
					Status::WARN  => 0,
					Status::INFO  => 0,
				),
			),
			$data
		);
	}

	public function post_id(): int {
		return (int) $this->data['post_id'];
	}

	/**
	 * Unix time of the run.
	 *
	 * @return int
	 */
	public function checked(): int {
		return (int) $this->data['checked'];
	}

	public function language(): string {
		return (string) $this->data['language'];
	}

	/**
	 * Per-channel outcome, keyed by channel id.
	 *
	 * @return array<string, array{eligible: bool, findings: array<int, array<string, mixed>>}>
	 */
	public function channels(): array {
		return (array) $this->data['channels'];
	}

	/**
	 * Every distinct finding once, most severe first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findings(): array {
		return (array) $this->data['findings'];
	}

	/**
	 * Finding counts by severity.
	 *
	 * @return array{block: int, warn: int, info: int}
	 */
	public function counts(): array {
		return (array) $this->data['counts'];
	}

	/**
	 * Whether the channel would take the article. True for a channel that is not
	 * in the report (the check cannot say it blocks it).
	 *
	 * @param string $channel_id Channel id.
	 * @return bool
	 */
	public function is_eligible( string $channel_id ): bool {
		$channels = $this->channels();

		return ! isset( $channels[ $channel_id ] ) || (bool) $channels[ $channel_id ]['eligible'];
	}

	/**
	 * True when no finding blocks any channel.
	 *
	 * @return bool
	 */
	public function is_clean(): bool {
		$counts = $this->counts();

		return 0 === (int) $counts[ Status::BLOCK ] && 0 === (int) $counts[ Status::WARN ];
	}

	/**
	 * True when at least one channel is blocked.
	 *
	 * @return bool
	 */
	public function has_blocks(): bool {
		return (int) $this->counts()[ Status::BLOCK ] > 0;
	}

	/**
	 * Channel ids that skip the article.
	 *
	 * @return string[]
	 */
	public function skipping_channels(): array {
		$out = array();

		foreach ( $this->channels() as $id => $channel ) {
			if ( ! $channel['eligible'] ) {
				$out[] = (string) $id;
			}
		}

		return $out;
	}

	/**
	 * Array form, for storage and REST.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
