<?php
/**
 * AI log: everything the AI suggested, and what a person decided.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Features\Translate;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\Marker;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Operations\PrivacyInventory;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-reports&report=ai-log`.
 *
 * Reads the AI log table only. It never asks a provider anything, and it holds no keys: a row holds the suggestion,
 * the decision, the staff names and which connection answered. Rows older than the chosen number of months are
 * deleted by the daily clean-up (`PrivacyInventory::purge_ai_log()`).
 *
 * The report stays in the list when the assistant is switched off, because the history is still there to read.
 * It is absent only when this WordPress has no AI Client or the log table does not exist.
 */
final class AiLogReport extends AbstractReport {

	/**
	 * Rows shown first, and added by each "Show more".
	 */
	private const STEP = 20;

	/**
	 * The most rows one page shows.
	 */
	private const MOST = 200;

	/**
	 * Features that make suggestions a person decides on.
	 */
	private const SUGGESTING = array( 'fix', 'image', 'summary', 'description', 'caption' );

	/**
	 * Names that older rows, and the sample site's rows, give a feature, and the feature they belong to.
	 */
	private const LEGACY = array(
		'title'             => 'fix',
		'alt_text'          => 'fix',
		'image_helper'      => 'image',
		'translation_draft' => 'translate',
		'news_classifier'   => 'local-news',
	);

	public function name(): string {
		return 'ai-log';
	}

