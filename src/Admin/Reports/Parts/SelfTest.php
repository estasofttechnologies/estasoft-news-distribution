<?php
/**
 * The self-test: asks the site's own feed and sitemap addresses, reads robots.txt, and checks the site's own parts.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\ClockCheck;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Frontend\Rewrite;
use Estasoft\NewsDistribution\Integrations\SiteFetch;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * It runs from this site's own server, in the queue, and says so on the screen. It asks only addresses
 * on this site, through `Integrations\SiteFetch`, so a test replaces the answers with the
 * `pre_http_request` filter. It sees what the site sends, not what a platform receives: a block at
 * a CDN or firewall in front of the site is invisible to it.
 *
 * Shape of `run()`:
 *
 *     array(
 *       'ran_at'    => int,   // Unix time the run ended
 *       'addresses' => array( array(
 *         'channel'  => string,  // channel id of the first channel that serves the address
 *         'kind'     => string,  // 'sitemap' or 'feed'
 *         'label'    => string,  // "Google News sitemap", "Microsoft Start feed"
 *         'language' => string,
 *         'url'      => string,
 *         'status'   => int,     // HTTP status, or 0 when the site did not answer
 *         'type'     => string,  // content type without parameters
 *         'bytes'    => int,
 *         'ms'       => int,
 *         'result'   => string,  // valid, slow, wrong_type, invalid, not_found, gone, server_error, unexpected or error
 *         'detail'   => string,  // plain words for a result that is not valid
 *       ) ),
 *       'robots'    => array(
 *         'state'    => string,  // read, none (no robots.txt, so nothing is blocked) or unread
 *         'status'   => int,
 *         'crawlers' => array( array(
 *           'name'     => string,
 *           'sitemaps' => array( 'state' => string, 'line' => int ),  // allowed, blocked, none or unread
 *           'feeds'    => array( 'state' => string, 'line' => int ),
 *         ) ),
 *         'flagged'  => array( array( 'crawler' => string, 'agent_line' => int, 'agent' => string, 'line' => int, 'rule' => string ) ),
 *       ),
 *       'system'    => array( array( 'id' => string, 'state' => string, 'text' => string ) ),  // state: passed, problem or not_tested
 *     )
 */
final class SelfTest {

	/**
	 * The crawlers read from robots.txt, with the name each one goes by.
	 *
	 * @var array<string, string>
	 */
	public const CRAWLERS = array(
		'Googlebot'   => 'Googlebot',
		'Bingbot'     => 'bingbot',
		'YandexBot'   => 'YandexBot',
		'Baiduspider' => 'Baiduspider',
	);

	/**
	 * A reply this much slower than the middle one, and at least this many milliseconds, is called slower than the others.
	 */
	private const SLOW_MS     = 1000;
	private const SLOW_FACTOR = 3;

	/**
	 * The most seconds the whole run may take.
	 */
	private const BUDGET = 120;

	private Plugin $plugin;

