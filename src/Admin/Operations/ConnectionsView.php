<?php
/**
 * Settings, Connections: the cards.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Admin\Ui\AddressRow;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Connections;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Integrations\Connections\ConnectionInterface;
use Estasoft\NewsDistribution\Integrations\OAuth\Providers;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * One card for each search engine that can be connected, a card saying Baidu is
 * not connected, and the two note cards. Every button is a form post, so the page
 * works without JavaScript. The credentials are never printed: a saved one shows
 * as a mask.
 *
 * Google Search Console and Yandex Webmaster offer two ways to connect, one under the other and
 * both closed until opened: a sign-in with the owner's own app (the redirect address to register,
 * the client ID and client secret, the sign-in button), and the key or token that was the only way
 * in round 1.
 */
final class ConnectionsView {

	private Plugin $plugin;

	private Connections $connections;

	/**
	 * Creates the view.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin      = $plugin;
		$this->connections = $plugin->make( Connections::class );
	}

	/**
	 * The page body.
	 *
	 * @param array<string, mixed> $values The section's values as the screen shows them (secrets are masks).
	 * @return string Escaped HTML.
	 */
	public function html( array $values ): string {
		$html = Flash::take();

		foreach ( $this->connections->all() as $engine ) {
			$html .= $this->card( $engine, $values );
		}

		$html .= Card::flush(
			'connections-baidu',
			__( 'Baidu Webmaster Tools', 'estasoft-news-distribution' ),
			__( 'News Distribution does not connect to Baidu, so Baidu stays hand-set. Follow the steps on the Baidu Webmaster Tools channel.', 'estasoft-news-distribution' ),
			''
		);

		return $html;
	}

