<?php
/**
 * Self-test: the plugin asks its own feed and sitemap addresses and checks what comes back.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Reports\Parts\Scannable;
use Estasoft\NewsDistribution\Admin\Reports\Parts\ScanControls;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Scans;
use Estasoft\NewsDistribution\Admin\Reports\Parts\SelfTest;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * The test runs from this site's own server, in the queue, when a person presses "Run now" and
 * once an hour. The screen and `GET /reports/self-test` read the last result; they never fetch.
 * It says what the site sends, not what a platform receives.
 */
final class SelfTestReport extends AbstractReport implements Scannable {

	public function name(): string {
		return 'self-test';
	}

	public function title(): string {
		return __( 'Self-test', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Fetches your own feeds and sitemaps from your own server every hour, and checks what comes back.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'platforms';
	}

	public function order(): int {
		return 20;
	}

	public function permission(): string {
		return Access::MANAGE;
	}

	public function scan_units(): int {
		return ( new SelfTest( $this->plugin ) )->units();
	}

	public function scan( callable $progress ): array {
		return ( new SelfTest( $this->plugin ) )->run( $progress );
	}

	public function scan_message( array $result ): string {
		$findings = $this->findings( $result );

		if ( array() === $findings['problems'] ) {
			return __( 'The self-test finished. No problems found.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: number of problems. */
			_n( 'The self-test finished. %s problem found.', 'The self-test finished. %s problems found.', count( $findings['problems'] ), 'estasoft-news-distribution' ),
			number_format_i18n( count( $findings['problems'] ) )
		);
	}

	/**
	 * The last result with what it means: problems, notes and the time of the next run.
	 *
	 * @return array<string, mixed>
	 */
	private function model(): array {
		$scans = $this->plugin->make( Scans::class );
		$last  = $scans->last( $this->name() );
		$data  = null === $last ? array() : $last['data'];

		return array(
			'ran_at'    => null === $last ? 0 : $last['at'],
			'next_ts'   => $this->next_run(),
			'running'   => (string) $scans->running( $this->name() ),
			'addresses' => isset( $data['addresses'] ) ? (array) $data['addresses'] : array(),
			'robots'    => isset( $data['robots'] ) ? (array) $data['robots'] : array(),
			'system'    => isset( $data['system'] ) ? (array) $data['system'] : array(),
			'findings'  => null === $last ? array(
				'problems' => array(),
				'notes'    => array(),
			) : $this->findings( $data ),
		);
	}

	/**
	 * When the next hourly run is booked, as Unix time, or 0.
	 *
	 * @return int
	 */
	private function next_run(): int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}

		$next = as_next_scheduled_action( Scans::SELF_TEST_HOURLY, array(), Queue::GROUP );

		return is_int( $next ) ? $next : 0;
	}

