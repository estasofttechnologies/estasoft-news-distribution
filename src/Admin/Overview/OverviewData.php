<?php
/**
 * Everything the Overview shows, built from stored results.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Overview;

use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The one service behind the Overview screen, `GET /overview`, the dashboard widget
 * and the Posts column. It reads the stored check of each article, the delivery
 * log, the fetch log, channel health, the application tracker and the plugin
 * detector. It never calls another server and never runs a check.
 *
 * Shape (times are ISO 8601 in UTC, statuses are `Core\Status` codes):
 *
 *     {
 *       generated, language ("all" or a code),
 *       languages: [ { code, name } ],
 *       summary:   { attention, channels_on, channels_total, articles, clean },
 *       needs:     [ { id, kind, status, title, detail, channel, action } ],
 *       groups:    [ { id, title, help, total, counts{status: n}, attention, open, rows: [ { key, channel, name, summary, status, enabled, url } ] } ],
 *       articles:  [ see ArticleRows ],
 *       path:      { sections, started, live, all_live } | null,
 *       reminders: [ { platform, name, date, overdue } ]
 *     }
 */
final class OverviewData {

	/**
	 * How long the widget keeps what it read.
	 */
	public const TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient that holds the cached answer (one per kind of viewer).
	 */
	public const CACHE = 'estasoft_nd_overview_';

	/**
	 * How many of the latest articles are listed.
	 */
	public const LATEST = 5;

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Builds the overview now.
	 *
	 * @param array<string, mixed> $args `language`: a language code to look at, or empty for all.
	 *                                   `path`: false leaves out the path to each platform.
	 * @return array<string, mixed>
	 */
	public function build( array $args = array() ): array {
		$language = $this->language( (string) ( $args['language'] ?? '' ) );
		$needs    = ( new NeedsYou( $this->plugin ) )->items( $language );
		$groups   = $this->groups();
		$articles = ( new ArticleRows( $this->plugin ) )->latest( $language, self::LATEST );
		$path     = new PlatformPath( $this->plugin );
		$enabled  = count( $this->plugin->registry()->enabled() );

		return array(
			'generated' => Clock::iso( Clock::now() ),
			'language'  => '' === $language ? 'all' : $language,
			'languages' => $this->languages(),
			'summary'   => array(
				'attention'      => count( $needs ),
				'channels_on'    => $enabled,
				'channels_total' => count( $this->plugin->registry()->all() ),
				'articles'       => count( $articles ),
				'clean'          => array() === $needs,
			),
			'needs'     => $needs,
			'groups'    => $groups,
			'articles'  => $articles,
			'path'      => false === ( $args['path'] ?? true ) ? null : $path->build(),
			'reminders' => $path->reminders(),
		);
	}

	/**
	 * The overview for the dashboard widget: all languages, kept for five minutes.
	 *
	 * @param bool $fresh Read again even when a copy is kept.
	 * @return array<string, mixed>
	 */
	public function cached( bool $fresh = false ): array {
		$key = self::CACHE . ( $this->plugin->access()->can( Access::MANAGE ) ? 'manage' : 'view' );

		if ( ! $fresh ) {
			$kept = get_transient( $key );

			if ( is_array( $kept ) && isset( $kept['generated'] ) ) {
				return $kept;
			}
		}

		$data = $this->build( array( 'path' => false ) );

		set_transient( $key, $data, self::TTL );

		return $data;
	}

	/**
	 * Drops the kept copies, so the next widget view reads again.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( self::CACHE . 'manage' );
		delete_transient( self::CACHE . 'view' );
	}

	/**
	 * A language code the site has, or an empty string.
	 *
	 * @param string $code What was asked for.
	 * @return string
	 */
	public function language( string $code ): string {
		$code = sanitize_key( $code );

		if ( '' === $code || 'all' === $code ) {
			return '';
		}

		return in_array( $code, array_map( 'strval', $this->plugin->languages()->languages() ), true ) ? $code : '';
	}

	/**
	 * The languages the filter offers. Empty when the site has one language.
	 *
	 * @return array<int, array{code: string, name: string}>
	 */
	public function languages(): array {
		$codes = array_values( array_unique( array_map( 'strval', $this->plugin->languages()->languages() ) ) );

		if ( count( $codes ) < 2 ) {
			return array();
		}

		$out = array();

		foreach ( $codes as $code ) {
			$out[] = array(
				'code' => $code,
				'name' => UiLanguages::native_name( $code ),
			);
		}

		return $out;
	}

	/**
	 * The channels by connection type, with each channel's own hub row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function groups(): array {
		$by_group = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$by_group[ $channel->group() ][] = $channel;
		}

		$out = array();

		foreach ( Group::all() as $id => $copy ) {
			$channels = $by_group[ $id ] ?? array();

			usort(
				$channels,
				static function ( $a, $b ): int {
					return $a->order() <=> $b->order();
				}
			);

			$rows   = array();
			$counts = array();

			foreach ( $channels as $channel ) {
				foreach ( $channel->hub_rows() as $row ) {
					$status = (string) $row['status'];
					$rows[] = array(
						'key'     => (string) $row['key'],
						'channel' => $channel->id(),
						'name'    => (string) $row['name'],
						'summary' => (string) $row['summary'],
						'status'  => $status,
						'enabled' => $row['enabled'],
						'url'     => Facts::channel_url( $this->plugin, $channel->id() ),
					);

					$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
				}
			}

			if ( array() === $rows ) {
				continue;
			}

			$attention = $counts[ Status::ATTENTION ] ?? 0;

			$out[] = array(
				'id'        => (string) $id,
				'title'     => $copy['title'],
				'help'      => $copy['help'],
				'total'     => count( $rows ),
				'counts'    => $counts,
				'attention' => $attention,
				'open'      => $attention > 0,
				'rows'      => $rows,
			);
		}

		return $out;
	}
}
