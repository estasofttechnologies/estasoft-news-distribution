<?php
/**
 * REST: the AI assistant.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Client;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Consent;
use Estasoft\NewsDistribution\Ai\Features\AbstractFeature;
use Estasoft\NewsDistribution\Ai\Log;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET /ai/status`: what the assistant is doing and why, for the settings page and the tabs. It holds no key, no
 *   token and no answer from a provider: WordPress keeps the connection, and this plugin never reads a key.
 * - `POST /ai/test` (administrator): sends the words "Reply with OK" and nothing from the site. It needs the recorded confirmation.
 * - `POST /ai/consent` (administrator, body `confirm: true`) and `DELETE /ai/consent`: records or removes the
 *   confirmation of what is sent and to whom, and turns the assistant on or off with it.
 * - `POST /ai/suggest/{feature}`: asks for a suggestion and returns it with its rule check. `feature` is `fix`, `ask`,
 *   `platform-help`, `translate`, `local-news`, `image`, `summary` or `digest`. Body: `post` (the article, for the features
 *   about one), and what the feature asks for (`language`, `keep`, `question`, `mode`, `facts`, `kind`). `kind` picks
 *   between two things one feature suggests: `summary` or `description` for `summary`, and `caption` for `image`. It
 *   changes nothing in the article.
 * - `POST /ai/decision`: records what a person chose for a suggestion (`log`, `decision`, and `index`, `text`, `target`,
 *   `reviewer`, `responsible`, `confirm`) and applies it. It is the only step that changes an article. A search description
 *   and a caption are logged under their own names (`Config::LOG_FEATURE`), and the decision goes to the feature behind them.
 *
 * None of these routes exists on a WordPress older than 7.0. The suggestion and decision routes answer 403 until the
 * assistant is on and confirmed.
 */
final class AiController extends Controller {

	/**
	 * Decisions that only close a suggestion or approve a saved draft. They stay possible when the assistant is later turned off.
	 */
	private const FREE = array( 'dismiss', 'approve' );

	/**
	 * Features by REST name.
	 *
	 * @var array<string, AbstractFeature>|null
	 */
	private ?array $features = null;

	/**
	 * @return void
	 */
	public function register_routes(): void {
		if ( ! $this->plugin->make( Availability::class )->supported() ) {
			return;
		}

		$this->route(
			'/ai/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $this->requires_editor(),
			)
		);

