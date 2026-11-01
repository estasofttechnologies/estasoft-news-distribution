<?php
/**
 * REST: fixes handed to people.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET /articles/{id}/fixes`: the fixes of the article, and who can be given one. A person who
 *   may only see their own articles gets only the fixes assigned to them.
 * - `POST /articles/{id}/fixes`: hands a problem to a person. Body: `code` (the finding),
 *   `assignee` (user ID), `note`, `email` (default true) and `due` (a date such as 2026-10-23).
 *   Answers 201 with the fix and `notified`, what happened to the email.
 * - `PATCH /fixes/{id}`: `status` (open, done or dismissed), `assignee` (give it to someone
 *   else) or `remind` (true: send the same message again). The person a fix is assigned to may
 *   mark it done; the rest is for people who can see every article.
 *
 * Assigning keeps track and sends a note. It does not change the article.
 */
final class FixesController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/articles/(?P<id>\d+)/fixes',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires_article( Access::VIEW_OWN ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $this->requires_article( Access::VIEW ),
					'args'                => array(
						'code'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'assignee' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'note'     => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'email'    => array(
							'type'    => 'boolean',
							'default' => true,
						),
						'due'      => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				),
			)
		);

		$this->route(
			'/fixes/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $this->requires( Access::VIEW_OWN ),
					'args'                => array(
						'status'   => array(
							'type' => 'string',
							'enum' => array( Fixes::OPEN, Fixes::DONE, Fixes::DISMISSED ),
						),
						'assignee' => array( 'type' => 'integer' ),
						'remind'   => array( 'type' => 'boolean' ),
						'email'    => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /articles/{id}/fixes.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $this->error( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$can = $this->plugin->access()->can( Access::VIEW );

		return rest_ensure_response(
			array(
				'post_id'    => $id,
				'items'      => $this->fixes()->for_article( $id ),
				'can_assign' => $can,
				'assignable' => $can ? $this->fixes()->assignable( $id ) : array(),
			)
		);
	}

	/**
	 * POST /articles/{id}/fixes.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( \WP_REST_Request $request ) {
		$fix = $this->fixes()->assign(
			(int) $request['id'],
			array(
				'code'     => (string) $request['code'],
				'assignee' => (int) $request['assignee'],
				'note'     => (string) $request['note'],
				'email'    => (bool) $request['email'],
				'due'      => (string) $request['due'],
			)
		);

		if ( is_wp_error( $fix ) ) {
			return $fix;
		}

		$response = rest_ensure_response( $fix );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * PATCH /fixes/{id}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$fixes   = $this->fixes();
		$current = $fixes->find( $id );

		if ( null === $current || ! $fixes->can_see( $current ) ) {
			return $this->error( 'estasoft_nd_unknown_fix', __( 'That fix does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		$editor = $this->plugin->access()->can( Access::VIEW );
		$status = $request->get_param( 'status' );
		$result = $current;

		if ( null === $status && null === $request->get_param( 'assignee' ) && ! (bool) $request->get_param( 'remind' ) ) {
			return $this->error( 'estasoft_nd_nothing_to_change', __( 'Say what to change: the status, the person, or send a reminder.', 'estasoft-news-distribution' ), 422 );
		}

		if ( ! $editor && ( null !== $request->get_param( 'assignee' ) || (bool) $request->get_param( 'remind' ) || Fixes::DONE !== $status ) ) {
			return $this->error( 'estasoft_nd_forbidden', __( 'You can mark a fix assigned to you as done. Changing who it is assigned to is for editors.', 'estasoft-news-distribution' ), rest_authorization_required_code() );
		}

		if ( null !== $request->get_param( 'assignee' ) ) {
			$result = $fixes->reassign( $id, (int) $request['assignee'], null, (bool) $request['email'] );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( (bool) $request->get_param( 'remind' ) ) {
			$result = $fixes->remind( $id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( null !== $status ) {
			$notified = $result['notified'] ?? null;
			$result   = $fixes->set_status( $id, (string) $status );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( null !== $notified ) {
				$result['notified'] = $notified;
			}
		}

		return rest_ensure_response( $result );
	}

	/**
	 * The fixes service.
	 *
	 * @return Fixes
	 */
	private function fixes(): Fixes {
		return $this->plugin->make( Fixes::class );
	}
}
