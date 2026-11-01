<?php
/**
 * Settings, Alerts.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Operations\AlertsView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Alerts as AlertSender;
use Estasoft\NewsDistribution\Core\Chat\ChatAddress;
use Estasoft\NewsDistribution\Core\Digest;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Who is told about what, by email, in Slack and in Microsoft Teams. The sending is
 * `Core\Alerts`; the Monday summary is `Core\Digest`.
 *
 * Stored as `estasoft_nd_settings.alerts`: `send_to` (addresses separated by commas) and
 * `email_alerts`, `slack_alerts` and `teams_alerts` (the alert types that are ticked in each
 * column of "What to send where": the keys of `Core\Alerts::types()`; email only by default).
 *
 * The two webhook addresses, `slack_webhook` and `teams_webhook`, are write-only secrets kept in
 * `Core\Secrets` as `alerts_slack_webhook` and `alerts_teams_webhook`: never returned, never in an
 * export, a restore point or a log. Only an administrator can set or remove one (`secret_permission()`),
 * even where Editors may manage the plugin. An address is checked when it is saved (`check_secrets()`).
 *
 * The section owns two whole pages: `&tab=emails` (sample emails) and `&tab=weekly`
 * (a preview of the Monday email).
 */
final class Alerts extends Section {

	public const NAME = 'alerts';

	/**
	 * The largest number of alert addresses.
	 */
	public const MAX_ADDRESSES = 20;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$default = array();

		foreach ( AlertSender::types() as $type => $info ) {
			if ( $info['default'] ) {
				$default[] = $type;
			}
		}

