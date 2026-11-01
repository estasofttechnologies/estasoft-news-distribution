<?php
/**
 * Mastodon.
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
 * Posts a status to the owner's Mastodon server, one account per language. The
 * server address is the same for every language; each token belongs to one
 * account.
 *
 * Servers set their own character limit. The limit is read from the server when
 * the owner connects, and 500 (Mastodon's documented default) is used when the
 * server does not say. Every link counts as 23 characters, whatever its length.
 * Each post carries an Idempotency-Key, which Mastodon remembers for up to an
 * hour, so a retry does not post twice.
 */
final class MastodonOutlet extends AbstractOutlet {

	/**
	 * What a link counts as, in the documentation.
	 */
	private const DEFAULT_LINK = 23;

	/**
	 * The most hashtags added to a post.
	 */
	private const MAX_HASHTAGS = 3;

	public function id(): string {
		return 'mastodon';
	}

	public function label(): string {
		return __( 'Mastodon', 'estasoft-news-distribution' );
	}

	/**
	 * Settings: the switch, the server, one token per language and who sees a post.
	 *
	 * @param string[] $languages Language codes the site publishes in.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( array $languages ): array {
		$fields = array(
			'mastodon_on'     => array(
				'type'    => 'bool',
				'label'   => __( 'Post to Mastodon', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'mastodon_server' => array(
				'type'        => 'string',
				'format'      => 'url',
				'schemes'     => array( 'https' ),
				'label'       => __( 'Server address', 'estasoft-news-distribution' ),
				'default'     => '',
				'max'         => 200,
				// The saved tokens are sent to this address, so only an administrator may change it (see SecretInput::moved).
				'destination' => true,
			),
		);

		foreach ( $languages as $language ) {
			$fields[ 'mastodon_token_' . self::suffix( (string) $language ) ] = array(
				'type'  => 'secret',
				'label' => sprintf(
					/* translators: %s: a language name such as English. */
					__( '%s access token', 'estasoft-news-distribution' ),
					$this->language_name( (string) $language )
				),
				'max'   => 300,
			);
		}

		$fields['mastodon_visibility'] = array(
			'type'    => 'enum',
			'label'   => __( 'Who sees a Mastodon post', 'estasoft-news-distribution' ),
			'default' => 'public',
			'options' => array(
				'public'   => __( 'Everyone (public)', 'estasoft-news-distribution' ),
				'unlisted' => __( 'Everyone, but not in public timelines (unlisted)', 'estasoft-news-distribution' ),
			),
		);

		return $fields;
	}

	public function is_on( array $settings ): bool {
		if ( empty( $settings['mastodon_on'] ) ) {
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
	 * One target per language that has a token.
	 *
	 * @param array<string, mixed> $settings  The channel's settings.
	 * @param string[]             $languages Language codes the site publishes in.
	 * @return Target[]
	 */
	public function targets( array $settings, array $languages ): array {
		$server  = self::server( $settings );
		$host    = (string) wp_parse_url( $server, PHP_URL_HOST );
		$targets = array();

		foreach ( $languages as $language ) {
			$language = (string) $language;

			if ( ! $this->has_secret( 'mastodon_token_' . self::suffix( $language ) ) ) {
				continue;
			}

			$handle    = $this->state->handle( 'mastodon:' . $language );
			$targets[] = new Target( $this->id(), $language, '' !== $handle ? $handle : $host, $language, '' !== $server );
		}

		return $targets;
	}

	/**
	 * The server's address as an origin (scheme and host), or an empty string when it is not a usable address.
	 *
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return string
	 */
	public static function server( array $settings ): string {
		$address = trim( (string) ( $settings['mastodon_server'] ?? '' ) );

		if ( '' !== $address && false === strpos( $address, '://' ) ) {
			$address = 'https://' . $address;
		}

		$parts = wp_parse_url( $address );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return '';
		}

		return 'https://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}

	/**
	 * The limit in force: what the server said when the owner connected, or the documented default.
	 *
	 * @return array{max: int, link: int, source: string}
	 */
	public function limit_info(): array {
		$known = $this->state->limit( $this->id() );

		if ( array() !== $known ) {
			return array(
				'max'    => $known['max'],
				'link'   => $known['link'],
				'source' => $known['source'],
			);
		}

		return array(
			'max'    => $this->limit( 'mastodon_default_max', 500 ),
			'link'   => $this->limit( 'mastodon_link_length', self::DEFAULT_LINK ),
			'source' => 'default',
		);
	}

	/**
	 * Asks the server for its character limit and keeps the answer.
	 *
	 * Mastodon's instance document says it in `configuration.statuses.max_characters`. A server that
	 * does not say gets the documented default of 500, and the screen says that it is a default.
	 *
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return array{max: int, link: int, source: string}
	 */
	public function refresh_limit( array $settings ): array {
		$server = self::server( $settings );

		if ( '' === $server ) {
			return $this->limit_info();
		}

		$max  = 0;
		$link = 0;

		foreach ( array( '/api/v2/instance', '/api/v1/instance' ) as $path ) {
			$reply = $this->http( 'GET', $server . $path, array( 'headers' => array( 'Accept' => 'application/json' ) ) );

			if ( ! $reply->is_success() ) {
				continue;
			}

			$json = $reply->json();
			$max  = (int) ( $json['configuration']['statuses']['max_characters'] ?? $json['max_toot_chars'] ?? 0 );
			$link = (int) ( $json['configuration']['statuses']['characters_reserved_per_url'] ?? 0 );

			if ( $max > 0 ) {
				break;
			}
		}

		$from_server = $max > 0;

		$this->state->record_limit(
			$this->id(),
			$from_server ? $max : $this->limit( 'mastodon_default_max', 500 ),
			$link > 0 ? $link : $this->limit( 'mastodon_link_length', self::DEFAULT_LINK ),
			$from_server,
			(string) wp_parse_url( $server, PHP_URL_HOST )
		);

		return $this->limit_info();
	}

	public function check( Article $article, array $settings ): ValidationResult {
		$result = parent::check( $article, $settings );
		$target = $this->target_for( $article->language(), $settings );

		if ( null === $target ) {
			$result->add(
				$this->context->rules->severity( OutletsChannel::ID, 'post.no_account', 'warn' ),
				'post.no_account',
				sprintf(
					/* translators: %s: a language name such as Kurmanji. */
					__( 'No Mastodon account is set for %s, so this article is not posted to Mastodon.', 'estasoft-news-distribution' ),
					$this->language_name( $article->language() )
				)
			);

			return $result;
		}

		$info     = $this->limit_info();
		$headline = ! empty( $settings['social_headline'] ) ? PostText::headline( $article ) : '';
		$needed   = mb_strlen( $headline ) + $info['link'] + ( '' !== $headline ? 2 : 0 );

		if ( $needed > $info['max'] ) {
			$result->add(
				$this->context->rules->severity( OutletsChannel::ID, 'post.headline_too_long', 'block' ),
				'post.headline_too_long',
				sprintf(
					/* translators: %d: the largest post the Mastodon server allows, in characters. */
					_n(
						'The headline and the link do not fit in a Mastodon post of %d character, and neither is ever cut. Shorten the headline.',
						'The headline and the link do not fit in a Mastodon post of %d characters, and neither is ever cut. Shorten the headline.',
						$info['max'],
						'estasoft-news-distribution'
					),
					$info['max']
				)
			);
		}

		return $result;
	}

	public function send( Target $target, Event $event, ?Article $article, array $settings ): Outcome {
		if ( null === $article ) {
			return Outcome::skipped( $target, __( 'Mastodon posts are not removed from here. Delete a post in Mastodon.', 'estasoft-news-distribution' ) );
		}

		$text = $this->status_text( $article, $settings );
		$key  = hash( 'sha256', home_url() . '|' . $article->id() . '|' . $event->type() . '|' . $target->id() );

		return $this->post( $target, $settings, $text, $this->visibility( $settings ), $article->language(), $key );
	}

	public function test( Target $target, array $settings, bool $send ): Outcome {
		$server = self::server( $settings );
		$token  = $this->secret( 'mastodon_token_' . self::suffix( $target->language() ) );

		if ( '' === $server ) {
			return Outcome::failed( $target, __( 'Enter the server address first.', 'estasoft-news-distribution' ), 0, false );
		}

		if ( '' === $token ) {
			return Outcome::failed( $target, __( 'Paste the access token for this language first.', 'estasoft-news-distribution' ), 0, false );
		}

		$reply = $this->http( 'GET', $server . '/api/v1/accounts/verify_credentials', array( 'headers' => $this->headers( $token ) ) );

		if ( 403 === $reply->code() ) {
			// The check reads the account, which needs its own permission; posting is a different one.
			return Outcome::failed( $target, __( 'Mastodon would not let News Distribution read the account with this token. Create the token with the permissions write:statuses and profile.', 'estasoft-news-distribution' ), 403, false );
		}

		if ( ! $reply->is_success() ) {
			return $this->failure( $target, $reply );
		}

		$name = (string) ( $reply->json()['acct'] ?? $reply->json()['username'] ?? '' );
		$host = (string) wp_parse_url( $server, PHP_URL_HOST );

		if ( '' !== $name ) {
			$this->state->record_handle( $target->id(), '@' . $this->clean( false === strpos( $name, '@' ) ? $name . '@' . $host : $name ) );
		}

		$info = $this->refresh_limit( $settings );

		if ( $send ) {
			return $this->post(
				$target,
				$settings,
				sprintf(
					/* translators: %s: the publication's name. */
					__( 'Test post from %s. News Distribution is connected. You can delete this post.', 'estasoft-news-distribution' ),
					$this->context->settings->publication_name( $target->language() )
				),
				'private',
				$target->language(),
				hash( 'sha256', 'test|' . $target->id() . '|' . Clock::now() . '|' . wp_generate_password( 12, false ) )
			);
		}

		return Outcome::sent(
			$target,
			$reply->code(),
			'server' === $info['source']
				? sprintf(
					/* translators: %d: the largest post the server allows, in characters. */
					_n( 'Mastodon accepted the token. The server allows %d character.', 'Mastodon accepted the token. The server allows %d characters.', $info['max'], 'estasoft-news-distribution' ),
					$info['max']
				)
				: sprintf(
					/* translators: %d: the largest post assumed, in characters. */
					_n( 'Mastodon accepted the token. The server did not say its limit, so %d character is used.', 'Mastodon accepted the token. The server did not say its limit, so %d characters are used.', $info['max'], 'estasoft-news-distribution' ),
					$info['max']
				)
		);
	}

	/**
	 * The text of a post: headline, summary, address and hashtags, shortened to fit.
	 *
	 * The headline and the address are never cut. Mastodon counts every link as the same number of
	 * characters, so the address costs that much however long it is. The summary takes what is left,
	 * then hashtags take what is left of that.
	 *
	 * @param Article              $article  The article.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return string
	 */
	public function status_text( Article $article, array $settings ): string {
		$info     = $this->limit_info();
		$headline = ! empty( $settings['social_headline'] ) ? PostText::headline( $article ) : '';
		$url      = $article->permalink();
		$blocks   = array();
		$count    = $info['link'];

		if ( '' !== $headline ) {
			$blocks[] = $headline;
			$count   += mb_strlen( $headline ) + 2;
		}

		$summary = '';

		if ( ! empty( $settings['social_summary'] ) ) {
			$summary = PostText::shorten( PostText::summary( $article ), max( 0, $info['max'] - $count - 2 ), 'mb_strlen' );
		}

		if ( '' !== $summary ) {
			$blocks[] = $summary;
			$count   += mb_strlen( $summary ) + 2;
		}

		$blocks[] = $url;

		if ( ! empty( $settings['social_hashtags'] ) ) {
			$tags = array_slice( PostText::hashtags( $article, $info['max'] - $count - 2, 'mb_strlen' ), 0, self::MAX_HASHTAGS );

			if ( array() !== $tags ) {
				$blocks[] = implode( ' ', $tags );
			}
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * Posts one status.
	 *
	 * @param Target               $target     The target.
	 * @param array<string, mixed> $settings   The channel's settings.
	 * @param string               $text       The status text.
	 * @param string               $visibility public, unlisted or private.
	 * @param string               $language   Language code of the post.
	 * @param string               $key        The Idempotency-Key.
	 * @return Outcome
	 */
	private function post( Target $target, array $settings, string $text, string $visibility, string $language, string $key ): Outcome {
		$server = self::server( $settings );
		$token  = $this->secret( 'mastodon_token_' . self::suffix( $target->language() ) );

		if ( '' === $server || '' === $token ) {
			return Outcome::failed( $target, __( 'The server address or the token is missing. Enter them again on the Mastodon and Bluesky tab.', 'estasoft-news-distribution' ), 0, false );
		}

		$until = $this->state->paused_until( $this->id() );

		if ( $until > Clock::now() ) {
			return Outcome::failed(
				$target,
				sprintf(
					/* translators: %s: a time of day such as 14:38. */
					__( 'Mastodon asked News Distribution to slow down, so it waits until %s.', 'estasoft-news-distribution' ),
					Clock::format( $until )
				),
				429,
				true
			);
		}

		$headers                    = $this->headers( $token );
		$headers['Idempotency-Key'] = $key;

		$reply = $this->http(
			'POST',
			$server . '/api/v1/statuses',
			array(
				'headers' => $headers,
				'body'    => wp_json_encode(
					array(
						'status'     => $text,
						'visibility' => $visibility,
						'language'   => Catalog::primary( $language ),
					)
				),
			)
		);

		if ( $reply->is_success() && ! empty( $reply->json()['id'] ) ) {
			return Outcome::sent(
				$target,
				$reply->code(),
				sprintf(
					/* translators: %s: a Mastodon account such as @example_times@mastodon.example. */
					__( 'Posted to %s.', 'estasoft-news-distribution' ),
					$target->label()
				),
				(string) ( $reply->json()['url'] ?? $reply->json()['id'] )
			);
		}

		return $this->failure( $target, $reply );
	}

	/**
	 * The request headers every call carries.
	 *
	 * @param string $token The account's access token.
	 * @return array<string, string>
	 */
	private function headers( string $token ): array {
		return array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		);
	}

	/**
	 * The visibility the owner chose, never anything outside the two offered.
	 *
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return string
	 */
	private function visibility( array $settings ): string {
		return 'unlisted' === ( $settings['mastodon_visibility'] ?? 'public' ) ? 'unlisted' : 'public';
	}

	/**
	 * Turns a refusal into an outcome with a sentence a person can act on.
	 *
	 * @param Target $target The target.
	 * @param Reply  $reply  The answer.
	 * @return Outcome
	 */
	private function failure( Target $target, Reply $reply ): Outcome {
		$detail = $this->clean( (string) ( $reply->json()['error'] ?? '' ) );

		if ( 429 === $reply->code() ) {
			$wait = $reply->retry_after();

			if ( 0 === $wait ) {
				$reset = strtotime( $reply->header( 'x-ratelimit-reset' ) );
				$wait  = false === $reset ? 0 : max( 0, $reset - Clock::now() );
			}

			if ( $wait > 0 ) {
				$this->state->pause( $this->id(), Clock::now() + min( $wait, HOUR_IN_SECONDS ) );
			}

			return Outcome::failed( $target, __( 'Mastodon asked News Distribution to slow down. It waits and tries again.', 'estasoft-news-distribution' ), 429, true );
		}

		if ( 401 === $reply->code() ) {
			return Outcome::failed( $target, __( 'Mastodon did not accept the access token. Create a new token and paste it again.', 'estasoft-news-distribution' ), 401, false );
		}

		if ( 403 === $reply->code() ) {
			return Outcome::failed( $target, __( 'The token may not post. Create it with the permission to write statuses (write:statuses).', 'estasoft-news-distribution' ), 403, false );
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
