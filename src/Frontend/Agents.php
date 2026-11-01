<?php
/**
 * Names the platform behind a feed request.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The fetch log keeps a platform name or "other", never a full user agent and
 * never an IP address.
 */
final class Agents {

	/**
	 * Substring of a user agent (lower case) => platform name.
	 *
	 * @return array<string, string>
	 */
	public static function map(): array {
		$map = array(
			'googlebot-news'        => 'googlebot-news',
			'googlebot'             => 'googlebot',
			'google-inspectiontool' => 'googlebot',
			'bingbot'               => 'bingbot',
			'msnbot'                => 'bingbot',
			'yandex'                => 'yandex',
			'baiduspider'           => 'baidu',
			'applebot'              => 'applebot',
			'smartnews'             => 'smartnews',
			'flipboard'             => 'flipboard',
			'yahoo'                 => 'yahoo',
			'feedly'                => 'feedly',
			'inoreader'             => 'inoreader',
			'newsblur'              => 'newsblur',
			'newsbreak'             => 'newsbreak',
			'microsoft start'       => 'microsoft-start',
			'msstart'               => 'microsoft-start',
		);

		/**
		 * Filters the platforms the fetch log can name.
		 *
		 * @param array<string, string> $map Lower-case user-agent fragment => platform name.
		 */
		$filtered = apply_filters( 'estasoft_nd_fetch_agents', $map );

		return is_array( $filtered ) ? $filtered : $map;
	}

	/**
	 * Names the platform for a user agent.
	 *
	 * @param string $user_agent The User-Agent header.
	 * @return string A platform name, or "other".
	 */
	public static function detect( string $user_agent ): string {
		$agent = strtolower( $user_agent );

		foreach ( self::map() as $fragment => $name ) {
			if ( '' !== $fragment && false !== strpos( $agent, $fragment ) ) {
				return $name;
			}
		}

		return 'other';
	}
}
