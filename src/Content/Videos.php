<?php
/**
 * The videos of an article and what is known about each, for the markup, the check and the editor.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Markup\VideoFinder;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Puts together, for each video found in an article, the details Google asks for: a name, a thumbnail
 * and an upload date, and the ones it recommends: a description and a duration.
 *
 * Where each detail comes from, strongest first:
 *
 * 1. What an editor typed for this video (`_estasoft_nd_video`, saved with the article).
 * 2. What the video site answered, when "Look up details" is on (`_estasoft_nd_video_lookup`, saved
 *    with the article so a video is asked about once).
 * 3. What the page and the library already know, with no request: the title WordPress cached from the
 *    video site's own embed code or that an iframe carries, the picture YouTube keeps at a fixed address,
 *    and, for a video the site hosts, the media library item (title, description, upload date, length)
 *    and the poster of the video element.
 *
 * The article's own date is never used for a video's upload date: it would tell search the video is
 * newer than it is. A video with no upload date is left out of the markup and flagged.
 */
final class Videos {

	public const OVERRIDES = '_estasoft_nd_video';
	public const LOOKUPS   = '_estasoft_nd_video_lookup';
	public const HOOK      = 'estasoft_nd_video_lookup';

	/**
	 * The details Google asks for. A video that lacks one is left out of the markup.
	 */
	public const REQUIRED = array( 'name', 'thumbnail', 'upload_date' );

	/**
	 * How long before an answer that failed (not "private or removed") may be asked for again.
	 */
	public const RETRY_AFTER = HOUR_IN_SECONDS;

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
	 * The Video section's values.
	 *
	 * @return array<string, mixed>
	 */
	public function settings(): array {
		return $this->plugin->settings()->section( 'video' );
	}

	/**
	 * Whether the markup is on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return ! empty( $this->settings()['enabled'] );
	}

	/**
	 * Whether "Look up details" is on. It serves the markup, so it does nothing while the markup is off.
	 *
	 * @return bool
	 */
	public function lookup_on(): bool {
		return $this->enabled() && ! empty( $this->settings()['lookup'] );
	}

	/**
	 * Whether videos of a site get markup.
	 *
	 * @param string $site youtube, vimeo or own.
	 * @return bool
	 */
	public function site_on( string $site ): bool {
		return in_array( $site, array( VideoFinder::YOUTUBE, VideoFinder::VIMEO, VideoFinder::OWN ), true ) && ! empty( $this->settings()[ $site ] );
	}

	/* ---------- Cleaning ---------- */

