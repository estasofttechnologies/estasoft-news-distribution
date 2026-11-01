<?php
/**
 * Finds the videos embedded in an article's text.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the saved text of an article and lists its videos in the order they appear.
 *
 * It finds the ways WordPress puts a video in an article:
 *
 * - an iframe whose address is a YouTube or Vimeo player;
 * - an Embed block (`wp:embed`, or the older `wp:core-embed/youtube` and `wp:core-embed/vimeo`), whose
 *   address is in the block comment;
 * - an `[embed]` shortcode;
 * - an address alone on a line, which WordPress turns into an embed when the page is shown;
 * - a `<video>` element, a Video block (`wp:video`) or a `[video]` shortcode, which is a video hosted by
 *   the site itself.
 *
 * A link in a sentence is not an embed and is not listed. A playlist is not one video and is not listed.
 *
 * It reads text and nothing else: no request is made, and nothing is looked up in the database. The
 * caller adds what a video's page or the media library knows.
 *
 * Each video is `array( key, site, id, hash, url, embed_url, content_url, thumbnail, title, poster, attachment )`.
 * `key` is stable for one video: `youtube:ID`, `vimeo:ID` or `own:` and twelve characters taken from the
 * address of the file. `thumbnail` is only given for YouTube, whose picture has a fixed address
 * (unconfirmed: see docs/external-services/youtube-vimeo.md). `title` is the iframe's own title, when it
 * has one. `poster` and `attachment` are for a video of the site.
 */
final class VideoFinder {

	public const YOUTUBE = 'youtube';
	public const VIMEO   = 'vimeo';
	public const OWN     = 'own';

	/**
	 * The most videos listed for one article.
	 */
	public const MAX = 20;

