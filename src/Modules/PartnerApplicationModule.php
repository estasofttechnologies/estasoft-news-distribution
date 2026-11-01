<?php
/**
 * Records the application status a person sets on a partner channel's own page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Settings\SecretInput;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The Microsoft Start and Yahoo News pages show a place to record where the
 * person stands with the platform. The status lives in the application tracker
 * (Core\Applications), not in the channel's settings, so the Applications screen
 * shows the same value. The page saves with the channel's own Save bar, so this
 * module reads what that save posted and applies it to the tracker once the save
 * succeeded:
 *
 * - Microsoft Start: `application_status` and `applied_on` are written as given. The
 *   date is read the way the Applications screen reads it ("12 Sep 2026" or 2026-09-12).
 * - Yahoo News: ticking "I have sent my feed URLs to Yahoo" moves a status of Not
 *   requested to Requested and records today's date. Nothing else changes a
 *   status, and unticking changes nothing.
 *
 * Nothing is sent to either platform. Both statuses are set by hand.
 *
 * A status or a date that is not valid is refused the same way on both paths. The route answers 400 and names
 * the field. The form without a script (`admin-post.php`) saves nothing, puts the typed values and the message
 * for the field in the form stash that every channel form uses (`Admin::FORM_KEY`), and returns to the page,
 * which shows the message on the field.
 */
final class PartnerApplicationModule implements ModuleInterface {

	public const MICROSOFT = 'microsoft-start';
	public const YAHOO     = 'yahoo-news';

	private Plugin $plugin;