	/**
	 * Creates the test.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The addresses the test will ask: each carried language of each feed or sitemap that is on.
	 *
	 * Channels that share one address (Bing and Yandex read the same search sitemap) give one row.
	 *
	 * @return array<int, array{channel: ChannelInterface, kind: string, label: string, language: string, url: string}>
	 */
	public function targets(): array {
		$channels = array();
		$seen     = array();
		$default  = $this->plugin->languages()->default_language();
		$codes    = array_values( array_unique( array_map( 'strval', $this->plugin->languages()->languages() ) ) );

		foreach ( $this->plugin->registry()->pulled() as $channel ) {
			if ( ! $channel->is_enabled() || '' === $channel->feed_slug() || isset( $seen[ $channel->feed_slug() ] ) ) {
				continue;
			}

			if ( ! in_array( $channel->group(), array( Group::SITEMAP, Group::PARTNER, Group::READERS ), true ) ) {
				continue;
			}

			$seen[ $channel->feed_slug() ] = true;
			$channels[]                    = $channel;
		}

		usort(
			$channels,
			static function ( ChannelInterface $a, ChannelInterface $b ): int {
				$first = Group::SITEMAP === $a->group() ? 0 : 1;
				$next  = Group::SITEMAP === $b->group() ? 0 : 1;

				return $first === $next ? $a->order() <=> $b->order() : $first <=> $next;
			}
		);

		$targets = array();

		foreach ( $channels as $channel ) {
			$kind = Group::SITEMAP === $channel->group() ? 'sitemap' : 'feed';

			// A feed is asked in every language it carries; the default language comes first.
			$languages = array_values(
				array_filter(
					$codes,
					static function ( string $code ) use ( $channel ): bool {
						return $channel->accepts_language( $code );
					}
				)
			);

			usort(
				$languages,
				static function ( string $a, string $b ) use ( $default ): int {
					return ( $b === $default ? 1 : 0 ) <=> ( $a === $default ? 1 : 0 );
				}
			);

			foreach ( $languages as $language ) {
				$targets[] = array(
					'channel'  => $channel,
					'kind'     => $kind,
					'label'    => $this->label( $channel, $kind ),
					'language' => $language,
					'url'      => add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . $channel->feed_slug() . '/' ) ),
				);
			}
		}

		return $targets;
	}

	/**
	 * The units of work: each address, robots.txt, and the site's own parts.
	 *
	 * @return int
	 */
	public function units(): int {
		return count( $this->targets() ) + 2;
	}

	/**
	 * Runs the test.
	 *
	 * @param callable $progress Called with the units done so far.
	 * @return array<string, mixed> See the class description.
	 */
	public function run( callable $progress ): array {
		$started   = microtime( true );
		$done      = 0;
		$addresses = array();

		foreach ( $this->targets() as $target ) {
			if ( microtime( true ) - $started > self::BUDGET ) {
				$addresses[] = $this->row( $target, 0, '', 0, 0, 'error', __( 'Not asked: the test ran out of time.', 'estasoft-news-distribution' ) );
			} else {
				$addresses[] = $this->ask( $target );
			}

			$progress( ++$done );
		}

		$addresses = $this->mark_slow( $addresses );
		$robots    = $this->robots( $addresses );

		$progress( ++$done );

		$system = $this->system( $addresses );

		$progress( ++$done );

		return array(
			'ran_at'    => Clock::now(),
			'addresses' => $addresses,
			'robots'    => $robots,
			'system'    => $system,
		);
	}

	/**
	 * The name of an address in the table.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param string           $kind    sitemap or feed.
	 * @return string
	 */
	private function label( ChannelInterface $channel, string $kind ): string {
		if ( 'search-sitemap' === $channel->feed_slug() ) {
			return __( 'Search sitemap', 'estasoft-news-distribution' );
		}

		if ( 'feed-readers' === $channel->id() ) {
			return __( 'Feed for readers', 'estasoft-news-distribution' );
		}

		return sprintf(
			'sitemap' === $kind
				/* translators: %s: a platform name, such as Google News. */
				? __( '%s sitemap', 'estasoft-news-distribution' )
				/* translators: %s: a platform name, such as Microsoft Start. */
				: __( '%s feed', 'estasoft-news-distribution' ),
			$channel->label()
		);
	}

	/**
	 * One row of the result.
	 *
	 * @param array<string, mixed> $target Target from targets().
	 * @param int                  $status HTTP status.
	 * @param string               $type   Content type.
	 * @param int                  $bytes  Size.
	 * @param int                  $ms     Time to answer.
	 * @param string               $result Result code.
	 * @param string               $detail Plain words, or empty.
	 * @return array<string, mixed>
	 */
	private function row( array $target, int $status, string $type, int $bytes, int $ms, string $result, string $detail ): array {
		return array(
			'channel'  => $target['channel']->id(),
			'kind'     => $target['kind'],
			'label'    => $target['label'],
			'language' => $target['language'],
			'url'      => $target['url'],
			'status'   => $status,
			'type'     => $type,
			'bytes'    => $bytes,
			'ms'       => $ms,
			'result'   => $result,
			'detail'   => $detail,
		);
	}

	/**
	 * Asks one address and judges the answer.
	 *
	 * @param array<string, mixed> $target Target from targets().
	 * @return array<string, mixed>
	 */
	private function ask( array $target ): array {
		$begin = microtime( true );
		$reply = SiteFetch::get( (string) $target['url'], 10 );
		$ms    = (int) round( ( microtime( true ) - $begin ) * 1000 );

		if ( ! $reply['ok'] ) {
			return $this->row( $target, 0, '', 0, $ms, 'error', (string) $reply['error'] );
		}

		$status = (int) $reply['status'];
		$type   = strtolower( trim( (string) strtok( (string) ( $reply['headers']['content-type'] ?? '' ), ';' ) ) );
		$bytes  = strlen( (string) $reply['body'] );

		if ( 404 === $status ) {
			return $this->row( $target, $status, $type, $bytes, $ms, 'not_found', __( 'The address answered 404.', 'estasoft-news-distribution' ) );
		}

		if ( 410 === $status ) {
			return $this->row( $target, $status, $type, $bytes, $ms, 'gone', __( 'The address answered 410: the channel does not carry this language.', 'estasoft-news-distribution' ) );
		}

		if ( $status >= 500 ) {
			return $this->row( $target, $status, $type, $bytes, $ms, 'server_error', __( 'The server answered with an error.', 'estasoft-news-distribution' ) );
		}

		if ( 200 !== $status ) {
			return $this->row( $target, $status, $type, $bytes, $ms, 'unexpected', __( 'The address did not answer 200.', 'estasoft-news-distribution' ) );
		}

		if ( ! $this->type_fits( $target['channel'], $type ) ) {
			return $this->row( $target, $status, $type, $bytes, $ms, 'wrong_type', __( 'The address answered with something other than XML.', 'estasoft-news-distribution' ) );
		}

		$checked = $this->check( $target['channel'], (string) $reply['body'], (string) $target['language'] );

		if ( ! $checked['valid'] ) {
			return $this->row( $target, $status, $type, $bytes, $ms, 'invalid', $checked['problem'] );
		}

		return $this->row( $target, $status, $type, $bytes, $ms, 'valid', '' );
	}

	/**
	 * Whether a content type fits what the channel sends: its own type, or plain XML.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @param string           $type    The content type without parameters.
	 * @return bool
	 */
	private function type_fits( ChannelInterface $channel, string $type ): bool {
		$own = strtolower( trim( (string) strtok( $channel->content_type(), ';' ) ) );

		return in_array( $type, array( $own, 'application/xml', 'text/xml' ), true );
	}

	/**
	 * The channel's own check of a document, or a plain XML check when it has none.
	 *
	 * @param ChannelInterface $channel  The channel.
	 * @param string           $body     The document.
	 * @param string           $language Language code.
	 * @return array{valid: bool, problem: string}
	 */
	private function check( ChannelInterface $channel, string $body, string $language ): array {
		if ( method_exists( $channel, 'check_output' ) ) {
			$checked = $channel->check_output( $body, $language );

			return array(
				'valid'   => ! empty( $checked['valid'] ),
				'problem' => (string) ( ( (array) ( $checked['problems'] ?? array() ) )[0] ?? __( 'The document did not pass the check.', 'estasoft-news-distribution' ) ),
			);
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return array(
			'valid'   => false !== $xml,
			'problem' => __( 'The document is not valid XML.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Marks a valid address that answered much more slowly than the middle one.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function mark_slow( array $rows ): array {
		$times = array();

		foreach ( $rows as $row ) {
			if ( 200 === $row['status'] ) {
				$times[] = (int) $row['ms'];
			}
		}

		if ( count( $times ) < 3 ) {
			return $rows;
		}

		sort( $times );

		$middle = $times[ (int) floor( count( $times ) / 2 ) ];

		foreach ( $rows as $index => $row ) {
			if ( 'valid' === $row['result'] && $row['ms'] >= self::SLOW_MS && $row['ms'] >= self::SLOW_FACTOR * max( 1, $middle ) ) {
				$rows[ $index ]['result'] = 'slow';
				$rows[ $index ]['detail'] = __( 'It answered much more slowly than the others.', 'estasoft-news-distribution' );
			}
		}

		return $rows;
	}

	/**
	 * Reads robots.txt and says what each crawler may fetch of the addresses above.
	 *
	 * @param array<int, array<string, mixed>> $addresses The rows of the addresses.
	 * @return array<string, mixed>
	 */
	private function robots( array $addresses ): array {
		$reply = SiteFetch::get( home_url( '/robots.txt' ), 10 );
		$paths = array(
			'sitemaps' => array(),
			'feeds'    => array(),
		);

		foreach ( $addresses as $row ) {
			$parts = wp_parse_url( (string) $row['url'] );
			$path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
			$paths[ 'sitemap' === $row['kind'] ? 'sitemaps' : 'feeds' ][] = $path;
		}

		$robots = array(
			'state'    => 'read',
			'status'   => (int) $reply['status'],
			'crawlers' => array(),
			'flagged'  => array(),
		);

		if ( ! $reply['ok'] || ( $reply['status'] >= 500 ) ) {
			$robots['state'] = 'unread';
		} elseif ( $reply['status'] >= 400 ) {
			// A missing robots.txt blocks nothing.
			$robots['state'] = 'none';
		}

		$file = new Robots( 'read' === $robots['state'] ? (string) $reply['body'] : '' );

		foreach ( self::CRAWLERS as $name => $token ) {
			$verdict = array();

			foreach ( $paths as $column => $list ) {
				$verdict[ $column ] = $this->verdict( $file, $token, $list, $robots['state'] );
			}

			$robots['crawlers'][] = array(
				'name'     => $name,
				'sitemaps' => $verdict['sitemaps'],
				'feeds'    => $verdict['feeds'],
			);

			foreach ( $verdict as $one ) {
				if ( 'blocked' === $one['state'] ) {
					$robots['flagged'][ $name ] = array(
						'crawler'    => $name,
						'agent_line' => $one['agent_line'],
						'agent'      => $file->line( $one['agent_line'] ),
						'line'       => $one['line'],
						'rule'       => $file->line( $one['line'] ),
					);
					break;
				}
			}
		}

		$robots['flagged'] = array_values( $robots['flagged'] );

		return $robots;
	}

	/**
	 * What one crawler may fetch of a list of paths.
	 *
	 * @param Robots   $file    The robots.txt.
	 * @param string   $token   The crawler's name.
	 * @param string[] $paths   Paths with queries.
	 * @param string   $state   read, none or unread.
	 * @return array{state: string, line: int, agent_line: int}
	 */
	private function verdict( Robots $file, string $token, array $paths, string $state ): array {
		if ( 'unread' === $state ) {
			return array(
				'state'      => 'unread',
				'line'       => 0,
				'agent_line' => 0,
			);
		}

		if ( array() === $paths ) {
			return array(
				'state'      => 'none',
				'line'       => 0,
				'agent_line' => 0,
			);
		}

		foreach ( $paths as $path ) {
			$checked = $file->check( $token, $path );

			if ( ! $checked['allowed'] ) {
				return array(
					'state'      => 'blocked',
					'line'       => $checked['line'],
					'agent_line' => $checked['agent_line'],
				);
			}
		}

		return array(
			'state'      => 'allowed',
			'line'       => 0,
			'agent_line' => 0,
		);
	}

	/**
	 * Checks the site's own parts. A part is `passed` only when something tested it.
	 *
	 * @param array<int, array<string, mixed>> $addresses The rows of the addresses.
	 * @return array<int, array{id: string, state: string, text: string}>
	 */
	private function system( array $addresses ): array {
		return array(
			$this->rewrite( $addresses ),
			$this->queue(),
			$this->clock(),
			$this->cache(),
		);
	}

	/**
	 * Whether the stored rules include the rule that sends a feed address to News Distribution.
	 *
	 * The rule is found by where it sends the address, not by its pattern, because the pattern leaves out the feed names
	 * that WordPress and other plugins have already taken.
	 *
	 * @param array<mixed> $rules The stored rewrite rules.
	 * @return bool
	 */
	private static function has_feed_rule( array $rules ): bool {
		foreach ( $rules as $query ) {
			if ( is_string( $query ) && false !== strpos( $query, Rewrite::VAR_FEED . '=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The rewrite rules that give feeds and sitemaps their addresses.
	 *
	 * @param array<int, array<string, mixed>> $addresses The rows of the addresses.
	 * @return array{id: string, state: string, text: string}
	 */
	private function rewrite( array $addresses ): array {
		if ( '' === (string) get_option( 'permalink_structure', '' ) ) {
			return array(
				'id'    => 'rewrite',
				'state' => 'problem',
				'text'  => __( 'Your site uses plain permalinks, so addresses such as /feed/google-news/ cannot answer. Choose another structure in Settings, Permalinks.', 'estasoft-news-distribution' ),
			);
		}

		$rules = get_option( 'rewrite_rules', array() );

		if ( ! is_array( $rules ) || ! self::has_feed_rule( $rules ) ) {
			return array(
				'id'    => 'rewrite',
				'state' => 'problem',
				'text'  => __( 'The rule for feed addresses is missing. Open Settings, Permalinks and save once to rebuild the rules.', 'estasoft-news-distribution' ),
			);
		}

		foreach ( $addresses as $row ) {
			if ( 'valid' === $row['result'] || 'slow' === $row['result'] ) {
				return array(
					'id'    => 'rewrite',
					'state' => 'passed',
					'text'  => __( 'The rule is saved, and a feed address answered with a valid document.', 'estasoft-news-distribution' ),
				);
			}
		}

		return array(
			'id'    => 'rewrite',
			'state' => 'not_tested',
			'text'  => __( 'The rule is saved, but no feed address answered with a valid document, so it was not proved.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Whether the queue picks up work.
	 *
	 * @return array{id: string, state: string, text: string}
	 */
	private function queue(): array {
		if ( ! $this->plugin->queue()->is_available() ) {
			return array(
				'id'    => 'queue',
				'state' => 'problem',
				'text'  => __( 'The background queue is not available, so delivery and checks cannot run.', 'estasoft-news-distribution' ),
			);
		}

		$overdue = function_exists( 'as_get_scheduled_actions' )
			? count(
				(array) as_get_scheduled_actions(
					array(
						'group'        => Queue::GROUP,
						'status'       => 'pending',
						'date'         => Clock::now() - 15 * MINUTE_IN_SECONDS,
						'date_compare' => '<=',
						'per_page'     => 20,
					),
					'ids'
				)
			)
			: 0;

		if ( $overdue > 0 ) {
			return array(
				'id'    => 'queue',
				'state' => 'problem',
				'text'  => sprintf(
					/* translators: %s: number of waiting jobs. */
					_n( '%s job has waited more than 15 minutes. The queue may not be running; check that your site’s scheduled tasks run.', '%s jobs have waited more than 15 minutes. The queue may not be running; check that your site’s scheduled tasks run.', $overdue, 'estasoft-news-distribution' ),
					number_format_i18n( $overdue )
				),
			);
		}

		if ( doing_action( Scans::HOOK ) || doing_action( 'estasoft_nd_self_test_hourly' ) ) {
			return array(
				'id'    => 'queue',
				'state' => 'passed',
				'text'  => __( 'This test ran in the queue, and no job has waited more than 15 minutes.', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'id'    => 'queue',
			'state' => 'not_tested',
			'text'  => __( 'No job has waited more than 15 minutes, but this run did not go through the queue.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The clock and time zone check that Settings, Clock and time zone also uses.
	 *
	 * @return array{id: string, state: string, text: string}
	 */
	private function clock(): array {
		$check = ( new ClockCheck( $this->plugin ) )->run();

		if ( ! empty( $check['ok'] ) ) {
			return array(
				'id'    => 'clock',
				'state' => 'passed',
				'text'  => __( 'The site’s time zone, PHP and the database agree.', 'estasoft-news-distribution' ),
			);
		}

		$title = '';

		foreach ( (array) $check['findings'] as $finding ) {
			if ( 'warning' === $finding['tone'] ) {
				$title = (string) $finding['title'];
				break;
			}
		}

		return array(
			'id'    => 'clock',
			'state' => 'problem',
			'text'  => '' === $title ? __( 'The clocks disagree. Open Settings, Clock and time zone.', 'estasoft-news-distribution' ) : $title,
		);
	}

	/**
	 * Whether a value written to the cache is read back.
	 *
	 * @return array{id: string, state: string, text: string}
	 */
	private function cache(): array {
		$key   = 'selftest:' . wp_generate_password( 10, false, false );
		$calls = 0;
		$make  = static function () use ( &$calls ): string {
			++$calls;

			return 'ok';
		};

		$this->plugin->cache()->remember( $key, MINUTE_IN_SECONDS, $make );
		$this->plugin->cache()->remember( $key, MINUTE_IN_SECONDS, $make );

		if ( 1 === $calls ) {
			return array(
				'id'    => 'cache',
				'state' => 'passed',
				'text'  => wp_using_ext_object_cache()
					? __( 'A value was written and read back. The site has a persistent object cache.', 'estasoft-news-distribution' )
					: __( 'A value was written and read back.', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'id'    => 'cache',
			'state' => 'problem',
			'text'  => __( 'A value written to the cache was not read back, so feeds are built again on every request.', 'estasoft-news-distribution' ),
		);
	}
}
