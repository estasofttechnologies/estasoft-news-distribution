<?php
/**
 * What the buttons on the Outlets page do.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Channels\OutletsScreen;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Channels\Outlets\AbstractOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\Attribution;
use Estasoft\NewsDistribution\Channels\Outlets\BlueskyOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\MastodonOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\Outcome;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Channels\Outlets\TelegramOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\WebhookOutlet;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Settings\SecretInput;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every button posts to the Outlets page (see AbstractTab::form()). A post is
 * checked (nonce, permission), does its work, leaves a message for the next
 * page view and answers with the address to go back to, so a reload never posts
 * again. Nothing here runs on publish: a request to another service leaves only
 * from a button a person pressed.
 *
 * Tokens, passwords and endpoint addresses need the administrator permission;
 * the other settings need the manage permission the page already needs.
 *
 * A service turns on only after its credentials pass a test that posts nothing.
 * One that fails stays off and the other service is not affected.
 */
final class Actions {

	public const NONCE = 'estasoft_nd_outlets';

	private OutletsScreen $screen;
	private OutletsChannel $channel;
	private Plugin $plugin;

	/**
	 * Creates the handler.
	 *
	 * @param OutletsScreen  $screen  The page.
	 * @param OutletsChannel $channel The channel.
	 * @param Plugin         $plugin  The running plugin.
	 */
	public function __construct( OutletsScreen $screen, OutletsChannel $channel, Plugin $plugin ) {
		$this->screen  = $screen;
		$this->channel = $channel;
		$this->plugin  = $plugin;
	}

