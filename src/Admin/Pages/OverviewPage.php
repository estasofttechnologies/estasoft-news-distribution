<?php
/**
 * Overview: what the latest articles did on each channel, and what needs the person.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Pages;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Overview\OverviewData;
use Estasoft\NewsDistribution\Admin\Overview\OverviewView;
use Estasoft\NewsDistribution\Admin\Page;
use Estasoft\NewsDistribution\Admin\Setup\Onboarding;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Modules\OverviewModule;
use Estasoft\NewsDistribution\Modules\SetupModule;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd`. It reads `Admin\Overview\OverviewData`, the same service
 * `GET /overview` answers with, so the screen and the route never disagree. Everything is
 * read from stored results; opening the screen runs no check and calls no other server.
 *
 * Order, full view: the path to each platform (while it is wanted), what needs attention,
 * the channels, the latest articles. Simple view puts the articles before the channels.
 * When nothing needs attention a green bar replaces the list and the channels fold up.
 */
final class OverviewPage extends Page {

	public function slug(): string {
		return 'estasoft-nd';
	}

	public function title(): string {
		return __( 'Overview', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 10;
	}

	public function in_simple_view(): bool {
		return true;
	}

	public function enqueue(): void {
		Assets::screen_style( 'overview' );
		Assets::screen_script( 'overview' );
	}

	/**
	 * The Refresh button: forget the copy the widget keeps, then show the screen again.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! isset( $_GET['refresh'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only asks whether Refresh was pressed; the nonce is checked next, before anything is done.
			return;
		}

		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'estasoft_nd_overview_refresh' ) ) {
			OverviewData::forget();
		}

		$args     = array();
		$language = $this->language();

		if ( '' !== $language ) {
			$args['language'] = $language;
		}

		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	/**
	 * The language the person is looking at, or an empty string for all.
	 *
	 * @return string
	 */
	private function language(): string {
		$code = Query::key( 'language' );

		return ( new OverviewData( $this->plugin ) )->language( $code );
	}

	public function render(): string {
		$this->plugin->access()->require_or_die( Access::VIEW );

		$user     = get_current_user_id();
		$language = $this->language();
		$service  = new OverviewData( $this->plugin );
		$data     = $service->build( array( 'language' => $language ) );
		$view     = new OverviewView();
		$simple   = 'full' !== (string) get_user_meta( $user, Admin::VIEW_META, true );
		$clean    = (bool) $data['summary']['clean'];
		$mode     = OverviewModule::path_mode( $user );
		$path     = (array) $data['path'];
		$show     = 'hidden' !== $mode && array() !== $path['sections'] && ( 'always' === $mode || ! $path['all_live'] );

		if ( array() !== $data['languages'] ) {
			$filter = $this->language_filter( $data['languages'], $language );

			add_filter(
				'estasoft_nd_internal_appbar_end',
				static function ( $html, $current ) use ( $filter ) {
					return 'estasoft-nd' === $current ? (string) $html . $filter : $html;
				},
				10,
				2
			);
		}

		$html = $this->header( $show, $simple, $language );

		if ( $simple ) {
			$html .= $this->simple_bar();
		}

		if ( $show ) {
			$html .= $view->path(
				$path,
				$mode,
				wp_nonce_field( OverviewModule::ACTION_PATH, '_wpnonce', true, false ),
				admin_url( 'admin-post.php' )
			);
		}

		$html .= $clean ? $view->all_clear() : $view->needs( (array) $data['needs'] );

		$channels = Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-ov-section',
				'aria-labelledby' => 'estasoft-nd-ov-channels',
			),
			$view->heading(
				'estasoft-nd-ov-channels',
				__( 'Channels by connection type', 'estasoft-news-distribution' ),
				'',
				Html::tag( 'a', array( 'href' => Links::admin( 'estasoft-nd-channels' ) ), esc_html__( 'Manage channels', 'estasoft-news-distribution' ) )
			) . $view->channels( (array) $data['groups'] )
		);

