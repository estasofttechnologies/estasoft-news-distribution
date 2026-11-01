<?php
/**
 * Reads the body each channel would send, looking for leftovers.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * For the Body rendering report. It asks each channel that carries article text to build its
 * document for one article at a time, exactly as the feed does, and reads the body back.
 * It sends nothing and fetches nothing.
 *
 * What it looks for:
 *
 * - `shortcode`: a shortcode that is still text in the body.
 * - `builder`: a tag whose class belongs to a page builder (the common names, not a complete list).
 * - `empty`: an empty paragraph, list or block.
 * - `embed`: an iframe, script or embed in the saved article that some channel leaves out.
 *
 * Shape of `scan()`:
 *
 *     array(
 *       'at'       => int,                                   // Unix time
 *       'channels' => array( array( 'id' => string, 'label' => string ) ),
 *       'articles' => int,                                   // articles read
 *       'affected' => int,                                   // articles with at least one finding
 *       'counts'   => array( 'all' => int, 'shortcodes' => int, 'builder' => int, 'other' => int ),
 *       'rows'     => array( array(
 *         'article' => int, 'title' => string, 'language' => string,
 *         'kind' => string, 'group' => string, 'found' => string, 'count' => int,
 *         'channels' => string[],
 *         'context' => array( 'channel' => string, 'lines' => string[], 'marked' => int ),
 *       ) ),
 *     )
 */
final class Rendering {

	/**
	 * The most articles read for one channel and language.
	 */
	private const PER_LANGUAGE = 30;

	/**
	 * The most seconds spent in all.
	 */
	private const BUDGET = 25;

	/**
	 * Class names that belong to page builders, as the start of a class. These are the usual names, not every builder.
	 *
	 * @var string[]
	 */
	private const BUILDER_PREFIXES = array(
		'elementor',
		'et_pb_',
		'et-db',
		'vc_',
		'wpb_',
		'fl-row',
		'fl-col',
		'fl-module',
		'fl-builder',
		'fusion-',
		'brxe-',
		'bricks-',
		'oxy-',
		'so-panel',
		'panel-grid',
		'themify_builder',
		'kt-row',
		'kt-blocks',
		'wp-block-kadence',
		'uagb-',
		'wp-block-uagb',
		'tve_',
		'siteorigin-',
	);

	private Plugin $plugin;

	/**
	 * Creates the reader.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The channels whose documents carry article text: partner feeds and feeds for readers that are on.
	 *
	 * @return ChannelInterface[]
	 */
	public function channels(): array {
		$found = array();

		foreach ( $this->plugin->registry()->pulled() as $channel ) {
			if ( ! $channel->is_enabled() || '' === $channel->feed_slug() ) {
				continue;
			}

			if ( in_array( $channel->group(), array( Group::PARTNER, Group::READERS ), true ) ) {
				$found[] = $channel;
			}
		}

		return $found;
	}

	/**
	 * Reads the recent articles through every channel.
	 *
	 * @return array<string, mixed> See the class description.
	 */
	public function scan(): array {
		$channels = $this->channels();
		$started  = microtime( true );
		$seen     = array();
		$rows     = array();

		foreach ( $channels as $channel ) {
			foreach ( array_unique( array_map( 'strval', $this->plugin->languages()->languages() ) ) as $language ) {
				if ( ! $channel->accepts_language( $language ) ) {
					continue;
				}

				foreach ( $this->articles( $channel, $language ) as $article ) {
					if ( microtime( true ) - $started > self::BUDGET ) {
						break 3;
					}

					$seen[ $article->id() ] = true;
					$rows                   = $this->read( $rows, $channel, $article, $language );
				}
			}
		}

		return $this->result( $channels, $rows, count( $seen ) );
	}

	/**
	 * The articles a channel's document would carry in a language.
	 *
	 * @param ChannelInterface $channel  The channel.
	 * @param string           $language Language code.
	 * @return Article[]
	 */
	private function articles( ChannelInterface $channel, string $language ): array {
		$window   = $channel->feed_window();
		$articles = $this->plugin->reader()->recent( $language, (int) $window['max_age'], min( self::PER_LANGUAGE, max( 1, (int) $window['limit'] ) ) );

		/** This filter is documented in src/Frontend/FeedController.php. */
		$kept = apply_filters( 'estasoft_nd_internal_feed_articles', $articles, $channel, $language );

		return is_array( $kept ) ? array_values( $kept ) : $articles;
	}

