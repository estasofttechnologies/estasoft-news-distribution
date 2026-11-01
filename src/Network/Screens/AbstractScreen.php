<?php
/**
 * Base for the three Network Admin tabs.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Network\Screens;

use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Network\NetworkAdmin;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A tab says who it is, prints its body and may handle form posts. Form posts go to
 * `network/edit.php?action=<name>`, which WordPress passes to `network_admin_edit_<name>`;
 * every handler checks the permission and a nonce before it reads anything.
 */
abstract class AbstractScreen {

	protected Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The `tab` query argument.
	 *
	 * @return string
	 */
	abstract public function tab(): string;

	/**
	 * The tab's name.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * The body: the heading and everything under it. Escaped HTML.
	 *
	 * @return string
	 */
	abstract public function render(): string;

	/**
	 * Enqueues the tab's own style and script.
	 *
	 * @return void
	 */
	public function enqueue(): void {
	}

	/**
	 * The form posts the tab handles.
	 *
	 * @return array<string, string> Action name => method name.
	 */
	public function actions(): array {
		return array();
	}

	/**
	 * Address of a tab of this screen's page.
	 *
	 * @param array<string, scalar> $args Extra query arguments.
	 * @param string                $tab  Tab, or this tab when empty.
	 * @return string
	 */
	public function url( array $args = array(), string $tab = '' ): string {
		return NetworkAdmin::url( '' === $tab ? $this->tab() : $tab, $args );
	}

	/**
	 * Address a form posts to.
	 *
	 * @param string $action Action name.
	 * @return string
	 */
	public function post_url( string $action ): string {
		return network_admin_url( 'edit.php?action=' . rawurlencode( $action ) );
	}

	/**
	 * The hidden nonce field of a form. It has no id, so a page with several forms stays valid.
	 *
	 * @param string $action Nonce action.
	 * @return string
	 */
	protected function nonce( string $action ): string {
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => '_wpnonce',
				'value' => wp_create_nonce( $action ),
			)
		);
	}

	/**
	 * Goes back to a tab and stops.
	 *
	 * @param array<string, scalar> $args Extra query arguments.
	 * @return void
	 */
	protected function back( array $args = array() ): void {
		wp_safe_redirect( $this->url( $args ) );
		exit;
	}

	/**
	 * Keeps a message for the next time this person opens a tab.
	 *
	 * @param string   $tone  info, success, warning or danger.
	 * @param string   $text  Plain text.
	 * @param string[] $lines More lines, plain text.
	 * @return void
	 */
	protected function flash( string $tone, string $text, array $lines = array() ): void {
		set_site_transient(
			NetworkAdmin::FLASH . get_current_user_id(),
			array(
				'tone'  => $tone,
				'text'  => $text,
				'lines' => array_values( array_map( 'strval', $lines ) ),
			),
			2 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * The kept message, printed once.
	 *
	 * @return string Escaped HTML, or an empty string.
	 */
	protected function take_flash(): string {
		$key  = NetworkAdmin::FLASH . get_current_user_id();
		$kept = get_site_transient( $key );

		if ( ! is_array( $kept ) || ! isset( $kept['text'] ) ) {
			return '';
		}

		delete_site_transient( $key );

		$html = esc_html( (string) $kept['text'] );

		foreach ( (array) ( $kept['lines'] ?? array() ) as $line ) {
			$html .= '<br>' . esc_html( (string) $line );
		}

		return Message::bar( (string) ( $kept['tone'] ?? 'info' ), $html );
	}
}
