<?php
/**
 * Base for a report under Reports.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-reports&report=<name>`.
 *
 * To add one: create `src/Admin/Reports/<Name>Report.php` extending this class.
 * The Reports page lists the reports that exist and are available, and opens
 * the one named in the address. A report prints below the page heading;
 * return escaped HTML, which is printed through Admin\Output::show().
 */
abstract class AbstractReport {

	protected Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The `report` argument and the REST name: "self-test", "hreflang", "fetch-monitor".
	 *
	 * @return string
	 */
	abstract public function name(): string;

	/**
	 * The name in the report list and the heading.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * One line under the heading and in the report list.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Permission needed to open the report: a Core\Access constant.
	 *
	 * @return string
	 */
	public function permission(): string {
		return Access::VIEW;
	}

	/**
	 * The group in the report list: "platforms", "content" or "audience".
	 *
	 * @return string
	 */
	public function group(): string {
		return 'content';
	}

	/**
	 * Position in the report list. Lower comes first.
	 *
	 * @return int
	 */
	public function order(): int {
		return 50;
	}

	/**
	 * Whether the report exists on this site. A report that needs a translation
	 * plugin, multisite or WordPress 7.0 returns false elsewhere.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return true;
	}

	/**
	 * The figures behind the report, shared by the screen and the REST route
	 * `GET /reports/<name>`. Plain values only: no HTML, times as ISO strings.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return array();
	}

	/**
	 * Buttons or a line for the right side of the page heading, such as the self-test's "Run now".
	 *
	 * @return string Escaped HTML, or an empty string.
	 */
	public function actions(): string {
		return '';
	}

	/**
	 * Prints the report.
	 *
	 * @return string Escaped HTML.
	 */
	abstract public function render(): string;

	/**
	 * Runs on admin_init, before any output, for form posts and redirects.
	 *
	 * @return void
	 */
	public function handle(): void {
	}

	/**
	 * Enqueue this report's own script or stylesheet (assets/js/screens/, assets/css/screens/).
	 *
	 * @return void
	 */
	public function enqueue(): void {
	}

	/**
	 * Address of this report.
	 *
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'   => 'estasoft-nd-reports',
					'report' => $this->name(),
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}
}
