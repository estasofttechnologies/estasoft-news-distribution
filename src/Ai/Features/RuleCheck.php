<?php
/**
 * Checks AI text with the same rules as text a person wrote.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A suggestion is never shown before it has been through this class. A title goes through the article checks and the
 * rule packs exactly as the stored title does: the article is read again with the suggested title in place of its
 * own, `ArticleChecker::build()` runs on that copy (it stores nothing and changes nothing), and the suggestion is
 * kept only when no check that matters raises a risk or a block about the title.
 *
 * A problem that was there before the title (such as a page title an SEO plugin wrote with the publication name in
 * it) is not held against a suggestion, so the same problem is found by running the checks once on a harmless
 * title and ignoring what it says.
 *
 * Each check comes back as a line a person can read: which platform, and what passed or failed.
 */
final class RuleCheck {

	/**
	 * Most characters of alt text. This is News Distribution's own style rule, not a platform's.
	 */
	public const ALT_MAX = 125;

	/**
	 * Most characters of a feed summary when no platform pack names a maximum (`summary_max` or `summary_recommended_max`).
	 * This is News Distribution's own default, not a platform's rule, and the screens say so.
	 */
	public const SUMMARY_DEFAULT_MAX = 300;

	/**
	 * Most characters of a search description. This is News Distribution's own style limit, not a platform's rule.
	 */
	public const DESCRIPTION_MAX = 155;

	/**
	 * Most characters of an image caption. This is News Distribution's own style limit, not a platform's rule.
	 */
	public const CAPTION_MAX = 200;

	/**
	 * How many words from the start of the article a summary is held against.
	 */
	private const OPENING_WORDS = 40;

	/**
	 * How many words in a row, taken unchanged from the opening, make a summary a copy.
	 */
	private const COPY_RUN = 8;

	/**
	 * The script each language is written in, for the languages whose script can be told apart from the others here.
	 * A language that is not listed is not checked for its script.
	 */
	private const SCRIPTS = array(
		'ar'  => 'Arabic',
		'fa'  => 'Arabic',
		'ur'  => 'Arabic',
		'ps'  => 'Arabic',
		'ckb' => 'Arabic',
		'he'  => 'Hebrew',
		'yi'  => 'Hebrew',
		'en'  => 'Latin',
		'ku'  => 'Latin',
		'es'  => 'Latin',
		'fr'  => 'Latin',
		'nl'  => 'Latin',
		'de'  => 'Latin',
		'it'  => 'Latin',
		'pt'  => 'Latin',
		'tr'  => 'Latin',
	);

	/**
	 * A title that breaks no title rule, used to find what the checks say regardless of the title.
	 */
	private const NEUTRAL = 'A calm headline of an ordinary length';

	private Plugin $plugin;

	/**
	 * What the checks say about the harmless title, by article.
	 *
	 * @var array<string, string[]>
	 */
	private array $baseline = array();

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The article as it would be read with other values.
	 *
	 * @param Article              $article   The article.
	 * @param array<string, mixed> $overrides Values to replace, keyed like the article's own data (`title`, `language`).
	 * @return Article
	 */
	public function variant( Article $article, array $overrides ): Article {
		return new Article(
			array_merge(
				array(
					'id'               => $article->id(),
					'title'            => $article->title(),
					'content'          => $article->content(),
					'permalink'        => $article->permalink(),
					'language'         => $article->language(),
					'author_name'      => $article->author_name(),
					'author_url'       => $article->author_url(),
					'thumbnail_url'    => $article->thumbnail_url(),
					'thumbnail_width'  => $article->thumbnail_width(),
					'thumbnail_height' => $article->thumbnail_height(),
					'categories'       => $article->categories(),
					'tags'             => $article->tags(),
					'published_ts'     => $article->published_ts(),
					'modified_ts'      => $article->modified_ts(),
					'post_type'        => $article->post_type(),
					'status'           => $article->status(),
					'author_id'        => $article->author_id(),
					'summary'          => $article->summary(),
				),
				$overrides
			)
		);
	}