	/**
	 * The figures, for the screen and for `GET /reports/self-test`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$model = $this->model();

		return array(
			'ran'       => $model['ran_at'] > 0,
			'ran_at'    => $model['ran_at'] > 0 ? Clock::iso( $model['ran_at'] ) : null,
			'next_run'  => $model['next_ts'] > 0 ? Clock::iso( $model['next_ts'] ) : null,
			'running'   => '' === $model['running'] ? null : $model['running'],
			'runs_from' => 'own-server',
			'problems'  => count( $model['findings']['problems'] ),
			'findings'  => $model['findings']['problems'],
			'notes'     => $model['findings']['notes'],
			'addresses' => $model['addresses'],
			'robots'    => $model['robots'],
			'system'    => $model['system'],
		);
	}

	/**
	 * What is wrong in a result, as plain sentences, and what is only worth a note.
	 *
	 * @param array<string, mixed> $data A result from SelfTest::run().
	 * @return array{problems: array<int, array{kind: string, text: string}>, notes: string[]}
	 */
	private function findings( array $data ): array {
		$problems         = array();
		$notes            = array();
		$groups           = array();
		$default_language = $this->plugin->languages()->default_language();

		foreach ( (array) ( $data['addresses'] ?? array() ) as $row ) {
			$result = (string) $row['result'];

			if ( 'slow' === $result ) {
				$notes[] = sprintf(
					/* translators: 1: the address name such as "SmartNews feed", 2: how long it took such as "1.9 s". */
					__( 'The %1$s took %2$s, which is slow but not a failure.', 'estasoft-news-distribution' ),
					$this->name_of( $row, $default_language ),
					View::duration( (int) $row['ms'] )
				);
			} elseif ( ! in_array( $result, array( 'valid' ), true ) ) {
				$groups[ $result ][] = $row;
			}
		}

		foreach ( $groups as $result => $rows ) {
			$first = $this->name_of( $rows[0], $default_language );

			if ( count( $rows ) > 2 ) {
				$problems[] = array(
					'kind' => 'address',
					'text' => sprintf(
						/* translators: 1: number of addresses, 2: what is wrong, such as "are sent as a web page", 3: one address name such as "Apple News feed". */
						__( '%1$s addresses %2$s, such as the %3$s.', 'estasoft-news-distribution' ),
						number_format_i18n( count( $rows ) ),
						$this->plural_problem( (string) $result ),
						$first
					),
				);
				continue;
			}

			foreach ( $rows as $row ) {
				$problems[] = array(
					'kind' => 'address',
					'text' => $this->sentence( (string) $result, $row, $default_language ),
				);
			}
		}

		foreach ( (array) ( $data['robots']['flagged'] ?? array() ) as $flag ) {
			$path = trim( (string) preg_replace( '/^[A-Za-z-]+:\s*/', '', (string) $flag['rule'] ) );
			$text = sprintf(
				/* translators: 1: crawler name such as Baiduspider, 2: a path such as /feed/. */
				__( '%1$s is blocked from %2$s, where your feeds and sitemaps live.', 'estasoft-news-distribution' ),
				$flag['crawler'],
				'' === $path ? '/feed/' : $path
			);
			$later = $this->platform_for( (string) $flag['crawler'] );

			if ( '' !== $later ) {
				$text .= ' ' . sprintf(
					/* translators: 1: crawler name such as Baiduspider, 2: platform name such as Baidu. */
					__( 'The %1$s block matters once %2$s is set up.', 'estasoft-news-distribution' ),
					$flag['crawler'],
					$later
				);
			}

			$problems[] = array(
				'kind' => 'robots',
				'text' => $text,
			);
		}

		foreach ( (array) ( $data['system'] ?? array() ) as $part ) {
			if ( 'problem' === $part['state'] ) {
				$problems[] = array(
					'kind' => 'system',
					'text' => (string) $part['text'],
				);
			}
		}

		return array(
			'problems' => $problems,
			'notes'    => $notes,
		);
	}

	/**
	 * The platform a crawler belongs to, when that platform is not set up on this site. Empty when it is.
	 *
	 * @param string $crawler Crawler name.
	 * @return string
	 */
	private function platform_for( string $crawler ): string {
		$map = array(
			'Googlebot'   => array( 'google-news', 'Google News' ),
			'Bingbot'     => array( 'bing', 'Bing' ),
			'YandexBot'   => array( 'yandex', 'Yandex' ),
			'Baiduspider' => array( 'baidu', 'Baidu' ),
		);

		if ( ! isset( $map[ $crawler ] ) ) {
			return '';
		}

		$channel = $this->plugin->registry()->get( $map[ $crawler ][0] );

		return null !== $channel && $channel->is_enabled() ? '' : $map[ $crawler ][1];
	}

