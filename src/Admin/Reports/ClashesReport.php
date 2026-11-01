<?php
/**
 * Clashes: articles that would collide in a feed or a sitemap.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Core\ArticleId;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Four kinds of clash, all found on this site with no request to anything else:
 *
 * - `same_id`: two articles carry one News Distribution ID (a duplicate-post tool copies it).
 * - `same_title`: two articles in one language have the same title.
 * - `same_address`: two articles have one address (two language versions on one address).
 * - `near_duplicate`: two articles in one language are published close together and share most of their words.
 *
 * The near-duplicate score compares the words of the two texts on this server. No text is sent anywhere.
 */
final class ClashesReport extends AbstractReport {

	/**
	 * How far back articles are compared, in days.
	 */
	private const DAYS = 30;

	/**
	 * The most articles read for each language.
	 */
	private const PER_LANGUAGE = 60;

	/**
	 * The share of words two texts must have in common to be called near-duplicates.
	 */
	private const SIMILAR = 0.8;

	/**
	 * Two articles further apart than this are not compared for near-duplicates, in seconds.
	 */
	private const NEAR_WINDOW = 3 * DAY_IN_SECONDS;

	/**
	 * Two near-duplicates must also have this share of their title words in common, so two articles that
	 * only reuse the same stock text are not called one story.
	 */
	private const SIMILAR_TITLE = 0.5;

	/**
	 * A text with fewer different words than this is too short to compare.
	 */
	private const MIN_WORDS = 15;

	/**
	 * The most rows the screen prints.
	 */
	private const MAX_ROWS = 50;

	/**
	 * The form field that names the action a form post asks for.
	 */
	private const FIELD = 'estasoft_nd_clash';
	private const NONCE = 'estasoft_nd_clash_new_id';

	public function name(): string {
		return 'clashes';
	}

