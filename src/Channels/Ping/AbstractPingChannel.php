<?php
/**
 * Shared behaviour of the pushed channels that only tell another service that something changed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Ping;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\MicrosoftStart\MicrosoftStartChannel;
use Estasoft\NewsDistribution\Core\AddressChange;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * WebSub and IndexNow carry no article content: they send an address and the
 * other side fetches it. Both are sent from the queue, one request per event,
 * and both can wait while Microsoft Start has a new article first.
 */
abstract class AbstractPingChannel extends AbstractChannel {

	public function mode(): string {
		return self::MODE_PUSHED;
	}

	public function group(): string {
		return Group::PUSHES;
	}

	/**
	 * Whether the channel has what it needs to send: a hub address, a key.
	 *
	 * @return bool
	 */
	abstract protected function is_ready(): bool;

	/**
	 * Waits while Microsoft Start has a new article first; otherwise sends at once.
	 * The base class may add its own delay (the release plan), so the longer one wins.
	 *
	 * @param Event $event The event.
	 * @return int
	 */
	public function delay_seconds( Event $event ): int {
		return max( parent::delay_seconds( $event ), MicrosoftStartChannel::exclusive_seconds( $this->context, $event ) );
	}

	/**
	 * With "Only languages turned on in Settings" ticked the site's language
	 * settings decide. Unticked, every language is sent.
	 *
	 * @param string $language Language code.
	 * @return bool
	 */
	public function accepts_language( string $language ): bool {
		if ( empty( $this->settings()['only_enabled_languages'] ) ) {
			return true;
		}

		return parent::accepts_language( $language );
	}

	/**
	 * The language codes this channel sends for, in the order the site lists them.
	 *
	 * @return string[]
	 */
	public function carried_languages(): array {
		$out = array();

		foreach ( $this->context->languages->languages() as $language ) {
			if ( $this->accepts_language( (string) $language ) ) {
				$out[] = (string) $language;
			}
		}

		return $out;
	}

	/**
	 * The four check boxes both pushed channels show under "Choose what is sent".
	 *
	 * @param array{publish: string, update: string, remove: string, languages: string} $help One line of help for each box.
	 * @return array<string, array<string, mixed>>
	 */
	protected function toggle_fields( array $help ): array {
		return array(
			'on_publish'             => array(
				'type'    => 'bool',
				'label'   => __( 'Articles you publish', 'estasoft-news-distribution' ),
				'help'    => $help['publish'],
				'default' => true,
				'control' => 'checkbox',
			),
			'on_update'              => array(
				'type'    => 'bool',
				'label'   => __( 'Articles you update', 'estasoft-news-distribution' ),
				'help'    => $help['update'],
				'default' => true,
				'control' => 'checkbox',
			),
			'on_remove'              => array(
				'type'    => 'bool',
				'label'   => __( 'Articles you remove', 'estasoft-news-distribution' ),
				'help'    => $help['remove'],
				'default' => true,
				'control' => 'checkbox',
			),
			'only_enabled_languages' => array(
				'type'    => 'bool',
				'label'   => __( 'Only languages turned on in Settings', 'estasoft-news-distribution' ),
				'help'    => $help['languages'],
				'default' => true,
				'control' => 'checkbox',
			),
		);
	}

	/**
	 * Whether the person asked for this kind of event to be sent.
	 *
	 * @param Event $event The event.
	 * @return bool
	 */
	protected function event_enabled( Event $event ): bool {
		$settings = $this->settings();

		switch ( $event->type() ) {
			case Event::PUBLISH:
				return ! empty( $settings['on_publish'] );
			case Event::UPDATE:
				return ! empty( $settings['on_update'] );
			case Event::UNPUBLISH:
			case Event::DELETE:
				return ! empty( $settings['on_remove'] );
		}

		return true;
	}

	/**
	 * Whether the last save of an updated article was a minor edit: the date
	 * guard kept the modified date because nothing the owner counts as important
	 * changed. Reads the guard's stored decision, so it needs nothing from the
	 * guard's code and answers false when the guard never ran.
	 *
	 * @param Event $event The event.
	 * @return bool
	 */
	protected function is_minor_edit( Event $event ): bool {
		if ( Event::UPDATE !== $event->type() ) {
			return false;
		}

		$meta = get_post_meta( $event->post_id(), '_estasoft_nd_date_guard', true );

		if ( ! is_array( $meta ) || 'kept' !== ( $meta['decision'] ?? '' ) ) {
			return false;
		}

		// A save that gave the article a new address is news to a crawler, whatever else it changed.
		return ! Plugin::instance()->make( AddressChange::class )->came_with( $event->post_id(), (int) ( $meta['at'] ?? 0 ) );
	}

