<?php
/**
 * Serves the API-key feed of one partner.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

use Estasoft\NewsDistribution\Channels\FeedReaders\FeedBuilder;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Takedown;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `/partner-feed/{slug}/`, with the key in the `Authorization: Bearer` header, in
 * `X-API-Key`, or in the address as `?key=` (a key in an address can end up in other
 * people's logs, so a header is the better choice).
 *
 * - No key, a wrong key, a revoked key or an unknown address answers 401. The answer is the
 *   same for all four, so it does not say which addresses exist.
 * - A right key on a partner that is switched off answers 404.
 * - A partner over its limit for the minute answers 429 with `Retry-After`.
 * - `?lang=` picks one of the partner's languages (the first one when it is left out); another
 *   language the site has answers 410, one it has not answers 404.
 * - The document is the open-readers RSS feed (`FeedBuilder`) with the full text, built from the
 *   same articles and held back by the same rules (hold, release plan, takedown), then limited to the
 *   partner's categories. It sits in the output cache, so a busy partner costs no extra queries.
 * - `Last-Modified` and `ETag` give a short 304 reply when nothing changed.
 *
 * Only `respond()` decides anything, and it returns a Response instead of sending it, so it can be tested.
 */
final class PartnerFeedController {

	/**
	 * Items in one partner feed.
	 */
	public const ITEMS = 25;

	/**
	 * How many recent articles are read before the category filter picks from them.
	 */
	private const CANDIDATES = 100;

	private Plugin $plugin;
	private PartnerFeedStore $store;

