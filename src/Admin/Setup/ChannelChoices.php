<?php
/**
 * The channels step of the first-run setup: the three starting setups and the list to choose from.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * What the person can turn on while setting up, and what each choice asks of them.
 *
 * A row is one of three kinds:
 *
 * - `toggle`: a box that turns the channel on or off (`ChannelSettings::set_enabled()`).
 * - `always`: on with the rest, and shown ticked and fixed. The feed that reader apps follow.
 * - `link`: a channel with no switch, or one that needs more than a switch (Outlets needs an account,
 *   Listings are requests). The row opens its page in Channels.
 *
 * A row appears only when its channel exists, so a channel that is not installed leaves no gap.
 */
final class ChannelChoices {

	public const FIELD  = 'channels';
	public const PRESET = 'preset';
	public const SHOWN  = 'preset_shown';

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The starting setups, in the order they are offered.
	 *
	 * @return array<string, array{title: string, description: string, recommended: bool, channels: string[]}>
	 */
	public static function presets(): array {
		$simple = array( 'google-news', 'bing', 'flipboard' );

		return array(
			'simple'  => array(
				'title'       => __( 'Start simple', 'estasoft-news-distribution' ),
				'description' => __( 'Google News, Bing and open readers. Nothing to apply for.', 'estasoft-news-distribution' ),
				'recommended' => true,
				'channels'    => $simple,
			),
			'digital' => array(
				'title'       => __( 'Digital publication', 'estasoft-news-distribution' ),
				'description' => __( 'Start simple, plus Microsoft Start, WebSub and IndexNow. You still ask Microsoft for an invitation.', 'estasoft-news-distribution' ),
				'recommended' => false,
				'channels'    => array_merge( $simple, array( 'microsoft-start', 'websub', 'indexnow' ) ),
			),
			'custom'  => array(
				'title'       => __( 'Choose for myself', 'estasoft-news-distribution' ),
				'description' => __( 'Start with nothing selected.', 'estasoft-news-distribution' ),
				'recommended' => false,
				'channels'    => array(),
			),
		);
	}

	/**
	 * Cleans a setup name from a request.
	 *
	 * @param mixed $preset The posted value.
	 * @return string A key of `presets()`, or an empty string.
	 */
	public static function clean_preset( $preset ): string {
		$preset = is_string( $preset ) ? sanitize_key( $preset ) : '';

		return isset( self::presets()[ $preset ] ) ? $preset : '';
	}

	/**
	 * The groups with their rows, in hub order.
	 *
	 * Each group is `title`, `help`, `collapsed` and `rows`; each row `id`, `name`, `description`, `note`,
	 * `kind`, `url` (for `link` rows) and `on`.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function groups(): array {
		$titles = Group::all();
		$order  = array(
			Group::SITEMAP  => array( 'google-news', 'bing', 'yandex', 'baidu' ),
			Group::PARTNER  => array( 'microsoft-start', 'yahoo-news', 'apple-news', 'smartnews', 'news-break' ),
			Group::READERS  => array( 'flipboard', 'feed-readers' ),
			Group::LISTINGS => array( 'ground-news', 'particle' ),
			Group::PUSHES   => array( 'websub', 'indexnow', 'outlets' ),
		);
		$copy   = self::copy();
		$groups = array();

		foreach ( $order as $group => $ids ) {
			$rows = array();

			foreach ( $ids as $id ) {
				$entry = $copy[ $id ];

				if ( null === $this->plugin->registry()->get( $entry['channel'] ) ) {
					continue;
				}

				$rows[] = array(
					'id'          => $id,
					'name'        => '' !== $entry['name'] ? $entry['name'] : $this->plugin->registry()->get( $entry['channel'] )->label(),
					'description' => $entry['description'],
					'note'        => $entry['note'],
					'kind'        => $entry['kind'],
					'url'         => 'link' === $entry['kind'] ? Links::channel( $entry['channel'] ) : '',
					'on'          => 'always' === $entry['kind'] || ( 'toggle' === $entry['kind'] && $this->plugin->channel_settings()->is_enabled( $id ) ),
				);
			}

			if ( array() === $rows ) {
				continue;
			}

			$groups[ $group ] = array(
				'title'     => $titles[ $group ]['title'],
				'help'      => $titles[ $group ]['help'],
				'collapsed' => in_array( $group, array( Group::LISTINGS, Group::PUSHES ), true ),
				'rows'      => $rows,
			);
		}

		return $groups;
	}

	/**
	 * The ids a person can switch here: the boxes of the list.
	 *
	 * @return string[]
	 */
	public function switchable(): array {
		$ids = array();

		foreach ( $this->groups() as $group ) {
			foreach ( $group['rows'] as $row ) {
				if ( 'toggle' === $row['kind'] ) {
					$ids[] = (string) $row['id'];
				}
			}
		}

		return $ids;
	}