	/**
	 * An upload date as it is stored and printed.
	 *
	 * A plain date stays a plain date. A date with a time and a zone (or Z) is kept with its zone. A time with
	 * no zone is cut back to its date, because the zone is not known and a made-up one would be wrong. A
	 * date in the future, or before 1990, is not accepted.
	 *
	 * @param mixed $value What was given.
	 * @return string An ISO 8601 date or date and time, or an empty string.
	 */
	public static function clean_date( $value ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		$zone = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/', $value );
		$date = 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2})?(?:\.\d+)?)?$/', $value, $found );

		if ( $zone ) {
			$moment = date_create_immutable( $value );

			if ( false === $moment ) {
				return '';
			}

			$stamp = $moment->getTimestamp();
			$clean = $moment->format( 'c' );
		} elseif ( $date && checkdate( (int) $found[2], (int) $found[3], (int) $found[1] ) ) {
			$clean = $found[1] . '-' . $found[2] . '-' . $found[3];
			$stamp = (int) strtotime( $clean . ' 00:00:00 UTC' );
		} else {
			return '';
		}

		if ( $stamp > Clock::now() + DAY_IN_SECONDS || $stamp < (int) strtotime( '1990-01-01 00:00:00 UTC' ) ) {
			return '';
		}

		return $clean;
	}

	/**
	 * A length as seconds.
	 *
	 * @param mixed $value Seconds, "2:14", "1:02:14" or an ISO 8601 length such as "PT2M14S".
	 * @return int Zero when it is not a length.
	 */
	public static function clean_duration( $value ): int {
		$seconds = 0;

		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d+(?:\.\d+)?$/', trim( $value ) ) ) ) {
			$seconds = (int) round( (float) $value );
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*(?:(\d{1,3}):)?(\d{1,2}):(\d{2})\s*$/', $value, $found ) ) {
			$seconds = (int) $found[1] * 3600 + (int) $found[2] * 60 + (int) $found[3];
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?\s*$/i', $value, $found ) ) {
			$seconds = (int) ( $found[1] ?? 0 ) * 3600 + (int) ( $found[2] ?? 0 ) * 60 + (int) round( (float) ( $found[3] ?? 0 ) );
		}

		return $seconds > 0 && $seconds <= 360000 ? $seconds : 0;
	}

	/**
	 * A length in seconds as ISO 8601.
	 *
	 * @param int $seconds Seconds.
	 * @return string Such as "PT2M14S", or an empty string for no length.
	 */
	public static function iso_duration( int $seconds ): string {
		if ( $seconds < 1 ) {
			return '';
		}

		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$rest    = $seconds % 60;

		return 'PT' . ( $hours > 0 ? $hours . 'H' : '' ) . ( $minutes > 0 ? $minutes . 'M' : '' ) . ( $rest > 0 || ( 0 === $hours && 0 === $minutes ) ? $rest . 'S' : '' );
	}

	/**
	 * Whether a key names a video this feature knows how to describe.
	 *
	 * @param string $key A key such as youtube:dQw4w9WgXcQ.
	 * @return bool
	 */
	public static function valid_key( string $key ): bool {
		return 1 === preg_match( '/^(?:youtube:[A-Za-z0-9_-]{11}|vimeo:\d{4,12}|own:[0-9a-f]{12})$/', $key );
	}

	/**
	 * Cleans the details an editor typed, on their way to storage.
	 *
	 * The result is a list with one entry for each video, every field present. A field left empty means "nothing
	 * typed", so the lookup or the page answers for it. An entry with nothing typed that does not leave the video out is dropped.
	 *
	 * @param mixed $value What was given.
	 * @return array<int, array{key: string, name: string, description: string, upload_date: string, duration: int, thumbnail: string, skip: bool}>
	 */
	public static function clean_overrides( $value ): array {
		$out  = array();
		$seen = array();

		foreach ( is_array( $value ) ? $value : array() as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['key'] ) || ! is_string( $row['key'] ) || ! self::valid_key( $row['key'] ) || isset( $seen[ $row['key'] ] ) ) {
				continue;
			}

			$thumbnail = isset( $row['thumbnail'] ) && is_scalar( $row['thumbnail'] ) ? trim( (string) $row['thumbnail'] ) : '';
			$entry     = array(
				'key'         => $row['key'],
				'name'        => isset( $row['name'] ) && is_scalar( $row['name'] ) ? mb_substr( sanitize_text_field( (string) $row['name'] ), 0, 200 ) : '',
				'description' => isset( $row['description'] ) && is_scalar( $row['description'] ) ? mb_substr( sanitize_textarea_field( (string) $row['description'] ), 0, 1000 ) : '',
				'upload_date' => self::clean_date( $row['upload_date'] ?? '' ),
				'duration'    => self::clean_duration( $row['duration'] ?? 0 ),
				'thumbnail'   => Republished::valid_url( $thumbnail ) ? esc_url_raw( $thumbnail, array( 'http', 'https' ) ) : '',
				'skip'        => ! empty( $row['skip'] ) && filter_var( $row['skip'], FILTER_VALIDATE_BOOLEAN ),
			);

			if ( '' === $entry['name'] && '' === $entry['description'] && '' === $entry['upload_date'] && 0 === $entry['duration'] && '' === $entry['thumbnail'] && ! $entry['skip'] ) {
				continue;
			}

			$seen[ $row['key'] ] = true;
			$out[]               = $entry;

			if ( count( $out ) >= VideoFinder::MAX * 2 ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * What an editor typed for an article, by video key.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, array{key: string, name: string, description: string, upload_date: string, duration: int, thumbnail: string, skip: bool}>
	 */
	public function overrides( int $post_id ): array {
		$map = array();

		foreach ( self::clean_overrides( get_post_meta( $post_id, self::OVERRIDES, true ) ) as $entry ) {
			$map[ $entry['key'] ] = $entry;
		}

		return $map;
	}

	/**
	 * What the video sites answered for an article, by video key.
	 *
	 * Each answer is `status` (`ok`, `unavailable` for a video the site says is private or removed, or `error` when
	 * the site could not be asked), `at` (when it was asked) and the details it gave.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, array{status: string, at: int, name: string, thumbnail: string, upload_date: string, duration: int, description: string}>
	 */
	public function lookups( int $post_id ): array {
		$stored = get_post_meta( $post_id, self::LOOKUPS, true );
		$map    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $key => $row ) {
			if ( ! is_string( $key ) || ! self::valid_key( $key ) || ! is_array( $row ) ) {
				continue;
			}

			$status = isset( $row['status'] ) && is_string( $row['status'] ) ? $row['status'] : 'error';

			$map[ $key ] = array(
				'status'      => in_array( $status, array( 'ok', 'unavailable', 'error' ), true ) ? $status : 'error',
				'at'          => (int) ( $row['at'] ?? 0 ),
				'name'        => isset( $row['name'] ) && is_scalar( $row['name'] ) ? mb_substr( sanitize_text_field( (string) $row['name'] ), 0, 200 ) : '',
				'thumbnail'   => isset( $row['thumbnail'] ) && is_scalar( $row['thumbnail'] ) && Republished::valid_url( (string) $row['thumbnail'] ) ? esc_url_raw( (string) $row['thumbnail'], array( 'http', 'https' ) ) : '',
				'upload_date' => self::clean_date( $row['upload_date'] ?? '' ),
				'duration'    => self::clean_duration( $row['duration'] ?? 0 ),
				'description' => isset( $row['description'] ) && is_scalar( $row['description'] ) ? mb_substr( sanitize_textarea_field( (string) $row['description'] ), 0, 1000 ) : '',
			);
		}

		return $map;
	}

	/* ---------- Finding ---------- */

	/**
	 * The videos of a text, each marked with whether it gets markup.
	 *
	 * `included` is false for a video of a site that is switched off, and, when "Which videos" is the first one
	 * only, for every video after the first that gets markup. `reason` says why: `site_off` or `later`.
	 *
	 * @param string $content The article's text.
	 * @return array<int, array<string, mixed>>
	 */
	public function detect( string $content ): array {
		$first = 'first' === ( $this->settings()['which'] ?? 'all' );
		$taken = false;
		$out   = array();

		foreach ( VideoFinder::find( $content ) as $video ) {
			$video['included'] = true;
			$video['reason']   = '';

			if ( ! $this->site_on( (string) $video['site'] ) ) {
				$video['included'] = false;
				$video['reason']   = 'site_off';
			} elseif ( $first && $taken ) {
				$video['included'] = false;
				$video['reason']   = 'later';
			} else {
				$taken = true;
			}

			$out[] = $video;
		}

		return $out;
	}

	/**
	 * The titles WordPress cached from the video sites' own embed code in an article's meta, by video key.
	 *
	 * WordPress keeps the embed code a video site gave it in the article's meta (`_oembed_` and a hash), and that
	 * code carries the video's title in the iframe. Reading it makes no request.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, string>
	 */
	public function embed_titles( int $post_id ): array {
		$titles = array();

		foreach ( get_post_meta( $post_id ) as $meta_key => $values ) {
			if ( 0 !== strpos( (string) $meta_key, '_oembed_' ) || 0 === strpos( (string) $meta_key, '_oembed_time_' ) ) {
				continue;
			}

			foreach ( (array) $values as $html ) {
				if ( ! is_string( $html ) || 1 !== preg_match( '/<iframe\b([^>]*)>/i', $html, $tag ) ) {
					continue;
				}

				if ( 1 !== preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag[1], $src ) || 1 !== preg_match( '/\btitle\s*=\s*(["\'])(.*?)\1/is', $tag[1], $title ) ) {
					continue;
				}

				$found = VideoFinder::identify( $src[2] );

				if ( null !== $found && '' !== trim( $title[2] ) ) {
					$titles[ $found['site'] . ':' . $found['id'] ] = trim( html_entity_decode( $title[2], ENT_QUOTES ) );
				}
			}
		}

		return $titles;
	}

	/* ---------- Putting it together ---------- */

	/**
	 * The videos of a saved article, with their details.
	 *
	 * @param int $post_id Article ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_post( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		return $this->resolve( $post_id, $this->detect( (string) $post->post_content ) );
	}

	/**
	 * The details of each video found in an article.
	 *
	 * Each video has the keys of `VideoFinder`, `included`, `reason`, and:
	 *
	 * - `name`, `description`, `thumbnail`, `upload_date`, `duration` (seconds): the details, from the strongest source that has them.
	 * - `from`: `typed` when an editor typed any of them, else `lookup` when the video site answered, else `page`, else an empty string.
	 * - `skip`: an editor left this video out of the markup.
	 * - `status`: the lookup's state: `ok`, `unavailable`, `error`, `pending` (it is on and no answer is saved), or an empty string when it is off or does not apply.
	 * - `missing`: the required details that are still missing.
	 * - `ready`: the video gets markup now: it is included, not left out, not unavailable and nothing is missing.
	 *
	 * @param int                                   $post_id Article ID.
	 * @param array<int, array<string, mixed>>      $found   What `detect()` returned.
	 * @param array<int, array<string, mixed>>|null $typed   Details typed in the editor and not saved yet; null reads what is saved.
	 * @return array<int, array<string, mixed>>
	 */
	public function resolve( int $post_id, array $found, ?array $typed = null ): array {
		$typed   = null === $typed ? $this->overrides( $post_id ) : $this->index( self::clean_overrides( $typed ) );
		$lookups = $this->lookups( $post_id );
		$titles  = $this->embed_titles( $post_id );
		$asking  = $this->lookup_on();
		$out     = array();

		foreach ( $found as $video ) {
			$key  = (string) $video['key'];
			$mine = $typed[ $key ] ?? null;
			$ask  = $lookups[ $key ] ?? null;
			$base = $this->library( $video );
			$page = array(
				'name'        => $this->first_text( array( (string) $video['title'], $titles[ $key ] ?? '', $base['name'] ) ),
				'description' => $base['description'],
				'thumbnail'   => $this->first_text( array( (string) $video['thumbnail'], (string) $video['poster'], $base['thumbnail'] ) ),
				'upload_date' => $base['upload_date'],
				'duration'    => $base['duration'],
			);
			$have = null !== $ask && 'ok' === $ask['status'] ? $ask : array();
			$from = null !== $mine && ( '' !== $mine['name'] || '' !== $mine['description'] || '' !== $mine['upload_date'] || $mine['duration'] > 0 || '' !== $mine['thumbnail'] ) ? 'typed' : '';

			$details = array();

			foreach ( array( 'name', 'description', 'thumbnail', 'upload_date', 'duration' ) as $field ) {
				$value = null !== $mine ? $mine[ $field ] : ( 'duration' === $field ? 0 : '' );

				if ( '' === $value || 0 === $value ) {
					$value = $have[ $field ] ?? ( 'duration' === $field ? 0 : '' );
				}

				if ( '' === $value || 0 === $value ) {
					$value = $page[ $field ];
				}

				$details[ $field ] = $value;
			}

			if ( '' === $from ) {
				$from = array() !== $have ? 'lookup' : ( $this->any( $page ) ? 'page' : '' );
			}

			$status = '';

			if ( VideoFinder::OWN !== $video['site'] && $asking ) {
				$status = null === $ask ? 'pending' : $ask['status'];
			}

			$missing = array();

			foreach ( self::REQUIRED as $field ) {
				if ( '' === $details[ $field ] ) {
					$missing[] = $field;
				}
			}

			$skip = null !== $mine && $mine['skip'];

			$out[] = array_merge(
				$video,
				$details,
				array(
					'from'    => $from,
					'skip'    => $skip,
					'status'  => $status,
					'missing' => $missing,
					'ready'   => ! empty( $video['included'] ) && ! $skip && 'unavailable' !== $status && array() === $missing,
				)
			);
		}

		return $out;
	}

	/**
	 * The first text in a list that is not empty.
	 *
	 * @param string[] $texts The texts, strongest first.
	 * @return string
	 */
	private function first_text( array $texts ): string {
		foreach ( $texts as $text ) {
			if ( '' !== trim( $text ) ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * Entries by key.
	 *
	 * @param array<int, array<string, mixed>> $entries Cleaned entries.
	 * @return array<string, array<string, mixed>>
	 */
	private function index( array $entries ): array {
		$map = array();

		foreach ( $entries as $entry ) {
			$map[ (string) $entry['key'] ] = $entry;
		}

		return $map;
	}

	/**
	 * Whether any detail has a value.
	 *
	 * @param array<string, mixed> $details Details.
	 * @return bool
	 */
	private function any( array $details ): bool {
		foreach ( $details as $value ) {
			if ( '' !== $value && 0 !== $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What the media library knows about a video the site hosts.
	 *
	 * @param array<string, mixed> $video A video from the finder.
	 * @return array{name: string, description: string, thumbnail: string, upload_date: string, duration: int}
	 */
	private function library( array $video ): array {
		$none = array(
			'name'        => '',
			'description' => '',
			'thumbnail'   => '',
			'upload_date' => '',
			'duration'    => 0,
		);

		if ( VideoFinder::OWN !== $video['site'] ) {
			return $none;
		}

		$id = (int) $video['attachment'];

		if ( $id < 1 && '' !== (string) $video['content_url'] ) {
			$id = (int) attachment_url_to_postid( (string) $video['content_url'] );
		}

		if ( $id < 1 || ! wp_attachment_is( 'video', $id ) ) {
			return $none;
		}

		$post = get_post( $id );
		$meta = wp_get_attachment_metadata( $id );

		if ( ! $post instanceof \WP_Post ) {
			return $none;
		}

		$description = trim( wp_strip_all_tags( '' !== trim( $post->post_content ) ? $post->post_content : $post->post_excerpt ) );
		$stamp       = '0000-00-00 00:00:00' === $post->post_date_gmt ? 0 : (int) strtotime( $post->post_date_gmt . ' UTC' );

		return array(
			'name'        => trim( wp_strip_all_tags( $post->post_title ) ),
			'description' => mb_substr( $description, 0, 1000 ),
			'thumbnail'   => '',
			'upload_date' => $stamp > 0 ? self::clean_date( gmdate( 'c', $stamp ) ) : '',
			'duration'    => is_array( $meta ) && isset( $meta['length'] ) ? self::clean_duration( (int) $meta['length'] ) : 0,
		);
	}
}