	/**
	 * Creates the controller.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->store  = $plugin->make( PartnerFeedStore::class );
	}

	/**
	 * Answers the request and stops.
	 *
	 * @param string $slug Partner slug from the address.
	 * @return void
	 */
	public function serve( string $slug ): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			( new Response( 405, array( 'Allow' => 'GET, HEAD' ) ) )->send();
		}

		$lang    = Query::text( 'lang' );
		$headers = array(
			'if_none_match'     => isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '',
			'if_modified_since' => isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '',
		);

		$this->respond( $slug, self::key_from_request(), $lang, $headers )->send( 'HEAD' === $method );
	}

	/**
	 * The key a request carries: the Authorization header, then X-API-Key, then `?key=`.
	 *
	 * @return string Empty when there is none.
	 */
	public static function key_from_request(): string {
		$auth = '';

		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $name ) {
			$auth = isset( $_SERVER[ $name ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $name ] ) ) ) : '';

			if ( '' !== $auth ) {
				break;
			}
		}

		if ( 1 === preg_match( '/^Bearer\s+(\S+)$/i', $auth, $found ) ) {
			return $found[1];
		}

		$header_key = isset( $_SERVER['HTTP_X_API_KEY'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_API_KEY'] ) ) ) : '';

		if ( '' !== $header_key ) {
			return $header_key;
		}

		// The key authorises a partner request; there is no page nonce.
		return trim( Query::text( 'key' ) );
	}

	/**
	 * Builds the response for a request.
	 *
	 * @param string               $slug    Partner slug.
	 * @param string               $key     The key the request carried, or an empty string.
	 * @param string               $lang    Requested language code, or an empty string for the partner's first.
	 * @param array<string,string> $headers if_none_match and if_modified_since from the request.
	 * @return Response
	 */
	public function respond( string $slug, string $key, string $lang, array $headers = array() ): Response {
		$partner = $this->store->find_by_slug( $slug );

		if ( ! $this->is_allowed( $partner, $key ) ) {
			return self::unauthorized();
		}

		if ( empty( $partner['enabled'] ) ) {
			return self::plain( 404, 'Not found' );
		}

		/**
		 * Lets something hold a feed back, such as demo mode (internal).
		 *
		 * @param Response|null $held    A response to send instead of the feed, or null.
		 * @param null          $channel No channel: a partner feed belongs to a partner.
		 * @param string        $slug    The partner's address.
		 */
		$held = apply_filters( 'estasoft_nd_internal_feed_hold', null, null, $slug );

		if ( $held instanceof Response ) {
			return $held;
		}

		$limit = PartnerFeedLimiter::hit( (string) $partner['id'], (int) $partner['rate_limit'] );
		$rate  = array(
			'X-RateLimit-Limit'     => (string) (int) $partner['rate_limit'],
			'X-RateLimit-Remaining' => (string) max( 0, (int) $partner['rate_limit'] - $limit['count'] ),
		);

		if ( ! $limit['allowed'] ) {
			return self::plain( 429, 'Too many requests', array_merge( $rate, array( 'Retry-After' => (string) $limit['retry_after'] ) ) );
		}

		$this->store->touch( (string) $partner['id'] );

		$language = $this->language_for( $partner, $lang );

		if ( 404 === $language['status'] || 410 === $language['status'] ) {
			return self::plain( (int) $language['status'], 410 === $language['status'] ? 'Gone' : 'Not found', $rate );
		}

		$code  = (string) $language['code'];
		$entry = $this->entry( $partner, $code );
		$etag  = '"' . md5( $entry['body'] ) . '"';
		$time  = (int) $entry['modified'];

		$common = array_merge(
			$rate,
			array(
				'Content-Type'  => 'application/rss+xml; charset=UTF-8',
				'X-Robots-Tag'  => 'noindex, nofollow',
				'Cache-Control' => 'private, max-age=60',
				'Vary'          => 'Authorization, X-API-Key, Accept-Encoding',
				'ETag'          => $etag,
			)
		);

		if ( $time > 0 ) {
			$common['Last-Modified'] = gmdate( 'D, d M Y H:i:s', $time ) . ' GMT';
		}

		if ( $this->not_modified( $etag, $time, $headers ) ) {
			return new Response( 304, $common );
		}

		return new Response( 200, $common, $entry['body'] );
	}

	/**
	 * Whether the request carries the partner's key.
	 *
	 * A slug with no partner still costs one hash, so the time taken does not tell a real address from an invented one.
	 *
	 * @param array<string, mixed>|null $partner The partner, or null for an unknown slug.
	 * @param string                    $key     The key the request carried.
	 * @return bool
	 */
	private function is_allowed( ?array $partner, string $key ): bool {
		if ( null === $partner ) {
			PartnerFeedKey::hash( $key, 'no-such-partner' );

			return false;
		}

		return PartnerFeedKey::matches( $key, (string) ( $partner['key_hash'] ?? '' ), (string) ( $partner['key_salt'] ?? '' ) );
	}

	/**
	 * The language a request gets.
	 *
	 * @param array<string, mixed> $partner The partner.
	 * @param string               $wanted  The `lang` value, or an empty string.
	 * @return array{status: int, code: string} 200 with the language code, or 404 or 410 without one.
	 */
	private function language_for( array $partner, string $wanted ): array {
		$mine = array_values( array_map( 'strval', (array) $partner['languages'] ) );
		$site = array_map( 'strval', $this->plugin->languages()->languages() );

		if ( '' === $wanted ) {
			foreach ( $mine as $code ) {
				if ( $this->is_on( $code ) ) {
					return array(
						'status' => 200,
						'code'   => $code,
					);
				}
			}

			return array(
				'status' => 410,
				'code'   => '',
			);
		}

		$normal = strtolower( str_replace( '_', '-', $wanted ) );

		foreach ( $site as $code ) {
			if ( strtolower( str_replace( '_', '-', $code ) ) !== $normal ) {
				continue;
			}

			return in_array( $code, $mine, true ) && $this->is_on( $code )
				? array(
					'status' => 200,
					'code'   => $code,
				)
				: array(
					'status' => 410,
					'code'   => '',
				);
		}

		return array(
			'status' => 404,
			'code'   => '',
		);
	}

	/**
	 * Whether a language is turned on in Settings, Languages.
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	private function is_on( string $code ): bool {
		return ! in_array( $code, array_map( 'strval', (array) $this->plugin->settings()->get( 'languages.off', array() ) ), true );
	}

	/**
	 * The built document, from the output cache when there is one.
	 *
	 * The key includes the partner's categories, so changing them is never served from an old copy.
	 *
	 * @param array<string, mixed> $partner The partner.
	 * @param string               $code    Language code.
	 * @return array{body: string, modified: int}
	 */
	private function entry( array $partner, string $code ): array {
		$categories = array_values( array_map( 'strval', (array) $partner['categories'] ) );

		return $this->plugin->cache()->remember(
			'partner:' . $partner['id'] . ':' . $code . ':' . md5( implode( ',', $categories ) ),
			$this->plugin->settings()->cache_ttl(),
			function () use ( $partner, $code, $categories ): array {
				return $this->build_document( $partner, $code, $categories );
			}
		);
	}

	/**
	 * Builds one partner feed now, without the cache.
	 *
	 * @param array<string, mixed> $partner    The partner.
	 * @param string               $code       Language code.
	 * @param string[]             $categories Category slugs; empty for every category.
	 * @return array{body: string, modified: int}
	 */
	public function build_document( array $partner, string $code, array $categories ): array {
		$articles = $this->plugin->reader()->recent( $code, YEAR_IN_SECONDS, self::CANDIDATES );
		$articles = $this->releasable( $articles, $code );
		$articles = $this->in_categories( $articles, $categories );
		$now      = Clock::now();
		$items    = FeedBuilder::select( $articles, $code, $now, self::ITEMS );
		$modified = 0;

		foreach ( $items as $article ) {
			$modified = max( $modified, $article->modified_ts(), $article->published_ts() );
		}

		$name        = $this->plugin->settings()->publication_name( $code );
		$description = trim( (string) get_bloginfo( 'description' ) );

		return array(
			'body'     => FeedBuilder::build(
				$items,
				$code,
				$now,
				array(
					'title'       => $name,
					'link'        => home_url( '/' ),
					'description' => '' !== $description ? $description : $name,
					'self'        => add_query_arg( 'lang', rawurlencode( $code ), PartnerFeedStore::feed_url( (string) $partner['slug'] ) ),
					'limit'       => self::ITEMS,
				)
			),
			'modified' => $modified > 0 ? $modified : $now,
		);
	}

	/**
	 * Takes out what no feed may carry: a taken-down article, and anything the open-readers feed
	 * holds back (a hold, an exclusive window that has not ended, republished content, a missing credit).
	 *
	 * @param Article[] $articles Candidates.
	 * @param string    $code     Language code.
	 * @return Article[]
	 */
	private function releasable( array $articles, string $code ): array {
		$stand_in = $this->plugin->registry()->get( 'feed-readers' );

		if ( null !== $stand_in ) {
			$kept = apply_filters( 'estasoft_nd_internal_feed_articles', $articles, $stand_in, $code );

			return is_array( $kept ) ? array_values( $kept ) : $articles;
		}

		$takedown = $this->plugin->make( Takedown::class );
		$kept     = array();

		foreach ( $articles as $article ) {
			if ( ! $takedown->is_down( $article->id() ) ) {
				$kept[] = $article;
			}
		}

		return $kept;
	}

	/**
	 * Keeps the articles in the chosen categories, and in the categories below them.
	 *
	 * @param Article[] $articles   Candidates.
	 * @param string[]  $categories Category slugs; empty keeps everything.
	 * @return Article[]
	 */
	private function in_categories( array $articles, array $categories ): array {
		if ( array() === $categories || array() === $articles ) {
			return $articles;
		}

		$ids = array();

		foreach ( $categories as $slug ) {
			$term = get_term_by( 'slug', $slug, 'category' );

			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$ids[] = (int) $term->term_id;
			$below = get_term_children( (int) $term->term_id, 'category' );

			foreach ( is_array( $below ) ? $below : array() as $child ) {
				$ids[] = (int) $child;
			}

			// With a translation plugin each language has its own category; the translations count as the same one.
			if ( function_exists( 'pll_get_term' ) && function_exists( 'pll_languages_list' ) ) {
				foreach ( (array) pll_languages_list() as $language ) {
					$translated = pll_get_term( (int) $term->term_id, (string) $language );

					if ( $translated ) {
						$ids[] = (int) $translated;
					}
				}
			}
		}

		$ids = array_values( array_unique( $ids ) );

		if ( array() === $ids ) {
			// The partner is limited to categories that no longer exist: it gets nothing, never everything.
			return array();
		}

		return array_values(
			array_filter(
				$articles,
				static function ( Article $article ) use ( $ids ): bool {
					return has_term( $ids, 'category', $article->id() );
				}
			)
		);
	}

	/**
	 * Whether the request's validators say the partner already has this document.
	 *
	 * An `If-None-Match` header wins over `If-Modified-Since`, as HTTP says.
	 *
	 * @param string               $etag     Current entity tag.
	 * @param int                  $modified Last modified time.
	 * @param array<string,string> $headers  Request validators.
	 * @return bool
	 */
	private function not_modified( string $etag, int $modified, array $headers ): bool {
		$none  = (string) ( $headers['if_none_match'] ?? '' );
		$since = (string) ( $headers['if_modified_since'] ?? '' );

		if ( '' !== $none ) {
			foreach ( explode( ',', $none ) as $candidate ) {
				$candidate = trim( str_replace( 'W/', '', $candidate ) );

				if ( '*' === $candidate || $candidate === $etag ) {
					return true;
				}
			}

			return false;
		}

		if ( '' !== $since && $modified > 0 ) {
			$time = strtotime( $since );

			// Only the date this feed sent is answered with a short reply: the feed can change without its newest article changing.
			return false !== $time && $time === $modified;
		}

		return false;
	}

	/**
	 * The answer to a request without a good key. It never says why.
	 *
	 * @return Response
	 */
	private static function unauthorized(): Response {
		return self::plain(
			401,
			'A valid API key is needed to read this feed.',
			array( 'WWW-Authenticate' => 'Bearer realm="News Distribution partner feed"' )
		);
	}

	/**
	 * A small plain-text answer.
	 *
	 * @param int                  $status  HTTP status.
	 * @param string               $body    Text.
	 * @param array<string,string> $headers More headers.
	 * @return Response
	 */
	private static function plain( int $status, string $body, array $headers = array() ): Response {
		return new Response(
			$status,
			array_merge(
				array(
					'Content-Type'  => 'text/plain; charset=UTF-8',
					'X-Robots-Tag'  => 'noindex, nofollow',
					'Cache-Control' => 'no-store',
				),
				$headers
			),
			$body
		);
	}
}