	/**
	 * Reads one article through one channel and adds what it finds to the rows.
	 *
	 * @param array<string, array<string, mixed>> $rows     Findings so far, by "article:kind".
	 * @param ChannelInterface                    $channel  The channel.
	 * @param Article                             $article  The article.
	 * @param string                              $language Language code.
	 * @return array<string, array<string, mixed>>
	 */
	private function read( array $rows, ChannelInterface $channel, Article $article, string $language ): array {
		try {
			$document = $channel->build( array( $article ), $language );
		} catch ( \Throwable $error ) {
			return $rows;
		}

		$body  = $this->body_of( $document );
		$lines = $this->lines( $body );
		$found = array(
			'shortcode' => $this->shortcodes( $body ),
			'builder'   => $this->builder( $body ),
			'empty'     => $this->empties( $body ),
		);

		foreach ( $found as $kind => $hit ) {
			if ( 0 === $hit['count'] ) {
				continue;
			}

			$key = $article->id() . ':' . $kind;

			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = $this->row( $article, $language, $kind, (string) $hit['found'], (int) $hit['count'], $channel->label(), $lines, (string) $hit['sample'] );
			}

			$rows[ $key ]['channels'][] = $channel->id();
		}

		$source = $article->content();

		if ( 1 === preg_match( '/<(iframe|script|embed|object)\b/i', $source ) && 0 === preg_match( '/<(iframe|script|embed|object)\b/i', $body ) ) {
			$key = $article->id() . ':embed';

			if ( ! isset( $rows[ $key ] ) ) {
				$hit          = $this->embed( $source );
				$rows[ $key ] = $this->row( $article, $language, 'embed', (string) $hit['found'], (int) $hit['count'], '', $this->lines( $source ), (string) $hit['sample'] );
			}

			$rows[ $key ]['channels'][] = $channel->id();
		}

