<?php
/**
 * Base for the top-level screens: Overview, Channels, Article check, Reports, Delivery log, Settings.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * To add a screen: create `src/Admin/Pages/<Name>Page.php` extending this class.
 * It is found by folder, gets a menu entry and a tab, and is wrapped in the
 * app bar. It only has to say who it is and print its body.
 *
 * Page address: `admin.php?page=<slug>`. Slugs are fixed by the spec:
 * estasoft-nd (Overview), -channels, -check, -reports, -log, -settings, -setup.
 */
abstract class Page {

	protected Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The `page` query argument.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * The tab and menu name.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * Prints the body: the heading and everything under it. Return escaped HTML;
	 * it is printed through Output::show().
	 *
	 * @return string
	 */
	abstract public function render(): string;

	/**
	 * Position among the tabs. Lower comes first.
	 *
	 * @return int
	 */
	public function order(): int {
		return 50;
	}

	/**
	 * The permission (a Core\Access constant) needed to open the screen.
	 *
	 * @return string
	 */
	public function permission(): string {
		return Access::VIEW;
	}

	/**
	 * Whether the Simple view shows this screen as a tab. The others sit under More.
	 *
	 * @return bool
	 */
	public function in_simple_view(): bool {
		return false;
	}

	/**
	 * Whether the screen is left out of the menu and the tabs (the setup wizard).
	 *
	 * @return bool
	 */
	public function is_hidden(): bool {
		return false;
	}

	/**
	 * Whether the screen exists on this site. A screen that needs multisite or a
	 * newer WordPress returns false elsewhere.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return true;
	}

	/**
	 * Runs on admin_init, before any output, when this screen is the one being opened.
	 * Use it for redirects and for form posts that do not go through admin-post.php.
	 *
	 * @return void
	 */
	public function handle(): void {
	}

	/**
	 * Runs when assets are enqueued on this screen. Enqueue the screen's own scripts here.
	 *
	 * @return void
	 */
	public function enqueue(): void {
	}

	/**
	 * Address of this screen.
	 *
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return string
	 */
	public function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $this->slug() ), $args ), admin_url( 'admin.php' ) );
	}
}
