<?php
/**
 * Base for a channel's own page under Channels.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Ui\FieldRows;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\SaveBar;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=<id>[&tab=<slug>]`.
 *
 * To add one: create `src/Admin/Channels/<Name>Screen.php` extending this class.
 * The Channels page finds it by folder and matches it on channel_id(). A channel
 * with no screen of its own gets a generic page built from its settings fields.
 *
 * The screen prints inside the Channels page: the app bar and the heading
 * (channel name, mode tag, status) are drawn by the page, so render() returns
 * the cards under the heading. Return escaped HTML; it is printed through
 * Admin\Output::show().
 */
abstract class AbstractChannelScreen {

	protected Plugin $plugin;
	protected ChannelInterface $channel;

	/**
	 * The rejected save to show again, read once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stash = null;

	/**
	 * @param Plugin           $plugin  The running plugin.
	 * @param ChannelInterface $channel The channel this screen shows.
	 */
	public function __construct( Plugin $plugin, ChannelInterface $channel ) {
		$this->plugin  = $plugin;
		$this->channel = $channel;
	}

	/**
	 * The channel id this screen is for, such as "google-news".
	 *
	 * @return string
	 */
	abstract public static function channel_id(): string;

	/**
	 * Tabs inside the channel page, as slug => title. The first tab is the
	 * default. An empty list means the page has no tabs.
	 *
	 * @return array<string, string>
	 */
	public function tabs(): array {
		return array();
	}

	/**
	 * The one line under the heading. Empty means the page prints none.
	 *
	 * @return string Plain text.
	 */
	public function description(): string {
		return '';
	}

	/**
	 * The heading and the last part of the breadcrumb. It is the channel's name
	 * unless the open tab is a page of its own, such as the SmartNews readiness report.
	 *
	 * @return string Plain text.
	 */
	public function heading(): string {
		return $this->channel->label();
	}

	/**
	 * Whether the heading shows the channel's mode tag and status pill. A page that
	 * is a checklist rather than a channel (Listings, Site verification) says no.
	 *
	 * @return bool
	 */
	public function shows_status(): bool {
		return true;
	}

	/**
	 * Buttons at the end of the heading row, such as "Test sitemap". Trusted,
	 * escaped HTML built with Admin\Ui\Button.
	 *
	 * @param string $tab The tab slug.
	 * @return string
	 */
	public function actions( string $tab ): string {
		return '';
	}

	/**
	 * Prints one tab.
	 *
	 * @param string $tab The tab slug, or an empty string when there are no tabs.
	 * @return string Escaped HTML.
	 */
	abstract public function render( string $tab ): string;

	/**
	 * Runs on admin_init, before any output, for form posts and redirects.
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function handle( string $tab ): void {
	}

	/**
	 * Enqueue this screen's own script or stylesheet (assets/js/screens/, assets/css/screens/).
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function enqueue( string $tab ): void {
	}

	/**
	 * Address of this channel page or one of its tabs.
	 *
	 * @param string                $tab  Tab slug, or empty.
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return string
	 */
	public function url( string $tab = '', array $args = array() ): string {
		$query = array(
			'page'    => 'estasoft-nd-channels',
			'channel' => $this->channel->id(),
		);

		if ( '' !== $tab ) {
			$query['tab'] = $tab;
		}

		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The channel's saved settings as the screen shows them, with the values of
	 * a rejected save put back so a person does not retype. A saved secret is
	 * never in it; use `<field>_is_set`.
	 *
	 * @return array<string, mixed>
	 */
	protected function values(): array {
		$values = $this->plugin->channel_settings()->for_screen( $this->channel->id(), $this->channel->fields() );
		$stash  = $this->stash();

		return array_merge( $values, (array) ( $stash['values'] ?? array() ) );
	}

	/**
	 * Messages for fields a rejected save refused, by field name.
	 *
	 * @return array<string, string>
	 */
	protected function errors(): array {
		return (array) ( $this->stash()['errors'] ?? array() );
	}

	/**
	 * Form rows for some of the channel's fields, in the order given. Each entry
	 * is a field name, or a row array as documented on Admin\Ui\FieldRows.
	 *
	 * @param array<int, string|array<string, mixed>> $rows Rows.
	 * @return string Escaped HTML.
	 */
	protected function rows( array $rows ): string {
		$builder = new FieldRows( $this->scope(), $this->fields_with_switch(), $this->values(), $this->errors() );
		$html    = '';

		foreach ( $rows as $row ) {
			$html .= $builder->row( is_array( $row ) ? $row : array( 'field' => $row ) );
		}

		return $html;
	}

	/**
	 * The channel's fields with the on/off switch in front, so `rows( array( 'enabled', ... ) )` works.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function fields_with_switch(): array {
		return array_merge(
			array(
				'enabled' => array(
					'type'    => 'bool',
					'label'   => __( 'Turn this channel on', 'estasoft-news-distribution' ),
					'default' => false,
					'control' => 'checkbox',
				),
			),
			$this->channel->fields()
		);
	}

	/**
	 * The scope that makes control ids unique, such as "channel-bing".
	 *
	 * @return string
	 */
	protected function scope(): string {
		return 'channel-' . $this->channel->id();
	}

	/**
	 * Wraps cards in the channel's form: the nonce, the hidden fields, the
	 * message after a save, and the save bar. It posts to admin-post.php, and the
	 * script saves through `PUT /channels/{id}` instead when it can.
	 *
	 * @param string $inner Escaped HTML of the cards.
	 * @param bool   $bar   Whether to print the save bar.
	 * @param string $tab   The open tab, so a plain post returns to it.
	 * @return string
	 */
	protected function form( string $inner, bool $bar = true, string $tab = '' ): string {
		$id      = $this->channel->id();
		$message = '';

		if ( Query::is( 'saved', '1' ) ) {
			$message = Message::text( 'success', __( 'Settings saved.', 'estasoft-news-distribution' ) );
		} elseif ( Query::is( 'error', '1' ) && array() !== $this->errors() ) {
			$message = Message::text( 'danger', __( 'Nothing was saved. Fix the highlighted settings and save again.', 'estasoft-news-distribution' ) );
		}

		return Html::tag(
			'form',
			array(
				'method'             => 'post',
				'action'             => admin_url( 'admin-post.php' ),
				'class'              => 'estasoft-nd-stack estasoft-nd-gap-16',
				'novalidate'         => true,
				'data-settings-form' => true,
				'data-section'       => $this->scope(),
				'data-endpoint'      => 'channels/' . rawurlencode( $id ),
				'data-dirty-guard'   => true,
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => 'estasoft_nd_save_channel',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'channel',
					'value' => $id,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'tab',
					'value' => $tab,
				)
			)
			. wp_nonce_field( Admin::NONCE_CHANNEL, '_wpnonce', true, false )
			. $message
			. $inner
			. ( $bar ? SaveBar::render() : '' )
		);
	}

	/**
	 * What the last rejected save left for this channel.
	 *
	 * @return array<string, mixed>
	 */
	private function stash(): array {
		if ( null === $this->stash ) {
			$this->stash = array();
			$found       = get_transient( Admin::FORM_KEY . get_current_user_id() );

			if ( is_array( $found ) && Query::has( 'error' ) && ( $found['section'] ?? '' ) === $this->scope() ) {
				$this->stash = $found;
				delete_transient( Admin::FORM_KEY . get_current_user_id() );
			}
		}

		return $this->stash;
	}
}
