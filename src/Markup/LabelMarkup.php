<?php
/**
 * The labels printed on an article page: updated, AI-assisted, correction and source.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Markup;

use Estasoft\NewsDistribution\Admin\Check\ArticleOptions;
use Estasoft\NewsDistribution\Content\Corrections;
use Estasoft\NewsDistribution\Content\Republished;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Small lines that tell readers what changed or where text came from. The same markup is
 * printed by the automatic placement (`the_content`), the block `estasoft-nd/labels` and the
 * shortcode `[estasoft_nd_labels]`, and the Page labels screen prints a sample of it.
 *
 * The classes are `estasoft-nd-*`, the colors are the variables `--estasoft-nd-*` set in
 * `assets/css/labels.css`, and no style is written into the page:
 *
 *     <div class="estasoft-nd-labels estasoft-nd-labels--top">
 *       <p class="estasoft-nd-label estasoft-nd-label--ai">AI-assisted</p>
 *       <p class="estasoft-nd-updated"><time datetime="2026-10-21T14:21:00+02:00">Updated 21 October 2026, 14:21</time></p>
 *     </div>
 *     <div class="estasoft-nd-labels estasoft-nd-labels--end">
 *       <aside class="estasoft-nd-correction"><strong>Correction, 21 October, 14:21.</strong> …</aside>
 *       <p class="estasoft-nd-source">Source: Example Wire Service. <a href="…">Read the original</a></p>
 *     </div>
 *
 * Every label is text, so a screen reader reads it; none is an image or a color alone.
 *
 * The parts of one article look like this (a part that does not apply is missing):
 *
 *     array(
 *       'updated'    => array( 'text' => 'Updated 21 October 2026, 14:21', 'iso' => '2026-10-21T14:21:00+02:00' ),
 *       'ai'         => array( 'text' => 'AI-assisted' ),
 *       'correction' => array( 'heading' => 'Correction, 21 October, 14:21.', 'note' => 'An earlier version …' ),
 *       'source'     => array( 'text' => 'Source: Example Wire Service.', 'url' => 'https://…', 'link' => 'Read the original' ),
 *     )
 */
final class LabelMarkup {

	/**
	 * The class every printed group of labels carries, so a second print can be told from a first.
	 */
	public const MARKER = 'estasoft-nd-labels';

	private Plugin $plugin;

	/**
	 * Creates the builder.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The labels that apply to an article, with the words of the current language.
	 *
	 * Each one needs its switch on in Settings, Page labels, and its own reason: the modified date
	 * moved, the content label is AI-assisted, a correction note is saved and shown, or the article
	 * is republished content with a source.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, array<string, string>>
	 */
	public function parts( int $post_id ): array {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return array();
		}

		$settings = $this->plugin->settings()->section( 'labels' );
		$options  = ArticleOptions::get( $post_id );
		$parts    = array();

		if ( ! empty( $settings['updated'] ) ) {
			$published = Clock::from_gmt( (string) $post->post_date_gmt );
			$modified  = Clock::from_gmt( (string) $post->post_modified_gmt );

			// Moved by at least a minute: a date the guard kept, or a fix seconds after publishing, shows nothing.
			if ( $published > 0 && $modified - $published >= MINUTE_IN_SECONDS && $modified <= Clock::now() + MINUTE_IN_SECONDS ) {
				$parts['updated'] = array(
					'text' => sprintf(
						/* translators: %s: a date and time, such as "21 October 2026, 14:21". */
						__( 'Updated %s', 'estasoft-news-distribution' ),
						Clock::format( $modified, (string) _x( 'j F Y, H:i', 'date and time of the Updated line', 'estasoft-news-distribution' ) )
					),
					'iso'  => Clock::format( $modified, 'c' ),
				);
			}
		}

		if ( ! empty( $settings['ai'] ) && 'ai_assisted' === ( $options['content_label'] ?? '' ) ) {
			$parts['ai'] = array( 'text' => __( 'AI-assisted', 'estasoft-news-distribution' ) );
		}

		if ( ! empty( $settings['correction'] ) ) {
			$note = $this->plugin->make( Corrections::class )->visible( $post_id );

			if ( null !== $note ) {
				$parts['correction'] = array(
					'heading' => sprintf(
						/* translators: %s: the date and time of a correction, such as "21 October, 14:21". */
						__( 'Correction, %s.', 'estasoft-news-distribution' ),
						Clock::format( $note['at'], (string) _x( 'j F, H:i', 'date and time of a correction note', 'estasoft-news-distribution' ) )
					),
					'note'    => $note['note'],
				);
			}
		}

