<?php
/**
 * Counts what the assistant has used, and holds it to the owner's limits.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Network\AiBudget;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Today's requests and this month's tokens, kept in one site option. It counts what the
 * provider reports; it never knows a price unless the owner enters one in Settings.
 *
 * The alert for a limit goes through `Core\Alerts` (type `ai_limit`): once at the alert level of the
 * monthly tokens (80%, or the 80 or 90 a network chose), once when the monthly limit is reached and once
 * when the daily limit is reached, each at most once per month or day, and only when the owner chose to be told.
 *
 * On a multisite network every finished request is also added to the network's running total
 * (`Network\AiBudget`), and when that total reaches the network's monthly budget `reached()` says `network`
 * for every site. A site's own monthly limit is held to the network's cap by `Config::monthly_limit()`, so a
 * site that is over a newly lowered cap is paused at once.
 */
final class Usage {

	public const OPTION = 'estasoft_nd_ai_usage';

	/**
	 * Percent of the monthly tokens at which the first alert goes out, when no network chooses another (`Policy::alert_at()`).
	 */
	public const WARN_AT = 80;

	private Plugin $plugin;

	private Config $config;

	private Policy $policy;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->config = $plugin->make( Config::class );
		$this->policy = $plugin->make( Policy::class );
	}

	/**
	 * The stored counters, rolled over to today and this month.
	 *
	 * @return array{day: string, requests: int, month: string, tokens: int, tokens_in: int, tokens_out: int, probe: array<string, mixed>, alerts: array<string, string>}
	 */
	public function state(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$day    = $this->day_key();
		$month  = $this->month_key();

		return array(
			'day'        => $day,
			'requests'   => ( $stored['day'] ?? '' ) === $day ? max( 0, (int) ( $stored['requests'] ?? 0 ) ) : 0,
			'month'      => $month,
			'tokens'     => ( $stored['month'] ?? '' ) === $month ? max( 0, (int) ( $stored['tokens'] ?? 0 ) ) : 0,
			'tokens_in'  => ( $stored['month'] ?? '' ) === $month ? max( 0, (int) ( $stored['tokens_in'] ?? 0 ) ) : 0,
			'tokens_out' => ( $stored['month'] ?? '' ) === $month ? max( 0, (int) ( $stored['tokens_out'] ?? 0 ) ) : 0,
			'probe'      => is_array( $stored['probe'] ?? null ) ? $stored['probe'] : array(),
			'alerts'     => is_array( $stored['alerts'] ?? null ) ? array_map( 'strval', $stored['alerts'] ) : array(),
		);
	}

	/**
	 * What this month's tokens cost at the owner's own prices, or null when no prices are entered.
	 *
	 * It is an estimate from the figures the provider reports and the prices the owner typed in. It is never a bill.
	 *
	 * @return array{amount: float, currency: string}|null
	 */
	public function estimate(): ?array {
		$values = $this->config->values();
		$in     = (string) ( $values['price_in'] ?? '' );
		$out    = (string) ( $values['price_out'] ?? '' );

		if ( '' === $in || '' === $out || ! is_numeric( $in ) || ! is_numeric( $out ) ) {
			return null;
		}

		$state = $this->state();

		return array(
			'amount'   => ( $state['tokens_in'] * (float) $in + $state['tokens_out'] * (float) $out ) / 1000000,
			'currency' => (string) ( $values['currency'] ?? 'USD' ),
		);
	}

	/**
	 * Requests made today.
	 *
	 * @return int
	 */
	public function requests_today(): int {
		return $this->state()['requests'];
	}

	/**
	 * Tokens used this month.
	 *
	 * @return int
	 */
	public function tokens_month(): int {
		return $this->state()['tokens'];
	}

	/**
	 * Which limit is reached: `network` (the network's monthly budget is used, on every site), `month` (this
	 * site's own monthly tokens), `day` (this site's requests today) or an empty string. The network's comes
	 * first, because raising a site's own limit would not help.
	 *
	 * @return string
	 */
	public function reached(): string {
		if ( $this->network_reached() ) {
			return 'network';
		}

		$state = $this->state();

		if ( $state['tokens'] >= $this->config->monthly_limit() ) {
			return 'month';
		}

		return $state['requests'] >= $this->config->daily_limit() ? 'day' : '';
	}

	/**
	 * Whether the network's monthly budget is used. Always false on a single site, and when the network sets no limit.
	 *
	 * @return bool
	 */
	public function network_reached(): bool {
		return $this->policy->network_budget_used();
	}

	/**
	 * When the reached limit lifts, as a Unix time: the start of tomorrow, of next month, or of the network's next month (UTC).
	 *
	 * @param string $which `day`, `month` or `network`.
	 * @return int
	 */
	public function resets_at( string $which ): int {
		if ( 'network' === $which ) {
			return AiBudget::resets_at();
		}

		$now  = ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( Clock::zone() );
		$next = 'month' === $which ? $now->modify( 'first day of next month' )->setTime( 0, 0, 0 ) : $now->modify( 'tomorrow' )->setTime( 0, 0, 0 );

		return $next->getTimestamp();
	}

	/**
	 * Adds one finished request.
	 *
	 * @param int $tokens     Tokens the provider reported in all.
	 * @param int $tokens_in  Of those, the tokens sent, when the provider says.
	 * @param int $tokens_out Of those, the tokens received, when the provider says.
	 * @return void
	 */
	public function record( int $tokens, int $tokens_in = 0, int $tokens_out = 0 ): void {
		$state               = $this->state();
		$state['requests']   = $state['requests'] + 1;
		$state['tokens']     = $state['tokens'] + max( 0, $tokens );
		$state['tokens_in']  = $state['tokens_in'] + max( 0, $tokens_in );
		$state['tokens_out'] = $state['tokens_out'] + max( 0, $tokens_out );

		$this->save( $state );

		// A network keeps one running total of every site's tokens (an atomic add; it never visits a site).
		if ( is_multisite() ) {
			AiBudget::add( get_current_blog_id(), max( 0, $tokens ) );
		}

		$this->maybe_alert( $state );
	}

	/**
	 * Remembers how the last test request went. Nothing about the provider's answer is kept except figures.
	 *
	 * @param array<string, mixed> $probe ok, provider, model, ms, tokens, reason, at.
	 * @return void
	 */
	public function remember_probe( array $probe ): void {
		$state          = $this->state();
		$state['probe'] = array(
			'ok'       => ! empty( $probe['ok'] ),
			'provider' => sanitize_key( (string) ( $probe['provider'] ?? '' ) ),
			'model'    => sanitize_text_field( (string) ( $probe['model'] ?? '' ) ),
			'ms'       => max( 0, (int) ( $probe['ms'] ?? 0 ) ),
			'tokens'   => max( 0, (int) ( $probe['tokens'] ?? 0 ) ),
			'reason'   => sanitize_key( (string) ( $probe['reason'] ?? '' ) ),
			'at'       => Clock::now(),
		);

		$this->save( $state );
	}

	/**
	 * The last test request, or an empty list.
	 *
	 * @return array<string, mixed>
	 */
	public function probe(): array {
		return $this->state()['probe'];
	}

	/**
	 * Today in the site's time zone, as a key.
	 *
	 * @return string
	 */
	private function day_key(): string {
		return wp_date( 'Y-m-d', Clock::now(), Clock::zone() );
	}

	/**
	 * This month in the site's time zone, as a key.
	 *
	 * @return string
	 */
	private function month_key(): string {
		return wp_date( 'Y-m', Clock::now(), Clock::zone() );
	}

	/**
	 * Writes the counters.
	 *
	 * @param array<string, mixed> $state The state to keep.
	 * @return void
	 */
	private function save( array $state ): void {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Sends the limit alert when a threshold has just been crossed and the owner asked to be told.
	 *
	 * @param array<string, mixed> $state The counters after this request.
	 * @return void
	 */
	private function maybe_alert( array $state ): void {
		if ( 'pause_tell' !== $this->config->on_limit() ) {
			return;
		}

		$monthly = $this->config->monthly_limit();
		$daily   = $this->config->daily_limit();
		$warn    = $this->policy->alert_at();
		$alert   = '';
		$message = '';

		if ( $state['tokens'] >= $monthly ) {
			$alert   = 'month100';
			$message = sprintf(
				/* translators: %s: the date suggestions start again, such as "1 Nov". */
				__( 'The monthly token limit of the AI assistant is reached. Suggestions stop until %s.', 'estasoft-news-distribution' ),
				wp_date( 'j M', $this->resets_at( 'month' ), Clock::zone() )
			);
		} elseif ( $state['tokens'] * 100 >= $monthly * $warn ) {
			$alert   = 'month' . $warn;
			$message = sprintf(
				/* translators: 1: percent, 2: tokens used, 3: tokens allowed. */
				__( 'The AI assistant has used %1$d%% of its monthly tokens: %2$s of %3$s.', 'estasoft-news-distribution' ),
				$warn,
				number_format_i18n( $state['tokens'] ),
				number_format_i18n( $monthly )
			);
		} elseif ( $state['requests'] >= $daily ) {
			$alert   = 'day';
			$message = __( 'The daily request limit of the AI assistant is reached. Suggestions start again tomorrow.', 'estasoft-news-distribution' );
		}

		if ( '' === $alert ) {
			return;
		}

		$key  = 'day' === $alert ? $state['day'] : $state['month'];
		$sent = $state['alerts'];

		if ( ( $sent[ $alert ] ?? '' ) === $key ) {
			return;
		}

		$sent[ $alert ]  = $key;
		$state['alerts'] = $sent;
		$this->save( $state );

		$this->plugin->make( Alerts::class )->send( Alerts::AI_LIMIT, array( 'message' => $message ) );
	}
}
