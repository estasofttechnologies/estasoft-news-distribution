<?php
/**
 * Site verification: the tags and files search engines ask for.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Verification;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\SearchSitemap\SitemapProbe;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Search engines ask a site owner to prove the site is theirs, with a meta tag in
 * the home page or a small file in the site root. This channel keeps what the
 * person pasted for each engine, and `VerificationModule` prints the tag and
 * answers the file's address. No file is ever written to the site root.
 *
 * "Save and check" asks the site's own address, the way a visitor would, and
 * reports what it saw. It cannot see whether an engine accepted the proof, so
 * "I verified" is ticked by the person.
 *
 * What is kept lives in the option `estasoft_nd_verification`, one record per
 * engine, not in the channel settings: a file's contents are markup, which the
 * settings validator would strip.
 */
final class VerificationChannel extends AbstractChannel {

	public const OPTION = 'estasoft_nd_verification';

	public const METHODS = array( 'none', 'meta', 'file', 'dns' );

	/**
	 * The engines, in the order the page lists them.
	 *
	 * @return array<string, array{name: string, meta: string, file_pattern: string, file_hint: string, handles: string, open_url: string, dns_label: string, dns_help: string, file_label: string}>
	 */
	public static function engines(): array {
		return array(
			'google' => array(
				'name'         => __( 'Google Search Console', 'estasoft-news-distribution' ),
				'meta'         => 'google-site-verification',
				'file_pattern' => '/^google[A-Za-z0-9]{8,64}\.html$/',
				'file_hint'    => __( 'Starts with google and ends in .html. Letters and digits only.', 'estasoft-news-distribution' ),
				'handles'      => __( 'Meta tag or HTML file. A DNS record is yours to add.', 'estasoft-news-distribution' ),
				'open_url'     => 'https://search.google.com/search-console',
				'dns_label'    => __( 'DNS record', 'estasoft-news-distribution' ),
				'dns_help'     => __( 'You add a TXT record at your domain host.', 'estasoft-news-distribution' ),
				'file_label'   => __( 'HTML file', 'estasoft-news-distribution' ),
			),
			'bing'   => array(
				'name'         => __( 'Bing Webmaster Tools', 'estasoft-news-distribution' ),
				'meta'         => 'msvalidate.01',
				'file_pattern' => '/^BingSiteAuth\.xml$/',
				'file_hint'    => __( 'The file name is BingSiteAuth.xml.', 'estasoft-news-distribution' ),
				'handles'      => __( 'Meta tag or XML file. A CNAME record is yours to add.', 'estasoft-news-distribution' ),
				'open_url'     => 'https://www.bing.com/webmasters',
				'dns_label'    => __( 'CNAME record', 'estasoft-news-distribution' ),
				'dns_help'     => __( 'You add a CNAME record at your domain host.', 'estasoft-news-distribution' ),
				'file_label'   => __( 'XML file', 'estasoft-news-distribution' ),
			),
			'yandex' => array(
				'name'         => __( 'Yandex Webmaster', 'estasoft-news-distribution' ),
				'meta'         => 'yandex-verification',
				'file_pattern' => '/^yandex_[A-Za-z0-9_]+\.html$/',
				'file_hint'    => __( 'Starts with yandex_ and ends in .html. Letters, digits and underscores only.', 'estasoft-news-distribution' ),
				'handles'      => __( 'Meta tag or HTML file. A DNS record is yours to add.', 'estasoft-news-distribution' ),
				'open_url'     => 'https://webmaster.yandex.com',
				'dns_label'    => __( 'DNS record', 'estasoft-news-distribution' ),
				'dns_help'     => __( 'You add a TXT record at your domain host.', 'estasoft-news-distribution' ),
				'file_label'   => __( 'HTML file', 'estasoft-news-distribution' ),
			),
			'baidu'  => array(
				'name'         => __( 'Baidu Webmaster Tools', 'estasoft-news-distribution' ),
				'meta'         => 'baidu-site-verification',
				'file_pattern' => '/^[A-Za-z0-9_-]{8,128}\.(?:html|txt)$/',
				'file_hint'    => __( 'Letters, digits, hyphens and underscores, ending in .html or .txt.', 'estasoft-news-distribution' ),
				'handles'      => __( 'Meta tag or file. Baidu may also ask for a DNS record, which is yours to add.', 'estasoft-news-distribution' ),
				'open_url'     => 'https://ziyuan.baidu.com',
				'dns_label'    => __( 'DNS record', 'estasoft-news-distribution' ),
				'dns_help'     => __( 'You add the record at your domain host.', 'estasoft-news-distribution' ),
				'file_label'   => __( 'File', 'estasoft-news-distribution' ),
			),
		);
	}

