<?php
/**
 * Platform rule packs.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Rule packs are data files (JSON) in `rules/`, one per platform. They carry
 * limits and severities, not wording: the wording of every message lives in
 * code, where it can be translated.
 *
 * A pack looks like this:
 *
 *     {
 *       "platform": "google-news",
 *       "version": "2026.10.1",
 *       "read_on": "2026-10-20",
 *       "fetch": { "interval_hours": 24, "agents": [ "googlebot-news" ] },
 *       "limits": { "title_max": 110 },
 *       "rules": {
 *         "title.long": {
 *           "severity": "warn",
 *           "confidence": "confirmed",
 *           "source": "https://…",
 *           "read_on": "2026-10-20",
 *           "note": "…"
 *         }
 *       }
 *     }
 *
 * `confidence` is `confirmed` (read on the platform's own page), `softened`
 * (a source we could not confirm, so the copy is careful) or `unverified`.
 * A rule that has no entry falls back to the default the caller gives.
 *
 * Optional pack keys the Platform rules screen shows when present:
 * `source_title` (the name of the platform page the rules come from, such as
 * "Google Search Central: News sitemaps") and `source_url`.
 *
 * Limits the article checks read, by name (see docs/check-codes.md): `title_max`,
 * `title_min`, `image_min_width`, `image_min_height`, `image_ratio_min`,
 * `image_ratio_max` and `body_min_words`.
 *
 * Older bundled versions of a pack live in `rules/previous/<platform>/<version>.json`.
 * The version a person rolled back to is kept in the option `estasoft_nd_rule_packs`
 * and is read here instead of the newest one.
 *
 * The bundled packs always work offline. A newer pack downloaded to the
 * uploads folder is read only when the person turned updates on; it is not
 * built in version 1.0.
 */
final class Rules {

	public const OPTION = 'estasoft_nd_rule_packs';

	/**
	 * Packs read so far, by platform. Null means not read yet.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $packs = null;

	/**
	 * The newest bundled pack of each platform, before any rollback. Null means not read yet.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $newest = null;

	/**
	 * Folder the packs are read from.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Creates the loader.
	 *
	 * @param string|null $directory Folder of pack files. Defaults to the plugin's `rules/` folder.
	 */
	public function __construct( ?string $directory = null ) {
		$this->directory = null === $directory ? ESTASOFT_ND_PATH . 'rules/' : trailingslashit( $directory );
	}