	/**
	 * The name of an address in a sentence: the label, and the language when the site has several and it is not the default.
	 *
	 * @param array<string, mixed> $row     A row of the result.
	 * @param string               $default_language Default language code.
	 * @return string
	 */
	private function name_of( array $row, string $default_language ): string {
		$label = (string) $row['label'];

		if ( 'sitemap' === $row['kind'] || (string) $row['language'] !== $default_language ) {
			return $this->with_language( $label, (string) $row['language'] );
		}

		return $label;
	}

	/**
	 * A label with its language, such as "Search sitemap, English".
	 *
	 * @param string $label    The label.
	 * @param string $language Language code.
	 * @return string
	 */
	private function with_language( string $label, string $language ): string {
		if ( count( $this->plugin->languages()->languages() ) < 2 ) {
			return $label;
		}

		return sprintf(
			/* translators: 1: an address name such as "Search sitemap", 2: a language name such as English. */
			__( '%1$s, %2$s', 'estasoft-news-distribution' ),
			$label,
			View::language( $language )
		);
	}

	/**
	 * What is wrong with several addresses at once.
	 *
	 * @param string $result A result code.
	 * @return string
	 */
	private function plural_problem( string $result ): string {
		switch ( $result ) {
			case 'wrong_type':
				return __( 'are sent as a web page or another type that is not XML', 'estasoft-news-distribution' );
			case 'invalid':
				return __( 'are not valid', 'estasoft-news-distribution' );
			case 'not_found':
				return __( 'answer 404', 'estasoft-news-distribution' );
			case 'error':
				return __( 'did not answer', 'estasoft-news-distribution' );
			default:
				return __( 'did not answer as expected', 'estasoft-news-distribution' );
		}
	}

	/**
	 * One sentence about one address that did not pass.
	 *
	 * @param string               $result  A result code.
	 * @param array<string, mixed> $row     A row of the result.
	 * @param string               $default_language Default language code.
	 * @return string
	 */
	private function sentence( string $result, array $row, string $default_language ): string {
		$name = $this->name_of( $row, $default_language );

		switch ( $result ) {
			case 'wrong_type':
				return 'text/html' === $row['type']
					? sprintf(
						/* translators: %s: an address name such as "Apple News feed". */
						__( 'The %s is sent as a web page.', 'estasoft-news-distribution' ),
						$name
					)
					: sprintf(
						/* translators: 1: an address name, 2: a content type such as text/plain. */
						__( 'The %1$s is sent as %2$s, not XML.', 'estasoft-news-distribution' ),
						$name,
						'' === $row['type'] ? __( 'an unknown type', 'estasoft-news-distribution' ) : $row['type']
					);
			case 'invalid':
				return sprintf(
					/* translators: 1: an address name, 2: the first problem found. */
					__( 'The %1$s is not valid: %2$s', 'estasoft-news-distribution' ),
					$name,
					(string) $row['detail']
				);
			case 'not_found':
				return sprintf(
					/* translators: %s: an address name. */
					__( 'The %s answers 404.', 'estasoft-news-distribution' ),
					$name
				);
			case 'server_error':
				return sprintf(
					/* translators: %s: an address name. */
					__( 'The %s answers with a server error.', 'estasoft-news-distribution' ),
					$name
				);
			case 'error':
				return sprintf(
					/* translators: 1: an address name, 2: the reason. */
					__( 'The %1$s did not answer: %2$s', 'estasoft-news-distribution' ),
					$name,
					(string) $row['detail']
				);
			default:
				return sprintf(
					/* translators: 1: an address name, 2: an HTTP status such as 410. */
					__( 'The %1$s answers %2$s, which was not expected.', 'estasoft-news-distribution' ),
					$name,
					number_format_i18n( (int) $row['status'] )
				);
		}
	}

	/**
	 * The controls: the button, the progress and the messages.
	 *
	 * @return ScanControls
	 */
	private function controls(): ScanControls {
		return new ScanControls( $this->plugin, $this );
	}

