<?php
/**
 * The stable identity of an article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * An ID written once, at first publish, and never changed, so feeds and
 * partners can recognise the same article after a new address or a new title.
 * A copied post gets a new one.
 */
final class ArticleId {

	public const META_ID        = '_estasoft_nd_id';
	public const META_FIRST_GMT = '_estasoft_nd_first_published_gmt';

	/**
	 * Registers the meta keys, hidden from REST.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		foreach ( array( self::META_ID, self::META_FIRST_GMT ) as $key ) {
			register_meta(
				'post',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'auth_callback'     => '__return_false',
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}
	}

	/**
	 * Makes sure a published article has its ID and first-published date.
	 *
	 * @param int $post_id Article ID.
	 * @return string The ID.
	 */
	public function ensure( int $post_id ): string {
		$id = (string) get_post_meta( $post_id, self::META_ID, true );

		if ( '' !== $id && ! $this->is_used_elsewhere( $id, $post_id ) ) {
			$this->ensure_first_published( $post_id );
			return $id;
		}

		$id = wp_generate_uuid4();
		update_post_meta( $post_id, self::META_ID, $id );
		delete_post_meta( $post_id, self::META_FIRST_GMT );
		$this->ensure_first_published( $post_id );

		return $id;
	}

	/**
	 * Reads the ID without creating one.
	 *
	 * @param int $post_id Article ID.
	 * @return string Empty when the article was never published.
	 */
	public function get( int $post_id ): string {
		return (string) get_post_meta( $post_id, self::META_ID, true );
	}

	/**
	 * The first publish time, which never changes.
	 *
	 * @param int $post_id Article ID.
	 * @return int Unix time, or 0.
	 */
	public function first_published( int $post_id ): int {
		return Clock::from_gmt( (string) get_post_meta( $post_id, self::META_FIRST_GMT, true ) );
	}

	/**
	 * Writes the first-published date once.
	 *
	 * @param int $post_id Article ID.
	 * @return void
	 */
	private function ensure_first_published( int $post_id ): void {
		if ( '' !== (string) get_post_meta( $post_id, self::META_FIRST_GMT, true ) ) {
			return;
		}

		$post = get_post( $post_id );
		$gmt  = $post instanceof \WP_Post && '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : Clock::to_gmt();

		update_post_meta( $post_id, self::META_FIRST_GMT, $gmt );
	}

	/**
	 * Whether another post already holds this ID, as a duplicate-post plugin may leave it.
	 *
	 * @param string $id      The ID.
	 * @param int    $post_id The post that holds it now.
	 * @return bool
	 */
	private function is_used_elsewhere( string $id, int $post_id ): bool {
		global $wpdb;

		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s AND post_id <> %d", self::META_ID, $id, $post_id )
		);

		return (int) $count > 0;
	}
}