	public function title(): string {
		return __( 'Clashes', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Articles that would collide in a feed or a sitemap.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 40;
	}

	/**
	 * The figures, for the screen and for `GET /reports/clashes`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$model   = $this->model();
		$clashes = array();

		foreach ( $model['clashes'] as $clash ) {
			$articles = array();

			foreach ( $clash['articles'] as $article ) {
				$articles[] = array(
					'article'   => $article['id'],
					'title'     => $article['title'],
					'language'  => $article['language'],
					'status'    => $article['status'],
					'published' => $article['published_ts'] > 0 ? Clock::iso( $article['published_ts'] ) : null,
				);
			}

			$clashes[] = array(
				'type'       => $clash['type'],
				'severity'   => $clash['tone'],
				'articles'   => $articles,
				'similarity' => $clash['similarity'],
				'apart'      => $clash['apart'],
			);
		}

		return array(
			'days'    => self::DAYS,
			'scanned' => $model['scanned'],
			'counts'  => $model['counts'],
			'clashes' => $clashes,
		);
	}

	/**
	 * Finds the clashes.
	 *
	 * @return array{scanned: int, counts: array<string, int>, clashes: array<int, array<string, mixed>>}
	 */
	private function model(): array {
		$articles = $this->articles();
		$ids      = $this->same_ids();
		$titles   = $this->same_titles( $articles );
		$spots    = $this->same_addresses( $articles );
		$pairs    = $this->pairs_of( array_merge( $ids, $titles, $spots ) );
		$near     = $this->near_duplicates( $articles, $pairs );
		$clashes  = array_merge( $ids, $titles, $spots, $near );
		$counts   = array(
			'same_id'        => count( $ids ),
			'same_title'     => count( $titles ),
			'same_address'   => count( $spots ),
			'near_duplicate' => count( $near ),
		);

		return array(
			'scanned' => count( $articles ),
			'counts'  => $counts,
			'clashes' => $clashes,
		);
	}

	/**
	 * The recent published articles of every language, newest first.
	 *
	 * @return Article[]
	 */
	private function articles(): array {
		$found = array();

		foreach ( array_unique( array_map( 'strval', $this->plugin->languages()->languages() ) ) as $language ) {
			foreach ( $this->plugin->reader()->recent( $language, self::DAYS * DAY_IN_SECONDS, self::PER_LANGUAGE ) as $article ) {
				$found[ $article->id() ] = $article;
			}
		}

		usort(
			$found,
			static function ( Article $a, Article $b ): int {
				return $b->published_ts() <=> $a->published_ts();
			}
		);

		return $found;
	}

	/**
	 * One entry of a row: what the screen and the data show about an article.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, mixed>
	 */
	private function entry( int $post_id ): array {
		$post = get_post( $post_id );

		return array(
			'id'           => $post_id,
			'title'        => $post instanceof \WP_Post ? wp_strip_all_tags( (string) $post->post_title ) : '',
			'language'     => $this->plugin->languages()->language_of_post( $post_id ),
			'status'       => $post instanceof \WP_Post ? (string) $post->post_status : '',
			'published_ts' => $post instanceof \WP_Post ? (int) strtotime( $post->post_date_gmt . ' UTC' ) : 0,
		);
	}

	/**
	 * The entry of an article that was already read.
	 *
	 * @param Article $article The article.
	 * @return array<string, mixed>
	 */
	private function entry_of( Article $article ): array {
		return array(
			'id'           => $article->id(),
			'title'        => $article->title(),
			'language'     => $article->language(),
			'status'       => $article->status(),
			'published_ts' => $article->published_ts(),
		);
	}

	/**
	 * A row of the model.
	 *
	 * @param string                           $type       same_id, same_title, same_address or near_duplicate.
	 * @param string                           $tone       danger or warning.
	 * @param array<int, array<string, mixed>> $articles   The articles, oldest first.
	 * @param int                              $similarity A percentage, for near-duplicates.
	 * @return array<string, mixed>
	 */
	private function clash( string $type, string $tone, array $articles, int $similarity = 0 ): array {
		$times = array_filter( array_map( 'intval', array_column( $articles, 'published_ts' ) ) );

		return array(
			'type'       => $type,
			'tone'       => $tone,
			'articles'   => $articles,
			'similarity' => $similarity,
			'apart'      => count( $times ) > 1 ? max( $times ) - min( $times ) : 0,
		);
	}

	/**
	 * Articles that carry the same News Distribution ID.
	 *
	 * The oldest article (the lowest ID) is taken to be the original. Each later one is a copy and
	 * gets a row of its own.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function same_ids(): array {
		global $wpdb;

		$types    = array_values( array_filter( array_map( 'sanitize_key', $this->plugin->settings()->post_types() ) ) );
		$statuses = array( 'publish', 'future', 'draft', 'pending', 'private' );

		if ( array() === $types ) {
			return array();
		}

		$sql = $wpdb->prepare(
			// The placeholders below are built from the two lists, so the number of values always matches.
			"SELECT m.meta_value AS uid, m.post_id AS post_id FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			WHERE m.meta_key = %s AND m.meta_value <> ''
			AND p.post_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')
			AND p.post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')
			AND m.meta_value IN ( SELECT meta_value FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s GROUP BY meta_value HAVING COUNT(*) > 1 )
			ORDER BY m.post_id ASC LIMIT 400', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names and a generated placeholder list; every value is prepared.
			array_merge( array( ArticleId::META_ID ), $types, $statuses, array( ArticleId::META_ID ) )
		);

		$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		$groups = array();

		foreach ( (array) $rows as $row ) {
			$groups[ (string) $row['uid'] ][] = (int) $row['post_id'];
		}

		$out = array();

		foreach ( $groups as $post_ids ) {
			if ( count( $post_ids ) < 2 ) {
				continue;
			}

			$original = (int) array_shift( $post_ids );

			foreach ( $post_ids as $copy ) {
				$out[] = $this->clash( 'same_id', 'danger', array( $this->entry( $original ), $this->entry( (int) $copy ) ) );
			}
		}

		return $out;
	}

	/**
	 * Articles in one language that have one title.
	 *
	 * @param Article[] $articles The recent articles.
	 * @return array<int, array<string, mixed>>
	 */
	private function same_titles( array $articles ): array {
		$groups = array();

		foreach ( $articles as $article ) {
			$title = $this->plain( $article->title() );

			if ( '' === $title ) {
				continue;
			}

			$groups[ $article->language() . '|' . $title ][] = $article;
		}

		$out = array();

		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			usort(
				$group,
				static function ( Article $a, Article $b ): int {
					return $a->published_ts() <=> $b->published_ts();
				}
			);

			$out[] = $this->clash( 'same_title', 'warning', array_map( array( $this, 'entry_of' ), $group ) );
		}

		return $out;
	}

