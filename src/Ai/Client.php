<?php
/**
 * The one place every AI request goes through.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `ask()` refuses, before anything is built or sent, unless the assistant is on, consent is
 * recorded, the feature's switch is on and no limit is reached (`Availability::gate()`). A second
 * guard, `guard()`, runs on WordPress's own `wp_ai_client_before_generate_result` action while one of
 * this plugin's requests is running, and stops the request when WordPress picked a provider that
 * the owner has not confirmed or that the network does not allow. So a provider installed after
 * the owner confirmed never receives text.
 *
 * An error from a provider is never passed on as written: it can quote part of a key. The
 * screens get a short sentence and a reason code instead.
 */
final class Client {

	/**
	 * Whether a request of this plugin is running now. Other plugins' AI requests are not touched.
	 *
	 * @var bool
	 */
	private static bool $active = false;

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The generator in use: the one a test supplied, or the WordPress AI Client.
	 *
	 * @return TextGenerator
	 */
	public function generator(): TextGenerator {
		/**
		 * Replaces the text generator (internal; tests use it so nothing leaves the machine).
		 *
		 * @param TextGenerator|null $generator A generator, or null for the WordPress AI Client.
		 */
		$given = apply_filters( 'estasoft_nd_internal_ai_generator', null );

		return $given instanceof TextGenerator ? $given : new WpTextGenerator();
	}

	/**
	 * Asks the provider for text on behalf of one feature.
	 *
	 * @param string               $feature A key of Config::FEATURE_TOGGLE.
	 * @param array<string, mixed> $request `system`, `prompt`, `max_tokens` and `image`; see TextGenerator.
	 * @return array{text: string, provider: string, model: string, tokens: int, ms: int}|\WP_Error
	 */
	public function ask( string $feature, array $request ) {
		$gate = $this->plugin->make( Availability::class )->gate( $feature );

		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return $this->send( $request );
	}

	/**
	 * Sends the owner's test request: the words "Reply with OK" and nothing from the site.
	 *
	 * It needs a working connection and recorded consent, because it sends text to a provider. It does not need
	 * a feature to be on, so the owner can test before turning one on.
	 *
	 * @return array{ok: bool, provider: string, provider_name: string, model: string, ms: int, tokens: int, reason: string, message: string}|\WP_Error
	 */
	public function test() {
		$availability = $this->plugin->make( Availability::class );
		$connection   = $availability->connection();

		if ( 'ok' !== $connection ) {
			return $this->refusal( $connection );
		}

		if ( ! $this->plugin->make( Consent::class )->valid() ) {
			return new \WP_Error( 'estasoft_nd_ai_needs_consent', __( 'A test request sends the words “Reply with OK” to your provider, so it waits for your confirmation. Read and confirm first.', 'estasoft-news-distribution' ), array( 'status' => 403 ) );
		}

		// A network whose monthly AI budget is used makes no request at all, not even this one.
		if ( $this->plugin->make( Usage::class )->network_reached() ) {
			return $availability->limit_error( 'network' );
		}

		$result = $this->send(
			array(
				'system'     => '',
				'prompt'     => 'Reply with OK',
				'max_tokens' => 20,
				'image'      => '',
			)
		);
		$usage  = $this->plugin->make( Usage::class );

		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$reason = is_array( $data ) ? (string) ( $data['reason'] ?? 'provider' ) : 'provider';

			$usage->remember_probe(
				array(
					'ok'     => false,
					'reason' => $reason,
				)
			);

			return $result;
		}

		$usage->remember_probe(
			array(
				'ok'       => true,
				'provider' => $result['provider'],
				'model'    => $result['model'],
				'ms'       => $result['ms'],
				'tokens'   => $result['tokens'],
			)
		);

		$name = $result['provider'];

		foreach ( $availability->providers() as $provider ) {
			if ( $provider['id'] === $result['provider'] ) {
				$name = $provider['name'];
			}
		}