	/**
	 * What the title must do, from the packs of the channels that are on and take the article's language.
	 *
	 * @param Article $article The article.
	 * @return array{min: int, max: int, name: bool, author: bool, date: bool}
	 */
	public function constraints( Article $article ): array {
		$rules = $this->plugin->rules();
		$min   = 0;
		$max   = 0;
		$out   = array(
			'min'    => 0,
			'max'    => 0,
			'name'   => false,
			'author' => false,
			'date'   => false,
		);

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $article->language() ) ) {
				continue;
			}

			$id = $channel->id();

			foreach ( array( 'title_max', 'title_recommended_max' ) as $name ) {
				$value = $rules->limit( $id, $name, null );

				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$max = 0 === $max ? (int) $value : min( $max, (int) $value );
				}
			}

			foreach ( array( 'title_min', 'title_recommended_min' ) as $name ) {
				$value = $rules->limit( $id, $name, null );

				if ( is_numeric( $value ) && (int) $value > 0 ) {
					$min = max( $min, (int) $value );
				}
			}

			$out['name']   = $out['name'] || $this->has_rule( $id, array( 'title.publication_name', 'title.has_publication_name' ) );
			$out['author'] = $out['author'] || $this->has_rule( $id, array( 'title.author', 'title.has_author' ) );
			$out['date']   = $out['date'] || $this->has_rule( $id, array( 'title.date', 'title.has_date' ) );
		}

		if ( 0 === $max ) {
			$general = $rules->limit( 'article-checks', 'title_max', null );
			$max     = is_numeric( $general ) ? (int) $general : 0;
		}

		$out['min'] = $min;
		$out['max'] = $max;

		return $out;
	}

	/**
	 * Checks a title for an article.
	 *
	 * @param Article $article The article the title is for.
	 * @param string  $title   The suggested title.
	 * @return array{pass: bool, lines: array<int, array{ok: bool, label: string, text: string}>}
	 */
	public function title( Article $article, string $title ): array {
		$title = trim( $title );
		$lines = array();

		$stripped = wp_strip_all_tags( $title );

		if ( '' === $title || false !== strpos( $title, "\n" ) || $stripped !== $title ) {
			return array(
				'pass'  => false,
				'lines' => array(
					array(
						'ok'    => false,
						'label' => '',
						'text'  => __( 'A title is one line of plain text.', 'estasoft-news-distribution' ),
					),
				),
			);
		}

		$ignored  = $this->ignored( $article );
		$report   = $this->plugin->make( ArticleChecker::class )->build( $this->variant( $article, array( 'title' => $title ) ) );
		$channels = $report->channels();
		$pass     = true;

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $article->language() ) ) {
				continue;
			}

			$found = array();

			foreach ( (array) ( $channels[ $channel->id() ]['findings'] ?? array() ) as $finding ) {
				if ( $this->counts( $finding, $ignored ) ) {
					$found[ (string) $finding['code'] ] = (string) $finding['message'];
				}
			}

			$rules = array(
				'length' => array(
					'declared' => $this->has_limit( $channel->id(), 'title_max' ) || $this->has_limit( $channel->id(), 'title_min' ) || $this->has_limit( $channel->id(), 'title_recommended_max' ) || $this->has_limit( $channel->id(), 'title_recommended_min' ),
					'codes'    => array( 'title.long', 'title.short' ),
					'ok'       => __( 'length is within its rule', 'estasoft-news-distribution' ),
				),
				'name'   => array(
					'declared' => $this->has_rule( $channel->id(), array( 'title.publication_name', 'title.has_publication_name' ) ),
					'codes'    => array( 'title.publication_name' ),
					'ok'       => __( 'no publication name', 'estasoft-news-distribution' ),
				),
				'author' => array(
					'declared' => $this->has_rule( $channel->id(), array( 'title.author', 'title.has_author' ) ),
					'codes'    => array( 'title.author' ),
					'ok'       => __( 'no author name', 'estasoft-news-distribution' ),
				),
				'date'   => array(
					'declared' => $this->has_rule( $channel->id(), array( 'title.date', 'title.has_date' ) ),
					'codes'    => array( 'title.date' ),
					'ok'       => __( 'no date', 'estasoft-news-distribution' ),
				),
			);

			foreach ( $rules as $rule ) {
				if ( ! $rule['declared'] ) {
					continue;
				}

				$failed = array_values( array_intersect( $rule['codes'], array_keys( $found ) ) );
				$ok     = array() === $failed;
				$pass   = $pass && $ok;

				$lines[] = array(
					'ok'    => $ok,
					'label' => $channel->label(),
					'text'  => $ok ? $rule['ok'] : (string) $found[ $failed[0] ],
				);
			}
		}

		// A title can break a rule that the article checks apply to every channel, even where no pack names it.
		foreach ( $report->findings() as $finding ) {
			if ( ! $this->counts( $finding, $ignored ) ) {
				continue;
			}

			$already = false;

			foreach ( $lines as $line ) {
				if ( ! $line['ok'] && $line['text'] === (string) $finding['message'] ) {
					$already = true;
				}
			}

			if ( ! $already ) {
				$lines[] = array(
					'ok'    => false,
					'label' => '',
					'text'  => (string) $finding['message'],
				);
				$pass    = false;
			}
		}

		if ( array() === $lines ) {
			$lines[] = array(
				'ok'    => true,
				'label' => '',
				'text'  => __( 'The title passes the title checks', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'pass'  => $pass,
			'lines' => $lines,
		);
	}

	/**
	 * Checks alt text.
	 *
	 * @param string $alt The suggested alt text.
	 * @return array{pass: bool, lines: array<int, array{ok: bool, label: string, text: string}>}
	 */
	public function alt( string $alt ): array {
		$alt    = trim( $alt );
		$length = mb_strlen( $alt );
		$prefix = 1 === preg_match( '/^\s*(?:an?\s+)?(?:image|picture|photo|photograph|graphic)\s+(?:of|showing)\b/iu', $alt );
		$bare   = wp_strip_all_tags( $alt );
		$plain  = $bare === $alt && false === strpos( $alt, "\n" );
		$file   = 1 === preg_match( '/\.(?:jpe?g|png|gif|webp|avif)\b/i', $alt );
		$fits   = $length > 0 && $length < self::ALT_MAX;
		$clean  = $plain && ! $file;

		if ( 0 === $length ) {
			$size = __( 'Is empty', 'estasoft-news-distribution' );
		} elseif ( ! $clean ) {
			$size = __( 'Plain text without a file name', 'estasoft-news-distribution' );
		} elseif ( ! $fits ) {
			$size = sprintf(
				/* translators: 1: number of characters, 2: the most characters allowed. */
				__( '%1$s characters, over the %2$s allowed', 'estasoft-news-distribution' ),
				number_format_i18n( $length ),
				number_format_i18n( self::ALT_MAX )
			);
		} else {
			$size = sprintf(
				/* translators: 1: number of characters, 2: the most characters allowed. */
				_n( '%1$s character, under %2$s', '%1$s characters, under %2$s', $length, 'estasoft-news-distribution' ),
				number_format_i18n( $length ),
				number_format_i18n( self::ALT_MAX )
			);
		}

		return array(
			'pass'  => ! $prefix && $fits && $clean,
			'lines' => array(
				array(
					'ok'    => ! $prefix,
					'label' => '',
					'text'  => $prefix ? __( 'Begins with “image of”', 'estasoft-news-distribution' ) : __( 'Does not begin “image of”', 'estasoft-news-distribution' ),
				),
				array(
					'ok'    => $fits && $clean,
					'label' => '',
					'text'  => $size,
				),
			),
		);
	}

	/**
	 * The most characters a feed summary may have: the smallest `summary_max` or `summary_recommended_max` that the packs of
	 * the platforms that are on and take the article's language give, or News Distribution's own default when none does.
	 *
	 * No bundled pack names one today, because the platform pages that were read do not state a length for the text under a headline.
	 *
	 * @param Article $article The article.
	 * @return array{max: int, source: string, platform: string} `source` is `rules` (a pack gave it, and `platform` names the platform) or `default`.
	 */
	public function summary_limit( Article $article ): array {
		$rules    = $this->plugin->rules();
		$max      = 0;
		$platform = '';

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( ! $channel->accepts_language( $article->language() ) ) {
				continue;
			}

			foreach ( array( 'summary_max', 'summary_recommended_max' ) as $name ) {
				$value = $rules->limit( $channel->id(), $name, null );

				if ( is_numeric( $value ) && (int) $value > 0 && ( 0 === $max || (int) $value < $max ) ) {
					$max      = (int) $value;
					$platform = $channel->label();
				}
			}
		}

		if ( $max > 0 ) {
			return array(
				'max'      => $max,
				'source'   => 'rules',
				'platform' => $platform,
			);
		}

		return array(
			'max'      => self::SUMMARY_DEFAULT_MAX,
			'source'   => 'default',
			'platform' => '',
		);
	}

	/**
	 * Checks a feed summary: plain text on one line, within the limit, one or two sentences, not a copy of the opening of the
	 * article, and in the language of the article.
	 *
	 * @param Article $article The article the summary is for.
	 * @param string  $text    The suggested summary.
	 * @param int     $max     The most characters (see summary_limit()).
	 * @return array{pass: bool, lines: array<int, array{ok: bool, level: string, label: string, text: string}>}
	 */
	public function summary( Article $article, string $text, int $max ): array {
		return $this->prose( $article, $text, $max, true );
	}

	/**
	 * Checks a search description: plain text on one line, within DESCRIPTION_MAX, not a copy of the opening of the article,
	 * and in the language of the article.
	 *
	 * @param Article $article The article the description is for.
	 * @param string  $text    The suggested description.
	 * @return array{pass: bool, lines: array<int, array{ok: bool, level: string, label: string, text: string}>}
	 */
	public function description( Article $article, string $text ): array {
		return $this->prose( $article, $text, self::DESCRIPTION_MAX, false );
	}

	/**
	 * Checks an image caption: plain text on one line, within CAPTION_MAX, without the photo credit in it, not the alt text
	 * again, and in the language of the article.
	 *
	 * @param Article $article The article the photo belongs to.
	 * @param string  $text    The suggested caption.
	 * @param string  $credit  The photo's credit, or empty.
	 * @param string  $alt     The photo's alt text, or empty.
	 * @return array{pass: bool, lines: array<int, array{ok: bool, level: string, label: string, text: string}>}
	 */
	public function caption( Article $article, string $text, string $credit = '', string $alt = '' ): array {
		$text  = trim( $text );
		$lines = array(
			$this->line( $this->is_plain( $text ), __( 'Plain text on one line', 'estasoft-news-distribution' ), __( 'Has markup, a web address or more than one line', 'estasoft-news-distribution' ) ),
			$this->length_line( $text, self::CAPTION_MAX ),
		);

		// The credit as stored ("Photo: Omar Ali") and the name without its label ("Omar Ali") are both kept out of the text.
		$credit     = trim( $credit );
		$name       = trim( (string) preg_replace( '/^\s*(?:photo(?:graph)?|image|picture|credit|source|foto)\s*[:\/\-–—]\s*/iu', '', $credit ) );
		$has_credit = false;

		foreach ( array_unique( array( $credit, $name ) ) as $needle ) {
			if ( mb_strlen( $needle ) >= 3 && false !== mb_stripos( $text, $needle ) ) {
				$has_credit = true;
			}
		}

		$credit_mark = 1 === preg_match( '/(?:^|\s)(?:photo|photograph|picture|image|credit|foto)\s*:|©/iu', $text );

		$lines[] = $this->line( ! $has_credit && ! $credit_mark, __( 'Does not repeat the credit', 'estasoft-news-distribution' ), __( 'Has the credit in it. The credit is printed on its own', 'estasoft-news-distribution' ) );

		$same = '' !== trim( $alt ) && implode( ' ', $this->words( $alt ) ) === implode( ' ', $this->words( $text ) );

		$lines[] = $this->line( ! $same, __( 'Is not the alt text again', 'estasoft-news-distribution' ), __( 'Is the same as the alt text', 'estasoft-news-distribution' ) );

		$language = $this->language_line( $article, $text );

		if ( null !== $language ) {
			$lines[] = $language;
		}

		return $this->result( $lines );
	}

	/**
	 * The checks a summary and a description share.
	 *
	 * @param Article $article   The article.
	 * @param string  $text      The suggested text.
	 * @param int     $max       The most characters.
	 * @param bool    $sentences Whether to hold the text to two sentences.
	 * @return array{pass: bool, lines: array<int, array{ok: bool, level: string, label: string, text: string}>}
	 */
	private function prose( Article $article, string $text, int $max, bool $sentences ): array {
		$text  = trim( $text );
		$lines = array(
			$this->line( $this->is_plain( $text ), __( 'Plain text on one line', 'estasoft-news-distribution' ), __( 'Has markup, a web address or more than one line', 'estasoft-news-distribution' ) ),
			$this->length_line( $text, $max ),
		);

		if ( $sentences ) {
			$lines[] = $this->line( $this->sentence_count( $text ) <= 2, __( 'One or two sentences', 'estasoft-news-distribution' ), __( 'More than two sentences', 'estasoft-news-distribution' ) );
		}

		$lines[] = $this->line(
			! $this->copies_opening( $article, $text ),
			sprintf(
				/* translators: %d: number of words from the start of the article. */
				__( 'Does not copy the first %d words', 'estasoft-news-distribution' ),
				self::OPENING_WORDS
			),
			sprintf(
				/* translators: %d: number of words from the start of the article. */
				__( 'Copies the first %d words word for word', 'estasoft-news-distribution' ),
				self::OPENING_WORDS
			)
		);

		$language = $this->language_line( $article, $text );

		if ( null !== $language ) {
			$lines[] = $language;
		}

		return $this->result( $lines );
	}

	/**
	 * Puts check lines in the shape the screens and the REST answers use.
	 *
	 * @param array<int, array{ok: bool, level: string, label: string, text: string}> $lines The lines.
	 * @return array{pass: bool, lines: array<int, array{ok: bool, level: string, label: string, text: string}>}
	 */
	private function result( array $lines ): array {
		$pass = true;

		foreach ( $lines as $line ) {
			$pass = $pass && $line['ok'];
		}

		return array(
			'pass'  => $pass,
			'lines' => $lines,
		);
	}

	/**
	 * One check line.
	 *
	 * @param bool   $ok   Whether the check passed.
	 * @param string $pass The words when it passed.
	 * @param string $fail The words when it failed.
	 * @return array{ok: bool, level: string, label: string, text: string}
	 */
	private function line( bool $ok, string $pass, string $fail ): array {
		return array(
			'ok'    => $ok,
			'level' => $ok ? 'pass' : 'fail',
			'label' => '',
			'text'  => $ok ? $pass : $fail,
		);
	}

	/**
	 * The length check line.
	 *
	 * @param string $text The text.
	 * @param int    $max  The most characters.
	 * @return array{ok: bool, level: string, label: string, text: string}
	 */
	private function length_line( string $text, int $max ): array {
		$length = mb_strlen( $text );

		if ( 0 === $length ) {
			return $this->line( false, '', __( 'Is empty', 'estasoft-news-distribution' ) );
		}

		return $this->line(
			$length <= $max,
			sprintf(
				/* translators: 1: number of characters, 2: the most characters allowed. */
				_n( '%1$s character, within the %2$s allowed', '%1$s characters, within the %2$s allowed', $length, 'estasoft-news-distribution' ),
				number_format_i18n( $length ),
				number_format_i18n( $max )
			),
			sprintf(
				/* translators: 1: number of characters, 2: the most characters allowed. */
				__( '%1$s characters, over the %2$s allowed', 'estasoft-news-distribution' ),
				number_format_i18n( $length ),
				number_format_i18n( $max )
			)
		);
	}

	/**
	 * Whether a text is plain: one line, no tags, no entities, no markdown, no web address.
	 *
	 * @param string $text The text, trimmed.
	 * @return bool
	 */
	private function is_plain( string $text ): bool {
		if ( '' === $text || 1 === preg_match( '/[\r\n]/', $text ) || wp_strip_all_tags( $text ) !== $text ) {
			return false;
		}

		if ( 1 === preg_match( '/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]+);/i', $text ) ) {
			return false;
		}

		return 1 !== preg_match( '/\*\*|__|`|\[[^\]]*\]\([^)]*\)|^#{1,6}\s|https?:\/\/|www\./i', $text );
	}

	/**
	 * How many sentences a text has. A full stop after an initial or an abbreviation such as "Dr." does not end one.
	 *
	 * @param string $text The text.
	 * @return int
	 */
	private function sentence_count( string $text ): int {
		$parts = preg_split( '/(?<=[.!?\x{061F}\x{06D4}\x{3002}])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! is_array( $parts ) ) {
			return 1;
		}

		$count = 0;
		$carry = false;

		foreach ( $parts as $part ) {
			if ( ! $carry ) {
				++$count;
			}

			$carry = 1 === preg_match( '/(?:\b\p{Lu}|\b(?:Mr|Mrs|Ms|Dr|St|No|Jr|Sr|vs|etc))\.$/u', $part );
		}

		return $count;
	}

	/**
	 * The words of a text in lower case, without tags, entities or punctuation.
	 *
	 * @param string $text The text.
	 * @return string[]
	 */
	private function words( string $text ): array {
		$text  = mb_strtolower( html_entity_decode( wp_strip_all_tags( strip_shortcodes( $text ) ), ENT_QUOTES, 'UTF-8' ) );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $parts ) ? array_values( $parts ) : array();
	}

	/**
	 * Whether a text copies the start of the article: a run of COPY_RUN words taken unchanged from its first OPENING_WORDS words,
	 * or a quoted passage of two words or more that stands there unchanged.
	 *
	 * @param Article $article The article.
	 * @param string  $text    The text.
	 * @return bool
	 */
	private function copies_opening( Article $article, string $text ): bool {
		$opening = array_slice( $this->words( $article->content() ), 0, self::OPENING_WORDS );
		$mine    = $this->words( $text );
		$run     = min( self::COPY_RUN, count( $opening ) );

		$opening_count = count( $opening );
		$mine_count    = count( $mine );

		if ( $run >= 4 && $mine_count >= $run ) {
			$grams = array();

			for ( $i = 0; $i + $run <= $opening_count; $i++ ) {
				$grams[ implode( ' ', array_slice( $opening, $i, $run ) ) ] = true;
			}

			for ( $i = 0; $i + $run <= $mine_count; $i++ ) {
				if ( isset( $grams[ implode( ' ', array_slice( $mine, $i, $run ) ) ] ) ) {
					return true;
				}
			}
		}

		$flat = ' ' . implode( ' ', $opening ) . ' ';

		if ( preg_match_all( '/["“«„]([^"”»“]{3,}?)["”»]/u', $text, $quoted ) ) {
			foreach ( $quoted[1] as $passage ) {
				$words = $this->words( $passage );

				if ( count( $words ) >= 2 && false !== strpos( $flat, ' ' . implode( ' ', $words ) . ' ' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a text is in the language of the article, as far as the script and the words can tell.
	 *
	 * A language written in Arabic or Hebrew letters must be mostly in those letters. A language written in Latin letters
	 * must be mostly in Latin letters, and at least a fifth of the first four letters of its longer words must be found in the article.
	 * It cannot tell apart two languages that share a script and words. A language not in SCRIPTS is not checked.
	 *
	 * @param Article $article The article.
	 * @param string  $text    The text.
	 * @return array{ok: bool, level: string, label: string, text: string}|null Null when the article's language is not one this can check.
	 */
	private function language_line( Article $article, string $text ): ?array {
		$code   = Catalog::primary( $article->language() );
		$script = self::SCRIPTS[ $code ] ?? '';

		if ( '' === $script ) {
			return null;
		}

		$name    = Catalog::name( $code );
		$letters = (int) preg_match_all( '/\p{L}/u', $text );
		$wanted  = (int) preg_match_all( '/\p{' . $script . '}/u', $text );
		$ok      = $letters > 0 && $wanted / $letters >= 0.6;

		if ( $ok && 'Latin' === $script ) {
			$mine = $this->stems( $text );

			if ( array() !== $mine ) {
				$shared = count( array_intersect_key( $mine, $this->stems( $article->title() . ' ' . $article->content() ) ) );
				$ok     = $shared / count( $mine ) >= 0.2;
			}
		}

		return $this->line(
			$ok,
			sprintf(
				/* translators: %s: a language name such as English. */
				__( 'Written in %s', 'estasoft-news-distribution' ),
				$name
			),
			sprintf(
				/* translators: %s: a language name such as English. */
				__( 'Does not look like %s, the language of the article', 'estasoft-news-distribution' ),
				$name
			)
		);
	}

	/**
	 * The first four letters of each word of four letters or more, without accents, as keys.
	 *
	 * @param string $text The text.
	 * @return array<string|int, true>
	 */
	private function stems( string $text ): array {
		$out = array();

		foreach ( $this->words( remove_accents( $text ) ) as $word ) {
			if ( mb_strlen( $word ) >= 4 ) {
				$out[ mb_substr( $word, 0, 4 ) ] = true;
			}
		}

		return $out;
	}

	/**
	 * Whether a finding is about the title and serious enough to refuse a suggestion for.
	 *
	 * @param array<string, mixed> $finding A finding.
	 * @param string[]             $ignored Messages the checks gave for a harmless title.
	 * @return bool
	 */
	private function counts( array $finding, array $ignored ): bool {
		return 0 === strpos( (string) ( $finding['code'] ?? '' ), 'title.' )
			&& in_array( $finding['severity'] ?? '', array( Status::BLOCK, Status::WARN ), true )
			&& ! in_array( (string) ( $finding['message'] ?? '' ), $ignored, true );
	}

	/**
	 * What the checks say about the title whatever it is: messages found with a harmless title.
	 *
	 * @param Article $article The article.
	 * @return string[]
	 */
	private function ignored( Article $article ): array {
		$id = $article->id() . '|' . $article->language() . '|' . md5( $article->content() . '|' . $article->modified_ts() . '|' . $article->author_name() );

		if ( ! isset( $this->baseline[ $id ] ) ) {
			$report   = $this->plugin->make( ArticleChecker::class )->build( $this->variant( $article, array( 'title' => self::NEUTRAL ) ) );
			$messages = array();

			foreach ( $report->findings() as $finding ) {
				if ( 0 === strpos( (string) $finding['code'], 'title.' ) ) {
					$messages[] = (string) $finding['message'];
				}
			}

			$this->baseline[ $id ] = $messages;
		}

		return $this->baseline[ $id ];
	}

	/**
	 * Whether a platform's pack names a numeric limit.
	 *
	 * @param string $platform Platform id.
	 * @param string $limit    Limit name.
	 * @return bool
	 */
	private function has_limit( string $platform, string $limit ): bool {
		return is_numeric( $this->plugin->rules()->limit( $platform, $limit, null ) );
	}

	/**
	 * Whether a platform's pack has a rule under one of these codes.
	 *
	 * @param string   $platform Platform id.
	 * @param string[] $codes    Finding codes the pack may use.
	 * @return bool
	 */
	private function has_rule( string $platform, array $codes ): bool {
		foreach ( $codes as $code ) {
			if ( array() !== $this->plugin->rules()->rule( $platform, $code ) ) {
				return true;
			}
		}

		return false;
	}
}
