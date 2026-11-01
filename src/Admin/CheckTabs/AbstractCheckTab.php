<?php
/**
 * Base for a tab on an article under Article check.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-check&post=<id>&tab=<slug>`, or without a post
 * (`&tab=images`) for a tab about the whole site.
 *
 * To add one: create `src/Admin/CheckTabs/<Name>Tab.php` extending this class.
 * The Article check page finds it by folder, adds it to the article's tabs when
 * it applies, and opens it when the address names it. The article's own check
 * results are the default tab and are drawn by the page itself.
 *
 * A tab prints below the article heading; return escaped HTML, which is printed
 * through Admin\Output::show().
 */
abstract class AbstractCheckTab {

	protected Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The `tab` argument: "history", "release", "takedown", "assign", "ai", "images".
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * The tab name.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * The last part of the breadcrumb of a tab about the whole site, which is the tab name unless the tab says more,
	 * such as the title of the article it is showing.
	 *
	 * @param int $post_id Always 0 for a tab about the whole site.
	 * @return string Plain text.
	 */
	public function crumb( int $post_id ): string {
		unset( $post_id );

		return $this->title();
	}

	/**
	 * One line under the heading, when the tab is opened on its own. Optional.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Position among the tabs. Lower comes first.
	 *
	 * @return int
	 */
	public function order(): int {
		return 50;
	}

	/**
	 * Whether the tab is about one article. A tab about the whole site returns false.
	 *
	 * @return bool
	 */
	public function needs_article(): bool {
		return true;
	}

	/**
	 * Permission needed to open the tab: a Core\Access constant. For a tab about
	 * an article the check is made with the article id.
	 *
	 * @return string
	 */
	public function permission(): string {
		return Access::VIEW_OWN;
	}

	/**
	 * Whether the tab exists on this site, or applies to this article.
	 *
	 * @param int $post_id Article id, or 0 for a tab about the whole site.
	 * @return bool
	 */
	public function available( int $post_id ): bool {
		return true;
	}

	/**
	 * Prints the tab.
	 *
	 * @param int $post_id Article id, or 0 for a tab about the whole site.
	 * @return string Escaped HTML.
	 */
	abstract public function render( int $post_id ): string;

	/**
	 * Runs on admin_init, before any output, for form posts and redirects.
	 *
	 * @param int $post_id Article id, or 0.
	 * @return void
	 */
	public function handle( int $post_id ): void {
	}

	/**
	 * Enqueue this tab's own script or stylesheet (assets/js/screens/, assets/css/screens/).
	 *
	 * @return void
	 */
	public function enqueue(): void {
	}

	/**
	 * Address of this tab.
	 *
	 * @param int                   $post_id Article id, or 0.
	 * @param array<string, scalar> $args    Extra query arguments.
	 * @return string
	 */
	public function url( int $post_id = 0, array $args = array() ): string {
		$query = array(
			'page' => 'estasoft-nd-check',
			'tab'  => $this->slug(),
		);

		if ( $post_id > 0 ) {
			$query['post'] = $post_id;
		}

		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}
}