		return array(
			'ok'            => true,
			'provider'      => $result['provider'],
			'provider_name' => $name,
			'model'         => $result['model'],
			'ms'            => $result['ms'],
			'tokens'        => $result['tokens'],
			'reason'        => '',
			'message'       => sprintf(
				/* translators: 1: provider name, 2: model name, 3: seconds, 4: number of tokens. */
				__( 'Test passed: %1$s answered with %2$s in %3$s seconds. %4$s tokens used, billed by your provider.', 'estasoft-news-distribution' ),
				$name,
				'' === $result['model'] ? '—' : $result['model'],
				number_format_i18n( $result['ms'] / 1000, 1 ),
				number_format_i18n( $result['tokens'] )
			),
		);
	}

	/**
	 * Runs the generator with the provider guard on and counts the request.
	 *
	 * @param array<string, mixed> $request See TextGenerator.
	 * @return array{text: string, provider: string, model: string, tokens: int, ms: int}|\WP_Error
	 */
	private function send( array $request ) {
		// The last door: whatever asked, a used-up network budget means no request is made.
		if ( $this->plugin->make( Usage::class )->network_reached() ) {
			return $this->plugin->make( Availability::class )->limit_error( 'network' );
		}

		$request = array_merge(
			array(
				'system'     => '',
				'prompt'     => '',
				'models'     => $this->plugin->make( Config::class )->models(),
				'max_tokens' => 1024,
				'image'      => '',
			),
			$request
		);

		$started      = microtime( true );
		self::$active = true;

		try {
			$result = $this->generator()->generate( $request );
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'prompt_builder_error', 'The request failed.', array( 'status' => 500 ) );
		} finally {
			self::$active = false;
		}

		if ( is_wp_error( $result ) ) {
			return $this->plain_error( $result );
		}

		$text = isset( $result['text'] ) ? trim( (string) $result['text'] ) : '';

		if ( '' === $text ) {
			return $this->plain_error( new \WP_Error( 'prompt_builder_error', 'Empty answer.', array( 'status' => 502 ) ) );
		}

		$answer = array(
			'text'     => $text,
			'provider' => sanitize_key( (string) ( $result['provider'] ?? '' ) ),
			'model'    => sanitize_text_field( (string) ( $result['model'] ?? '' ) ),
			'tokens'   => max( 0, (int) ( $result['tokens'] ?? 0 ) ),
			'ms'       => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);

		$this->plugin->make( Usage::class )->record( $answer['tokens'], max( 0, (int) ( $result['tokens_in'] ?? 0 ) ), max( 0, (int) ( $result['tokens_out'] ?? 0 ) ) );

		return $answer;
	}

	/**
	 * Stops a request whose provider the owner has not confirmed. Hooked to WordPress's
	 * `wp_ai_client_before_generate_result`, which fires after the model is chosen and before it is called.
	 *
	 * @param object $event WordPress's BeforeGenerateResultEvent.
	 * @return void
	 * @throws \RuntimeException When the provider that WordPress chose is not confirmed or not allowed.
	 */
	public static function guard( $event ): void {
		if ( ! self::$active ) {
			return;
		}

		$provider = '';

		if ( is_object( $event ) && method_exists( $event, 'getModel' ) ) {
			$model = $event->getModel();

			if ( is_object( $model ) && method_exists( $model, 'providerMetadata' ) ) {
				$provider = (string) $model->providerMetadata()->getId();
			}
		}

		$plugin = Plugin::instance();

		if ( '' === $provider || ! $plugin->make( Consent::class )->covers( $provider ) || ! $plugin->make( Policy::class )->allows_provider( $provider ) ) {
			throw new \RuntimeException( 'estasoft_nd_ai_provider_not_confirmed' );
		}
	}

	/**
	 * Whether one of this plugin's requests is running. For tests.
	 *
	 * @return bool
	 */
	public static function running(): bool {
		return self::$active;
	}

	/**
	 * Marks a request as running. For tests that call `guard()` directly.
	 *
	 * @param bool $running Whether one is running.
	 * @return void
	 */
	public static function mark_running( bool $running ): void {
		self::$active = $running;
	}

	/**
	 * The sentence for a reason a request failed. The provider's own words are never used.
	 *
	 * @param string $reason `prevented`, `key`, `quota`, `no_model` or `provider`.
	 * @return string
	 */
	public static function reason_text( string $reason ): string {
		switch ( $reason ) {
			case 'prevented':
				return __( 'AI is switched off for this WordPress, so nothing was sent.', 'estasoft-news-distribution' );
			case 'key':
				return __( 'Your provider did not accept the request. It said the key is not valid. Nothing from your site was changed. Check the key under Settings, Connectors, then test again.', 'estasoft-news-distribution' );
			case 'quota':
				return __( 'Your provider said its quota is used up. Nothing was changed. Add credit or raise the quota in your provider’s account, then send a test request.', 'estasoft-news-distribution' );
			case 'no_model':
				return __( 'WordPress found no model that can do this. Check your preferred models in Settings, AI assistant, or connect another provider. Nothing was changed.', 'estasoft-news-distribution' );
			default:
				return __( 'The AI provider did not answer. Nothing was changed. Try again.', 'estasoft-news-distribution' );
		}
	}

	/**
	 * The error for a connection that is not fine.
	 *
	 * @param string $connection `unsupported`, `locked` or `no_provider`.
	 * @return \WP_Error
	 */
	private function refusal( string $connection ): \WP_Error {
		$messages = array(
			'unsupported' => array( 404, __( 'AI features need WordPress 7.0 or newer, with AI switched on.', 'estasoft-news-distribution' ) ),
			'locked'      => array( 403, __( 'Your network administrator has turned the AI assistant off for this site.', 'estasoft-news-distribution' ) ),
			'no_provider' => array( 409, __( 'Connect an AI provider to use the assistant.', 'estasoft-news-distribution' ) ),
		);
		$row      = $messages[ $connection ] ?? $messages['no_provider'];

		return new \WP_Error( 'estasoft_nd_ai_' . $connection, $row[1], array( 'status' => $row[0] ) );
	}

	/**
	 * Turns an error from the AI Client into a plain sentence and a reason code.
	 *
	 * The provider's own message is dropped: it can quote the start of a key.
	 *
	 * @param \WP_Error $error The error.
	 * @return \WP_Error
	 */
	private function plain_error( \WP_Error $error ): \WP_Error {
		$code   = (string) $error->get_error_code();
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;

		if ( 0 === strpos( $code, 'estasoft_nd_' ) ) {
			return $error;
		}

		if ( 'prompt_prevented' === $code ) {
			$reason = 'prevented';
		} elseif ( 401 === $status || 403 === $status ) {
			$reason = 'key';
		} elseif ( 429 === $status || 402 === $status ) {
			$reason = 'quota';
		} elseif ( 'prompt_invalid_argument' === $code ) {
			$reason = 'no_model';
		} else {
			$reason = 'provider';
		}

		return new \WP_Error(
			'estasoft_nd_ai_failed',
			self::reason_text( $reason ),
			array(
				'status' => 502,
				'reason' => $reason,
			)
		);
	}
}
