<?php
/**
 * Serves feeds and sitemaps.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Core\ArticleChoices;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `/feed/{slug}/?lang={code}`.
 *
 * - An unknown feed, or one whose channel is off, answers 404.
 * - A language the channel does not carry answers 410.
 * - The document is built from a cache keyed by feed, language and cache version.
 * - `Last-Modified` and `ETag` let a platform ask "has it changed?" and get a
 *   short 304 reply, when the "Short replies" setting is on.
 * - Only requests from named platforms reach the fetch log.
 */
final class FeedController {

	private Plugin $plugin;

	/**
	 * Creates the controller.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Answers the request and stops.
	 *
	 * @param string $slug Feed slug from the address.
	 * @return void
	 */
	public function serve( string $slug ): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$lang   = Query::text( 'lang' );

		$headers = array(
			'if_none_match'     => isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '',
			'if_modified_since' => isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '',
		);

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			( new Response( 405, array( 'Allow' => 'GET, HEAD' ) ) )->send();
		}

		$response = $this->respond( $slug, $lang, $headers, $agent );
		$response->send( 'HEAD' === $method );
	}

	/**
	 * Builds the response for a request.
	 *
	 * @param string               $slug    Feed slug.
	 * @param string               $lang    Requested language code, or an empty string for the default.
	 * @param array<string,string> $headers if_none_match and if_modified_since from the request.
	 * @param string               $agent   The user agent.
	 * @return Response
	 */
	public function respond( string $slug, string $lang, array $headers, string $agent ): Response {
		$started  = microtime( true );
		$channel  = $this->channel_for( $slug );
		$platform = Agents::detect( $agent );

		if ( null === $channel ) {
			return self::plain( 404 );
		}

		/**
		 * Lets something hold a pulled feed or sitemap back, such as demo mode (internal). A held request is not logged:
		 * the platform did not get the feed.
		 *
		 * @param Response|null    $held    A response to send instead of the feed, or null.
		 * @param ChannelInterface $channel The channel that serves the feed.
		 * @param string           $slug    Feed slug.
		 */
		$held = apply_filters( 'estasoft_nd_internal_feed_hold', null, $channel, $slug );

		if ( $held instanceof Response ) {
			return $held;
		}

		$language = $this->resolve_language( $lang );

		if ( '' === $language ) {
			return $this->logged( $channel, '', $platform, 'not_found', self::plain( 404 ), $started, false );
		}

		if ( ! $channel->accepts_language( $language ) ) {
			return $this->logged( $channel, $language, $platform, 'gone', self::plain( 410 ), $started, false );
		}

		$entry       = $this->entry( $channel, $slug, $language );
		$etag        = '"' . md5( $entry['body'] ) . '"';
		$modified    = (int) $entry['modified'];
		$conditional = '' !== $headers['if_none_match'] || '' !== $headers['if_modified_since'];
		$send_short  = (bool) $this->plugin->settings()->get( 'general.short_replies', true );

		$common = array(
			'Content-Type'  => $channel->content_type(),
			'X-Robots-Tag'  => 'noindex',
			'Cache-Control' => 'public, max-age=60',
			'Vary'          => 'Accept-Encoding',
		);

		/**
		 * Filters the HTTP headers of a feed response, such as the WebSub Link header.
		 *
		 * @param array<string, string> $common   Headers.
		 * @param ChannelInterface      $channel  The channel that serves the feed.
		 * @param string                $language Language code of the response.
		 */
		$common = apply_filters( 'estasoft_nd_feed_headers', $common, $channel, $language );

		if ( $send_short ) {
			$common['ETag'] = $etag;

			if ( $modified > 0 ) {
				$common['Last-Modified'] = gmdate( 'D, d M Y H:i:s', $modified ) . ' GMT';
			}

			if ( $this->not_modified( $etag, $modified, $headers ) ) {
				$short = new Response( 304, $common );
				return $this->logged( $channel, $language, $platform, 'not_modified', $short, $started, true );
			}
		}

		$full = new Response( 200, $common, $entry['body'] );

		return $this->logged( $channel, $language, $platform, 'ok', $full, $started, $conditional );
	}

	/**
	 * Answers an address nothing serves, then stops.
	 *
	 * @return void
	 */
	public static function not_found(): void {
		self::plain( 404 )->send();
	}

	/**
	 * A small plain-text answer for an error status.
	 *
	 * @param int $status HTTP status.
	 * @return Response
	 */
	private static function plain( int $status ): Response {
		return new Response(
			$status,
			array(
				'Content-Type'  => 'text/plain; charset=UTF-8',
				'X-Robots-Tag'  => 'noindex',
				'Cache-Control' => 'public, max-age=60',
			),
			410 === $status ? 'Gone' : 'Not found'
		);
	}

	/**
	 * The channel that answers a slug: an enabled one, or none.
	 *
	 * @param string $slug Feed slug.
	 * @return ChannelInterface|null
	 */
	private function channel_for( string $slug ): ?ChannelInterface {
		foreach ( $this->plugin->registry()->serving( $slug ) as $channel ) {
			if ( $channel->is_enabled() ) {
				return $channel;
			}
		}

		return null;
	}

	/**
	 * Matches the requested language to one the site publishes in.
	 *
	 * @param string $requested Value of `lang`, possibly empty.
	 * @return string The language code, or an empty string when it is unknown.
	 */
	private function resolve_language( string $requested ): string {
		$known = $this->plugin->languages()->languages();

		if ( '' === $requested ) {
			return $this->plugin->languages()->default_language();
		}

		$wanted = strtolower( str_replace( '_', '-', $requested ) );

		foreach ( $known as $code ) {
			if ( strtolower( str_replace( '_', '-', (string) $code ) ) === $wanted ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * The built document, from the cache when there is one.
	 *
	 * @param ChannelInterface $channel  The channel.
	 * @param string           $slug     Feed slug.
	 * @param string           $language Language code.
	 * @return array{body: string, modified: int}
	 */
	private function entry( ChannelInterface $channel, string $slug, string $language ): array {
		$plugin = $this->plugin;

		return $plugin->cache()->remember(
			'feed:' . $slug . ':' . $language,
			$plugin->settings()->cache_ttl(),
			function () use ( $channel, $language ): array {
				return $this->build_document( $channel, $language );
			}
		);
	}

	/**
	 * Builds a channel's document now, without the cache. Used by the feed
	 * request on a cache miss, and by the validate, preview and self-test routes.
	 *
	 * @param ChannelInterface $channel  The channel.
	 * @param string           $language Language code.
	 * @return array{body: string, modified: int, articles: int}
	 */
	public function build_document( ChannelInterface $channel, string $language ): array {
		$window   = $channel->feed_window();
		$articles = $this->plugin->reader()->recent( $language, (int) $window['max_age'], (int) $window['limit'] );

		/**
		 * Takes articles out of one channel's document: a held article, one still waiting
		 * for its release time, one that was taken down (internal).
		 *
		 * @param \Estasoft\NewsDistribution\Core\Article[] $articles The articles.
		 * @param ChannelInterface                          $channel  The channel.
		 * @param string                                    $language Language code.
		 */
		$kept     = apply_filters( 'estasoft_nd_internal_feed_articles', $articles, $channel, $language );
		$articles = is_array( $kept ) ? array_values( $kept ) : $articles;
		$modified = 0;

		foreach ( $articles as $article ) {
			$modified = max( $modified, $article->modified_ts(), $article->published_ts() );
		}

		// An article that left the document because a person switched the channel off for it changed the document at that time.
		$modified = max( $modified, $this->plugin->make( ArticleChoices::class )->changed_at() );

		return array(
			'body'     => $channel->build( $articles, $language ),
			'modified' => $modified > 0 ? $modified : Clock::now(),
			'articles' => count( $articles ),
		);
	}

	/**
	 * Whether the request's validators say the platform already has this document.
	 *
	 * An `If-None-Match` header wins over `If-Modified-Since`, as HTTP says.
	 *
	 * @param string               $etag     Current entity tag.
	 * @param int                  $modified Last modified time.
	 * @param array<string,string> $headers  Request validators.
	 * @return bool
	 */
	private function not_modified( string $etag, int $modified, array $headers ): bool {
		if ( '' !== $headers['if_none_match'] ) {
			foreach ( explode( ',', $headers['if_none_match'] ) as $candidate ) {
				$candidate = trim( str_replace( 'W/', '', $candidate ) );

				if ( '*' === $candidate || $candidate === $etag ) {
					return true;
				}
			}

			return false;
		}

		if ( '' !== $headers['if_modified_since'] && $modified > 0 ) {
			$since = strtotime( $headers['if_modified_since'] );

			// Only the date this feed sent is answered with a short reply. The feed can change without its newest article
			// changing (an older item ages out of the week), so a later date a client made up does not prove it has the feed.
			return false !== $since && $since === $modified;
		}

		return false;
	}

	/**
	 * Records the request for a named platform, then returns the response.
	 *
	 * @param ChannelInterface $channel     The channel.
	 * @param string           $language    Language code, possibly empty.
	 * @param string           $platform    Platform name from the user agent, or "other".
	 * @param string           $status      ok, not_modified, gone or not_found.
	 * @param Response         $response    The response.
	 * @param float            $started     Start time from microtime().
	 * @param bool             $conditional Whether the request asked "has it changed?".
	 * @return Response
	 */
	private function logged( ChannelInterface $channel, string $language, string $platform, string $status, Response $response, float $started, bool $conditional ): Response {
		if ( 'other' !== $platform ) {
			$this->plugin->fetch_log()->record(
				$channel->id(),
				$language,
				$platform,
				$status,
				$response->status,
				(int) round( ( microtime( true ) - $started ) * 1000 ),
				$conditional,
				strlen( $response->body )
			);
		}

		return $response;
	}
}
