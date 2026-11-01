<?php
/**
 * Remembers, per article, which targets already got a post.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The queue retries a whole delivery, so a retry must not post again to a
 * target that already took the post. Each attempt leaves a mark in the post
 * meta `_estasoft_nd_outlets`, one per event and target:
 *
 * - `sent`: the other side accepted it. A retry skips the target.
 * - `failed`: the other side refused it for good. A retry skips the target for
 *   a while, so one wrong token does not hold up the others.
 * - `pending`: the request may or may not have arrived (no answer, or a server
 *   error). The next attempt checks first where the service allows it.
 */
final class Marks {

	public const META = '_estasoft_nd_outlets';

	public const SENT    = 'sent';
	public const FAILED  = 'failed';
	public const PENDING = 'pending';

	/**
	 * How long a permanent failure is skipped by retries: longer than the whole retry chain.
	 */
	private const FAILED_FOR = 2 * HOUR_IN_SECONDS;

	/**
	 * The mark of one target for one event.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $event     Event type.
	 * @param string $target_id Target id.
	 * @return array{status: string, time: int, remote: string}|array{} Empty when there is no mark.
	 */
	public function get( int $post_id, string $event, string $target_id ): array {
		$all = $this->all( $post_id );
		$row = $all[ $event . '|' . $target_id ] ?? array();

		if ( ! is_array( $row ) || empty( $row['s'] ) ) {
			return array();
		}

		return array(
			'status' => (string) $row['s'],
			'time'   => (int) ( $row['t'] ?? 0 ),
			'remote' => (string) ( $row['r'] ?? '' ),
		);
	}

	/**
	 * Whether a retry should leave this target alone.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $event     Event type.
	 * @param string $target_id Target id.
	 * @return bool
	 */
	public function is_settled( int $post_id, string $event, string $target_id ): bool {
		$mark = $this->get( $post_id, $event, $target_id );

		if ( array() === $mark ) {
			return false;
		}

		if ( self::SENT === $mark['status'] ) {
			return true;
		}

		return self::FAILED === $mark['status'] && Clock::now() - $mark['time'] < self::FAILED_FOR;
	}

	/**
	 * Writes a mark.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $event     Event type.
	 * @param string $target_id Target id.
	 * @param string $status    sent, failed or pending.
	 * @param string $remote    The other side's id or address for the post.
	 * @return void
	 */
	public function record( int $post_id, string $event, string $target_id, string $status, string $remote = '' ): void {
		if ( $post_id < 1 || ! $this->post_exists( $post_id ) ) {
			return;
		}

		$all      = $this->all( $post_id );
		$key      = $event . '|' . $target_id;
		$previous = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array();
		$row      = array(
			's' => $status,
			't' => Clock::now(),
			'r' => mb_substr( $remote, 0, 300 ),
		);

		// A pending mark keeps count of the sends that started, so the next one knows it is a repeat.
		if ( self::PENDING === $status && self::PENDING === ( $previous['s'] ?? '' ) ) {
			$row['n'] = max( 1, (int) ( $previous['n'] ?? 1 ) );
		}

		$all[ $key ] = $row;

		update_post_meta( $post_id, self::META, $all );
	}

	/**
	 * Marks that a send is starting. A send that follows an unfinished one is a repeat.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $event     Event type.
	 * @param string $target_id Target id.
	 * @return void
	 */
	public function begin( int $post_id, string $event, string $target_id ): void {
		if ( $post_id < 1 || ! $this->post_exists( $post_id ) ) {
			return;
		}

		$all                              = $this->all( $post_id );
		$all[ $event . '|' . $target_id ] = array(
			's' => self::PENDING,
			't' => Clock::now(),
			'r' => '',
			'n' => $this->attempts( $post_id, $event, $target_id ) + 1,
		);

		update_post_meta( $post_id, self::META, $all );
	}

	/**
	 * How many sends started and did not finish: zero when none is unfinished.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $event     Event type.
	 * @param string $target_id Target id.
	 * @return int
	 */
	public function attempts( int $post_id, string $event, string $target_id ): int {
		$all = $this->all( $post_id );
		$row = $all[ $event . '|' . $target_id ] ?? array();

		if ( ! is_array( $row ) || self::PENDING !== ( $row['s'] ?? '' ) ) {
			return 0;
		}

		return max( 1, (int) ( $row['n'] ?? 1 ) );
	}

	/**
	 * Removes the mark of one target for one event.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $event     Event type.
	 * @param string $target_id Target id.
	 * @return void
	 */
	public function forget( int $post_id, string $event, string $target_id ): void {
		$all = $this->all( $post_id );
		$key = $event . '|' . $target_id;

		if ( ! array_key_exists( $key, $all ) ) {
			return;
		}

		unset( $all[ $key ] );

		if ( array() === $all ) {
			delete_post_meta( $post_id, self::META );
			return;
		}

		update_post_meta( $post_id, self::META, $all );
	}

	/**
	 * Removes the mark of one target, every event.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $target_id Target id.
	 * @return void
	 */
	public function clear( int $post_id, string $target_id ): void {
		$all = $this->all( $post_id );

		foreach ( array_keys( $all ) as $key ) {
			if ( substr( (string) $key, -strlen( $target_id ) - 1 ) === '|' . $target_id ) {
				unset( $all[ $key ] );
			}
		}

		if ( array() === $all ) {
			delete_post_meta( $post_id, self::META );
			return;
		}

		update_post_meta( $post_id, self::META, $all );
	}

	/**
	 * Every mark of an article.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	private function all( int $post_id ): array {
		if ( $post_id < 1 || ! $this->post_exists( $post_id ) ) {
			return array();
		}

		$all = get_post_meta( $post_id, self::META, true );

		return is_array( $all ) ? $all : array();
	}

	/**
	 * Whether the post still exists. A deleted post has nowhere to keep a mark.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	private function post_exists( int $post_id ): bool {
		return get_post( $post_id ) instanceof \WP_Post;
	}
}
