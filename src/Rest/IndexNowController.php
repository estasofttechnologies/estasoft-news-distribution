<?php
/**
 * REST: /indexnow/*.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Channels\IndexNow\Catchup;
use Estasoft\NewsDistribution\Channels\IndexNow\IndexNowChannel;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The IndexNow page's own actions. The channel's settings, its test and its
 * output check are in the channels controller; this one holds what only IndexNow
 * has: the catch-up and the key.
 *
 * - `GET /indexnow/catchup`: the run and how many addresses each range would send.
 * - `POST /indexnow/catchup` with `days` (7, 30, 90 or 365): starts a catch-up
 *   through the queue and answers `202` with the job id. Poll `GET /jobs/{id}`.
 * - `POST /indexnow/catchup/pause`, `/resume`, `/cancel`: change the run.
 * - `POST /indexnow/key`: replaces the key. The old key file answers 404 at once.
 * - `POST /indexnow/key-check`: fetches the key file the way an engine does.
 *
 * Every route needs the manage permission.
 */
final class IndexNowController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/indexnow/catchup',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read_catchup' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_catchup' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
					'args'                => array(
						'days' => array(
							'type'              => 'integer',
							'default'           => 30,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		foreach ( array( 'pause', 'resume', 'cancel' ) as $action ) {
			$this->route(
				'/indexnow/catchup/' . $action,
				array(
					array(
						'methods'             => \WP_REST_Server::CREATABLE,
						'callback'            => array( $this, $action . '_catchup' ),
						'permission_callback' => $this->requires( Access::MANAGE ),
					),
				)
			);
		}

		$this->route(
			'/indexnow/key',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'new_key' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);

		$this->route(
			'/indexnow/key-check',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'check_key' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);
	}

	/**
	 * GET /indexnow/catchup.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read_catchup() {
		$catchup = $this->catchup();

		if ( is_wp_error( $catchup ) ) {
			return $catchup;
		}

		return rest_ensure_response( $catchup->summary( true ) );
	}

	/**
	 * POST /indexnow/catchup. Starts the run and answers 202 with the job id.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_catchup( \WP_REST_Request $request ) {
		$catchup = $this->catchup();

		if ( is_wp_error( $catchup ) ) {
			return $catchup;
		}

		$started = $catchup->start( (int) $request['days'] );

		if ( is_wp_error( $started ) ) {
			return $started;
		}

		$response = new \WP_REST_Response( $catchup->summary(), 202 );
		$response->header( 'Location', rest_url( self::REST_NAMESPACE . '/jobs/' . rawurlencode( (string) $started['job'] ) ) );

		return $response;
	}

	/**
	 * POST /indexnow/catchup/pause.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function pause_catchup() {
		return $this->change( 'pause' );
	}

	/**
	 * POST /indexnow/catchup/resume.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resume_catchup() {
		return $this->change( 'resume' );
	}

	/**
	 * POST /indexnow/catchup/cancel.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_catchup() {
		return $this->change( 'cancel' );
	}

	/**
	 * POST /indexnow/key. Replaces the key with a new random one.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function new_key() {
		$channel = $this->channel();

		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		$key = $channel->regenerate_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		return rest_ensure_response(
			array(
				'key' => $key,
				'url' => $channel->key_url(),
			)
		);
	}

	/**
	 * POST /indexnow/key-check. Reaches the site's own key file address, so it
	 * is only ever a person pressing a button.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_key() {
		$channel = $this->channel();

		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		$checked = $channel->check_key_file();

		return rest_ensure_response(
			array(
				'ok'      => $checked['ok'],
				'code'    => $checked['code'],
				'message' => $checked['message'],
				'time'    => Clock::iso( $checked['time'] ),
				'url'     => $channel->key_url(),
			)
		);
	}

	/**
	 * Pauses, resumes or cancels the run and answers with it.
	 *
	 * @param string $action pause, resume or cancel.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function change( string $action ) {
		$catchup = $this->catchup();

		if ( is_wp_error( $catchup ) ) {
			return $catchup;
		}

		$changed = $catchup->{$action}();

		if ( is_wp_error( $changed ) ) {
			return $changed;
		}

		return rest_ensure_response( $catchup->summary() );
	}

	/**
	 * The IndexNow channel, or a 404.
	 *
	 * @return IndexNowChannel|\WP_Error
	 */
	private function channel() {
		$channel = $this->plugin->registry()->get( 'indexnow' );

		if ( ! $channel instanceof IndexNowChannel ) {
			return $this->error( 'estasoft_nd_unknown_channel', __( 'That channel does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return $channel;
	}

	/**
	 * The catch-up, or a 404.
	 *
	 * @return Catchup|\WP_Error
	 */
	private function catchup() {
		$catchup = Catchup::of( $this->plugin );

		if ( null === $catchup ) {
			return $this->error( 'estasoft_nd_unknown_channel', __( 'That channel does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return $catchup;
	}
}