	public function title(): string {
		return __( 'AI log', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Everything the AI suggested, and what a person decided.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 60;
	}

	public function permission(): string {
		return Access::MANAGE;
	}

	public function available(): bool {
		return $this->plugin->make( Availability::class )->supported() && $this->plugin->make( Log::class )->available();
	}

	public function enqueue(): void {
		Assets::screen_style( 'ai' );
		Assets::screen_script( 'ai' );
	}

	/**
	 * The figures, for the screen and for `GET /reports/ai-log`.
	 *
	 * Staff are named by display name only. No email address, no key and no provider secret is in it.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$period = $this->period();
		$limit  = $this->limit();
		$found  = $this->plugin->make( Log::class )->query( $this->query( $period, $limit ) );
		$items  = array();

		foreach ( $found['items'] as $row ) {
			$items[] = array(
				'id'             => $row['id'],
				'at'             => Clock::iso( $row['created'] ),
				'article'        => $row['post_id'] > 0 ? array(
					'id'    => $row['post_id'],
					'title' => $this->title_of( $row['post_id'] ),
				) : null,
				'feature'        => $row['feature'],
				'what'           => $this->what( $row ),
				'provider'       => Parts::connection_name( $this->plugin, $row['connection'] ),
				'decision'       => $row['decision'],
				'decision_label' => Log::decisions()[ $row['decision'] ] ?? $row['decision'],
				'reviewer'       => $this->person( $row ),
			);
		}

		$summary = $this->summary( $period );

		return array(
			'period'   => $period,
			'feature'  => $this->feature(),
			'decision' => $this->decision(),
			'summary'  => $summary,
			'total'    => $found['total'],
			'items'    => $items,
			'marked'   => $this->plugin->make( Marker::class )->count(),
			'months'   => $this->plugin->make( PrivacyInventory::class )->ai_months(),
		);
	}

	/**
	 * What the screen asks the log for.
	 *
	 * @param string $period this_month, last_month or year.
	 * @param int    $limit  Rows.
	 * @return array<string, mixed>
	 */
	private function query( string $period, int $limit ): array {
		$range = $this->range( $period );

		return array(
			'feature'  => $this->names( $this->feature() ),
			'decision' => $this->decision(),
			'from'     => $range['from'],
			'to'       => $range['to'],
			'limit'    => $limit,
		);
	}

	/**
	 * The filter choices in the address, each limited to the values the screen offers.
	 *
	 * @return string
	 */
	private function feature(): string {
		$feature = Query::key( 'feature' );

		return isset( $this->features()[ $feature ] ) ? $feature : '';
	}

	/**
	 * The decision filter.
	 *
	 * @return string
	 */
	private function decision(): string {
		$decision = Query::key( 'decision' );

		return isset( Log::decisions()[ $decision ] ) ? $decision : '';
	}

	/**
	 * The period filter.
	 *
	 * @return string this_month, last_month or year.
	 */
	private function period(): string {
		$period = Query::key( 'period' );

		return isset( $this->periods()[ $period ] ) ? $period : 'this_month';
	}

	/**
	 * How many rows the address asks for.
	 *
	 * @return int
	 */
	private function limit(): int {
		$limit = Query::int( 'limit', self::STEP );

		return max( self::STEP, min( self::MOST, $limit ) );
	}

	/**
	 * The features of the filter, with the words the screen uses.
	 *
	 * @return array<string, string>
	 */
	private function features(): array {
		return array(
			'fix'           => __( 'Fix suggestions', 'estasoft-news-distribution' ),
			'translate'     => __( 'Translation drafts', 'estasoft-news-distribution' ),
			'image'         => __( 'Image helper', 'estasoft-news-distribution' ),
			'summary'       => __( 'Summary suggestions', 'estasoft-news-distribution' ),
			'description'   => __( 'Search descriptions', 'estasoft-news-distribution' ),
			'caption'       => __( 'Image captions', 'estasoft-news-distribution' ),
			'ask'           => __( 'Ask the assistant', 'estasoft-news-distribution' ),
			'platform-help' => __( 'Platform helpers', 'estasoft-news-distribution' ),
			'local-news'    => __( 'Local news and repeats', 'estasoft-news-distribution' ),
			'digest'        => __( 'Weekly summary', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The periods of the filter.
	 *
	 * @return array<string, string>
	 */
	private function periods(): array {
		return array(
			'this_month' => __( 'This month', 'estasoft-news-distribution' ),
			'last_month' => __( 'Last month', 'estasoft-news-distribution' ),
			'year'       => __( 'Last 12 months', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * Unix times a period starts and ends at, in the site's time zone.
	 *
	 * @param string $period this_month, last_month or year.
	 * @return array{from: int, to: int} `to` is 0 when the period runs up to now.
	 */
	private function range( string $period ): array {
		$now   = new \DateTimeImmutable( '@' . Clock::now() );
		$local = $now->setTimezone( Clock::zone() );
		$first = $local->modify( 'first day of this month midnight' );

		if ( 'last_month' === $period ) {
			return array(
				'from' => $local->modify( 'first day of last month midnight' )->getTimestamp(),
				'to'   => $first->getTimestamp(),
			);
		}

		if ( 'year' === $period ) {
			return array(
				'from' => $local->modify( '-12 months' )->getTimestamp(),
				'to'   => 0,
			);
		}

		return array(
			'from' => $first->getTimestamp(),
			'to'   => 0,
		);
	}

	/**
	 * The counts the heading line states.
	 *
	 * @param string $period this_month, last_month or year.
	 * @return array{suggestions: int, used: int, edited: int, dismissed: int, undecided: int, drafts: int, questions: int}
	 */
	private function summary( string $period ): array {
		$range = $this->range( $period );
		$sum   = array(
			'suggestions' => 0,
			'used'        => 0,
			'edited'      => 0,
			'dismissed'   => 0,
			'undecided'   => 0,
			'drafts'      => 0,
			'questions'   => 0,
		);
		$tally = $this->plugin->make( Log::class )->tally( $range['from'], 0 === $range['to'] ? Clock::now() + 1 : $range['to'] );

		foreach ( $tally as $row ) {
			$feature  = self::LEGACY[ $row['feature'] ] ?? $row['feature'];
			$decision = $row['decision'];
			$total    = $row['total'];

			if ( 'translate' === $feature ) {
				$sum['drafts'] += Log::REVIEW === $decision ? $total : 0;
				continue;
			}

			if ( 'ask' === $feature || 'platform-help' === $feature ) {
				$sum['questions'] += Log::ANSWERED === $decision ? $total : 0;
				continue;
			}

			if ( ! in_array( $feature, self::SUGGESTING, true ) || Log::NONE === $decision ) {
				continue;
			}

			$sum['suggestions'] += $total;

			if ( Log::USED === $decision || Log::USED_FEEDS === $decision ) {
				$sum['used'] += $total;
			} elseif ( Log::EDITED === $decision ) {
				$sum['edited'] += $total;
			} elseif ( Log::DISMISSED === $decision ) {
				$sum['dismissed'] += $total;
			} elseif ( Log::PENDING === $decision ) {
				$sum['undecided'] += $total;
			}
		}

		return $sum;
	}

	/**
	 * The heading sentence: counts of suggestions and what was decided, then what is listed but not counted.
	 *
	 * @param string $period this_month, last_month or year.
	 * @return string Plain text.
	 */
	private function sentence( string $period ): string {
		$sum   = $this->summary( $period );
		$start = $this->periods()[ $period ] . ': ';

		if ( 0 === $sum['suggestions'] ) {
			$text = $start . __( 'no suggestions.', 'estasoft-news-distribution' );
		} else {
			$parts = array(
				sprintf(
					/* translators: %s: number of suggestions. */
					_n( '%s suggestion', '%s suggestions', $sum['suggestions'], 'estasoft-news-distribution' ),
					number_format_i18n( $sum['suggestions'] )
				),
				sprintf(
					/* translators: %s: number of suggestions a person used. */
					__( '%s used', 'estasoft-news-distribution' ),
					number_format_i18n( $sum['used'] )
				),
				sprintf(
					/* translators: %s: number of suggestions a person edited and then used. */
					__( '%s edited then used', 'estasoft-news-distribution' ),
					number_format_i18n( $sum['edited'] )
				),
				sprintf(
					/* translators: %s: number of suggestions a person dismissed. */
					__( '%s dismissed', 'estasoft-news-distribution' ),
					number_format_i18n( $sum['dismissed'] )
				),
			);

			if ( $sum['undecided'] > 0 ) {
				$parts[] = sprintf(
					/* translators: %s: number of suggestions nobody has decided on. */
					__( '%s not decided yet', 'estasoft-news-distribution' ),
					number_format_i18n( $sum['undecided'] )
				);
			}

			$text = $start . implode( ', ', $parts ) . '.';
		}

		$other = array();

		if ( $sum['drafts'] > 0 ) {
			$other[] = sprintf(
				/* translators: %s: number of translation drafts. */
				_n( '%s draft is not reviewed yet', '%s drafts are not reviewed yet', $sum['drafts'], 'estasoft-news-distribution' ),
				number_format_i18n( $sum['drafts'] )
			);
		}

		if ( $sum['questions'] > 0 ) {
			$other[] = sprintf(
				/* translators: %s: number of questions answered. */
				_n( '%s question was answered', '%s questions were answered', $sum['questions'], 'estasoft-news-distribution' ),
				number_format_i18n( $sum['questions'] )
			);
		}

		if ( array() !== $other ) {
			$text .= ' ' . sprintf(
				/* translators: %s: for example "1 draft is not reviewed yet and 3 questions were answered". */
				__( 'Translation drafts and questions are listed too, but not counted as suggestions: %s.', 'estasoft-news-distribution' ),
				implode( ' ' . __( 'and', 'estasoft-news-distribution' ) . ' ', $other )
			);
		}

		return $text;
	}

	/**
	 * The title of an article for a row, or a word when it is gone.
	 *
	 * @param int $post_id Article ID.
	 * @return string Plain text.
	 */
	private function title_of( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return __( 'Deleted article', 'estasoft-news-distribution' );
		}

		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );

		return '' === $title ? __( '(no title)', 'estasoft-news-distribution' ) : $title;
	}

	/**
	 * A feature and every name older rows give it, for the filter.
	 *
	 * @param string $feature A feature of the filter, or empty.
	 * @return string[] Names to match; empty for all.
	 */
	private function names( string $feature ): array {
		if ( '' === $feature ) {
			return array();
		}

		return array_merge( array( $feature ), array_keys( self::LEGACY, $feature, true ) );
	}

	/**
	 * What a row with an older feature name did, in words.
	 *
	 * @param string $name The stored feature name.
	 * @return string Plain text.
	 */
	private function legacy_what( string $name ): string {
		$words = array(
			'title'             => __( 'Title suggestions', 'estasoft-news-distribution' ),
			'summary'           => __( 'Summary suggestions', 'estasoft-news-distribution' ),
			'alt_text'          => __( 'Alt text', 'estasoft-news-distribution' ),
			'image_helper'      => __( 'Image helper', 'estasoft-news-distribution' ),
			'translation_draft' => __( 'Translation draft', 'estasoft-news-distribution' ),
			'news_classifier'   => __( 'Local news and repeats', 'estasoft-news-distribution' ),
		);

		return $words[ $name ] ?? $name;
	}

	/**
	 * What the AI did in a row, in the words of the log.
	 *
	 * @param array<string, mixed> $row A log row.
	 * @return string Plain text.
	 */
	private function what( array $row ): string {
		$data = (array) $row['data'];

		if ( isset( self::LEGACY[ $row['feature'] ] ) ) {
			return $this->legacy_what( (string) $row['feature'] );
		}

		switch ( $row['feature'] ) {
			case 'fix':
				$shown = count( (array) ( $data['suggestions'] ?? array() ) );

				return sprintf(
					/* translators: %d: number of title suggestions shown. */
					_n( 'Title suggestions, %d shown', 'Title suggestions, %d shown', $shown, 'estasoft-news-distribution' ),
					$shown
				);
			case 'image':
				return $this->image_what( (array) ( $data['parts'] ?? array() ) );
			case 'summary':
				// Rows from the sample world and older versions name this feature but hold no list.
				if ( ! isset( $data['suggestions'] ) ) {
					return $this->legacy_what( 'summary' );
				}

				$shown = count( (array) $data['suggestions'] );

				return sprintf(
					/* translators: %d: number of summary suggestions shown. */
					_n( 'Summary suggestions, %d shown', 'Summary suggestions, %d shown', $shown, 'estasoft-news-distribution' ),
					$shown
				);
			case 'description':
				$shown = count( (array) ( $data['suggestions'] ?? array() ) );

				return sprintf(
					/* translators: %d: number of search description suggestions shown. */
					_n( 'Search description suggestions, %d shown', 'Search description suggestions, %d shown', $shown, 'estasoft-news-distribution' ),
					$shown
				);
			case 'caption':
				$shown = count( (array) ( $data['suggestions'] ?? array() ) );

				return sprintf(
					/* translators: %d: number of image caption suggestions shown. */
					_n( 'Image caption suggestions, %d shown', 'Image caption suggestions, %d shown', $shown, 'estasoft-news-distribution' ),
					$shown
				);
			case 'translate':
				return sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( '%s translation draft', 'estasoft-news-distribution' ),
					View::language_short( (string) ( $data['language'] ?? '' ) )
				);
			case 'ask':
				return __( 'A question to the assistant', 'estasoft-news-distribution' );
			case 'platform-help':
				return 'email' === ( $data['mode'] ?? '' ) ? __( 'An email to the platform, drafted', 'estasoft-news-distribution' ) : __( 'A platform check, explained', 'estasoft-news-distribution' );
			case 'local-news':
				return __( 'Local news and repeats', 'estasoft-news-distribution' );
			case 'digest':
				return __( 'Weekly summary', 'estasoft-news-distribution' );
			default:
				return (string) $row['feature'];
		}
	}

	/**
	 * What the image helper looked at.
	 *
	 * @param array<string, mixed> $parts Which parts it suggested: photo, focal, alt.
	 * @return string Plain text.
	 */
	private function image_what( array $parts ): string {
		$names = array(
			'photo' => __( 'photo check', 'estasoft-news-distribution' ),
			'focal' => __( 'focal point', 'estasoft-news-distribution' ),
			'alt'   => __( 'alt text', 'estasoft-news-distribution' ),
		);
		$found = array();

		foreach ( $names as $key => $name ) {
			if ( isset( $parts[ $key ] ) ) {
				$found[] = $name;
			}
		}

		if ( array() === $found ) {
			return __( 'Image helper', 'estasoft-news-distribution' );
		}

		$text = implode( ', ', $found );

		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/**
	 * The person a row names: who decided, or who the draft was assigned to. Null when no person is involved.
	 *
	 * @param array<string, mixed> $row A log row.
	 * @return string|null Display name, with "(assigned)" for a draft waiting for its reviewer.
	 */
	private function person( array $row ): ?string {
		if ( $row['reviewer_id'] < 1 ) {
			return null;
		}

		$user = get_userdata( (int) $row['reviewer_id'] );
		$name = $user instanceof \WP_User ? (string) $user->display_name : __( 'A former user', 'estasoft-news-distribution' );

		if ( Log::ANSWERED === $row['decision'] || Log::NONE === $row['decision'] || Log::AUTO === $row['decision'] ) {
			return null;
		}

		return Log::REVIEW === $row['decision']
			? sprintf(
				/* translators: %s: a person's name. */
				__( '%s (assigned)', 'estasoft-news-distribution' ),
				$name
			)
			: $name;
	}

	/**
	 * When a row happened: the time today, "Yesterday", or the day.
	 *
	 * @param int $timestamp Unix time.
	 * @return string Plain text.
	 */
	private function when( int $timestamp ): string {
		$today = Clock::format( Clock::now(), 'Y-m-d' );
		$day   = Clock::format( $timestamp, 'Y-m-d' );

		if ( $day === $today ) {
			return Times::clock( $timestamp );
		}

		if ( Clock::format( Clock::now() - DAY_IN_SECONDS, 'Y-m-d' ) === $day ) {
			return __( 'Yesterday', 'estasoft-news-distribution' );
		}

		return wp_date( 'j M', $timestamp, Clock::zone() );
	}

	/**
	 * The mark in the Decision column.
	 *
	 * @param string $decision A decision.
	 * @return string Escaped HTML.
	 */
	private function decision_mark( string $decision ): string {
		$label = Log::decisions()[ $decision ] ?? $decision;
		$tone  = array(
			Log::USED       => 'success',
			Log::USED_FEEDS => 'success',
			Log::EDITED     => 'success',
			Log::APPROVED   => 'success',
			Log::AUTO       => 'success',
			Log::ANSWERED   => 'info',
			Log::REVIEW     => 'info',
			Log::COPIED     => 'info',
			Log::PENDING    => 'wait',
		);

		return isset( $tone[ $decision ] )
			? View::mark( $tone[ $decision ], $label )
			: Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $label ) );
	}

	/**
	 * One row of the table.
	 *
	 * @param array<string, mixed> $row A log row.
	 * @return array<int, string> Cells.
	 */
	private function cells( array $row ): array {
		$post     = (int) $row['post_id'];
		$provider = Parts::connection_name( $this->plugin, (string) $row['connection'] );
		$person   = $this->person( $row );
		$title    = $post > 0 ? $this->title_of( $post ) : '';
		$article  = '';

		if ( $post > 0 ) {
			$article = null === get_post( $post )
				? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $title ) )
				: Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-strong',
						'href'  => Links::check( $post ),
						'dir'   => 'auto',
					),
					esc_html( $title )
				);
		} else {
			$article = Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not about one article', 'estasoft-news-distribution' ) );
		}

