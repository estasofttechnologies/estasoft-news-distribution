<?php
/**
 * Whether the AI assistant can run on this site, and why not when it cannot.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai;

use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Loaded only on WordPress 7.0 and newer, where core ships the AI Client. It asks WordPress
 * nothing that reaches a provider: `wp_supports_ai()` is a local switch, and the providers are the
 * connectors WordPress has registered (`wp_get_connectors()`), each of which says whether its
 * plugin is installed. Whether a key is saved is not asked, because a key belongs to WordPress
 * and this plugin never reads one. A test request finds that out, and only after consent.
 *
 * The answer is two questions that the screens show apart:
 *
 * - `connection`: `unsupported` (WordPress older than 7.0, or AI switched off for this
 *   WordPress), `locked` (the network turned it off), `no_provider` or `ok`.
 * - `assistant`: `off` (no feature is on), `needs_consent` (a feature is on, or a provider was
 *   added since, and nobody has confirmed), `paused` (a limit is reached) or `on`.
 *
 * `state` is the first thing that stops the assistant, or `ready`.
 *
 * `limit` has three reasons (`Usage::reached()`, `status()['usage']['reached']` and the `limit` of the 429 error `gate()` gives):
 * `day` and `month` (this site's own limits; the monthly one is held to the network's cap) and `network` (the network's
 * monthly AI budget is used, so the assistant is paused on every site, and no request is made).
 */
final class Availability {

