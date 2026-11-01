<?php
/**
 * The opening paragraph of the weekly summary email.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Ai\Reviewers;
use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Digest as WeeklyDigest;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Words the opening of the Monday email: "In plain words", two sentences about the week.
 *
 * What is sent to the provider is the totals of the email and the names of the channels, and nothing else: no
 * article title, no text and no name of a person. The paragraph is asked for only when the queue runs the Monday
 * job (or when an administrator presses the preview button), never when an article is published or a page is opened.
 *
 * The paragraph is held back, and the email goes out with the usual table alone, unless all of these hold:
 *
 * - every number in it is a number in the figures it was given;
 * - it makes no claim about readers, traffic or awards, and holds no web address;
 * - it is short and written in the script of the person's language.
 *
 * Each person gets it in their own WordPress language. A language other than English is worded by the AI only when a
 * reviewer is signed off for it; otherwise that person gets the fixed template, without AI.
 */
final class Digest extends AbstractFeature {

	/**
	 * Option that keeps the last paragraph of each language, for the preview page.
	 */
	public const LAST = 'estasoft_nd_ai_digest_last';

	/**
	 * The longest paragraph, in characters.
	 */
	public const MAX_CHARS = 500;

	/**
	 * Set by tests that send the email without the queue.
	 *
	 * @var bool
	 */
	private static bool $forced = false;

	public function name(): string {
		return 'digest';
	}

	public function label(): string {
		return __( 'Weekly summary wording', 'estasoft-news-distribution' );
	}

	public function needs_article(): bool {
		return false;
	}

	/**
	 * Whether the Monday email is being sent now: the queue is running its job.
	 *
	 * @return bool
	 */
	public static function sending(): bool {
		return self::$forced || doing_action( WeeklyDigest::HOOK );
	}

	/**
	 * Lets a test send the email without the queue.
	 *
	 * @param bool $on Whether the email counts as being sent by the queue.
	 * @return void
	 */
	public static function force_sending( bool $on ): void {
		self::$forced = $on;
	}

	/**
	 * Hooked to `estasoft_nd_internal_digest_model`: puts the paragraph at the top of the email while it is sent.
	 *
	 * A preview of the email never asks the provider, and never shows a paragraph, so opening a page costs nothing.
	 *
	 * @param mixed $model  What the email prints.
	 * @param mixed $digest The figures behind it.
	 * @return mixed
	 */
	public function filter( $model, $digest = array() ) {
		if ( ! is_array( $model ) || ! is_array( $digest ) || ! self::sending() ) {
			return $model;
		}

		if ( ! $this->plugin->make( Config::class )->feature_on( $this->name() ) ) {
			return $model;
		}

		$language = Catalog::primary( determine_locale() );
		$figures  = $this->figures( $digest );
		$found    = $this->for_send( $figures, $language );

		if ( null === $found ) {
			return $model;
		}

		$caption = __( 'Worded by AI from the figures below. The figures come from your logs.', 'estasoft-news-distribution' );

		array_unshift(
			$model['sections'],
			array(
				'key'   => 'ai',
				'title' => __( 'In plain words', 'estasoft-news-distribution' ),
				'lines' => array(
					array(
						'status' => Status::PROCESSING,
						'text'   => $found['text'],
					),
					array(
						'status' => Status::PROCESSING,
						'text'   => $caption,
					),
				),
				'ai'    => array(
					'text'    => $found['text'],
					'numbers' => $found['numbers'],
					'caption' => $caption,
				),
			)
		);

		return $model;
	}