		$what = Html::tag( 'span', array(), esc_html( $this->what( $row ) ) );

		if ( '' !== $provider ) {
			$what .= Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: a provider name such as Anthropic. */
						__( 'through %s', 'estasoft-news-distribution' ),
						$provider
					)
				)
			);
		}

		return array(
			Html::tag(
				'span',
				array(
					'class' => 'estasoft-nd-muted',
					'title' => Clock::datetime( (int) $row['created'] ),
				),
				esc_html( $this->when( (int) $row['created'] ) )
			),
			$article,
			Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $what ),
			$this->decision_mark( (string) $row['decision'] ),
			null === $person ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), '—' ) : esc_html( $person ),
		);
	}

	/**
	 * The three filters. Each change is sent at once with a script; without one the Apply button sends them.
	 *
	 * @param string $feature  The feature filter.
	 * @param string $decision The decision filter.
	 * @param string $period   The period filter.
	 * @return string Escaped HTML.
	 */
	private function filters( string $feature, string $decision, string $period ): string {
		$features  = array( '' => __( 'All features', 'estasoft-news-distribution' ) ) + $this->features();
		$decisions = array( '' => __( 'All decisions', 'estasoft-news-distribution' ) ) + Log::decisions();

		return Html::tag(
			'form',
			array(
				'method'         => 'get',
				'action'         => admin_url( 'admin.php' ),
				'class'          => 'estasoft-nd-ai-filters',
				'data-ai-filter' => '1',
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'page',
					'value' => 'estasoft-nd-reports',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'report',
					'value' => $this->name(),
				)
			)
			. CommandBar::select( 'feature', __( 'Feature', 'estasoft-news-distribution' ), $features, $feature )
			. CommandBar::select( 'decision', __( 'Decision', 'estasoft-news-distribution' ), $decisions, $decision )
			. CommandBar::select( 'period', __( 'Period', 'estasoft-news-distribution' ), $this->periods(), $period )
			. Button::submit( __( 'Apply', 'estasoft-news-distribution' ), array( 'class' => 'estasoft-nd-ai-nojs' ) )
		);
	}

	public function render(): string {
		$feature  = $this->feature();
		$decision = $this->decision();
		$period   = $this->period();
		$limit    = $this->limit();
		$found    = $this->plugin->make( Log::class )->query( $this->query( $period, $limit ) );
		$rows     = array();

		foreach ( $found['items'] as $row ) {
			$rows[] = $this->cells( $row );
		}

		$shown = count( $rows );
		$more  = $found['total'] > $shown
			? Button::link(
				sprintf(
					/* translators: %d: number of entries. */
					__( 'Show %d more', 'estasoft-news-distribution' ),
					self::STEP
				),
				$this->url(
					array(
						'feature'  => '' === $feature ? null : $feature,
						'decision' => '' === $decision ? null : $decision,
						'period'   => $period,
						'limit'    => min( self::MOST, $limit + self::STEP ),
					)
				),
				array( 'size' => 'sm' )
			)
			: '';

		$table = array() === $rows
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-ai-empty' ), esc_html__( 'No entries match.', 'estasoft-news-distribution' ) )
			: Parts::fit(
				Grid::render(
					'estasoft-nd-cols-ai-log',
					array(
						__( 'When', 'estasoft-news-distribution' ),
						__( 'Article', 'estasoft-news-distribution' ),
						__( 'What the AI did', 'estasoft-news-distribution' ),
						__( 'Decision', 'estasoft-news-distribution' ),
						__( 'Reviewed by', 'estasoft-news-distribution' ),
					),
					$rows,
					__( 'What the AI suggested and what a person decided', 'estasoft-news-distribution' )
				),
				__( 'What the AI suggested and what a person decided', 'estasoft-news-distribution' )
			);

		$footer = array() === $rows ? '' : Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-table-footer' ),
			View::footer(
				sprintf(
					/* translators: 1: entries shown, 2: entries in all. */
					_n( 'Showing %1$s of %2$s entry, newest first.', 'Showing %1$s of %2$s entries, newest first.', $found['total'], 'estasoft-news-distribution' ),
					number_format_i18n( $shown ),
					number_format_i18n( $found['total'] )
				),
				$more
			)
		);

		$card = Card::flush(
			'ai-log',
			__( 'AI log', 'estasoft-news-distribution' ),
			$this->sentence( $period ),
			$this->filters( $feature, $decision, $period ) . $table . $footer
		);

		return $card . Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-notes' ), $this->labels() . $this->holds() );
	}

	/**
	 * "Content labels": how many articles carry the AI-assisted mark and how many drafts wait.
	 *
	 * @return string Escaped HTML.
	 */
	private function labels(): string {
		$marked = $this->plugin->make( Marker::class )->count();
		$drafts = count( $this->plugin->make( Translate::class )->unreviewed( self::MOST ) );
		$lines  = array(
			array(
				'level' => 'pass',
				'text'  => sprintf(
					/* translators: %s: number of articles. */
					_n( '%s article marked AI-assisted', '%s articles marked AI-assisted', $marked, 'estasoft-news-distribution' ),
					number_format_i18n( $marked )
				),
			),
		);

		$body = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			esc_html__( 'Articles with AI-written text are marked AI-assisted automatically, and a person can remove the mark only by editing the article’s content label. The reviewer is recorded in this log.', 'estasoft-news-distribution' )
		) . Parts::ticks( $lines );

		if ( $drafts > 0 ) {
			$body .= Parts::ticks(
				array(
					array(
						'level' => 'info',
						'text'  => sprintf(
							/* translators: %s: number of translation drafts. */
							_n( '%s translation draft not reviewed yet', '%s translation drafts not reviewed yet', $drafts, 'estasoft-news-distribution' ),
							number_format_i18n( $drafts )
						),
					),
				)
			);
		}

		$body .= Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'Apple News asks for AI-generated articles to be marked as such in News Publisher. News Distribution lists the marked articles for you.', 'estasoft-news-distribution' )
		);

		return Card::note( 'ai-labels', __( 'Content labels', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * "What this log holds" and how long it is kept.
	 *
	 * @return string Escaped HTML.
	 */
	private function holds(): string {
		$months = $this->plugin->make( PrivacyInventory::class )->ai_months();
		$kept   = sprintf(
			/* translators: %d: number of months. */
			_n( 'Kept for %d month.', 'Kept for %d months.', $months, 'estasoft-news-distribution' ),
			$months
		) . ' ' . __( 'Change it in Settings, Privacy and data. On a network, the network administrator sets it in Network, AI policy. Entries cannot be edited, and they are deleted only when the time is up.', 'estasoft-news-distribution' );

		return Card::note(
			'ai-holds',
			__( 'What this log holds', 'estasoft-news-distribution' ),
			Parts::ticks(
				array(
					array(
						'level' => 'pass',
						'text'  => __( 'The suggestion and the decision', 'estasoft-news-distribution' ),
					),
					array(
						'level' => 'pass',
						'text'  => __( 'Staff names, and which provider answered', 'estasoft-news-distribution' ),
					),
					array(
						'level' => 'none',
						'text'  => __( 'Not your keys', 'estasoft-news-distribution' ),
					),
					array(
						'level' => 'none',
						'text'  => __( 'Not a copy of the article', 'estasoft-news-distribution' ),
					),
				)
			) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $kept ) )
		);
	}
}
