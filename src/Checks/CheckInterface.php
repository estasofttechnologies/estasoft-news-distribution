<?php
/**
 * One article check that is not tied to a single channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Checks;

use Estasoft\NewsDistribution\Core\Article;

defined( 'ABSPATH' ) || exit;

/**
 * A check looks at the article itself (image, alt text, credit, title, date,
 * body, duplicates, byline) and reports findings. A finding can name the channels
 * it affects; with no channels it applies to every channel that is on.
 *
 * To add a check: create `src/Checks/<Name>Check.php` implementing this interface.
 * It is found by folder (`Discovery`) and run by `ArticleChecker`.
 *
 * Finding shape: array{
 *   code: string,        // stable, such as "image.alt_missing"
 *   severity: string,    // Status::BLOCK, Status::WARN or Status::INFO
 *   message: string,     // one plain sentence a person can act on
 *   channels: string[],  // channel ids it applies to; empty means all that are on
 *   fix: string          // fix slug the Article check can offer ("add-alt", "add-image"), or ""
 * }
 */
interface CheckInterface {

	/**
	 * Short id, such as "image". Finding codes start with it.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Run order. Lower runs first.
	 *
	 * @return int
	 */
	public function order(): int;

	/**
	 * Runs the check.
	 *
	 * @param Article $article The article.
	 * @return array<int, array{code: string, severity: string, message: string, channels: string[], fix: string}>
	 */
	public function run( Article $article ): array;
}