	/**
	 * `POST /ai/suggest/digest`: a paragraph for the week so far, so an administrator can see the wording before Monday.
	 *
	 * It costs one request and is logged. Nothing is sent to anyone.
	 *
	 * @param int                  $post_id Always 0.
	 * @param array<string, mixed> $args    `language`: the language to word it in; the person's own when empty.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function suggest( int $post_id, array $args ) {
		unset( $post_id );

		$language = Catalog::primary( '' !== (string) ( $args['language'] ?? '' ) ? (string) $args['language'] : get_user_locale() );
		$digest   = $this->plugin->make( WeeklyDigest::class )->build( null, 'so_far' );
		$figures  = $this->figures( $digest );
		$result   = $this->write( $figures, $language, 'preview' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array_merge(
			array(
				'feature'  => $this->name(),
				'language' => $language,
				'figures'  => $figures['lines'],
				'message'  => '',
			),
			$result
		);
	}

	/**
	 * How a person's email is worded.
	 *
	 * @param string $language Language code of the person.
	 * @return array{state: string, text: string} `state` is `ai`, `reviewed` or `fixed`.
	 */
	public function wording( string $language ): array {
		$language = Catalog::primary( $language );

		if ( ! $this->plugin->make( Config::class )->feature_on( $this->name() ) ) {
			return array(
				'state' => 'fixed',
				'text'  => __( 'Fixed template, no AI: this wording is off', 'estasoft-news-distribution' ),
			);
		}

		if ( 'en' === $language ) {
			return array(
				'state' => 'ai',
				'text'  => __( 'Worded by AI', 'estasoft-news-distribution' ),
			);
		}

		if ( $this->reviewed( $language ) ) {
			return array(
				'state' => 'reviewed',
				'text'  => __( 'Worded by AI, reviewer signed off', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'state' => 'fixed',
			'text'  => __( 'Fixed template, no AI: no signed-off reviewer', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The people the Monday email goes to, with the language each gets.
	 *
	 * @return array<int, array{name: string, language: string, tag: string, state: string, text: string}>
	 */
	public function people(): array {
		$alerts = $this->plugin->make( Alerts::class );
		$out    = array();

		foreach ( $alerts->addresses() as $address ) {
			$who      = $alerts->recipient( (string) $address );
			$user     = $who['user_id'] > 0 ? get_userdata( $who['user_id'] ) : false;
			$locale   = $who['user_id'] > 0 ? get_user_locale( $who['user_id'] ) : get_locale();
			$language = Catalog::primary( $locale );
			$wording  = $this->wording( $language );

			$out[] = array(
				'name'     => $user instanceof \WP_User ? (string) $user->display_name : (string) $address,
				'language' => $language,
				'tag'      => Catalog::tag( $language ),
				'state'    => $wording['state'],
				'text'     => $wording['text'],
			);
		}

		return $out;
	}

	/**
	 * The last paragraph made in a language, for the preview page.
	 *
	 * @param string $language Language code.
	 * @return array{text: string, numbers: string[], at: int, kind: string, log: int}|null
	 */
	public function last( string $language ): ?array {
		$all   = get_option( self::LAST, array() );
		$found = is_array( $all ) ? ( $all[ Catalog::primary( $language ) ] ?? null ) : null;

		return is_array( $found ) && isset( $found['text'] ) ? array(
			'text'    => (string) $found['text'],
			'numbers' => array_map( 'strval', (array) ( $found['numbers'] ?? array() ) ),
			'at'      => (int) ( $found['at'] ?? 0 ),
			'kind'    => (string) ( $found['kind'] ?? 'preview' ),
			'log'     => (int) ( $found['log'] ?? 0 ),
		) : null;
	}

	/**
	 * The figures the provider is given and the numbers in them.
	 *
	 * Only totals and channel names. The reasons are the plugin's own fixed wording, never an article title.
	 *
	 * @param array<string, mixed> $digest What `Core\Digest::build()` returned.
	 * @return array{text: string, lines: string[], numbers: string[]}
	 */
	public function figures( array $digest ): array {
		$counts = (array) ( $digest['counts'] ?? array() );
		$lines  = array(
			'Articles published: ' . (int) ( $counts['published'] ?? 0 ),
			'No problems found: ' . (int) ( $counts['clean'] ?? 0 ),
			'Skipped by at least one channel: ' . (int) ( $counts['skipped'] ?? 0 ),
		);

		if ( (int) ( $counts['unchecked'] ?? 0 ) > 0 ) {
			$lines[] = 'Not checked yet: ' . (int) $counts['unchecked'];
		}

		foreach ( (array) ( $digest['skipped'] ?? array() ) as $reason ) {
			if ( ! is_array( $reason ) ) {
				continue;
			}

			$channels = implode( ', ', array_map( 'strval', array_values( (array) ( $reason['channels'] ?? array() ) ) ) );
			$lines[]  = sprintf(
				'%1$s: %2$d (skipped by %3$s)',
				wp_strip_all_tags( (string) ( $reason['reason'] ?? '' ) ),
				(int) ( $reason['count'] ?? 0 ),
				$channels
			);
		}

		$text = implode( "\n", $lines );

		return array(
			'text'    => $text,
			'lines'   => $lines,
			'numbers' => Grounding::numbers( $text ),
		);
	}

	/**
	 * The paragraph for an email being sent, from the cache when another person in the same language already got one.
	 *
	 * @param array{text: string, lines: string[], numbers: string[]} $figures  The figures.
	 * @param string                                                  $language Language code.
	 * @return array{text: string, numbers: string[]}|null Null when the email goes without a paragraph.
	 */
	private function for_send( array $figures, string $language ): ?array {
		$key    = 'estasoft_nd_ai_digest_' . md5( $language . "\n" . $figures['text'] . "\n" . gmdate( 'o-W', Clock::now() ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['text'] ) ) {
			return array(
				'text'    => (string) $cached['text'],
				'numbers' => array_map( 'strval', (array) ( $cached['numbers'] ?? array() ) ),
			);
		}

		$result = $this->write( $figures, $language, 'sent' );

		if ( is_wp_error( $result ) || '' === (string) ( $result['paragraph'] ?? '' ) ) {
			return null;
		}

		$found = array(
			'text'    => (string) $result['paragraph'],
			'numbers' => $result['numbers'],
		);

		set_transient( $key, $found, 3 * DAY_IN_SECONDS );

		return $found;
	}

	/**
	 * Asks for a paragraph, checks it and writes the log row.
	 *
	 * @param array{text: string, lines: string[], numbers: string[]} $figures  The figures.
	 * @param string                                                  $language Language code.
	 * @param string                                                  $kind     `sent` (the Monday job) or `preview` (a button).
	 * @return array<string, mixed>|\WP_Error `paragraph` is empty when the check failed.
	 */
	private function write( array $figures, string $language, string $kind ) {
		$wording = $this->wording( $language );

		if ( 'fixed' === $wording['state'] ) {
			return new \WP_Error(
				'estasoft_nd_ai_fixed_template',
				'en' === $language || $this->reviewed( $language )
					? __( 'The weekly summary wording is off in Settings, AI assistant.', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: a language name such as Arabic. */
						__( 'No reviewer is signed off for %s, so this language gets the fixed template without AI.', 'estasoft-news-distribution' ),
						Catalog::name( $language )
					),
				array( 'status' => 409 )
			);
		}

		$answer = $this->ask(
			array(
				'system'     => $this->system( $language ),
				'prompt'     => "Figures for the week:\n" . $figures['text'],
				'max_tokens' => 300,
			)
		);

		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$text  = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $answer['text'] ) ) );
		$text  = (string) preg_replace( '/^[\s"\'“”‘’]+|[\s"\'“”‘’]+$/u', '', $text );
		$check = $this->check( $text, $figures, $language );

		$log = $this->record(
			0,
			$answer,
			array(
				'finding'   => 'digest',
				'language'  => $language,
				'kind'      => $kind,
				'figures'   => $figures['numbers'],
				'checks'    => $check['lines'],
				'pass'      => $check['pass'],
				'paragraph' => $check['pass'] ? $text : '',
			),
			$check['pass'] && 'sent' === $kind ? Log::AUTO : Log::NONE
		);

		$result = array_merge(
			array(
				'log'       => $log,
				'kind'      => $kind,
				'paragraph' => $check['pass'] ? $text : '',
				'numbers'   => $check['numbers'],
				'checks'    => $check['lines'],
				'pass'      => $check['pass'],
				'wording'   => $wording,
			),
			$this->meta( $answer )
		);

		if ( $check['pass'] ) {
			$this->remember( $language, $text, $check['numbers'], $kind, $log );
		}

		return $result;
	}

	/**
	 * What the provider is told it is.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	private function system( string $language ): string {
		$name = 'ku' === $language ? 'Kurdish (Kurmanji) written in Latin script' : Catalog::name( $language );

		return 'You write the opening paragraph of a weekly summary email for the staff of a news website. '
			. 'Write in ' . $name . ', in plain words, in one or two short sentences. '
			. 'Use only the figures you are given. Do not write any number that is not in the figures. '
			. 'Do not say anything about readers, traffic, audience or awards, and do not give advice beyond naming the main thing to fix. '
			. 'Reply with the paragraph only.';
	}

	/**
	 * Checks a paragraph against the figures it was made from.
	 *
	 * @param string                                                  $text     The paragraph.
	 * @param array{text: string, lines: string[], numbers: string[]} $figures  The figures.
	 * @param string                                                  $language Language code.
	 * @return array{pass: bool, numbers: string[], lines: array<int, array{ok: bool, label: string, text: string}>}
	 */
	private function check( string $text, array $figures, string $language ): array {
		$lines   = array();
		$pass    = true;
		$numbers = Grounding::numbers( $text );
		$extra   = array_values( array_diff( $numbers, $figures['numbers'] ) );

		if ( '' === $text || mb_strlen( $text ) > self::MAX_CHARS ) {
			$pass    = false;
			$lines[] = array(
				'ok'    => false,
				'label' => '',
				'text'  => '' === $text
					? __( 'The answer was empty', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %d: the most characters. */
						__( 'The paragraph is longer than %d characters', 'estasoft-news-distribution' ),
						self::MAX_CHARS
					),
			);
		}

		if ( array() !== $extra ) {
			$pass    = false;
			$lines[] = array(
				'ok'    => false,
				'label' => '',
				'text'  => sprintf(
					/* translators: %s: numbers such as 24, 21. */
					__( 'These numbers are not in the figures: %s', 'estasoft-news-distribution' ),
					implode( ', ', $extra )
				),
			);
		} elseif ( array() !== $numbers ) {
			$lines[] = array(
				'ok'    => true,
				'label' => '',
				'text'  => sprintf(
					/* translators: %s: numbers such as 24, 21, 3. */
					__( 'Every number in this paragraph matches the table below: %s', 'estasoft-news-distribution' ),
					implode( ', ', $numbers )
				),
			);
		} else {
			$lines[] = array(
				'ok'    => true,
				'label' => '',
				'text'  => __( 'The paragraph has no numbers, so there is nothing to match', 'estasoft-news-distribution' ),
			);
		}

		if ( Grounding::claims( $text ) || array() !== Grounding::addresses( $text ) || false !== strpos( $text, '@' ) ) {
			$pass    = false;
			$lines[] = array(
				'ok'    => false,
				'label' => '',
				'text'  => __( 'The paragraph claims something about readers, traffic or awards, or holds an address', 'estasoft-news-distribution' ),
			);
		}

		$pattern = $this->script( $language );

		if ( null !== $pattern && '' !== $text ) {
			$letters = (int) preg_match_all( '/\p{L}/u', $text );
			$right   = (int) preg_match_all( $pattern['pattern'], $text );

			if ( $letters > 0 && $right / $letters < $pattern['min'] ) {
				$pass    = false;
				$lines[] = array(
					'ok'    => false,
					'label' => '',
					'text'  => __( 'The paragraph is not in the language it was asked for', 'estasoft-news-distribution' ),
				);
			}
		}

		return array(
			'pass'    => $pass,
			'numbers' => $numbers,
			'lines'   => $lines,
		);
	}

	/**
	 * The script a language is written in.
	 *
	 * @param string $language Language code.
	 * @return array{pattern: string, min: float}|null
	 */
	private function script( string $language ): ?array {
		switch ( $language ) {
			case 'ar':
				return array(
					'pattern' => '/\p{Arabic}/u',
					'min'     => 0.6,
				);
			case 'he':
				return array(
					'pattern' => '/\p{Hebrew}/u',
					'min'     => 0.6,
				);
			case 'en':
			case 'ku':
			case 'es':
			case 'fr':
			case 'nl':
				return array(
					'pattern' => '/\p{Latin}/u',
					'min'     => 0.9,
				);
		}

		return null;
	}

	/**
	 * Whether a reviewer is signed off for a language.
	 *
	 * @param string $language Primary language code.
	 * @return bool
	 */
	private function reviewed( string $language ): bool {
		$reviewers = $this->plugin->make( Reviewers::class );

		foreach ( array_keys( $this->plugin->make( Config::class )->reviewers() ) as $code ) {
			if ( Catalog::primary( (string) $code ) === $language && array() !== $reviewers->signed_off( (string) $code ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keeps the last paragraph of a language, for the preview page.
	 *
	 * @param string   $language Language code.
	 * @param string   $text     The paragraph.
	 * @param string[] $numbers  The numbers in it.
	 * @param string   $kind     `sent` or `preview`.
	 * @param int      $log      The AI log row.
	 * @return void
	 */
	private function remember( string $language, string $text, array $numbers, string $kind, int $log ): void {
		$all = get_option( self::LAST, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ $language ] = array(
			'text'    => $text,
			'numbers' => $numbers,
			'at'      => Clock::now(),
			'kind'    => $kind,
			'log'     => $log,
		);

		update_option( self::LAST, array_slice( $all, -10, 10, true ), false );
	}
}
