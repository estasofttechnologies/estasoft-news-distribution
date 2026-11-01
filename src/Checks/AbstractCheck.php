<?php
/**
 * Shared helpers for the article checks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Rules;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Where the severity and the limits of a finding come from.
 *
 * Severity: each platform's rule pack may carry a rule with the finding's code.
 * The pack of the platform's channel decides how bad the finding is for that
 * channel; the `article-checks` pack gives the default. One finding is
 * reported for each severity, listing the channels it applies to, so a title
 * with the publication name can be a risk for one platform and a tip for none.
 *
 * Limits (`title_max`, `image_min_width` and so on): the platform's pack first,
 * then the `article-checks` pack. No number is written in code.
 *
 * A check calls `emit()` with a code and a function that says, for one
 * platform, whether the article fails and with what sentence.
 */
abstract class AbstractCheck implements CheckInterface {

	/**
	 * The pack holding News Distribution's own defaults.
	 */
	public const PACK = 'article-checks';

	/**
	 * Names a platform pack may use for a rule that these checks name differently.
	 *
	 * @var array<string, string[]>
	 */
	public const PACK_ALIASES = array(
		'title.publication_name' => array( 'title.has_publication_name' ),
		'title.author'           => array( 'title.has_author' ),
		'title.date'             => array( 'title.has_date' ),
	);

	protected Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The rule packs.
	 *
	 * @return Rules
	 */
	protected function rules(): Rules {
		return $this->plugin->rules();
	}

	/**
	 * The severity the article-checks pack gives a code.
	 *
	 * @param string $code     Finding code.
	 * @param string $fallback Severity when the pack has none.
	 * @return string
	 */
	protected function default_severity( string $code, string $fallback = Status::WARN ): string {
		return $this->rules()->severity( self::PACK, $code, $fallback );
	}

