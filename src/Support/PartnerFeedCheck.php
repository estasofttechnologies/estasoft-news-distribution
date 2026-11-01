<?php
/**
 * The plugin's own check of a partner feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Support;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a channel's feed for each language it carries, reads the XML back and
 * counts what is in it and what was left out. Nothing leaves the site: this is
 * News Distribution checking its own output, not a platform's answer.
 */
final class PartnerFeedCheck {

	/**
	 * How many skipped articles one check keeps for the screen.
	 */
	private const KEEP_SKIPPED = 20;

	/**
	 * Runs the check.
	 *
	 * @param AbstractChannel $channel The pulled channel to check.
	 * @param Plugin          $plugin  The running plugin.
	 * @return array{ok: bool, code: int, message: string, time: int, languages: array<string, array<string, mixed>>, skipped: array<int, array<string, mixed>>, items: int, skipped_total: int}
	 */
	public static function run( AbstractChannel $channel, Plugin $plugin ): array {
		$window    = $channel->feed_window();
		$languages = array();
		$skipped   = array();
		$items     = 0;
		$left_out  = 0;
		$valid     = true;

		foreach ( $plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$articles = $plugin->reader()->recent( $language, (int) $window['max_age'], (int) $window['limit'] );
			$body     = $channel->build( $articles, $language );
			$checked  = $channel->check_output( $body, $language );
			$missing  = 0;

			foreach ( $articles as $article ) {
				$findings = $channel->validate( $article )->errors();

				if ( array() === $findings ) {
					continue;
				}

				++$missing;

				if ( count( $skipped ) < self::KEEP_SKIPPED ) {
					$skipped[] = array(
						'id'       => $article->id(),
						'language' => $language,
						'code'     => (string) $findings[0]['code'],
					);
				}
			}

			$languages[ $language ] = array(
				'valid'    => (bool) $checked['valid'],
				'items'    => (int) $checked['entries'],
				'skipped'  => $missing,
				'problems' => array_values( (array) $checked['problems'] ),
			);

			$items    += (int) $checked['entries'];
			$left_out += $missing;
			$valid     = $valid && (bool) $checked['valid'];
		}

		return array(
			'ok'            => $valid && array() !== $languages,
			'code'          => 0,
			'message'       => self::message( $items, $left_out, $languages ),
			'time'          => Clock::now(),
			'languages'     => $languages,
			'skipped'       => $skipped,
			'items'         => $items,
			'skipped_total' => $left_out,
		);
	}

	/**
	 * One sentence that says what the check found.
	 *
	 * @param int                                 $items     Items in the feeds.
	 * @param int                                 $left_out  Articles left out.
	 * @param array<string, array<string, mixed>> $languages Per-language results.
	 * @return string
	 */
	private static function message( int $items, int $left_out, array $languages ): string {
		if ( array() === $languages ) {
			return __( 'No language is turned on for this feed.', 'estasoft-news-distribution' );
		}

		foreach ( $languages as $language => $row ) {
			if ( ! $row['valid'] ) {
				return sprintf(
					/* translators: %s: a language code such as en. */
					__( 'The %s feed is not valid RSS. Open the details to see why.', 'estasoft-news-distribution' ),
					strtoupper( (string) $language )
				);
			}
		}

		$text = sprintf(
			/* translators: %s: number of items in the feeds. */
			_n( '%s item in the feeds.', '%s items in the feeds.', $items, 'estasoft-news-distribution' ),
			number_format_i18n( $items )
		);

		if ( $left_out > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of articles left out of the feeds. */
				_n( '%s article is left out.', '%s articles are left out.', $left_out, 'estasoft-news-distribution' ),
				number_format_i18n( $left_out )
			);
		}

		return $text;
	}
}
