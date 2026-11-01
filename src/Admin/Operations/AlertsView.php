<?php
/**
 * Settings, Alerts: the list of alerts, the test email, the Emails tab and the Weekly tab.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Alerts;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Digest;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the Alerts section prints that is not a plain field. The two tabs
 * (`&tab=emails` and `&tab=weekly`) are whole pages with their own heading.
 */
final class AlertsView {

	private Plugin $plugin;

	private Alerts $alerts;

	/**
	 * Creates the view.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->alerts = $plugin->make( Alerts::class );
	}

	/**
	 * The sentence under "What to send where": says which chat columns are off because no address is saved.
	 *
	 * @return string
	 */
	public function matrix_description(): string {
		$slack = $this->alerts->has_chat_address( Alerts::SLACK );
		$teams = $this->alerts->has_chat_address( Alerts::TEAMS );

		if ( $slack && $teams ) {
			return __( 'Choose where each alert goes.', 'estasoft-news-distribution' );
		}

		if ( $slack ) {
			return __( 'Choose where each alert goes. The Teams column is off until you save a Teams webhook above.', 'estasoft-news-distribution' );
		}

		if ( $teams ) {
			return __( 'Choose where each alert goes. The Slack column is off until you save a Slack webhook above.', 'estasoft-news-distribution' );
		}

		return __( 'Choose where each alert goes. The Slack and Teams columns are off until you save a webhook above.', 'estasoft-news-distribution' );
	}