	/**
	 * Articles that share one address, such as two language versions the translation plugin put on one page.
	 *
	 * @param Article[] $articles The recent articles.
	 * @return array<int, array<string, mixed>>
	 */
	private function same_addresses( array $articles ): array {
		$groups = array();

		foreach ( $articles as $article ) {
			$address = $this->address( $article->permalink() );

			if ( '' !== $address ) {
				$groups[ $address ][] = $article;
			}
		}

		$out = array();

		foreach ( $groups as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			usort(
				$group,
				static function ( Article $a, Article $b ): int {
					return $a->id() <=> $b->id();
				}
			);

			$out[] = $this->clash( 'same_address', 'warning', array_map( array( $this, 'entry_of' ), $group ) );
		}

		return $out;
	}

	/**
	 * Pairs of recent articles in one language that share most of their words.
	 *
	 * @param Article[]         $articles The recent articles, newest first.
	 * @param array<string,int> $skip     Pairs already reported, as "low-high" article IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function near_duplicates( array $articles, array $skip ): array {
		$words = array();
		$out   = array();
		$count = count( $articles );

		$heads = array();

		foreach ( $articles as $index => $article ) {
			$words[ $index ] = $this->words( $article );
			$heads[ $index ] = $this->title_words( $article );
		}

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$a = $articles[ $i ];
				$b = $articles[ $j ];

				if ( $a->language() !== $b->language() || abs( $a->published_ts() - $b->published_ts() ) > self::NEAR_WINDOW ) {
					continue;
				}

				if ( isset( $skip[ min( $a->id(), $b->id() ) . '-' . max( $a->id(), $b->id() ) ] ) ) {
					continue;
				}

				if ( count( $words[ $i ] ) < self::MIN_WORDS || count( $words[ $j ] ) < self::MIN_WORDS ) {
					continue;
				}

				$score = $this->similarity( $words[ $i ], $words[ $j ] );

				if ( $score < self::SIMILAR || $this->similarity( $heads[ $i ], $heads[ $j ] ) < self::SIMILAR_TITLE ) {
					continue;
				}

				$pair  = $a->published_ts() <= $b->published_ts() ? array( $a, $b ) : array( $b, $a );
				$out[] = $this->clash( 'near_duplicate', 'warning', array_map( array( $this, 'entry_of' ), $pair ), (int) round( $score * 100 ) );
			}
		}

		return $out;
	}

	/**
	 * The article pairs a list of rows already covers, so a pair is reported once.
	 *
	 * @param array<int, array<string, mixed>> $clashes Rows.
	 * @return array<string, int> "low-high" article IDs => 1.
	 */
	private function pairs_of( array $clashes ): array {
		$pairs = array();

		foreach ( $clashes as $clash ) {
			$ids = array_map( 'intval', array_column( $clash['articles'], 'id' ) );

			foreach ( $ids as $one ) {
				foreach ( $ids as $other ) {
					if ( $one < $other ) {
						$pairs[ $one . '-' . $other ] = 1;
					}
				}
			}
		}

		return $pairs;
	}

