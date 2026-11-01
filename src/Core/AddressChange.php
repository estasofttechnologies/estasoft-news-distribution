<?php
/**
 * The record of a published article's address changing.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `Core\EventListener` tells the plugin when a published article keeps its ID and gets a new address (a slug edit,
 * a category in the path, a parent, a language directory). This keeps that in the audit trail as an
 * `Audit::ADDRESS` entry whose detail holds the old and the new address, and reads it back for the History tab.
 *
 * It sends nothing. The channels read an article's address when they deliver, so an update after the change names
 * the new address, and nothing names the old one. WordPress sends the old address of a changed slug to the new one
 * by itself; this plugin adds no redirect.
 *
 * Get it with `Plugin::instance()->make( AddressChange::class )`.
 */
final class AddressChange {

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Writes one entry.
	 *
	 * @param int    $post_id  Article ID.
	 * @param string $was      The address before.
	 * @param string $now      The address now.
	 * @param bool   $redirect Whether WordPress sends the old address to the new one by itself.
	 * @return void
	 */
	public function record( int $post_id, string $was, string $now, bool $redirect ): void {
		$this->plugin->audit()->record(
			Audit::ADDRESS,
			$post_id,
			(string) wp_json_encode(
				array(
					'old'      => $was,
					'new'      => $now,
					'redirect' => $redirect,
				)
			)
		);
	}

	/**
	 * Whether the address changed in the save made at a time (give or take the few seconds one save takes).
	 *
	 * The pings that skip a minor edit ask this: a save that only moved the address keeps the date, but the new
	 * address is still news.
	 *
	 * @param int $post_id Article ID.
	 * @param int $at      Unix time of the save, such as the date guard's decision.
	 * @param int $within  Seconds either side that still count as the same save.
	 * @return bool
	 */
	public function came_with( int $post_id, int $at, int $within = 180 ): bool {
		if ( $at < 1 ) {
			return false;
		}

		foreach ( $this->history( $post_id, 3 ) as $change ) {
			if ( abs( $change['at'] - $at ) <= $within ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The address changes of one article, newest first.
	 *
	 * @param int $post_id Article ID.
	 * @param int $limit   Most entries.
	 * @return array<int, array{at: int, old: string, new: string, redirect: bool, user: int}>
	 */
	public function history( int $post_id, int $limit = 25 ): array {
		$out = array();

		foreach ( $this->plugin->audit()->for_post( $post_id, Audit::ADDRESS, $limit ) as $row ) {
			$detail = json_decode( (string) $row['detail'], true );

			if ( ! is_array( $detail ) || ! isset( $detail['old'], $detail['new'] ) ) {
				continue;
			}

			$out[] = array(
				'at'       => (int) $row['created_ts'],
				'old'      => (string) $detail['old'],
				'new'      => (string) $detail['new'],
				'redirect' => ! empty( $detail['redirect'] ),
				'user'     => (int) $row['user_id'],
			);
		}

		return $out;
	}
}
