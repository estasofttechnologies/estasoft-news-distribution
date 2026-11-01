<?php
/**
 * The readiness report as HTML.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SmartNews;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the array ReadinessReport::build() returns. The admin screen prints it
 * as a preview and the shared page prints it for the platform, so both look the
 * same. Every piece of text is escaped here; print the result through
 * Admin\Output::show() or wp_kses() with Output::allowed().
 */
final class ReportView {

	/**
	 * The sections a person can include, in page order.
	 *
	 * @return string[]
	 */
	public static function keys(): array {
		return array( 'publication', 'feeds', 'rules', 'samples', 'contact' );
	}

	/**
	 * The title and help line of each section, for the include boxes.
	 *
	 * @param int $samples How many sample entries the report holds.
	 * @return array<string, array{label: string, help: string}>
	 */
	public static function sections( int $samples ): array {
		return array(
			'publication' => array(
				'label' => __( 'Publication details', 'estasoft-news-distribution' ),
				'help'  => __( 'Name, languages, logo and description.', 'estasoft-news-distribution' ),
			),
			'feeds'       => array(
				'label' => __( 'Feed addresses', 'estasoft-news-distribution' ),
				'help'  => __( 'The address for each language.', 'estasoft-news-distribution' ),
			),
			'rules'       => array(
				'label' => __( 'Rules checked', 'estasoft-news-distribution' ),
				'help'  => __( 'Each rule and whether it passes.', 'estasoft-news-distribution' ),
			),
			'samples'     => array(
				'label' => sprintf(
					/* translators: %d: the number of sample entries. */
					_n( '%d sample entry', '%d sample entries', $samples, 'estasoft-news-distribution' ),
					$samples
				),
				'help'  => __( 'Real articles, as the platform receives them.', 'estasoft-news-distribution' ),
			),
			'contact'     => array(
				'label' => __( 'Contact', 'estasoft-news-distribution' ),
				'help'  => __( 'Who the platform should write to.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The report.
	 *
	 * @param array<string, mixed> $report     A report from ReadinessReport::build().
	 * @param string[]             $sections   Section keys to include.
	 * @param bool                 $for_public True on the shared page (leaves out what is not included and
	 *                                         uses an h1); false on the admin preview (hides it, so the boxes can show it).
	 * @return string Escaped HTML.
	 */
	public static function render( array $report, array $sections, bool $for_public ): string {
		if ( array() === $report ) {
			return '';
		}

		$body = self::head( $report, $for_public );

		$body .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( self::checked_line( $report ) ) );

		foreach ( self::keys() as $key ) {
			$shown = in_array( $key, $sections, true );

			if ( $for_public && ! $shown ) {
				continue;
			}

			$html = self::section( $key, $report );

			if ( '' === $html ) {
				continue;
			}

			$body .= Html::tag(
				'section',
				array(
					'class'               => 'estasoft-nd-report__section',
					'data-report-section' => $key,
					'hidden'              => $shown ? null : true,
				),
				$html
			);
		}

		$body .= Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-report__foot' ),
			esc_html(
				sprintf(
					/* translators: 1: a platform name such as SmartNews, 2: the same platform name. */
					__( 'This report shows what News Distribution’s own check found against the rules it applies for %1$s. It is not an approval, and %2$s decides whether to accept the feeds.', 'estasoft-news-distribution' ),
					(string) $report['platform_name'],
					(string) $report['platform_name']
				)
			)
		);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-report' ), $body );
	}

	/**
	 * One section of the report.
	 *
	 * @param string               $key    A key from keys().
	 * @param array<string, mixed> $report A report.
	 * @return string
	 */
	private static function section( string $key, array $report ): string {
		switch ( $key ) {
			case 'publication':
				return self::publication( $report );
			case 'feeds':
				return self::feeds( $report );
			case 'rules':
				return self::rules( $report );
			case 'samples':
				return self::samples( $report );
			case 'contact':
				return self::contact( $report );
		}

		return '';
	}

	/**
	 * The headline and the summary of the checks.
	 *
	 * @param array<string, mixed> $report A report.
	 * @param bool                 $for_public Whether this is the shared page.
	 * @return string
	 */
	private static function head( array $report, bool $for_public ): string {
		$summary = (array) $report['summary'];
		$tag     = $for_public ? 'h1' : 'h2';
		$status  = $summary['problems'] > 0 ? Status::BLOCK : ( $summary['warnings'] > 0 ? Status::WARN : Status::CLEAN );

		return Html::tag(
			'header',
			array( 'class' => 'estasoft-nd-report__head' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html__( 'What the platform sees', 'estasoft-news-distribution' ) )
				. Html::tag( $tag, array( 'class' => 'estasoft-nd-headline' ), esc_html( (string) $report['title'] ) )
			)
			. self::line( $status, self::summary_line( $summary ) )
		);
	}

	/**
	 * "8 of 9 checks pass, 1 warning".
	 *
	 * @param array<string, int> $summary The summary counts.
	 * @return string
	 */
	private static function summary_line( array $summary ): string {
		$warnings = (int) $summary['warnings'];
		$problems = (int) $summary['problems'];
		$passed   = (int) $summary['passed'];
		$total    = (int) $summary['total'];

		if ( 0 === $warnings && 0 === $problems ) {
			return sprintf(
				/* translators: %d: the number of checks. */
				_n( 'The %d check passes', 'All %d checks pass', $total, 'estasoft-news-distribution' ),
				$total
			);
		}

		$warning_text = sprintf(
			/* translators: %d: the number of warnings. */
			_n( '%d warning', '%d warnings', $warnings, 'estasoft-news-distribution' ),
			$warnings
		);
		$problem_text = sprintf(
			/* translators: %d: the number of problems. */
			_n( '%d problem', '%d problems', $problems, 'estasoft-news-distribution' ),
			$problems
		);

		if ( 0 === $problems ) {
			return sprintf(
				/* translators: 1: checks that pass, 2: all checks, 3: the number of warnings, such as "1 warning". */
				__( '%1$d of %2$d checks pass, %3$s', 'estasoft-news-distribution' ),
				$passed,
				$total,
				$warning_text
			);
		}

		if ( 0 === $warnings ) {
			return sprintf(
				/* translators: 1: checks that pass, 2: all checks, 3: the number of problems, such as "1 problem". */
				__( '%1$d of %2$d checks pass, %3$s', 'estasoft-news-distribution' ),
				$passed,
				$total,
				$problem_text
			);
		}

		return sprintf(
			/* translators: 1: checks that pass, 2: all checks, 3: the number of warnings, 4: the number of problems. */
			__( '%1$d of %2$d checks pass, %3$s and %4$s', 'estasoft-news-distribution' ),
			$passed,
			$total,
			$warning_text,
			$problem_text
		);
	}

	/**
	 * "Checked today at 14:34 by News Distribution, against rule pack 2026.09.1. The feeds hold 14 items: ...".
	 *
	 * @param array<string, mixed> $report A report.
	 * @return string
	 */
	private static function checked_line( array $report ): string {
		$time  = (int) $report['generated'];
		$today = Clock::format( $time, 'Y-m-d' ) === Clock::format( Clock::now(), 'Y-m-d' );
		$pack  = (string) $report['rule_pack'];
		$text  = $today
			? sprintf(
				/* translators: 1: a time such as 14:34, 2: a rule pack version such as 2026.09.1. */
				__( 'Checked today at %1$s by News Distribution, against rule pack %2$s.', 'estasoft-news-distribution' ),
				Clock::format( $time, 'H:i' ),
				$pack
			)
			: sprintf(
				/* translators: 1: a date and time such as 21 Oct 2026, 14:34, 2: a rule pack version such as 2026.09.1. */
				__( 'Checked on %1$s by News Distribution, against rule pack %2$s.', 'estasoft-news-distribution' ),
				Clock::datetime( $time ),
				$pack
			);
		$parts = array();

		foreach ( (array) $report['items']['by_language'] as $language => $count ) {
			$parts[] = ReadinessReport::language_label( (string) $language, false ) . ' ' . (int) $count;
		}

		$total = (int) $report['items']['total'];

		return $text . ' ' . sprintf(
			/* translators: 1: the number of items, 2: a list such as "English 6, Kurdish 3". */
			_n( 'The feeds hold %1$d item: %2$s.', 'The feeds hold %1$d items: %2$s.', $total, 'estasoft-news-distribution' ),
			$total,
			implode( ', ', $parts )
		);
	}

	/**
	 * Publication: name and languages, logo size, description length.
	 *
	 * @param array<string, mixed> $report A report.
	 * @return string
	 */
	private static function publication( array $report ): string {
		$details = (array) ( $report['details'] ?? array() );

		if ( array() === $details ) {
			return '';
		}

		$logo  = (array) $details['logo'];
		$desc  = (array) $details['description'];
		$list  = wp_sprintf( '%l', (array) $details['language_names'] );
		$lines = '';

		if ( $logo['present'] ) {
			$lines .= Html::tag(
				'li',
				array(),
				self::line(
					$logo['ok'] ? Status::CLEAN : Status::WARN,
					sprintf(
						/* translators: 1: the logo width in pixels, 2: the logo height in pixels. */
						__( 'Logo is %1$d × %2$d', 'estasoft-news-distribution' ),
						(int) $logo['width'],
						(int) $logo['height']
					)
				)
			);
		} else {
			$lines .= Html::tag( 'li', array(), self::line( Status::BLOCK, __( 'No logo yet', 'estasoft-news-distribution' ) ) );
		}

		if ( '' !== $desc['text'] ) {
			$lines .= Html::tag(
				'li',
				array(),
				self::line(
					$desc['ok'] ? Status::CLEAN : Status::WARN,
					$desc['ok']
						? sprintf(
							/* translators: 1: the description length in characters, 2: the length SmartNews recommends staying under. */
							_n(
								'Description is %1$d character, under the %2$d SmartNews recommends',
								'Description is %1$d characters, under the %2$d SmartNews recommends',
								(int) $desc['length'],
								'estasoft-news-distribution'
							),
							(int) $desc['length'],
							(int) $desc['max']
						)
						: sprintf(
							/* translators: 1: the description length in characters, 2: the length SmartNews recommends staying under. */
							_n(
								'Description is %1$d character, not under the %2$d SmartNews recommends',
								'Description is %1$d characters, not under the %2$d SmartNews recommends',
								(int) $desc['length'],
								'estasoft-news-distribution'
							),
							(int) $desc['length'],
							(int) $desc['max']
						)
				)
			);
		}

		return Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Publication', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array(), esc_html( (string) $details['name'] . ' · ' . $list ) )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-6' ), $lines );
	}

	/**
	 * Feeds: one address per language, and the outcome of the plugin's own check.
	 *
	 * @param array<string, mixed> $report A report.
	 * @return string
	 */
	private static function feeds( array $report ): string {
		$feeds    = (array) ( $report['feeds'] ?? array() );
		$problems = 0;
		$urls     = '';

		foreach ( $feeds as $feed ) {
			$urls     .= Html::tag( 'p', array( 'class' => 'estasoft-nd-url estasoft-nd-mono' ), esc_html( (string) $feed['url'] ) );
			$problems += $feed['valid'] ? 0 : 1;
		}

		$count = count( $feeds );
		$line  = 0 === $problems
			? sprintf(
				/* translators: %d: the number of feeds. */
				_n( 'News Distribution’s own SmartFormat check found no problems in the %d feed', 'News Distribution’s own SmartFormat check found no problems in the %d feeds', $count, 'estasoft-news-distribution' ),
				$count
			)
			: sprintf(
				/* translators: 1: the number of feeds with problems, 2: the number of feeds. */
				_n( 'News Distribution’s own SmartFormat check found problems in %1$d of %2$d feed', 'News Distribution’s own SmartFormat check found problems in %1$d of %2$d feeds', $count, 'estasoft-news-distribution' ),
				$problems,
				$count
			);

		return Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Feeds', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $urls )
			. ( array() === $feeds ? '' : self::line( 0 === $problems ? Status::CLEAN : Status::BLOCK, $line ) );
	}

	/**
	 * Checks: each rule and whether it passes.
	 *
	 * @param array<string, mixed> $report A report.
	 * @return string
	 */
	private static function rules( array $report ): string {
		$lines = '';

		foreach ( (array) ( $report['checks'] ?? array() ) as $check ) {
			$lines .= Html::tag( 'li', array(), self::line( self::status_of( (string) $check['status'] ), (string) $check['label'] ) );
		}

		return Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Checks', 'estasoft-news-distribution' ) )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $lines );
	}

	/**
	 * Sample entries: a few real items.
	 *
	 * @param array<string, mixed> $report A report.
	 * @return string
	 */
	private static function samples( array $report ): string {
		$entries = '';

		foreach ( (array) ( $report['samples'] ?? array() ) as $sample ) {
			$lines = Html::tag(
				'li',
				array(),
				self::line(
					Status::CLEAN,
					sprintf(
						/* translators: %s: an author name. */
						__( 'Author: %s', 'estasoft-news-distribution' ),
						(string) $sample['author']
					)
				)
			);

			$lines .= Html::tag(
				'li',
				array(),
				self::line(
					Status::CLEAN,
					sprintf(
						/* translators: 1: a width in pixels, 2: a height in pixels. */
						__( 'Thumbnail %1$d × %2$d px', 'estasoft-news-distribution' ),
						(int) $sample['width'],
						(int) $sample['height']
					)
				)
			);

			$lines .= Html::tag(
				'li',
				array(),
				self::line(
					Status::CLEAN,
					sprintf(
						/* translators: %s: a web address. */
						__( 'Canonical address: %s', 'estasoft-news-distribution' ),
						(string) $sample['canonical']
					)
				)
			);

			$entries .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-report__entry' ),
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Tag::lang( (string) $sample['language'] )
					. Html::tag(
						'strong',
						array(
							'lang' => (string) $sample['language'],
							'dir'  => Catalog::direction( (string) $sample['language'] ),
						),
						esc_html( (string) $sample['title'] )
					)
				)
				. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $lines )
			);
		}

		if ( '' === $entries ) {
			return '';
		}

		return Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Sample entries', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $entries );
	}

	/**
	 * Contact: name and email, only when there is something to show.
	 *
	 * @param array<string, mixed> $report A report.
	 * @return string
	 */
	private static function contact( array $report ): string {
		$contact = (array) ( $report['contact'] ?? array() );
		$text    = trim( implode( ' · ', array_filter( array( (string) ( $contact['name'] ?? '' ), (string) ( $contact['email'] ?? '' ) ) ) ) );

		if ( '' === $text ) {
			return '';
		}

		return Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'Contact', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array(), esc_html( $text ) );
	}

	/**
	 * One line with a status icon, a hidden status word and the text.
	 *
	 * @param string $status A Core\Status code: clean, warn or block.
	 * @param string $text   Plain text.
	 * @return string
	 */
	private static function line( string $status, string $text ): string {
		$info = Status::describe( $status );

		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . $info['tone'] ),
			Icon::svg( $info['icon'] )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html( $info['word'] . ': ' ) )
			. Html::tag( 'span', array(), esc_html( $text ) )
		);
	}

	/**
	 * The Status code that shows a report check status.
	 *
	 * @param string $status pass, warning or problem.
	 * @return string
	 */
	private static function status_of( string $status ): string {
		if ( ReadinessReport::PASS === $status ) {
			return Status::CLEAN;
		}

		return ReadinessReport::WARNING === $status ? Status::WARN : Status::BLOCK;
	}
}
