<?php
/**
 * The page of the Outlets channel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Channels\Outlets\Actions;
use Estasoft\NewsDistribution\Admin\Channels\Outlets\AttributionTab;
use Estasoft\NewsDistribution\Admin\Channels\Outlets\Flash;
use Estasoft\NewsDistribution\Admin\Channels\Outlets\OverviewTab;
use Estasoft\NewsDistribution\Admin\Channels\Outlets\SocialTab;
use Estasoft\NewsDistribution\Admin\Channels\Outlets\TelegramTab;
use Estasoft\NewsDistribution\Admin\Channels\Outlets\WebhookTab;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * One page, four views: the Medium queue and webhooks, the attribution line,
 * Telegram, and Mastodon with Bluesky. The channel frame draws the heading; the
 * views are chosen with a row of links under it (the frame's own tabs are for
 * pages whose sections do not need a heading of their own, and this page's
 * description sits above its switch).
 *
 * Every button posts to this page and Actions does the work, then returns here
 * with a message. Nothing on this page runs on publish: a request to another
 * service leaves only from a button a person pressed, or from the queue.
 */
final class OutletsScreen extends AbstractChannelScreen {

	/**
	 * The views, in the order of the switch.
	 *
	 * @var string[]
	 */
	public const TABS = array( 'overview', 'attribution', 'telegram', 'social' );

	private OutletsChannel $outlets;

	/**
	 * Creates the page.
	 *
	 * @param Plugin           $plugin  The running plugin.
	 * @param ChannelInterface $channel The channel; the Outlets channel.
	 *
	 * @throws \InvalidArgumentException When the channel is not the Outlets channel.
	 */
	public function __construct( Plugin $plugin, ChannelInterface $channel ) {
		if ( ! $channel instanceof OutletsChannel ) {
			throw new \InvalidArgumentException( 'The Outlets page needs the Outlets channel.' );
		}

		parent::__construct( $plugin, $channel );

		$this->outlets = $channel;
	}

	public static function channel_id(): string {
		return 'outlets';
	}

	public function description(): string {
		switch ( $this->open_tab() ) {
			case 'attribution':
				return __( 'The attribution line for each language.', 'estasoft-news-distribution' );
			case 'telegram':
				return __( 'Post a link to a Telegram channel when an article goes live.', 'estasoft-news-distribution' );
			case 'social':
				return __( 'Post a link to a Mastodon or Bluesky account when an article goes live.', 'estasoft-news-distribution' );
		}

		return __( 'News Distribution sends each published article to your webhooks, Telegram, Mastodon and Bluesky. Medium has no sending option now, so its stories are imported by hand.', 'estasoft-news-distribution' );
	}

	/**
	 * The page's own switch is printed by render(), so the frame draws no tabs.
	 *
	 * @return array<string, string>
	 */
	public function tabs(): array {
		return array();
	}

	/**
	 * What the buttons on the page asked for. Runs before anything is printed.
	 *
	 * @param string $tab The tab slug (always empty: the page has its own switch).
	 * @return void
	 */
	public function handle( string $tab ): void {
		unset( $tab );

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method || ! isset( $_POST['estasoft_nd_outlets'] ) ) {
			return;
		}

		check_admin_referer( Actions::NONCE );

		wp_safe_redirect( ( new Actions( $this, $this->outlets, $this->plugin ) )->run() );
		exit;
	}

	/**
	 * Adds the page's stylesheet and scripts.
	 *
	 * @param string $tab The tab slug (always empty).
	 * @return void
	 */
	public function enqueue( string $tab ): void {
		unset( $tab );

		Assets::screen_style( 'outlets' );
		Assets::screen_script( 'outlets' );
		Assets::screen_script( 'dirty-form' );
	}

	/**
	 * Prints the switch, the messages left by the last button, and the open view.
	 *
	 * @param string $tab The tab slug (always empty).
	 * @return string
	 */
	public function render( string $tab ): string {
		unset( $tab );

		$open = $this->open_tab();
		$html = $this->switcher( $open );

		foreach ( Flash::take() as $message ) {
			$html .= Message::text( $message['tone'], $message['text'] );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-24' ), $html . $this->view( $open ) );
	}

	/**
	 * The view that is open: the `tab` in the address, or the first one.
	 *
	 * @return string One of TABS.
	 */
	public function open_tab(): string {
		$wanted = Query::key( 'tab' );

		return in_array( $wanted, self::TABS, true ) ? $wanted : self::TABS[0];
	}

	/**
	 * The settings as the page shows them: secrets as masks, a refused save put back as typed.
	 *
	 * @return array<string, mixed>
	 */
	public function view_values(): array {
		return $this->values();
	}

	/**
	 * Messages of a refused save, by setting name.
	 *
	 * @return array<string, string>
	 */
	public function field_errors(): array {
		return $this->errors();
	}

	/**
	 * The scope that keeps control ids unique on the page.
	 *
	 * @return string
	 */
	public function scope_id(): string {
		return $this->scope();
	}

	/**
	 * Whether the person may enter, change or remove tokens, passwords and endpoint addresses.
	 *
	 * @return bool
	 */
	public function can_change_secrets(): bool {
		return $this->plugin->access()->can( Access::ADMIN );
	}

	/**
	 * The row of links under the description.
	 *
	 * @param string $open The open view.
	 * @return string
	 */
	private function switcher( string $open ): string {
		$labels = array(
			'overview'    => __( 'Queue and webhooks', 'estasoft-news-distribution' ),
			'attribution' => __( 'Attribution', 'estasoft-news-distribution' ),
			'telegram'    => __( 'Telegram', 'estasoft-news-distribution' ),
			'social'      => __( 'Mastodon and Bluesky', 'estasoft-news-distribution' ),
		);
		$links  = '';

		foreach ( $labels as $slug => $label ) {
			$on     = $slug === $open;
			$links .= Html::tag(
				'a',
				array(
					'class'        => $on ? 'is-on' : null,
					'href'         => $this->url( $slug ),
					'aria-current' => $on ? 'page' : null,
				),
				esc_html( $label )
			);
		}

		return Html::tag(
			'nav',
			array( 'class' => 'estasoft-nd-outlets-switch' ),
			Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-seg',
					'aria-label' => __( 'Outlets view', 'estasoft-news-distribution' ),
				),
				$links
			)
		);
	}

	/**
	 * The open view.
	 *
	 * @param string $open The open view.
	 * @return string
	 */
	private function view( string $open ): string {
		if ( 'attribution' === $open ) {
			return ( new AttributionTab( $this, $this->outlets, $this->plugin ) )->render();
		}

		if ( 'telegram' === $open ) {
			return ( new TelegramTab( $this, $this->outlets, $this->plugin ) )->render();
		}

		if ( 'social' === $open ) {
			return ( new SocialTab( $this, $this->outlets, $this->plugin ) )->render();
		}

		$webhook = Query::key( 'webhook' );

		if ( 1 === preg_match( '/^(new|[1-9][0-9]*)$/', $webhook ) ) {
			return ( new WebhookTab( $this, $this->outlets, $this->plugin, $webhook ) )->render();
		}

		return ( new OverviewTab( $this, $this->outlets, $this->plugin ) )->render();
	}
}
