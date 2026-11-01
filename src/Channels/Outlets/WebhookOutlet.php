<?php
/**
 * Signed webhooks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Retry;

defined( 'ABSPATH' ) || exit;

/**
 * Sends each published article to the owner's own endpoints as a JSON POST, with
 * its canonical address in the payload. One endpoint is one row of settings: a
 * name, an address, a signing secret and a switch. The address is a secret
 * because many services put a token in it; it is never shown again after it is
 * saved, and only its host is displayed.
 *
 * Every request is signed so the other side can tell it came from this site:
 * `X-Estasoft-Signature: sha256=` followed by the HMAC-SHA256, keyed with the
 * signing secret, of the timestamp, a full stop and the request body. The
 * timestamp is sent in `X-Estasoft-Timestamp`.
 *
 * A failed request is tried again by the queue, four attempts in all (see Core\Retry).
 */
final class WebhookOutlet extends AbstractOutlet {

	public function id(): string {
		return 'webhook';
	}

	public function label(): string {
		return __( 'Webhooks', 'estasoft-news-distribution' );
	}

	/**
	 * How many endpoints there can be.
	 *
	 * @return int
	 */
	public function slots(): int {
		return max( 1, $this->limit( 'webhook_max_endpoints', 5 ) );
	}

	/**
	 * Settings: for each endpoint a name, an address, a signing secret and a switch.
	 *
	 * @param string[] $languages Language codes the site publishes in.
	 * @return array<string, array<string, mixed>>
	 */
	public function fields( array $languages ): array {
		$fields = array();
		$slots  = $this->slots();

		for ( $number = 1; $number <= $slots; $number++ ) {
			$fields[ 'webhook_' . $number . '_name' ]   = array(
				'type'    => 'string',
				'label'   => __( 'Endpoint name', 'estasoft-news-distribution' ),
				'default' => '',
				'max'     => 80,
			);
			$fields[ 'webhook_' . $number . '_url' ]    = array(
				'type'  => 'secret',
				'label' => __( 'Endpoint address', 'estasoft-news-distribution' ),
				'max'   => 2000,
			);
			$fields[ 'webhook_' . $number . '_secret' ] = array(
				'type'  => 'secret',
				'label' => __( 'Signing secret', 'estasoft-news-distribution' ),
				'max'   => 200,
			);
			$fields[ 'webhook_' . $number . '_on' ]     = array(
				'type'    => 'bool',
				'label'   => __( 'Send articles to this endpoint', 'estasoft-news-distribution' ),
				'default' => false,
			);
		}

		return $fields;
	}