	/**
	 * The ids that are on now.
	 *
	 * @return string[]
	 */
	public function on_now(): array {
		return array_values( array_intersect( $this->switchable(), $this->enabled_ids() ) );
	}

	/**
	 * The ids a setup turns on, limited to channels that exist here.
	 *
	 * @param string $preset A key of `presets()`.
	 * @return string[]
	 */
	public function for_preset( string $preset ): array {
		$presets = self::presets();

		return isset( $presets[ $preset ] ) ? array_values( array_intersect( $this->switchable(), $presets[ $preset ]['channels'] ) ) : array();
	}

	/**
	 * What the list shows ticked when it opens.
	 *
	 * A site that already has channels on, or that finished this step before, shows what is on.
	 * A new site shows the recommended setup.
	 *
	 * @param string $stored The setup stored by an earlier visit, or an empty string.
	 * @return string[]
	 */
	public function selected( string $stored ): array {
		$now = $this->on_now();

		if ( '' !== $stored || array() !== $now ) {
			return $now;
		}

		return $this->for_preset( 'simple' );
	}

	/**
	 * The setup that matches a selection, so the cards show the right one chosen.
	 *
	 * @param string[] $ids The selection.
	 * @return string A key of `presets()`.
	 */
	public function matching( array $ids ): string {
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );

		foreach ( array( 'simple', 'digital' ) as $preset ) {
			$set = $this->for_preset( $preset );
			sort( $set );

			if ( $set === $ids ) {
				return $preset;
			}
		}

