<?php
/**
 * Outlets, one webhook endpoint.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Admin\Channels\OutletsScreen;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Channels\Outlets\WebhookOutlet;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * The page for one endpoint: its name, address, signing secret and switch,
 * with a button that sends a signed test request. The address and the secret
 * are write-only: once saved, only the host of the address is shown.
 *
 * `?webhook=new` adds an endpoint in the first free place, `?webhook=2` edits
 * the second, and `?webhook=2&confirm=remove` asks before removing it.
 */
final class WebhookTab extends AbstractTab {

	/**
	 * The endpoint number this page is about, or "new".
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Creates the tab for one endpoint.
	 *
	 * @param OutletsScreen  $screen  The page.
	 * @param OutletsChannel $channel The channel.
	 * @param Plugin         $plugin  The running plugin.
	 * @param string         $key     Endpoint number, or "new".
	 */
	public function __construct( OutletsScreen $screen, OutletsChannel $channel, Plugin $plugin, string $key ) {
		parent::__construct( $screen, $channel, $plugin );

		$this->key = $key;
	}

	public function slug(): string {
		return 'overview';
	}

	/**
	 * Which endpoint number "new" means: the first one with no name and no address.
	 *
	 * @param WebhookOutlet        $webhook  The outlet.
	 * @param array<string, mixed> $settings The channel's settings.
	 * @return string Empty when every place is in use.
	 */
	public static function first_free( WebhookOutlet $webhook, array $settings ): string {
		$used = array();

		foreach ( $webhook->targets( $settings, array() ) as $target ) {
			$used[ $target->key() ] = true;
		}

		for ( $number = 1, $last = $webhook->slots(); $number <= $last; $number++ ) {
			if ( ! isset( $used[ (string) $number ] ) ) {
				return (string) $number;
			}
		}

		return '';
	}

	public function render(): string {
		$webhook = $this->channel->outlet( 'webhook' );

		if ( ! $webhook instanceof WebhookOutlet ) {
			return '';
		}

		$number = 'new' === $this->key ? self::first_free( $webhook, $this->channel->settings() ) : $this->key;
		$crumb  = Html::tag(
			'p',
			array(),
			Button::link( __( 'Back to Queue and webhooks', 'estasoft-news-distribution' ), $this->screen->url( 'overview' ), array( 'variant' => 'subtle' ) )
		);

		if ( '' === $number || (int) $number < 1 || (int) $number > $webhook->slots() ) {
			return $crumb . Message::text( 'info', __( 'Every place for an endpoint is in use. Remove one to add another.', 'estasoft-news-distribution' ) );
		}

		if ( 'remove' === $this->confirm() && 'new' !== $this->key ) {
			return $crumb . $this->confirm_card( $number );
		}

		return $crumb . $this->split( $this->editor( $webhook, $number ), $this->side( $webhook ) );
	}

	/**
	 * What the address asks for, validated against a list.
	 *
	 * @return string
	 */
	private function confirm(): string {
		return Query::key( 'confirm' );
	}

	/**
	 * The question before an endpoint is removed.
	 *
	 * @param string $number Endpoint number.
	 * @return string
	 */
	private function confirm_card( string $number ): string {
		$values = $this->values();
		$name   = trim( (string) ( $values[ 'webhook_' . $number . '_name' ] ?? '' ) );
		$name   = '' !== $name ? $name : sprintf(
			/* translators: %d: the number of an endpoint. */
			__( 'Endpoint %d', 'estasoft-news-distribution' ),
			(int) $number
		);

		return $this->form(
			Html::tag(
				'section',
				array( 'class' => 'estasoft-nd-card estasoft-nd-card--padded' ),
				Html::tag(
					'h2',
					array( 'class' => 'estasoft-nd-title' ),
					esc_html(
						sprintf(
							/* translators: %s: the name of an endpoint. */
							__( 'Remove %s?', 'estasoft-news-distribution' ),
							$name
						)
					)
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Its address and signing secret are deleted from this site, and nothing is sent to it again. Articles already sent are not affected.', 'estasoft-news-distribution' ) )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					$this->action_button( 'webhook_remove', __( 'Remove endpoint', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
					. Button::link( __( 'Keep it', 'estasoft-news-distribution' ), $this->screen->url( 'overview', array( 'webhook' => $number ) ) )
				)
			),
			__( 'Remove endpoint', 'estasoft-news-distribution' ),
			array( 'webhook' => $number ),
			false
		);
	}

	/**
	 * The form for one endpoint.
	 *
	 * @param WebhookOutlet $webhook The outlet.
	 * @param string        $number  Endpoint number.
	 * @return string
	 */
	private function editor( WebhookOutlet $webhook, string $number ): string {
		$values = $this->values();
		$name   = 'webhook_' . $number . '_name';
		$url    = 'webhook_' . $number . '_url';
		$secret = 'webhook_' . $number . '_secret';
		$on     = 'webhook_' . $number . '_on';
		$exists = ! empty( $values[ $url . '_is_set' ] ) || '' !== trim( (string) ( $values[ $name ] ?? '' ) );
		$hint   = $webhook->address_hint( $number );
		$last   = $this->state()->webhook( $number );

		$name_id = Field::id( $this->screen->scope_id(), $name );
		$rows    = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $name_id,
				),
				esc_html__( 'Name', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-control' ),
				$this->text_input(
					$name,
					__( 'Endpoint name', 'estasoft-news-distribution' ),
					array(
						'maxlength'   => 80,
						'placeholder' => sprintf(
							/* translators: %d: the number of an endpoint. */
							__( 'Endpoint %d', 'estasoft-news-distribution' ),
							(int) $number
						),
					),
					false
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Only you see it, to tell your endpoints apart.', 'estasoft-news-distribution' ) )
			)
		);