	/**
	 * The different words of an article, from its title and the start of its text.
	 *
	 * @param Article $article The article.
	 * @return array<string, bool> Words as keys.
	 */
	private function words( Article $article ): array {
		$text  = $article->title() . ' ' . wp_strip_all_tags( strip_shortcodes( $article->content() ) );
		$split = preg_split( '/[^\p{L}\p{N}]+/u', function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$set   = array();

		foreach ( array_slice( (array) $split, 0, 400 ) as $word ) {
			$set[ (string) $word ] = true;
		}

		return $set;
	}

	/**
	 * The different words of an article's title.
	 *
	 * @param Article $article The article.
	 * @return array<string, bool> Words as keys.
	 */
	private function title_words( Article $article ): array {
		$set = array();

		foreach ( explode( ' ', $this->plain( $article->title() ) ) as $word ) {
			if ( '' !== $word ) {
				$set[ $word ] = true;
			}
		}

		return $set;
	}

	/**
	 * The share of words two texts have in common, from 0 to 1.
	 *
	 * @param array<string, bool> $a Words of one text.
	 * @param array<string, bool> $b Words of the other.
	 * @return float
	 */
	private function similarity( array $a, array $b ): float {
		$shared = count( array_intersect_key( $a, $b ) );
		$all    = count( $a ) + count( $b ) - $shared;

		return $all > 0 ? $shared / $all : 0.0;
	}

	/**
	 * A title as it is compared: lower case, letters and digits only.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	private function plain( string $title ): string {
		$title = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );

		return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $title ) );
	}

	/**
	 * An address as it is compared: no scheme, no fragment, no closing slash.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private function address( string $url ): string {
		$url = strtolower( trim( $url ) );

		if ( '' === $url ) {
			return '';
		}

		$url = (string) preg_replace( '#^https?://#', '', $url );
		$url = (string) preg_replace( '/#.*$/', '', $url );

		return rtrim( $url, '/' );
	}

	/**
	 * Runs the "Give the copy a new ID" form post.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) || ! isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether the form was posted; the nonce is checked next, before anything is read.
			return;
		}

		check_admin_referer( self::NONCE );

		$post_id = isset( $_POST['post'] ) ? absint( wp_unslash( $_POST['post'] ) ) : 0;
		$state   = $this->give_new_id( $post_id );

		wp_safe_redirect( $this->url( array( 'done' => $state ) ) );
		exit;
	}

	/**
	 * Gives a copy an ID of its own.
	 *
	 * Core\ArticleId already knows an ID another article holds, and writes a new one for the copy,
	 * with its own first-published date. The original is never touched.
	 *
	 * @param int $post_id The copy.
	 * @return string `new_id` when the ID changed, `same_id` when it was no longer shared, `denied` when the person may not edit the article.
	 */
	public function give_new_id( int $post_id ): string {
		if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
			return 'denied';
		}

		$ids    = $this->plugin->article_ids();
		$before = $ids->get( $post_id );

		return $ids->ensure( $post_id ) !== $before ? 'new_id' : 'same_id';
	}

	/**
	 * The lead message.
	 *
	 * @param array<string, mixed> $model The model.
	 * @return string
	 */
	private function lead( array $model ): string {
		$done = Query::key( 'done' );
		$note = '';

		if ( 'new_id' === $done ) {
			$note = View::lead( 'success', __( 'The copy has a new ID.', 'estasoft-news-distribution' ), __( 'The original keeps its ID. Platforms will read the copy as a different article.', 'estasoft-news-distribution' ) );
		} elseif ( 'same_id' === $done ) {
			$note = View::lead( 'info', __( 'Nothing changed.', 'estasoft-news-distribution' ), __( 'That article no longer shares its ID with another one.', 'estasoft-news-distribution' ) );
		} elseif ( 'denied' === $done ) {
			$note = View::lead( 'danger', __( 'You may not edit that article.', 'estasoft-news-distribution' ), __( 'Ask someone who can edit it to give the copy a new ID.', 'estasoft-news-distribution' ) );
		}

		$counts = $model['counts'];
		$others = $counts['same_title'] + $counts['same_address'] + $counts['near_duplicate'];

		if ( $counts['same_id'] > 0 ) {
			return $note . View::lead(
				'danger',
				sprintf(
					/* translators: %s: number of clashes. */
					_n( '%s clash can hide an article.', '%s clashes can hide an article.', $counts['same_id'], 'estasoft-news-distribution' ),
					number_format_i18n( $counts['same_id'] )
				),
				_n(
					'Two articles share one ID. Use the button on the first row to give the copy a new ID.',
					'Some articles share one ID. Use the button on each of those rows to give the copy a new ID.',
					$counts['same_id'],
					'estasoft-news-distribution'
				)
			);
		}

		if ( $others > 0 ) {
			return $note . View::lead(
				'warning',
				sprintf(
					/* translators: %s: number of clashes. */
					_n( '%s clash to look at.', '%s clashes to look at.', $others, 'estasoft-news-distribution' ),
					number_format_i18n( $others )
				),
				__( 'No article shares an ID with another, so none is hidden. Open the articles and decide what to keep.', 'estasoft-news-distribution' )
			);
		}

		return $note . View::lead(
			'success',
			__( 'No clashes found.', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: 1: number of articles, 2: number of days. */
				_n( 'Compared %1$s article from the last %2$s days.', 'Compared %1$s articles from the last %2$s days.', $model['scanned'], 'estasoft-news-distribution' ),
				number_format_i18n( $model['scanned'] ),
				number_format_i18n( self::DAYS )
			)
		);
	}

