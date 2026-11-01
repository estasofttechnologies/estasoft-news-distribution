<?php
/**
 * The buttons of the operations screens, and their styles and script.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Operations\Forms;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\CachePurge;
use Estasoft\NewsDistribution\Core\Connections;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Operations\SettingsBackup;
use Estasoft\NewsDistribution\Core\Operations\UpdatePoints;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Rest\SettingsTransferController;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Connections, Cache purge, Alerts, Privacy and Backup and uninstall.
 *
 * Every button on these screens is a form post to `admin-post.php`, so it works
 * without JavaScript. The handlers here check the nonce and the person's
 * permission, do the work through the services in `Core\` (the same ones the
 * REST routes use), remember a one-line answer for the next page (`Flash`) and
 * send the person back. The script only makes the two test buttons run without
 * leaving the page, and copies the policy text.
 */
final class OperationsModule implements ModuleInterface {

	/**
	 * The settings sections that load the stylesheet and script of the operations screens.
	 */
	private const SECTIONS = array( 'connections', 'caching', 'alerts', 'privacy', 'data' );

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		// `/settings/{section}` would otherwise answer `/settings/export`, `/settings/import` and `/settings/restore-points`.
		add_filter( 'rest_endpoints', array( SettingsTransferController::class, 'put_first' ) );