		return $rows;
	}

	/**
	 * A row of the result.
	 *
	 * @param Article  $article  The article.
	 * @param string   $language Language code.
	 * @param string   $kind     shortcode, builder, empty or embed.
	 * @param string   $found    What was found, as short text.
	 * @param int      $count    How many were found.
	 * @param string   $channel  The channel the context comes from, or empty when it comes from the saved article.
	 * @param string[] $lines    The lines of the body.
	 * @param string   $sample   The text to find in the lines.
	 * @return array<string, mixed>
	 */
	private function row( Article $article, string $language, string $kind, string $found, int $count, string $channel, array $lines, string $sample ): array {
		return array(
			'article'  => $article->id(),
			'title'    => $article->title(),
			'language' => $language,
			'kind'     => $kind,
			'group'    => 'shortcode' === $kind ? 'shortcodes' : ( 'builder' === $kind ? 'builder' : 'other' ),
			'found'    => $found,
			'count'    => $count,
			'channels' => array(),
			'context'  => $this->context( $lines, $sample, $channel ),
			'at'       => $article->published_ts(),
		);
	}

	/**
	 * Turns the rows into the result.
	 *
	 * @param ChannelInterface[]                  $channels The channels read.
	 * @param array<string, array<string, mixed>> $rows     Findings by "article:kind".
	 * @param int                                 $articles Articles read.
	 * @return array<string, mixed>
	 */
	private function result( array $channels, array $rows, int $articles ): array {
		$order = array(
			'shortcode' => 1,
			'builder'   => 2,
			'empty'     => 3,
			'embed'     => 4,
		);

		usort(
			$rows,
			static function ( array $a, array $b ) use ( $order ): int {
				$by_date = (int) $b['at'] <=> (int) $a['at'];

				return 0 !== $by_date ? $by_date : $order[ $a['kind'] ] <=> $order[ $b['kind'] ];
			}
		);

		$counts   = array(
			'all'        => count( $rows ),
			'shortcodes' => 0,
			'builder'    => 0,
			'other'      => 0,
		);
		$affected = array();

		foreach ( $rows as $index => $row ) {
			++$counts[ $row['group'] ];
			$affected[ $row['article'] ] = true;
			$rows[ $index ]['channels']  = array_values( array_unique( $row['channels'] ) );
			unset( $rows[ $index ]['at'] );
		}

		$labels = array();

		foreach ( $channels as $channel ) {
			$labels[] = array(
				'id'    => $channel->id(),
				'label' => $channel->label(),
			);
		}

		return array(
			'at'       => Clock::now(),
			'channels' => $labels,
			'articles' => $articles,
			'affected' => count( $affected ),
			'counts'   => $counts,
			'rows'     => $rows,
		);
	}

	/**
	 * The article body inside a channel's document, as markup.
	 *
	 * The body is in `content:encoded`, `content`, or `description`, in a CDATA section or written
	 * out with entities. A document with none of them is read whole.
	 *
	 * @param string $document The channel's document for one article.
	 * @return string
	 */
	public function body_of( string $document ): string {
		foreach ( array( 'content:encoded', 'content', 'description' ) as $tag ) {
			if ( 1 !== preg_match( '#<' . preg_quote( $tag, '#' ) . '\b[^>]*>(.*?)</' . preg_quote( $tag, '#' ) . '>#s', $document, $match ) ) {
				continue;
			}

			$inner = str_replace( ']]]]><![CDATA[>', ']]>', $match[1] );

			if ( false !== strpos( $inner, '<![CDATA[' ) ) {
				$inner = (string) preg_replace( '/<!\[CDATA\[(.*?)\]\]>/s', '$1', $inner );
			} else {
				$inner = html_entity_decode( $inner, ENT_QUOTES | ENT_XML1, 'UTF-8' );
			}

			return trim( $inner );
		}

		return trim( html_entity_decode( (string) preg_replace( '/<!\[CDATA\[(.*?)\]\]>/s', '$1', $document ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
	}

	/**
	 * Splits markup into lines at the tag boundaries, one block to a line.
	 *
	 * @param string $html Markup.
	 * @return string[]
	 */
	public function lines( string $html ): array {
		$flat  = (string) preg_replace( '/<!--.*?-->/s', '', $html );
		$flat  = (string) preg_replace( '#(</(?:p|li|ul|ol|div|figure|h[1-6]|blockquote|section|table)>|<(?:div|section|ul|ol|figure)\b[^>]*>(?!\s*</))\s*#i', "$1\n", $flat );
		$lines = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $flat ) as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Three lines around the one that holds the finding.
	 *
	 * @param string[] $lines   The lines of the body.
	 * @param string   $sample  Text to find in a line.
	 * @param string   $channel The channel the body is from, or empty.
	 * @return array{channel: string, lines: string[], marked: int}
	 */
	private function context( array $lines, string $sample, string $channel ): array {
		$at = 0;

		foreach ( $lines as $index => $line ) {
			if ( '' !== $sample && false !== strpos( $line, $sample ) ) {
				$at = $index;
				break;
			}
		}

		$from   = max( 0, $at - 1 );
		$slice  = array_slice( $lines, $from, 3 );
		$marked = '' === $sample ? 0 : $at - $from + 1;

		foreach ( $slice as $index => $line ) {
			$slice[ $index ] = $this->short( (string) $line, 120 );
		}

		return array(
			'channel' => $channel,
			'lines'   => array_values( $slice ),
			'marked'  => $marked,
		);
	}

	/**
	 * Shortcodes that are still text in the body.
	 *
	 * Reads the text outside code, script and style. A bracketed word counts when it is a shortcode
	 * this site knows, when it carries attributes, or when it has a closing partner. A note in
	 * brackets such as [sic] or [1] does not.
	 *
	 * @param string $html Markup.
	 * @return array{found: string, count: int, sample: string}
	 */
	public function shortcodes( string $html ): array {
		$clean = (string) preg_replace( '#<(pre|code|script|style)\b.*?</\1>#is', '', $html );
		$clean = (string) preg_replace( '/<!--.*?-->/s', '', $clean );

		if ( ! preg_match_all( '/\[(\/)?([A-Za-z][A-Za-z0-9_-]{1,40})((?:\s[^\[\]<>]*)?)\]/', $clean, $matches, PREG_SET_ORDER ) ) {
			return array(
				'found'  => '',
				'count'  => 0,
				'sample' => '',
			);
		}

		$closing = array();

		foreach ( $matches as $match ) {
			if ( '/' === $match[1] ) {
				$closing[ strtolower( $match[2] ) ] = true;
			}
		}

		$hits = array();

		foreach ( $matches as $match ) {
			$name = strtolower( $match[2] );

			if ( '/' === $match[1] ) {
				continue;
			}

			if ( shortcode_exists( $name ) || false !== strpos( $match[3], '=' ) || isset( $closing[ $name ] ) ) {
				$hits[] = $match[0];
			}
		}

		if ( array() === $hits ) {
			return array(
				'found'  => '',
				'count'  => 0,
				'sample' => '',
			);
		}

		return array(
			'found'  => $this->plain_quotes( html_entity_decode( $hits[0], ENT_QUOTES, 'UTF-8' ) ),
			'count'  => count( $hits ),
			'sample' => $hits[0],
		);
	}

	/**
	 * Tags whose class belongs to a page builder.
	 *
	 * @param string $html Markup.
	 * @return array{found: string, count: int, sample: string}
	 */
	public function builder( string $html ): array {
		$hits      = array();
		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			$class = $processor->get_attribute( 'class' );

			if ( ! is_string( $class ) || '' === trim( $class ) ) {
				continue;
			}

			foreach ( (array) preg_split( '/\s+/', trim( $class ) ) as $token ) {
				if ( $this->is_builder_class( (string) $token ) ) {
					$hits[] = '<' . strtolower( (string) $processor->get_tag() ) . ' class="' . $class . '">';
					break;
				}
			}
		}

		return array(
			'found'  => array() === $hits ? '' : $this->short( $hits[0], 90 ),
			'count'  => count( $hits ),
			'sample' => array() === $hits ? '' : 'class="' . substr( $hits[0], strpos( $hits[0], 'class="' ) + 7, 40 ),
		);
	}

	/**
	 * Whether one class name belongs to a page builder.
	 *
	 * @param string $token A class name.
	 * @return bool
	 */
	private function is_builder_class( string $token ): bool {
		$token = strtolower( $token );

		foreach ( self::BUILDER_PREFIXES as $prefix ) {
			if ( 0 === strpos( $token, $prefix ) ) {
				return true;
			}
		}

		// "pb-section" is a builder wrapper; "pb-3" is a spacing class that themes use and is left alone.
		return 1 === preg_match( '/^pb-[a-z]/', $token );
	}

	/**
	 * Empty paragraphs, lists and blocks.
	 *
	 * @param string $html Markup.
	 * @return array{found: string, count: int, sample: string}
	 */
	public function empties( string $html ): array {
		$clean = (string) preg_replace( '/<!--.*?-->/s', '', $html );

		if ( ! preg_match_all( '#<(p|ul|ol|li|div|figure|blockquote|h[1-6]|section)\b[^>]*>(?:\s|&nbsp;|&\#160;|\x{00A0}|<br\s*/?>)*</\1>#iu', $clean, $matches, PREG_SET_ORDER ) ) {
			return array(
				'found'  => '',
				'count'  => 0,
				'sample' => '',
			);
		}

		$types = array();

		foreach ( $matches as $match ) {
			$tag = strtolower( $match[1] );

			if ( 'p' === $tag ) {
				$types['p'] = true;
			} elseif ( in_array( $tag, array( 'ul', 'ol', 'li' ), true ) ) {
				$types['list'] = true;
			} else {
				$types['block'] = true;
			}
		}

		return array(
			'found'  => implode( ',', array_keys( $types ) ),
			'count'  => count( $matches ),
			'sample' => $matches[0][0],
		);
	}

	/**
	 * The first iframe, script, embed or object in the saved text.
	 *
	 * @param string $source The saved article text.
	 * @return array{found: string, count: int, sample: string}
	 */
	public function embed( string $source ): array {
		preg_match_all( '/<(iframe|script|embed|object)\b[^>]*>/i', $source, $matches );

		$first = isset( $matches[0][0] ) ? (string) $matches[0][0] : '';

		return array(
			'found'  => $this->short( $first, 70 ),
			'count'  => count( $matches[0] ),
			'sample' => $first,
		);
	}

	/**
	 * A text cut to a length, with an ellipsis when it was longer.
	 *
	 * @param string $text   Text.
	 * @param int    $length The most characters kept.
	 * @return string
	 */
	private function short( string $text, int $length ): string {
		return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length - 1 ) . '…' : $text;
	}

	/**
	 * Curly quotes turned back to plain ones, so a shortcode reads as it was typed.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function plain_quotes( string $text ): string {
		return strtr(
			$text,
			array(
				"\u{201C}" => '"',
				"\u{201D}" => '"',
				"\u{2033}" => '"',
				"\u{201E}" => '"',
				"\u{2018}" => "'",
				"\u{2019}" => "'",
				"\u{2032}" => "'",
			)
		);
	}
}
