<?php
/**
 * The installed rule packs, as the Platform rules screen and /rules show them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the bundled packs with their version, the date their sources were read and
 * how sure each rule is, and lets an administrator choose an older bundled version.
 *
 * Version 1.0 downloads nothing: there are only the packs that ship with the plugin
 * (`rules/<name>.json`, and `rules/previous/<platform>/<version>.json` for older ones).
 * A plugin update brings newer ones. Rolling back means choosing one of the older
 * bundled versions; with none, it is not offered.
 *
 * Get it with `Plugin::instance()->make( RulePacks::class )`.
 *
 * One pack looks like this:
 *
 *     array(
 *       'platform'    => 'google-news',
 *       'label'       => 'Google News',
 *       'version'     => '2026.09.2',   // the one in use
 *       'newest'      => '2026.09.2',   // the newest bundled
 *       'rolled_back' => false,
 *       'older'       => array( '2026.09.1' ),
 *       'read_on'     => '2026-10-21',  // or null
 *       'source'      => 'Google Search Central: News sitemaps',
 *       'rules'       => 15,
 *       'counts'      => array( 'confirmed' => 3, 'softened' => 2, 'unverified' => 10 ),
 *     )
 */
final class RulePacks {

	private const PACK_ARTICLE_CHECKS = 'article-checks';

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Every pack, in alphabetical order of its label.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$list = array();

