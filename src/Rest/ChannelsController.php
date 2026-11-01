<?php
/**
 * REST: /channels, /channels/{id}, /channels/{id}/test, /channels/{id}/validate.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Rest;

use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsSwitch;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Settings\SecretInput;
use Estasoft\NewsDistribution\Frontend\FeedController;

defined( 'ABSPATH' ) || exit;

/**
 * Every channel, one channel's settings and status, a test a person starts, and
 * the plugin's own check of a channel's output. Secrets are write-only: a saved
 * secret comes back as a mask and a `<field>_is_set` flag, never as its value.
 *
 * A channel needs nothing here. It appears because it is registered, its fields
 * come from `fields()`, its test from `test()` and its output check from
 * `check_output()`.
 */
final class ChannelsController extends Controller {

	/**
	 * @return void
	 */
	public function register_routes(): void {
		$this->route(
			'/channels',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => $this->requires( Access::VIEW ),
				),
			)
		);

		$one = '/channels/(?P<id>[a-z0-9-]+)';

		$this->route(
			$one,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => $this->requires( Access::VIEW ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);

		$this->route(
			$one . '/test',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);

		$this->route(
			$one . '/validate',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'validate' ),
					'permission_callback' => $this->requires( Access::MANAGE ),
				),
			)
		);
	}

	/**
	 * GET /channels.
	 *
	 * @return \WP_REST_Response
	 */
	public function index(): \WP_REST_Response {
		$items = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$items[] = $this->summary( $channel );
		}

		return rest_ensure_response( $items );
	}

	/**
	 * GET /channels/{id}.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function read( \WP_REST_Request $request ) {
		$channel = $this->find( (string) $request['id'] );

		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		return rest_ensure_response( $this->detail( $channel ) );
	}

	/**
	 * PUT /channels/{id}. A value outside its range is not saved; the response
	 * names the field and the allowed range.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$channel = $this->find( (string) $request['id'] );

		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		$input = $request->get_json_params();
		$input = is_array( $input ) ? $input : $request->get_body_params();
		$input = is_array( $input ) ? $input : array();

		// Secrets belong to the `admin` permission, even where `manage` is given to Editors.
		$current = $this->plugin->channel_settings()->get( $channel->id(), $channel->fields() );

		if ( ( SecretInput::touches( $channel->fields(), $input ) || array() !== SecretInput::moved( $channel->fields(), $input, $current ) ) && ! $this->plugin->access()->can( Access::ADMIN ) ) {
			return $this->error( 'estasoft_nd_secrets_need_admin', __( 'Only an administrator can change a saved key, token or address. Ask one to enter it.', 'estasoft-news-distribution' ), 403 );
		}

		// Outlets is on while an outlet is on, so its switch turns the outlets off and on (see OutletsSwitch).
		$outlets = null;
		$wanted  = false;

		if ( $channel instanceof OutletsChannel && array_key_exists( 'enabled', $input ) ) {
			$outlets = $this->plugin->make( OutletsSwitch::class );
			$wanted  = (bool) filter_var( $input['enabled'], FILTER_VALIDATE_BOOLEAN );
			unset( $input['enabled'] );

			$refused = $outlets->check( $channel, $wanted, $input );

			if ( null !== $refused ) {
				return $refused;
			}
		}

		$saved = $this->plugin->channel_settings()->save( $channel->id(), $channel->fields(), $input );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( null !== $outlets ) {
			$switched = $outlets->apply( $channel, $wanted );

			if ( is_wp_error( $switched ) ) {
				return $switched;
			}
		}

		return rest_ensure_response( $this->detail( $channel ) );
	}

	/**
	 * POST /channels/{id}/test. Reaches the other side, so it is only ever a
	 * person pressing a button. The result is kept as the channel's last test.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function test( \WP_REST_Request $request ) {
		$channel = $this->find( (string) $request['id'] );

		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		$result = array_merge(
			array(
				'ok'      => false,
				'code'    => 0,
				'message' => '',
			),
			$channel->test()
		);

		$result['time'] = Clock::now();
		$this->plugin->channel_settings()->set_last_test( $channel->id(), $result );

		return rest_ensure_response( $this->last_test( $result ) );
	}

	/**
	 * POST /channels/{id}/validate: builds the channel's output for each
	 * language it carries and checks it with the channel's own rules.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function validate( \WP_REST_Request $request ) {
		$channel = $this->find( (string) $request['id'] );

		if ( is_wp_error( $channel ) ) {
			return $channel;
		}

		if ( ChannelInterface::MODE_PULLED !== $channel->mode() ) {
			return $this->error( 'estasoft_nd_nothing_to_validate', __( 'This channel sends items one at a time, so there is no document to check. Use the article check instead.', 'estasoft-news-distribution' ), 400 );
		}

		$feeds   = new FeedController( $this->plugin );
		$results = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			if ( ! $channel->accepts_language( (string) $language ) ) {
				continue;
			}

			$document = $feeds->build_document( $channel, (string) $language );
			$checked  = $channel->check_output( $document['body'], (string) $language );

			$results[] = array(
				'language' => (string) $language,
				'valid'    => (bool) $checked['valid'],
				'entries'  => (int) $checked['entries'],
				'articles' => (int) $document['articles'],
				'problems' => array_values( (array) $checked['problems'] ),
			);
		}

		return rest_ensure_response(
			array(
				'channel'   => $channel->id(),
				'languages' => $results,
			)
		);
	}

	/**
	 * Finds a channel or answers 404.
	 *
	 * @param string $id Channel id.
	 * @return ChannelInterface|\WP_Error
	 */
	private function find( string $id ) {
		$channel = $this->plugin->registry()->get( $id );

		if ( null === $channel ) {
			return $this->error( 'estasoft_nd_unknown_channel', __( 'That channel does not exist.', 'estasoft-news-distribution' ), 404 );
		}

		return $channel;
	}

	/**
	 * One channel as the list shows it.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return array<string, mixed>
	 */
	private function summary( ChannelInterface $channel ): array {
		$settings = $this->plugin->channel_settings()->get( $channel->id(), $channel->fields() );

		return array(
			'id'        => $channel->id(),
			'label'     => $channel->label(),
			'group'     => $channel->group(),
			'mode'      => $channel->mode(),
			'order'     => $channel->order(),
			'enabled'   => $channel->is_enabled(),
			'health'    => $channel->health(),
			'rows'      => $channel->hub_rows(),
			'last_test' => $this->last_test( (array) $settings['last_test'] ),
		);
	}

	/**
	 * One channel with its settings, rule pack and feed addresses.
	 *
	 * @param ChannelInterface $channel The channel.
	 * @return array<string, mixed>
	 */
	private function detail( ChannelInterface $channel ): array {
		$feeds = array();

		if ( '' !== $channel->feed_slug() ) {
			foreach ( $this->plugin->languages()->languages() as $language ) {
				$feeds[] = array(
					'language' => (string) $language,
					'carried'  => $channel->accepts_language( (string) $language ),
					'url'      => add_query_arg( 'lang', rawurlencode( (string) $language ), home_url( '/feed/' . $channel->feed_slug() . '/' ) ),
				);
			}
		}

		return array_merge(
			$this->summary( $channel ),
			array(
				'settings'    => $this->plugin->channel_settings()->for_screen( $channel->id(), $channel->fields() ),
				'fields'      => $this->public_fields( $channel->fields() ),
				'rule_pack'   => $this->plugin->rules()->version( $channel->id() ),
				'feeds'       => $feeds,
				'gate_note'   => $channel->gate_note(),
				'application' => $this->plugin->applications()->get( $channel->id() ),
			)
		);
	}

	/**
	 * Field specs without callables, which cannot be sent as JSON.
	 *
	 * @param array<string, array<string, mixed>> $fields Field specs.
	 * @return array<string, array<string, mixed>>
	 */
	private function public_fields( array $fields ): array {
		foreach ( $fields as $name => $spec ) {
			foreach ( array( 'choices', 'options' ) as $key ) {
				if ( isset( $spec[ $key ] ) && is_callable( $spec[ $key ] ) ) {
					$fields[ $name ][ $key ] = call_user_func( $spec[ $key ] );
				}
			}
		}

		return $fields;
	}

	/**
	 * The last test as the screen shows it: the time in ISO 8601 UTC.
	 *
	 * @param array<string, mixed> $test A stored test result.
	 * @return array<string, mixed>
	 */
	private function last_test( array $test ): array {
		if ( array() === $test || empty( $test['time'] ) ) {
			return array();
		}

		return array(
			'time'    => Clock::iso( (int) $test['time'] ),
			'ok'      => ! empty( $test['ok'] ),
			'code'    => (int) ( $test['code'] ?? 0 ),
			'message' => (string) ( $test['message'] ?? '' ),
		);
	}
}