	/**
	 * Whether a platform's pack has a rule for a code, under the code or an alias.
	 *
	 * @param string $platform Platform id.
	 * @param string $code     Finding code.
	 * @return bool
	 */
	protected function declares( string $platform, string $code ): bool {
		foreach ( $this->keys( $code ) as $key ) {
			if ( array() !== $this->rules()->rule( $platform, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The severity a platform gives a code, or the default when its pack has no rule.
	 *
	 * @param string $platform Platform id.
	 * @param string $code     Finding code.
	 * @param string $fallback  Severity when the pack has no rule.
	 * @return string
	 */
	protected function severity_for( string $platform, string $code, string $fallback ): string {
		foreach ( $this->keys( $code ) as $key ) {
			if ( array() !== $this->rules()->rule( $platform, $key ) ) {
				return $this->rules()->severity( $platform, $key, $fallback );
			}
		}

		return $fallback;
	}

	/**
	 * A numeric limit: the platform's own, else the article-checks pack's.
	 *
	 * @param string $platform Platform id, or an empty string for the article-checks pack alone.
	 * @param string $name     Limit name, such as "title_max".
	 * @param float  $fallback Value when neither pack has it.
	 * @return float
	 */
	protected function limit( string $platform, string $name, float $fallback = 0.0 ): float {
		$own = '' === $platform ? null : $this->rules()->limit( $platform, $name, null );

		if ( is_numeric( $own ) ) {
			return (float) $own;
		}

		return (float) $this->rules()->limit( self::PACK, $name, $fallback );
	}

	/**
	 * The channels that are on and take the article's language.
	 *
	 * @param Article $article The article.
	 * @return string[] Channel ids.
	 */
	protected function channel_ids( Article $article ): array {
		$ids = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			if ( $channel->accepts_language( $article->language() ) ) {
				$ids[] = $channel->id();
			}
		}

		return $ids;
	}

	/**
	 * One finding.
	 *
	 * @param string   $code     Stable code.
	 * @param string   $severity Status::BLOCK, WARN or INFO.
	 * @param string   $message  One plain sentence.
	 * @param string[] $channels Channel ids; empty for every channel that is on.
	 * @param string   $fix      Fix slug, or an empty string.
	 * @return array{code: string, severity: string, message: string, channels: string[], fix: string}
	 */
	protected function finding( string $code, string $severity, string $message, array $channels = array(), string $fix = '' ): array {
		return array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
			'channels' => array_values( $channels ),
			'fix'      => $fix,
		);
	}

	/**
	 * Works out the findings for one code across the channels that are on.
	 *
	 * `$evaluate` is called with a platform id (an empty string for the article
	 * alone) and returns null when the article passes, a sentence when it fails,
	 * or array( sentence, weight ) when the sentence depends on a limit: among
	 * channels with one severity the lowest weight is the sentence used, so it is
	 * true for every channel in the group.
	 *
	 * With `$specific` true the finding is about what a platform asks for, so it is
	 * raised only for channels whose pack has a rule with this code; when none does,
	 * it is raised once for the article with the default severity. With `$specific`
	 * false it concerns every channel that is on.
	 *
	 * @param Article  $article  The article.
	 * @param string   $code     Finding code.
	 * @param callable $evaluate Function( string $platform ): null|string|array.
	 * @param string   $fix      Fix slug.
	 * @param bool     $specific Whether only channels whose pack names the code are told.
	 * @param string   $fallback  Severity when a pack has no rule; empty for the article-checks pack's.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	protected function emit( Article $article, string $code, callable $evaluate, string $fix = '', bool $specific = false, string $fallback = '' ): array {
		$fallback = '' === $fallback ? $this->default_severity( $code ) : $fallback;
		$ids      = $this->channel_ids( $article );
		$told     = $ids;

		if ( $specific ) {
			$told = array();

			foreach ( $ids as $id ) {
				if ( $this->declares( $id, $code ) ) {
					$told[] = $id;
				}
			}
		}

		if ( array() === $told ) {
			$result = $this->normalise_result( $evaluate( '' ) );

			return null === $result ? array() : array( $this->finding( $code, $fallback, $result[0], array(), $fix ) );
		}

		$groups = array();

		foreach ( $told as $id ) {
			$result = $this->normalise_result( $evaluate( $id ) );

			if ( null === $result ) {
				continue;
			}

			$severity = $this->severity_for( $id, $code, $fallback );

			if ( ! isset( $groups[ $severity ] ) ) {
				$groups[ $severity ] = array(
					'message'  => $result[0],
					'weight'   => $result[1],
					'channels' => array(),
				);
			}

			if ( $result[1] < $groups[ $severity ]['weight'] ) {
				$groups[ $severity ]['message'] = $result[0];
				$groups[ $severity ]['weight']  = $result[1];
			}

			$groups[ $severity ]['channels'][] = $id;
		}

		$findings = array();

		foreach ( array( Status::BLOCK, Status::WARN, Status::INFO ) as $severity ) {
			if ( ! isset( $groups[ $severity ] ) ) {
				continue;
			}

			$group      = $groups[ $severity ];
			$every      = count( $group['channels'] ) === count( $ids );
			$findings[] = $this->finding( $code, $severity, $group['message'], $every ? array() : $group['channels'], $fix );
		}

		return $findings;
	}

	/**
	 * Turns what an evaluate function returned into array( sentence, weight ) or null.
	 *
	 * @param mixed $result Null, a sentence, or array( sentence, weight ).
	 * @return array{0: string, 1: float}|null
	 */
	private function normalise_result( $result ): ?array {
		if ( null === $result || '' === $result ) {
			return null;
		}

		if ( is_array( $result ) ) {
			return array( (string) $result[0], (float) ( $result[1] ?? 0 ) );
		}

		return array( (string) $result, 0.0 );
	}

	/**
	 * The pack keys to look under for a code: the code, then its aliases.
	 *
	 * @param string $code Finding code.
	 * @return string[]
	 */
	private function keys( string $code ): array {
		return array_merge( array( $code ), self::PACK_ALIASES[ $code ] ?? array() );
	}
}
