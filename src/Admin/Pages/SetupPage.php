<?php
/**
 * First-run setup: name the publication, choose channels, see the first check.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Pages;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Setup\ChannelsStep;
use Estasoft\NewsDistribution\Admin\Setup\CheckStep;
use Estasoft\NewsDistribution\Admin\Setup\Onboarding;
use Estasoft\NewsDistribution\Admin\Setup\PublicationStep;
use Estasoft\NewsDistribution\Admin\Setup\StartStep;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Modules\SetupModule;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-setup&step=start|publication|channels|check`. It is not in the menu or the
 * tabs: the Start setup link on the Plugins screen and the Overview link to it.
 *
 * The screen has its own narrow frame. The app bar and the WordPress menu are hidden by the screen's
 * stylesheet, because the person is in a guided path, not in the plugin yet. Every step is a form that
 * posts to `admin-post.php` (`Modules\SetupModule`), so nothing here needs a script. The script only
 * saves clicks: it ticks the boxes when a setup is chosen and keeps the counts of folded groups.
 */
final class SetupPage extends Page {

	public function slug(): string {
		return SetupModule::PAGE;
	}

	public function title(): string {
		return __( 'Setup', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 90;
	}

	public function permission(): string {
		return Access::MANAGE;
	}

	public function is_hidden(): bool {
		return true;
	}

	/**
	 * Loads the styles and the script, and marks the page so the WordPress menu can be hidden.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		Assets::screen_style( 'address-row' );
		Assets::screen_style( 'setup' );
		Assets::screen_script( 'address-row' );
		Assets::screen_script( 'setup' );

		add_filter(
			'admin_body_class',
			static function ( $classes ) {
				return trim( (string) $classes . ' estasoft-nd-setup-screen' );
			}
		);
	}

	/**
	 * The open step.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$step = $this->step();

		switch ( $step ) {
			case 'publication':
				return ( new PublicationStep( $this->plugin ) )->render( SetupModule::flash( $step ) );
			case 'channels':
				return ( new ChannelsStep( $this->plugin ) )->render();
			case 'check':
				return ( new CheckStep( $this->plugin ) )->render();
			default:
				return StartStep::render();
		}
	}

	/**
	 * The step the address asks for.
	 *
	 * @return string
	 */
	private function step(): string {
		$step = Query::key( 'step' );

		return Onboarding::clean_step( $step );
	}
}