		foreach ( array_keys( $this->plugin->rules()->all() ) as $platform ) {
			$list[] = $this->describe( (string) $platform );
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return strnatcasecmp( (string) $a['label'], (string) $b['label'] );
			}
		);

		return $list;
	}

	/**
	 * One pack, or an empty array when there is none.
	 *
	 * @param string $platform Platform id.
	 * @return array<string, mixed>
	 */
	public function describe( string $platform ): array {
		$rules = $this->plugin->rules();
		$pack  = $rules->pack( $platform );

		if ( array() === $pack ) {
			return array();
		}

		$newest = (string) ( $rules->newest( $platform )['version'] ?? ( $pack['version'] ?? '' ) );
		$counts = array(
			'confirmed'  => 0,
			'softened'   => 0,
			'unverified' => 0,
		);
		$list   = is_array( $pack['rules'] ?? null ) ? $pack['rules'] : array();

		foreach ( $list as $rule ) {
			$confidence = is_array( $rule ) ? (string) ( $rule['confidence'] ?? 'unverified' ) : 'unverified';
			$confidence = isset( $counts[ $confidence ] ) ? $confidence : 'unverified';
			++$counts[ $confidence ];
		}

		return array(
			'platform'    => $platform,
			'label'       => $this->label( $platform ),
			'version'     => (string) ( $pack['version'] ?? '' ),
			'newest'      => $newest,
			'rolled_back' => (string) ( $pack['version'] ?? '' ) !== $newest,
			'older'       => $rules->older_versions( $platform ),
			'read_on'     => isset( $pack['read_on'] ) && is_string( $pack['read_on'] ) && '' !== $pack['read_on'] ? $pack['read_on'] : null,
			'source'      => $this->source( $pack ),
			'rules'       => count( $list ),
			'counts'      => $counts,
		);
	}

	/**
	 * The rules of one pack, for the screen that lists them.
	 *
	 * @param string $platform Platform id.
	 * @return array<int, array{code: string, severity: string, confidence: string, source: string, read_on: string|null, note: string}>
	 */
	public function rules_of( string $platform ): array {
		$pack = $this->plugin->rules()->pack( $platform );
		$out  = array();

		foreach ( is_array( $pack['rules'] ?? null ) ? $pack['rules'] : array() as $code => $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}

			$out[] = array(
				'code'       => (string) $code,
				'severity'   => (string) ( $rule['severity'] ?? Status::INFO ),
				'confidence' => (string) ( $rule['confidence'] ?? 'unverified' ),
				'source'     => (string) ( $rule['source'] ?? '' ),
				'read_on'    => isset( $rule['read_on'] ) && is_string( $rule['read_on'] ) && '' !== $rule['read_on'] ? $rule['read_on'] : null,
				'note'       => (string) ( $rule['note'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Uses one bundled version of a platform's pack. An empty version means the newest.
	 *
	 * @param string $platform Platform id.
	 * @param string $version  An older bundled version, or an empty string for the newest.
	 * @return array<string, mixed>|\WP_Error The pack as it is described now.
	 */
	public function use_version( string $platform, string $version = '' ) {
		$rules = $this->plugin->rules();

		if ( array() === $rules->pack( $platform ) ) {
			return new \WP_Error(
				'estasoft_nd_unknown_pack',
				__( 'There is no rule pack for that platform.', 'estasoft-news-distribution' ),
				array( 'status' => 404 )
			);
		}

		$newest = (string) ( $rules->newest( $platform )['version'] ?? '' );
		$stored = get_option( Rules::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$set    = isset( $stored['applied'] ) && is_array( $stored['applied'] ) ? $stored['applied'] : array();

		if ( '' === $version || $version === $newest ) {
			unset( $set[ $platform ] );
		} else {
			$file = $rules->previous_file( $platform, $version );
			$json = '' === $file ? null : json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in the plugin folder.

			if ( ! is_array( $json ) || (string) ( $json['platform'] ?? '' ) !== $platform || (string) ( $json['version'] ?? '' ) !== $version ) {
				return new \WP_Error(
					'estasoft_nd_pack_version_missing',
					__( 'That version is not included with this version of News Distribution, so it cannot be chosen.', 'estasoft-news-distribution' ),
					array( 'status' => 400 )
				);
			}

			$set[ $platform ] = $version;
		}

		$stored['applied'] = $set;

		update_option( Rules::OPTION, $stored, false );
		$rules->flush();

		$this->plugin->audit()->record(
			Audit::SETTINGS,
			0,
			sprintf(
				/* translators: 1: a platform id such as google-news, 2: a rule pack version such as 2026.09.2. */
				__( 'Platform rules: %1$s now uses pack %2$s.', 'estasoft-news-distribution' ),
				$platform,
				'' === $version ? $newest : $version
			)
		);

		return $this->describe( $platform );
	}

	/**
	 * A platform's name as a person reads it: the channel's label when there is one.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	public function label( string $platform ): string {
		$pack = $this->plugin->rules()->pack( $platform );

		if ( isset( $pack['label'] ) && is_string( $pack['label'] ) && '' !== $pack['label'] ) {
			return $pack['label'];
		}

		$channel = $this->plugin->registry()->get( $platform );

		if ( null !== $channel ) {
			return $channel->label();
		}

		if ( self::PACK_ARTICLE_CHECKS === $platform ) {
			return __( 'Article checks', 'estasoft-news-distribution' );
		}

		return ucwords( str_replace( '-', ' ', $platform ) );
	}

	/**
	 * Where a pack's rules come from, in words: its own `source_title`, else the short
	 * names of its rule sources (the site of an address, or the words before the first
	 * colon, bracket or comma), the first three and a count of the rest.
	 *
	 * @param array<string, mixed> $pack The pack.
	 * @return string
	 */
	private function source( array $pack ): string {
		if ( isset( $pack['source_title'] ) && is_string( $pack['source_title'] ) && '' !== $pack['source_title'] ) {
			return $pack['source_title'];
		}

		$names = array();

		foreach ( is_array( $pack['rules'] ?? null ) ? $pack['rules'] : array() as $rule ) {
			$source = is_array( $rule ) ? trim( (string) ( $rule['source'] ?? '' ) ) : '';

			if ( '' === $source ) {
				continue;
			}

			$host = wp_parse_url( $source, PHP_URL_HOST );

			if ( is_string( $host ) && '' !== $host ) {
				$name = (string) preg_replace( '/^www\./', '', $host );
			} else {
				$parts = preg_split( '/[:(,]/', $source );
				$name  = trim( is_array( $parts ) ? (string) $parts[0] : $source );
				$name  = mb_strlen( $name ) > 40 ? mb_substr( $name, 0, 39 ) . '…' : $name;
			}

			if ( '' !== $name && 0 !== strcasecmp( $name, 'News Distribution' ) ) {
				$names[ $name ] = true;
			}
		}

		$names = array_map( 'strval', array_keys( $names ) );

		if ( array() === $names ) {
			return 'News Distribution';
		}

		$text = implode( ', ', array_slice( $names, 0, 3 ) );
		$more = count( $names ) - 3;

		if ( $more > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of further sources. */
				_n( 'and %s more', 'and %s more', $more, 'estasoft-news-distribution' ),
				number_format_i18n( $more )
			);
		}

		return $text;
	}
}