	/**
	 * What the current request posted for a channel, by channel id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $captured = array();

	/**
	 * Set while the module writes, so the write does not start another.
	 *
	 * @var bool
	 */
	private bool $busy = false;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'rest_request_before_callbacks', array( $this, 'capture_rest' ), 10, 3 );
		add_action( 'admin_post_estasoft_nd_save_channel', array( $this, 'capture_post' ), 1 );
		add_action( 'estasoft_nd_output_changed', array( $this, 'apply' ) );
	}

	/**
	 * Notes what a save of one of the two channels carries, and refuses a status or
	 * a date that is not valid, naming the field.
	 *
	 * @param mixed $response The response so far.
	 * @param mixed $handler  The matched route handler.
	 * @param mixed $request  The request.
	 * @return mixed
	 */
	public function capture_rest( $response, $handler, $request ) {
		unset( $handler );

		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request || ! in_array( $request->get_method(), array( 'PUT', 'POST', 'PATCH' ), true ) ) {
			return $response;
		}

		$route = $request->get_route();

		if ( 1 !== preg_match( '#^/estasoft-nd/v1/channels/(' . self::MICROSOFT . '|' . self::YAHOO . ')$#', $route, $found ) ) {
			return $response;
		}

		if ( ! $this->plugin->access()->can( Access::MANAGE ) ) {
			// The route's own permission check answers 401 or 403. Nothing is noted for a person who may not save.
			return $response;
		}

		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : $request->get_body_params();
		$input = is_array( $input ) ? $input : array();
		$error = $this->problem( $input );

		if ( null !== $error ) {
			return $error;
		}

		$this->captured[ $found[1] ] = $input;

		return $response;
	}

	/**
	 * Notes what the channel form posted. It runs just before the channel form handler, so it checks the same nonce
	 * first; the handler checks the permission before it saves, and these notes are applied only after a save went through.
	 *
	 * @return void
	 */
	public function capture_post(): void {
		check_admin_referer( Admin::NONCE_CHANNEL );

		if ( ! $this->plugin->access()->can( Access::MANAGE ) ) {
			return;
		}

		$id = isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '';

		if ( ! in_array( $id, array( self::MICROSOFT, self::YAHOO ), true ) || ! isset( $_POST['estasoft_nd'] ) || ! is_array( $_POST['estasoft_nd'] ) ) {
			return;
		}

		$channel = $this->plugin->registry()->get( $id );
		$input   = Field::posted( null === $channel ? array() : $channel->fields(), Admin::NONCE_CHANNEL );

		// The status, the date and the "sent" tick are the page's own, next to the channel's fields.
		// A list sent in their place is kept as a list, so problem() refuses it.
		foreach ( array( 'application_status', 'applied_on', 'urls_sent' ) as $key ) {
			if ( ! isset( $_POST['estasoft_nd'][ $key ] ) ) {
				continue;
			}

			$input[ $key ] = is_array( $_POST['estasoft_nd'][ $key ] )
				? map_deep( wp_unslash( $_POST['estasoft_nd'][ $key ] ), 'sanitize_text_field' )
				: sanitize_text_field( wp_unslash( $_POST['estasoft_nd'][ $key ] ) );
		}

		$error = $this->problem( $input );

		if ( null !== $error ) {
			$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';

			$this->return_to_form( $id, $input, $error, $tab );

			return;
		}

		$this->captured[ $id ] = $input;
	}

	/**
	 * Sends the person back to the channel page with the problem shown on its field, and saves nothing.
	 *
	 * The channel form handler never runs after this, but the nonce was checked before anything was read. A typed
	 * secret is never kept in the stash.
	 *
	 * @param string               $id    Channel id.
	 * @param array<string, mixed> $input What the form posted.
	 * @param \WP_Error            $error What `problem()` found.
	 * @param string               $tab   The tab to return to, or an empty string.
	 * @return void
	 */
	private function return_to_form( string $id, array $input, \WP_Error $error, string $tab ): void {
		$channel = $this->plugin->registry()->get( $id );
		$kept    = array_diff_key( $input, SecretInput::fields( null === $channel ? array() : $channel->fields() ) );
		$data    = $error->get_error_data();

		set_transient(
			Admin::FORM_KEY . get_current_user_id(),
			array(
				'section' => 'channel-' . $id,
				'values'  => $kept,
				'errors'  => is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array( $id => $error->get_error_message() ),
			),
			2 * MINUTE_IN_SECONDS
		);

		$target = array(
			'page'    => 'estasoft-nd-channels',
			'channel' => $id,
			'error'   => 1,
		);

		if ( '' !== $tab ) {
			$target['tab'] = $tab;
		}

		wp_safe_redirect( add_query_arg( $target, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Applies what was posted once the channel settings were saved.
	 *
	 * @param mixed $changed What changed, such as "channels".
	 * @return void
	 */
	public function apply( $changed ): void {
		if ( 'channels' !== $changed || $this->busy || array() === $this->captured ) {
			return;
		}

		$captured       = $this->captured;
		$this->captured = array();
		$this->busy     = true;

		foreach ( $captured as $id => $input ) {
			if ( self::MICROSOFT === $id ) {
				$this->microsoft( $input );
			} elseif ( self::YAHOO === $id ) {
				$this->yahoo( $input );
			}
		}

		$this->busy = false;
	}

	/**
	 * Writes the status and date a person chose for Microsoft Start.
	 *
	 * @param array<string, mixed> $input What the save posted.
	 * @return void
	 */
	private function microsoft( array $input ): void {
		$data = array();

		if ( isset( $input['application_status'] ) ) {
			$data['status'] = (string) $input['application_status'];
		}

		if ( isset( $input['applied_on'] ) ) {
			$day = Applications::parse_date( (string) $input['applied_on'] );

			if ( null !== $day ) {
				$data['applied_on'] = $day;
			}
		}

		if ( array() !== $data ) {
			$this->plugin->applications()->save( self::MICROSOFT, $data );
		}
	}

	/**
	 * Moves Yahoo News from Not requested to Requested when the person says the
	 * feed addresses were sent.
	 *
	 * @param array<string, mixed> $input What the save posted.
	 * @return void
	 */
	private function yahoo( array $input ): void {
		if ( ! isset( $input['urls_sent'] ) || ! filter_var( $input['urls_sent'], FILTER_VALIDATE_BOOLEAN ) ) {
			return;
		}

		$row = $this->plugin->applications()->get( self::YAHOO );

		if ( Status::NOT_REQUESTED !== $row['status'] ) {
			return;
		}

		$this->plugin->applications()->save(
			self::YAHOO,
			array(
				'status'     => Status::REQUESTED,
				'applied_on' => '' !== $row['applied_on'] ? $row['applied_on'] : Clock::format( Clock::now(), 'Y-m-d' ),
			)
		);
	}

	/**
	 * What is wrong with a posted status or date, in words a person can act on.
	 *
	 * @param array<string, mixed> $input What the save posted.
	 * @return \WP_Error|null Null when nothing is wrong.
	 */
	private function problem( array $input ): ?\WP_Error {
		if ( isset( $input['application_status'] ) && ( ! is_scalar( $input['application_status'] ) || ! in_array( (string) $input['application_status'], Applications::statuses(), true ) ) ) {
			return $this->refuse( 'application_status', __( 'Choose one of the listed statuses.', 'estasoft-news-distribution' ) );
		}

		if ( isset( $input['applied_on'] ) && ( ! is_scalar( $input['applied_on'] ) || null === Applications::parse_date( (string) $input['applied_on'] ) ) ) {
			return $this->refuse( 'applied_on', __( 'Applied on must be a date such as 12 Sep 2026.', 'estasoft-news-distribution' ) );
		}

		return null;
	}

	/**
	 * An error that names one field.
	 *
	 * @param string $field   Field name.
	 * @param string $message A sentence.
	 * @return \WP_Error
	 */
	private function refuse( string $field, string $message ): \WP_Error {
		return new \WP_Error(
			'estasoft_nd_invalid_value',
			$message,
			array(
				'status' => 400,
				'fields' => array( $field => $message ),
			)
		);
	}
}