		$articles = Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-ov-section',
				'aria-labelledby' => 'estasoft-nd-ov-articles',
			),
			$view->heading(
				'estasoft-nd-ov-articles',
				__( 'Latest articles', 'estasoft-news-distribution' ),
				'',
				Html::tag( 'a', array( 'href' => Links::check() ), esc_html__( 'Open article check', 'estasoft-news-distribution' ) )
			) . $view->articles( (array) $data['articles'], ! $simple && ! $clean )
		);

		$html .= $simple ? $articles . $channels : $channels . $articles;

		if ( 'hidden' === $mode && array() !== $path['sections'] ) {
			$html .= $this->show_path_form();
		}

		return $html;
	}

	/**
	 * A link to the first-run setup, until it has been finished.
	 *
	 * @return string Escaped HTML, or an empty string.
	 */
	private function setup_link(): string {
		if ( ! $this->plugin->access()->can( Access::MANAGE ) ) {
			return '';
		}

		$state = SetupModule::state();

		if ( Onboarding::STATUS_DONE === $state['status'] ) {
			return '';
		}

		if ( Onboarding::STATUS_STARTED === $state['status'] ) {
			return Button::link( __( 'Continue the setup', 'estasoft-news-distribution' ), SetupModule::url( $state['step'] ), array( 'variant' => 'subtle' ) );
		}

		return Button::link( __( 'Run the setup', 'estasoft-news-distribution' ), SetupModule::url( Onboarding::START ), array( 'variant' => 'subtle' ) );
	}

	/**
	 * The heading, with when it was looked at and the Refresh button.
	 *
	 * @param bool   $path     Whether the path card is shown.
	 * @param bool   $simple   Whether the person is in the Simple view.
	 * @param string $language The language filter.
	 * @return string
	 */
	private function header( bool $path, bool $simple, string $language ): string {
		if ( $path ) {
			$text = __( 'Your path to each platform stays here until every platform you started is live.', 'estasoft-news-distribution' );
		} elseif ( $simple ) {
			$text = __( 'What your latest articles did, and what needs you.', 'estasoft-news-distribution' );
		} else {
			$text = __( 'What your latest articles did on each channel, and what needs you.', 'estasoft-news-distribution' );
		}

		$args = array( 'refresh' => 1 );

		if ( '' !== $language ) {
			$args['language'] = $language;
		}

		$actions = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ov-checked' ),
			esc_html(
				sprintf(
					/* translators: %s: a clock time such as 14:35. */
					__( 'Checked %s', 'estasoft-news-distribution' ),
					Clock::format( Clock::now() )
				)
			)
		) . $this->setup_link() . Button::link( __( 'Refresh', 'estasoft-news-distribution' ), wp_nonce_url( $this->url( $args ), 'estasoft_nd_overview_refresh' ) );

		return PageHeader::render( __( 'Overview', 'estasoft-news-distribution' ), $text, Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ), $actions ) );
	}

	/**
	 * The note in the Simple view, with the way to show every screen.
	 *
	 * @return string
	 */
	private function simple_bar(): string {
		$form = Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
			),
			wp_nonce_field( OverviewModule::ACTION_VIEW, '_wpnonce', true, false )
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => OverviewModule::ACTION_VIEW,
				)
			)
			. Button::submit( __( 'Show every screen', 'estasoft-news-distribution' ), array( 'variant' => 'subtle' ) )
		);

		return Message::bar(
			'info',
			Html::tag( 'strong', array(), esc_html__( 'Simple view.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html__( 'You see Overview, Channels and Article check. Reports, the delivery log and Settings are under More.', 'estasoft-news-distribution' ),
			$form
		);
	}

	/**
	 * A button to bring the path card back after it was hidden.
	 *
	 * @return string
	 */
	private function show_path_form(): string {
		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-ov-restore',
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
			),
			wp_nonce_field( OverviewModule::ACTION_PATH, '_wpnonce', true, false )
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => OverviewModule::ACTION_PATH,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'show',
					'value' => '1',
				)
			)
			. Button::submit( __( 'Show the path to each platform', 'estasoft-news-distribution' ), array( 'variant' => 'subtle' ) )
		);
	}

	/**
	 * The language filter at the end of the app bar.
	 *
	 * @param array<int, array{code: string, name: string}> $languages The languages the site has.
	 * @param string                                        $current   The language in use, or an empty string.
	 * @return string
	 */
	private function language_filter( array $languages, string $current ): string {
		$options = Html::tag(
			'option',
			array(
				'value'    => '',
				'selected' => '' === $current,
			),
			esc_html__( 'All languages', 'estasoft-news-distribution' )
		);

		foreach ( $languages as $language ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => $language['code'],
					'selected' => $language['code'] === $current,
				),
				esc_html( $language['name'] )
			);
		}

		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-ov-lang',
				'method' => 'get',
				'action' => admin_url( 'admin.php' ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'page',
					'value' => $this->slug(),
				)
			)
			. Html::tag( 'label', array( 'for' => 'estasoft-nd-ov-language' ), esc_html__( 'Language', 'estasoft-news-distribution' ) )
			. Html::tag(
				'select',
				array(
					'class'            => 'estasoft-nd-field',
					'id'               => 'estasoft-nd-ov-language',
					'name'             => 'language',
					'data-auto-submit' => '1',
				),
				$options
			)
			. Button::submit( __( 'Apply', 'estasoft-news-distribution' ), array( 'class' => 'estasoft-nd-ov-nojs' ) )
		);
	}
}
