<?php
/**
 * The entries of the Delivery log, ready for a screen or the REST API.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `DeliveryLog` with the filters people use and adds what a list needs:
 * the article's title, the channel's name and whether the push can be sent
 * again. A push that is still waiting or being retried shows once, as its latest
 * row, unless the caller asks for every attempt.
 *
 * Get an instance with `Plugin::instance()->make( DeliveryEntries::class )`.
 */
final class DeliveryEntries {

	/**
	 * The statuses a person can filter by, in the order the screen lists them.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array( Status::SENT, Status::QUEUED, Status::RETRY, Status::FAILED, Status::SKIPPED );
	}

	private Plugin $plugin;

	/**
	 * Creates the reader.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * A page of entries.
	 *
	 * Filters: channel, status, language, post (article ID), search (part of an
	 * article title), days (only entries from the last N days), all_attempts
	 * (every row, not just the latest of each push), page, per_page (at most 100).
	 *
	 * @param array<string, mixed> $filters Filters and paging.
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function search( array $filters ): array {
		$args = array(
			'channel'     => (string) ( $filters['channel'] ?? '' ),
			'status'      => (string) ( $filters['status'] ?? '' ),
			'language'    => (string) ( $filters['language'] ?? '' ),
			'post'        => (int) ( $filters['post'] ?? 0 ),
			'latest_only' => empty( $filters['all_attempts'] ),
			'page'        => max( 1, (int) ( $filters['page'] ?? 1 ) ),
			'per_page'    => max( 1, min( 100, (int) ( $filters['per_page'] ?? 20 ) ) ),
		);

		$days = (int) ( $filters['days'] ?? 0 );

		if ( $days > 0 ) {
			$args['since'] = Clock::now() - ( $days * DAY_IN_SECONDS );
		}

		$search = trim( (string) ( $filters['search'] ?? '' ) );

		if ( '' !== $search ) {
			$args['posts'] = $this->post_ids_titled( $search );
		}

		$found = $this->plugin->log()->query( $args );

		return array(
			'rows'  => $this->decorate( $found['rows'] ),
			'total' => $found['total'],
		);
	}

	/**
	 * The channels a person can filter by: every channel that pushes, and any channel that has an entry.
	 *
	 * @return array<string, string> Channel id => name, by name.
	 */
	public function channels(): array {
		global $wpdb;

		$table = $this->plugin->log()->table();
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT channel FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is ours and there is no input.
		$found = array();

		foreach ( array_keys( $this->plugin->registry()->pushed() ) as $id ) {
			$found[ (string) $id ] = true;
		}

		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$found[ (string) $id ] = true;
		}

		$out = array();

		foreach ( array_keys( $found ) as $id ) {
			$channel    = $this->plugin->registry()->get( $id );
			$out[ $id ] = null === $channel ? $id : $channel->label();
		}

		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );

		return $out;
	}

	/**
	 * Adds the title, channel name and retry flag to raw rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from DeliveryLog.
	 * @return array<int, array<string, mixed>>
	 */
	private function decorate( array $rows ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', array_column( $rows, 'post_id' ) ) ) ) );

		if ( array() !== $ids ) {
			// One query fills the cache for every title on the page.
			get_posts(
				array(
					'post__in'               => $ids,
					'post_type'              => 'any',
					'post_status'            => 'any',
					'posts_per_page'         => count( $ids ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'ignore_sticky_posts'    => true,
				)
			);
		}

		$retry   = $this->plugin->make( DeliveryRetry::class );
		$manage  = $this->plugin->access()->can( Access::MANAGE );
		$decoded = array();

		foreach ( $rows as $row ) {
			$post_id = (int) $row['post_id'];
			$channel = $this->plugin->registry()->get( (string) $row['channel'] );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;
			$title   = $post instanceof \WP_Post ? get_the_title( $post ) : '';

			if ( '' === $title && $post_id > 0 ) {
				$title = Removed::recall( $post_id )['title'];
			}

			$row['title']         = $title;
			$row['post_exists']   = $post instanceof \WP_Post;
			$row['channel_label'] = null === $channel ? (string) $row['channel'] : $channel->label();
			$row['can_retry']     = $manage && in_array( $row['status'], array( Status::FAILED, Status::RETRY ), true ) && $retry->can_retry( $row );
			$decoded[]            = $row;
		}

		return $decoded;
	}

	/**
	 * IDs of articles whose title holds the text. At most 200, newest first.
	 *
	 * @param string $text Part of a title.
	 * @return int[]
	 */
	private function post_ids_titled( string $text ): array {
		global $wpdb;

		$like = '%' . $wpdb->esc_like( $text ) . '%';
		$ids  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_status <> 'auto-draft' AND post_type NOT IN ( 'revision', 'nav_menu_item' ) ORDER BY ID DESC LIMIT 200", $like ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
