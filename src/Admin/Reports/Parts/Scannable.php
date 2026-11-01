<?php
/**
 * A report that reads pages of the site and keeps what it found.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

defined( 'ABSPATH' ) || exit;

/**
 * The self-test, the hreflang audit and the Discover check read the site's own
 * addresses, which takes time and sends requests to the site itself. They never
 * do it when a screen opens or an article is published. A person presses a button,
 * the work runs in the queue as a job (`Core\Jobs`), and the result is stored for
 * the screen and for `GET /reports/{name}`.
 */
interface Scannable {

	/**
	 * The units of work in one scan, for the job's progress bar.
	 *
	 * @return int
	 */
	public function scan_units(): int;

	/**
	 * Does the work. It runs in the queue, never in a page load.
	 *
	 * @param callable $progress Call with the units done so far.
	 * @return array<string, mixed> What was found. It is stored as the report's last result.
	 */
	public function scan( callable $progress ): array;

	/**
	 * The sentence a finished job gives the screen.
	 *
	 * @param array<string, mixed> $result What scan() returned.
	 * @return string
	 */
	public function scan_message( array $result ): string;
}
