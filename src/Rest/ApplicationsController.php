<?php
/**
 * REST: /applications and /applications/{platform}.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;

defined( 'ABSPATH' ) || exit;

/**
 * The application tracker. The plugin cannot see any platform's answer, so every
 * status, date and note here is set by a person; nothing is sent anywhere.
 * A reminder date that has passed turns `display_status` to `attention` while
 * `status` stays what the person set.
 */
final class ApplicationsController extends Controller {

	/**
	 * The fields a person can set, in the order the screen shows them.
	 */
	private const FIELDS = array( 'status', 'applied_on', 'contact', 'reminder_on', 'note' );

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/applications',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);

		$this->route(
			'/applications/(?P<platform>[a-z0-9-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);
	}

	/**
	 * GET /applications: every platform in the tracker.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$items = array();

		foreach ( array_keys( Applications::platforms() ) as $platform ) {
			$items[] = $this->item( (string) $platform );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * POST /applications: tracks a platform that is not in the list. Its status starts at Not requested.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$input = $this->input( $request );
		$added = $this->plugin->applications()->add( (string) ( $input['name'] ?? '' ), (string) ( $input['link'] ?? '' ) );

		if ( is_wp_error( $added ) ) {
			return $added;
		}

		return new \WP_REST_Response( $this->item( $added['platform'] ), 201 );
	}

	/**
	 * PUT /applications/{platform}: sets the status, dates, link and note by hand.
	 * A field that is left out keeps its value. A bad value saves nothing and the
	 * answer names the field.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$platform = (string) $request['platform'];

		if ( ! isset( Applications::platforms()[ $platform ] ) ) {
			return $this->error( 'estasoft_nd_unknown_platform', __( 'That platform is not in the tracker.', 'estasoft-news-distribution' ), 404 );
		}

		$input = array_intersect_key( $this->input( $request ), array_flip( self::FIELDS ) );

		if ( array() === $input ) {
			return $this->error( 'estasoft_nd_nothing_to_save', __( 'Send at least one of: status, applied_on, contact, reminder_on, note.', 'estasoft-news-distribution' ), 400 );
		}

		foreach ( array( 'applied_on', 'reminder_on' ) as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			$day = Applications::parse_date( (string) $input[ $key ] );

			if ( null === $day ) {
				$message = 'applied_on' === $key
					? __( 'Applied on must be a day, month and year, for example 5 Oct 2026.', 'estasoft-news-distribution' )
					: __( 'Remind me on must be a day, month and year, for example 5 Oct 2026.', 'estasoft-news-distribution' );

				return $this->error( 'estasoft_nd_invalid_value', $message, 400, array( 'fields' => array( $key => $message ) ) );
			}

			$input[ $key ] = $day;
		}

		$saved = $this->plugin->applications()->save( $platform, $input );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( $this->item( $platform ) );
	}

	/**
	 * DELETE /applications/{platform}: takes a platform that was added by hand out of the tracker.
	 * The platforms that ship with the plugin stay.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove( \WP_REST_Request $request ) {
		$platform = (string) $request['platform'];

		if ( ! isset( Applications::platforms()[ $platform ] ) ) {
			return $this->error( 'estasoft_nd_unknown_platform', __( 'That platform is not in the tracker.', 'estasoft-news-distribution' ), 404 );
		}

		if ( ! $this->plugin->applications()->remove( $platform ) ) {
			return $this->error( 'estasoft_nd_platform_stays', __( 'Only a platform you added can be removed.', 'estasoft-news-distribution' ), 409 );
		}

		return rest_ensure_response( array( 'removed' => $platform ) );
	}

	/**
	 * The JSON or form body as an array.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request ): array {
		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : $request->get_body_params();

		return is_array( $input ) ? $input : array();
	}

	/**
	 * One platform as the API returns it.
	 *
	 * @param string $platform Platform id.
	 * @return array<string, mixed>
	 */
	private function item( string $platform ): array {
		$apps = $this->plugin->applications();
		$row  = $apps->get( $platform );

		return array(
			'platform'       => $platform,
			'name'           => Applications::name( $platform ),
			'custom'         => Applications::is_custom( $platform ),
			'status'         => $row['status'],
			'display_status' => $apps->display_status( $platform ),
			'overdue'        => $apps->is_overdue( $platform ),
			'applied_on'     => '' === $row['applied_on'] ? null : $row['applied_on'],
			'contact'        => $row['contact'],
			'reminder_on'    => '' === $row['reminder_on'] ? null : $row['reminder_on'],
			'note'           => $row['note'],
		);
	}
}