	/**
	 * "Last run 14:00. Next run 15:00." and the "Run now" button.
	 *
	 * @return string
	 */
	public function actions(): string {
		$model = $this->model();
		$now   = Clock::now();
		$text  = '';

		if ( $model['ran_at'] > 0 ) {
			$same  = wp_date( 'Y-m-d', $model['ran_at'], wp_timezone() ) === wp_date( 'Y-m-d', $now, wp_timezone() );
			$text .= sprintf(
				/* translators: %s: a time such as 14:00, or a day and time such as "19 Oct, 14:00". */
				__( 'Last run %s.', 'estasoft-news-distribution' ),
				$same ? wp_date( 'H:i', $model['ran_at'], wp_timezone() ) : View::day( $model['ran_at'], $now )
			);
		}

		if ( $model['next_ts'] > 0 ) {
			$text .= ( '' === $text ? '' : ' ' ) . sprintf(
				/* translators: %s: a time such as 15:00. */
				__( 'Next run %s.', 'estasoft-news-distribution' ),
				wp_date( 'H:i', $model['next_ts'], wp_timezone() )
			);
		}

		return ( '' === $text ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) ) )
			. $this->controls()->form( __( 'Run now', 'estasoft-news-distribution' ) );
	}

	/**
	 * Runs the "Run now" form post: starts the test in the queue and goes back to the report.
	 *
	 * @return void
	 */
	public function handle(): void {
		$this->controls()->handle();
	}

	/**
	 * The script that follows a running test, when one is running.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$this->controls()->enqueue();
	}

	/**
	 * The result of an address as a word and an icon.
	 *
	 * @param string $result A result code.
	 * @return string
	 */
	private function result_mark( string $result ): string {
		switch ( $result ) {
			case 'valid':
				return View::mark( 'success', __( 'Valid', 'estasoft-news-distribution' ) );
			case 'slow':
				return View::mark( 'warning', __( 'Slower than the others', 'estasoft-news-distribution' ) );
			case 'wrong_type':
				return View::mark( 'danger', __( 'Wrong content type', 'estasoft-news-distribution' ) );
			case 'invalid':
				return View::mark( 'danger', __( 'Not valid', 'estasoft-news-distribution' ) );
			case 'not_found':
				return View::mark( 'danger', __( 'Not found', 'estasoft-news-distribution' ) );
			case 'server_error':
				return View::mark( 'danger', __( 'Server error', 'estasoft-news-distribution' ) );
			case 'error':
				return View::mark( 'danger', __( 'No answer', 'estasoft-news-distribution' ) );
			default:
				return View::mark( 'danger', __( 'Unexpected answer', 'estasoft-news-distribution' ) );
		}
	}

	/**
	 * The Addresses card.
	 *
	 * @param array<string, mixed> $model The model.
	 * @return string
	 */
	private function addresses_card( array $model ): string {
		$default_language = $this->plugin->languages()->default_language();
		$cells            = array();
		$hidden           = 0;

		foreach ( $model['addresses'] as $row ) {
			// A feed that answers well in another language needs no row of its own.
			if ( 'feed' === $row['kind'] && (string) $row['language'] !== $default_language && 'valid' === $row['result'] ) {
				++$hidden;
				continue;
			}

			$name = $this->name_of( $row, $default_language );
			$mark = $this->result_mark( (string) $row['result'] );

			if ( '' !== (string) $row['detail'] && 'valid' !== $row['result'] ) {
				$mark = Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
					$mark . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['detail'] ) )
				);
			}

			$cells[] = array(
				Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $name ) ),
				esc_html( (int) $row['status'] > 0 ? (string) $row['status'] : '—' ),
				'' === (string) $row['type'] ? esc_html( '—' ) : Html::tag( 'code', array(), esc_html( (string) $row['type'] ) ),
				esc_html( (int) $row['status'] > 0 ? View::size( (int) $row['bytes'] ) : '—' ),
				esc_html( View::duration( (int) $row['ms'] ) ),
				$mark,
			);
		}

		$description = __( 'Each one is fetched, then checked for its status, type, size and speed, and that the XML is valid.', 'estasoft-news-distribution' );
		$news        = array_values(
			array_filter(
				$model['addresses'],
				static function ( array $row ): bool {
					return 'google-news' === $row['channel'];
				}
			)
		);

		if ( array() !== $news ) {
			$answering = count(
				array_filter(
					$news,
					static function ( array $row ): bool {
						return 200 === (int) $row['status'];
					}
				)
			);

			if ( 1 === count( $news ) && 1 === $answering ) {
				$description .= ' ' . __( 'The Google News sitemap answers from your server.', 'estasoft-news-distribution' );
			} else {
				$description .= ' ' . ( count( $news ) === $answering
				? sprintf(
					/* translators: %s: number of sitemaps. */
					__( 'All %s Google News sitemaps answer from your server.', 'estasoft-news-distribution' ),
					number_format_i18n( count( $news ) )
				)
				: sprintf(
					/* translators: 1: sitemaps that answer, 2: sitemaps in all. */
					__( '%1$s of %2$s Google News sitemaps answer from your server.', 'estasoft-news-distribution' ),
					number_format_i18n( $answering ),
					number_format_i18n( count( $news ) )
				) );
			}
		}

		if ( $hidden > 0 ) {
			$description .= ' ' . sprintf(
				/* translators: %s: number of feeds. */
				_n( 'A feed also answered well in %s other language and has no row of its own.', 'Feeds also answered well in %s other languages and have no row of their own.', $hidden, 'estasoft-news-distribution' ),
				number_format_i18n( $hidden )
			);
		}

		return Card::flush(
			'addresses',
			__( 'Addresses', 'estasoft-news-distribution' ),
			$description,
			array() === $cells
				? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-card-pad' ), esc_html__( 'No feed or sitemap is turned on, so there is no address to ask.', 'estasoft-news-distribution' ) )
				: Grid::render(
					'estasoft-nd-cols-selftest',
					array(
						__( 'Address', 'estasoft-news-distribution' ),
						__( 'Status', 'estasoft-news-distribution' ),
						__( 'Type', 'estasoft-news-distribution' ),
						__( 'Size', 'estasoft-news-distribution' ),
						__( 'Time', 'estasoft-news-distribution' ),
						__( 'Result', 'estasoft-news-distribution' ),
					),
					$cells,
					__( 'Addresses asked', 'estasoft-news-distribution' )
				)
		);
	}

	/**
	 * What a crawler may read, as a word and an icon.
	 *
	 * @param array<string, mixed> $verdict A verdict from the result.
	 * @return string
	 */
	private function crawler_mark( array $verdict ): string {
		switch ( $verdict['state'] ) {
			case 'allowed':
				return View::mark( 'success', __( 'Allowed', 'estasoft-news-distribution' ) );
			case 'blocked':
				return View::mark(
					'danger',
					sprintf(
						/* translators: %s: a line number in robots.txt. */
						__( 'Blocked by line %s', 'estasoft-news-distribution' ),
						number_format_i18n( (int) $verdict['line'] )
					)
				);
			case 'none':
				return View::mark( 'neutral', __( 'Nothing to check', 'estasoft-news-distribution' ) );
			default:
				return View::mark( 'neutral', __( 'Not read', 'estasoft-news-distribution' ) );
		}
	}

	/**
	 * The card that says what the crawlers may read.
	 *
	 * @param array<string, mixed> $model The model.
	 * @return string
	 */
	private function crawlers_card( array $model ): string {
		$robots = $model['robots'];
		$cells  = array();

		foreach ( (array) ( $robots['crawlers'] ?? array() ) as $crawler ) {
			$cells[] = array(
				Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( (string) $crawler['name'] ) ),
				$this->crawler_mark( (array) $crawler['sitemaps'] ),
				$this->crawler_mark( (array) $crawler['feeds'] ),
			);
		}

		$description = __( 'Read from your robots.txt, for the sitemap and feed addresses above. Both live under /feed/.', 'estasoft-news-distribution' );

		if ( 'none' === ( $robots['state'] ?? '' ) ) {
			$description = __( 'Your site has no robots.txt, so nothing is blocked.', 'estasoft-news-distribution' );
		} elseif ( 'unread' === ( $robots['state'] ?? '' ) ) {
			$description = __( 'The site did not give its robots.txt, so nothing could be read.', 'estasoft-news-distribution' );
		}

		return Card::flush(
			'crawlers',
			__( 'What the crawlers may read', 'estasoft-news-distribution' ),
			$description,
			array() === $cells
				? ''
				: Grid::render(
					'estasoft-nd-cols-crawlers',
					array(
						__( 'Crawler', 'estasoft-news-distribution' ),
						__( 'Sitemaps', 'estasoft-news-distribution' ),
						__( 'Feeds', 'estasoft-news-distribution' ),
					),
					$cells,
					__( 'What each crawler may read', 'estasoft-news-distribution' )
				) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-card-pad' ), esc_html__( 'These follow the standard robots.txt rules. A platform’s crawler may read them a little differently.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The card for a crawler that robots.txt blocks: the two lines that do it, and what to change.
	 *
	 * @param array<string, mixed> $flag    A flagged crawler from the result.
	 * @param array<string, mixed> $model   The model.
	 * @return string
	 */
	private function robots_card( array $flag, array $model ): string {
		$path   = trim( (string) preg_replace( '/^[A-Za-z-]+:\s*/', '', (string) $flag['rule'] ) );
		$path   = '' === $path ? '/feed/' : $path;
		$lines  = array();
		$flags  = array();
		$sample = '/feed/search-sitemap/';

		foreach ( $model['addresses'] as $row ) {
			if ( 'sitemap' === $row['kind'] ) {
				$sample = (string) wp_parse_url( (string) $row['url'], PHP_URL_PATH );
				break;
			}
		}

		if ( (int) $flag['agent_line'] > 0 && (int) $flag['agent_line'] !== (int) $flag['line'] ) {
			$lines[] = (int) $flag['agent_line'] . '  ' . (string) $flag['agent'];
		}

		$lines[] = (int) $flag['line'] . '  ' . (string) $flag['rule'];
		$flags[] = array(
			'line' => count( $lines ),
			'tone' => 'danger',
		);

		return Card::note(
			'robots-' . sanitize_key( (string) $flag['crawler'] ),
			__( 'robots.txt', 'estasoft-news-distribution' ),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: 1: crawler name such as Baiduspider, 2: a path such as /feed/. */
						__( '%1$s is not allowed to read %2$s, so it cannot read your feeds or your sitemaps.', 'estasoft-news-distribution' ),
						$flag['crawler'],
						$path
					)
				)
			)
			. Code::block( implode( "\n", $lines ), __( 'The lines of robots.txt that block the crawler', 'estasoft-news-distribution' ), $flags )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: a line number, 2: an example robots.txt line such as "Allow: /feed/search-sitemap/". */
						__( 'Remove line %1$s, or add an Allow line above it for the sitemap and feed addresses, for example %2$s. News Distribution never edits your robots.txt.', 'estasoft-news-distribution' ),
						number_format_i18n( (int) $flag['line'] ),
						'Allow: ' . $sample
					)
				)
			)
		);
	}

	/**
	 * The card that lists the site's own parts, each marked as tested only when it was.
	 *
	 * @param array<string, mixed> $model The model.
	 * @return string
	 */
	private function system_card( array $model ): string {
		$names = array(
			'rewrite' => __( 'Feed addresses', 'estasoft-news-distribution' ),
			'queue'   => __( 'Background queue', 'estasoft-news-distribution' ),
			'clock'   => __( 'Clock and time zone', 'estasoft-news-distribution' ),
			'cache'   => __( 'Cache', 'estasoft-news-distribution' ),
		);
		$cells = array();

		foreach ( $model['system'] as $part ) {
			switch ( $part['state'] ) {
				case 'passed':
					$mark = StatusView::inline( Status::TEST_PASSED );
					break;
				case 'problem':
					$mark = View::mark( 'danger', __( 'Problem', 'estasoft-news-distribution' ) );
					break;
				default:
					$mark = View::mark( 'neutral', __( 'Not tested', 'estasoft-news-distribution' ) );
			}

			$cells[] = array(
				Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $names[ $part['id'] ] ?? (string) $part['id'] ) ),
				$mark,
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $part['text'] ) ),
			);
		}

		return Card::flush(
			'system',
			__( 'Your site', 'estasoft-news-distribution' ),
			__( 'Test passed appears only for what was really tested in this run.', 'estasoft-news-distribution' ),
			Grid::render(
				'estasoft-nd-cols-system',
				array(
					__( 'Part', 'estasoft-news-distribution' ),
					__( 'Result', 'estasoft-news-distribution' ),
					__( 'What was found', 'estasoft-news-distribution' ),
				),
				$cells,
				__( 'The site’s own parts', 'estasoft-news-distribution' )
			)
		);
	}

	public function render(): string {
		$model = $this->model();
		$ctl   = $this->controls();
		$job   = $ctl->job_in_view();
		$out   = Message::bar(
			'info',
			Html::tag( 'strong', array(), esc_html__( 'This test runs from your own server.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'It shows what your site sends, not what a platform receives. It cannot see a block set at a CDN or firewall in front of your site, and it cannot run a platform’s own crawler.', 'estasoft-news-distribution' )
		);

		$problem = $ctl->problem();

		if ( '' !== $problem ) {
			$out .= Message::text( 'danger', $problem );
		}

		if ( '' !== $job ) {
			$out .= $ctl->progress();
		}

		if ( $model['ran_at'] < 1 ) {
			return $out . ( '' !== $job ? '' : View::lead( 'info', __( 'The self-test has not run yet.', 'estasoft-news-distribution' ), __( 'Press Run now. It asks each feed and sitemap that is on, reads robots.txt, and checks the queue, the clock and the cache.', 'estasoft-news-distribution' ) ) );
		}

		$findings = $model['findings'];
		$count    = count( $findings['problems'] );
		$flagged  = (array) ( $model['robots']['flagged'] ?? array() );
		$texts    = array();

		foreach ( $findings['problems'] as $one ) {
			$texts[] = $one['text'];
		}

		foreach ( $findings['notes'] as $note ) {
			$texts[] = $note;
		}

		if ( $count > 0 ) {
			$out .= View::lead(
				'danger',
				sprintf(
					/* translators: %s: number of problems. */
					_n( '%s problem found.', '%s problems found.', $count, 'estasoft-news-distribution' ),
					number_format_i18n( $count )
				),
				implode( ' ', $texts ),
				Button::link(
					__( 'Show how to fix', 'estasoft-news-distribution' ),
					array() !== $flagged ? '#estasoft-nd-card-robots-' . sanitize_key( (string) $flagged[0]['crawler'] ) : '#estasoft-nd-card-addresses',
					array( 'size' => 'sm' )
				)
			);
		} else {
			$out .= View::lead(
				'success',
				__( 'No problems found.', 'estasoft-news-distribution' ),
				implode( ' ', $findings['notes'] )
			);
		}

		$out .= $this->addresses_card( $model );

		$cards = $this->crawlers_card( $model );

		foreach ( array_slice( $flagged, 0, 2 ) as $flag ) {
			$cards .= $this->robots_card( (array) $flag, $model );
		}

		return $out . Html::tag( 'div', array( 'class' => 'estasoft-nd-notes estasoft-nd-notes--top' ), $cards ) . $this->system_card( $model );
	}
}
