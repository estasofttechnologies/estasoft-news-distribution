<?php
/**
 * Reads a robots.txt and says what one crawler may fetch.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

defined( 'ABSPATH' ) || exit;

/**
 * The rules of the Robots Exclusion Protocol (RFC 9309), and nothing a platform adds to them:
 *
 * - Lines are `field: value`; a `#` starts a comment.
 * - Consecutive `User-agent` lines start a group, and the rules after them belong to it.
 * - A crawler uses the group of the most specific name that its own name starts with
 *   (`Googlebot` for `Googlebot-News`), or the `*` group when none matches.
 * - `*` in a path matches any characters and `$` at the end matches the end of the address.
 * - The longest matching rule wins; when an allow and a disallow are equally long, allow wins.
 * - An empty `Disallow:` allows everything.
 *
 * A crawler may read these rules slightly differently, so the screen says what the standard decides.
 */
final class Robots {

	/**
	 * Groups: each with its agents, the line number of its first `User-agent`, and its rules.
	 *
	 * @var array<int, array{agents: string[], line: int, rules: array<int, array{type: string, path: string, line: int}>}>
	 */
	private array $groups = array();

	/**
	 * The lines of the file, from line 1.
	 *
	 * @var string[]
	 */
	private array $lines = array();

	/**
	 * Reads a robots.txt.
	 *
	 * @param string $body The file.
	 */
	public function __construct( string $body ) {
		$this->lines = array_map( 'rtrim', (array) preg_split( '/\r\n|\r|\n/', $body ) );
		$current     = null;
		$collecting  = false;

		foreach ( $this->lines as $index => $raw ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', (string) $raw ) );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );
			$number                = $index + 1;

			if ( 'user-agent' === $field ) {
				if ( ! $collecting ) {
					$this->groups[] = array(
						'agents' => array(),
						'line'   => $number,
						'rules'  => array(),
					);
					$current        = count( $this->groups ) - 1;
					$collecting     = true;
				}

				$this->groups[ (int) $current ]['agents'][] = strtolower( $value );
				continue;
			}

			$collecting = false;

			if ( null === $current || ! in_array( $field, array( 'allow', 'disallow' ), true ) ) {
				continue;
			}

			$this->groups[ $current ]['rules'][] = array(
				'type' => $field,
				'path' => $value,
				'line' => $number,
			);
		}
	}

	/**
	 * The text of one line of the file.
	 *
	 * @param int $number Line number, from 1.
	 * @return string
	 */
	public function line( int $number ): string {
		return isset( $this->lines[ $number - 1 ] ) ? trim( (string) $this->lines[ $number - 1 ] ) : '';
	}

	/**
	 * Whether a crawler may fetch a path.
	 *
	 * @param string $crawler The crawler's name, such as "Googlebot".
	 * @param string $path    Path with the query, such as "/feed/search-sitemap/?lang=en".
	 * @return array{allowed: bool, line: int, agent_line: int}
	 *         `line` is the line of the rule that decided, or 0 when no rule matched. `agent_line` is the `User-agent` line of the group used, or 0 for none.
	 */
	public function check( string $crawler, string $path ): array {
		$group = $this->group_for( $crawler );

		if ( null === $group ) {
			return array(
				'allowed'    => true,
				'line'       => 0,
				'agent_line' => 0,
			);
		}

		$best   = null;
		$length = -1;

		foreach ( $group['rules'] as $rule ) {
			if ( '' === $rule['path'] || ! $this->matches( $rule['path'], $path ) ) {
				continue;
			}

			$size = strlen( $rule['path'] );

			if ( $size > $length || ( $size === $length && 'allow' === $rule['type'] ) ) {
				$best   = $rule;
				$length = $size;
			}
		}

		return array(
			'allowed'    => null === $best || 'allow' === $best['type'],
			'line'       => null === $best ? 0 : $best['line'],
			'agent_line' => $group['line'],
		);
	}

	/**
	 * The group a crawler follows: the most specific name it starts with, else `*`.
	 * Groups that carry the same name are read as one.
	 *
	 * @param string $crawler The crawler's name.
	 * @return array{agents: string[], line: int, rules: array<int, array{type: string, path: string, line: int}>}|null
	 */
	private function group_for( string $crawler ): ?array {
		$crawler = strtolower( $crawler );
		$name    = '';

		foreach ( $this->groups as $group ) {
			foreach ( $group['agents'] as $agent ) {
				if ( '' !== $agent && '*' !== $agent && 0 === strpos( $crawler, $agent ) && strlen( $agent ) > strlen( $name ) ) {
					$name = $agent;
				}
			}
		}

		$name   = '' === $name ? '*' : $name;
		$merged = null;

		foreach ( $this->groups as $group ) {
			if ( ! in_array( $name, $group['agents'], true ) ) {
				continue;
			}

			if ( null === $merged ) {
				$merged = $group;
				continue;
			}

			$merged['rules'] = array_merge( $merged['rules'], $group['rules'] );
		}

		return $merged;
	}

	/**
	 * Whether a rule's path matches the start of an address path.
	 *
	 * @param string $pattern The rule's path, which may hold `*` and a closing `$`.
	 * @param string $path    The address path.
	 * @return bool
	 */
	private function matches( string $pattern, string $path ): bool {
		$anchored = '$' === substr( $pattern, -1 );
		$pattern  = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$regex    = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . ( $anchored ? '$' : '' ) . '#';

		return 1 === preg_match( $regex, $path );
	}
}
