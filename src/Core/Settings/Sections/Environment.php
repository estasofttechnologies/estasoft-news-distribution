<?php
/**
 * Settings, Environment.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Setup\DemoPanel;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\GlobalBanner;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Demo\Demo;
use Estasoft\NewsDistribution\Core\Environment as Site;
use Estasoft\NewsDistribution\Modules\EnvironmentModule;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Staging mode and demo mode, the support information, and what News Distribution
 * found about the site it runs on. The work is done by Core\Environment (the probes
 * and the support text) and Modules\EnvironmentModule (the banner and the buttons).
 *
 * Saved choices:
 *
 * - `mode`: `auto` follows the WordPress environment type and pauses pushes only for
 *   `staging`; `on` always pauses them; `off` never pauses them for staging.
 * - `demo`: pauses pushes too, and holds the feeds and sitemaps that platforms pull. The first-run demo turns it
 *   on and "Exit demo" turns it off. It is a site's own state, not a setting to carry to another site, so a
 *   settings export leaves it out (`portable` is false).
 */
final class Environment extends AbstractSiteSection {

	public const NAME = 'environment';

	private ?Site $service = null;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'mode' => array(
				'type'    => 'enum',
				'label'   => __( 'Mode', 'estasoft-news-distribution' ),
				'default' => 'auto',
				'control' => 'select',
				'width'   => 360,
				'options' => array(
					'auto' => __( 'Automatic: follow the environment type', 'estasoft-news-distribution' ),
					'on'   => __( 'Always on', 'estasoft-news-distribution' ),
					'off'  => __( 'Always off', 'estasoft-news-distribution' ),
				),
			),
			'demo' => array(
				'type'      => 'bool',
				'label'     => __( 'Demo mode', 'estasoft-news-distribution' ),
				'row_label' => __( 'Demo mode', 'estasoft-news-distribution' ),
				'default'   => false,
				'control'   => 'checkbox',
				'title'     => __( 'Hold every push and every feed while this site is a demo', 'estasoft-news-distribution' ),
				'help'      => __( 'The first-run demo turns this on. Nothing is pushed and no feed or sitemap is served while it is on, whatever Mode says.', 'estasoft-news-distribution' ),
				'portable'  => false,
			),
		);
	}

	public function title(): string {
		return __( 'Environment', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'site';
	}

	public function order(): int {
		return 40;
	}

	/**
	 * The environment service.
	 *
	 * @return Site
	 */
	private function service(): Site {
		if ( null === $this->service ) {
			$this->service = new Site( Plugin::instance() );
		}

		return $this->service;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'staging',
					'title'       => __( 'Staging mode', 'estasoft-news-distribution' ),
					'description' => __( 'Keeps test posts from reaching partners.', 'estasoft-news-distribution' ),
					'rows'        => $this->staging_rows(),
				),
				array(
					'id'          => 'support',
					'title'       => __( 'Support information', 'estasoft-news-distribution' ),
					'description' => __( 'Copy this when you ask for help.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Included', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_included' ),
						),
						array(
							'label' => __( 'Never included', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_never' ),
						),
						array(
							'label' => __( 'Copy or download', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_support_actions' ),
						),
					),
				),
				array(
					'id'          => 'site',
					'title'       => __( 'Your site', 'estasoft-news-distribution' ),
					'description' => __( 'What News Distribution found when it looked. It can see whether a plugin is there, not whether it is set up.', 'estasoft-news-distribution' ),
					'rows'        => $this->probe_rows(),
				),
			),
			'cannot' => array(
				__( 'Tell a staging copy from the live site when your host does not say so. It reads the WordPress environment type, so a staging copy that reports “production” is treated as live. Set Mode to Always on for that copy', 'estasoft-news-distribution' ),
				__( 'Stop other plugins or your host from sending anything. It pauses its own pushes only', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The rows of the Staging mode card. The row about sample articles is there only while the demo's samples are.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function staging_rows(): array {
		$rows = array(
			array(
				'label' => __( 'Detected', 'estasoft-news-distribution' ),
				'html'  => array( $this, 'render_detected' ),
			),
			array( 'field' => 'mode' ),
			array( 'field' => 'demo' ),
		);

		if ( ( new Demo( Plugin::instance() ) )->has_samples() ) {
			$rows[] = array(
				'label' => __( 'Sample articles', 'estasoft-news-distribution' ),
				'wide'  => true,
				'html'  => array( $this, 'render_samples' ),
			);
		}

		$rows[] = array(
			'label' => __( 'While it is on', 'estasoft-news-distribution' ),
			'html'  => array( $this, 'render_effects' ),
		);
		$rows[] = array(
			'label' => __( 'How it looks', 'estasoft-news-distribution' ),
			'html'  => array( $this, 'render_banner_preview' ),
		);

		return $rows;
	}

	/**
	 * One row for each probe, then the "Check again" row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function probe_rows(): array {
		$rows = array();

		foreach ( $this->service()->probes( false ) as $name => $probe ) {
			$rows[] = array(
				'label' => (string) $probe['label'],
				'html'  => function () use ( $name ): string {
					return $this->render_probe( (string) $name );
				},
			);
		}

		$rows[] = array(
			'label' => __( 'Check again', 'estasoft-news-distribution' ),
			'wide'  => true,
			'html'  => array( $this, 'render_check_again' ),
		);

		return $rows;
	}

	/**
	 * The "Detected" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_detected(): string {
		$state = $this->service()->staging();
		$names = array(
			'staging'     => __( 'Staging', 'estasoft-news-distribution' ),
			'production'  => __( 'Live (production)', 'estasoft-news-distribution' ),
			'development' => __( 'Development', 'estasoft-news-distribution' ),
			'local'       => __( 'Local', 'estasoft-news-distribution' ),
		);
		$type  = (string) $state['detected'];
		$name  = $names[ $type ] ?? $type;
		$tone  = 'staging' === $type ? 'warning' : 'success';

		$line = $state['paused']
			? __( 'Pushes are paused right now.', 'estasoft-news-distribution' )
			: __( 'Pushes are not paused.', 'estasoft-news-distribution' );

		return self::result( $tone, $name )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'WordPress environment type', 'estasoft-news-distribution' ) . '. ' . esc_html( $line ) );
	}

	/**
	 * The "Sample articles" row: how many there are, and the way out.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_samples(): string {
		return DemoPanel::row( Plugin::instance() );
	}

	/**
	 * Whether the demo flag is what holds things back (it wins over staging mode).
	 *
	 * @return bool
	 */
	private function demo_is_on(): bool {
		return (bool) $this->service()->staging()['demo'];
	}

	/**
	 * The "While it is on" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_effects(): string {
		if ( $this->demo_is_on() ) {
			return self::icon_list(
				array(
					__( 'Nothing is pushed: no WebSub pings and no outlet webhooks', 'estasoft-news-distribution' ),
					__( 'Feeds and sitemaps are not served: a platform that asks for one gets “503, not sent: demo mode is on”', 'estasoft-news-distribution' ),
					__( 'No alert or digest email goes out', 'estasoft-news-distribution' ),
					__( 'Sample articles are hidden from visitors and start with “Sample:”', 'estasoft-news-distribution' ),
					__( 'A banner shows on every News Distribution screen', 'estasoft-news-distribution' ),
				)
			);
		}

		return self::icon_list(
			array(
				__( 'Pushes are paused: no WebSub pings and no outlet webhooks', 'estasoft-news-distribution' ),
				__( 'Feeds and sitemaps still answer platforms that pull them, marked noindex, which asks search engines not to keep them. It is a request, not a block.', 'estasoft-news-distribution' ),
				__( 'A banner shows on every News Distribution screen', 'estasoft-news-distribution' ),
				__( 'The delivery log records what would have been sent', 'estasoft-news-distribution' ),
			)
		);
	}

	/**
	 * The "How it looks" row: the banner as it shows on every screen.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_banner_preview(): string {
		$demo = $this->demo_is_on();

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-banner-preview' ),
			GlobalBanner::render(
				$demo ? __( 'Demo mode.', 'estasoft-news-distribution' ) : __( 'Staging mode.', 'estasoft-news-distribution' ),
				$demo ? DemoPanel::effect( Plugin::instance() ) : __( 'Nothing is pushed to platforms from this site.', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * The "Included" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_included(): string {
		return self::icon_list(
			array(
				__( 'News Distribution, WordPress and PHP versions', 'estasoft-news-distribution' ),
				__( 'The version of each rule pack', 'estasoft-news-distribution' ),
				__( 'The channels that are on, by name', 'estasoft-news-distribution' ),
				__( 'Plugins that touch sitemaps, feeds or tags', 'estasoft-news-distribution' ),
				__( 'The time zone and the languages News Distribution uses', 'estasoft-news-distribution' ),
				__( 'What the checks in Your site found', 'estasoft-news-distribution' ),
				__( 'The last 20 errors, without article text', 'estasoft-news-distribution' ),
			)
		);
	}

	/**
	 * The "Never included" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_never(): string {
		return self::icon_list(
			array(
				__( 'Keys, tokens, passwords or email addresses', 'estasoft-news-distribution' ),
				__( 'Article titles or text', 'estasoft-news-distribution' ),
				__( 'Your visitors’ data', 'estasoft-news-distribution' ),
				__( 'Your site’s address', 'estasoft-news-distribution' ),
			),
			'minus-circle',
			'neutral'
		);
	}

	/**
	 * The buttons under the support information, and the text itself for anyone who cannot use them.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_support_actions(): string {
		$text = $this->service()->support_text();

		$buttons = Button::button(
			__( 'Copy support information', 'estasoft-news-distribution' ),
			array(
				'attrs' => array(
					'data-copy-target' => 'estasoft-nd-support-text',
					'hidden'           => true,
				),
			)
		)
			. Button::link(
				__( 'Download as a file', 'estasoft-news-distribution' ),
				self::action_url( EnvironmentModule::ACTION_DOWNLOAD )
			)
			. Html::tag(
				'span',
				array(
					'class'            => 'estasoft-nd-status estasoft-nd-status--success estasoft-nd-copy-status',
					'role'             => 'status',
					'data-copy-status' => '1',
					'hidden'           => true,
				),
				Icon::svg( 'check-circle' ) . Html::tag( 'span', array( 'data-copy-message' => '1' ), esc_html__( 'Copied to the clipboard', 'estasoft-news-distribution' ) )
			);

		$show = Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-support-text' ),
			Html::tag( 'summary', array(), esc_html__( 'Show what will be copied', 'estasoft-news-distribution' ) )
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => 'estasoft-nd-support-text',
				),
				esc_html__( 'Support information', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'textarea',
				array(
					'id'       => 'estasoft-nd-support-text',
					'class'    => 'estasoft-nd-field estasoft-nd-support-text__box',
					'readonly' => true,
					'rows'     => 14,
					'dir'      => 'ltr',
				),
				esc_textarea( $text )
			)
		);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-support-actions' ), $buttons ) . $show;
	}

	/**
	 * One "Your site" row.
	 *
	 * @param string $name The probe name.
	 * @return string Escaped HTML.
	 */
	private function render_probe( string $name ): string {
		$probe = $this->service()->probe( $name, false );
		$tones = array(
			'ok'      => 'success',
			'info'    => 'neutral',
			'warning' => 'warning',
			'problem' => 'danger',
			'unknown' => 'neutral',
		);
		$icons = array(
			'ok'      => 'check-circle',
			'info'    => 'minus-circle',
			'warning' => 'warning',
			'problem' => 'x-circle',
			'unknown' => 'clock',
		);
		$state = (string) $probe['status'];

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-probe' ),
			Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . ( $tones[ $state ] ?? 'neutral' ) ),
				Icon::svg( $icons[ $state ] ?? 'minus-circle' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-probe__text' ),
				Html::tag( 'strong', array(), esc_html( (string) $probe['summary'] ) )
				. ( '' !== (string) $probe['detail'] ? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $probe['detail'] ) ) : '' )
			)
		);
	}

	/**
	 * The "Check again" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_check_again(): string {
		$latest = 0;

		foreach ( $this->service()->probes( false ) as $probe ) {
			$latest = max( $latest, (int) $probe['checked_at'] );
		}

		$note = $latest > 0
			? sprintf(
				/* translators: %s: a time such as 14:35. */
				__( 'Last checked %s. The checks of robots.txt and the REST address read your own site, and only when you press the button.', 'estasoft-news-distribution' ),
				Clock::format( $latest )
			)
			: __( 'The checks of robots.txt and the REST address read your own site, and only when you press the button.', 'estasoft-news-distribution' );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-support-actions' ),
			Button::link( __( 'Check again', 'estasoft-news-distribution' ), self::action_url( EnvironmentModule::ACTION_CHECK ), array( 'icon' => 'retry' ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $note ) )
		);
	}
}
