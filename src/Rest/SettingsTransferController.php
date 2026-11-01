<?php
/**
 * REST: export, import, restore points and reset.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Operations\SettingsBackup;

defined( 'ABSPATH' ) || exit;

/**
 * Settings, Backup and uninstall. Administrators only. A settings file never
 * holds a secret, and the sections `connections` and `data` are never part of it
 * (see `Core\Operations\SettingsBackup`).
 *
 * - `POST /settings/export`: body `include` (a list of `settings`, `alerts`, `notes`, `referrals`;
 *   default `settings`). Answers `{ filename, file }`, where `file` is the settings file.
 * - `POST /settings/import`: body `mode` (`preview`, the default, or `apply`) and `file`
 *   (the settings file as an object, or as text). `preview` saves nothing and answers what
 *   would change; `apply` saves it, after a restore point, and writes an audit row.
 * - `GET /settings/restore-points`: the restore points, newest first.
 * - `POST /settings/restore-points/{id}`: puts one back (a new restore point is saved first).
 * - `POST /settings/reset/{section}`: puts one section back to what a new install has.
 *
 * `SettingsController` answers `/settings/{section}` for any name, which would
 * take `export`, `import` and `restore-points`. This controller moves its own
 * routes in front of it with the `rest_endpoints` filter, which
 * `Modules\OperationsModule` adds when the plugin starts.
 */
final class SettingsTransferController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$permission = $this->requires( Access::ADMIN );

		$this->route(
			'/settings/export',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'export' ),
					'permission_callback' => $permission,
					'args'                => array(
						'include' => array(
							'type'    => 'array',
							'default' => array( 'settings' ),
							'items'   => array(
								'type' => 'string',
								'enum' => array( 'settings', 'alerts', 'notes', 'referrals' ),
							),
						),
					),
				),
			)
		);

		$this->route(
			'/settings/import',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => $permission,
					'args'                => array(
						'mode' => array(
							'type'    => 'string',
							'default' => 'preview',
							'enum'    => array( 'preview', 'apply' ),
						),
					),
				),
			)
		);

		$this->route(
			'/settings/restore-points',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'points' ),
					'permission_callback' => $permission,
				),
			)
		);

		$this->route(
			'/settings/restore-points/(?P<id>[a-z0-9]{6,32})',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'restore' ),
					'permission_callback' => $permission,
				),
			)
		);

		$this->route(
			'/settings/reset/(?P<section>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset' ),
					'permission_callback' => $permission,
				),
			)
		);
	}

	/**
	 * Moves this controller's routes in front of `/settings/{section}`.
	 *
	 * WordPress tries routes in the order they were registered, and the pattern
	 * of `SettingsController` matches the one-word addresses `export`, `import` and
	 * `restore-points`.
	 *
	 * @param mixed $endpoints All routes.
	 * @return mixed
	 */
	public static function put_first( $endpoints ) {
		if ( ! is_array( $endpoints ) ) {
			return $endpoints;
		}

		$mine = array();

		foreach ( array( 'export', 'import', 'restore-points' ) as $word ) {
			$key = '/' . self::REST_NAMESPACE . '/settings/' . $word;

			if ( isset( $endpoints[ $key ] ) ) {
				$mine[ $key ] = $endpoints[ $key ];
				unset( $endpoints[ $key ] );
			}
		}

		return array_merge( $mine, $endpoints );
	}

	/**
	 * The service.
	 *
	 * @return SettingsBackup
	 */
	private function backup(): SettingsBackup {
		return $this->plugin->make( SettingsBackup::class );
	}

	/**
	 * POST /settings/export.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function export( \WP_REST_Request $request ) {
		$include = array_map( 'strval', (array) $request['include'] );

		return rest_ensure_response(
			array(
				'filename' => $this->backup()->filename(),
				'file'     => $this->backup()->export( array() === $include ? array( 'settings' ) : $include ),
			)
		);
	}

	/**
	 * POST /settings/import.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();
		$given  = is_array( $params ) && isset( $params['file'] ) ? $params['file'] : null;

		if ( is_array( $given ) ) {
			$given = wp_json_encode( $given );
		}

		if ( ! is_string( $given ) || '' === trim( $given ) ) {
			return $this->error( 'estasoft_nd_missing_file', __( 'Send the settings file in the "file" field.', 'estasoft-news-distribution' ), 400 );
		}

		$file = $this->backup()->parse( $given );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( 'apply' === (string) $request['mode'] ) {
			$done = $this->backup()->apply( $file );

			return is_wp_error( $done ) ? $done : rest_ensure_response( $done );
		}

		return rest_ensure_response( $this->backup()->preview( $file ) );
	}

	/**
	 * GET /settings/restore-points.
	 *
	 * @return \WP_REST_Response
	 */
	public function points() {
		$list = array();

		foreach ( $this->backup()->points() as $point ) {
			$list[] = array(
				'id'      => $point['id'],
				'created' => Clock::iso( $point['created'] ),
				'reason'  => $point['reason'],
				'label'   => $this->backup()->reason_text( $point['reason'], $point['section'], $point['version'], $point['how'] ),
				'section' => $point['section'],
				'version' => $point['version'],
				'count'   => $point['count'],
			);
		}

		return rest_ensure_response( $list );
	}

	/**
	 * POST /settings/restore-points/{id}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore( \WP_REST_Request $request ) {
		$done = $this->backup()->restore( (string) $request['id'] );

		return is_wp_error( $done ) ? $done : rest_ensure_response( $done );
	}

	/**
	 * POST /settings/reset/{section}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reset( \WP_REST_Request $request ) {
		$done = $this->backup()->reset( (string) $request['section'] );

		return is_wp_error( $done ) ? $done : rest_ensure_response( $done );
	}
}