	/**
	 * Finds the videos in a text.
	 *
	 * @param string $content The article's text, as it is saved.
	 * @return array<int, array<string, mixed>> In the order they appear, without repeats.
	 */
	public static function find( string $content ): array {
		if ( '' === $content ) {
			return array();
		}

		$candidates = array_merge(
			self::iframes( $content ),
			self::blocks( $content ),
			self::embed_shortcodes( $content ),
			self::address_lines( $content ),
			self::video_blocks( $content ),
			self::video_elements( $content ),
			self::video_shortcodes( $content )
		);

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return $a['pos'] <=> $b['pos'];
			}
		);

		$list = array();

		foreach ( $candidates as $candidate ) {
			$key = (string) $candidate['key'];

			if ( ! isset( $list[ $key ] ) ) {
				$list[ $key ] = $candidate;
				continue;
			}

			// The same video found a second way: keep the first, and take what only the second knows.
			foreach ( array( 'title', 'poster', 'hash', 'attachment', 'content_url' ) as $field ) {
				if ( empty( $list[ $key ][ $field ] ) && ! empty( $candidate[ $field ] ) ) {
					$list[ $key ][ $field ] = $candidate[ $field ];
				}
			}
		}

		$out = array();

		foreach ( array_slice( array_values( $list ), 0, self::MAX ) as $video ) {
			unset( $video['pos'] );
			$out[] = $video;
		}

		return $out;
	}

	/**
	 * What an address is: a YouTube or a Vimeo video, or null.
	 *
	 * @param string $url An address, with or without the scheme.
	 * @return array{site: string, id: string, hash: string}|null
	 */
	public static function identify( string $url ): ?array {
		$url = trim( html_entity_decode( $url, ENT_QUOTES ) );

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}

		$host  = strtolower( (string) preg_replace( '/^www\./i', '', (string) $parts['host'] ) );
		$path  = (string) ( $parts['path'] ?? '' );
		$query = array();

		parse_str( (string) ( $parts['query'] ?? '' ), $query );

		if ( in_array( $host, array( 'youtube.com', 'm.youtube.com', 'youtube-nocookie.com' ), true ) ) {
			$id = '';

			// "videoseries" is eleven letters long, and it is how YouTube writes the address of a playlist's player.
			if ( 1 === preg_match( '#^/(?:embed|v|shorts|live)/([A-Za-z0-9_-]{11})(?:[/?]|$)#', $path, $found ) && 'videoseries' !== $found[1] ) {
				$id = $found[1];
			} elseif ( '/watch' === $path && isset( $query['v'] ) && is_string( $query['v'] ) && 1 === preg_match( '/^[A-Za-z0-9_-]{11}$/', $query['v'] ) ) {
				$id = $query['v'];
			}

			return '' === $id ? null : array(
				'site' => self::YOUTUBE,
				'id'   => $id,
				'hash' => '',
			);
		}

		if ( 'youtu.be' === $host && 1 === preg_match( '#^/([A-Za-z0-9_-]{11})(?:[/?]|$)#', $path, $found ) ) {
			return array(
				'site' => self::YOUTUBE,
				'id'   => $found[1],
				'hash' => '',
			);
		}

		if ( 'player.vimeo.com' === $host && 1 === preg_match( '#^/video/(\d{4,12})(?:[/?]|$)#', $path, $found ) ) {
			return array(
				'site' => self::VIMEO,
				'id'   => $found[1],
				'hash' => isset( $query['h'] ) && is_string( $query['h'] ) && 1 === preg_match( '/^[0-9a-f]{6,16}$/i', $query['h'] ) ? $query['h'] : '',
			);
		}

		if ( 'vimeo.com' === $host && 1 === preg_match( '#^/(?:channels/[^/]+/|groups/[^/]+/videos/|album/\d+/video/|showcase/\d+/video/)?(\d{4,12})(?:/([0-9a-f]{6,16}))?/?$#i', $path, $found ) ) {
			return array(
				'site' => self::VIMEO,
				'id'   => $found[1],
				'hash' => $found[2] ?? '',
			);
		}

		return null;
	}

	/**
	 * The addresses that belong to a YouTube or a Vimeo video.
	 *
	 * @param string $site YouTube or Vimeo.
	 * @param string $id   The video's id.
	 * @param string $hash The key that goes with the address of an unlisted Vimeo video, or an empty string.
	 * @return array{url: string, embed_url: string, thumbnail: string}
	 */
	public static function addresses( string $site, string $id, string $hash = '' ): array {
		if ( self::YOUTUBE === $site ) {
			return array(
				'url'       => 'https://www.youtube.com/watch?v=' . $id,
				'embed_url' => 'https://www.youtube.com/embed/' . $id,
				'thumbnail' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
			);
		}

		return array(
			'url'       => 'https://vimeo.com/' . $id . ( '' === $hash ? '' : '/' . $hash ),
			'embed_url' => 'https://player.vimeo.com/video/' . $id . ( '' === $hash ? '' : '?h=' . $hash ),
			'thumbnail' => '',
		);
	}

	/**
	 * The key of a video that the site hosts.
	 *
	 * @param string $url The file's address.
	 * @return string
	 */
	public static function own_key( string $url ): string {
		$bare = (string) preg_replace( '/[?#].*$/', '', trim( $url ) );

		return self::OWN . ':' . substr( md5( $bare ), 0, 12 );
	}

	/**
	 * A candidate for a YouTube or Vimeo address.
	 *
	 * @param string               $url   The address.
	 * @param int                  $pos   Where it was found.
	 * @param array<string, mixed> $extra Anything else known, such as the iframe's title.
	 * @return array<string, mixed>|null
	 */
	private static function hosted( string $url, int $pos, array $extra = array() ): ?array {
		$found = self::identify( $url );

		if ( null === $found ) {
			return null;
		}

		$addresses = self::addresses( $found['site'], $found['id'], $found['hash'] );

		return array_merge(
			array(
				'pos'         => $pos,
				'key'         => $found['site'] . ':' . $found['id'],
				'site'        => $found['site'],
				'id'          => $found['id'],
				'hash'        => $found['hash'],
				'url'         => $addresses['url'],
				'embed_url'   => $addresses['embed_url'],
				'content_url' => '',
				'thumbnail'   => $addresses['thumbnail'],
				'title'       => '',
				'poster'      => '',
				'attachment'  => 0,
			),
			$extra
		);
	}

	/**
	 * A candidate for a video file the site hosts.
	 *
	 * @param string $src        The file's address.
	 * @param int    $pos        Where it was found.
	 * @param string $poster     The picture shown before it plays.
	 * @param int    $attachment The media library item, when the block names one.
	 * @return array<string, mixed>|null
	 */
	private static function file( string $src, int $pos, string $poster = '', int $attachment = 0 ): ?array {
		$src = trim( html_entity_decode( $src, ENT_QUOTES ) );

		if ( '' === $src ) {
			return null;
		}

		return array(
			'pos'         => $pos,
			'key'         => self::own_key( self::absolute( $src ) ),
			'site'        => self::OWN,
			'id'          => '',
			'hash'        => '',
			'url'         => '',
			'embed_url'   => '',
			'content_url' => self::absolute( $src ),
			'thumbnail'   => '',
			'title'       => '',
			'poster'      => '' === $poster ? '' : self::absolute( trim( html_entity_decode( $poster, ENT_QUOTES ) ) ),
			'attachment'  => $attachment,
		);
	}

	/**
	 * A whole address for one that starts with a slash or with two.
	 *
	 * @param string $url An address.
	 * @return string
	 */
	private static function absolute( string $url ): string {
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}

		if ( 0 === strpos( $url, '/' ) ) {
			return home_url( $url );
		}

		return $url;
	}

	/**
	 * Whether an address is a video file, by its ending.
	 *
	 * @param string $url An address.
	 * @return bool
	 */
	private static function is_file_address( string $url ): bool {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return 1 === preg_match( '/\.(?:' . implode( '|', array_map( 'preg_quote', wp_get_video_extensions() ) ) . ')$/i', $path );
	}

	/**
	 * Iframes.
	 *
	 * @param string $content The text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function iframes( string $content ): array {
		$out = array();

		if ( ! preg_match_all( '/<iframe\b([^>]*)>/i', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$attributes = (string) $match[1][0];

			if ( 1 !== preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $attributes, $src ) ) {
				continue;
			}

			$title = 1 === preg_match( '/\btitle\s*=\s*(["\'])(.*?)\1/is', $attributes, $named ) ? trim( html_entity_decode( $named[2], ENT_QUOTES ) ) : '';
			$video = self::hosted( $src[2], (int) $match[0][1], array( 'title' => $title ) );

			if ( null !== $video ) {
				$out[] = $video;
			}
		}

		return $out;
	}

	/**
	 * Embed blocks: the address is in the block comment.
	 *
	 * @param string $content The text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function blocks( string $content ): array {
		$out = array();

		if ( ! preg_match_all( '/<!--\s*wp:(?:core-embed\/[a-z0-9-]+|embed)\s+(\{.*?\})\s*\/?-->/s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$data = json_decode( (string) $match[1][0], true );

			if ( ! is_array( $data ) || empty( $data['url'] ) || ! is_string( $data['url'] ) ) {
				continue;
			}

			$video = self::hosted( $data['url'], (int) $match[0][1] );

			if ( null !== $video ) {
				$out[] = $video;
			}
		}

		return $out;
	}

	/**
	 * The [embed] shortcode.
	 *
	 * @param string $content The text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function embed_shortcodes( string $content ): array {
		$out = array();

		if ( ! preg_match_all( '/\[embed\b[^\]]*\](.*?)\[\/embed\]/is', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$url   = trim( wp_strip_all_tags( (string) $match[1][0] ) );
			$video = self::hosted( $url, (int) $match[0][1] );

			if ( null === $video && self::is_file_address( $url ) ) {
				$video = self::file( $url, (int) $match[0][1] );
			}

			if ( null !== $video ) {
				$out[] = $video;
			}
		}

		return $out;
	}

	/**
	 * An address alone on its line, as WordPress embeds it, with or without a paragraph around it.
	 *
	 * @param string $content The text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function address_lines( string $content ): array {
		$out = array();

		if ( ! preg_match_all( '/^[ \t]*(?:<p>[ \t]*)?(https?:\/\/[^\s<>"]+)[ \t]*(?:<\/p>)?[ \t]*$/im', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$url   = (string) $match[1][0];
			$video = self::hosted( $url, (int) $match[0][1] );

			if ( null === $video && self::is_file_address( $url ) ) {
				$video = self::file( $url, (int) $match[0][1] );
			}

			if ( null !== $video ) {
				$out[] = $video;
			}
		}

		return $out;
	}

	/**
	 * Video blocks: the media library item is named in the comment.
	 *
	 * @param string $content The text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function video_blocks( string $content ): array {
		$out = array();

		if ( ! preg_match_all( '/<!--\s*wp:video(?:\s+(\{.*?\}))?\s*-->(.*?)<!--\s*\/wp:video\s*-->/is', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$data  = isset( $match[1] ) && '' !== (string) $match[1][0] ? json_decode( (string) $match[1][0], true ) : array();
			$id    = is_array( $data ) && isset( $data['id'] ) ? absint( $data['id'] ) : 0;
			$video = self::from_element( (string) $match[2][0], (int) $match[0][1], $id );

			if ( null !== $video ) {
				$out[] = $video;
			}
		}

		return $out;
	}

	/**
	 * Video elements.
	 *
	 * @param string $content The text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function video_elements( string $content ): array {
		$out = array();

		if ( ! preg_match_all( '/<video\b[^>]*>.*?<\/video>/is', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$video = self::from_element( (string) $match[0][0], (int) $match[0][1], 0 );

			if ( null !== $video ) {
				$out[] = $video;
			}
		}

		return $out;
	}

	/**
	 * The file and the poster of the first video element in a piece of text.
	 *
	 * @param string $html       The text.
	 * @param int    $pos        Where it was found.
	 * @param int    $attachment The media library item, or 0.
	 * @return array<string, mixed>|null
	 */
	private static function from_element( string $html, int $pos, int $attachment ): ?array {
		if ( 1 !== preg_match( '/<video\b([^>]*)>/i', $html, $tag ) ) {
			return null;
		}

		$src    = '';
		$poster = '';

		if ( 1 === preg_match( '/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag[1], $found ) ) {
			$src = $found[2];
		} elseif ( 1 === preg_match( '/<source\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $found ) ) {
			$src = $found[2];
		}

		if ( 1 === preg_match( '/\bposter\s*=\s*(["\'])(.*?)\1/is', $tag[1], $found ) ) {
			$poster = $found[2];
		}

		return self::file( $src, $pos, $poster, $attachment );
	}

	/**
	 * The [video] shortcode.
	 *
	 * @param string $content The text.
	 * @return array<int, array<string, mixed>>
	 */
	private static function video_shortcodes( string $content ): array {
		$out = array();

		if ( ! preg_match_all( '/\[video\b([^\]]*)\]/i', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}

		foreach ( $matches as $match ) {
			$atts = shortcode_parse_atts( (string) $match[1][0] );
			$atts = is_array( $atts ) ? $atts : array();
			$src  = isset( $atts['src'] ) ? (string) $atts['src'] : '';

			foreach ( wp_get_video_extensions() as $extension ) {
				if ( '' === $src && ! empty( $atts[ $extension ] ) ) {
					$src = (string) $atts[ $extension ];
				}
			}

			$video = self::file( $src, (int) $match[0][1], isset( $atts['poster'] ) ? (string) $atts['poster'] : '' );

			if ( null !== $video ) {
				$out[] = $video;
			}
		}

		return $out;
	}
}
