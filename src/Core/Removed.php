<?php
/**
 * What an article looked like before it was unpublished or deleted.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A deleted post has no address left, but a push that tells a search engine
 * the page is gone needs it. The listener writes a small snapshot while the
 * article is still public, and the queued delivery reads it. Snapshots expire
 * after a week.
 */
final class Removed {

	private const PREFIX = 'estasoft_nd_removed_';

	/**
	 * Keeps a snapshot.
	 *
	 * @param int                  $post_id Article ID.
	 * @param array<string,string> $data    url, title and language.
	 * @return void
	 */
	public static function remember( int $post_id, array $data ): void {
		set_transient(
			self::PREFIX . $post_id,
			array(
				'url'      => (string) ( $data['url'] ?? '' ),
				'title'    => (string) ( $data['title'] ?? '' ),
				'language' => (string) ( $data['language'] ?? '' ),
			),
			WEEK_IN_SECONDS
		);
	}

	/**
	 * Reads a snapshot.
	 *
	 * @param int $post_id Article ID.
	 * @return array{url: string, title: string, language: string}
	 */
	public static function recall( int $post_id ): array {
		$data = get_transient( self::PREFIX . $post_id );
		$data = is_array( $data ) ? $data : array();

		return array(
			'url'      => (string) ( $data['url'] ?? '' ),
			'title'    => (string) ( $data['title'] ?? '' ),
			'language' => (string) ( $data['language'] ?? '' ),
		);
	}
}