		return 'custom';
	}

	/**
	 * What a posted form chose.
	 *
	 * Without a script, choosing another setup card does not tick the boxes, so a setup that differs from
	 * the one the page was drawn with replaces the boxes. With the script, the boxes follow the card and
	 * the two agree.
	 *
	 * @param array<string, mixed> $post The unslashed form.
	 * @return array{ids: string[], preset: string}
	 */
	public function read( array $post ): array {
		$preset = self::clean_preset( $post[ self::PRESET ] ?? '' );
		$shown  = self::clean_preset( $post[ self::SHOWN ] ?? '' );

		if ( '' !== $preset && $preset !== $shown ) {
			return array(
				'ids'    => $this->for_preset( $preset ),
				'preset' => $preset,
			);
		}

		$posted = array();

		foreach ( (array) ( $post[ self::FIELD ] ?? array() ) as $id ) {
			if ( is_string( $id ) ) {
				$posted[] = sanitize_key( $id );
			}
		}

		$ids = array_values( array_intersect( $this->switchable(), $posted ) );

		return array(
			'ids'    => $ids,
			'preset' => $this->matching( $ids ),
		);
	}

	/**
	 * Turns the chosen channels on and the others in the list off. Feed readers are always turned on.
	 *
	 * @param string[] $ids The chosen ids.
	 * @return void
	 */
	public function apply( array $ids ): void {
		$settings = $this->plugin->channel_settings();

		foreach ( $this->switchable() as $id ) {
			$want = in_array( $id, $ids, true );

			if ( $settings->is_enabled( $id ) !== $want ) {
				$settings->set_enabled( $id, $want );
			}
		}

		foreach ( $this->groups() as $group ) {
			foreach ( $group['rows'] as $row ) {
				if ( 'always' === $row['kind'] && ! $settings->is_enabled( (string) $row['id'] ) ) {
					$settings->set_enabled( (string) $row['id'], true );
				}
			}
		}
	}

	/**
	 * The ids of the channels that are on, from the stored settings.
	 *
	 * @return string[]
	 */
	private function enabled_ids(): array {
		$ids      = array();
		$settings = $this->plugin->channel_settings();

		foreach ( array_keys( self::copy() ) as $id ) {
			if ( $settings->is_enabled( $id ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * What each row says. `channel` is the registered channel the row belongs to; `name` is empty to use
	 * the channel's own name.
	 *
	 * @return array<string, array{channel: string, name: string, description: string, note: string, kind: string}>
	 */
	private static function copy(): array {
		$needs_site = __( 'Needs your site verified first', 'estasoft-news-distribution' );
		$no_apply   = __( 'No approval needed', 'estasoft-news-distribution' );

		return array(
			'google-news'     => array(
				'channel'     => 'google-news',
				'name'        => '',
				'description' => __( 'A news sitemap for the last 2 days.', 'estasoft-news-distribution' ),
				'note'        => $no_apply,
				'kind'        => 'toggle',
			),
			'bing'            => array(
				'channel'     => 'bing',
				'name'        => '',
				'description' => __( 'A plain search sitemap. Bing tries to fetch it when you submit it.', 'estasoft-news-distribution' ),
				'note'        => $needs_site,
				'kind'        => 'toggle',
			),
			'yandex'          => array(
				'channel'     => 'yandex',
				'name'        => '',
				'description' => __( 'The same plain search sitemap. Yandex usually reads a new sitemap within 2 weeks.', 'estasoft-news-distribution' ),
				'note'        => $needs_site,
				'kind'        => 'toggle',
			),
			'baidu'           => array(
				'channel'     => 'baidu',
				'name'        => '',
				'description' => __( 'The same plain search sitemap.', 'estasoft-news-distribution' ),
				'note'        => __( 'Console in Chinese; registering may ask for a phone number', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'microsoft-start' => array(
				'channel'     => 'microsoft-start',
				'name'        => '',
				'description' => __( 'A feed for Microsoft Start Partner Hub, one per language.', 'estasoft-news-distribution' ),
				'note'        => __( 'Needs an invitation from Microsoft', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'yahoo-news'      => array(
				'channel'     => 'yahoo-news',
				'name'        => '',
				'description' => __( 'A feed for Yahoo content partners.', 'estasoft-news-distribution' ),
				'note'        => __( 'You apply, and Yahoo decides', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'apple-news'      => array(
				'channel'     => 'apple-news',
				'name'        => '',
				'description' => __( 'An RSS feed for a News Publisher channel.', 'estasoft-news-distribution' ),
				'note'        => __( 'You apply, and Apple decides', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'smartnews'       => array(
				'channel'     => 'smartnews',
				'name'        => '',
				'description' => __( 'A SmartFormat feed.', 'estasoft-news-distribution' ),
				'note'        => __( 'You apply, and SmartNews decides', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'news-break'      => array(
				'channel'     => 'news-break',
				'name'        => '',
				'description' => __( 'An RSS feed for a publisher profile.', 'estasoft-news-distribution' ),
				'note'        => __( 'You apply, and News Break decides', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'flipboard'       => array(
				'channel'     => 'flipboard',
				'name'        => '',
				'description' => __( 'A full-text feed with images, for Flipboard magazines.', 'estasoft-news-distribution' ),
				'note'        => $no_apply,
				'kind'        => 'toggle',
			),
			'feed-readers'    => array(
				'channel'     => 'feed-readers',
				'name'        => __( 'Feedly, Inoreader and NewsBlur', 'estasoft-news-distribution' ),
				'description' => __( 'They read the same feed, which News Distribution advertises on every page.', 'estasoft-news-distribution' ),
				'note'        => __( 'Always on', 'estasoft-news-distribution' ),
				'kind'        => 'always',
			),
			'ground-news'     => array(
				'channel'     => 'listings',
				'name'        => __( 'Ground News', 'estasoft-news-distribution' ),
				'description' => __( 'You ask to be listed. News Distribution prepares the details.', 'estasoft-news-distribution' ),
				'note'        => __( 'You ask, and Ground News decides', 'estasoft-news-distribution' ),
				'kind'        => 'link',
			),
			'particle'        => array(
				'channel'     => 'listings',
				'name'        => __( 'Particle', 'estasoft-news-distribution' ),
				'description' => __( 'You ask to be listed. News Distribution prepares the details.', 'estasoft-news-distribution' ),
				'note'        => __( 'You ask, and Particle decides', 'estasoft-news-distribution' ),
				'kind'        => 'link',
			),
			'websub'          => array(
				'channel'     => 'websub',
				'name'        => '',
				'description' => __( 'Tells a WebSub hub each time you publish.', 'estasoft-news-distribution' ),
				'note'        => __( 'Needs a hub address', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'indexnow'        => array(
				'channel'     => 'indexnow',
				'name'        => '',
				'description' => __( 'Tells search engines that use IndexNow when an article is new or changed.', 'estasoft-news-distribution' ),
				'note'        => __( 'News Distribution serves the key file', 'estasoft-news-distribution' ),
				'kind'        => 'toggle',
			),
			'outlets'         => array(
				'channel'     => 'outlets',
				'name'        => '',
				'description' => __( 'Posts each new article to accounts and webhooks you connect.', 'estasoft-news-distribution' ),
				'note'        => __( 'Needs an account to post to', 'estasoft-news-distribution' ),
				'kind'        => 'link',
			),
		);
	}
}
