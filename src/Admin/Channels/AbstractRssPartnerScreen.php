<?php
/**
 * What the Microsoft Start and Yahoo News pages share.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\RssPartner\AbstractRssPartnerChannel;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * A pulled partner feed: one RSS feed per language that the platform fetches on
 * its own schedule. The page shows the feed addresses, what the plugin's own
 * check of the feeds found (nothing is sent anywhere), and how the platform has
 * fetched them. "Test feeds" builds the feeds and reads them back; it is a link
 * with a nonce, so it works without a script.
 */
abstract class AbstractRssPartnerScreen extends AbstractStepScreen {

	/**
	 * The button that checks the feeds.
	 *
	 * @return string Plain text, such as "Test feeds".
	 */
	abstract protected function test_label(): string;

	/**
	 * The channel as a partner feed channel.
	 *
	 * @return AbstractRssPartnerChannel|null
	 */
	protected function partner(): ?AbstractRssPartnerChannel {
		return $this->channel instanceof AbstractRssPartnerChannel ? $this->channel : null;
	}

	public function actions( string $tab ): string {
		return Button::link( $this->test_label(), $this->action_url( 'test' ) );
	}

	protected function known_actions(): array {
		return array( 'test' => 'get' );
	}

	protected function perform( string $action, array $input ): array {
		unset( $input );

		$partner = $this->partner();

		if ( null === $partner || 'test' !== $action ) {
			return array();
		}

		return $this->finish_test( $partner->test() );
	}

	/**
	 * The latest feed check, as it was stored.
	 *
	 * @return array<string, mixed>
	 */
	protected function last_check(): array {
		$partner = $this->partner();

		return null === $partner ? array() : (array) ( $partner->settings()['last_test'] ?? array() );
	}

	/**
	 * Whether the latest check was run and found nothing wrong.
	 *
	 * @return bool
	 */
	protected function check_passed(): bool {
		$check = $this->last_check();

		return isset( $check['languages'] ) && ! empty( $check['ok'] );
	}

	/**
	 * The language names of the feeds, as one phrase such as "English (US) and Arabic".
	 *
	 * @param string[] $codes Language codes.
	 * @return string
	 */
	protected function names( array $codes ): string {
		$names = array();

		foreach ( $codes as $code ) {
			$names[] = Catalog::name( (string) $code );
		}

		return wp_sprintf( '%l', $names );
	}

