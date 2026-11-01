<?php
/**
 * REST: /network/sites, the sites of a multisite network and their templates.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Network\SitesList;
use Estasoft\NewsDistribution\Network\SiteSummaries;
use Estasoft\NewsDistribution\Network\Templates;

defined( 'ABSPATH' ) || exit;

/**
 * Registered only on a multisite network; on a single site the routes do not exist. Permission
 * `network` (`manage_network_options`, so Super Admins only).
 *
 * - `GET /network/sites`: every site with its health, and the saved templates. `search` finds text in
 *   a site's name or address; `filter` is `all`, `attention` or `not_set_up`; `refresh=1` reads the
 *   sites again instead of using the copy kept for five minutes. A pass reads at most 200 sites.
 * - `PUT /network/sites`: one step of working with templates, chosen by `action`:
 *   - `review`: `template`, `sites` (IDs). Compares and writes nothing.
 *   - `apply`: `template`, `accept` (change keys from a review). Writes only those.
 *   - `undo`: `id` of an application.
 *   - `save_template`: `site`, `name`. Saves that site's settings as a template.
 *   - `delete_template`: `template`.
 *   A template never carries or sets a secret, a connected account, a verification tag or an IndexNow key.
 */
final class NetworkSitesController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! is_multisite() ) {
			return;
		}

		$this->route(
			'/network/sites',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::NETWORK ),
					'args'                => array(
						'search'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'filter'  => array(
							'type'    => 'string',
							'default' => 'all',
							'enum'    => array( 'all', 'attention', 'not_set_up' ),
						),
						'refresh' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->requires( Access::NETWORK ),
				),
			)
		);
	}

	/**
	 * GET /network/sites.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$data     = SitesList::build( (string) $request['search'], (string) $request['filter'], (bool) $request['refresh'] );
		$response = new \WP_REST_Response( $data );

		$response->header( 'X-WP-Total', (string) count( $data['sites'] ) );
		$response->header( 'X-WP-TotalPages', '1' );

		return $response;
	}

	/**
	 * PUT /network/sites.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$input     = $request->get_json_params();
		$input     = is_array( $input ) ? $input : $request->get_body_params();
		$action    = isset( $input['action'] ) && is_string( $input['action'] ) ? $input['action'] : '';
		$templates = new Templates();

		switch ( $action ) {
			case 'review':
				$result = $templates->review( $this->text( $input, 'template' ), $this->numbers( $input, 'sites' ) );
				break;

			case 'apply':
				$result = $templates->apply( $this->text( $input, 'template' ), $this->strings( $input, 'accept' ) );
				break;

			case 'undo':
				$result = $templates->undo( $this->text( $input, 'id' ) );
				break;

			case 'save_template':
				$result = $templates->save_from_site( (int) ( $input['site'] ?? 0 ), $this->text( $input, 'name' ) );
				break;

			case 'delete_template':
				$result = $templates->delete( $this->text( $input, 'template' ) );
				$result = true === $result ? array( 'deleted' => true ) : $result;
				break;

			default:
				return $this->error( 'estasoft_nd_invalid_value', __( 'Choose what to do: review, apply, undo, save_template or delete_template.', 'estasoft-news-distribution' ), 400, array( 'fields' => array( 'action' => __( 'Choose what to do.', 'estasoft-news-distribution' ) ) ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( 'save_template' === $action ) {
			( new SiteSummaries() )->forget();
		}

		return rest_ensure_response( $result );
	}

	/**
	 * A text value from the body.
	 *
	 * @param array<string, mixed> $input Body.
	 * @param string               $key   Key.
	 * @return string
	 */
	private function text( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';
	}

	/**
	 * A list of whole numbers from the body.
	 *
	 * @param array<string, mixed> $input Body.
	 * @param string               $key   Key.
	 * @return int[]
	 */
	private function numbers( array $input, string $key ): array {
		return isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? array_map( 'intval', $input[ $key ] ) : array();
	}

	/**
	 * A list of text values from the body.
	 *
	 * @param array<string, mixed> $input Body.
	 * @param string               $key   Key.
	 * @return string[]
	 */
	private function strings( array $input, string $key ): array {
		return isset( $input[ $key ] ) && is_array( $input[ $key ] ) ? array_map( 'strval', array_filter( $input[ $key ], 'is_scalar' ) ) : array();
	}
}