		$url_id = Field::id( $this->screen->scope_id(), $url );
		$rows  .= Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $url_id,
				),
				esc_html__( 'Address', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-control' ),
				( '' !== $hint ? Html::tag( 'p', array( 'class' => 'estasoft-nd-mono estasoft-nd-outlets-address' ), esc_html( $hint ) ) : '' )
				. $this->secret_input( $url, __( 'Endpoint address', 'estasoft-news-distribution' ), false, false )
				. $this->field_error( $url )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'It must start with https://. Many services put a token in the address, so it is stored like a password and only its host is shown again.', 'estasoft-news-distribution' ) )
			)
		);

		$secret_id = Field::id( $this->screen->scope_id(), $secret );
		$rows     .= Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $secret_id,
				),
				esc_html__( 'Signing secret', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-control' ),
				$this->secret_input( $secret, __( 'Signing secret', 'estasoft-news-distribution' ), true, false )
				. $this->field_error( $secret )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'A long random text, at least 16 characters. Enter the same text on the receiving side, so it can check that a request came from this site.', 'estasoft-news-distribution' ) )
			)
		);

		$rows .= Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-form-label' ), esc_html__( 'Sending', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-control' ),
				$this->checkbox( $on, __( 'Send articles to this endpoint', 'estasoft-news-distribution' ), __( 'Off by default. Nothing is sent until you turn it on.', 'estasoft-news-distribution' ) )
			)
		);

		$buttons = $this->action_button( 'webhook_save', $exists ? __( 'Save endpoint', 'estasoft-news-distribution' ) : __( 'Add endpoint', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			. $this->action_button( 'webhook_test', __( 'Send a test request', 'estasoft-news-distribution' ) );

		if ( $exists && $this->can_change_secrets() ) {
			$buttons .= Button::link(
				__( 'Remove endpoint', 'estasoft-news-distribution' ),
				$this->screen->url(
					'overview',
					array(
						'webhook' => $number,
						'confirm' => 'remove',
					)
				),
				array( 'variant' => 'subtle' )
			);
		}

		$test = '';

		if ( 'ping' === ( $last['event'] ?? '' ) && ! empty( $last['time'] ) ) {
			$test = Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				( 'sent' === $last['status'] ? StatusView::pill( Status::TEST_PASSED ) : StatusView::inline( Status::FAILED, __( 'Test failed', 'estasoft-news-distribution' ) ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) ( $last['message'] ?? '' ) ) )
			);
		}

		$foot = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-row estasoft-nd-outlets-foot' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons )
			. $test
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The test sends a small signed request that carries no article. Saving checks the address and the secret, but sends nothing.', 'estasoft-news-distribution' ) )
		);

		$id = 'estasoft-nd-card-webhook';

		return $this->form(
			Html::tag(
				'section',
				array(
					'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
					'aria-labelledby' => $id,
				),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-card-head' ),
					Html::tag(
						'h2',
						array(
							'class' => 'estasoft-nd-title',
							'id'    => $id,
						),
						esc_html( $exists ? __( 'Edit endpoint', 'estasoft-news-distribution' ) : __( 'Add an endpoint', 'estasoft-news-distribution' ) )
					)
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'News Distribution sends each published article to this address as a signed JSON request.', 'estasoft-news-distribution' ) )
				) . $rows . $foot
			),
			__( 'Webhook endpoint', 'estasoft-news-distribution' ),
			array( 'webhook' => $number ),
			false
		);
	}

	/**
	 * The message under a field a save refused, when the field is not a plain box.
	 *
	 * @param string $field Setting name.
	 * @return string
	 */
	private function field_error( string $field ): string {
		return $this->error_line( Field::id( $this->screen->scope_id(), $field ), (string) ( $this->screen->field_errors()[ $field ] ?? '' ) );
	}

	/**
	 * What a request holds and how to check it.
	 *
	 * @param WebhookOutlet $webhook The outlet.
	 * @return string
	 */
	private function side( WebhookOutlet $webhook ): string {
		list( $article, $example ) = $this->preview_article();

		$event   = new Event( Event::PUBLISH, $article->id(), $article->language() );
		$payload = $webhook->payload( Event::PUBLISH, $event, $article, $this->channel->settings() );
		$json    = (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return $this->side_card(
			__( 'What the endpoint receives', 'estasoft-news-distribution' ),
			$this->rules(
				array(
					__( 'A JSON POST for each new article, and one when an article is removed. Edits are not sent.', 'estasoft-news-distribution' ),
					__( 'X-Estasoft-Signature holds sha256= and the HMAC-SHA256 of the timestamp, a full stop and the body, made with your signing secret. X-Estasoft-Timestamp holds the timestamp.', 'estasoft-news-distribution' ),
					__( 'X-Estasoft-Delivery is the same on every retry of one article, so your side can drop a repeat.', 'estasoft-news-distribution' ),
					sprintf(
						/* translators: %d: the number of attempts. */
						_n( 'An answer that is not a success is tried again, up to %d attempt.', 'An answer that is not a success is tried again, up to %d attempts.', Retry::MAX_ATTEMPTS, 'estasoft-news-distribution' ),
						Retry::MAX_ATTEMPTS
					),
				)
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-label' ), esc_html( $example ? __( 'Example of a request body', 'estasoft-news-distribution' ) : __( 'The newest article as a request body', 'estasoft-news-distribution' ) ) )
			. Code::block( $json, __( 'Example request body', 'estasoft-news-distribution' ) )
		);
	}
}