	public function id(): string {
		return 'verification';
	}

	public function label(): string {
		return __( 'Site verification', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::SITEMAP;
	}

	public function order(): int {
		return 90;
	}

	public function mode(): string {
		return self::MODE_MANUAL;
	}

	public function feed_slug(): string {
		return '';
	}

	/**
	 * Verification has no switch: the page is always there.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}

	/**
	 * A manual channel builds nothing.
	 *
	 * @param \Estasoft\NewsDistribution\Core\Article[] $articles Unused.
	 * @param string                                    $language Unused.
	 * @param int|null                                  $now      Unused.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		unset( $articles, $language, $now );

		return '';
	}

	public function health(): string {
		return Status::HEALTHY;
	}

	/**
	 * Site verification is not a row on the Channels hub; it has its own button there.
	 *
	 * @return array<int, array{key: string, name: string, summary: string, status: string, enabled: bool|null, action: string}>
	 */
	public function hub_rows(): array {
		return array();
	}

	/**
	 * What is kept for one engine, with defaults.
	 *
	 * @param string $engine Engine id such as "yandex".
	 * @return array{method: string, code: string, file_name: string, file_body: string, marked: bool, marked_on: string, check: array{state: string, code: int, checked_ts: int, method: string}}
	 */
	public function record( string $engine ): array {
		$all = get_option( self::OPTION, array() );
		$row = is_array( $all ) && isset( $all[ $engine ] ) && is_array( $all[ $engine ] ) ? $all[ $engine ] : array();
		$chk = isset( $row['check'] ) && is_array( $row['check'] ) ? $row['check'] : array();

		return array(
			'method'    => in_array( (string) ( $row['method'] ?? '' ), self::METHODS, true ) ? (string) $row['method'] : 'none',
			'code'      => (string) ( $row['code'] ?? '' ),
			'file_name' => (string) ( $row['file_name'] ?? '' ),
			'file_body' => (string) ( $row['file_body'] ?? '' ),
			'marked'    => ! empty( $row['marked'] ),
			'marked_on' => (string) ( $row['marked_on'] ?? '' ),
			'check'     => array(
				'state'      => (string) ( $chk['state'] ?? '' ),
				'code'       => (int) ( $chk['code'] ?? 0 ),
				'checked_ts' => (int) ( $chk['checked_ts'] ?? 0 ),
				'method'     => (string) ( $chk['method'] ?? '' ),
			),
		);
	}

	/**
	 * Validates and keeps what the person entered for one engine.
	 *
	 * Input keys: `method`, `code`, `file_name`, `file_body`, `marked`. A key that
	 * is missing keeps its value. Nothing is kept when any value is rejected.
	 *
	 * @param string               $engine Engine id.
	 * @param array<string, mixed> $input  Unslashed input.
	 * @return array<string, mixed>|\WP_Error The kept record, or an error that names the fields.
	 */
	public function save_engine( string $engine, array $input ) {
		$engines = self::engines();

		if ( ! isset( $engines[ $engine ] ) ) {
			return new \WP_Error( 'estasoft_nd_unknown_engine', __( 'That search engine is not on this page.', 'estasoft-news-distribution' ), array( 'status' => 404 ) );
		}

		$info   = $engines[ $engine ];
		$record = $this->record( $engine );
		$errors = array();

		if ( array_key_exists( 'method', $input ) ) {
			$method = sanitize_key( (string) $input['method'] );

			if ( ! in_array( $method, self::METHODS, true ) ) {
				$errors['method'] = __( 'Choose one of the listed methods.', 'estasoft-news-distribution' );
			} else {
				$record['method'] = $method;
			}
		}

		if ( array_key_exists( 'code', $input ) ) {
			$code = self::meta_code( (string) $input['code'] );

			if ( '' !== $code && 1 !== preg_match( '/^[A-Za-z0-9._:=+\/-]{4,200}$/', $code ) ) {
				$errors['code'] = __( 'The code can only hold letters, digits and . _ : = + / - characters. Paste just the code, or the whole meta tag.', 'estasoft-news-distribution' );
			} else {
				$record['code'] = $code;
			}
		}

		if ( array_key_exists( 'file_name', $input ) ) {
			$name = trim( sanitize_text_field( (string) $input['file_name'] ) );

			if ( '' !== $name && 1 !== preg_match( $info['file_pattern'], $name ) ) {
				$errors['file_name'] = $info['file_hint'];
			} else {
				$record['file_name'] = $name;
			}
		}

		if ( array_key_exists( 'file_body', $input ) ) {
			$record['file_body'] = self::clean_body( (string) $input['file_body'], $record['file_name'] );
		}

		if ( 'meta' === $record['method'] && '' === $record['code'] && ! isset( $errors['code'] ) ) {
			$errors['code'] = __( 'Paste the code the search engine gave you.', 'estasoft-news-distribution' );
		}

		if ( 'file' === $record['method'] ) {
			if ( '' === $record['file_name'] && ! isset( $errors['file_name'] ) ) {
				$errors['file_name'] = __( 'Enter the file name the search engine gave you.', 'estasoft-news-distribution' );
			}

			if ( '' !== $record['file_name'] && ! isset( $errors['file_name'] ) ) {
				$body_error = self::body_error( $engine, $record['file_name'], $record['file_body'] );

				if ( '' !== $body_error ) {
					$errors['file_body'] = $body_error;
				}
			}
		}

		if ( array_key_exists( 'marked', $input ) ) {
			$on = (bool) filter_var( $input['marked'], FILTER_VALIDATE_BOOLEAN );

			if ( $on && ! $record['marked'] ) {
				$record['marked_on'] = Clock::format( Clock::now(), 'Y-m-d' );
			}

			if ( ! $on ) {
				$record['marked_on'] = '';
			}

			$record['marked'] = $on;
		}

		if ( array() !== $errors ) {
			return new \WP_Error(
				'estasoft_nd_invalid_value',
				(string) reset( $errors ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}

		$this->write( $engine, $record );

		return $record;
	}

	/**
	 * Asks the site's own address, the way a visitor would, and keeps what it saw.
	 *
	 * Meta tag: reads the home page and looks for the tag. File: reads the file's
	 * address and compares its contents. A DNS record cannot be seen from here.
	 * Tests replace the request with the `pre_http_request` filter.
	 *
	 * @param string $engine Engine id.
	 * @return array{state: string, code: int, checked_ts: int, method: string}
	 */
	public function check( string $engine ): array {
		$record = $this->record( $engine );
		$result = array(
			'state'      => 'none',
			'code'       => 0,
			'checked_ts' => Clock::now(),
			'method'     => $record['method'],
		);

		if ( 'meta' === $record['method'] && '' !== $record['code'] ) {
			$result = array_merge( $result, $this->check_meta( $engine, $record['code'] ) );
		} elseif ( 'file' === $record['method'] && '' !== $record['file_name'] ) {
			$result = array_merge(
				$result,
				SitemapProbe::plain_permalinks()
					? array(
						'state' => 'plain',
						'code'  => 0,
					)
					: $this->check_file( $record['file_name'], $this->file_contents( $engine, $record ) )
			);
		}

		$stored            = get_option( self::OPTION, array() );
		$stored            = is_array( $stored ) ? $stored : array();
		$row               = isset( $stored[ $engine ] ) && is_array( $stored[ $engine ] ) ? $stored[ $engine ] : $this->raw( $engine );
		$row['check']      = $result;
		$stored[ $engine ] = $row;

		update_option( self::OPTION, $stored, false );

		return $result;
	}

	/**
	 * The meta tags to print in the home page head: name => content.
	 *
	 * @return array<string, string>
	 */
	public function meta_tags(): array {
		$tags = array();

		foreach ( self::engines() as $engine => $info ) {
			$record = $this->record( $engine );

			if ( 'meta' === $record['method'] && '' !== $record['code'] ) {
				$tags[ $info['meta'] ] = $record['code'];
			}
		}

		return $tags;
	}

	/**
	 * The file the site answers at a root address, or null when none is set up.
	 *
	 * @param string $name File name from the address, such as "yandex_a1b2.html".
	 * @return array{body: string, type: string}|null
	 */
	public function file( string $name ): ?array {
		foreach ( array_keys( self::engines() ) as $engine ) {
			$record = $this->record( $engine );

			if ( 'file' !== $record['method'] || '' === $record['file_name'] || $record['file_name'] !== $name ) {
				continue;
			}

			return array(
				'body' => $this->file_contents( $engine, $record ),
				'type' => self::content_type_of( $name ),
			);
		}

		return null;
	}

	/**
	 * The file names this channel answers at the site root.
	 *
	 * @return string[]
	 */
	public function file_names(): array {
		$names = array();

		foreach ( array_keys( self::engines() ) as $engine ) {
			$record = $this->record( $engine );

			if ( 'file' === $record['method'] && '' !== $record['file_name'] ) {
				$names[] = $record['file_name'];
			}
		}

		return $names;
	}

	/**
	 * The address a file is served at.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function file_url( string $name ): string {
		return home_url( '/' . rawurlencode( $name ) );
	}

	/**
	 * The media type for a file name.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function content_type_of( string $name ): string {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'xml' === $ext ) {
			return 'application/xml; charset=UTF-8';
		}

		return 'txt' === $ext ? 'text/plain; charset=UTF-8' : 'text/html; charset=UTF-8';
	}

	/**
	 * The code from what was pasted: a bare code, or the content of a whole meta tag.
	 *
	 * @param string $pasted What the person pasted.
	 * @return string
	 */
	public static function meta_code( string $pasted ): string {
		$pasted = trim( $pasted );

		if ( 1 === preg_match( '/content\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $pasted, $found ) ) {
			return trim( '' !== $found[1] ? $found[1] : ( $found[2] ?? '' ) );
		}

		return sanitize_text_field( $pasted );
	}

	/**
	 * Keeps only what a verification file needs. An HTML file keeps a small set of
	 * tags and nothing that runs; an XML or text file keeps its text.
	 *
	 * @param string $body Pasted contents.
	 * @param string $name File name, to know the kind of file.
	 * @return string
	 */
	public static function clean_body( string $body, string $name ): string {
		$body = str_replace( array( "\r\n", "\r" ), "\n", trim( $body ) );

		if ( 'html' === strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) || '' === $name ) {
			return trim( wp_kses( $body, self::body_tags() ) );
		}

		return $body;
	}

	/**
	 * The tags a verification file or a pasted code may hold: a page with a meta tag, or Bing's users and user.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function body_tags(): array {
		return array(
			'html'  => array(),
			'head'  => array(),
			'title' => array(),
			'body'  => array(),
			'users' => array(),
			'user'  => array(),
			'meta'  => array(
				'charset'    => true,
				'http-equiv' => true,
				'content'    => true,
				'name'       => true,
			),
		);
	}

	/**
	 * What is wrong with a file's contents for an engine, or an empty string.
	 *
	 * @param string $engine Engine id.
	 * @param string $name   File name.
	 * @param string $body   Cleaned contents.
	 * @return string
	 */
	public static function body_error( string $engine, string $name, string $body ): string {
		if ( 'google' === $engine ) {
			return '' === $body || false !== stripos( $body, 'google-site-verification' ) ? '' : __( 'Google’s file says “google-site-verification:” followed by the file name. Leave this empty to let News Distribution write it.', 'estasoft-news-distribution' );
		}

		if ( '' === $body ) {
			return __( 'Paste the contents the search engine shows you.', 'estasoft-news-distribution' );
		}

		if ( 'yandex' === $engine ) {
			if ( 1 !== preg_match( '/Verification:\s*([A-Za-z0-9]+)/', $body, $found ) ) {
				return __( 'The contents must include the code after “Verification:”.', 'estasoft-news-distribution' );
			}

			if ( 1 === preg_match( '/^yandex_([A-Za-z0-9]+)\.html$/', $name, $from_name ) && $from_name[1] !== $found[1] ) {
				return __( 'The code in the contents is not the code in the file name. Paste what Yandex shows.', 'estasoft-news-distribution' );
			}
		}

		if ( 'bing' === $engine ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );

			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( false === $xml || 'users' !== $xml->getName() || '' === trim( (string) $xml->user ) ) {
				return __( 'The contents must be Bing’s XML: a users element with the code inside a user element.', 'estasoft-news-distribution' );
			}
		}

		return '';
	}

	/**
	 * What the site must answer for an engine's file.
	 *
	 * @param string               $engine Engine id.
	 * @param array<string, mixed> $record The engine's record.
	 * @return string
	 */
	private function file_contents( string $engine, array $record ): string {
		$body = (string) $record['file_body'];

		if ( 'google' === $engine && '' === $body ) {
			return 'google-site-verification: ' . (string) $record['file_name'];
		}

		return $body;
	}

	/**
	 * Looks for the meta tag in the home page.
	 *
	 * @param string $engine Engine id.
	 * @param string $code   The code that must be in the tag.
	 * @return array{state: string, code: int}
	 */
	private function check_meta( string $engine, string $code ): array {
		$name     = self::engines()[ $engine ]['meta'];
		$response = $this->get( add_query_arg( 'estasoft_nd_check', (string) Clock::now(), home_url( '/' ) ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'state' => 'failed',
				'code'  => 0,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return array(
				'state' => 404 === $status ? 'not_found' : 'error',
				'code'  => $status,
			);
		}

		$html  = (string) wp_remote_retrieve_body( $response );
		$found = 1 === preg_match( '/<meta\b[^>]*\bname\s*=\s*["\']' . preg_quote( $name, '/' ) . '["\'][^>]*\bcontent\s*=\s*["\']' . preg_quote( $code, '/' ) . '["\']/i', $html )
			|| 1 === preg_match( '/<meta\b[^>]*\bcontent\s*=\s*["\']' . preg_quote( $code, '/' ) . '["\'][^>]*\bname\s*=\s*["\']' . preg_quote( $name, '/' ) . '["\']/i', $html );

		return array(
			'state' => $found ? 'live' : 'no_tag',
			'code'  => 200,
		);
	}

	/**
	 * Reads the file's address and compares what comes back.
	 *
	 * @param string $name     File name.
	 * @param string $expected The contents it must have.
	 * @return array{state: string, code: int}
	 */
	private function check_file( string $name, string $expected ): array {
		$response = $this->get( self::file_url( $name ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'state' => 'failed',
				'code'  => 0,
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			return array(
				'state' => 404 === $status ? 'not_found' : 'error',
				'code'  => $status,
			);
		}

		$normalise = static function ( string $text ): string {
			return trim( str_replace( array( "\r\n", "\r" ), "\n", $text ) );
		};

		return array(
			'state' => $normalise( (string) wp_remote_retrieve_body( $response ) ) === $normalise( $expected ) ? 'live' : 'differs',
			'code'  => 200,
		);
	}

	/**
	 * A same-site request.
	 *
	 * @param string $url The site's own address.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function get( string $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter for requests to the site itself.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
	}

	/**
	 * The stored row of an engine as it is, or an empty array.
	 *
	 * @param string $engine Engine id.
	 * @return array<string, mixed>
	 */
	private function raw( string $engine ): array {
		$record = $this->record( $engine );
		unset( $record['check'] );

		return $record;
	}

	/**
	 * Keeps an engine's record and forgets the last check, which no longer applies.
	 *
	 * @param string               $engine Engine id.
	 * @param array<string, mixed> $record The record.
	 * @return void
	 */
	private function write( string $engine, array $record ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$before = $this->record( $engine );

		unset( $record['check'] );

		$same_proof = $before['method'] === $record['method'] && $before['code'] === $record['code'] && $before['file_name'] === $record['file_name'] && $before['file_body'] === $record['file_body'];

		if ( $same_proof && isset( $stored[ $engine ]['check'] ) ) {
			$record['check'] = $stored[ $engine ]['check'];
		}

		$stored[ $engine ] = $record;

		update_option( self::OPTION, $stored, false );
	}
}
