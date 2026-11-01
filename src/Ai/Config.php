<?php
/**
 * The owner's AI choices, read from Settings, AI assistant.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads `estasoft_nd_settings.ai` and holds it to what the network allows. Nothing here
 * decides whether the assistant may run; that is `Availability`.
 */
final class Config {

	/**
	 * The feature switches on the settings screen.
	 */
	public const TOGGLES = array( 'fix', 'ask', 'translate', 'local', 'image', 'digest', 'summary', 'caption' );

	/**
	 * The REST feature name of each suggestion and the switch that controls it.
	 */
	public const FEATURE_TOGGLE = array(
		'fix'           => 'fix',
		'ask'           => 'ask',
		'platform-help' => 'ask',
		'translate'     => 'translate',
		'local-news'    => 'local',
		'image'         => 'image',
		'digest'        => 'digest',
		'summary'       => 'summary',
		'description'   => 'summary',
		'caption'       => 'caption',
	);

	/**
	 * The feature that decides a log row, for the names a row can carry that are not a feature of their own.
	 *
	 * A search description is made and decided by the summary feature, and a caption by the image helper's code,
	 * but each has its own name in the AI log so the report can filter on it.
	 */
	public const LOG_FEATURE = array(
		'description' => 'summary',
		'caption'     => 'image',
	);

	private Plugin $plugin;

	private Policy $policy;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->policy = $plugin->make( Policy::class );
	}

	/**
	 * Every value of the section, with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function values(): array {
		return $this->plugin->settings()->section( 'ai' );
	}

	/**
	 * The owner's switch. It does not mean the assistant may run; consent must be recorded too.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return (bool) ( $this->values()['enabled'] ?? false );
	}

	/**
	 * Whether one feature switch is on.
	 *
	 * @param string $toggle One of TOGGLES.
	 * @return bool
	 */
	public function toggle_on( string $toggle ): bool {
		$features = $this->values()['features'] ?? array();

		return is_array( $features ) && ! empty( $features[ $toggle ] );
	}

	/**
	 * Whether the switch that controls a REST feature is on.
	 *
	 * @param string $feature A key of FEATURE_TOGGLE.
	 * @return bool
	 */
	public function feature_on( string $feature ): bool {
		return isset( self::FEATURE_TOGGLE[ $feature ] ) && $this->toggle_on( self::FEATURE_TOGGLE[ $feature ] );
	}

	/**
	 * Whether any feature switch is on.
	 *
	 * @return bool
	 */
	public function any_on(): bool {
		foreach ( self::TOGGLES as $toggle ) {
			if ( $this->toggle_on( $toggle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Preferred model names, best first.
	 *
	 * @return string[]
	 */
	public function models(): array {
		$lines  = preg_split( '/[\r\n,]+/', (string) ( $this->values()['models'] ?? '' ) );
		$models = array();

		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );

			if ( '' !== $line && 1 === preg_match( '/^[A-Za-z0-9._:\/-]{1,100}$/', $line ) ) {
				$models[] = $line;
			}
		}

		return array_values( array_unique( $models ) );
	}

	/**
	 * Requests a day.
	 *
	 * @return int
	 */
	public function daily_limit(): int {
		return max( 1, (int) ( $this->values()['daily_limit'] ?? 200 ) );
	}

	/**
	 * Tokens a month, held to the network's cap.
	 *
	 * @return int
	 */
	public function monthly_limit(): int {
		$limit = max( 1, (int) ( $this->values()['monthly_tokens'] ?? 2000000 ) );
		$cap   = $this->policy->token_cap();

		return $cap > 0 ? min( $limit, $cap ) : $limit;
	}

	/**
	 * Whether drafts and scheduled posts may be sent: the site's choice, when the network allows it.
	 *
	 * @return bool
	 */
	public function drafts(): bool {
		return ! empty( $this->values()['drafts'] ) && $this->policy->allows_drafts();
	}

	/**
	 * Whether author names may be sent: the site's choice, when the network allows it.
	 *
	 * @return bool
	 */
	public function authors(): bool {
		return ! empty( $this->values()['authors'] ) && $this->policy->allows_authors();
	}

	/**
	 * What happens at a limit: `pause_tell` or `pause_quiet`.
	 *
	 * @return string
	 */
	public function on_limit(): string {
		return 'pause_quiet' === ( $this->values()['on_limit'] ?? '' ) ? 'pause_quiet' : 'pause_tell';
	}

	/**
	 * Signed-off reviewers by language.
	 *
	 * @return array<string, int[]>
	 */
	public function reviewers(): array {
		$map = $this->values()['reviewers'] ?? array();
		$out = array();

		foreach ( is_array( $map ) ? $map : array() as $language => $ids ) {
			$out[ (string) $language ] = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		}

		return $out;
	}
}
