<?php
/**
 * Admin addresses that emails and screens link to.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the addresses of the screens an alert points at, so an email
 * and a screen never disagree about where "Open the Delivery log" goes.
 */
final class Links {

	/**
	 * A screen of the plugin.
	 *
	 * @param string               $page The `page` argument, such as "estasoft-nd-log".
	 * @param array<string, mixed> $args More query arguments.
	 * @return string
	 */
	public static function admin( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * A Settings section.
	 *
	 * @param string               $section Section name, or empty for the first one.
	 * @param array<string, mixed> $args    More query arguments, such as `tab`.
	 * @return string
	 */
	public static function settings( string $section = '', array $args = array() ): string {
		if ( '' !== $section ) {
			$args = array_merge( array( 'section' => $section ), $args );
		}

		return self::admin( 'estasoft-nd-settings', $args );
	}

	/**
	 * The Delivery log.
	 *
	 * @return string
	 */
	public static function delivery_log(): string {
		return self::admin( 'estasoft-nd-log' );
	}

	/**
	 * The Fetch monitor report.
	 *
	 * @return string
	 */
	public static function fetch_monitor(): string {
		return self::admin( 'estasoft-nd-reports', array( 'report' => 'fetch-monitor' ) );
	}

	/**
	 * Article check, for one article or the whole list.
	 *
	 * @param int    $post_id Article ID, or 0 for the list.
	 * @param string $tab     Tab such as "assign" or "takedown".
	 * @return string
	 */
	public static function check( int $post_id = 0, string $tab = '' ): string {
		$args = array();

		if ( $post_id > 0 ) {
			$args['post'] = $post_id;
		}

		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return self::admin( 'estasoft-nd-check', $args );
	}

	/**
	 * One channel's page.
	 *
	 * @param string $channel_id Channel id.
	 * @return string
	 */
	public static function channel( string $channel_id ): string {
		return self::admin( 'estasoft-nd-channels', array( 'channel' => $channel_id ) );
	}
}