	/**
	 * The steps and the limits, printed side by side after the cards.
	 *
	 * @return string
	 */
	public static function notes(): string {
		$steps = array(
			__( 'You make an access credential in the engine’s own account and paste it here. Or, for Google and Yandex, you register an app of your own there, paste its client ID and client secret, and sign in. News Distribution never sees your password.', 'estasoft-news-distribution' ),
			__( 'It is checked with the engine first, and saved only when the engine accepts it. It is stored in your database, is never shown again, and is left out of every export and restore point.', 'estasoft-news-distribution' ),
			__( 'It is sent only to that engine and its sign-in service, and never to Estasoft.', 'estasoft-news-distribution' ),
			__( 'Disconnect removes it, asks Google or Yandex to cancel a sign-in where they can, and statuses go back to hand-set.', 'estasoft-news-distribution' ),
		);

		$list = '';

		foreach ( $steps as $step ) {
			$list .= Html::tag( 'li', array(), esc_html( $step ) );
		}

		$how = Card::note(
			'connections-how',
			__( 'How connecting works', 'estasoft-news-distribution' ),
			Html::tag( 'ol', array( 'class' => 'estasoft-nd-ops-steps' ), $list )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Connecting is optional. Everything works without it. It happens only when you press Connect, Sign in or Check now, never when you publish.', 'estasoft-news-distribution' ) )
		);

		$cannot = Card::cannot(
			array(
				__( 'Read or change anything in an engine’s account beyond sitemaps.', 'estasoft-news-distribution' ),
				__( 'Sign you in without an app of your own. It has no app registered with Google or Yandex, and a client secret cannot ship inside a plugin.', 'estasoft-news-distribution' ),
				__( 'See whether Google News, Bing or Yandex lists an article. It sees only the sitemap status the engine reports.', 'estasoft-news-distribution' ),
				__( 'Connect to Baidu.', 'estasoft-news-distribution' ),
			)
		);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $how . $cannot );
	}

	/**
	 * What each engine's card says. Plain text, translated.
	 *
	 * @param string $id Engine id.
	 * @return array{blurb: string, key_way: string, secret: string, sent: string, says: string, mapping: string, help: string, plain: array<string, array{label: string, help: string}>, multiline: bool}
	 */
	private function words( string $id ): array {
		switch ( $id ) {
			case 'yandex-webmaster':
				return array(
					'blurb'     => __( 'With Yandex connected, News Distribution can add your sitemaps and read the status Yandex shows for each one, so the status you see is Yandex’s own. Without it, you add them in Yandex Webmaster yourself and tick the step on the channel.', 'estasoft-news-distribution' ),
					'key_way'   => __( 'Paste an access token', 'estasoft-news-distribution' ),
					'secret'    => __( 'Access token', 'estasoft-news-distribution' ),
					'sent'      => __( 'Sent to Yandex, using the token you paste or the sign-in you make: the sitemap addresses to add. Received: what Yandex knows about each sitemap.', 'estasoft-news-distribution' ),
					'says'      => __( 'Yandex says', 'estasoft-news-distribution' ),
					'mapping'   => __( 'Yandex gives no status word for a sitemap, so its own facts are shown as Yandex gives them. News Distribution maps them: read without errors to Healthy, not read yet to Processing, an error to Needs attention with a link to the details in Yandex.', 'estasoft-news-distribution' ),
					'help'      => __( 'Make an access token for the Yandex Webmaster API in your Yandex account, with permission to read your site and add sitemaps, and paste it here.', 'estasoft-news-distribution' ),
					'plain'     => array(),
					'multiline' => false,
				);

			case 'google-search-console':
				return array(
					'blurb'     => __( 'News Distribution can submit your sitemaps and read what Search Console shows for them: when Google last read each one, and any errors or warnings. It cannot see whether an article appears in Google News. Without it, you submit the sitemaps in Search Console yourself.', 'estasoft-news-distribution' ),
					'key_way'   => __( 'Paste a service account key', 'estasoft-news-distribution' ),
					'secret'    => __( 'Service account key', 'estasoft-news-distribution' ),
					'sent'      => __( 'Sent to Google, using the key you paste or the sign-in you make: the sitemap addresses to submit. Received: the status of each sitemap.', 'estasoft-news-distribution' ),
					'says'      => __( 'Google says', 'estasoft-news-distribution' ),
					'mapping'   => __( 'Google’s own words are shown as Google gives them. News Distribution maps them: read without errors to Healthy, waiting to be read to Processing, an error to Needs attention with a link to the details in Search Console.', 'estasoft-news-distribution' ),
					'help'      => __( 'Paste the whole key file (JSON) of a Google Cloud service account that you added as a user of your property in Search Console.', 'estasoft-news-distribution' ),
					'plain'     => array(
						'google_property' => array(
							'label' => __( 'Search Console property', 'estasoft-news-distribution' ),
							'help'  => __( 'Optional. Leave it empty to use your site address. For a domain property, write it as sc-domain:example.com.', 'estasoft-news-distribution' ),
						),
					),
					'multiline' => true,
				);

			default:
				return array(
					'blurb'     => __( 'News Distribution can submit your sitemaps to Bing and read the list of sitemaps Bing has for your site. It cannot see whether an article is indexed. Without it, you submit the sitemaps in Bing Webmaster Tools yourself.', 'estasoft-news-distribution' ),
					'key_way'   => __( 'Connect', 'estasoft-news-distribution' ),
					'secret'    => __( 'API key', 'estasoft-news-distribution' ),
					'sent'      => __( 'Sent to Bing, using the key you paste: the sitemap addresses to submit. Received: the list of your sitemaps.', 'estasoft-news-distribution' ),
					'says'      => __( 'Bing says', 'estasoft-news-distribution' ),
					'mapping'   => __( 'Bing’s own words are shown as Bing gives them. News Distribution maps them as far as it can: an error to Needs attention, a sitemap Bing has read to Healthy, one it has not read yet to Processing.', 'estasoft-news-distribution' ),
					'help'      => __( 'Make an API key in Bing Webmaster Tools, in Settings, API access, and paste it here.', 'estasoft-news-distribution' ),
					'plain'     => array(
						'bing_site_url' => array(
							'label' => __( 'Site address in Bing', 'estasoft-news-distribution' ),
							'help'  => __( 'Optional. Leave it empty to use your site address, as Bing Webmaster Tools lists it.', 'estasoft-news-distribution' ),
						),
					),
					'multiline' => false,
				);
		}
	}

	/**
	 * One engine's card.
	 *
	 * @param ConnectionInterface  $engine The engine.
	 * @param array<string, mixed> $values The section's values.
	 * @return string
	 */
	private function card( ConnectionInterface $engine, array $values ): string {
		$id        = $engine->id();
		$words     = $this->words( $id );
		$mode      = $this->connections->mode( $id );
		$connected = Connections::MODE_NONE !== $mode;
		$state     = $this->connections->state( $id );
		$actions   = '';

		if ( ! $connected ) {
			$actions = StatusView::inline( Status::NOT_SET_UP );
		} elseif ( $state['checked_on'] > 0 && ! $state['ok'] ) {
			$actions = StatusView::inline( Status::ATTENTION );
		}

		$body = $connected ? $this->connected_body( $engine, $words, $state, $values, $mode ) : $this->unconnected_body( $engine, $words );

		return Card::flush( 'connections-' . $id, $engine->label(), $words['blurb'], $body, $actions );
	}

	/**
	 * The body of a card that has no credential yet: what is sent, and a Connect form that opens in place.
	 *
	 * @param ConnectionInterface  $engine The engine.
	 * @param array<string, mixed> $words  The card's words.
	 * @return string
	 */
	private function unconnected_body( ConnectionInterface $engine, array $words ): string {
		$id    = $engine->id();
		$field = $engine->secret_field();
		$input = $words['multiline']
			? Html::tag(
				'textarea',
				array(
					'class'            => 'estasoft-nd-field estasoft-nd-ops-key',
					'id'               => 'estasoft-nd-conn-' . $id,
					'name'             => 'credential',
					'rows'             => 6,
					'autocomplete'     => 'off',
					'spellcheck'       => 'false',
					'dir'              => 'ltr',
					'aria-describedby' => 'estasoft-nd-conn-' . $id . '-help',
				),
				''
			)
			: Html::void(
				'input',
				array(
					'class'            => 'estasoft-nd-field estasoft-nd-ops-key',
					'id'               => 'estasoft-nd-conn-' . $id,
					'type'             => 'password',
					'name'             => 'credential',
					'value'            => '',
					'autocomplete'     => 'new-password',
					'dir'              => 'ltr',
					'aria-describedby' => 'estasoft-nd-conn-' . $id . '-help',
				)
			);

		$fields = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => 'estasoft-nd-conn-' . $id,
				),
				esc_html( $words['secret'] )
			)
			. $input
			. Html::tag(
				'p',
				array(
					'class' => 'estasoft-nd-muted estasoft-nd-small',
					'id'    => 'estasoft-nd-conn-' . $id . '-help',
				),
				esc_html( $words['help'] )
			)
		);

		foreach ( $words['plain'] as $name => $plain ) {
			$fields .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => 'estasoft-nd-conn-' . $id . '-' . $name,
					),
					esc_html( $plain['label'] )
				)
				. Html::void(
					'input',
					array(
						'class'            => 'estasoft-nd-field',
						'id'               => 'estasoft-nd-conn-' . $id . '-' . $name,
						'type'             => 'text',
						'name'             => $name,
						'value'            => '',
						'dir'              => 'ltr',
						'aria-describedby' => 'estasoft-nd-conn-' . $id . '-' . $name . '-help',
					)
				)
				. Html::tag(
					'p',
					array(
						'class' => 'estasoft-nd-muted estasoft-nd-small',
						'id'    => 'estasoft-nd-conn-' . $id . '-' . $name . '-help',
					),
					esc_html( $plain['help'] )
				)
			);
		}

		$form = Forms::post(
			'estasoft_nd_connection',
			Forms::connection_nonce(),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $fields )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Button::submit(
					__( 'Connect', 'estasoft-news-distribution' ),
					array(
						'variant' => 'primary',
						'attrs'   => array(
							'name'  => 'op',
							'value' => 'connect',
						),
					)
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The engine is asked first. The credential is saved only if the engine accepts it.', 'estasoft-news-distribution' ) )
			),
			array( 'data-ops-form' => 'connect' ),
			array( 'engine' => $id )
		);

		$app = $this->connections->app( $id );
		$key = Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-ops-connect' ),
			Html::tag( 'summary', array( 'class' => 'estasoft-nd-btn' ), esc_html( $words['key_way'] ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-connect__form' ), $form )
		);

		$ways = $key;

		if ( null !== $app['provider'] ) {
			$ways = Html::tag( 'p', array( 'class' => 'estasoft-nd-small' ), esc_html__( 'Two ways to connect. Use one.', 'estasoft-news-distribution' ) )
				. $this->sign_in( $engine, $app )
				. $key;
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ops-pad estasoft-nd-stack estasoft-nd-gap-12' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $words['sent'] ) )
			. $ways
		);
	}

	/**
	 * What a provider's sign-in says: the button, the steps to register an app and the permission it asks for.
	 *
	 * @param array<string, mixed> $provider The provider (see `Providers`).
	 * @return array{button: string, way: string, steps: string[], scope: string, unverified: string}
	 */
	private function sign_in_words( array $provider ): array {
		if ( Providers::YANDEX === $provider['id'] ) {
			return array(
				'button'     => __( 'Sign in with Yandex', 'estasoft-news-distribution' ),
				'way'        => __( 'Sign in with your own Yandex app', 'estasoft-news-distribution' ),
				'steps'      => array(
					__( 'In your Yandex account, register an app of your own on Yandex OAuth, for a web service, with the Yandex Webmaster permissions below.', 'estasoft-news-distribution' ),
					__( 'Give it this redirect address (Yandex calls it a callback URI):', 'estasoft-news-distribution' ),
					__( 'Paste the app’s client ID and client secret below, then press Sign in with Yandex. Sign in with the Yandex account that owns your site in Yandex Webmaster.', 'estasoft-news-distribution' ),
				),
				'scope'      => __( 'The permissions it asks for:', 'estasoft-news-distribution' ),
				'unverified' => __( 'These permission names have not been tried against Yandex yet. If Yandex refuses the sign-in, the answer is shown here, and the access token way still works.', 'estasoft-news-distribution' ),
			);
		}

		return array(
			'button'     => __( 'Sign in with Google', 'estasoft-news-distribution' ),
			'way'        => __( 'Sign in with your own Google app', 'estasoft-news-distribution' ),
			'steps'      => array(
				__( 'In Google Cloud, make a project, turn on the Google Search Console API, and make an OAuth client of type Web application.', 'estasoft-news-distribution' ),
				__( 'Give it this redirect address (Google calls it an authorized redirect URI):', 'estasoft-news-distribution' ),
				__( 'Paste the client ID and client secret below, then press Sign in with Google. Sign in with a Google account that is an owner or a user of your property in Search Console.', 'estasoft-news-distribution' ),
			),
			'scope'      => __( 'The permission it asks for:', 'estasoft-news-distribution' ),
			'unverified' => '',
		);
	}

	/**
	 * The sign-in way of a card that is not connected: the steps, the redirect address to register, the app's client ID and client secret, and the button.
	 *
	 * @param ConnectionInterface                                                      $engine The engine.
	 * @param array{provider: array<string, mixed>|null, id: string, has_secret: bool} $app    What `Connections::app()` returned.
	 * @return string
	 */
	private function sign_in( ConnectionInterface $engine, array $app ): string {
		$provider = (array) $app['provider'];
		$words    = $this->sign_in_words( $provider );
		$id       = $engine->id();
		$id_field = (string) $provider['client_id_field'];
		$secret   = (string) $provider['client_secret_field'];
		$base     = 'estasoft-nd-conn-' . $id . '-app';

		$steps = '';

		foreach ( $words['steps'] as $index => $step ) {
			$steps .= Html::tag(
				'li',
				array(),
				esc_html( $step ) . ( 1 === $index ? AddressRow::value( __( 'Redirect address', 'estasoft-news-distribution' ), Providers::redirect_uri() ) : '' )
			);
		}

		$permission = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html( $words['scope'] ) . ' ' . Html::tag(
				'code',
				array(
					'class' => 'estasoft-nd-mono',
					'dir'   => 'ltr',
				),
				esc_html( (string) $provider['scope'] )
			)
			. ( '' === $words['unverified'] ? '' : ' ' . esc_html( $words['unverified'] ) )
		);

		$fields = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => $base . '-id',
				),
				esc_html__( 'Client ID', 'estasoft-news-distribution' )
			)
			. Html::void(
				'input',
				array(
					'class'        => 'estasoft-nd-field estasoft-nd-ops-key',
					'id'           => $base . '-id',
					'type'         => 'text',
					'name'         => $id_field,
					'value'        => $app['id'],
					'autocomplete' => 'off',
					'spellcheck'   => 'false',
					'dir'          => 'ltr',
				)
			)
		)
		. Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => $base . '-secret',
				),
				esc_html__( 'Client secret', 'estasoft-news-distribution' )
			)
			. Html::void(
				'input',
				array(
					'class'            => 'estasoft-nd-field estasoft-nd-ops-key',
					'id'               => $base . '-secret',
					'type'             => 'password',
					'name'             => $secret,
					'value'            => '',
					'autocomplete'     => 'new-password',
					'dir'              => 'ltr',
					'aria-describedby' => $base . '-secret-help',
				)
			)
			. Html::tag(
				'p',
				array(
					'class' => 'estasoft-nd-muted estasoft-nd-small',
					'id'    => $base . '-secret-help',
				),
				esc_html(
					$app['has_secret']
						? __( 'A client secret is saved. It is never shown again. Paste a new one only to replace it.', 'estasoft-news-distribution' )
						: __( 'Saved on your site, never shown again, and left out of every export.', 'estasoft-news-distribution' )
				)
			)
		);

		$form = Forms::post(
			'estasoft_nd_oauth_start',
			Forms::connection_nonce(),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $fields )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Button::submit( $words['button'], array( 'variant' => 'primary' ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'You go to the sign-in page, then come back here. Nothing is saved as connected until the engine accepts it.', 'estasoft-news-distribution' ) )
			),
			array(),
			array( 'engine' => $id )
		);

		$forget = '';

		if ( $app['has_secret'] || '' !== $app['id'] ) {
			$forget = Forms::post(
				'estasoft_nd_oauth_forget',
				Forms::connection_nonce(),
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Button::submit( __( 'Remove the saved client ID and secret', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
				),
				array(),
				array( 'engine' => $id )
			);
		}

		return Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-ops-connect' ),
			Html::tag( 'summary', array( 'class' => 'estasoft-nd-btn' ), esc_html( $words['way'] ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ops-connect__form estasoft-nd-stack estasoft-nd-gap-12' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'News Distribution has no app registered with this service, so you make one in your own account. The sign-in is yours: it is saved on your site and can be cancelled from your account there at any time.', 'estasoft-news-distribution' ) )
				. Html::tag( 'ol', array( 'class' => 'estasoft-nd-ops-steps' ), $steps )
				. $permission
				. $form
				. $forget
			)
		);
	}

	/**
	 * The body of a connected card: the saved credential's mask, what the engine said about each sitemap, and the buttons.
	 *
	 * @param ConnectionInterface  $engine The engine.
	 * @param array<string, mixed> $words  The card's words.
	 * @param array<string, mixed> $state  What `Connections::state()` returned.
	 * @param array<string, mixed> $values The section's values.
	 * @param string               $mode   `key` or `oauth`, from `Connections::mode()`.
	 * @return string
	 */
	private function connected_body( ConnectionInterface $engine, array $words, array $state, array $values, string $mode ): string {
		$id     = $engine->id();
		$oauth  = Connections::MODE_OAUTH === $mode;
		$mask   = $oauth ? '' : (string) ( $values[ $engine->secret_field() ] ?? '' );
		$saved  = '';
		$label  = $words['secret'];
		$signed = $this->connections->app( $id );

		if ( $oauth ) {
			$label = __( 'Sign-in with your own app', 'estasoft-news-distribution' );
			$saved = $state['saved_on'] > 0
				? sprintf(
					/* translators: %s: a date, such as "14 Oct". */
					__( 'Signed in %s. The saved sign-in is never shown again.', 'estasoft-news-distribution' ),
					Clock::format( (int) $state['saved_on'], 'j M' )
				)
				: __( 'Signed in. The saved sign-in is never shown again.', 'estasoft-news-distribution' );

			if ( $state['expires_on'] > 0 ) {
				$saved .= ' ' . sprintf(
					/* translators: %s: a date, such as "14 Oct 2027". */
					__( 'It works until %s.', 'estasoft-news-distribution' ),
					Clock::format( (int) $state['expires_on'], 'j M Y' )
				);
			}
		} elseif ( $state['saved_on'] > 0 ) {
			$saved = sprintf(
				/* translators: %s: a date, such as "14 Oct". */
				__( 'Saved %s. It is never shown again.', 'estasoft-news-distribution' ),
				Clock::format( (int) $state['saved_on'], 'j M' )
			);
		} else {
			$saved = __( 'Saved. It is never shown again.', 'estasoft-news-distribution' );
		}

		$credential = Html::tag( 'div', array( 'class' => 'estasoft-nd-label' ), esc_html( $label ) )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Icon::svg( 'lock' )
				. ( '' === $mask ? '' : Html::tag(
					'span',
					array(
						'class' => 'estasoft-nd-mono',
						'dir'   => 'ltr',
					),
					esc_html( $mask )
				) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $saved ) )
			);

		if ( '' !== $state['account'] ) {
			$credential .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: the account the key belongs to, such as an email address. */
						__( 'Signed in as %s.', 'estasoft-news-distribution' ),
						$state['account']
					)
				)
			);
		}

		$rows = array();

		foreach ( (array) $state['rows'] as $row ) {
			$says = esc_html( (string) ( $row['says'] ?? '' ) );

			if ( ! empty( $row['link'] ) ) {
				$says .= ' ' . Html::tag(
					'a',
					array(
						'href'   => esc_url( (string) $row['link'] ),
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
					),
					esc_html(
						sprintf(
							/* translators: %s: the engine's name, such as "Yandex Webmaster". */
							__( 'Details in %s', 'estasoft-news-distribution' ),
							$engine->label()
						)
					)
				);
			}

			$rows[] = array(
				esc_html( (string) ( $row['label'] ?? '' ) ),
				$says,
				StatusView::inline( (string) ( $row['status'] ?? Status::PROCESSING ) ),
			);
		}

		if ( array() !== $rows ) {
			$table = Grid::render(
				'estasoft-nd-cols-ops-sitemaps',
				array( __( 'Sitemap', 'estasoft-news-distribution' ), (string) $words['says'], __( 'News Distribution shows', 'estasoft-news-distribution' ) ),
				$rows,
				sprintf(
					/* translators: %s: the engine's name. */
					__( 'Sitemaps and what %s says about them', 'estasoft-news-distribution' ),
					$engine->label()
				)
			) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $words['mapping'] ) );
		} else {
			$table = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing has been read yet. Press Check now to submit your sitemaps and read their status.', 'estasoft-news-distribution' ) );
		}

		$result = '';

		if ( $state['checked_on'] > 0 ) {
			$ago = human_time_diff( (int) $state['checked_on'], Clock::now() );

			if ( $state['ok'] ) {
				$text = sprintf(
					/* translators: 1: how long ago, such as "1 min", 2: what the check found, such as "3 sitemaps read". */
					__( 'Test passed, %1$s ago: %2$s', 'estasoft-news-distribution' ),
					$ago,
					$state['message']
				);

				$result = StatusView::inline( Status::TEST_PASSED, $text );
			} else {
				$text = sprintf(
					/* translators: 1: how long ago, such as "1 min", 2: why the check failed. */
					__( 'Test failed, %1$s ago: %2$s', 'estasoft-news-distribution' ),
					$ago,
					$state['message']
				);

				$result = StatusView::inline( Status::ATTENTION, $text );
			}
		}

		$buttons = Forms::post(
			'estasoft_nd_connection',
			Forms::connection_nonce(),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				Button::submit(
					__( 'Check now', 'estasoft-news-distribution' ),
					array(
						'attrs' => array(
							'name'  => 'op',
							'value' => 'check',
						),
					)
				)
				. Button::submit(
					__( 'Disconnect', 'estasoft-news-distribution' ),
					array(
						'attrs' => array(
							'name'  => 'op',
							'value' => 'disconnect',
						),
					)
				)
			),
			array( 'data-ops-form' => 'check' ),
			array( 'engine' => $id )
		);

		$again = '';

		if ( $oauth && null !== $signed['provider'] ) {
			// The same sign-in as the first time, with the app already saved here. It is also the way back when the engine stops accepting the saved sign-in.
			$again = Forms::post(
				'estasoft_nd_oauth_start',
				Forms::connection_nonce(),
				Button::submit( __( 'Sign in again', 'estasoft-news-distribution' ), array() ),
				array(),
				array( 'engine' => $id )
			);
		}

		$gone = $oauth
			? __( 'Disconnect asks the service to cancel the sign-in where it can, removes the saved sign-in and client secret from your site, and statuses go back to hand-set. It asks for no confirmation, because you can sign in again at any time.', 'estasoft-news-distribution' )
			: __( 'Disconnect removes the saved credential, and statuses go back to hand-set. It asks for no confirmation, because you can connect again at any time.', 'estasoft-news-distribution' );

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-pad estasoft-nd-stack estasoft-nd-gap-12' ), $credential )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-pad estasoft-nd-stack estasoft-nd-gap-8' ), $table )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ops-pad estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-ops-actions' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ), $buttons . $again . Forms::result( 'estasoft-nd-conn-' . $id . '-result', $result ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $gone ) )
			);
	}
}