		// Just before WordPress replaces this plugin's files (priority 5: before WordPress deactivates the plugin), save a restore point.
		add_filter( 'upgrader_pre_install', array( UpdatePoints::class, 'before_install' ), 5, 2 );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'admin_post_estasoft_nd_connection', array( $this, 'connection' ) );
		add_action( 'admin_post_estasoft_nd_test_email', array( $this, 'test_email' ) );
		add_action( 'admin_post_estasoft_nd_test_slack', array( $this, 'test_slack' ) );
		add_action( 'admin_post_estasoft_nd_test_teams', array( $this, 'test_teams' ) );
		add_action( 'admin_post_estasoft_nd_test_purge', array( $this, 'test_purge' ) );
		add_action( 'admin_post_estasoft_nd_data_export', array( $this, 'export' ) );
		add_action( 'admin_post_estasoft_nd_data_import_preview', array( $this, 'import_preview' ) );
		add_action( 'admin_post_estasoft_nd_data_import_apply', array( $this, 'import_apply' ) );
		add_action( 'admin_post_estasoft_nd_data_restore', array( $this, 'restore' ) );
		add_action( 'admin_post_estasoft_nd_data_reset', array( $this, 'reset' ) );
	}

	/**
	 * Loads the files of the operations screens, on those screens only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' !== $page || ! in_array( $section, self::SECTIONS, true ) ) {
			return;
		}

		Assets::screen_style( 'operations' );

		if ( 'alerts' === $section ) {
			Assets::screen_style( 'alerts-chat' );
			Assets::screen_script(
				'alerts-chat',
				array(
					'text' => array(
						'working' => __( 'Sending…', 'estasoft-news-distribution' ),
						'offline' => __( 'Could not reach your site. Check your connection and try again.', 'estasoft-news-distribution' ),
					),
				)
			);
		}

		Assets::screen_script(
			'operations',
			array(
				'text' => array(
					'working'  => __( 'Working…', 'estasoft-news-distribution' ),
					'copied'   => __( 'Copied to the clipboard', 'estasoft-news-distribution' ),
					'failed'   => __( 'Could not copy. Select the text and copy it yourself.', 'estasoft-news-distribution' ),
					'offline'  => __( 'Could not reach your site. Check your connection and try again.', 'estasoft-news-distribution' ),
					/* translators: %s: the name of a settings section, such as "Page markup". */
					'reset'    => __( 'Reset %s', 'estasoft-news-distribution' ),
					'checking' => __( 'Checking…', 'estasoft-news-distribution' ),
				),
			)
		);
	}

	/**
	 * Sends the person back to a settings section, at a card.
	 *
	 * @param string               $section Section name.
	 * @param string               $card    Card id inside the section, or an empty string.
	 * @param array<string, mixed> $args    More query arguments.
	 * @return void
	 */
	private function back( string $section, string $card = '', array $args = array() ): void {
		$url = Links::settings( $section, $args ) . ( '' === $card ? '' : '#estasoft-nd-card-' . sanitize_key( $section . '-' . $card ) );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * One posted value as text, or an empty string. The nonce is checked first, here, so the form is never read without it.
	 *
	 * @param string $key   The field name.
	 * @param string $nonce The nonce action of the form.
	 * @return string
	 */
	private function posted( string $key, string $nonce ): string {
		check_admin_referer( $nonce );

		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? trim( sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) ) : '';
	}

	/**
	 * Connect, Check now and Disconnect.
	 *
	 * @return void
	 */
	public function connection(): void {
		check_admin_referer( Forms::connection_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$engine_id = $this->posted( 'engine', Forms::connection_nonce() );

		$service = $this->plugin->make( Connections::class );
		$engine  = $service->get( $engine_id );

		if ( null === $engine ) {
			wp_die( esc_html__( 'That connection does not exist.', 'estasoft-news-distribution' ), '', array( 'response' => 404 ) );
		}

		$op = $this->posted( 'op', Forms::connection_nonce() );

		if ( 'connect' === $op ) {
			// A key or token is sent to the engine as typed, so only control characters (line breaks around a pasted file) are removed. It is never printed.
			$credential = isset( $_POST['credential'] ) && is_scalar( $_POST['credential'] ) ? trim( (string) filter_var( wp_unslash( $_POST['credential'] ), FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW ) ) : '';
			$input      = array( $engine->secret_field() => $credential );

			foreach ( $engine->plain_fields() as $field ) {
				$input[ $field ] = $this->posted( $field, Forms::connection_nonce() );
			}

			$done = $service->connect( $engine_id, $input );

			if ( is_wp_error( $done ) ) {
				Flash::set( 'danger', $done->get_error_message() );
			} elseif ( $done['ok'] ) {
				Flash::set(
					'success',
					sprintf(
						/* translators: 1: the service's name, 2: what the service said. */
						__( 'Connected to %1$s. %2$s', 'estasoft-news-distribution' ),
						$engine->label(),
						$done['message']
					)
				);
			} else {
				Flash::set(
					'danger',
					sprintf(
						/* translators: 1: the service's name, 2: why the service refused. */
						__( 'Not connected to %1$s. %2$s', 'estasoft-news-distribution' ),
						$engine->label(),
						$done['message']
					)
				);
			}
		} elseif ( 'check' === $op ) {
			$done = $service->check( $engine_id );

			if ( is_wp_error( $done ) ) {
				Flash::set( 'danger', $done->get_error_message() );
			} elseif ( $done['ok'] ) {
				Flash::set( 'success', sprintf( /* translators: 1: the service's name, 2: what the check found. */ __( 'Test passed with %1$s. %2$s', 'estasoft-news-distribution' ), $engine->label(), $done['message'] ) );
			} else {
				Flash::set( 'danger', sprintf( /* translators: 1: the service's name, 2: why the check failed. */ __( 'Test failed with %1$s. %2$s', 'estasoft-news-distribution' ), $engine->label(), $done['message'] ) );
			}
		} elseif ( 'disconnect' === $op ) {
			Flash::set( 'success', Connections::disconnect_text( $engine->label(), $service->disconnect_report( $engine_id ) ) );
		}

		$this->back( 'connections', $engine_id );
	}

	/**
	 * Send a test email. The nonce is the one of the Alerts settings form the button sits in.
	 *
	 * @return void
	 */
	public function test_email(): void {
		check_admin_referer( 'estasoft_nd_save_alerts' );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$result = $this->plugin->make( Alerts::class )->send_test();

		if ( 'nobody' === $result['status'] ) {
			Flash::set( 'warning', $result['error'] );
		}

		$this->back( 'alerts', 'email' );
	}

	/**
	 * Send a test message to Slack. The nonce is the one of the Alerts settings form the button sits in.
	 *
	 * @return void
	 */
	public function test_slack(): void {
		$this->test_chat( Alerts::SLACK );
	}

	/**
	 * Send a test message to Microsoft Teams. The nonce is the one of the Alerts settings form the button sits in.
	 *
	 * @return void
	 */
	public function test_teams(): void {
		$this->test_chat( Alerts::TEAMS );
	}

	/**
	 * Posts one test message to a chat service's saved address, then sends the person back to the Chat card. The
	 * answer is kept with the alert state, so the card shows it, including "no address" when nothing was sent.
	 *
	 * @param string $service `slack` or `teams`.
	 * @return void
	 */
	private function test_chat( string $service ): void {
		check_admin_referer( 'estasoft_nd_save_alerts' );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$this->plugin->make( Alerts::class )->send_test_chat( $service );

		$this->back( 'alerts', 'chat' );
	}

	/**
	 * Test purge now. The nonce is the one of the Cache purge settings form the button sits in.
	 *
	 * @return void
	 */
	public function test_purge(): void {
		check_admin_referer( 'estasoft_nd_save_caching' );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$this->plugin->make( CachePurge::class )->test();

		$this->back( 'caching', 'test' );
	}

	/**
	 * Streams the settings file.
	 *
	 * @return void
	 */
	public function export(): void {
		check_admin_referer( Forms::data_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$include = isset( $_POST['include'] ) && is_array( $_POST['include'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['include'] ) ) : array();
		$backup  = $this->plugin->make( SettingsBackup::class );
		$file    = $backup->export( array() === $include ? array( 'settings' ) : $include );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $backup->filename() . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo wp_json_encode( $file, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A downloaded JSON file, not a page.
		exit;
	}

	/**
	 * Step one of an import: reads the file and shows what it would change.
	 *
	 * @return void
	 */
	public function import_preview(): void {
		check_admin_referer( Forms::data_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$backup = $this->plugin->make( SettingsBackup::class );
		$name   = isset( $_FILES['settings_file']['tmp_name'] ) && is_string( $_FILES['settings_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['settings_file']['tmp_name'] ) ) : '';
		$error  = isset( $_FILES['settings_file']['error'] ) ? absint( $_FILES['settings_file']['error'] ) : UPLOAD_ERR_NO_FILE;
		$size   = isset( $_FILES['settings_file']['size'] ) ? absint( $_FILES['settings_file']['size'] ) : 0;

		if ( '' === $name || UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $name ) ) {
			Flash::set( 'danger', __( 'No file was received. Choose a settings file and try again.', 'estasoft-news-distribution' ) );
			$this->back( 'data', 'import' );
		}

		if ( $size > SettingsBackup::MAX_BYTES ) {
			Flash::set( 'danger', __( 'That file is too large for a settings file. A settings file is plain text of a few kilobytes.', 'estasoft-news-distribution' ) );
			$this->back( 'data', 'import' );
		}

		$json = (string) file_get_contents( $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A file the person just uploaded; it is read, never run or kept.
		$file = $backup->parse( $json );

		if ( is_wp_error( $file ) ) {
			$backup->unstash();
			Flash::set( 'danger', $file->get_error_message() );
			$this->back( 'data', 'import' );
		}

		$backup->stash( $json );

		$this->back( 'data', 'import', array( 'step' => 'preview' ) );
	}

	/**
	 * Step two of an import: Apply or Cancel.
	 *
	 * @return void
	 */
	public function import_apply(): void {
		check_admin_referer( Forms::data_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$backup = $this->plugin->make( SettingsBackup::class );
		$json   = $backup->stashed();

		if ( 'apply' !== $this->posted( 'op', Forms::data_nonce() ) ) {
			$backup->unstash();
			$this->back( 'data', 'import' );
		}

		$file = '' === $json ? null : $backup->parse( $json );

		if ( ! is_array( $file ) ) {
			Flash::set( 'danger', __( 'The file is no longer kept. Choose it again.', 'estasoft-news-distribution' ) );
			$this->back( 'data', 'import' );
		}

		$done = $backup->apply( $file );
		$backup->unstash();

		if ( is_wp_error( $done ) ) {
			Flash::set( 'danger', $done->get_error_message() );
			$this->back( 'data', 'import' );
		}

		Flash::set(
			'success',
			sprintf(
				/* translators: %d: number of settings. */
				_n( 'Imported %d setting. Your earlier settings were saved as a restore point first.', 'Imported %d settings. Your earlier settings were saved as a restore point first.', $done['applied'], 'estasoft-news-distribution' ),
				$done['applied']
			)
		);

		$this->back( 'data', 'restore' );
	}

	/**
	 * Restore.
	 *
	 * @return void
	 */
	public function restore(): void {
		check_admin_referer( Forms::data_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$done = $this->plugin->make( SettingsBackup::class )->restore( $this->posted( 'point', Forms::data_nonce() ) );

		if ( is_wp_error( $done ) ) {
			Flash::set( 'danger', $done->get_error_message() );
		} else {
			Flash::set( 'success', __( 'The settings were restored. The settings you had before were saved as a new restore point, so you can undo this.', 'estasoft-news-distribution' ) );
		}

		$this->back( 'data', 'restore' );
	}

	/**
	 * Reset a section.
	 *
	 * @return void
	 */
	public function reset(): void {
		check_admin_referer( Forms::data_nonce() );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$name   = sanitize_key( $this->posted( 'section', Forms::data_nonce() ) );
		$backup = $this->plugin->make( SettingsBackup::class );
		$done   = $backup->reset( $name );

		if ( is_wp_error( $done ) ) {
			Flash::set( 'danger', $done->get_error_message() );
		} else {
			$section = $this->plugin->settings()->section_object( $name );

			Flash::set(
				'success',
				sprintf(
					/* translators: %s: the name of a settings section, such as "Page markup". */
					__( '%s was put back to what a new install has. The settings you had before were saved as a restore point.', 'estasoft-news-distribution' ),
					null === $section ? $name : $section->title()
				)
			);
		}

		$this->back( 'data', 'reset' );
	}
}
