<?php
/**
 * Bluesky, through the AT Protocol.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Language\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Posts to the owner's Bluesky accounts, one account per language, signed in with
 * an app password (never the account password).
 *
 * A post holds at most 300 graphemes and 3000 bytes. Bluesky does not build the
 * link card itself, so News Distribution uploads the article's image with the
 * post. Bluesky has no idempotency key, so before it retries it looks at the
 * account's latest posts and does not post the same article twice.
 */
final class BlueskyOutlet extends AbstractOutlet {

	/**
	 * The server that signs people in. An account on another server is found from the answer.
	 */
	public const ENTRYWAY = 'https://bsky.social';

	/**
	 * The most hashtags added to a post.
	 */
	private const MAX_HASHTAGS = 3;

	public function id(): string {
		return 'bluesky';
	}

	public function label(): string {
		return __( 'Bluesky', 'estasoft-news-distribution' );
	}

	/**
	 * Settings: the switch, and a handle and an app password per language.
	 *
	 * @param string[] $languages Language codes the site publishes in.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( array $languages ): array {
		$fields = array(
			'bluesky_on' => array(
				'type'    => 'bool',
				'label'   => __( 'Post to Bluesky', 'estasoft-news-distribution' ),
				'default' => false,
			),
		);

		foreach ( $languages as $language ) {
			$name   = $this->language_name( (string) $language );
			$suffix = self::suffix( (string) $language );

			$fields[ 'bluesky_handle_' . $suffix ]   = array(
				'type'    => 'string',
				'label'   => sprintf(
					/* translators: %s: a language name such as English. */
					__( '%s handle', 'estasoft-news-distribution' ),
					$name
				),
				'default' => '',
				'max'     => 253,
			);
			$fields[ 'bluesky_password_' . $suffix ] = array(
				'type'  => 'secret',
				'label' => sprintf(
					/* translators: %s: a language name such as English. */
					__( '%s app password', 'estasoft-news-distribution' ),
					$name
				),
				'max'   => 100,
			);
		}

		return $fields;
	}

	public function is_on( array $settings ): bool {
		if ( empty( $settings['bluesky_on'] ) ) {
			return false;
		}

		foreach ( $this->targets( $settings, $this->languages() ) as $target ) {
			if ( $target->is_complete() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One target per language that has a handle or an app password.
	 *
	 * @param array<string, mixed> $settings  The channel's settings.
	 * @param string[]             $languages Language codes the site publishes in.
	 * @return Target[]
	 */
	public function targets( array $settings, array $languages ): array {
		$targets = array();

		foreach ( $languages as $language ) {
			$language = (string) $language;
			$suffix   = self::suffix( $language );
			$handle   = self::normalise_handle( (string) ( $settings[ 'bluesky_handle_' . $suffix ] ?? '' ) );
			$has_pass = $this->has_secret( 'bluesky_password_' . $suffix );

			if ( '' === $handle && ! $has_pass ) {
				continue;
			}

			$targets[] = new Target( $this->id(), $language, $handle, $language, '' !== $handle && self::is_valid_handle( $handle ) && $has_pass );
		}

		return $targets;
	}

	/**
	 * Cleans what a person typed as a handle: no leading @, lower case.
	 *
	 * @param string $text What was typed.
	 * @return string
	 */
	public static function normalise_handle( string $text ): string {
		return strtolower( ltrim( trim( $text ), '@' ) );
	}

	/**
	 * Whether a handle has the shape of a domain name, such as name.bsky.social.
	 *
	 * @param string $handle A normalised handle.
	 * @return bool
	 */
	public static function is_valid_handle( string $handle ): bool {
		return 1 === preg_match( '/^(?=.{3,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $handle );
	}

	/**
	 * The size of a post as Bluesky limits it, as one number: the larger of the graphemes and a tenth of
	 * the bytes, so a post is within both limits exactly when this is at most 300.
	 *
	 * @param string $text The text of a post.
	 * @return int
	 */
	public static function units( string $text ): int {
		return max( PostText::graphemes( $text ), (int) ceil( strlen( $text ) / 10 ) );
	}

	public function check( Article $article, array $settings ): ValidationResult {
		$result = parent::check( $article, $settings );

		if ( null === $this->target_for( $article->language(), $settings ) ) {
			$result->add(
				$this->context->rules->severity( OutletsChannel::ID, 'post.no_account', 'warn' ),
				'post.no_account',
				sprintf(
					/* translators: %s: a language name such as Kurmanji. */
					__( 'No Bluesky account is set for %s, so this article is not posted to Bluesky.', 'estasoft-news-distribution' ),
					$this->language_name( $article->language() )
				)
			);

			return $result;
		}

		$headline = ! empty( $settings['social_headline'] ) ? PostText::headline( $article ) : '';
		$needed   = self::units( implode( "\n\n", array_filter( array( $headline, $article->permalink() ) ) ) );

		if ( $needed > $this->limit( 'bluesky_graphemes_max', 300 ) ) {
			$result->add(
				$this->context->rules->severity( OutletsChannel::ID, 'post.headline_too_long', 'block' ),
				'post.headline_too_long',
				sprintf(
					/* translators: %d: the most characters a Bluesky post can hold. */
					_n(
						'The headline and the link do not fit in a Bluesky post of %d character, and neither is ever cut. Shorten the headline.',
						'The headline and the link do not fit in a Bluesky post of %d characters, and neither is ever cut. Shorten the headline.',
						$this->limit( 'bluesky_graphemes_max', 300 ),
						'estasoft-news-distribution'
					),
					$this->limit( 'bluesky_graphemes_max', 300 )
				)
			);
		}

		if ( ! empty( $settings['social_card'] ) && '' === $article->thumbnail_url() ) {
			$result->add_info(
				'post.no_image',
				__( 'The article has no image, so the Bluesky link card has a title and description only.', 'estasoft-news-distribution' )
			);
		}

		return $result;
	}

	public function send( Target $target, Event $event, ?Article $article, array $settings ): Outcome {
		if ( null === $article ) {
			return Outcome::skipped( $target, __( 'Bluesky posts are not removed from here. Delete a post in Bluesky.', 'estasoft-news-distribution' ) );
		}

		$session = $this->open_session( $target, $settings );

		if ( null !== $session['outcome'] ) {
			return $session['outcome'];
		}

		// A send that follows one that did not finish may have arrived: look at the account first.
		if ( $this->marks->attempts( $article->id(), $event->type(), $target->id() ) > 1 && $this->already_posted( $session, $article->permalink() ) ) {
			return Outcome::sent( $target, 200, __( 'The post is already on the account, so it was not posted again.', 'estasoft-news-distribution' ) );
		}

		$text   = $this->post_text( $article, $settings );
		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'Y-m-d\TH:i:s.000\Z', Clock::now() ),
			'langs'     => array( Catalog::primary( $article->language() ) ),
		);
		$facets = $this->facets( $text, $article->permalink() );

		if ( array() !== $facets ) {
			$record['facets'] = $facets;
		}

		if ( ! empty( $settings['social_card'] ) ) {
			$record['embed'] = $this->card( $session, $article, $settings );
		}

		return $this->create( $target, $session, $record );
	}

	public function test( Target $target, array $settings, bool $send ): Outcome {
		$session = $this->open_session( $target, $settings );

		if ( null !== $session['outcome'] ) {
			return $session['outcome'];
		}

		$this->state->record_handle( $target->id(), $this->clean( $session['handle'] ) );

		if ( ! $send ) {
			return Outcome::sent(
				$target,
				200,
				sprintf(
					/* translators: %s: a Bluesky handle such as example-times.bsky.social. */
					__( 'Bluesky accepted the app password for %s.', 'estasoft-news-distribution' ),
					$this->clean( $session['handle'] )
				)
			);
		}

		return $this->create(
			$target,
			$session,
			array(
				'$type'     => 'app.bsky.feed.post',
				'text'      => sprintf(
					/* translators: %s: the publication's name. */
					__( 'Test post from %s. News Distribution is connected. You can delete this post.', 'estasoft-news-distribution' ),
					$this->context->settings->publication_name( $target->language() )
				),
				'createdAt' => gmdate( 'Y-m-d\TH:i:s.000\Z', Clock::now() ),
				'langs'     => array( Catalog::primary( $target->language() ) ),
			)
		);
	}

	/**
	 * The text of a post: headline, summary, address and hashtags, shortened to fit.
	 *
	 * The headline and the address are never cut. Bluesky counts the address as written, so a long
	 * address costs what it is long. The summary takes what is left.
	 *
	 * @param Article              $article  The article.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return string
	 */
	public function post_text( Article $article, array $settings ): string {
		$max      = $this->limit( 'bluesky_graphemes_max', 300 );
		$headline = ! empty( $settings['social_headline'] ) ? PostText::headline( $article ) : '';
		$url      = $article->permalink();
		$summary  = '';

		if ( ! empty( $settings['social_summary'] ) ) {
			$summary = PostText::shorten(
				PostText::summary( $article ),
				$max,
				static function ( string $candidate ) use ( $headline, $url ): int {
					return self::units( implode( "\n\n", array_filter( array( $headline, $candidate, $url ), 'strlen' ) ) );
				}
			);
		}

		$text = implode( "\n\n", array_filter( array( $headline, $summary, $url ), 'strlen' ) );

		if ( ! empty( $settings['social_hashtags'] ) ) {
			$room = $max - self::units( $text ) - 2;
			$tags = array_slice(
				PostText::hashtags(
					$article,
					$room,
					static function ( string $tag ): int {
						return self::units( $tag );
					}
				),
				0,
				self::MAX_HASHTAGS
			);

			if ( array() !== $tags ) {
				$text .= "\n\n" . implode( ' ', $tags );
			}
		}

		return $text;
	}

	/**
	 * Marks the address and the hashtags in a post, by byte position, as Bluesky wants them.
	 *
	 * @param string $text The text of the post.
	 * @param string $url  The address in the text.
	 * @return array<int, array<string, mixed>>
	 */
	public function facets( string $text, string $url ): array {
		$facets = array();
		$at     = '' === $url ? false : strpos( $text, $url );

		if ( false !== $at ) {
			$facets[] = array(
				'index'    => array(
					'byteStart' => $at,
					'byteEnd'   => $at + strlen( $url ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $url,
					),
				),
			);
		}

		$from = false === $at ? 0 : $at + strlen( $url );

		if ( preg_match_all( '/(?<=\s)#([\p{L}\p{N}\p{M}_]+)/u', substr( $text, $from ), $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $index => $match ) {
				$start    = $from + (int) $match[1];
				$facets[] = array(
					'index'    => array(
						'byteStart' => $start,
						'byteEnd'   => $start + strlen( (string) $match[0] ),
					),
					'features' => array(
						array(
							'$type' => 'app.bsky.richtext.facet#tag',
							'tag'   => (string) $found[1][ $index ][0],
						),
					),
				);
			}
		}

		return $facets;
	}

	/**
	 * Signs in with the handle and the app password.
	 *
	 * @param Target               $target   The target.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return array{outcome: Outcome|null, jwt: string, did: string, handle: string, pds: string}
	 */
	private function open_session( Target $target, array $settings ): array {
		$none = array(
			'outcome' => null,
			'jwt'     => '',
			'did'     => '',
			'handle'  => '',
			'pds'     => self::ENTRYWAY,
		);

		$handle   = self::normalise_handle( (string) ( $settings[ 'bluesky_handle_' . self::suffix( $target->language() ) ] ?? '' ) );
		$password = $this->secret( 'bluesky_password_' . self::suffix( $target->language() ) );

		if ( '' === $handle || '' === $password ) {
			$none['outcome'] = Outcome::failed( $target, __( 'The handle or the app password is missing. Enter them again on the Mastodon and Bluesky tab.', 'estasoft-news-distribution' ), 0, false );

			return $none;
		}

		$until = $this->state->paused_until( $this->id() );

		if ( $until > Clock::now() ) {
			$none['outcome'] = Outcome::failed(
				$target,
				sprintf(
					/* translators: %s: a time of day such as 14:38. */
					__( 'Bluesky asked News Distribution to slow down, so it waits until %s.', 'estasoft-news-distribution' ),
					Clock::format( $until )
				),
				429,
				true
			);

			return $none;
		}

		$reply = $this->http(
			'POST',
			self::ENTRYWAY . '/xrpc/com.atproto.server.createSession',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'identifier' => $handle,
						'password'   => $password,
					)
				),
			)
		);
		$json  = $reply->json();

		if ( ! $reply->is_success() || empty( $json['accessJwt'] ) || empty( $json['did'] ) ) {
			$none['outcome'] = $this->failure( $target, $reply, true );

			return $none;
		}

		return array(
			'outcome' => null,
			'jwt'     => (string) $json['accessJwt'],
			'did'     => (string) $json['did'],
			'handle'  => (string) ( $json['handle'] ?? $handle ),
			'pds'     => $this->pds_from( $json ),
		);
	}

	/**
	 * The server that holds the account, from the sign-in answer, else the one that signs people in.
	 *
	 * @param array<mixed> $session The createSession answer.
	 * @return string
	 */
	private function pds_from( array $session ): string {
		$services = $session['didDoc']['service'] ?? array();

		foreach ( is_array( $services ) ? $services : array() as $service ) {
			$id  = (string) ( $service['id'] ?? '' );
			$url = (string) ( $service['serviceEndpoint'] ?? '' );

			if ( '#atproto_pds' === substr( $id, -12 ) && 0 === strpos( $url, 'https://' ) && false !== wp_parse_url( $url, PHP_URL_HOST ) ) {
				return 'https://' . (string) wp_parse_url( $url, PHP_URL_HOST ) . ( null !== wp_parse_url( $url, PHP_URL_PORT ) ? ':' . (int) wp_parse_url( $url, PHP_URL_PORT ) : '' );
			}
		}

		return self::ENTRYWAY;
	}

	/**
	 * Looks at the account's latest posts for one that already links to the article.
	 *
	 * @param array<string, mixed> $session The open session.
	 * @param string               $url     Address of the article.
	 * @return bool
	 */
	private function already_posted( array $session, string $url ): bool {
		$reply = $this->http(
			'GET',
			add_query_arg(
				array(
					'repo'       => $session['did'],
					'collection' => 'app.bsky.feed.post',
					'limit'      => 20,
				),
				$session['pds'] . '/xrpc/com.atproto.repo.listRecords'
			),
			array( 'headers' => array( 'Authorization' => 'Bearer ' . $session['jwt'] ) )
		);

		if ( ! $reply->is_success() ) {
			return false;
		}

		foreach ( (array) ( $reply->json()['records'] ?? array() ) as $row ) {
			$value = is_array( $row ) ? (array) ( $row['value'] ?? array() ) : array();

			if ( ( $value['embed']['external']['uri'] ?? '' ) === $url || false !== strpos( (string) ( $value['text'] ?? '' ), $url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The link card: title, description and the article's image, uploaded with the post.
	 *
	 * @param array<string, mixed> $session  The open session.
	 * @param Article              $article  The article.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return array<string, mixed>
	 */
	private function card( array $session, Article $article, array $settings ): array {
		unset( $settings );

		$external = array(
			'uri'         => $article->permalink(),
			'title'       => mb_substr( PostText::headline( $article ), 0, 300 ),
			'description' => mb_substr( PostText::summary( $article ), 0, 1000 ),
		);
		$thumb    = $this->upload_thumbnail( $session, $article->thumbnail_url() );

		if ( array() !== $thumb ) {
			$external['thumb'] = $thumb;
		}

		return array(
			'$type'    => 'app.bsky.embed.external',
			'external' => $external,
		);
	}

	/**
	 * Fetches the article's image and uploads it to the account. A card without an image is still posted
	 * when the image cannot be fetched, is not an image or is too large.
	 *
	 * @param array<string, mixed> $session The open session.
	 * @param string               $url     Address of the image.
	 * @return array<mixed> The blob reference, or an empty array.
	 */
	private function upload_thumbnail( array $session, string $url ): array {
		if ( '' === $url ) {
			return array();
		}

		$image = $this->http( 'GET', $url, array( 'raw' => true ) );
		$type  = strtolower( trim( explode( ';', $image->header( 'content-type' ) )[0] ) );

		if ( ! $image->is_success() || '' === $image->body() || ! in_array( $type, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return array();
		}

		if ( strlen( $image->body() ) > $this->limit( 'bluesky_blob_max_bytes', 950000 ) ) {
			return array();
		}

		$upload = $this->http(
			'POST',
			$session['pds'] . '/xrpc/com.atproto.repo.uploadBlob',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $session['jwt'],
					'Content-Type'  => $type,
				),
				'body'    => $image->body(),
			)
		);
		$blob   = $upload->json()['blob'] ?? array();

		return $upload->is_success() && is_array( $blob ) ? $blob : array();
	}

	/**
	 * Creates the post record.
	 *
	 * @param Target               $target  The target.
	 * @param array<string, mixed> $session The open session.
	 * @param array<string, mixed> $record  The post.
	 * @return Outcome
	 */
	private function create( Target $target, array $session, array $record ): Outcome {
		$reply = $this->http(
			'POST',
			$session['pds'] . '/xrpc/com.atproto.repo.createRecord',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $session['jwt'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'repo'       => $session['did'],
						'collection' => 'app.bsky.feed.post',
						'record'     => $record,
					)
				),
			)
		);

		if ( $reply->is_success() && ! empty( $reply->json()['uri'] ) ) {
			return Outcome::sent(
				$target,
				$reply->code(),
				sprintf(
					/* translators: %s: a Bluesky handle such as example-times.bsky.social. */
					__( 'Posted to %s.', 'estasoft-news-distribution' ),
					$target->label()
				),
				(string) $reply->json()['uri']
			);
		}

		return $this->failure( $target, $reply, false );
	}

	/**
	 * Turns a refusal into an outcome with a sentence a person can act on.
	 *
	 * @param Target $target  The target.
	 * @param Reply  $reply   The answer.
	 * @param bool   $sign_in Whether the call was the sign-in.
	 * @return Outcome
	 */
	private function failure( Target $target, Reply $reply, bool $sign_in ): Outcome {
		$detail = $this->clean( (string) ( $reply->json()['message'] ?? $reply->json()['error'] ?? '' ) );

		if ( 429 === $reply->code() ) {
			$reset = (int) $reply->header( 'ratelimit-reset' );
			$wait  = $reply->retry_after();

			if ( 0 === $wait && $reset > Clock::now() ) {
				$wait = $reset - Clock::now();
			}

			if ( $wait > 0 ) {
				$this->state->pause( $this->id(), Clock::now() + min( $wait, HOUR_IN_SECONDS ) );
			}

			return Outcome::failed( $target, __( 'Bluesky asked News Distribution to slow down. It waits and tries again.', 'estasoft-news-distribution' ), 429, true );
		}

		if ( $sign_in && in_array( $reply->code(), array( 400, 401 ), true ) ) {
			return Outcome::failed( $target, __( 'Bluesky did not accept the handle or the app password. Create a new app password and enter both again.', 'estasoft-news-distribution' ), $reply->code(), false );
		}

		return Outcome::failed( $target, OutletHttp::describe_failure( $reply, $this->label(), $detail ), $reply->code(), null );
	}

	/**
	 * The target for a language, when it is complete.
	 *
	 * @param string               $language Language code.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return Target|null
	 */
	private function target_for( string $language, array $settings ): ?Target {
		foreach ( $this->targets( $settings, $this->languages() ) as $target ) {
			if ( $target->is_complete() && $target->carries( $language ) ) {
				return $target;
			}
		}

		return null;
	}
}