	public function is_on( array $settings ): bool {
		foreach ( $this->targets( $settings, array() ) as $target ) {
			if ( $target->is_on() && $target->is_complete() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One target per endpoint that has an address or a name.
	 *
	 * @param array<string, mixed> $settings  The channel's settings.
	 * @param string[]             $languages Language codes the site publishes in.
	 * @return Target[]
	 */
	public function targets( array $settings, array $languages ): array {
		$targets = array();
		$slots   = $this->slots();

		for ( $number = 1; $number <= $slots; $number++ ) {
			$name    = trim( (string) ( $settings[ 'webhook_' . $number . '_name' ] ?? '' ) );
			$has_url = $this->has_secret( 'webhook_' . $number . '_url' );

			if ( '' === $name && ! $has_url ) {
				continue;
			}

			$targets[] = new Target(
				$this->id(),
				(string) $number,
				'' !== $name ? $name : sprintf(
					/* translators: %d: the number of an endpoint. */
					__( 'Endpoint %d', 'estasoft-news-distribution' ),
					$number
				),
				'',
				$has_url && $this->has_secret( 'webhook_' . $number . '_secret' ),
				! empty( $settings[ 'webhook_' . $number . '_on' ] )
			);
		}

		return $targets;
	}

	/**
	 * What a screen may show of an endpoint's address: the scheme and host, and "/…" when more follows.
	 * The rest of the address can hold a token, so it is never shown.
	 *
	 * @param string $key Endpoint number.
	 * @return string Empty when no address is saved.
	 */
	public function address_hint( string $key ): string {
		$url   = $this->secret( 'webhook_' . $key . '_url' );
		$parts = '' === $url ? false : wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$more = ( isset( $parts['path'] ) && '/' !== $parts['path'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] );

		return strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( $more ? '/…' : '' );
	}

	/**
	 * Endpoints are told about new articles and about removals, so the other side can take an article down.
	 * Edits are not sent.
	 *
	 * @param Event $event The event.
	 * @return bool
	 */
	public function carries( Event $event ): bool {
		return Event::UPDATE !== $event->type();
	}

	public function send( Target $target, Event $event, ?Article $article, array $settings ): Outcome {
		$payload = $this->payload( $event->type(), $event, $article, $settings );
		$id      = hash( 'sha256', home_url() . '|' . $event->post_id() . '|' . $event->type() . '|' . $target->id() );
		$outcome = $this->post( $target, $payload, substr( $id, 0, 32 ) );

		$this->remember( $target, $outcome, $event->post_id(), $event->type() );

		return $outcome;
	}

	public function test( Target $target, array $settings, bool $send ): Outcome {
		$payload = array(
			'event'   => 'ping',
			'sent_at' => Clock::iso( Clock::now() ),
			'site'    => array(
				'name' => $this->context->settings->publication_name(),
				'url'  => home_url( '/' ),
			),
			'message' => __( 'This is a test request from News Distribution. It carries no article.', 'estasoft-news-distribution' ),
		);

		$outcome = $this->post( $target, $payload, substr( hash( 'sha256', 'ping|' . $target->id() . '|' . Clock::now() . '|' . wp_generate_password( 12, false ) ), 0, 32 ) );

		$this->remember( $target, $outcome, 0, 'ping' );

		return $outcome;
	}

	/**
	 * The JSON a webhook receives.
	 *
	 * @param string               $type     Event type.
	 * @param Event                $event    The event.
	 * @param Article|null         $article  The article, or null when it was removed.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return array<string, mixed>
	 */
	public function payload( string $type, Event $event, ?Article $article, array $settings ): array {
		$language = $this->language_of( $event, $article );
		$site     = $this->context->settings->publication_name( $language );
		$saved    = isset( $settings['attribution'] ) && is_array( $settings['attribution'] ) ? $settings['attribution'] : array();

		$payload = array(
			'event'   => $type,
			'sent_at' => Clock::iso( Clock::now() ),
			'site'    => array(
				'name' => $site,
				'url'  => home_url( '/' ),
			),
		);

		if ( null === $article ) {
			$payload['article'] = array(
				'id'       => $event->post_id(),
				'url'      => $event->url(),
				'title'    => $event->title(),
				'language' => $language,
			);

			return $payload;
		}

		$line = Attribution::line( $language, $saved );
		$uuid = (string) get_post_meta( $article->id(), '_estasoft_nd_id', true );

		$payload['article'] = array(
			'id'         => $article->id(),
			'uuid'       => $uuid,
			'url'        => $article->permalink(),
			'title'      => PostText::headline( $article ),
			'excerpt'    => PostText::summary( $article ),
			'language'   => $language,
			'author'     => $article->author_name(),
			'image'      => $article->thumbnail_url(),
			'categories' => $article->categories(),
			'tags'       => $article->tags(),
			'published'  => Clock::iso( $article->published_ts() ),
			'modified'   => Clock::iso( $article->modified_ts() ),
		);

		$payload['attribution'] = array(
			'text' => Attribution::text( $line, $site ),
			'html' => Attribution::html( $line, $site, $article->permalink() ),
			'url'  => $article->permalink(),
		);

		return $payload;
	}

	/**
	 * Signs a body. The other side computes the same value from the timestamp header and the body it received.
	 *
	 * @param int    $timestamp Unix time sent in X-Estasoft-Timestamp.
	 * @param string $body      The request body.
	 * @param string $secret    The signing secret.
	 * @return string "sha256=" and the hex digest.
	 */
	public static function sign( int $timestamp, string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	/**
	 * Posts a payload to one endpoint and turns the answer into an outcome.
	 *
	 * @param Target               $target   The endpoint.
	 * @param array<string, mixed> $payload  The JSON.
	 * @param string               $delivery The delivery id, the same on every retry so the other side can drop a repeat.
	 * @return Outcome
	 */
	private function post( Target $target, array $payload, string $delivery ): Outcome {
		$url    = $this->secret( 'webhook_' . $target->key() . '_url' );
		$secret = $this->secret( 'webhook_' . $target->key() . '_secret' );

		if ( '' === $url || '' === $secret ) {
			return Outcome::failed( $target, __( 'The address or the signing secret is missing. Enter them again.', 'estasoft-news-distribution' ), 0, false );
		}

		if ( 0 !== strpos( $url, 'https://' ) ) {
			return Outcome::failed( $target, __( 'The address must start with https://, so the request is not sent in the clear.', 'estasoft-news-distribution' ), 0, false );
		}

		$time = Clock::now();
		$body = (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$reply = $this->http(
			'POST',
			$url,
			array(
				'headers' => array(
					'Content-Type'         => 'application/json; charset=utf-8',
					'Accept'               => 'application/json',
					'X-Estasoft-Event'     => (string) ( $payload['event'] ?? '' ),
					'X-Estasoft-Delivery'  => $delivery,
					'X-Estasoft-Timestamp' => (string) $time,
					'X-Estasoft-Signature' => self::sign( $time, $body, $secret ),
				),
				'body'    => $body,
			)
		);

		if ( $reply->is_success() ) {
			return Outcome::sent( $target, $reply->code(), $reply->code() . ' ' . get_status_header_desc( $reply->code() ) );
		}

		if ( $reply->code() >= 300 && $reply->code() < 400 ) {
			return Outcome::failed( $target, __( 'The endpoint answered with a redirect. Use its final address, because a signed request is not followed to another address.', 'estasoft-news-distribution' ), $reply->code(), false );
		}

		return Outcome::failed( $target, OutletHttp::describe_failure( $reply, $target->label() ), $reply->code(), null );
	}

	/**
	 * Keeps what the endpoint row shows: the last result, and how many attempts this article has had.
	 *
	 * @param Target  $target  The endpoint.
	 * @param Outcome $outcome What happened.
	 * @param int     $post_id Article ID, or zero for a test.
	 * @param string  $event   Event type, or "ping".
	 * @return void
	 */
	private function remember( Target $target, Outcome $outcome, int $post_id, string $event ): void {
		$before   = $this->state->webhook( $target->key() );
		$attempts = 1;

		if ( $outcome->is_failure() && $post_id > 0 && (int) ( $before['post'] ?? 0 ) === $post_id && 'failed' === ( $before['status'] ?? '' ) && empty( $before['final'] ) ) {
			$attempts = (int) ( $before['attempts'] ?? 0 ) + 1;
		}

		$this->state->record_webhook(
			$target->key(),
			array(
				'status'   => $outcome->is_sent() ? 'sent' : 'failed',
				'time'     => Clock::now(),
				'code'     => $outcome->code(),
				'message'  => $this->clean( $outcome->message() ),
				'event'    => $event,
				'post'     => $post_id,
				'attempts' => $attempts,
				'final'    => $outcome->is_failure() && ( ! $outcome->is_retryable() || $attempts >= Retry::MAX_ATTEMPTS ),
			)
		);
	}
}
