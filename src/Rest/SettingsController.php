<?php
/**
 * REST: /settings/{section}.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Read or save one Settings section. A value outside its range is not saved;
 * the response names the field and the allowed range.
 */
final class SettingsController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/settings/(?P<section>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_use_section' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'can_use_section' ),
				),
			)
		);
	}

	/**
	 * Whether the person holds the section's permission. An unknown section is
	 * answered after this check, so the response never confirms a section exists
	 * to someone who may not use settings at all.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_use_section( \WP_REST_Request $request ): bool {
		$section = $this->plugin->settings()->section_object( (string) $request['section'] );

		return $this->plugin->access()->can( null === $section ? Access::MANAGE : $section->permission() );
	}

	/**
	 * GET /settings/{section}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$name    = (string) $request['section'];
		$section = $this->plugin->settings()->section_object( $name );

		if ( null === $section || ! $section->available() ) {
			return $this->error( 'estasoft_nd_unknown_section', __( 'That settings section does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return rest_ensure_response(
			array(
				'section' => $name,
				'values'  => $this->plugin->settings()->section_for_screen( $name ),
			)
		);
	}

	/**
	 * PUT /settings/{section}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$name    = (string) $request['section'];
		$section = $this->plugin->settings()->section_object( $name );

		if ( null === $section || ! $section->available() ) {
			return $this->error( 'estasoft_nd_unknown_section', __( 'That settings section does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : $request->get_body_params();

		$saved = $this->plugin->settings()->save_section( $name, is_array( $input ) ? $input : array() );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response(
			array(
				'section' => $name,
				'values'  => $this->plugin->settings()->section_for_screen( $name ),
			)
		);
	}
}