	/**
	 * The table of alerts with one check box each, in the Email, Slack and Teams columns. A chat column is off
	 * until that service has a saved address.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function matrix( array $values ): string {
		$columns = array(
			'email_alerts' => array(
				'label' => __( 'Email', 'estasoft-news-distribution' ),
				'ready' => true,
			),
			'slack_alerts' => array(
				'label' => __( 'Slack', 'estasoft-news-distribution' ),
				'ready' => $this->alerts->has_chat_address( Alerts::SLACK ),
			),
			'teams_alerts' => array(
				'label' => __( 'Teams', 'estasoft-news-distribution' ),
				'ready' => $this->alerts->has_chat_address( Alerts::TEAMS ),
			),
		);
		$rows    = array();

		foreach ( Alerts::types() as $type => $info ) {
			$row = array(
				Html::tag( 'strong', array(), esc_html( $info['title'] ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $info['help'] ) ),
			);

			foreach ( $columns as $field => $column ) {
				$chosen = array_map( 'strval', (array) ( $values[ $field ] ?? array() ) );
				$label  = $column['ready']
					? sprintf(
						/* translators: 1: the name of an alert, such as "A fix is assigned to you", 2: where it can be sent: Email, Slack or Teams. */
						__( '%1$s, %2$s', 'estasoft-news-distribution' ),
						$info['title'],
						$column['label']
					)
					: sprintf(
						/* translators: 1: the name of an alert, such as "A fix is assigned to you", 2: a chat service: Slack or Teams. */
						__( '%1$s (set up %2$s first), %2$s', 'estasoft-news-distribution' ),
						$info['title'],
						$column['label']
					);

				$row[] = Html::tag(
					'label',
					array( 'class' => 'estasoft-nd-check estasoft-nd-ops-tick' ),
					Html::void(
						'input',
						array(
							'type'     => 'checkbox',
							'name'     => Field::name( $field ) . '[]',
							'value'    => $type,
							'checked'  => $column['ready'] && in_array( $type, $chosen, true ),
							'disabled' => ! $column['ready'],
						)
					)
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html( $label ) )
				);
			}

			$rows[] = $row;
		}

		// An empty marker for each column, so that clearing every box still saves an empty list.
		$markers = '';

		foreach ( array_keys( $columns ) as $field ) {
			$markers .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => Field::name( $field ) . '[]',
					'value' => '',
				)
			);
		}

		$links = sprintf(
			/* translators: 1: link to the Emails tab, 2: link to the Weekly summary tab. */
			esc_html__( 'See what the emails look like: %1$s and %2$s.', 'estasoft-news-distribution' ),
			Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'alerts', array( 'tab' => 'emails' ) ) ) ), esc_html__( 'Emails', 'estasoft-news-distribution' ) ),
			Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'alerts', array( 'tab' => 'weekly' ) ) ) ), esc_html__( 'Weekly summary', 'estasoft-news-distribution' ) )
		);

		return $markers
			. Grid::render(
				'estasoft-nd-cols-ops-alerts-chat',
				array(
					__( 'Alert', 'estasoft-news-distribution' ),
					$columns['email_alerts']['label'],
					$columns['slack_alerts']['label'],
					$columns['teams_alerts']['label'],
				),
				$rows,
				__( 'Alerts and where they are sent', 'estasoft-news-distribution' )
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ops-foot' ), $links );
	}

	/**
	 * The Slack row of the Chat card.
	 *
	 * @param array<string, mixed>  $values The section's values.
	 * @param array<string, string> $errors Rejected fields and their messages.
	 * @return string Escaped HTML.
	 */
	public function slack_row( array $values = array(), array $errors = array() ): string {
		return $this->chat_row( Alerts::SLACK, $values, $errors );
	}

	/**
	 * The Microsoft Teams row of the Chat card.
	 *
	 * @param array<string, mixed>  $values The section's values.
	 * @param array<string, string> $errors Rejected fields and their messages.
	 * @return string Escaped HTML.
	 */
	public function teams_row( array $values = array(), array $errors = array() ): string {
		return $this->chat_row( Alerts::TEAMS, $values, $errors );
	}

	/**
	 * One chat service: the address box, the test button, how the last test went and what the test does.
	 *
	 * The address is write-only. A saved one shows as bullets and is replaced by pasting a new one. Only an
	 * administrator can paste, replace or remove one; anyone who manages the plugin can send the test.
	 *
	 * @param string                $service `slack` or `teams`.
	 * @param array<string, mixed>  $values  The section's values.
	 * @param array<string, string> $errors  Rejected fields and their messages.
	 * @return string Escaped HTML.
	 */
	private function chat_row( string $service, array $values, array $errors ): string {
		$field   = $service . '_webhook';
		$sender  = $this->alerts->chat_sender( $service );
		$label   = null === $sender ? $service : $sender->label();
		$is_set  = $this->alerts->has_chat_address( $service );
		$can_set = $this->plugin->access()->can( Access::ADMIN );
		$id      = Field::id( 'alerts', $field );
		$error   = (string) ( $errors[ $field ] ?? '' );
		$mask    = Alerts::SLACK === $service ? 'https://hooks.slack.com/services/••••••••••••' : '••••••••••••';
		$hold    = $is_set ? $mask : __( 'Webhook URL', 'estasoft-news-distribution' );

		if ( ! $can_set ) {
			$hold = $is_set ? $mask : __( 'Only an administrator can paste an address.', 'estasoft-news-distribution' );
		}

		unset( $values );

		$input = Html::void(
			'input',
			array(
				'class'            => Html::classes( 'estasoft-nd-field', Html::width_class( 360 ) ),
				'type'             => 'url',
				'id'               => $id,
				'name'             => Field::name( $field ),
				'value'            => '',
				'autocomplete'     => 'off',
				'spellcheck'       => 'false',
				'placeholder'      => $hold,
				'disabled'         => ! $can_set,
				'aria-label'       => sprintf(
					/* translators: %s: chat service name, such as Slack. */
					__( '%s webhook address', 'estasoft-news-distribution' ),
					$label
				),
				'aria-describedby' => $id . '-help' . ( '' !== $error ? ' ' . $id . '-error' : '' ),
				'aria-invalid'     => '' !== $error ? 'true' : null,
			)
		);

		$button = Button::submit(
			__( 'Send test message', 'estasoft-news-distribution' ),
			array(
				'attrs' => array(
					'name'           => 'action',
					'value'          => 'estasoft_nd_test_' . $service,
					'data-chat-test' => $service,
				),
			)
		);

		$line = Forms::result( 'estasoft-nd-' . $service . '-result', self::chat_test_line( $this->alerts->last_chat_test( $service ), $is_set ) );

		$html = Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12 estasoft-nd-chat-row' ), $input . $button )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-chat-result' ), $line );

		if ( '' !== $error ) {
			$html .= Html::tag(
				'p',
				array(
					'class'            => 'estasoft-nd-field-error',
					'id'               => $id . '-error',
					'role'             => 'alert',
					'data-field-error' => $field,
				),
				Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $error ) )
			);
		} else {
			$html .= Html::tag(
				'p',
				array(
					'class'            => 'estasoft-nd-field-error',
					'hidden'           => true,
					'role'             => 'alert',
					'data-field-error' => $field,
				),
				''
			);
		}

		if ( $is_set && $can_set ) {
			$html .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check' ),
				Html::void(
					'input',
					array(
						'type'  => 'checkbox',
						'name'  => Field::name( $field . '__remove' ),
						'value' => '1',
					)
				)
				. esc_html(
					sprintf(
						/* translators: %s: chat service name, such as Slack. */
						__( 'Remove the saved %s address', 'estasoft-news-distribution' ),
						$label
					)
				)
			);
		}

		$help = $this->chat_help( $service, $is_set );

		if ( ! $can_set ) {
			$help .= ' ' . __( 'Only an administrator can change this address.', 'estasoft-news-distribution' );
		}

		return $html . Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-muted estasoft-nd-small',
				'id'    => $id . '-help',
			),
			esc_html( $help )
		);
	}

	/**
	 * What a chat row says about the address and the test.
	 *
	 * @param string $service `slack` or `teams`.
	 * @param bool   $is_set  Whether an address is saved.
	 * @return string Plain text.
	 */
	private function chat_help( string $service, bool $is_set ): string {
		if ( Alerts::SLACK === $service ) {
			$parts = array(
				$is_set
					? __( 'Saved and hidden. Paste a new address to replace it.', 'estasoft-news-distribution' )
					: __( 'Paste the address of your Slack incoming webhook, then send a test message.', 'estasoft-news-distribution' ),
				__( 'The test posts one short message to that Slack channel, with no article text, and cannot be taken back; delete it in Slack.', 'estasoft-news-distribution' ),
			);

			if ( ! $is_set ) {
				$parts[] = __( 'With no address, the test asks for one and sends nothing.', 'estasoft-news-distribution' );
			}

			$parts[] = __( 'To create one, add an incoming webhook to a Slack channel. The address must start with https://hooks.slack.com/services/.', 'estasoft-news-distribution' );

			return implode( ' ', $parts );
		}

		$parts = array(
			$is_set
				? __( 'Saved and hidden. Paste a new address to replace it.', 'estasoft-news-distribution' )
				: __( 'Paste the webhook address Teams gives you for a channel, then send a test message.', 'estasoft-news-distribution' ),
			__( 'The test posts one short message to that channel, with no article text, and cannot be taken back; delete it in Teams.', 'estasoft-news-distribution' ),
		);

		if ( ! $is_set ) {
			$parts[] = __( 'With no address, the test asks for one and sends nothing.', 'estasoft-news-distribution' );
		}

		$parts[] = __( 'The address comes from the Workflows app in Teams or from an incoming webhook.', 'estasoft-news-distribution' );

		return implode( ' ', $parts );
	}

	/**
	 * How the last test message to a chat service went, as a short line.
	 *
	 * @param array{time: int, ok: bool, message: string}|null $last   What `Alerts::last_chat_test()` returned.
	 * @param bool                                             $is_set Whether an address is saved.
	 * @return string Escaped HTML. Without an address it says "Not set up".
	 */
	public static function chat_test_line( ?array $last, bool $is_set ): string {
		if ( ! $is_set ) {
			// A test with no address saved sends nothing and says what to do.
			return StatusView::inline( Status::NOT_SET_UP, null === $last ? null : $last['message'] );
		}

		if ( null === $last ) {
			return '';
		}

		$when = Clock::now() - $last['time'] < HOUR_IN_SECONDS ? Format::ago( $last['time'] ) : Format::when( $last['time'] );

		if ( $last['ok'] ) {
			return StatusView::inline(
				Status::TEST_PASSED,
				sprintf(
					/* translators: %s: how long ago, such as "2 minutes ago". */
					__( 'Test passed, %s', 'estasoft-news-distribution' ),
					$when
				)
			);
		}

		return StatusView::inline(
			Status::FAILED,
			sprintf(
				/* translators: 1: how long ago, such as "2 minutes ago", 2: the reason the service gave. */
				__( 'Test failed, %1$s: %2$s', 'estasoft-news-distribution' ),
				$when,
				'' !== $last['message'] ? $last['message'] : __( 'The service did not accept the message.', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * The "Test" row: the button, how the last test went, and what the test does.
	 *
	 * @return string Escaped HTML.
	 */
	public function test_row(): string {
		$last      = $this->alerts->last_test();
		$addresses = $this->alerts->addresses();
		$user      = wp_get_current_user();
		$target    = array() !== $addresses ? implode( ', ', $addresses ) : (string) $user->user_email;

		$help = sprintf(
			/* translators: %s: one or more email addresses. */
			__( 'Sends one short email to %s, from this site, with no article text. It cannot be taken back.', 'estasoft-news-distribution' ),
			$target
		);

		if ( null !== $last && $last['ok'] ) {
			$help .= ' ' . sprintf(
				/* translators: %s: a time, such as "14:33". */
				__( 'WordPress accepted the last one at %s.', 'estasoft-news-distribution' ),
				Clock::format( $last['time'], 'H:i' )
			);
		}

		$help .= ' ' . __( 'News Distribution cannot see your inbox, so check there and in spam. If WordPress cannot send, the line beside the button shows its error instead, for example that your host blocks mail.', 'estasoft-news-distribution' );

		if ( array() === $addresses ) {
			$help .= ' ' . __( 'No address is saved, so the test goes to your own address.', 'estasoft-news-distribution' );
		} else {
			$help .= ' ' . __( 'It goes to the saved addresses, so save first if you changed them.', 'estasoft-news-distribution' );
		}

		return Flash::take() . Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			Button::submit(
				__( 'Send a test email', 'estasoft-news-distribution' ),
				array(
					'attrs' => array(
						'name'          => 'action',
						'value'         => 'estasoft_nd_test_email',
						'data-ops-test' => 'settings/alerts/test-email',
					),
				)
			)
			. Forms::result( 'estasoft-nd-email-result', self::test_line( $last ) )
		) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $help ) );
	}

	/**
	 * How the last test email went, as a short line.
	 *
	 * @param array{time: int, ok: bool, to: string[], message: string}|null $last What `Alerts::last_test()` returned.
	 * @return string Escaped HTML, or an empty string when no test was sent.
	 */
	public static function test_line( ?array $last ): string {
		if ( null === $last ) {
			return '';
		}

		if ( $last['ok'] ) {
			return StatusView::inline(
				Status::TEST_PASSED,
				sprintf(
					/* translators: %s: how long ago, such as "2 minutes ago". */
					__( 'Test passed, %s', 'estasoft-news-distribution' ),
					Format::ago( $last['time'] )
				)
			);
		}

		return StatusView::inline(
			Status::FAILED,
			sprintf(
				/* translators: 1: how long ago, such as "2 minutes ago", 2: the reason WordPress gave. */
				__( 'Test failed, %1$s: %2$s', 'estasoft-news-distribution' ),
				Format::ago( $last['time'] ),
				'' !== $last['message'] ? $last['message'] : __( 'WordPress could not send the email.', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * The Emails tab: sample emails and when each is sent.
	 *
	 * @return string Escaped HTML, a whole page.
	 */
	public function emails_page(): string {
		$user      = wp_get_current_user();
		$me        = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$addresses = $this->alerts->addresses();
		$alert_to  = array() !== $addresses ? implode( ', ', $addresses ) : (string) $user->user_email;
		$site      = $this->alerts->site_name();

		$cards = EmailView::card( 'fix', $this->alerts->compose( Alerts::FIX_ASSIGNED, $this->alerts->sample_context( Alerts::FIX_ASSIGNED ) ), $me )
			. EmailView::card( 'push', $this->alerts->compose( Alerts::PUSH_FAILED, $this->alerts->sample_context( Alerts::PUSH_FAILED ) ), $alert_to )
			. EmailView::card( 'takedown', $this->alerts->compose( Alerts::TAKEDOWN, $this->alerts->sample_context( Alerts::TAKEDOWN ) ), array() !== $addresses ? $me . ', ' . $alert_to : $me );

		$alerts_url = Links::settings( 'alerts' );

		$settings_link = Html::tag( 'a', array( 'href' => esc_url( $alerts_url ) ), esc_html__( 'Settings, Alerts', 'estasoft-news-distribution' ) );

		$when = array(
			array( Alerts::FIX_ASSIGNED, __( 'The person it is assigned to', 'estasoft-news-distribution' ), __( 'Once for each fix, and one reminder if it is overdue', 'estasoft-news-distribution' ), $settings_link ),
			array( Alerts::PUSH_FAILED, __( 'The alert addresses', 'estasoft-news-distribution' ), __( 'At most once an hour, grouped. Only after retries stop', 'estasoft-news-distribution' ), $settings_link ),
			array( Alerts::TAKEDOWN, __( 'The person who started it, and the alert addresses', 'estasoft-news-distribution' ), __( 'Once, and one reminder after 24 hours', 'estasoft-news-distribution' ), $settings_link ),
			array( Alerts::PLATFORM_SILENT, __( 'The alert addresses', 'estasoft-news-distribution' ), __( 'Once for each quiet spell of 24 hours or more', 'estasoft-news-distribution' ), $settings_link ),
			array( Alerts::ARTICLE_SKIPPED, __( 'The alert addresses', 'estasoft-news-distribution' ), __( 'Once a day, and only when an article was skipped', 'estasoft-news-distribution' ), $settings_link ),
			array( Alerts::RULE_CHANGE, __( 'The alert addresses', 'estasoft-news-distribution' ), __( 'Once for each rule change that affects your articles', 'estasoft-news-distribution' ), $settings_link ),
			array( Alerts::AI_LIMIT, __( 'The alert addresses', 'estasoft-news-distribution' ), __( 'When the limit is reached', 'estasoft-news-distribution' ), $settings_link ),
			array(
				Alerts::WEEKLY,
				__( 'Each person on the list', 'estasoft-news-distribution' ),
				__( 'Once a week', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: a link with the text "Settings, Alerts". */
					esc_html__( '%s, or the link in the email', 'estasoft-news-distribution' ),
					$settings_link
				),
			),
		);

		$rows  = array();
		$types = Alerts::types();

		foreach ( $when as $line ) {
			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( $types[ $line[0] ]['title'] ) ),
				esc_html( $line[1] ),
				esc_html( $line[2] ),
				$line[3],
			);
		}

		$table = Card::flush(
			'emails-when',
			__( 'When emails are sent', 'estasoft-news-distribution' ),
			__( 'Every email News Distribution sends, and the weekly summary that has its own screen. Nothing else is emailed.', 'estasoft-news-distribution' ),
			Grid::render(
				'estasoft-nd-cols-ops-when',
				array(
					__( 'Email', 'estasoft-news-distribution' ),
					__( 'Who gets it', 'estasoft-news-distribution' ),
					__( 'How often', 'estasoft-news-distribution' ),
					__( 'Turn it off', 'estasoft-news-distribution' ),
				),
				$rows,
				__( 'When each email is sent', 'estasoft-news-distribution' )
			)
		);

		$checks = '';

		foreach (
			array(
				__( 'The article title, the platform and the next step. Never the article text, a key or a token.', 'estasoft-news-distribution' ),
				__( 'Plain wording, no images, and a button that opens a screen in WordPress.', 'estasoft-news-distribution' ),
				__( 'Titles in Arabic or Hebrew keep their direction inside a left-to-right email.', 'estasoft-news-distribution' ),
			) as $item
		) {
			$checks .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'check-circle' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		$notes = Card::note(
			'emails-holds',
			__( 'What is in an email', 'estasoft-news-distribution' ),
			Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-ops-checks' ), $checks )
		) . Card::cannot(
			array(
				__( 'Read replies. Every email says so, and the button is the way back.', 'estasoft-news-distribution' ),
				__( 'Tell you an email was read. There are no tracking images or tracked links.', 'estasoft-news-distribution' ),
				__( 'Send from another address. It uses your site’s WordPress mail, so an SMTP plugin that changes the sender changes it here too.', 'estasoft-news-distribution' ),
			)
		);

		return $this->crumbs( __( 'Emails', 'estasoft-news-distribution' ) )
			. PageHeader::render(
				__( 'Emails', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %s: the site name. */
					__( 'What News Distribution writes to people. Sample emails, with names from %s. To send a test email, open Alerts.', 'estasoft-news-distribution' ),
					$site
				),
				Button::link( __( 'Open Alerts', 'estasoft-news-distribution' ), $alerts_url )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-emails' ), $cards )
			. $table
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $notes );
	}

	/**
	 * The Weekly tab: a preview of Monday's email, built from the week so far.
	 *
	 * @return string Escaped HTML, a whole page.
	 */
	public function weekly_page(): string {
		$digest = $this->plugin->make( Digest::class );
		$model  = $digest->view_model( $digest->build( null, 'so_far' ) );
		$on     = $this->alerts->is_on( Alerts::WEEKLY );

		$switch = CommandBar::segments(
			array(
				'preview'  => array(
					'label' => __( 'Preview', 'estasoft-news-distribution' ),
					'url'   => Links::settings( 'alerts', array( 'tab' => 'weekly' ) ),
				),
				'settings' => array(
					'label' => __( 'Settings', 'estasoft-news-distribution' ),
					'url'   => Links::settings( 'alerts' ) . '#estasoft-nd-card-alerts-what',
				),
			),
			'preview',
			__( 'Weekly summary email', 'estasoft-news-distribution' )
		);

		if ( $on && $this->alerts->addresses() !== array() ) {
			$status = Message::text(
				'info',
				sprintf(
					/* translators: %s: a date and time, such as "Monday 26 Oct, 08:00". */
					__( 'The next summary is sent on %s.', 'estasoft-news-distribution' ),
					wp_date( 'l j M, H:i', $digest->next_run(), Clock::zone() )
				)
			);
		} elseif ( $on ) {
			$status = Message::text( 'warning', __( 'Weekly summary is on, but there is no address to send it to. Add one in Settings, Alerts.', 'estasoft-news-distribution' ) );
		} else {
			$status = Message::text( 'info', __( 'Weekly summary is off. Turn it on in Settings, Alerts to get this email each Monday.', 'estasoft-news-distribution' ) );
		}

		return $this->crumbs( __( 'Weekly summary', 'estasoft-news-distribution' ) )
			. PageHeader::render(
				__( 'Weekly summary email', 'estasoft-news-distribution' ),
				__( 'Sent every Monday to the addresses in Settings, Alerts. It arrives in each person’s WordPress language. This preview uses the week so far.', 'estasoft-news-distribution' )
			)
			. Html::tag( 'div', array(), $switch )
			. $status
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-digest' ), EmailView::digest_card( $model ) );
	}

	/**
	 * "Settings / Alerts / Emails".
	 *
	 * @param string $here The current page's name.
	 * @return string Escaped HTML.
	 */
	private function crumbs( string $here ): string {
		return Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-small estasoft-nd-ops-crumbs',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			Html::tag( 'a', array( 'href' => esc_url( Links::settings() ) ), esc_html__( 'Settings', 'estasoft-news-distribution' ) )
			. ' / '
			. Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'alerts' ) ) ), esc_html__( 'Alerts', 'estasoft-news-distribution' ) )
			. ' / '
			. Html::tag( 'span', array( 'aria-current' => 'page' ), esc_html( $here ) )
		);
	}
}
