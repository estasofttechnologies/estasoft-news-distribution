<?php
/**
 * Addresses of other News Distribution screens, only when they exist and the person may open them.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Admin\CheckTabs\AbstractCheckTab;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * The Article check screens link to the delivery log, to the assign tab, to the image presets. Those
 * screens belong to other workstreams and may not exist on a site, so a link is only made when the
 * screen is found by folder and the signed-in person holds its permission. Otherwise the link is an
 * empty string and the caller prints nothing.
 */
final class Links {

	private Plugin $plugin;

	/**
	 * Screens by slug, once found.
	 *
	 * @var array<string, Page>|null
	 */
	private ?array $pages = null;

	/**
	 * Tabs by slug, once found.
	 *
	 * @var array<string, AbstractCheckTab>|null
	 */
	private ?array $tabs = null;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The address of a top-level screen.
	 *
	 * @param string                $slug The `page` argument, such as "estasoft-nd-log".
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return string An empty string when the screen is not there or the person may not open it.
	 */
	public function page( string $slug, array $args = array() ): string {
		if ( null === $this->pages ) {
			$this->pages = array();

			foreach ( Discovery::classes( 'Admin/Pages' ) as $class ) {
				if ( class_exists( $class ) && is_subclass_of( $class, Page::class ) ) {
					$page = new $class( $this->plugin );

					if ( $page->available() ) {
						$this->pages[ $page->slug() ] = $page;
					}
				}
			}
		}

		if ( ! isset( $this->pages[ $slug ] ) || ! $this->plugin->access()->can( $this->pages[ $slug ]->permission() ) ) {
			return '';
		}

		return $this->pages[ $slug ]->url( $args );
	}

	/**
	 * Every tab found by folder, by slug.
	 *
	 * @return array<string, AbstractCheckTab>
	 */
	public function all_tabs(): array {
		if ( null === $this->tabs ) {
			$this->tabs = array();

			foreach ( Discovery::classes( 'Admin/CheckTabs' ) as $class ) {
				if ( class_exists( $class ) && is_subclass_of( $class, AbstractCheckTab::class ) ) {
					$tab = new $class( $this->plugin );

					$this->tabs[ $tab->slug() ] = $tab;
				}
			}
		}

		return $this->tabs;
	}

	/**
	 * The tabs a person sees on one article, or on no article, in order.
	 *
	 * @param int $post_id Article, or 0 for the tabs about the whole site.
	 * @return array<string, AbstractCheckTab>
	 */
	public function tabs( int $post_id ): array {
		$access = $this->plugin->access();
		$out    = array();

		foreach ( $this->all_tabs() as $slug => $tab ) {
			if ( $tab->needs_article() !== ( $post_id > 0 ) || ! $tab->available( $post_id ) ) {
				continue;
			}

			if ( ! $access->can( $tab->permission(), $post_id > 0 ? $post_id : null ) ) {
				continue;
			}

			$out[ $slug ] = $tab;
		}

		uasort(
			$out,
			static function ( AbstractCheckTab $a, AbstractCheckTab $b ): int {
				return $a->order() <=> $b->order();
			}
		);

		return $out;
	}

	/**
	 * The address of one tab.
	 *
	 * @param string                $slug    Tab slug.
	 * @param int                   $post_id Article, or 0 for a tab about the whole site.
	 * @param array<string, scalar> $args    Extra query arguments.
	 * @return string An empty string when the tab is not there for this person.
	 */
	public function tab( string $slug, int $post_id, array $args = array() ): string {
		$tabs = $this->tabs( $post_id );

		return isset( $tabs[ $slug ] ) ? $tabs[ $slug ]->url( $post_id, $args ) : '';
	}
}