		if ( ! empty( $settings['source'] ) ) {
			$source = $this->source( $post_id );

			if ( null !== $source ) {
				$parts['source'] = $source;
			}
		}

		return $parts;
	}

	/**
	 * The source line of an article: the attribution line and a link to the original.
	 *
	 * It shows for republished content (the content label Syndicated, or marked in the editor)
	 * with a source name filled in.
	 *
	 * @param int $post_id Article ID.
	 * @return array{text: string, url: string, link: string}|null
	 */
	private function source( int $post_id ): ?array {
		$content = $this->plugin->make( Republished::class );

		if ( ! $content->is_republished( $post_id ) ) {
			return null;
		}

		$name = $content->source_name( $post_id );

		if ( '' === trim( $name ) ) {
			return null;
		}

		$line = $content->attribution( $name, $this->plugin->languages()->language_of_post( $post_id ) );

		if ( 1 !== preg_match( '/[.!?…؟。]$/u', $line ) ) {
			$line .= '.';
		}

		$url = $content->details( $post_id )['url'];

		return array(
			'text' => $line,
			'url'  => $url,
			'link' => '' === $url ? '' : __( 'Read the original', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The labels at the start of the article: Updated, and the AI-assisted chip.
	 *
	 * @param array<string, array<string, string>> $parts   The parts.
	 * @param string                               $classes More classes for the group.
	 * @return string Escaped HTML, or an empty string when there is nothing to print.
	 */
	public static function top( array $parts, string $classes = '' ): string {
		$html = '';

		if ( isset( $parts['ai'] ) ) {
			$html .= '<p class="estasoft-nd-label estasoft-nd-label--ai">' . esc_html( $parts['ai']['text'] ) . '</p>';
		}

		if ( isset( $parts['updated'] ) ) {
			$html .= '<p class="estasoft-nd-updated"><time datetime="' . esc_attr( $parts['updated']['iso'] ) . '">' . esc_html( $parts['updated']['text'] ) . '</time></p>';
		}

		return self::wrap( $html, 'top', $classes );
	}

	/**
	 * The labels at the end of the article: the correction note and the source line.
	 *
	 * @param array<string, array<string, string>> $parts   The parts.
	 * @param string                               $classes More classes for the group.
	 * @return string Escaped HTML, or an empty string when there is nothing to print.
	 */
	public static function end( array $parts, string $classes = '' ): string {
		$html = '';

		if ( isset( $parts['correction'] ) ) {
			$note  = wp_kses( nl2br( esc_html( $parts['correction']['note'] ) ), array( 'br' => array() ) );
			$html .= '<aside class="estasoft-nd-correction"><strong>' . esc_html( $parts['correction']['heading'] ) . '</strong> ' . $note . '</aside>';
		}

		if ( isset( $parts['source'] ) ) {
			$html .= '<p class="estasoft-nd-source">' . esc_html( $parts['source']['text'] );

			if ( '' !== $parts['source']['url'] ) {
				$html .= ' <a href="' . esc_url( $parts['source']['url'] ) . '" rel="noopener">' . esc_html( $parts['source']['link'] ) . '</a>';
			}

			$html .= '</p>';
		}

		return self::wrap( $html, 'end', $classes );
	}

	/**
	 * Every label of an article in one group, as the block and the shortcode print it.
	 *
	 * @param array<string, array<string, string>> $parts The parts.
	 * @return string Escaped HTML.
	 */
	public static function all( array $parts ): string {
		return self::top( $parts ) . self::end( $parts );
	}

	/**
	 * Wraps a group of labels.
	 *
	 * @param string $html    The labels.
	 * @param string $where   "top" or "end".
	 * @param string $classes More classes.
	 * @return string
	 */
	private static function wrap( string $html, string $where, string $classes ): string {
		if ( '' === $html ) {
			return '';
		}

		$class = self::MARKER . ' ' . self::MARKER . '--' . $where . ( '' === $classes ? '' : ' ' . $classes );

		return '<div class="' . esc_attr( $class ) . '">' . $html . '</div>';
	}
}
