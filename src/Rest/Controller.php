<?php
/**
 * Base for the REST controllers.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace `estasoft-nd/v1`. Every route has a `permission_callback` that asks
 * Core\Access; there are no public routes. Cookie authentication with the
 * X-WP-Nonce header is what the admin pages use, and application passwords work
 * because these are ordinary WordPress routes.
 *
 * To add a resource: create `src/Rest/<Name>Controller.php` extending this
 * class. It is found by folder and registered on `rest_api_init`.
 *
 * Conventions (spec 6.2):
 * - JSON in and out. Errors are WP_Error with a code, a plain-language message
 *   the screen can show as written, and data.status.
 * - Lists take `page` and `per_page` (default 20, at most 100) and answer with
 *   X-WP-Total and X-WP-TotalPages.
 * - Times are ISO 8601 in UTC (Clock::iso()). Status values are machine codes
 *   from Core\Status, never display words.
 * - Long work returns 202 with a job id; the screen polls GET /jobs/{id}.
 */
abstract class Controller {

	public const REST_NAMESPACE = 'estasoft-nd/v1';

	protected Plugin $plugin;

	/**
	 * Creates the controller.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers the controller's routes with register_rest_route().
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Registers one route in the plugin's namespace.
	 *
	 * @param string               $route Route pattern, such as "/channels/(?P<id>[a-z0-9-]+)".
	 * @param array<string, mixed> $args  Route arguments or a list of them.
	 * @return void
	 */
	protected function route( string $route, array $args ): void {
		register_rest_route( self::REST_NAMESPACE, $route, $args );
	}

	/**
	 * A permission callback for a fixed permission name.
	 *
	 * @param string $permission One of the Core\Access constants.
	 * @return callable
	 */
	protected function requires( string $permission ): callable {
		$access = $this->plugin->access();

		return static function () use ( $access, $permission ): bool {
			return $access->can( $permission );
		};
	}

	/**
	 * A permission callback that reads the article from the request's `id` parameter.
	 *
	 * @param string $permission One of the Core\Access constants.
	 * @return callable
	 */
	protected function requires_article( string $permission ): callable {
		$access = $this->plugin->access();

		return static function ( \WP_REST_Request $request ) use ( $access, $permission ): bool {
			return $access->can( $permission, (int) $request['id'] );
		};
	}

	/**
	 * A plain-language error.
	 *
	 * @param string               $code    Stable code, starting with estasoft_nd_.
	 * @param string               $message Sentence the screen can show as written.
	 * @param int                  $status  HTTP status.
	 * @param array<string, mixed> $data    Extra data, such as `fields`.
	 * @return \WP_Error
	 */
	protected function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $data ) );
	}

	/**
	 * A list response with the paging headers.
	 *
	 * @param array<int, mixed> $items    Items on this page.
	 * @param int               $total    Total items.
	 * @param int               $per_page Items per page.
	 * @return \WP_REST_Response
	 */
	protected function paged( array $items, int $total, int $per_page ): \WP_REST_Response {
		$response = new \WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $per_page ) ) ) );

		return $response;
	}

	/**
	 * Shared collection arguments: page and per_page.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function paging_args(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}
}