		$this->route(
			'/ai/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $this->requires( Access::ADMIN ),
			)
		);

		$this->route(
			'/ai/consent',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'consent' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
					'args'                => array(
						'confirm' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => $this->requires( Access::ADMIN ),
				),
			)
		);

		$this->route(
			'/ai/suggest/(?P<feature>[a-z-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'suggest' ),
				'permission_callback' => $this->requires_editor(),
				'args'                => array(
					'post'     => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'language' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'keep'     => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
					'question' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'mode'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'facts'    => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
					'kind'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		$this->route(
			'/ai/decision',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'decide' ),
				'permission_callback' => $this->requires_editor(),
				'args'                => array(
					'log'         => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'decision'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'index'       => array(
						'type'    => 'integer',
						'default' => -1,
					),
					'text'        => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'target'      => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'responsible' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'reviewer'    => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'confirm'     => array(
						'type'              => 'boolean',
						'default'           => false,
						'sanitize_callback' => 'rest_sanitize_boolean',
					),
				),
			)
		);
	}

	/**
	 * Anyone who can edit posts. What each route lets them do is checked inside, for the article they name.
	 *
	 * @return callable
	 */
	private function requires_editor(): callable {
		$capability = $this->plugin->access()->capability_for( Access::AI );

		return static function () use ( $capability ): bool {
			return current_user_can( $capability );
		};
	}

	/**
	 * The features, by REST name, found by folder.
	 *
	 * @return array<string, AbstractFeature>
	 */
	private function features(): array {
		if ( null === $this->features ) {
			$this->features = array();

			foreach ( Discovery::classes( 'Ai/Features' ) as $class ) {
				if ( class_exists( $class ) && is_subclass_of( $class, AbstractFeature::class ) ) {
					$feature = $this->plugin->make( $class );

					if ( $feature instanceof AbstractFeature ) {
						$this->features[ $feature->name() ] = $feature;
					}
				}
			}
		}

		return $this->features;
	}

	/**
	 * `GET /ai/status`.
	 *
	 * @return \WP_REST_Response
	 */
	public function status(): \WP_REST_Response {
		return new \WP_REST_Response( $this->plugin->make( Availability::class )->status() );
	}

	/**
	 * `POST /ai/test`.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test() {
		$result = $this->plugin->make( Client::class )->test();

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	/**
	 * `POST /ai/consent`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function consent( \WP_REST_Request $request ) {
		if ( true !== (bool) $request['confirm'] ) {
			return $this->error( 'estasoft_nd_ai_confirm', __( 'Confirm what is sent and to whom first. Until you do, the assistant stays off and nothing is sent.', 'estasoft-news-distribution' ), 400 );
		}

		$done = $this->plugin->make( Consent::class )->grant( get_current_user_id() );

		return is_wp_error( $done ) ? $done : $this->status();
	}

	/**
	 * `DELETE /ai/consent`.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke() {
		$done = $this->plugin->make( Consent::class )->revoke( get_current_user_id() );

		return is_wp_error( $done ) ? $done : $this->status();
	}

	/**
	 * `POST /ai/suggest/{feature}`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function suggest( \WP_REST_Request $request ) {
		$features = $this->features();
		$feature  = $features[ (string) $request['feature'] ] ?? null;

		if ( null === $feature ) {
			return $this->error( 'estasoft_nd_ai_no_feature', __( 'There is no such AI feature.', 'estasoft-news-distribution' ), 404 );
		}

		$post_id = (int) $request['post'];

		if ( $feature->needs_article() && $post_id < 1 ) {
			return $this->error( 'estasoft_nd_ai_no_article', __( 'Say which article this is about.', 'estasoft-news-distribution' ), 400 );
		}

		if ( ! $feature->can( $post_id ) ) {
			return $this->error( 'estasoft_nd_ai_forbidden', __( 'You cannot use the AI assistant here. It may be off, or not confirmed yet, or you may not be allowed to edit this article.', 'estasoft-news-distribution' ), 403 );
		}

		$result = $feature->suggest( $post_id, $this->input( $request ) );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	/**
	 * `POST /ai/decision`.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function decide( \WP_REST_Request $request ) {
		$row = $this->plugin->make( Log::class )->get( (int) $request['log'] );

		if ( null === $row ) {
			return $this->error( 'estasoft_nd_ai_no_suggestion', __( 'That suggestion is not in the log any more.', 'estasoft-news-distribution' ), 404 );
		}

		$name    = Config::LOG_FEATURE[ $row['feature'] ] ?? $row['feature'];
		$feature = $this->features()[ $name ] ?? null;

		if ( null === $feature ) {
			return $this->error( 'estasoft_nd_ai_no_feature', __( 'There is nothing to decide here.', 'estasoft-news-distribution' ), 404 );
		}

		$decision = (string) $request['decision'];
		$post_id  = (int) $row['post_id'];

		if ( in_array( $decision, self::FREE, true ) ) {
			$allowed = $post_id > 0 ? current_user_can( 'edit_post', $post_id ) : $this->plugin->access()->can( Access::MANAGE );
		} else {
			$allowed = $feature->can( $post_id ) && $this->plugin->make( Config::class )->feature_on( (string) $row['feature'] );
		}

		if ( ! $allowed ) {
			return $this->error( 'estasoft_nd_ai_forbidden', __( 'You cannot decide this here. The assistant may be off, or you may not be allowed to edit this article.', 'estasoft-news-distribution' ), 403 );
		}

		$result = $feature->decide( $row, $this->input( $request ), get_current_user_id() );

		return is_wp_error( $result ) ? $result : new \WP_REST_Response( $result );
	}

	/**
	 * What the request carries, for a feature to read and clean.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array<string, mixed>
	 */
	private function input( \WP_REST_Request $request ): array {
		$params = $request->get_params();

		unset( $params['feature'] );

		return $params;
	}
}
