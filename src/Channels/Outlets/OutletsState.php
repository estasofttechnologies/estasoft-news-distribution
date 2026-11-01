<?php
/**
 * What the outlets learned while they ran.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\Outlets;

use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The option `estasoft_nd_outlets_state` (never autoloaded). It holds facts the
 * screens show and the outlets reuse: the last test of each target, the limit a
 * Mastodon server reported, the last result of each webhook, the Medium stories
 * marked as imported, and a pause a service asked for. It never holds a secret:
 * every text written here has been cleaned by the outlet first.
 *
 * It is not part of the channel's settings because the settings keep only the
 * fields a channel declares.
 */
final class OutletsState {

	public const OPTION = 'estasoft_nd_outlets_state';

	/**
	 * The most Medium stories remembered as imported.
	 */
	private const MAX_IMPORTED = 300;

	/**
	 * The most articles remembered in the recent posts list.
	 */
	private const MAX_RECENT = 12;

	/**
	 * The option as read for this request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $data = null;

	/**
	 * The last test of a target a person started.
	 *
	 * @param string $target_id Target id, such as "telegram:en".
	 * @return array{ok: bool, time: int, code: int, message: string}|array{} Empty when never tested.
	 */
	public function test( string $target_id ): array {
		$row = $this->group( 'tests' )[ $target_id ] ?? array();

		if ( ! is_array( $row ) || array() === $row ) {
			return array();
		}

		return array(
			'ok'      => ! empty( $row['ok'] ),
			'time'    => (int) ( $row['time'] ?? 0 ),
			'code'    => (int) ( $row['code'] ?? 0 ),
			'message' => (string) ( $row['message'] ?? '' ),
		);
	}

	/**
	 * Keeps the result of a test.
	 *
	 * @param string $target_id Target id.
	 * @param bool   $ok        Whether the test passed.
	 * @param int    $code      HTTP status, or 0.
	 * @param string $message   What happened. Never a secret.
	 * @return void
	 */
	public function record_test( string $target_id, bool $ok, int $code, string $message ): void {
		$this->put(
			'tests',
			$target_id,
			array(
				'ok'      => $ok,
				'time'    => Clock::now(),
				'code'    => $code,
				'message' => mb_substr( $message, 0, 300 ),
			)
		);
	}

	/**
	 * Forgets the test of a target, for example after its credentials changed.
	 *
	 * @param string $target_id Target id.
	 * @return void
	 */
	public function forget_test( string $target_id ): void {
		$this->drop( 'tests', $target_id );
	}

	/**
	 * The latest delivery to a target, sent or failed.
	 *
	 * @param string $target_id Target id.
	 * @return array{status: string, time: int, code: int, message: string}|array{} Empty when nothing was sent yet.
	 */
	public function delivery( string $target_id ): array {
		$row = $this->group( 'deliveries' )[ $target_id ] ?? array();

		if ( ! is_array( $row ) || empty( $row['status'] ) ) {
			return array();
		}

		return array(
			'status'  => (string) $row['status'],
			'time'    => (int) ( $row['time'] ?? 0 ),
			'code'    => (int) ( $row['code'] ?? 0 ),
			'message' => (string) ( $row['message'] ?? '' ),
		);
	}

	/**
	 * Keeps the latest delivery to a target.
	 *
	 * @param string $target_id Target id.
	 * @param string $status    sent or failed.
	 * @param int    $code      HTTP status, or 0.
	 * @param string $message   What happened. Never a secret.
	 * @return void
	 */
	public function record_delivery( string $target_id, string $status, int $code, string $message ): void {
		$this->put(
			'deliveries',
			$target_id,
			array(
				'status'  => $status,
				'time'    => Clock::now(),
				'code'    => $code,
				'message' => mb_substr( $message, 0, 300 ),
			)
		);
	}

