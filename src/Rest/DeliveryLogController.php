<?php
/**
 * REST: /delivery-log and /delivery-log/{id}/retry.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\DeliveryEntries;
use Estasoft\NewsDistribution\Core\DeliveryRetry;
use Estasoft\NewsDistribution\Core\Retry;

defined( 'ABSPATH' ) || exit;

/**
 * The pushes News Distribution sent to WebSub, IndexNow and the outlets, and a
 * way to send a failed one again. Nothing here calls a platform: a retry only
 * puts the delivery back in the background queue.
 */
final class DeliveryLogController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/delivery-log',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::VIEW ),
					'args'                => $this->validated(
						array_merge(
							$this->paging_args(),
							array(
								'channel'      => array(
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_key',
								),
								'status'       => array(
									'type' => 'string',
									'enum' => array_merge( array( '' ), DeliveryEntries::statuses() ),
								),
								'language'     => array(
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_text_field',
								),
								'post'         => array(
									'type'              => 'integer',
									'minimum'           => 1,
									'sanitize_callback' => 'absint',
								),
								'search'       => array(
									'type'              => 'string',
									'sanitize_callback' => 'sanitize_text_field',
								),
								'days'         => array(
									'type'              => 'integer',
									'minimum'           => 1,
									'maximum'           => 90,
									'sanitize_callback' => 'absint',
								),
								'all_attempts' => array(
									'type'    => 'boolean',
									'default' => false,
								),
							)
						)
					),
				),
			)
		);

		$this->route(
			'/delivery-log/(?P<id>\d+)/retry',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'retry' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);
	}

	/**
	 * Gives every argument the standard check, so a value outside its type, range
	 * or list is a 400 that names the parameter.
	 *
	 * @param array<string, array<string, mixed>> $args Route arguments.
	 * @return array<string, array<string, mixed>>
	 */
	private function validated( array $args ): array {
		foreach ( $args as $name => $arg ) {
			$args[ $name ]['validate_callback'] = 'rest_validate_request_arg';
		}

		return $args;
	}

	/**
	 * GET /delivery-log.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$per_page = (int) $request['per_page'];
		$found    = $this->plugin->make( DeliveryEntries::class )->search(
			array(
				'channel'      => (string) $request['channel'],
				'status'       => (string) $request['status'],
				'language'     => (string) $request['language'],
				'post'         => (int) $request['post'],
				'search'       => (string) $request['search'],
				'days'         => (int) $request['days'],
				'all_attempts' => (bool) $request['all_attempts'],
				'page'         => (int) $request['page'],
				'per_page'     => $per_page,
			)
		);

		return $this->paged( array_map( array( $this, 'item' ), $found['rows'] ), $found['total'], $per_page );
	}

	/**
	 * POST /delivery-log/{id}/retry. Queues one failed delivery again and answers
	 * 202 with the entry that now shows it as waiting.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry( \WP_REST_Request $request ) {
		$queued = $this->plugin->make( DeliveryRetry::class )->retry( (int) $request['id'] );

		if ( is_wp_error( $queued ) ) {
			return $queued;
		}

		$response = new \WP_REST_Response(
			array(
				'id'      => $queued['id'],
				'status'  => $queued['status'],
				'attempt' => $queued['attempt'],
				'channel' => $queued['channel'],
				'post_id' => $queued['post_id'],
			),
			202
		);

		return $response;
	}

	/**
	 * One entry as the API returns it: status codes, times in ISO 8601 UTC.
	 *
	 * @param array<string, mixed> $row A decorated row.
	 * @return array<string, mixed>
	 */
	private function item( array $row ): array {
		return array(
			'id'            => (int) $row['id'],
			'post_id'       => (int) $row['post_id'],
			'title'         => (string) $row['title'],
			'channel'       => (string) $row['channel'],
			'channel_label' => (string) $row['channel_label'],
			'language'      => (string) $row['language'],
			'event'         => (string) $row['event'],
			'status'        => (string) $row['status'],
			'attempt'       => (int) $row['attempt'],
			'max_attempts'  => Retry::MAX_ATTEMPTS,
			'response_code' => (int) $row['response_code'],
			'message'       => (string) $row['message'],
			'created'       => Clock::iso( (int) $row['created_ts'] ),
			'run_at'        => (int) $row['run_at_ts'] > 0 ? Clock::iso( (int) $row['run_at_ts'] ) : null,
			'can_retry'     => (bool) $row['can_retry'],
		);
	}
}