		return array(
			'send_to'       => array(
				'type'         => 'string',
				'label'        => __( 'Send to', 'estasoft-news-distribution' ),
				'default'      => '',
				'max'          => 1000,
				'control'      => 'text',
				'width'        => 360,
				'autocomplete' => 'off',
				'placeholder'  => 'editor@example.com',
				'help'         => __( 'Separate several addresses with commas. Each address is checked when you save; if one is not valid, it is named here and nothing is saved until it is fixed.', 'estasoft-news-distribution' ),
			),
			'email_alerts'  => $this->type_list( __( 'Send by email', 'estasoft-news-distribution' ), $default ),
			'slack_alerts'  => $this->type_list( __( 'Send to Slack', 'estasoft-news-distribution' ), array() ),
			'teams_alerts'  => $this->type_list( __( 'Send to Microsoft Teams', 'estasoft-news-distribution' ), array() ),
			'slack_webhook' => array(
				'type'  => 'secret',
				'label' => __( 'Slack webhook address', 'estasoft-news-distribution' ),
				'max'   => ChatAddress::MAX,
			),
			'teams_webhook' => array(
				'type'  => 'secret',
				'label' => __( 'Microsoft Teams webhook address', 'estasoft-news-distribution' ),
				'max'   => ChatAddress::MAX,
			),
		);
	}

	/**
	 * A column of the "What to send where" table: a list of alert types.
	 *
	 * @param string   $label   The column's name.
	 * @param string[] $ticked  The types that are ticked on a new install.
	 * @return array<string, mixed>
	 */
	private function type_list( string $label, array $ticked ): array {
		return array(
			'type'      => 'list',
			'label'     => $label,
			'default'   => $ticked,
			'max_items' => 20,
			'item'      => array(
				'type'    => 'enum',
				'options' => array_map(
					static function ( array $info ): string {
						return $info['title'];
					},
					AlertSender::types()
				),
			),
		);
	}

	/**
	 * Only an administrator sets or removes a webhook address, even where Editors may manage the plugin.
	 *
	 * @return string
	 */
	public function secret_permission(): string {
		return Access::ADMIN;
	}

	/**
	 * Checks a Slack or Teams address that was typed. It must be the right kind of address and must not point inside a network.
	 *
	 * @param array<string, string> $changes Field => new value.
	 * @return array<string, string>
	 */
	public function check_secrets( array $changes ): array {
		$errors = array();

		foreach ( array(
			'slack_webhook' => 'slack',
			'teams_webhook' => 'teams',
		) as $field => $service ) {
			if ( isset( $changes[ $field ] ) ) {
				$problem = ChatAddress::problem( $service, $changes[ $field ] );

				if ( '' !== $problem ) {
					$errors[ $field ] = $problem;
				}
			}
		}

		return $errors;
	}

	public function title(): string {
		return __( 'Alerts', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Hear about a problem before a platform stops taking your articles.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 40;
	}

	public function affects_output(): bool {
		return false;
	}

	/**
	 * Tidies the list of addresses (one comma and a space between them) when every one is valid.
	 *
	 * An address that is not valid is left as typed, so `cross_check()` can name it.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		if ( isset( $input['send_to'] ) && is_string( $input['send_to'] ) ) {
			$parsed = AlertSender::parse_addresses( $input['send_to'] );

			if ( array() === $parsed['invalid'] ) {
				$input['send_to'] = implode( ', ', $parsed['valid'] );
			}
		}

		return $input;
	}

	/**
	 * Names every address that is not valid, and refuses a list that is too long.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		$parsed = AlertSender::parse_addresses( (string) ( $values['send_to'] ?? '' ) );

		if ( array() !== $parsed['invalid'] ) {
			return array(
				'send_to' => sprintf(
					/* translators: %s: the addresses that are not valid, separated by commas. */
					_n( '%s is not a valid email address.', '%s are not valid email addresses.', count( $parsed['invalid'] ), 'estasoft-news-distribution' ),
					implode( ', ', $parsed['invalid'] )
				),
			);
		}

		if ( count( $parsed['valid'] ) > self::MAX_ADDRESSES ) {
			return array(
				'send_to' => sprintf(
					/* translators: %d: the largest number of addresses. */
					__( 'Use at most %d addresses.', 'estasoft-news-distribution' ),
					self::MAX_ADDRESSES
				),
			);
		}

		return array();
	}

	/**
	 * Books the Monday email when the weekly summary was just turned on.
	 *
	 * @param array<string, mixed> $values   The saved values.
	 * @param array<string, mixed> $previous The values before the save.
	 * @return void
	 */
	public function saved( array $values, array $previous ): void {
		unset( $previous );

		foreach ( array( 'email_alerts', 'slack_alerts', 'teams_alerts' ) as $column ) {
			if ( in_array( AlertSender::WEEKLY, array_map( 'strval', (array) ( $values[ $column ] ?? array() ) ), true ) ) {
				Plugin::instance()->make( Digest::class )->schedule();
				break;
			}
		}
	}

	/**
	 * @param string $tab The `tab` argument.
	 * @return string|null
	 */
	public function render_tab( string $tab ): ?string {
		if ( 'emails' === $tab ) {
			return ( new AlertsView( Plugin::instance() ) )->emails_page();
		}

		if ( 'weekly' === $tab ) {
			return ( new AlertsView( Plugin::instance() ) )->weekly_page();
		}

		/**
		 * A whole page for another tab of the Alerts section (internal; the AI assistant owns `weekly-ai`).
		 *
		 * @param string|null $page Escaped HTML with its own single h1, or null.
		 * @param string      $tab  The `tab` argument.
		 */
		$page = apply_filters( 'estasoft_nd_internal_alerts_tab', null, $tab );

		return is_string( $page ) ? $page : null;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		$view = new AlertsView( Plugin::instance() );

		return array(
			'cards'  => array(
				array(
					'id'          => 'email',
					'title'       => __( 'Email', 'estasoft-news-distribution' ),
					'description' => __( 'Hear about a problem before a platform stops taking your articles.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'send_to' ),
						array(
							'label' => __( 'Test', 'estasoft-news-distribution' ),
							'html'  => array( $view, 'test_row' ),
						),
					),
				),
				array(
					'id'          => 'chat',
					'title'       => __( 'Chat', 'estasoft-news-distribution' ),
					'description' => __( 'A short message with a link back to the problem. News Distribution never sends article text.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Slack', 'estasoft-news-distribution' ),
							'html'  => array( $view, 'slack_row' ),
						),
						array(
							'label' => __( 'Microsoft Teams', 'estasoft-news-distribution' ),
							'html'  => array( $view, 'teams_row' ),
						),
					),
				),
				array(
					'id'          => 'what',
					'title'       => __( 'What to send where', 'estasoft-news-distribution' ),
					'description' => $view->matrix_description(),
					'rows'        => array(
						array(
							'wide' => true,
							'html' => array( $view, 'matrix' ),
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'What each alert holds', 'estasoft-news-distribution' ),
					'text'  => __( 'Email goes through your site’s mail setup to the addresses above. Slack and Teams get a short message with a link back to the problem, never article text. Nothing is sent until you add an address or a webhook and tick a box.', 'estasoft-news-distribution' ),
				),
			),
			'cannot' => array(
				__( 'See a platform’s own dashboard, so it cannot warn you about a rejection or a review result there.', 'estasoft-news-distribution' ),
				__( 'Make sure an email arrives. It hands the message to WordPress, and your host or mail plugin delivers it.', 'estasoft-news-distribution' ),
				__( 'See whether a message showed up in Slack or Teams. It can only see that the service accepted it.', 'estasoft-news-distribution' ),
			),
		);
	}
}