	/**
	 * The word and the mark for a kind of clash.
	 *
	 * @param array<string, mixed> $clash A row of the model.
	 * @return string
	 */
	private function problem( array $clash ): string {
		switch ( $clash['type'] ) {
			case 'same_id':
				return View::mark( 'danger', __( 'Same ID on two articles', 'estasoft-news-distribution' ) );
			case 'same_title':
				return View::mark( 'warning', __( 'Same title in one feed', 'estasoft-news-distribution' ) );
			case 'same_address':
				return View::mark( 'warning', __( 'Same address in two languages', 'estasoft-news-distribution' ) );
			default:
				return View::mark( 'warning', __( 'Near-duplicate', 'estasoft-news-distribution' ) );
		}
	}

	/**
	 * Why a kind of clash matters, as the board says it.
	 *
	 * @param string $type The kind.
	 * @return string
	 */
	private function why( string $type ): string {
		switch ( $type ) {
			case 'same_id':
				return __( 'Platforms such as Microsoft Start read items with one ID as one article, so the copy may be skipped or may replace the original.', 'estasoft-news-distribution' );
			case 'same_title':
				return __( 'Two entries with one title are confusing for readers and for platforms that show a list.', 'estasoft-news-distribution' );
			case 'same_address':
				return __( 'Each language version needs its own address, or the language links and the sitemaps point at the wrong page.', 'estasoft-news-distribution' );
			default:
				return __( 'Two entries on one story look like a repeat in a list. Keep both if each adds something.', 'estasoft-news-distribution' );
		}
	}

	/**
	 * The words under the titles of a row.
	 *
	 * @param array<string, mixed> $clash A row of the model.
	 * @return string
	 */
	private function detail( array $clash ): string {
		$articles = $clash['articles'];

		switch ( $clash['type'] ) {
			case 'same_id':
				return __( 'The copy most likely came from a duplicate-post tool.', 'estasoft-news-distribution' );

			case 'same_title':
				$dates = array();

				foreach ( $articles as $article ) {
					$dates[] = wp_date( 'j M', (int) $article['published_ts'], wp_timezone() );
				}

				return sprintf(
					/* translators: 1: dates such as "12 Oct and 19 Oct", 2: a language name such as English. */
					_n( 'Published %1$s, in %2$s.', 'Published %1$s, all in %2$s.', count( $articles ), 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $dates ),
					View::language( (string) $articles[0]['language'] )
				);

			case 'same_address':
				$names = array();

				foreach ( $articles as $article ) {
					$names[] = View::language( (string) $article['language'] );
				}

				return sprintf(
					/* translators: %s: language names such as "Kurdish (Kurmanji) and Arabic". */
					__( 'The %s versions share one address.', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $names )
				);

			default:
				return sprintf(
					/* translators: 1: a percentage such as 88, 2: how far apart, such as "3 hours". */
					__( '%1$s%% similar text, published %2$s apart. Worked out on your site by comparing the words. No text is sent anywhere.', 'estasoft-news-distribution' ),
					number_format_i18n( (int) $clash['similarity'] ),
					$this->span( (int) $clash['apart'] )
				);
		}
	}

