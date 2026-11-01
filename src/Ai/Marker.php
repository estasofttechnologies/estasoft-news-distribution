<?php
/**
 * Marks articles that carry AI-written text.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * `_estasoft_nd_ai` is the record: `assisted` (true while the article carries AI-written text), `features`,
 * `log` (the AI log rows whose text was used), `by` and `at`. Marking also sets the article's content label to
 * AI-assisted, which is what the page marking and the Apple News list read.
 *
 * A person removes the mark by changing the content label in the editor panel. `watch()` sees that
 * and clears `assisted`. The AI log keeps its rows, so the history stays.
 */
final class Marker {

	public const META = '_estasoft_nd_ai';

	/**
	 * Set while this class writes the content label, so the watcher does not undo it.
	 *
	 * @var bool
	 */
	private static bool $writing = false;

	/**
	 * Marks an article as AI-assisted.
	 *
	 * @param int    $post_id Article.
	 * @param int    $log_id  The AI log row whose text was used.
	 * @param string $feature REST feature name.
	 * @param int    $user_id The person who chose the text.
	 * @return void
	 */
	public function mark( int $post_id, int $log_id, string $feature, int $user_id ): void {
		$record   = $this->record( $post_id );
		$features = array_values( array_unique( array_merge( (array) ( $record['features'] ?? array() ), array( $feature ) ) ) );
		$logs     = array_values( array_unique( array_merge( array_map( 'intval', (array) ( $record['log'] ?? array() ) ), array( $log_id ) ) ) );

		update_post_meta(
			$post_id,
			self::META,
			array(
				'assisted' => true,
				'features' => $features,
				'log'      => $logs,
				'by'       => $user_id,
				'at'       => Clock::now(),
			)
		);

		$options = ArticleOptions::get( $post_id );

		if ( 'syndicated' !== $options['content_label'] && 'ai_assisted' !== $options['content_label'] ) {
			self::$writing = true;

			try {
				ArticleOptions::update( $post_id, array( 'content_label' => 'ai_assisted' ) );
			} finally {
				self::$writing = false;
			}
		}
	}

	/**
	 * The stored record.
	 *
	 * @param int $post_id Article.
	 * @return array<string, mixed>
	 */
	public function record( int $post_id ): array {
		$record = get_post_meta( $post_id, self::META, true );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Whether the article is marked AI-assisted now.
	 *
	 * @param int $post_id Article.
	 * @return bool
	 */
	public function is_marked( int $post_id ): bool {
		return ! empty( $this->record( $post_id )['assisted'] );
	}

	/**
	 * How many articles are marked.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( '"assisted";b:1' ) . '%';

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s", self::META, $like )
		);
	}

	/**
	 * Clears `assisted` when a person changes the content label away from AI-assisted.
	 *
	 * @param int    $meta_id   Meta row id.
	 * @param int    $post_id   Post.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value The new value.
	 * @return void
	 */
	public function watch( $meta_id, $post_id, $meta_key, $meta_value ): void {
		unset( $meta_id );

		if ( self::$writing || ArticleOptions::META !== $meta_key || ! is_array( $meta_value ) ) {
			return;
		}

		$record = $this->record( (int) $post_id );

		if ( empty( $record['assisted'] ) || 'ai_assisted' === ( $meta_value['content_label'] ?? '' ) ) {
			return;
		}

		$record['assisted']   = false;
		$record['removed_by'] = get_current_user_id();
		$record['removed_at'] = Clock::now();

		update_post_meta( (int) $post_id, self::META, $record );
	}
}
