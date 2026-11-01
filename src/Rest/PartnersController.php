<?php
/**
 * REST: /partners, the partners that read an API-key feed.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Frontend\PartnerFeedStore;

defined( 'ABSPATH' ) || exit;

/**
 * Permission `admin` on every route: a key is a secret, and nothing that holds one can be
 * given to Editors.
 *
 * - `GET /partners` and `GET /partners/{id}`: a partner has a name, an address, its languages,
 *   categories and rate limit, and for the key only its state, its last four characters and when
 *   it was made. A hash or a key is never in an answer.
 * - `POST /partners`: adds a partner. The answer is 201 and carries `api_key`, the only time the key is
 *   ever shown. Name, languages and categories are checked; a rate limit outside 10 to 600 is
 *   rejected, not changed.
 * - `PATCH /partners/{id}`: name, `enabled`, languages, categories and rate limit. The address and the key stay.
 * - `POST /partners/{id}/key`: makes a new key (the old one stops at once) and shows it once. This is also
 *   how a revoked partner gets a key again.
 * - `DELETE /partners/{id}/key`: revokes the key. The feed answers 401 until a new one is made.
 * - `DELETE /partners/{id}`: removes the partner and its address.
 *
 * Replacing, revoking and deleting a key write an audit row.
 */
final class PartnersController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/partners',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
					'args'                => $this->paging_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
			)
		);

		$one = '/partners/(?P<id>p[a-f0-9]{8})';

		$this->route(
			$one,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
			)
		);

		$this->route(
			$one . '/key',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rotate' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
			)
		);
	}

	/**
	 * The partner store.
	 *
	 * @return PartnerFeedStore
	 */
	private function store(): PartnerFeedStore {
		$store = $this->plugin->make( PartnerFeedStore::class );
		$store->flush();

		return $store;
	}

	/**
	 * GET /partners.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$all      = $this->store()->views();
		$per_page = max( 1, min( 100, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );

		return $this->paged( array_slice( $all, ( $page - 1 ) * $per_page, $per_page ), count( $all ), $per_page );
	}

	/**
	 * GET /partners/{id}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$partner = $this->store()->get( (string) $request['id'] );

		if ( null === $partner ) {
			return $this->not_found();
		}

		return rest_ensure_response( $this->store()->view( $partner ) );
	}

	/**
	 * POST /partners.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$made = $this->store()->create( $this->input( $request ) );

		if ( is_wp_error( $made ) ) {
			return $made;
		}

		return $this->with_key( $made, 201 );
	}

	/**
	 * PATCH /partners/{id}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$updated = $this->store()->update( (string) $request['id'], $this->input( $request ) );

		return is_wp_error( $updated ) ? $updated : rest_ensure_response( $updated );
	}

	/**
	 * DELETE /partners/{id}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( \WP_REST_Request $request ) {
		$deleted = $this->store()->delete( (string) $request['id'] );

		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /partners/{id}/key: a new key, shown once.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rotate( \WP_REST_Request $request ) {
		$made = $this->store()->rotate( (string) $request['id'] );

		return is_wp_error( $made ) ? $made : $this->with_key( $made, 200 );
	}

	/**
	 * DELETE /partners/{id}/key: the key stops working.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke( \WP_REST_Request $request ) {
		$revoked = $this->store()->revoke( (string) $request['id'] );

		return is_wp_error( $revoked ) ? $revoked : rest_ensure_response( $revoked );
	}

	/**
	 * The values a request sent: JSON, or form fields.
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
	 * An answer that carries a new key. It must not be stored by anything on the way.
	 *
	 * @param array{partner: array<string, mixed>, key: string} $made   The partner and its new key.
	 * @param int                                               $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private function with_key( array $made, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array_merge(
				$made['partner'],
				array(
					'api_key'        => $made['key'],
					'api_key_notice' => __( 'Shown once. Copy it now; News Distribution cannot show it again.', 'estasoft-news-distribution' ),
				)
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A "no such partner" error.
	 *
	 * @return \WP_Error
	 */
	private function not_found(): \WP_Error {
		return $this->error( 'estasoft_nd_partner_not_found', __( 'That partner does not exist.', 'estasoft-news-distribution' ), 404 );
	}
}