	/**
	 * The articles most recently sent, newest first, each with the outcome for every target it went to.
	 *
	 * @return array<int, array{post: int, title: string, time: int, rows: array<string, array{status: string, time: int, message: string}>}>
	 */
	public function recent(): array {
		$list = array();

		foreach ( $this->group( 'recent' ) as $post => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$list[] = array(
				'post'  => (int) $post,
				'title' => (string) ( $entry['title'] ?? '' ),
				'time'  => (int) ( $entry['time'] ?? 0 ),
				'rows'  => is_array( $entry['rows'] ?? null ) ? $entry['rows'] : array(),
			);
		}

		usort(
			$list,
			static function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		return $list;
	}

	/**
	 * Keeps the outcome of one target for one article, for the recent posts list.
	 *
	 * @param int    $post_id   Article ID.
	 * @param string $title     The article's headline.
	 * @param string $target_id Target id.
	 * @param string $status    sent or failed.
	 * @param string $message   What happened. Never a secret.
	 * @return void
	 */
	public function record_recent( int $post_id, string $title, string $target_id, string $status, string $message ): void {
		$all   = $this->group( 'recent' );
		$key   = (string) $post_id;
		$entry = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : array( 'rows' => array() );

		$entry['title']              = mb_substr( $title, 0, 200 );
		$entry['time']               = Clock::now();
		$entry['rows'][ $target_id ] = array(
			'status'  => $status,
			'time'    => Clock::now(),
			'message' => mb_substr( $message, 0, 300 ),
		);
		$all[ $key ]                 = $entry;

		if ( count( $all ) > self::MAX_RECENT ) {
			uasort(
				$all,
				static function ( $a, $b ): int {
					return (int) ( $b['time'] ?? 0 ) <=> (int) ( $a['time'] ?? 0 );
				}
			);
			$all = array_slice( $all, 0, self::MAX_RECENT, true );
		}

		$this->replace( 'recent', $all );
	}

	/**
	 * What a service told us about its own limits.
	 *
	 * @param string $outlet Outlet id.
	 * @return array{max: int, link: int, source: string, read_at: int, host: string}|array{} Empty when never read.
	 */
	public function limit( string $outlet ): array {
		$row = $this->group( 'limits' )[ $outlet ] ?? array();

		if ( ! is_array( $row ) || empty( $row['max'] ) ) {
			return array();
		}

		return array(
			'max'     => (int) $row['max'],
			'link'    => (int) ( $row['link'] ?? 23 ),
			'source'  => 'server' === ( $row['source'] ?? '' ) ? 'server' : 'default',
			'read_at' => (int) ( $row['read_at'] ?? 0 ),
			'host'    => (string) ( $row['host'] ?? '' ),
		);
	}

	/**
	 * Keeps a limit a service reported, or the default used when it said nothing.
	 *
	 * @param string $outlet Outlet id.
	 * @param int    $max    Largest post, in characters.
	 * @param int    $link   Characters one link counts as.
	 * @param bool   $server True when the service reported it, false when it is the default.
	 * @param string $host   The server the limit was read from.
	 * @return void
	 */
	public function record_limit( string $outlet, int $max, int $link, bool $server, string $host ): void {
		$this->put(
			'limits',
			$outlet,
			array(
				'max'     => $max,
				'link'    => $link,
				'source'  => $server ? 'server' : 'default',
				'read_at' => Clock::now(),
				'host'    => $host,
			)
		);
	}

	/**
	 * Forgets a service's limits, such as after its server address changed: the limit belonged to the old one.
	 *
	 * @param string $outlet Outlet id.
	 * @return void
	 */
	public function forget_limit( string $outlet ): void {
		$this->drop( 'limits', $outlet );
	}

	/**
	 * The public name of the account behind a target, learned when it was tested.
	 *
	 * @param string $target_id Target id.
	 * @return string
	 */
	public function handle( string $target_id ): string {
		return (string) ( $this->group( 'handles' )[ $target_id ] ?? '' );
	}

	/**
	 * Keeps the public name of an account.
	 *
	 * @param string $target_id Target id.
	 * @param string $handle    Such as "@name@server" or "name.bsky.social".
	 * @return void
	 */
	public function record_handle( string $target_id, string $handle ): void {
		$this->put( 'handles', $target_id, mb_substr( $handle, 0, 200 ) );
	}

	/**
	 * What is known about one webhook endpoint.
	 *
	 * @param string $key Endpoint number.
	 * @return array<string, mixed> host, status, time, code, message, attempts, final, post. Empty when nothing is known.
	 */
	public function webhook( string $key ): array {
		$row = $this->group( 'webhooks' )[ $key ] ?? array();

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Keeps what is known about one endpoint, merged with what was there.
	 *
	 * @param string               $key    Endpoint number.
	 * @param array<string, mixed> $values Values to set.
	 * @return void
	 */
	public function record_webhook( string $key, array $values ): void {
		if ( isset( $values['message'] ) ) {
			$values['message'] = mb_substr( (string) $values['message'], 0, 300 );
		}

		$this->put( 'webhooks', $key, array_merge( $this->webhook( $key ), $values ) );
	}

	/**
	 * Forgets an endpoint.
	 *
	 * @param string $key Endpoint number.
	 * @return void
	 */
	public function forget_webhook( string $key ): void {
		$this->drop( 'webhooks', $key );
		$this->drop( 'tests', 'webhook:' . $key );
	}

	/**
	 * When a person marked a Medium story as imported.
	 *
	 * @param int $post_id Article ID.
	 * @return int Unix time, or zero when it is not marked.
	 */
	public function imported( int $post_id ): int {
		return (int) ( $this->group( 'imported' )[ (string) $post_id ] ?? 0 );
	}

	/**
	 * Marks a story as imported by hand, or clears the mark.
	 *
	 * @param int  $post_id  Article ID.
	 * @param bool $imported True to mark, false to clear.
	 * @return void
	 */
	public function mark_imported( int $post_id, bool $imported ): void {
		if ( ! $imported ) {
			$this->drop( 'imported', (string) $post_id );
			return;
		}

		$this->put( 'imported', (string) $post_id, Clock::now() );

		$all = $this->group( 'imported' );

		if ( count( $all ) > self::MAX_IMPORTED ) {
			asort( $all );
			$all = array_slice( $all, -self::MAX_IMPORTED, null, true );
			$this->replace( 'imported', $all );
		}
	}

	/**
	 * When an outlet may talk to its service again, after the service asked it to wait.
	 *
	 * @param string $outlet Outlet id.
	 * @return int Unix time, or zero.
	 */
	public function paused_until( string $outlet ): int {
		return (int) ( $this->group( 'pause' )[ $outlet ] ?? 0 );
	}

	/**
	 * Keeps a pause a service asked for.
	 *
	 * @param string $outlet Outlet id.
	 * @param int    $until  Unix time, or zero to clear it.
	 * @return void
	 */
	public function pause( string $outlet, int $until ): void {
		if ( $until <= Clock::now() ) {
			$this->drop( 'pause', $outlet );
			return;
		}

		$this->put( 'pause', $outlet, $until );
	}

	/**
	 * The switches that were on when the channel was last switched off, so switching it on turns back exactly those.
	 *
	 * @return string[] Setting names such as telegram_on and webhook_2_on. Empty when nothing is remembered.
	 */
	public function remembered(): array {
		$row  = $this->group( 'switch' )['was_on'] ?? array();
		$keys = is_array( $row ) && is_array( $row['keys'] ?? null ) ? $row['keys'] : array();

		return array_values( array_filter( array_map( 'strval', $keys ) ) );
	}

	/**
	 * Keeps the switches that are about to be turned off.
	 *
	 * @param string[] $keys Setting names such as telegram_on.
	 * @return void
	 */
	public function remember( array $keys ): void {
		$this->put(
			'switch',
			'was_on',
			array(
				'keys' => array_values( array_unique( array_map( 'strval', $keys ) ) ),
				'time' => Clock::now(),
			)
		);
	}

	/**
	 * Forgets the remembered switches, once they were turned back on.
	 *
	 * @return void
	 */
	public function forget_remembered(): void {
		$this->drop( 'switch', 'was_on' );
	}

	/**
	 * Forgets the copy read earlier in this request. Tests call it after they reset the option.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->data = null;
	}

	/**
	 * One group of the option.
	 *
	 * @param string $name Group name.
	 * @return array<string, mixed>
	 */
	private function group( string $name ): array {
		if ( null === $this->data ) {
			$raw        = get_option( self::OPTION, array() );
			$this->data = is_array( $raw ) ? $raw : array();
		}

		$group = $this->data[ $name ] ?? array();

		return is_array( $group ) ? $group : array();
	}

	/**
	 * Sets one entry of a group and saves.
	 *
	 * @param string $name  Group name.
	 * @param string $key   Entry key.
	 * @param mixed  $value Entry value.
	 * @return void
	 */
	private function put( string $name, string $key, $value ): void {
		$group         = $this->group( $name );
		$group[ $key ] = $value;

		$this->replace( $name, $group );
	}

	/**
	 * Removes one entry of a group and saves.
	 *
	 * @param string $name Group name.
	 * @param string $key  Entry key.
	 * @return void
	 */
	private function drop( string $name, string $key ): void {
		$group = $this->group( $name );

		if ( ! array_key_exists( $key, $group ) ) {
			return;
		}

		unset( $group[ $key ] );
		$this->replace( $name, $group );
	}

	/**
	 * Replaces a whole group and saves the option.
	 *
	 * @param string               $name  Group name.
	 * @param array<string, mixed> $group New contents.
	 * @return void
	 */
	private function replace( string $name, array $group ): void {
		$this->group( $name );
		$this->data[ $name ] = $group;

		update_option( self::OPTION, $this->data, false );
	}
}