	public const STATES = array( 'unsupported', 'locked', 'no_provider', 'off', 'needs_consent', 'limit', 'ready' );

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Whether this WordPress has the AI Client at all.
	 *
	 * @return bool
	 */
	public function supported(): bool {
		return version_compare( (string) get_bloginfo( 'version' ), '7.0', '>=' )
			&& function_exists( 'wp_supports_ai' )
			&& function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Whether WordPress says AI is switched on for this request (constant `WP_AI_SUPPORT` and the `wp_supports_ai` filter).
	 *
	 * @return bool
	 */
	public function wordpress_allows(): bool {
		return $this->supported() && wp_supports_ai();
	}

	/**
	 * The page where the owner connects a provider.
	 *
	 * @return string
	 */
	public function connectors_url(): string {
		return admin_url( 'options-connectors.php' );
	}

	/**
	 * The AI providers WordPress has connectors for, whether or not a network allows them.
	 *
	 * This reads no network policy. The network's own list of providers it can allow is built from it
	 * (`Modules\AiModule::network_providers()`), and a policy lookup here would ask that list again.
	 *
	 * @return array<int, array{id: string, name: string, family: string, installed: bool, plugin_url: string}>
	 */
	public function connectors(): array {
		if ( ! $this->supported() || ! function_exists( 'wp_get_connectors' ) ) {
			return array();
		}

		$families = array(
			'anthropic' => 'Claude',
			'google'    => 'Gemini',
			'openai'    => 'ChatGPT',
		);
		$out      = array();

		foreach ( wp_get_connectors() as $id => $connector ) {
			if ( ! is_array( $connector ) || 'ai_provider' !== ( $connector['type'] ?? '' ) ) {
				continue;
			}

			$id        = (string) $id;
			$installed = false;
			$active    = $connector['plugin']['is_active'] ?? null;

			if ( is_callable( $active ) ) {
				try {
					$installed = (bool) call_user_func( $active );
				} catch ( \Throwable $e ) {
					$installed = false;
				}
			}

			$out[] = array(
				'id'         => $id,
				'name'       => (string) ( $connector['name'] ?? ucwords( $id ) ),
				'family'     => $families[ $id ] ?? '',
				'installed'  => $installed,
				'plugin_url' => add_query_arg(
					array(
						'tab'  => 'search',
						'type' => 'term',
						's'    => 'AI Provider for ' . (string) ( $connector['name'] ?? $id ),
					),
					admin_url( 'plugin-install.php' )
				),
			);
		}

		return $out;
	}

	/**
	 * The AI providers WordPress knows, with whether each can be used here.
	 *
	 * @return array<int, array{id: string, name: string, family: string, installed: bool, allowed: bool, plugin_url: string}>
	 */
	public function providers(): array {
		$connectors = $this->connectors();

		if ( array() === $connectors ) {
			return array();
		}

		$policy = $this->plugin->make( Policy::class );
		$out    = array();

		foreach ( $connectors as $connector ) {
			$connector['allowed'] = $policy->allows_provider( $connector['id'] );
			$out[]                = $connector;
		}

		return $out;
	}

	/**
	 * The providers that are installed and that the network allows.
	 *
	 * @return array<int, array{id: string, name: string, family: string, installed: bool, allowed: bool, plugin_url: string}>
	 */
	public function usable_providers(): array {
		return array_values(
			array_filter(
				$this->providers(),
				static function ( array $provider ): bool {
					return $provider['installed'] && $provider['allowed'];
				}
			)
		);
	}

	/**
	 * The connection, apart from what the owner chose.
	 *
	 * @return string `unsupported`, `locked`, `no_provider` or `ok`.
	 */
	public function connection(): string {
		if ( ! $this->wordpress_allows() ) {
			return 'unsupported';
		}

		if ( $this->plugin->make( Policy::class )->locked() ) {
			return 'locked';
		}

		return array() === $this->usable_providers() ? 'no_provider' : 'ok';
	}

	/**
	 * The assistant, given a working connection.
	 *
	 * @return string `off`, `needs_consent`, `paused` or `on`.
	 */
	public function assistant(): string {
		$config  = $this->plugin->make( Config::class );
		$consent = $this->plugin->make( Consent::class );

		if ( ! $config->any_on() ) {
			return 'off';
		}

		if ( ! $config->enabled() || ! $consent->valid() ) {
			return 'needs_consent';
		}

		return '' !== $this->plugin->make( Usage::class )->reached() ? 'paused' : 'on';
	}

	/**
	 * The first thing that stops the assistant, or `ready`.
	 *
	 * @return string One of STATES.
	 */
	public function state(): string {
		$connection = $this->connection();

		if ( 'ok' !== $connection ) {
			return $connection;
		}

		$assistant = $this->assistant();

		if ( 'paused' === $assistant ) {
			return 'limit';
		}

		return 'on' === $assistant ? 'ready' : $assistant;
	}

	/**
	 * Whether the assistant could run for someone who opens a screen: connection fine, owner turned it on
	 * and confirmed. A reached limit does not hide the screens; they say it.
	 *
	 * @return bool
	 */
	public function usable(): bool {
		return in_array( $this->state(), array( 'ready', 'limit' ), true );
	}

	/**
	 * Whether one feature may send a request right now.
	 *
	 * Every request goes through `Client::ask()`, which calls this first. Nothing is sent when
	 * it returns an error.
	 *
	 * @param string $feature A key of Config::FEATURE_TOGGLE.
	 * @return true|\WP_Error
	 */
	public function gate( string $feature ) {
		$config = $this->plugin->make( Config::class );
		$state  = $this->state();

		$errors = array(
			'unsupported'   => array( 404, __( 'AI features need WordPress 7.0 or newer, with AI switched on.', 'estasoft-news-distribution' ) ),
			'locked'        => array( 403, __( 'Your network administrator has turned the AI assistant off for this site.', 'estasoft-news-distribution' ) ),
			'no_provider'   => array( 409, __( 'Connect an AI provider to use the assistant.', 'estasoft-news-distribution' ) ),
			'off'           => array( 403, __( 'The assistant is off. Turn it on in Settings, AI assistant.', 'estasoft-news-distribution' ) ),
			'needs_consent' => array( 403, __( 'The assistant needs your confirmation before it starts. Nothing is sent until then.', 'estasoft-news-distribution' ) ),
		);

		if ( isset( $errors[ $state ] ) ) {
			return new \WP_Error( 'estasoft_nd_ai_' . $state, $errors[ $state ][1], array( 'status' => $errors[ $state ][0] ) );
		}

		if ( ! $config->feature_on( $feature ) ) {
			return new \WP_Error( 'estasoft_nd_ai_feature_off', __( 'This feature is off. Turn it on in Settings, AI assistant.', 'estasoft-news-distribution' ), array( 'status' => 403 ) );
		}

		$reached = $this->plugin->make( Usage::class )->reached();

		if ( '' !== $reached ) {
			return $this->limit_error( $reached );
		}

		return true;
	}

	/**
	 * The error for a limit that is reached. Nothing was sent.
	 *
	 * @param string $reached `network`, `month` or `day`.
	 * @return \WP_Error Code `estasoft_nd_ai_limit`, status 429, and the reason in `data['limit']`.
	 */
	public function limit_error( string $reached ): \WP_Error {
		if ( 'network' === $reached ) {
			$message = __( 'The network’s monthly AI budget is used, so suggestions are paused on every site. Nothing else is affected.', 'estasoft-news-distribution' );
		} elseif ( 'month' === $reached ) {
			$message = __( 'The monthly token limit is reached, so suggestions are paused. Nothing else is affected.', 'estasoft-news-distribution' );
		} else {
			$message = __( 'The daily request limit is reached, so suggestions are paused until tomorrow. Nothing else is affected.', 'estasoft-news-distribution' );
		}

		return new \WP_Error(
			'estasoft_nd_ai_limit',
			$message,
			array(
				'status' => 429,
				'limit'  => $reached,
			)
		);
	}

	/**
	 * One sentence for a limit that is reached, for screens. It is empty when none is.
	 *
	 * @param string $reached `network`, `month`, `day` or an empty string.
	 * @return string
	 */
	public function limit_reason( string $reached ): string {
		switch ( $reached ) {
			case 'network':
				return __( 'The network’s monthly AI budget is used.', 'estasoft-news-distribution' );
			case 'month':
				return __( 'The monthly token limit of this site is used.', 'estasoft-news-distribution' );
			case 'day':
				return __( 'The daily request limit of this site is reached.', 'estasoft-news-distribution' );
			default:
				return '';
		}
	}

	/**
	 * Everything `GET /ai/status` says. It holds no key, no token and no provider answer.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$config  = $this->plugin->make( Config::class );
		$usage   = $this->plugin->make( Usage::class );
		$consent = $this->plugin->make( Consent::class );
		$policy  = $this->plugin->make( Policy::class );
		$record  = $consent->record();
		$reached = $usage->reached();
		$user    = null === $record ? null : get_userdata( $record['user'] );
		$probe   = $usage->probe();

		return array(
			'supported'      => $this->supported(),
			'wordpress'      => (string) get_bloginfo( 'version' ),
			'connection'     => $this->connection(),
			'assistant'      => $this->assistant(),
			'state'          => $this->state(),
			'ready'          => 'ready' === $this->state(),
			'providers'      => $this->providers(),
			'models'         => $config->models(),
			'consent'        => array(
				'recorded'  => null !== $record,
				'valid'     => $consent->valid(),
				'at'        => null === $record ? null : Clock::iso( $record['at'] ),
				'by'        => $user instanceof \WP_User ? $user->display_name : '',
				'providers' => null === $record ? array() : $record['providers'],
			),
			'features'       => array_combine(
				Config::TOGGLES,
				array_map(
					static function ( string $toggle ) use ( $config ): bool {
						return $config->toggle_on( $toggle );
					},
					Config::TOGGLES
				)
			),
			'drafts'         => $config->drafts(),
			'usage'          => array(
				'requests_today' => $usage->requests_today(),
				'daily_limit'    => $config->daily_limit(),
				'tokens_month'   => $usage->tokens_month(),
				'monthly_limit'  => $config->monthly_limit(),
				'reached'        => $reached,
				'reason'         => $this->limit_reason( $reached ),
				'resets_at'      => '' === $reached ? null : Clock::iso( $usage->resets_at( $reached ) ),
			),
			'policy'         => array(
				'network'         => $policy->exists(),
				'locked'          => $policy->locked(),
				'providers'       => $policy->providers(),
				'drafts_allowed'  => $policy->allows_drafts(),
				'authors_allowed' => $policy->allows_authors(),
				'token_cap'       => $policy->token_cap(),
				'budget_used'     => $policy->network_budget_used(),
				'second_approver' => $policy->second_approver(),
			),
			'last_test'      => array() === $probe ? null : array(
				'ok'       => (bool) $probe['ok'],
				'provider' => (string) $probe['provider'],
				'model'    => (string) $probe['model'],
				'ms'       => (int) $probe['ms'],
				'tokens'   => (int) $probe['tokens'],
				'reason'   => (string) $probe['reason'],
				'at'       => Clock::iso( (int) $probe['at'] ),
			),
			'connectors_url' => $this->connectors_url(),
		);
	}
}