	/**
	 * A feed address for each language, with what the latest check found.
	 *
	 * @param string $what What the address is, for the Copy button, such as "Microsoft Start feed".
	 * @return string
	 */
	protected function feed_lines( string $what ): string {
		$partner = $this->partner();

		if ( null === $partner ) {
			return '';
		}

		$check = $this->last_check();
		$html  = '';

		foreach ( $partner->carried_languages() as $language ) {
			$found = $check['languages'][ $language ] ?? null;
			$html .= $this->address_line( $language, $partner->feed_url( $language ), $what, $this->feed_facts( $found ) );
		}

		if ( '' === $html ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No language is turned on for this feed in the Languages settings.', 'estasoft-news-distribution' ) );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $html );
	}

	/**
	 * What the latest check found in one feed.
	 *
	 * @param mixed $found The stored result of the language, or null when it was never checked.
	 * @return string Trusted HTML.
	 */
	private function feed_facts( $found ): string {
		if ( ! is_array( $found ) ) {
			return StatusView::inline( Status::OFF, __( 'Not checked yet', 'estasoft-news-distribution' ) );
		}

		if ( ! empty( $found['valid'] ) ) {
			return StatusView::inline(
				Status::CLEAN,
				sprintf(
					/* translators: %s: number of items in the feed. */
					_n( 'Valid RSS, %s item', 'Valid RSS, %s items', (int) $found['items'], 'estasoft-news-distribution' ),
					number_format_i18n( (int) $found['items'] )
				)
			);
		}

		$problems = (array) ( $found['problems'] ?? array() );

		return StatusView::inline( Status::ATTENTION, array() !== $problems ? (string) $problems[0] : __( 'The feed has problems', 'estasoft-news-distribution' ) );
	}

	/**
	 * Why an article was left out of the feeds, as a short phrase.
	 *
	 * @param string $code The finding's code, such as "image.missing".
	 * @return string
	 */
	protected function reason( string $code ): string {
		switch ( $code ) {
			case 'image.missing':
				return __( 'no featured image', 'estasoft-news-distribution' );
			case 'body.min_words':
				$partner = $this->partner();

				return sprintf(
					/* translators: %s: the minimum number of words, such as 150. */
					__( 'body under %s words', 'estasoft-news-distribution' ),
					number_format_i18n( null === $partner ? 0 : (int) ( $partner->settings()['min_words'] ?? 0 ) )
				);
			case 'title.missing':
				return __( 'no title', 'estasoft-news-distribution' );
			case 'link.missing':
				return __( 'no public address', 'estasoft-news-distribution' );
		}

		return __( 'did not pass the checks', 'estasoft-news-distribution' );
	}

	/**
	 * The distinct reasons the latest check left articles out, as one phrase.
	 *
	 * @return string
	 */
	private function reasons(): string {
		$out = array();

		foreach ( (array) ( $this->last_check()['skipped'] ?? array() ) as $item ) {
			$out[ $this->reason( (string) ( $item['code'] ?? '' ) ) ] = true;
		}

		return implode( ', ', array_keys( $out ) );
	}

	/**
	 * A bar when the latest check left articles out.
	 *
	 * @return string
	 */
	protected function skipped_bar(): string {
		$check = $this->last_check();
		$count = (int) ( $check['skipped_total'] ?? 0 );

		if ( $count < 1 ) {
			return '';
		}

		$codes = array();

		foreach ( (array) ( $check['skipped'] ?? array() ) as $item ) {
			$codes[ (string) ( $item['code'] ?? '' ) ] = true;
		}

		$lead = sprintf(
			/* translators: %s: number of articles. */
			_n( '%s article is not in your feed.', '%s articles are not in your feeds.', $count, 'estasoft-news-distribution' ),
			number_format_i18n( $count )
		);
		$text = array( 'image.missing' ) === array_keys( $codes )
			? __( 'They have no featured image, and this platform shows a thumbnail with every headline. Add an image and each article joins the feeds the next time they are built.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: why the articles were left out, such as "body under 150 words". */
				__( 'Left out: %s. Article check shows the reason for each one.', 'estasoft-news-distribution' ),
				$this->reasons()
			);

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html( $lead ) ) . ' ' . esc_html( $text ),
			Button::link( __( 'Review articles', 'estasoft-news-distribution' ), Links::check() )
		);
	}

	/**
	 * "Latest feed check": when, how many items per language and how many articles were left out.
	 *
	 * @param string $title The card's heading.
	 * @return string
	 */
	protected function check_card( string $title ): string {
		$check = $this->last_check();

		if ( ! isset( $check['languages'] ) || ! is_array( $check['languages'] ) ) {
			return Card::note(
				'check',
				$title,
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not checked yet. Press the button at the top to build the feeds and read them back. Nothing is sent anywhere.', 'estasoft-news-distribution' ) )
			);
		}

		$parts = array();

		foreach ( $check['languages'] as $code => $row ) {
			$parts[] = strtoupper( (string) $code ) . ' ' . number_format_i18n( (int) ( $row['items'] ?? 0 ) );
		}

		$items = (int) ( $check['items'] ?? 0 );
		$body  = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html(
				sprintf(
					/* translators: 1: the time of the check, such as 14:31, 2: the languages, such as "English and Arabic". */
					__( 'Checked %1$s, %2$s feeds', 'estasoft-news-distribution' ),
					Clock::format( (int) ( $check['time'] ?? 0 ) ),
					$this->names( array_keys( $check['languages'] ) )
				)
			)
		) . Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
			StatusView::inline(
				! empty( $check['ok'] ) ? Status::CLEAN : Status::ATTENTION,
				sprintf(
					/* translators: 1: number of items, 2: how many each language has, such as "EN 9, KU 4". */
					_n( '%1$s item in the feeds: %2$s', '%1$s items in the feeds: %2$s', $items, 'estasoft-news-distribution' ),
					number_format_i18n( $items ),
					implode( ', ', $parts )
				)
			)
			. $this->skipped_line( $check )
		);

		$count = (int) ( $check['skipped_total'] ?? 0 );

		if ( $count > 0 ) {
			$body .= Html::tag(
				'a',
				array(
					'href'  => Links::check(),
					'class' => 'estasoft-nd-strong',
				),
				esc_html(
					sprintf(
						/* translators: %s: number of articles. */
						_n( 'See the %s article', 'See the %s articles', $count, 'estasoft-news-distribution' ),
						number_format_i18n( $count )
					)
				)
			);
		}

		return Card::note( 'check', $title, Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $body ) );
	}

	/**
	 * The line about articles that were left out.
	 *
	 * @param array<string, mixed> $check The latest check.
	 * @return string
	 */
	private function skipped_line( array $check ): string {
		$count = (int) ( $check['skipped_total'] ?? 0 );

		if ( $count < 1 ) {
			return '';
		}

		return StatusView::inline(
			Status::FAILED,
			sprintf(
				/* translators: 1: number of articles, 2: why, such as "no featured image". */
				_n( '%1$s article skipped: %2$s', '%1$s articles skipped: %2$s', $count, 'estasoft-news-distribution' ),
				number_format_i18n( $count ),
				$this->reasons()
			)
		);
	}

	/**
	 * When the platform last fetched any feed, or 0.
	 *
	 * @return int
	 */
	protected function last_fetch(): int {
		$partner = $this->partner();
		$last    = 0;

		foreach ( null === $partner ? array() : $partner->fetch_report() as $state ) {
			$last = max( $last, (int) $state['last_ts'] );
		}

		return $last;
	}

	/**
	 * "Fetches": whether the platform fetches the feeds on time, when it last did, and how often.
	 *
	 * @return string
	 */
	protected function fetches_card(): string {
		$partner = $this->partner();
		$report  = null === $partner ? array() : $partner->fetch_report();
		$codes   = array_column( $report, 'status' );
		$total   = (int) array_sum( array_column( $report, 'fetches_24h' ) );
		$last    = $this->last_fetch();
		$status  = Status::NEVER;

		foreach ( array( Status::LATE, Status::ON_TIME, Status::NOT_FETCHED ) as $code ) {
			if ( in_array( $code, $codes, true ) ) {
				$status = $code;
				break;
			}
		}

		$label = $this->channel->label();

		if ( $last > 0 ) {
			$text = sprintf(
				/* translators: 1: the platform, such as "Microsoft Start", 2: how long ago, such as "4 mins". */
				__( 'Last fetched by %1$s %2$s ago.', 'estasoft-news-distribution' ),
				$label,
				human_time_diff( $last, Clock::now() )
			) . ' ' . sprintf(
				/* translators: %s: number of requests. */
				_n( '%s request in the last 24 hours.', '%s requests in the last 24 hours.', $total, 'estasoft-news-distribution' ),
				number_format_i18n( $total )
			);
		} else {
			$text = sprintf(
				/* translators: %s: the platform, such as "Microsoft Start". */
				__( '%s has not fetched the feeds yet. It reads them on its own schedule.', 'estasoft-news-distribution' ),
				$label
			);
		}

		return Card::note(
			'fetches',
			__( 'Fetches', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
				StatusView::inline( $status )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
				. Html::tag( 'a', array( 'href' => Links::fetch_monitor() ), esc_html__( 'Open the fetch monitor', 'estasoft-news-distribution' ) )
			)
		);
	}
}