	/**
	 * The words for an event in the log and on the screens.
	 *
	 * @param string $event Event type as the delivery log stores it.
	 * @return string
	 */
	public static function event_label( string $event ): string {
		switch ( $event ) {
			case Event::PUBLISH:
				return __( 'Published', 'estasoft-news-distribution' );
			case Event::UPDATE:
				return __( 'Updated', 'estasoft-news-distribution' );
			case Event::UNPUBLISH:
				return __( 'Unpublished', 'estasoft-news-distribution' );
			case Event::DELETE:
				return __( 'Deleted', 'estasoft-news-distribution' );
			case 'test':
				return __( 'Test', 'estasoft-news-distribution' );
			case 'catchup':
				return __( 'Catch-up', 'estasoft-news-distribution' );
		}

		return $event;
	}

	/**
	 * Sends a POST and reduces the answer to what the log needs.
	 *
	 * Redirects are not followed: a hub or an endpoint that redirects a POST is
	 * reported as such, because following it could change the request.
	 *
	 * @param string               $url  Address.
	 * @param array<string, mixed> $args wp_remote_post() arguments.
	 * @param bool                 $safe Use the safe variant, which refuses private network addresses. For an address the owner typed.
	 * @return array{code: int, text: string, error: string}
	 */
	protected function post( string $url, array $args, bool $safe ): array {
		$args = array_merge(
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'user-agent'          => $this->user_agent(),
				// A hub's answer is never read past 1 MB.
				'limit_response_size' => 1048576,
			),
			$args
		);

		$response = $safe ? wp_safe_remote_post( $url, $args ) : wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'code'  => 0,
				'text'  => '',
				'error' => $response->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'code'  => $code,
			'text'  => self::reason( $code, (string) wp_remote_retrieve_response_message( $response ) ),
			'error' => '',
		);
	}

	/**
	 * The standard words for an HTTP code, such as "Service Unavailable".
	 *
	 * @param int    $code     HTTP status.
	 * @param string $fallback What the server said, used when WordPress has no words for the code.
	 * @return string
	 */
	public static function reason( int $code, string $fallback = '' ): string {
		$text = get_status_header_desc( $code );

		return '' !== $text ? $text : $fallback;
	}

	/**
	 * What the plugin tells another server about itself.
	 *
	 * @return string
	 */
	protected function user_agent(): string {
		return 'Estasoft News Distribution/' . ESTASOFT_ND_VERSION . '; ' . home_url( '/' );
	}

	/**
	 * Keeps a test in the delivery log. A test has no article, so its row has post 0.
	 *
	 * @param string $language Language code of the address that was sent.
	 * @param bool   $ok       Whether the other side accepted it.
	 * @param int    $code     HTTP status, or 0.
	 * @param string $message  Short detail. Never a secret.
	 * @return void
	 */
	protected function log_test( string $language, bool $ok, int $code, string $message ): void {
		$this->context->log->record( 0, $this->id(), $language, 'test', $ok ? Status::SENT : Status::FAILED, 1, $code, $message );
	}

	/**
	 * Newest delivery-log rows of this channel.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_rows( int $limit = 20 ): array {
		return $this->context->log->query(
			array(
				'channel'  => $this->id(),
				'per_page' => $limit,
			)
		)['rows'];
	}

	/**
	 * Off: Not set up. On but missing a hub or a key: Not set up. Otherwise Needs
	 * attention while the newest row of any article, event or test in the last 24
	 * hours is Retrying or Failed, else Healthy. A delivery that failed and then
	 * went through does not count.
	 *
	 * @return string
	 */
	public function health(): string {
		if ( ! $this->is_enabled() || ! $this->is_ready() ) {
			return Status::NOT_SET_UP;
		}

		$since = Clock::now() - DAY_IN_SECONDS;
		$seen  = array();

		foreach ( $this->recent_rows( 100 ) as $row ) {
			if ( (int) $row['created_ts'] < $since ) {
				break;
			}

			$key = $row['post_id'] . '|' . $row['event'];

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;

			if ( in_array( $row['status'], array( Status::RETRY, Status::FAILED ), true ) ) {
				return Status::ATTENTION;
			}
		}

		return Status::HEALTHY;
	}
}
