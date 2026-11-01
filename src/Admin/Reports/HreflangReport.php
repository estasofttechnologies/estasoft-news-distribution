<?php
/**
 * Hreflang: whether the language versions of an article point at each other.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Reports\Parts\Hreflang;
use Estasoft\NewsDistribution\Admin\Reports\Parts\ScanControls;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Scannable;
use Estasoft\NewsDistribution\Admin\Reports\Parts\Scans;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the language links in the head of the pages of each article's translations, from the
 * site's own server, when a person presses "Check the pages". The reading and the judging are in
 * `Parts\Hreflang`; this class prints the result. Opening the screen never fetches a page.
 */
final class HreflangReport extends AbstractReport implements Scannable {

	/**
	 * Rows shown before "Show all".
	 */
	private const SHOWN = 6;

	/**
	 * The form field that asks for one article to be read again.
	 */
	private const RECHECK = 'estasoft_nd_hreflang_recheck';
	private const NONCE   = 'estasoft_nd_hreflang_recheck';

	public function name(): string {
		return 'hreflang';
	}

	public function title(): string {
		return __( 'Hreflang', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Whether your language versions point at each other.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 20;
	}

	/**
	 * Only a site with more than one language has versions to compare.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return count( $this->plugin->languages()->languages() ) > 1;
	}

	public function scan_units(): int {
		return ( new Hreflang( $this->plugin ) )->units();
	}

	public function scan( callable $progress ): array {
		return ( new Hreflang( $this->plugin ) )->scan( $progress );
	}

	public function scan_message( array $result ): string {
		$model = $this->model_of( $result );

		return 0 === $model['problems']
			? __( 'The hreflang check finished. No language-link problems found.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: number of articles. */
				_n( 'The hreflang check finished. %s article has language-link problems.', 'The hreflang check finished. %s articles have language-link problems.', $model['problems'], 'estasoft-news-distribution' ),
				number_format_i18n( $model['problems'] )
			);
	}

	/**
	 * The languages that are on, in the order the screen prints them.
	 *
	 * @return string[]
	 */
	private function languages(): array {
		$off = array_map( 'strval', (array) $this->plugin->settings()->get( 'languages.off', array() ) );

		return array_values( array_diff( array_unique( array_map( 'strval', $this->plugin->languages()->languages() ) ), $off ) );
	}

	/**
	 * The judged rows of a stored result.
	 *
	 * @param array<string, mixed> $result What the scan stored.
	 * @return array{languages: string[], rows: array<int, array<string, mixed>>, problems: int, unchecked: int}
	 */
	private function model_of( array $result ): array {
		$languages = array() === (array) ( $result['languages'] ?? array() ) ? $this->languages() : array_map( 'strval', (array) $result['languages'] );
		$rows      = array();
		$problems  = 0;

		foreach ( (array) ( $result['groups'] ?? array() ) as $group ) {
			$judged    = Hreflang::analyse( (array) $group, $languages );
			$rows[]    = array(
				'group'  => $group,
				'judged' => $judged,
			);
			$problems += $judged['problem'] ? 1 : 0;
		}

		return array(
			'languages' => $languages,
			'rows'      => $rows,
			'problems'  => $problems,
			'unchecked' => (int) ( $result['unchecked'] ?? 0 ),
		);
	}

	/**
	 * The last stored result, judged.
	 *
	 * @return array<string, mixed>
	 */
	private function model(): array {
		$scans = $this->plugin->make( Scans::class );
		$last  = $scans->last( $this->name() );

		return array_merge(
			null === $last ? array(
				'languages' => $this->languages(),
				'rows'      => array(),
				'problems'  => 0,
				'unchecked' => 0,
			) : $this->model_of( $last['data'] ),
			array(
				'ran_at'  => null === $last ? 0 : $last['at'],
				'running' => (string) $scans->running( $this->name() ),
			)
		);
	}

	/**
	 * The figures, for the screen and for `GET /reports/hreflang`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$model     = $this->model();
		$languages = array();
		$rows      = array();

		foreach ( $model['languages'] as $code ) {
			$languages[] = array(
				'code' => $code,
				'name' => View::language( $code ),
			);
		}

		foreach ( $model['rows'] as $row ) {
			$versions = array();

			foreach ( $row['judged']['cells'] as $code => $cell ) {
				$version = (array) ( $row['group']['versions'][ $code ] ?? array() );

				$versions[ $code ] = array(
					'article' => (int) ( $version['id'] ?? 0 ),
					'state'   => (string) ( $version['state'] ?? 'missing' ),
					'finding' => $cell['state'],
					'text'    => $cell['text'],
					'tags'    => array_values( (array) ( $version['tags'] ?? array() ) ),
				);
			}

			$rows[] = array(
				'group'     => (int) $row['group']['group'],
				'title'     => (string) $row['group']['title'],
				'published' => (int) $row['group']['published_ts'] > 0 ? Clock::iso( (int) $row['group']['published_ts'] ) : null,
				'result'    => $row['judged']['result'],
				'text'      => $row['judged']['result_text'],
				'problem'   => $row['judged']['problem'],
				'versions'  => $versions,
			);
		}

		return array(
			'ran'       => $model['ran_at'] > 0,
			'ran_at'    => $model['ran_at'] > 0 ? Clock::iso( $model['ran_at'] ) : null,
			'running'   => '' === $model['running'] ? null : $model['running'],
			'languages' => $languages,
			'articles'  => count( $rows ),
			'problems'  => $model['problems'],
			'unchecked' => $model['unchecked'],
			'rows'      => $rows,
		);
	}

	/**
	 * The controls: the button, the progress and the messages.
	 *
	 * @return ScanControls
	 */
	private function controls(): ScanControls {
		return new ScanControls( $this->plugin, $this );
	}

	public function actions(): string {
		$model = $this->model();
		$text  = '';

		if ( $model['ran_at'] > 0 ) {
			$text = sprintf(
				/* translators: %s: a day and time such as "today, 14:10". */
				__( 'Last checked %s.', 'estasoft-news-distribution' ),
				View::day( $model['ran_at'], Clock::now() )
			);
		}

		return ( '' === $text ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) ) )
			. $this->controls()->form( __( 'Check the pages', 'estasoft-news-distribution' ) );
	}

	/**
	 * Runs a form post: reads one article again, or starts the whole check.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( 'POST' === ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) && isset( $_POST[ self::RECHECK ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether the form was posted; the nonce is checked next, before anything is read.
			check_admin_referer( self::NONCE );
			$this->plugin->access()->require_or_die( $this->permission() );

			$group = absint( wp_unslash( $_POST[ self::RECHECK ] ) );

			$state = $this->recheck( $group ) ? 'rechecked' : 'gone';

			wp_safe_redirect(
				$this->url(
					array(
						'show' => $group,
						'done' => $state,
					)
				)
			);
			exit;
		}

		$this->controls()->handle();
	}

	/**
	 * Reads the pages of one article again and keeps the result.
	 *
	 * @param int $group_id The group ID.
	 * @return bool False when the article is no longer among the recent ones.
	 */
	public function recheck( int $group_id ): bool {
		$fresh = ( new Hreflang( $this->plugin ) )->recheck( $group_id );

		if ( null === $fresh ) {
			return false;
		}

		$scans = $this->plugin->make( Scans::class );
		$last  = $scans->last( $this->name() );
		$data  = null === $last ? array(
			'ran_at'    => Clock::now(),
			'languages' => $this->languages(),
			'groups'    => array(),
			'unchecked' => 0,
		) : $last['data'];
		$found = false;

		foreach ( (array) $data['groups'] as $index => $group ) {
			if ( (int) $group['group'] === $group_id ) {
				$data['groups'][ $index ] = $fresh;
				$found                    = true;
			}
		}

		if ( ! $found ) {
			array_unshift( $data['groups'], $fresh );
		}

		$scans->save( $this->name(), null === $last ? '' : $last['job'], $data );

		return true;
	}

	public function enqueue(): void {
		$this->controls()->enqueue();
	}

	/**
	 * The translation plugin's name for a sentence.
	 *
	 * @return string The name, or an empty string when it is not one News Distribution knows.
	 */
	private function plugin_name(): string {
		switch ( $this->plugin->languages()->name() ) {
			case 'polylang':
				return 'Polylang';
			case 'wpml':
				return 'WPML';
			default:
				return '';
		}
	}

	/**
	 * The lead message.
	 *
	 * @param array<string, mixed> $model The model.
	 * @param bool                 $only  Whether the list shows only problems.
	 * @return string
	 */
	private function lead( array $model, bool $only ): string {
		$done = Query::key( 'done' );
		$note = '';

		if ( 'rechecked' === $done ) {
			$note = View::lead( 'success', __( 'Read again.', 'estasoft-news-distribution' ), __( 'The pages of that article were read again from your own server.', 'estasoft-news-distribution' ) );
		} elseif ( 'gone' === $done ) {
			$note = View::lead( 'info', __( 'That article is no longer among the recent ones.', 'estasoft-news-distribution' ), __( 'Run Check the pages to read the current list.', 'estasoft-news-distribution' ) );
		}

		if ( $model['ran_at'] < 1 ) {
			return $note . View::lead( 'info', __( 'The hreflang check has not run yet.', 'estasoft-news-distribution' ), __( 'Press Check the pages. It reads the language links in the pages of your recent articles, from your own server.', 'estasoft-news-distribution' ) );
		}

		if ( 0 === $model['problems'] ) {
			return $note . View::lead(
				'success',
				__( 'No language-link problems found.', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: number of articles. */
					_n( 'Read the pages of %s recent article.', 'Read the pages of %s recent articles.', count( $model['rows'] ), 'estasoft-news-distribution' ),
					number_format_i18n( count( $model['rows'] ) )
				)
			);
		}

		return $note . View::lead(
			'danger',
			sprintf(
				/* translators: %s: number of articles. */
				_n( '%s article has language-link problems.', '%s articles have language-link problems.', $model['problems'], 'estasoft-news-distribution' ),
				number_format_i18n( $model['problems'] )
			),
			__( 'Google may ignore a pair of pages when one does not link back to the other. Technical name: hreflang.', 'estasoft-news-distribution' ),
			$only
				? Button::link( __( 'Show all articles', 'estasoft-news-distribution' ), $this->url(), array( 'size' => 'sm' ) )
				: Button::link( __( 'Show only problems', 'estasoft-news-distribution' ), $this->url( array( 'problems' => '1' ) ), array( 'size' => 'sm' ) )
		);
	}

	/**
	 * A cell of the table.
	 *
	 * @param array<string, mixed> $cell A judged cell.
	 * @return string
	 */
	private function cell( array $cell ): string {
		return View::mark( (string) $cell['tone'], (string) $cell['text'] );
	}

	/**
	 * The Article cell: the title, and a note when only one language has the article.
	 *
	 * @param array<string, mixed> $row A judged row.
	 * @return string
	 */
	private function title_cell( array $row ): string {
		$title = '' === (string) $row['group']['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $row['group']['title'];
		$have  = array();

		foreach ( (array) $row['group']['versions'] as $code => $version ) {
			if ( 'missing' !== $version['state'] ) {
				$have[] = (string) $code;
			}
		}

		$sub = 1 === count( $have )
			? Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: a language name such as Arabic. */
						__( '%s only', 'estasoft-news-distribution' ),
						View::language_short( $have[0] )
					)
				)
			)
			: '';

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag( 'span', array_merge( array( 'class' => 'estasoft-nd-strong' ), View::text_attributes( $title ) ), esc_html( $title ) ) . $sub
		);
	}

	/**
	 * The Result cell: the finding, and a "Show tags" link when the page's tags show the fix.
	 *
	 * @param array<string, mixed> $row A judged row.
	 * @return string
	 */
	private function result_cell( array $row ): string {
		$judged = $row['judged'];
		$mark   = View::mark( $judged['problem'] ? 'danger' : ( 'unchecked' === $judged['result'] ? 'neutral' : 'success' ), (string) $judged['result_text'] );

		if ( '' === $judged['fix'] ) {
			return $mark;
		}

		$title = (string) $row['group']['title'];

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-start' ),
			$mark . Html::tag(
				'a',
				array(
					'href'       => $this->url( array( 'show' => (int) $row['group']['group'] ) ),
					'aria-label' => sprintf(
						/* translators: %s: article title. */
						__( 'Show tags: %s', 'estasoft-news-distribution' ),
						$title
					),
				),
				esc_html__( 'Show tags', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * The lines of one page's tags, with the wrong ones flagged and the fix beside them.
	 *
	 * @param array<string, mixed> $row A judged row.
	 * @return array{lines: string[], flags: array<int, array{line: int, tone: string}>, language: string}
	 */
	private function tag_lines( array $row ): array {
		$judged   = $row['judged'];
		$code     = (string) $judged['fix'];
		$cell     = (array) $judged['cells'][ $code ];
		$language = (string) ( $cell['lang'] ?? $code );
		$version  = (array) $row['group']['versions'][ $code ];
		$lines    = array();
		$flags    = array();
		$listed   = array();

		foreach ( (array) $version['tags'] as $tag ) {
			$lines[] = $this->tag_line( (string) $tag['code'], (string) $tag['href'] );

			if ( ! Hreflang::valid_code( (string) $tag['code'] ) ) {
				$flags[] = array(
					'line' => count( $lines ),
					'tone' => 'danger',
				);
				$use     = Hreflang::fix_for( (string) $tag['code'] );
				$lines[] = $this->tag_line( '' === $use ? strtolower( Catalog::primary( (string) $tag['code'] ) ) : $use, (string) $tag['href'] );
				$flags[] = array(
					'line' => count( $lines ),
					'tone' => 'ok',
				);
			}

			foreach ( (array) $row['group']['versions'] as $other => $other_version ) {
				if ( 'published' === $other_version['state'] && Hreflang::address( (string) $other_version['url'] ) === Hreflang::address( (string) $tag['href'] ) ) {
					$listed[ $other ] = true;
				}
			}
		}

		foreach ( (array) $row['group']['versions'] as $other => $other_version ) {
			if ( 'published' !== $other_version['state'] || isset( $listed[ $other ] ) ) {
				continue;
			}

			$use     = 'ku' === Catalog::primary( (string) $other ) ? ( ! empty( $other_version['arabic'] ) ? 'ku-Arab' : 'ku-Latn' ) : strtolower( Catalog::primary( (string) $other ) );
			$lines[] = $this->tag_line( $use, (string) $other_version['url'] );
			$flags[] = array(
				'line' => count( $lines ),
				'tone' => 'ok',
			);
		}

		return array(
			'lines'    => $lines,
			'flags'    => $flags,
			'language' => $language,
		);
	}

	/**
	 * One language link as it is written in a page.
	 *
	 * @param string $code The language code.
	 * @param string $href The address.
	 * @return string
	 */
	private function tag_line( string $code, string $href ): string {
		return '<link rel="alternate" hreflang="' . $code . '" href="' . $href . '" />';
	}

	/**
	 * The card that shows the tags of the page that needs a fix.
	 *
	 * @param array<int, array<string, mixed>> $rows The judged rows.
	 * @return string
	 */
	private function tags_card( array $rows ): string {
		$wanted = Query::int( 'show' );
		$chosen = null;

		foreach ( $rows as $row ) {
			if ( '' === $row['judged']['fix'] ) {
				continue;
			}

			if ( (int) $row['group']['group'] === $wanted ) {
				$chosen = $row;
				break;
			}

			$chosen = null === $chosen ? $row : $chosen;
		}

		if ( null === $chosen ) {
			return '';
		}

		$shown  = $this->tag_lines( $chosen );
		$group  = (int) $chosen['group']['group'];
		$plugin = $this->plugin_name();

		return Card::note(
			'hreflang-tags',
			sprintf(
				/* translators: %s: a language name such as Kurdish. */
				__( 'What the %s page should say', 'estasoft-news-distribution' ),
				View::language_short( $shown['language'] )
			),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html(
					sprintf(
						/* translators: %s: article title. */
						__( '%s. Choose Show tags on another row to see its tags. The red line is wrong today and the green line is the fix.', 'estasoft-news-distribution' ),
						(string) $chosen['group']['title']
					)
				)
			)
			. Code::block( implode( "\n", $shown['lines'] ), __( 'The language links of the page', 'estasoft-news-distribution' ), $shown['flags'] )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html( $this->fix_note( $plugin ) )
			)
			. Html::tag(
				'form',
				array(
					'method' => 'post',
					'action' => $this->url( array( 'show' => $group ) ),
				),
				Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => self::RECHECK,
						'value' => (string) $group,
					)
				)
				. Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => '_wpnonce',
						'value' => wp_create_nonce( self::NONCE ),
					)
				)
				. Button::submit( __( 'Recheck this article', 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * The sentence under the tags: who prints them and what to do.
	 *
	 * @param string $plugin The translation plugin's name, or an empty string.
	 * @return string
	 */
	private function fix_note( string $plugin ): string {
		if ( '' === $plugin ) {
			return __( 'Your translation plugin or your theme prints these tags. News Distribution reads what is really in each page, so a caching or theme problem shows up here. It cannot change the tags itself: fix the cause there, then recheck.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: translation plugin name such as Polylang. */
			__( '%1$s prints these tags. News Distribution reads what is really in each page, so a caching or theme problem shows up here. It cannot change the tags itself: fix the cause in %1$s or your theme, then recheck.', 'estasoft-news-distribution' ),
			$plugin
		);
	}

	/**
	 * The card with the rules the audit checks.
	 *
	 * @return string
	 */
	private function rules_card(): string {
		$rules = array(
			__( 'Every version lists itself and every other version', 'estasoft-news-distribution' ),
			__( 'Language codes use the two-letter standard (ISO 639-1), with an optional script or region', 'estasoft-news-distribution' ),
			__( 'Kurmanji is written ku-Latn, never kmr', 'estasoft-news-distribution' ),
			__( 'The address in each tag answers 200 and is that version’s own address', 'estasoft-news-distribution' ),
		);
		$list  = '';

		foreach ( $rules as $rule ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'info' ) . Html::tag( 'span', array(), esc_html( $rule ) ) );
		}

		return Card::note( 'hreflang-rules', __( 'Rules News Distribution checks', 'estasoft-news-distribution' ), Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list ) );
	}

	public function render(): string {
		$model = $this->model();
		$ctl   = $this->controls();
		$only  = Query::is( 'problems', '1' );
		$all   = Query::is( 'all', '1' );
		$out   = '';
		$text  = $ctl->problem();

		if ( '' !== $text ) {
			$out .= View::lead( 'danger', $text );
		}

		$out .= $ctl->progress() . $this->lead( $model, $only );

		if ( $model['ran_at'] < 1 ) {
			return $out . Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $this->rules_card() );
		}

		$languages = $this->languages();
		$rows      = array_values(
			array_filter(
				$model['rows'],
				static function ( array $row ) use ( $only ): bool {
					return ! $only || $row['judged']['problem'];
				}
			)
		);
		$shown     = array();
		$cells     = array();
		$left      = 0;

		// The newest few, and every article with a problem, even when it is older.
		foreach ( $rows as $index => $row ) {
			if ( $all || $only || $index < self::SHOWN || $row['judged']['problem'] ) {
				$shown[] = $row;
			} elseif ( 'complete' === $row['judged']['result'] ) {
				++$left;
			}
		}

		foreach ( $shown as $row ) {
			$line = array( $this->title_cell( $row ) );

			foreach ( $languages as $code ) {
				$line[] = $this->cell( (array) $row['judged']['cells'][ $code ] );
			}

			$line[]  = $this->result_cell( $row );
			$cells[] = $line;
		}

		$headers = array( __( 'Article', 'estasoft-news-distribution' ) );

		foreach ( $languages as $code ) {
			$headers[] = View::language( $code );
		}

		$headers[] = __( 'Result', 'estasoft-news-distribution' );

		$hidden   = count( $rows ) - count( $shown );
		$complete = $left;
		$footer   = sprintf(
			/* translators: 1: rows shown, 2: rows in all. */
			_n( 'Showing %1$s of %2$s article, newest first.', 'Showing %1$s of %2$s articles, newest first.', count( $rows ), 'estasoft-news-distribution' ),
			number_format_i18n( count( $shown ) ),
			number_format_i18n( count( $rows ) )
		);

		if ( $hidden > 0 && $complete === $hidden ) {
			$footer .= ' ' . sprintf(
				/* translators: %s: number of articles. */
				_n( 'The other %s is complete.', 'The other %s are complete.', $hidden, 'estasoft-news-distribution' ),
				number_format_i18n( $hidden )
			);
		}

		if ( $hidden > 0 && count( $shown ) > self::SHOWN ) {
			$footer .= ' ' . __( 'Every article with a problem is shown.', 'estasoft-news-distribution' );
		}

		$more = $hidden > 0
			? Button::link(
				sprintf(
					/* translators: %s: number of articles. */
					__( 'Show all %s', 'estasoft-news-distribution' ),
					number_format_i18n( count( $rows ) )
				),
				$this->url( array( 'all' => '1' ) ),
				array( 'size' => 'sm' )
			)
			: '';

		$table = array() === $cells
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-card-pad' ), esc_html__( 'No articles to show.', 'estasoft-news-distribution' ) )
			: Grid::render( 'estasoft-nd-cols-hreflang-' . min( 6, max( 2, count( $languages ) ) ), $headers, $cells, __( 'Language links by article', 'estasoft-news-distribution' ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-table-footer' ), View::footer( $footer, $more ) );

		$notice = $model['unchecked'] > 0
			? View::lead(
				'info',
				sprintf(
					/* translators: %s: number of pages. */
					_n( '%s page was not read.', '%s pages were not read.', $model['unchecked'], 'estasoft-news-distribution' ),
					number_format_i18n( $model['unchecked'] )
				),
				__( 'The check ran out of time. Run it again to read the rest.', 'estasoft-news-distribution' )
			)
			: '';

		return $out
			. $notice
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ), $table )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-notes estasoft-nd-notes--wide-first' ), $this->tags_card( $rows ) . $this->rules_card() );
	}
}