	/**
	 * Every pack, keyed by platform id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null === $this->packs ) {
			$this->packs = array();
			$files       = glob( $this->directory . '*.json' );

			foreach ( is_array( $files ) ? $files : array() as $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in the plugin folder.
				$json = json_decode( (string) file_get_contents( $file ), true );

				if ( is_array( $json ) && ! empty( $json['platform'] ) ) {
					$this->packs[ (string) $json['platform'] ] = $json;
				}
			}

			$this->newest = $this->packs;

			$this->apply_rollbacks();

			/**
			 * Filters the rule packs read for this request, keyed by platform id.
			 *
			 * Internal: tests and the rule-pack screen use it. Other plugins should not rely on it.
			 *
			 * @param array<string, array<string, mixed>> $packs Packs by platform id.
			 */
			$packs       = apply_filters( 'estasoft_nd_internal_rule_packs', $this->packs );
			$this->packs = is_array( $packs ) ? $packs : array();
		}

		return $this->packs;
	}

	/**
	 * Forgets the packs read so far, so the next call reads the folder again.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->packs  = null;
		$this->newest = null;
	}

	/**
	 * The newest bundled pack of a platform, even when an older version is in use.
	 *
	 * @param string $platform Platform id.
	 * @return array<string, mixed> Empty when there is no pack.
	 */
	public function newest( string $platform ): array {
		$this->all();

		return $this->newest[ $platform ] ?? array();
	}

	/**
	 * Older bundled versions of a platform's pack, newest first.
	 *
	 * Each is a file `rules/previous/<platform>/<version>.json` whose platform and
	 * version match its name.
	 *
	 * @param string $platform Platform id.
	 * @return string[]
	 */
	public function older_versions( string $platform ): array {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $platform ) ) {
			return array();
		}

		$files    = glob( $this->directory . 'previous/' . $platform . '/*.json' );
		$versions = array();

		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$version = basename( $file, '.json' );

			if ( '' !== $this->previous_file( $platform, $version ) ) {
				$versions[] = $version;
			}
		}

		usort(
			$versions,
			static function ( string $a, string $b ): int {
				return version_compare( $b, $a );
			}
		);

		return $versions;
	}

	/**
	 * The folder the packs are read from.
	 *
	 * @return string
	 */
	public function directory(): string {
		return $this->directory;
	}

	/**
	 * Swaps in an older bundled version for each platform a person rolled back.
	 *
	 * @return void
	 */
	private function apply_rollbacks(): void {
		$stored  = get_option( self::OPTION, array() );
		$applied = is_array( $stored ) && isset( $stored['applied'] ) && is_array( $stored['applied'] ) ? $stored['applied'] : array();

		foreach ( $applied as $platform => $version ) {
			$platform = sanitize_key( (string) $platform );
			$version  = (string) $version;

			if ( ! isset( $this->packs[ $platform ] ) || (string) ( $this->packs[ $platform ]['version'] ?? '' ) === $version ) {
				continue;
			}

			$file = $this->previous_file( $platform, $version );

			if ( '' === $file ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file in the plugin folder.
			$json = json_decode( (string) file_get_contents( $file ), true );

			if ( is_array( $json ) && (string) ( $json['platform'] ?? '' ) === $platform && (string) ( $json['version'] ?? '' ) === $version ) {
				$this->packs[ $platform ] = $json;
			}
		}
	}

	/**
	 * The file of an older bundled version, or an empty string when there is none.
	 *
	 * @param string $platform Platform id.
	 * @param string $version  Pack version.
	 * @return string
	 */
	public function previous_file( string $platform, string $version ): string {
		if ( 1 !== preg_match( '/^[a-z0-9-]+$/', $platform ) || 1 !== preg_match( '/^[0-9A-Za-z.-]+$/', $version ) ) {
			return '';
		}

		$file = $this->directory . 'previous/' . $platform . '/' . $version . '.json';

		return is_readable( $file ) ? $file : '';
	}

	/**
	 * One platform's pack.
	 *
	 * @param string $platform Platform id.
	 * @return array<string, mixed> Empty when there is no pack.
	 */
	public function pack( string $platform ): array {
		return $this->all()[ $platform ] ?? array();
	}

	/**
	 * A numeric or text limit from a pack.
	 *
	 * @param string $platform Platform id.
	 * @param string $name     Limit name, such as "title_max".
	 * @param mixed  $fallback  Value when the pack has none.
	 * @return mixed
	 */
	public function limit( string $platform, string $name, $fallback = null ) {
		$limits = $this->pack( $platform )['limits'] ?? array();

		return is_array( $limits ) && array_key_exists( $name, $limits ) ? $limits[ $name ] : $fallback;
	}

	/**
	 * The severity a platform gives a finding: block, warn or info.
	 *
	 * @param string $platform Platform id.
	 * @param string $code     Finding code, such as "image.missing".
	 * @param string $fallback  Severity when the pack has no entry.
	 * @return string
	 */
	public function severity( string $platform, string $code, string $fallback = Status::WARN ): string {
		$rule = $this->rule( $platform, $code );
		$sev  = (string) ( $rule['severity'] ?? $fallback );

		return in_array( $sev, array( Status::BLOCK, Status::WARN, Status::INFO ), true ) ? $sev : $fallback;
	}

	/**
	 * One rule's entry.
	 *
	 * @param string $platform Platform id.
	 * @param string $code     Finding code.
	 * @return array<string, mixed> Empty when the pack has no such rule.
	 */
	public function rule( string $platform, string $code ): array {
		$rules = $this->pack( $platform )['rules'] ?? array();
		$rule  = is_array( $rules ) ? ( $rules[ $code ] ?? array() ) : array();

		return is_array( $rule ) ? $rule : array();
	}

	/**
	 * How often the platform usually fetches, in hours. Used to say "later than usual".
	 *
	 * @param string $platform Platform id.
	 * @param int    $fallback  Hours when the pack has none.
	 * @return int
	 */
	public function fetch_interval_hours( string $platform, int $fallback = 24 ): int {
		$fetch = $this->pack( $platform )['fetch'] ?? array();

		return max( 1, (int) ( is_array( $fetch ) && isset( $fetch['interval_hours'] ) ? $fetch['interval_hours'] : $fallback ) );
	}

	/**
	 * The version of a pack, or an empty string.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	public function version( string $platform ): string {
		return (string) ( $this->pack( $platform )['version'] ?? '' );
	}
}
