<?php
/**
 * REST: /rules.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\RulePacks;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /rules` lists the installed rule packs. `POST /rules` chooses an older bundled
 * version of one pack (`platform`, `version`; an empty version means the newest).
 *
 * Version 1.0 downloads nothing: `check` and `install` answer 501 and say so. There is
 * no signing scheme, so there is no way to trust a download. Only an administrator may use
 * either route. The settings of this screen are `/settings/rules`.
 */
final class RulesController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/rules',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'change' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
					'args'                => array(
						'action'   => array(
							'type'    => 'string',
							'default' => 'use',
							'enum'    => array( 'use', 'check', 'install' ),
						),
						'platform' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_key',
						),
						'version'  => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * GET /rules.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		return rest_ensure_response(
			array(
				'updates' => array(
					'supported' => false,
					'on'        => false,
					'note'      => __( 'This version of News Distribution does not look for new rule packs and downloads nothing. The rules that ship with it keep working.', 'estasoft-news-distribution' ),
				),
				'packs'   => $this->packs()->all(),
			)
		);
	}

	/**
	 * POST /rules.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change( \WP_REST_Request $request ) {
		if ( 'use' !== (string) $request['action'] ) {
			return $this->error(
				'estasoft_nd_rules_download_off',
				__( 'This version of News Distribution does not download rule packs. Update the plugin to get newer packs.', 'estasoft-news-distribution' ),
				501
			);
		}

		$platform = (string) $request['platform'];

		if ( '' === $platform ) {
			return $this->error(
				'estasoft_nd_rules_platform_missing',
				__( 'Say which platform the rule pack is for.', 'estasoft-news-distribution' ),
				400,
				array( 'fields' => array( 'platform' => __( 'Say which platform the rule pack is for.', 'estasoft-news-distribution' ) ) )
			);
		}

		$pack = $this->packs()->use_version( $platform, (string) $request['version'] );

		return is_wp_error( $pack ) ? $pack : rest_ensure_response( $pack );
	}

	/**
	 * The rule packs service.
	 *
	 * @return RulePacks
	 */
	private function packs(): RulePacks {
		return $this->plugin->make( RulePacks::class );
	}
}
