<?php
/**
 * The AI log of every site, as one CSV or JSON file.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * For records or an auditor. It holds what each site's AI log holds: the feature, the connection
 * (a name, never a key), the decision, the reviewer's name and a short summary of the suggestion.
 * It never holds an API key, and the log keeps no copy of an article.
 *
 * Sites are read one at a time through `SiteLoop`, a page of 500 entries at a time, and each page
 * is written out after WordPress has gone back to the site it started on, so a large network never
 * sits in memory and a failed write never leaves the wrong site current. At most 200,000 entries
 * are written; the JSON file says when it stopped short.
 *
 * A CSV cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is written with a
 * leading apostrophe, so a spreadsheet never runs a suggestion as a formula.
 */
final class AiExport {

	public const PAGE     = 500;
	public const MAX_ROWS = 200000;

	/**
	 * The columns, in order.
	 *
	 * @return string[]
	 */
	public static function columns(): array {
		return array( 'site_id', 'site', 'id', 'time', 'article', 'feature', 'connection', 'decision', 'reviewer', 'summary' );
	}

	/**
	 * The file name for a format.
	 *
	 * @param string $format csv or json.
	 * @return string
	 */
	public static function filename( string $format ): string {
		return 'news-distribution-ai-log-' . gmdate( 'Y-m-d', Clock::now() ) . '.' . ( 'json' === $format ? 'json' : 'csv' );
	}

	/**
	 * Writes the export through a callback, a piece at a time.
	 *
	 * @param string   $format csv or json.
	 * @param callable $write  Called with each piece of the file.
	 * @return array{rows: int, sites: int, truncated: bool}
	 */
	public function write( string $format, callable $write ): array {
		$json      = 'json' === $format;
		$rows      = 0;
		$sites     = 0;
		$truncated = false;
		$first     = true;
		$names     = array();

		if ( $json ) {
			$write( '{"exported":' . wp_json_encode( Clock::iso( Clock::now() ) ) . ',"entries":[' );
		} else {
			$write( self::csv_line( self::columns() ) );
		}

		foreach ( SiteLoop::site_ids() as $blog_id ) {
			$name    = '';
			$last_id = 0;
			$counted = false;
			$more    = true;

			while ( $more ) {
				$page = SiteLoop::in_site(
					$blog_id,
					static function () use ( $last_id ): array {
						return self::page( $last_id );
					}
				);

				if ( '' === $name ) {
					$name = $page['site'];
				}

				if ( array() !== $page['rows'] && ! $counted ) {
					++$sites;
					$counted = true;
				}

				foreach ( $page['rows'] as $row ) {
					if ( $rows >= self::MAX_ROWS ) {
						$truncated = true;
						break 3;
					}

					$reviewer = (int) $row['reviewer_id'];

					if ( $reviewer > 0 && ! isset( $names[ $reviewer ] ) ) {
						$user               = get_userdata( $reviewer );
						$names[ $reviewer ] = $user instanceof \WP_User ? (string) $user->display_name : '';
					}

					$entry = array(
						'site_id'    => $blog_id,
						'site'       => $name,
						'id'         => (int) $row['id'],
						'time'       => Clock::iso( Clock::from_gmt( (string) $row['created_gmt'] ) ),
						'article'    => (int) $row['post_id'],
						'feature'    => (string) $row['feature'],
						'connection' => (string) $row['connection'],
						'decision'   => (string) $row['decision'],
						'reviewer'   => $reviewer > 0 ? $names[ $reviewer ] : '',
						'summary'    => (string) $row['summary'],
					);

					if ( $json ) {
						$write( ( $first ? '' : ',' ) . wp_json_encode( $entry ) );
					} else {
						$write( self::csv_line( array_values( $entry ) ) );
					}

					$first = false;
					++$rows;
					$last_id = max( $last_id, (int) $row['id'] );
				}

				$more = count( $page['rows'] ) >= self::PAGE;
			}
		}

		if ( $json ) {
			$write( '],"truncated":' . ( $truncated ? 'true' : 'false' ) . '}' );
		}

		return array(
			'rows'      => $rows,
			'sites'     => $sites,
			'truncated' => $truncated,
		);
	}

	/**
	 * One page of the current site's AI log. Call it inside `SiteLoop`.
	 *
	 * @param int $after_id Only entries with a larger ID.
	 * @return array{site: string, rows: array<int, array<string, mixed>>}
	 */
	private static function page( int $after_id ): array {
		global $wpdb;

		$table = Schema::table( 'ai_log' );
		$site  = trim( (string) get_option( 'blogname', '' ) );
		$none  = array(
			'site' => $site,
			'rows' => array(),
		);

		if ( ! Tables::exists( $table ) ) {
			return $none;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT id, post_id, feature, connection, decision, reviewer_id, summary, created_gmt FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, $after_id, self::PAGE ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'site' => $site,
			'rows' => is_array( $rows ) ? $rows : array(),
		);
	}

	/**
	 * One CSV line.
	 *
	 * @param array<int, mixed> $cells Values.
	 * @return string
	 */
	public static function csv_line( array $cells ): string {
		$out = array();

		foreach ( $cells as $cell ) {
			$text = is_scalar( $cell ) ? (string) $cell : '';

			if ( '' !== $text && false !== strpos( "=+-@\t\r", $text[0] ) && ! is_numeric( $text ) ) {
				$text = "'" . $text;
			}

			$out[] = '"' . str_replace( '"', '""', $text ) . '"';
		}

		return implode( ',', $out ) . "\r\n";
	}
}
