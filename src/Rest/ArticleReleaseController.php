<?php
/**
 * REST: the release plan and the hold of one article.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Control\Plan;
use Estasoft\NewsDistribution\Core\Hold;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET /articles/{id}/release`: the plan, the hold and what each channel does now.
 * - `PUT /articles/{id}/release`: saves the plan. Body: `mode` (default, now, exclusive or
 *   custom), and for an exclusive `exclusive` (a channel id), `duration` (seconds, -1 for the
 *   site's usual delay, 0 until released) and `delay_search`; for a custom plan `times`
 *   (channel id or "*" to seconds).
 * - `POST /articles/{id}/release/end`: ends the exclusive now.
 * - `POST /articles/{id}/hold`: holds the article. `reason` is required.
 * - `DELETE /articles/{id}/hold`: releases the hold.
 *
 * Reading is for anyone who can open the article's check. Changing is for people who can see
 * every article. The work is in `Core\Hold`.
 */
final class ArticleReleaseController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$one = '/articles/(?P<id>\d+)';

		$this->route(
			$one . '/release',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires_article( Access::VIEW_OWN ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $this->requires_article( Access::VIEW ),
					'args'                => array(
						'mode'         => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => Plan::modes(),
						),
						'exclusive'    => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'duration'     => array(
							'type'    => 'integer',
							'default' => Plan::USUAL,
						),
						'delay_search' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'times'        => array(
							'type'                 => 'object',
							'default'              => array(),
							'additionalProperties' => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		$this->route(
			$one . '/release/end',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'end' ),
					'permission_callback' => $this->requires_article( Access::VIEW ),
				),
			)
		);

		$this->route(
			$one . '/hold',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'hold' ),
					'permission_callback' => $this->requires_article( Access::VIEW ),
					'args'                => array(
						'reason' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'lift' ),
					'permission_callback' => $this->requires_article( Access::VIEW ),
				),
			)
		);
	}

	/**
	 * GET /articles/{id}/release.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$id = (int) $request['id'];

		if ( ! $this->exists( $id ) ) {
			return $this->missing();
		}

		return rest_ensure_response( $this->present( $id, $this->holds()->state( $id ) ) );
	}

	/**
	 * PUT /articles/{id}/release.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$input = array(
			'mode'         => (string) $request['mode'],
			'exclusive'    => (string) $request['exclusive'],
			'duration'     => (int) $request['duration'],
			'delay_search' => (bool) $request['delay_search'],
			'times'        => (array) $request['times'],
		);
		$state = $this->holds()->save_plan( $id, $input );

		return is_wp_error( $state ) ? $state : rest_ensure_response( $this->present( $id, $state ) );
	}

	/**
	 * POST /articles/{id}/release/end.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function end( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$state = $this->holds()->end_exclusive( $id );

		return is_wp_error( $state ) ? $state : rest_ensure_response( $this->present( $id, $state ) );
	}

	/**
	 * POST /articles/{id}/hold.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function hold( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$state = $this->holds()->hold( $id, (string) $request['reason'] );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$response = rest_ensure_response( $this->present( $id, $state ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * DELETE /articles/{id}/hold.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function lift( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$state = $this->holds()->lift( $id );

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$out           = $this->present( $id, $state );
		$out['queued'] = array_values( (array) ( $state['queued'] ?? array() ) );

		return rest_ensure_response( $out );
	}

	// ---------------------------------------------------------------------
	// Internals.
	// ---------------------------------------------------------------------

	/**
	 * The state as the route answers: times as ISO 8601, and the channels.
	 *
	 * @param int                  $id    Article ID.
	 * @param array<string, mixed> $state What `Hold::state()` returns.
	 * @return array<string, mixed>
	 */
	private function present( int $id, array $state ): array {
		$holds = $this->holds();
		$plan  = $state['plan'];
		$hold  = $state['held'];
		$usual = (int) $state['usual'];

		$channels = array();

		foreach ( $holds->channels_state( $id ) as $row ) {
			$row['until'] = null === $row['until'] ? null : Clock::iso( (int) $row['until'] );
			$channels[]   = $row;
		}

		$plan['ended'] = $plan['ended'] > 0 ? Clock::iso( (int) $plan['ended'] ) : null;
		$plan['saved'] = $plan['saved'] > 0 ? Clock::iso( (int) $plan['saved'] ) : null;

		return array(
			'post_id'     => $id,
			'held'        => null === $hold ? null : array(
				'reason' => $hold['reason'],
				'by'     => $this->person( (int) $hold['by'] ),
				'at'     => $hold['at'] > 0 ? Clock::iso( (int) $hold['at'] ) : null,
			),
			'plan'        => $plan,
			'breaking'    => (bool) $state['breaking'],
			'published'   => Clock::iso( (int) $state['base'] ),
			'usual_delay' => $usual,
			'exclusive'   => array(
				'active' => (bool) $state['exclusive']['active'],
				'ends'   => null === $state['exclusive']['ends'] ? null : Clock::iso( (int) $state['exclusive']['ends'] ),
				'open'   => (bool) $state['exclusive']['open'],
				'over'   => (bool) $state['exclusive']['over'],
			),
			'channels'    => $channels,
			'choices'     => array(
				'exclusive' => $holds->exclusive_choices(),
				'in_review' => $holds->exclusive_blocked(),
				'durations' => Plan::exclusive_choices( $usual ),
				'custom'    => Plan::custom_choices(),
			),
		);
	}

	/**
	 * A person as the routes show them.
	 *
	 * @param int $user_id User ID.
	 * @return array{id: int, name: string}|null
	 */
	private function person( int $user_id ): ?array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User ? array(
			'id'   => $user_id,
			'name' => (string) $user->display_name,
		) : null;
	}

	/**
	 * Whether the article exists and is of a post type the site sends.
	 *
	 * @param int $id Article ID.
	 * @return bool
	 */
	private function exists( int $id ): bool {
		$post = get_post( $id );

		return $post instanceof \WP_Post && in_array( $post->post_type, $this->plugin->settings()->post_types(), true );
	}

	/**
	 * The 404.
	 *
	 * @return \WP_Error
	 */
	private function missing(): \WP_Error {
		return $this->error( 'estasoft_nd_unknown_article', __( 'That article does not exist.', 'estasoft-news-distribution' ), 404 );
	}

	/**
	 * The hold and release plan service.
	 *
	 * @return Hold
	 */
	private function holds(): Hold {
		return $this->plugin->make( Hold::class );
	}
}
