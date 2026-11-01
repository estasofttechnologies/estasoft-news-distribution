<?php
/**
 * The five groups of the Channels hub, in their fixed order.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * A group is how a channel connects: what the person does, and what News
 * Distribution does. The order and the wording are fixed by the design.
 */
final class Group {

	public const SITEMAP  = 'sitemap';
	public const PARTNER  = 'partner';
	public const READERS  = 'readers';
	public const LISTINGS = 'listings';
	public const PUSHES   = 'pushes';

	/**
	 * Group id => title and one line of help, in hub order.
	 *
	 * @return array<string, array{title: string, help: string}>
	 */
	public static function all(): array {
		return array(
			self::SITEMAP  => array(
				'title' => __( 'Sitemap', 'estasoft-news-distribution' ),
				'help'  => __( 'You submit the sitemap in each search engine’s webmaster tools.', 'estasoft-news-distribution' ),
			),
			self::PARTNER  => array(
				'title' => __( 'Partner feeds', 'estasoft-news-distribution' ),
				'help'  => __( 'You apply, then the platform fetches your feed.', 'estasoft-news-distribution' ),
			),
			self::READERS  => array(
				'title' => __( 'Open readers', 'estasoft-news-distribution' ),
				'help'  => __( 'No approval: any reader can follow your feed.', 'estasoft-news-distribution' ),
			),
			self::LISTINGS => array(
				'title' => __( 'Listings', 'estasoft-news-distribution' ),
				'help'  => __( 'You ask to be listed. News Distribution prepares the details.', 'estasoft-news-distribution' ),
			),
			self::PUSHES   => array(
				'title' => __( 'Pushes', 'estasoft-news-distribution' ),
				'help'  => __( 'News Distribution sends to these through the background queue.', 'estasoft-news-distribution' ),
			),
		);
	}
}
