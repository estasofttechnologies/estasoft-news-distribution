<?php
/**
 * Articles that were unpublished or deleted, kept so the feed can say so.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SmartNews;

use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A feed that simply drops an unpublished article leaves the platform to guess.
 * The SmartNews feed keeps a small marker for each removed article for a few
 * days (see `deleted_days` in rules/smartnews.json). The markers are written
 * when the unpublish or delete event fires, never on a timer, and an article
 * that is published again loses its marker.
 */
final class Removals {

	/**
	 * Where the markers are kept (not loaded on every request).
	 */
	public const OPTION = 'estasoft_nd_smartnews_removed';

	/**
	 * Listens to the plugin's post event: adds a marker for a removal, drops
	 * it when the article is published again.
	 *
	 * @param mixed $event A Core\Event.
	 * @return void
	 */
	public static function on_event( $event ): void {
		if ( ! $event instanceof Event || ! Plugin::instance()->channel_settings()->is_enabled( 'smartnews' ) ) {
			return;
		}

		$rows = self::stored();
		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $event ): bool {
					return (int) $row['post_id'] !== $event->post_id();
				}
			)
		);

		if ( $event->removes_content() && '' !== $event->url() ) {
			$rows[] = array(
				'post_id'  => $event->post_id(),
				'url'      => $event->url(),
				'title'    => $event->title(),
				'language' => $event->language(),
				'guid'     => Plugin::instance()->article_ids()->get( $event->post_id() ),
				'at'       => $event->timestamp(),
			);
		}

		self::save( $rows, $event->timestamp() );
	}

	/**
	 * The markers still inside the window for one language, newest first.
	 *
	 * @param string $language Language code.
	 * @param int    $now      Unix time to treat as now.
	 * @param int    $days     How many days a marker stays.
	 * @return array<int, array{post_id: int, url: string, title: string, language: string, guid: string, at: int}>
	 */
	public static function active( string $language, int $now, int $days ): array {
		$keep = array();

		foreach ( self::stored() as $row ) {
			if ( $row['language'] === $language && $row['at'] >= $now - $days * DAY_IN_SECONDS && $row['at'] <= $now ) {
				$keep[] = $row;
			}
		}

		usort(
			$keep,
			static function ( array $a, array $b ): int {
				return $b['at'] <=> $a['at'];
			}
		);

		return $keep;
	}

	/**
	 * The stored markers, cleaned.
	 *
	 * @return array<int, array{post_id: int, url: string, title: string, language: string, guid: string, at: int}>
	 */
	public static function stored(): array {
		$raw  = get_option( self::OPTION, array() );
		$rows = array();

		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) || empty( $row['url'] ) ) {
				continue;
			}

			$rows[] = array(
				'post_id'  => (int) ( $row['post_id'] ?? 0 ),
				'url'      => (string) $row['url'],
				'title'    => (string) ( $row['title'] ?? '' ),
				'language' => (string) ( $row['language'] ?? '' ),
				'guid'     => (string) ( $row['guid'] ?? '' ),
				'at'       => (int) ( $row['at'] ?? 0 ),
			);
		}

		return $rows;
	}

	/**
	 * Saves the markers, dropping the ones past the longest window.
	 *
	 * @param array<int, array<string, mixed>> $rows Markers.
	 * @param int                              $now  Unix time to treat as now.
	 * @return void
	 */
	private static function save( array $rows, int $now ): void {
		$days = (int) Plugin::instance()->rules()->limit( 'smartnews', 'deleted_days', 3 );
		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $now, $days ): bool {
					return (int) $row['at'] >= $now - $days * DAY_IN_SECONDS;
				}
			)
		);

		if ( array() === $rows ) {
			delete_option( self::OPTION );
			return;
		}

		update_option( self::OPTION, $rows, false );
	}
}