	/**
	 * A gap in time in words: "3 hours", "2 days".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private function span( int $seconds ): string {
		if ( $seconds < HOUR_IN_SECONDS ) {
			return __( 'less than an hour', 'estasoft-news-distribution' );
		}

		if ( $seconds < 2 * DAY_IN_SECONDS ) {
			$hours = (int) round( $seconds / HOUR_IN_SECONDS );

			return sprintf(
				/* translators: %s: number of hours. */
				_n( '%s hour', '%s hours', $hours, 'estasoft-news-distribution' ),
				number_format_i18n( $hours )
			);
		}

		$days = (int) round( $seconds / DAY_IN_SECONDS );

		return sprintf(
			/* translators: %s: number of days. */
			_n( '%s day', '%s days', $days, 'estasoft-news-distribution' ),
			number_format_i18n( $days )
		);
	}

	/**
	 * The Articles cell of a row: each title, then the detail line.
	 *
	 * @param array<string, mixed> $clash A row of the model.
	 * @return string
	 */
	private function articles_cell( array $clash ): string {
		$titles = '';

		foreach ( $clash['articles'] as $article ) {
			$title   = '' === (string) $article['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $article['title'];
			$titles .= Html::tag( 'span', array_merge( array( 'class' => 'estasoft-nd-strong' ), View::text_attributes( $title ) ), esc_html( $title ) );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			$titles . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->detail( $clash ) ) )
		);
	}

	/**
	 * The Action cell of a row.
	 *
	 * Opening two articles takes two links, because one link cannot open two screens without a script.
	 *
	 * @param array<string, mixed> $clash A row of the model.
	 * @return string
	 */
	private function action_cell( array $clash ): string {
		$articles = $clash['articles'];
		$buttons  = '';

		if ( 'same_id' === $clash['type'] ) {
			$copy     = (int) $articles[1]['id'];
			$buttons .= Html::tag(
				'form',
				array(
					'method' => 'post',
					'action' => $this->url(),
				),
				Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => self::FIELD,
						'value' => 'new_id',
					)
				)
				. Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => 'post',
						'value' => (string) $copy,
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
				. Button::submit(
					__( 'Give the copy a new ID', 'estasoft-news-distribution' ),
					array(
						'variant' => 'primary',
						'size'    => 'sm',
					)
				)
			);
		}

		foreach ( array_slice( $articles, 0, 2 ) as $index => $article ) {
			$url = (string) get_edit_post_link( (int) $article['id'], 'raw' );

			if ( '' === $url ) {
				continue;
			}

			$label = 0 === $index ? __( 'Open first', 'estasoft-news-distribution' ) : __( 'Open second', 'estasoft-news-distribution' );

			$buttons .= Button::link(
				$label,
				$url,
				array(
					'size'  => 'sm',
					'attrs' => array(
						'aria-label' => sprintf(
							/* translators: 1: "Open first" or "Open second", 2: the article title. */
							__( '%1$s: %2$s', 'estasoft-news-distribution' ),
							$label,
							(string) $article['title']
						),
					),
				)
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-start' ), $buttons );
	}

	public function render(): string {
		$model = $this->model();
		$cells = array();
		$rows  = array_slice( $model['clashes'], 0, self::MAX_ROWS );

		foreach ( $rows as $clash ) {
			$cells[] = array(
				$this->problem( $clash ),
				$this->articles_cell( $clash ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->why( (string) $clash['type'] ) ) ),
				$this->action_cell( $clash ),
			);
		}

		$table = '';

		if ( array() !== $cells ) {
			$footer = count( $model['clashes'] ) > count( $rows )
				? Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-table-footer' ),
					View::footer(
						sprintf(
							/* translators: 1: rows shown, 2: rows in all. */
							__( 'Showing %1$s of %2$s clashes.', 'estasoft-news-distribution' ),
							number_format_i18n( count( $rows ) ),
							number_format_i18n( count( $model['clashes'] ) )
						)
					)
				)
				: '';

			$table = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ),
				Grid::render(
					'estasoft-nd-cols-clashes',
					array(
						__( 'Problem', 'estasoft-news-distribution' ),
						__( 'Articles', 'estasoft-news-distribution' ),
						__( 'Why it matters', 'estasoft-news-distribution' ),
						__( 'Action', 'estasoft-news-distribution' ),
					),
					$cells,
					__( 'Clashes between articles', 'estasoft-news-distribution' )
				) . $footer
			);
		}

		$apart = array(
			__( 'The ID is stored on the post and never changes', 'estasoft-news-distribution' ),
			__( 'A copied post is given a new ID automatically, where News Distribution can tell it was copied', 'estasoft-news-distribution' ),
			__( 'Updates keep the original publish date', 'estasoft-news-distribution' ),
			__( 'A changed address is not a clash: the ID stays the same, so platforms see one article', 'estasoft-news-distribution' ),
		);
		$list  = '';

		foreach ( $apart as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'info' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return $this->lead( $model )
			. $table
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-notes' ),
				Card::note(
					'apart',
					__( 'How News Distribution tells articles apart', 'estasoft-news-distribution' ),
					Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Each article gets an ID the first time it is published, together with its first publish date. Both are kept for good, so a changed address or an edited date never makes a duplicate.', 'estasoft-news-distribution' ) )
					. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
				)
				. Card::cannot(
					array(
						__( 'Decide which of two articles to keep. Open both articles and choose', 'estasoft-news-distribution' ),
						__( 'Merge two articles into one', 'estasoft-news-distribution' ),
						__( 'See how a platform treats a repeated ID. It can only warn that the risk exists', 'estasoft-news-distribution' ),
					)
				)
			);
	}
}
