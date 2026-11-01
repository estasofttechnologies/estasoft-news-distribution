<?php
/**
 * Checks that AI text only uses what it was given.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Features;

defined( 'ABSPATH' ) || exit;

/**
 * An AI answer that quotes a number nobody gave it is held back. This is a blunt check, on purpose: it cannot tell
 * whether a sentence is true, only that every figure in it is one of the figures in the facts. Where it cannot be
 * sure, the answer is refused. It does not make an answer true, and the screens say so.
 */
final class Grounding {

	/**
	 * Words that make a claim about an audience, traffic or an award. An email to a platform never makes one.
	 */
	private const CLAIMS = '/\b(?:audience|traffic|page ?views?|visitors?|unique users?|subscribers?|circulation|monthly readers|daily readers|award|awards|awarded|million|billion|thousands? of|largest|leading|number one|#1|best-?known)\b/iu';

	/**
	 * The figures in a text, as plain digits: "1,200" is "1200" and Arabic-Indic digits are made Western.
	 *
	 * @param string $text Any text.
	 * @return string[] Unique figures.
	 */
	public static function numbers( string $text ): array {
		$text = strtr(
			$text,
			array(
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
			)
		);

		preg_match_all( '/\d+(?:[,.\x{202F}\x{00A0}]\d+)*/u', $text, $found );

		$out = array();

		foreach ( $found[0] as $raw ) {
			$figure = (string) preg_replace( '/[,\x{202F}\x{00A0}]/u', '', $raw );
			$figure = rtrim( $figure, '.' );

			if ( '' !== $figure ) {
				$out[ $figure ] = true;
			}

			// A figure written "1.200" for 1,200 is read both ways.
			if ( 1 === preg_match( '/^\d{1,3}(?:\.\d{3})+$/', $figure ) ) {
				$out[ str_replace( '.', '', $figure ) ] = true;
			}
		}

		return array_map( 'strval', array_keys( $out ) );
	}

	/**
	 * Whether every figure in a text is a figure in the facts.
	 *
	 * @param string $text  The AI's text.
	 * @param string $facts What it was given.
	 * @return bool
	 */
	public static function covered( string $text, string $facts ): bool {
		return array() === array_diff( self::numbers( $text ), self::numbers( $facts ) );
	}

	/**
	 * Whether a text makes a claim about an audience, traffic, size or awards.
	 *
	 * @param string $text The AI's text.
	 * @return bool
	 */
	public static function claims( string $text ): bool {
		return 1 === preg_match( self::CLAIMS, $text );
	}

	/**
	 * The web addresses in a text.
	 *
	 * @param string $text The AI's text.
	 * @return string[] Without a final slash, punctuation or query separators at the end.
	 */
	public static function addresses( string $text ): array {
		preg_match_all( '#https?://[^\s<>"\')]+#iu', $text, $found );

		$out = array();

		foreach ( $found[0] as $address ) {
			$out[] = self::address( $address );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * An address in the form used to compare two.
	 *
	 * @param string $address A web address.
	 * @return string
	 */
	public static function address( string $address ): string {
		return strtolower( rtrim( html_entity_decode( $address, ENT_QUOTES, 'UTF-8' ), '.,;:!?/' ) );
	}
}