	/**
	 * Does what the posted form asks and says where to go next.
	 *
	 * @return string The address to redirect to.
	 */
	public function run(): string {
		check_admin_referer( self::NONCE );

		$tab     = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'overview';
		$tab     = in_array( $tab, OutletsScreen::TABS, true ) ? $tab : 'overview';
		$do      = isset( $_POST['estasoft_nd_do'] ) ? sanitize_text_field( wp_unslash( $_POST['estasoft_nd_do'] ) ) : 'save';
		$webhook = isset( $_POST['webhook'] ) ? (string) preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['webhook'] ) ) ) : '';

		$parts = array_pad( explode( ':', $do, 3 ), 3, '' );
		$name  = (string) $parts[0];
		$one   = (string) $parts[1];
		$two   = (string) $parts[2];
		$args  = array();

		if ( 'save' === $name && '' !== $webhook ) {
			$name = 'webhook_save';
		}

		switch ( $name ) {
			case 'medium_on':
			case 'medium_off':
				$args = $this->medium_switch( 'medium_on' === $name );
				break;
			case 'medium_mark':
			case 'medium_unmark':
				$this->mark_story( (int) $one, 'medium_mark' === $name );
				break;
			case 'webhook_retry':
				$this->retry_webhook( $one );
				break;
			case 'webhook_save':
			case 'webhook_test':
			case 'webhook_remove':
				$tab  = 'overview';
				$args = $this->webhook( $name, $webhook );
				break;
			case 'save':
				$args = $this->save_tab( $tab );
				break;
			case 'telegram_on':
			case 'telegram_off':
			case 'telegram_test':
				$tab  = 'telegram';
				$args = $this->telegram( $name );
				break;
			case 'social_on':
			case 'mastodon_off':
			case 'bluesky_off':
			case 'test':
				$tab  = 'social';
				$args = $this->social( $name, $one, $two );
				break;
		}

		return $this->screen->url( $tab, $args );
	}

	/* ---------- Medium ---------- */

	/**
	 * Turns the list of stories to import on or off.
	 *
	 * @param bool $on Whether the list is on.
	 * @return array<string, scalar> Address arguments.
	 */
	private function medium_switch( bool $on ): array {
		$done = $this->save( array( 'medium_on' => $on ? '1' : '0' ) );

		if ( true !== $done ) {
			return $done;
		}

		Flash::add(
			'success',
			$on
				? __( 'The Medium list is on. Stories you publish from now on appear in it after the index-first delay.', 'estasoft-news-distribution' )
				: __( 'The Medium list is off.', 'estasoft-news-distribution' )
		);

		return array();
	}

	/**
	 * Marks a story as imported into Medium by hand, or clears the mark.
	 *
	 * @param int  $post_id  Article ID.
	 * @param bool $imported True to mark.
	 * @return void
	 */
	private function mark_story( int $post_id, bool $imported ): void {
		if ( $post_id < 1 || null === $this->plugin->reader()->find( $post_id ) ) {
			Flash::add( 'warning', __( 'That story is not published any more, so there is nothing to mark.', 'estasoft-news-distribution' ) );
			return;
		}

		$this->channel->state()->mark_imported( $post_id, $imported );
		$this->channel->reset();

		Flash::add(
			'success',
			$imported
				? __( 'Marked as imported. Only this list changed; nothing was sent to Medium.', 'estasoft-news-distribution' )
				: __( 'The mark was removed, so the story is in the list again.', 'estasoft-news-distribution' )
		);
	}

	/* ---------- Webhooks ---------- */

	/**
	 * Sends a failed webhook again.
	 *
	 * @param string $key Endpoint number.
	 * @return void
	 */
	private function retry_webhook( string $key ): void {
		$outcome = $this->channel->retry_webhook( preg_replace( '/[^0-9]/', '', $key ) );

		Flash::add(
			$outcome->is_sent() ? 'success' : 'danger',
			sprintf(
				/* translators: 1: the name of an endpoint, 2: what happened. */
				__( '%1$s: %2$s', 'estasoft-news-distribution' ),
				$outcome->target()->label(),
				$outcome->message()
			)
		);
	}

	/**
	 * Saves, tests or removes one endpoint.
	 *
	 * @param string $action webhook_save, webhook_test or webhook_remove.
	 * @param string $number Endpoint number.
	 * @return array<string, scalar> Address arguments.
	 */
	private function webhook( string $action, string $number ): array {
		$webhook = $this->channel->outlet( 'webhook' );

		if ( ! $webhook instanceof WebhookOutlet || '' === $number || (int) $number < 1 || (int) $number > $webhook->slots() ) {
			Flash::add( 'warning', __( 'That endpoint does not exist.', 'estasoft-news-distribution' ) );
			return array();
		}

		$stay = array( 'webhook' => $number );

		if ( 'webhook_remove' === $action ) {
			if ( ! $this->can_change_secrets() ) {
				Flash::add( 'danger', __( 'Only an administrator can remove an endpoint.', 'estasoft-news-distribution' ) );
				return $stay;
			}

			$done = $this->save(
				array(
					'webhook_' . $number . '_name'        => '',
					'webhook_' . $number . '_on'          => '0',
					'webhook_' . $number . '_url__remove' => '1',
					'webhook_' . $number . '_secret__remove' => '1',
				)
			);

			if ( true !== $done ) {
				return $stay + $done;
			}

			$this->channel->state()->forget_webhook( $number );
			Flash::add( 'success', __( 'The endpoint was removed.', 'estasoft-news-distribution' ) );

			return array();
		}

		$posted = $this->posted( $this->webhook_fields( $number ) );
		$input  = $posted;

		if ( ! isset( $input[ 'webhook_' . $number . '_on' ] ) ) {
			$input[ 'webhook_' . $number . '_on' ] = '0';
		}

		$changed = $this->has_text( $posted, 'webhook_' . $number . '_url' ) || $this->has_text( $posted, 'webhook_' . $number . '_secret' ) || ! empty( $posted[ 'webhook_' . $number . '_url__remove' ] ) || ! empty( $posted[ 'webhook_' . $number . '_secret__remove' ] );
		$done    = $this->save( $input );

		if ( true !== $done ) {
			return $stay + $done;
		}

		if ( $changed ) {
			$this->channel->state()->forget_webhook( $number );
		}

		$target = null;

		foreach ( $webhook->targets( $this->channel->settings(), array() ) as $candidate ) {
			if ( $candidate->key() === $number ) {
				$target = $candidate;
			}
		}

		if ( null !== $target && $target->is_on() && ! $target->is_complete() ) {
			$this->save( array( 'webhook_' . $number . '_on' => '0' ) );
			Flash::add( 'warning', __( 'The endpoint is saved but stays off: it needs an address and a signing secret before it can send.', 'estasoft-news-distribution' ) );

			return $stay;
		}

		if ( 'webhook_test' === $action ) {
			$outcome = $this->channel->run_test( 'webhook', $number, true );

			Flash::add(
				$outcome->is_sent() ? 'success' : 'danger',
				$outcome->is_sent()
					? sprintf(
						/* translators: %s: what the endpoint answered, such as 200 OK. */
						__( 'Test passed. The endpoint answered %s.', 'estasoft-news-distribution' ),
						$outcome->message()
					)
					: $outcome->message()
			);

			return $stay;
		}

		Flash::add( 'success', __( 'The endpoint was saved.', 'estasoft-news-distribution' ) );

		return array();
	}

	/**
	 * The setting names one endpoint form may post.
	 *
	 * @param string $number Endpoint number.
	 * @return string[]
	 */
	private function webhook_fields( string $number ): array {
		return array(
			'webhook_' . $number . '_name',
			'webhook_' . $number . '_url',
			'webhook_' . $number . '_secret',
			'webhook_' . $number . '_on',
		);
	}

	/* ---------- Saving a tab ---------- */

	/**
	 * Saves a tab's form and tests the credentials of a service that is on and changed.
	 *
	 * @param string $tab The tab.
	 * @return array<string, scalar> Address arguments.
	 */
	private function save_tab( string $tab ): array {
		if ( 'attribution' === $tab ) {
			return $this->save_attribution();
		}

		if ( 'telegram' === $tab ) {
			return $this->telegram( 'save' );
		}

		if ( 'social' === $tab ) {
			return $this->social( 'save', '', '' );
		}

		return array();
	}

	/**
	 * Saves the attribution lines and says which ones carry no link.
	 *
	 * @return array<string, scalar> Address arguments.
	 */
	private function save_attribution(): array {
		$input = $this->posted( array( 'attribution' ) );

		if ( isset( $input['attribution'] ) && is_array( $input['attribution'] ) ) {
			$kept   = (array) ( $this->channel->settings()['attribution'] ?? array() );
			$posted = array();

			foreach ( $input['attribution'] as $code => $line ) {
				if ( is_scalar( $line ) ) {
					$posted[ (string) $code ] = (string) $line;
				}
			}

			// A language that was not on the form keeps the line it had.
			$input['attribution'] = array_merge( $kept, $posted );
		}

		$done = $this->save( $input );

		if ( true !== $done ) {
			return $done;
		}

		$saved   = (array) ( $this->channel->settings()['attribution'] ?? array() );
		$no_link = array();

		foreach ( $this->channel->site_languages() as $code ) {
			$line = Attribution::line( $code, $saved );

			if ( '' !== $line && ! Attribution::has_token( $line ) ) {
				$no_link[] = Catalog::name( $code );
			}
		}

		Flash::add( 'success', __( 'Attribution lines saved.', 'estasoft-news-distribution' ) );

		if ( array() !== $no_link ) {
			Flash::add(
				'warning',
				sprintf(
					/* translators: 1: how many lines, 2: the languages, such as English and Arabic, 3: the placeholder {site}. */
					_n( 'The line for %2$s has no %3$s, so it is saved as written and carries no link.', 'The lines for %2$s have no %3$s, so they are saved as written and carry no link.', count( $no_link ), 'estasoft-news-distribution' ),
					count( $no_link ),
					wp_sprintf( '%l', $no_link ),
					Attribution::TOKEN
				)
			);
		}

		return array();
	}

	/**
	 * Saves the Telegram form, then does what the button asked.
	 *
	 * @param string $action save, telegram_on, telegram_off or telegram_test.
	 * @return array<string, scalar> Address arguments.
	 */
	private function telegram( string $action ): array {
		$before  = $this->channel->settings();
		$posted  = $this->posted( $this->tab_fields( 'telegram' ) );
		$changed = $this->changed_languages( 'telegram', $posted, $before );
		$done    = $this->save( $posted );

		if ( true !== $done ) {
			return $done;
		}

		foreach ( $changed as $code ) {
			$this->channel->state()->forget_test( 'telegram:' . $code );
		}

		if ( 'telegram_off' === $action ) {
			return $this->switch_off( 'telegram' );
		}

		if ( 'telegram_on' === $action ) {
			return $this->turn_on( 'telegram', $changed );
		}

		if ( 'telegram_test' === $action ) {
			$sent = false;

			foreach ( $this->channel->outlet( 'telegram' )->targets( $this->channel->settings(), $this->channel->site_languages() ) as $target ) {
				if ( $target->is_complete() ) {
					$sent = true;
					$this->flash_test( $target->language(), $this->channel->run_test( 'telegram', $target->key(), true ) );
				}
			}

			if ( ! $sent ) {
				Flash::add( 'warning', __( 'Nothing to test yet: enter the token and at least one channel first.', 'estasoft-news-distribution' ) );
			}

			return array();
		}

		Flash::add( 'success', __( 'Settings saved.', 'estasoft-news-distribution' ) );

		return $this->recheck( 'telegram', $changed );
	}

	/**
	 * Saves the Mastodon and Bluesky form, then does what the button asked.
	 *
	 * @param string $action save, social_on, mastodon_off, bluesky_off or test.
	 * @param string $outlet For a test, the service.
	 * @param string $code   For a test, the language.
	 * @return array<string, scalar> Address arguments.
	 */
	private function social( string $action, string $outlet, string $code ): array {
		$before  = $this->channel->settings();
		$posted  = $this->posted( $this->tab_fields( 'social' ) );
		$changed = array(
			'mastodon' => $this->changed_languages( 'mastodon', $posted, $before ),
			'bluesky'  => $this->changed_languages( 'bluesky', $posted, $before ),
		);
		$done    = $this->save( $posted );

		if ( true !== $done ) {
			return $done;
		}

		foreach ( $changed as $id => $codes ) {
			foreach ( $codes as $language ) {
				$this->channel->state()->forget_test( $id . ':' . $language );
			}
		}

		if ( $this->differs( $posted, $before, 'mastodon_server', array( $this, 'origin' ) ) ) {
			// The limit that was read belonged to the old server.
			$this->channel->state()->forget_limit( 'mastodon' );
		}

		if ( 'mastodon_off' === $action ) {
			return $this->switch_off( 'mastodon' );
		}

		if ( 'bluesky_off' === $action ) {
			return $this->switch_off( 'bluesky' );
		}

		if ( 'social_on' === $action ) {
			$this->turn_on( 'mastodon', $changed['mastodon'] );
			$this->turn_on( 'bluesky', $changed['bluesky'] );

			return array();
		}

		if ( 'test' === $action && in_array( $outlet, array( 'mastodon', 'bluesky' ), true ) ) {
			$this->flash_test( $code, $this->channel->run_test( $outlet, $code, true ) );

			return array();
		}

		Flash::add( 'success', __( 'Settings saved.', 'estasoft-news-distribution' ) );

		foreach ( array( 'mastodon', 'bluesky' ) as $id ) {
			$this->recheck( $id, $changed[ $id ] );
		}

		return array();
	}

	/**
	 * After a save: a service that is on and whose credentials changed is tested again; one that fails goes off.
	 *
	 * @param string   $outlet  Outlet id.
	 * @param string[] $changed Languages whose credentials changed.
	 * @return array<string, scalar> Address arguments.
	 */
	private function recheck( string $outlet, array $changed ): array {
		$service = $this->channel->outlet( $outlet );

		if ( null === $service || array() === $changed || ! $service->is_on( $this->channel->settings() ) ) {
			return array();
		}

		$failed = $this->test_changed( $outlet, $changed );

		if ( null !== $failed ) {
			$this->switch_off( $outlet );
			Flash::add(
				'danger',
				sprintf(
					/* translators: 1: a service name such as Bluesky, 2: a language name such as Arabic, 3: what went wrong. */
					__( '%1$s was turned off because the test for %2$s failed. %3$s', 'estasoft-news-distribution' ),
					$service->label(),
					Catalog::name( $failed['language'] ),
					$failed['message']
				)
			);
		}

		return array();
	}

	/**
	 * Turns a service on after its credentials pass a test that posts nothing.
	 *
	 * @param string   $outlet  Outlet id.
	 * @param string[] $changed Languages whose credentials changed in this save.
	 * @return array<string, scalar> Address arguments.
	 */
	private function turn_on( string $outlet, array $changed ): array {
		$service  = $this->channel->outlet( $outlet );
		$settings = $this->channel->settings();

		if ( null === $service ) {
			return array();
		}

		$ready = array();

		foreach ( $service->targets( $settings, $this->channel->site_languages() ) as $target ) {
			if ( $target->is_complete() ) {
				$ready[] = $target->language();
			}
		}

		if ( array() === $ready ) {
			Flash::add(
				'warning',
				'telegram' === $outlet
					? __( 'Telegram stays off: it needs the token and at least one channel.', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: a service name such as Mastodon. */
						__( '%s stays off: it needs an account first.', 'estasoft-news-distribution' ),
						$service->label()
					)
			);

			return array();
		}

		// A target that never passed a test is tested too, so a first "turn on" proves the connection.
		$to_test = array();

		foreach ( $ready as $language ) {
			$known = $this->channel->state()->test( $outlet . ':' . $language );

			if ( in_array( $language, $changed, true ) || array() === $known || empty( $known['ok'] ) ) {
				$to_test[] = $language;
			}
		}

		$failed = $this->test_changed( $outlet, $to_test );

		if ( null !== $failed ) {
			Flash::add(
				'danger',
				sprintf(
					/* translators: 1: a service name such as Bluesky, 2: a language name such as Arabic, 3: what went wrong. */
					__( '%1$s stays off because the test for %2$s failed. %3$s', 'estasoft-news-distribution' ),
					$service->label(),
					Catalog::name( $failed['language'] ),
					$failed['message']
				)
			);

			return array();
		}

		$done = $this->save( array( $outlet . '_on' => '1' ) );

		if ( true !== $done ) {
			return $done;
		}

		Flash::add(
			'success',
			'telegram' === $outlet
				? sprintf(
					/* translators: %d: how many channels. */
					_n( 'Telegram is on for %d channel.', 'Telegram is on for %d channels.', count( $ready ), 'estasoft-news-distribution' ),
					count( $ready )
				)
				: ServiceLine::sentence( $this->channel, $service )
		);

		return array();
	}

	/**
	 * Tests the accounts of a service without posting. The first failure stops the run.
	 *
	 * @param string   $outlet    Outlet id.
	 * @param string[] $languages Languages to test.
	 * @return array{language: string, message: string}|null The failure, or null when every test passed.
	 */
	private function test_changed( string $outlet, array $languages ): ?array {
		foreach ( $languages as $language ) {
			$outcome = $this->channel->run_test( $outlet, (string) $language, false );

			if ( ! $outcome->is_sent() ) {
				return array(
					'language' => (string) $language,
					'message'  => $outcome->message(),
				);
			}
		}

		return null;
	}

	/**
	 * Turns a service off.
	 *
	 * @param string $outlet Outlet id.
	 * @return array<string, scalar> Address arguments.
	 */
	private function switch_off( string $outlet ): array {
		$service = $this->channel->outlet( $outlet );
		$done    = $this->save( array( $outlet . '_on' => '0' ) );

		if ( true !== $done ) {
			return $done;
		}

		Flash::add(
			'info',
			sprintf(
				/* translators: %s: a service name such as Mastodon. */
				__( '%s is off. Nothing is posted there until you turn it on again.', 'estasoft-news-distribution' ),
				null === $service ? $outlet : $service->label()
			)
		);

		return array();
	}

	/**
	 * Says what a test did, for one language.
	 *
	 * @param string  $language Language code.
	 * @param Outcome $outcome  The result.
	 * @return void
	 */
	private function flash_test( string $language, Outcome $outcome ): void {
		Flash::add(
			$outcome->is_sent() ? 'success' : 'danger',
			sprintf(
				/* translators: 1: a language name such as English, 2: what happened. */
				__( '%1$s: %2$s', 'estasoft-news-distribution' ),
				Catalog::name( $language ),
				$outcome->message()
			)
		);
	}

	/* ---------- Reading and saving the form ---------- */

	/**
	 * The setting names a tab's form may post, without the on/off switches.
	 *
	 * @param string $tab telegram or social.
	 * @return string[]
	 */
	private function tab_fields( string $tab ): array {
		$names = array_keys( $this->channel->fields() );
		$out   = array();

		foreach ( $names as $name ) {
			$name = (string) $name;

			if ( 'telegram' === $tab && 0 === strpos( $name, 'telegram_' ) && 'telegram_on' !== $name ) {
				$out[] = $name;
			}

			if ( 'social' === $tab && ( 0 === strpos( $name, 'social_' ) || ( 0 === strpos( $name, 'mastodon_' ) && 'mastodon_on' !== $name ) || ( 0 === strpos( $name, 'bluesky_' ) && 'bluesky_on' !== $name ) ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * What the form posted, limited to the settings it may change. A person who may not change secrets
	 * has the secrets taken out, with a message.
	 *
	 * @param string[] $allowed Setting names.
	 * @return array<string, mixed>
	 */
	private function posted( array $allowed ): array {
		$raw = Field::posted( $this->channel->fields(), self::NONCE );

		$secrets = array_keys( SecretInput::fields( $this->channel->fields() ) );
		$input   = array();

		foreach ( $allowed as $name ) {
			if ( array_key_exists( $name, $raw ) ) {
				$input[ $name ] = $raw[ $name ];
			}

			if ( in_array( $name, $secrets, true ) && array_key_exists( $name . '__remove', $raw ) ) {
				$input[ $name . '__remove' ] = $raw[ $name . '__remove' ];
			}
		}

		if ( ! $this->can_change_secrets() ) {
			$touched = false;

			foreach ( $secrets as $secret ) {
				foreach ( array( $secret, $secret . '__remove' ) as $key ) {
					if ( isset( $input[ $key ] ) && '' !== $input[ $key ] && '0' !== $input[ $key ] ) {
						$touched = true;
					}

					unset( $input[ $key ] );
				}
			}

			// An address that the tokens are sent to is kept like a token.
			foreach ( SecretInput::moved( $this->channel->fields(), $input, $this->channel->settings() ) as $moved ) {
				unset( $input[ $moved ] );
				$touched = true;
			}

			if ( $touched ) {
				Flash::add( 'warning', __( 'Only an administrator can change tokens, passwords and addresses. Those were not changed.', 'estasoft-news-distribution' ) );
			}
		}

		return $input;
	}

	/**
	 * Whether a posted value is a non-empty text.
	 *
	 * @param array<string, mixed> $input Posted values.
	 * @param string               $name  Setting name.
	 * @return bool
	 */
	private function has_text( array $input, string $name ): bool {
		return isset( $input[ $name ] ) && is_scalar( $input[ $name ] ) && '' !== trim( (string) $input[ $name ] );
	}

	/**
	 * Whether the person may change tokens, passwords and addresses that are kept secret.
	 *
	 * @return bool
	 */
	private function can_change_secrets(): bool {
		return $this->screen->can_change_secrets();
	}

	/**
	 * The languages whose credentials this save changes: a new token or password, or a different
	 * channel, handle or server.
	 *
	 * @param string               $outlet Outlet id.
	 * @param array<string, mixed> $input  Posted values.
	 * @param array<string, mixed> $before The settings before.
	 * @return string[]
	 */
	private function changed_languages( string $outlet, array $input, array $before ): array {
		$languages = $this->channel->site_languages();
		$changed   = array();

		foreach ( $languages as $code ) {
			$suffix = AbstractOutlet::suffix( $code );
			$typed  = false;

			if ( 'telegram' === $outlet ) {
				$typed = $this->has_text( $input, 'telegram_token' ) || $this->differs( $input, $before, 'telegram_chat_' . $suffix, array( TelegramOutlet::class, 'normalise_chat' ) );
			} elseif ( 'mastodon' === $outlet ) {
				$typed = $this->has_text( $input, 'mastodon_token_' . $suffix ) || $this->differs( $input, $before, 'mastodon_server', array( $this, 'origin' ) );
			} elseif ( 'bluesky' === $outlet ) {
				$typed = $this->has_text( $input, 'bluesky_password_' . $suffix ) || $this->differs( $input, $before, 'bluesky_handle_' . $suffix, array( BlueskyOutlet::class, 'normalise_handle' ) );
			}

			if ( $typed ) {
				$changed[] = $code;
			}
		}

		return $changed;
	}

	/**
	 * Whether a posted text differs from the saved one once both are cleaned the same way.
	 *
	 * @param array<string, mixed> $input  Posted values.
	 * @param array<string, mixed> $before The settings before.
	 * @param string               $name   Setting name.
	 * @param callable             $clean  Cleans a text.
	 * @return bool
	 */
	private function differs( array $input, array $before, string $name, callable $clean ): bool {
		if ( ! array_key_exists( $name, $input ) || ! is_scalar( $input[ $name ] ) ) {
			return false;
		}

		return (string) call_user_func( $clean, (string) $input[ $name ] ) !== (string) call_user_func( $clean, (string) ( $before[ $name ] ?? '' ) );
	}

	/**
	 * A Mastodon server as its origin.
	 *
	 * @param string $text What was typed.
	 * @return string
	 */
	public function origin( string $text ): string {
		return MastodonOutlet::server( array( 'mastodon_server' => $text ) );
	}

	/**
	 * Checks what was typed beyond the settings' own rules, and cleans it.
	 *
	 * @param array<string, mixed> $input Posted values, cleaned in place.
	 * @return array<string, string> Messages by setting name.
	 */
	private function check( array &$input ): array {
		$errors = array();

		foreach ( $input as $name => $value ) {
			if ( ! is_string( $value ) ) {
				continue;
			}

			$name = (string) $name;

			if ( 0 === strpos( $name, 'telegram_chat_' ) ) {
				$clean = TelegramOutlet::normalise_chat( $value );

				if ( '' !== $clean && ! TelegramOutlet::is_valid_chat( $clean ) ) {
					$errors[ $name ] = __( 'Use @name for a public channel, or the channel’s number for a private one (it starts with -100).', 'estasoft-news-distribution' );
				}

				$input[ $name ] = $clean;
			} elseif ( 0 === strpos( $name, 'bluesky_handle_' ) ) {
				$clean = BlueskyOutlet::normalise_handle( $value );

				if ( '' !== $clean && ! BlueskyOutlet::is_valid_handle( $clean ) ) {
					$errors[ $name ] = __( 'A Bluesky handle looks like name.bsky.social.', 'estasoft-news-distribution' );
				}

				$input[ $name ] = $clean;
			} elseif ( 'mastodon_server' === $name ) {
				$clean = '' === trim( $value ) ? '' : MastodonOutlet::server( array( 'mastodon_server' => $value ) );

				if ( '' !== trim( $value ) && '' === $clean ) {
					$errors[ $name ] = __( 'Enter your server’s address, such as https://mastodon.example.', 'estasoft-news-distribution' );
				}

				$input[ $name ] = $clean;
			} elseif ( 1 === preg_match( '/^webhook_\d+_url$/', $name ) && '' !== trim( $value ) ) {
				$parts = wp_parse_url( trim( $value ) );

				if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] ) ) {
					$errors[ $name ] = __( 'The address must be a full web address that starts with https://.', 'estasoft-news-distribution' );
				}
			} elseif ( 1 === preg_match( '/^webhook_\d+_secret$/', $name ) && '' !== trim( $value ) && mb_strlen( trim( $value ) ) < 16 ) {
				$errors[ $name ] = __( 'Use at least 16 characters, so the signature cannot be guessed.', 'estasoft-news-distribution' );
			}
		}

		return $errors;
	}

	/**
	 * Saves settings of the channel. A refusal is kept to show again on the page.
	 *
	 * @param array<string, mixed> $input Values to save.
	 * @return true|array<string, scalar> True when saved, else the address arguments that show the refusal.
	 */
	private function save( array $input ) {
		$errors = $this->check( $input );

		if ( array() !== $errors ) {
			return $this->reject( $input, $errors );
		}

		$saved = $this->plugin->channel_settings()->save( $this->channel->id(), $this->channel->fields(), $input );

		if ( is_wp_error( $saved ) ) {
			$data = $saved->get_error_data();

			return $this->reject( $input, is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array( $this->channel->id() => $saved->get_error_message() ) );
		}

		$this->channel->reset();

		// The stored switch follows the services, for anything that reads it directly.
		$this->plugin->channel_settings()->set_enabled( $this->channel->id(), array() !== $this->channel->active_ids() );

		return true;
	}

	/**
	 * Keeps a refused form to show it again with its messages. Secrets are never kept.
	 *
	 * @param array<string, mixed>  $input  What was typed.
	 * @param array<string, string> $errors Messages by setting name.
	 * @return array<string, scalar> Address arguments.
	 */
	private function reject( array $input, array $errors ): array {
		foreach ( array_keys( SecretInput::fields( $this->channel->fields() ) ) as $secret ) {
			unset( $input[ $secret ], $input[ $secret . '__remove' ] );
		}

		set_transient(
			Admin::FORM_KEY . get_current_user_id(),
			array(
				'section' => $this->screen->scope_id(),
				'values'  => $input,
				'errors'  => $errors,
			),
			2 * MINUTE_IN_SECONDS
		);

		Flash::add( 'danger', __( 'Nothing was saved. Fix the highlighted settings and save again.', 'estasoft-news-distribution' ) );

		return array( 'error' => 1 );
	}
}
