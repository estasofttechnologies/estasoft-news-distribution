<?php
/**
 * What the Apple News, SmartNews and News Break pages share.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\AppleNews\FeedDocument;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * A page for a platform the owner applies to by hand. Apple, SmartNews and News
 * Break each decide for themselves and News Distribution cannot see the answer,
 * so the page records the application as a status the owner sets (Core\Applications)
 * and never says more than it knows.
 *
 * The settings form posts to the page's own address. It saves the channel's
 * settings and the application in one step, with or without a script, and the
 * script only shows the save bar when something changed. The test button and the
 * dialogs come from AbstractStepScreen.
 */
abstract class AbstractPartnerScreen extends AbstractStepScreen {

	/**
	 * The name the application fields are posted under.
	 */
	public const APP = 'estasoft_nd_app';

	/**
	 * How the platform's fetches look, read once for the page.
	 *
	 * @var array<string, array{status: string, last_ts: int, fetches_24h: int, agent: string}>|null
	 */
	private ?array $states = null;

	/**
	 * Whether the channel has a feed to test and address rows to show.
	 *
	 * @return bool
	 */
	protected function has_feed(): bool {
		return true;
	}

	/**
	 * The channel's fields the settings form can post. A page with two forms
	 * returns the ones the open tab holds.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected function form_fields(): array {
		return $this->channel->fields();
	}

	/**
	 * Changes the posted channel values before they are saved, for what a plain
	 * field cannot carry (an uploaded image, a value typed in an "add" box).
	 *
	 * @param array<string, mixed>  $input  The posted channel values.
	 * @param array<string, mixed>  $post   Everything that was posted.
	 * @param array<string, string> $errors Messages by field name; add one to stop the save.
	 * @return array<string, mixed>
	 */
	protected function prepare_input( array $input, array $post, array &$errors ): array {
		unset( $post, $errors );

		return $input;
	}

	/**
	 * What the page posts for the application, as Applications::save() takes it,
	 * or null when the page has no application fields.
	 *
	 * @param array<string, mixed> $post Everything that was posted.
	 * @return array<string, string>|null
	 */
	protected function application_input( array $post ): ?array {
		$app = isset( $post[ self::APP ] ) && is_array( $post[ self::APP ] ) ? $post[ self::APP ] : null;

		if ( null === $app ) {
			return null;
		}

		$id   = $this->channel->id();
		$now  = $this->plugin->applications()->get( $id );
		$row  = array();
		$open = array( Status::REQUESTED, Status::IN_REVIEW );

		// A tick box says "I asked": it moves between Not requested and Requested and leaves a later status alone.
		if ( array_key_exists( 'requested', $app ) ) {
			$ticked = '1' === (string) $app['requested'];

			if ( $ticked && Status::NOT_REQUESTED === $now['status'] ) {
				$row['status'] = Status::REQUESTED;

				if ( '' === $now['applied_on'] ) {
					$row['applied_on'] = Clock::format( Clock::now(), 'Y-m-d' );
				}
			} elseif ( ! $ticked && in_array( $now['status'], $open, true ) ) {
				$row['status'] = Status::NOT_REQUESTED;
			}
		}

		if ( array_key_exists( 'status', $app ) ) {
			$row['status'] = sanitize_key( (string) $app['status'] );
		}

		if ( array_key_exists( 'applied_on', $app ) ) {
			$row['applied_on'] = $this->date_from_text( (string) $app['applied_on'] );
		}

		return $row;
	}

	/**
	 * Runs the settings form, then the actions AbstractStepScreen knows.
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function handle( string $tab ): void {
		unset( $tab );

		if ( $this->posted( 'save' ) ) {
			$this->save();
		}

		// The page frame does not know these pages' tabs, so an action returns to the one it came from.
		parent::handle( $this->current_tab() );
	}

	/**
	 * The page actions: the feed test, which only reads.
	 *
	 * @return array<string, string>
	 */
	protected function known_actions(): array {
		return $this->has_feed() ? array( 'test' => 'get' ) : array();
	}

	/**
	 * Does one page action.
	 *
	 * @param string               $action The action name.
	 * @param array<string, mixed> $input  The posted values.
	 * @return array<string, scalar>
	 */
	protected function perform( string $action, array $input ): array {
		unset( $input );

		if ( 'test' !== $action ) {
			return array();
		}

		$lines = $this->test_problems();

		if ( array() === $lines ) {
			return $this->remember( true, __( 'Test passed: every feed is valid.', 'estasoft-news-distribution' ) );
		}

		return $this->remember(
			false,
			__( 'The test found problems:', 'estasoft-news-distribution' ) . ' ' . implode( ' ', $lines )
		);
	}

	/**
	 * The "Test feed" button.
	 *
	 * @param string $tab The tab slug.
	 * @return string
	 */
	public function actions( string $tab ): string {
		if ( ! $this->has_feed() ) {
			return '';
		}

		return Button::link(
			__( 'Test feed', 'estasoft-news-distribution' ),
			$this->action_url( 'test' ),
			array(
				'attrs' => array(
					'data-test-feed' => true,
					'data-endpoint'  => 'channels/' . rawurlencode( $this->channel->id() ) . '/validate',
				),
			)
		);
	}

	/**
	 * Loads the shared step styles and this page's own.
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function enqueue( string $tab ): void {
		parent::enqueue( $tab );
		Assets::screen_style( 'partner' );
		Assets::screen_script(
			'partner',
			array(
				'text' => array_merge(
					array(
						'running'  => __( 'Testing the feeds…', 'estasoft-news-distribution' ),
						'passed'   => __( 'Test passed: every feed is valid.', 'estasoft-news-distribution' ),
						'problems' => __( 'The test found problems:', 'estasoft-news-distribution' ),
						'failed'   => __( 'The test could not run.', 'estasoft-news-distribution' ),
						'offline'  => __( 'Could not reach your site.', 'estasoft-news-distribution' ),
						'remove'   => __( 'Remove', 'estasoft-news-distribution' ),
					),
					$this->script_text()
				),
			)
		);
	}

	/**
	 * Words only this page's script needs, by key.
	 *
	 * @return array<string, string>
	 */
	protected function script_text(): array {
		return array();
	}

	/**
	 * The tab that is open. These pages draw their own tabs, so the page frame
	 * does not know it.
	 *
	 * @return string One of tab_names(), or an empty string.
	 */
	protected function current_tab(): string {
		$wanted = Query::key( 'tab' );

		return in_array( $wanted, $this->tab_names(), true ) ? $wanted : (string) ( $this->tab_names()[0] ?? '' );
	}

	/**
	 * The tabs this page has, the first being the default.
	 *
	 * @return string[]
	 */
	protected function tab_names(): array {
		return array();
	}

	/**
	 * The test result area, the message a plain page load leaves and the bar that
	 * says the channel is off.
	 *
	 * @param string $off What does not work while the channel is off.
	 * @return string
	 */
	protected function head( string $off ): string {
		$region = $this->has_feed()
			? Html::tag(
				'div',
				array(
					'id'        => 'estasoft-nd-test-result',
					'role'      => 'status',
					'aria-live' => 'polite',
					'hidden'    => true,
				),
				''
			)
			: '';

		return $region . $this->notice() . $this->off_bar( $off );
	}

	/**
	 * The form around the settings. It posts to this page and saves the channel's
	 * settings and the application together.
	 *
	 * @param string $inner   Escaped HTML of the cards.
	 * @param string $tab     The open tab, so the save returns to it.
	 * @param string $button  The label of the save button, or an empty string for "Save changes".
	 * @param bool   $uploads Whether the form carries files.
	 * @return string
	 */
	protected function save_form( string $inner, string $tab = '', string $button = '', bool $uploads = false ): string {
		return Html::tag(
			'form',
			array(
				'method'            => 'post',
				'action'            => $this->url( $tab ),
				'class'             => 'estasoft-nd-stack estasoft-nd-gap-16',
				'novalidate'        => true,
				'enctype'           => $uploads ? 'multipart/form-data' : null,
				'data-partner-form' => true,
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'estasoft_nd_do',
					'value' => 'save',
				)
			)
			. wp_nonce_field( $this->save_nonce(), '_wpnonce', true, false )
			. $this->saved_message()
			. $inner
			. $this->save_bar( $button )
		);
	}

	/**
	 * The save bar. It is the shared one with a label of its own, such as "Save rules".
	 * Without a script it is always shown; with one it appears when something changes.
	 *
	 * @param string $label The save button's label, or an empty string for "Save changes".
	 * @return string
	 */
	private function save_bar( string $label ): string {
		$state = Html::tag(
			'span',
			array(
				'class'              => 'estasoft-nd-savebar__state',
				'data-savebar-state' => true,
				'hidden'             => true,
			),
			Icon::svg( 'warning' ) . Html::tag( 'strong', array(), esc_html__( 'You have unsaved changes', 'estasoft-news-distribution' ) )
		);

		$actions = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-savebar__actions' ),
			Button::button(
				__( 'Discard', 'estasoft-news-distribution' ),
				array(
					'attrs' => array(
						'data-savebar-discard' => true,
						'hidden'               => true,
					),
				)
			)
			. Button::submit( '' === $label ? __( 'Save changes', 'estasoft-news-distribution' ) : $label, array( 'variant' => 'primary' ) )
		);

		return Html::tag(
			'div',
			array(
				'class'        => 'estasoft-nd-savebar',
				'role'         => 'region',
				'aria-label'   => __( 'Unsaved changes', 'estasoft-news-distribution' ),
				'data-savebar' => true,
			),
			$state . $actions
		);
	}

	/**
	 * One of the channel's fields as a control, with the value and the error of
	 * a save that was refused.
	 *
	 * @param string               $name     Field name.
	 * @param array<string, mixed> $override Parts of the field's spec to change for this page, such as `help`.
	 * @return string
	 */
	protected function field( string $name, array $override = array() ): string {
		$fields = $this->channel->fields();

		if ( ! isset( $fields[ $name ] ) ) {
			return '';
		}

		$spec   = array_merge( $fields[ $name ], $override );
		$values = $this->values();
		$errors = $this->errors();

		return Field::render( $this->scope(), $name, $spec, $values[ $name ] ?? Field::default_of( $fields[ $name ] ), (string) ( $errors[ $name ] ?? '' ) );
	}

	/**
	 * A control with a label above it, as the settings steps print them.
	 *
	 * @param string               $name     Field name.
	 * @param string               $label    The label.
	 * @param array<string, mixed> $override Parts of the field's spec to change for this page, such as `help`.
	 * @return string
	 */
	protected function labelled( string $name, string $label, array $override = array() ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => Field::id( $this->scope(), $name ),
				),
				esc_html( $label )
			) . $this->field( $name, $override )
		);
	}

	/**
	 * The fields for the application: the status the owner set and the day applied.
	 *
	 * @param bool $with_status Whether to offer the status list (a page with a tick box leaves it out).
	 * @return string
	 */
	protected function application_fields( bool $with_status ): string {
		$row  = $this->plugin->applications()->get( $this->channel->id() );
		$html = '';

		if ( $with_status ) {
			$options = '';

			foreach ( Applications::statuses() as $code ) {
				$options .= Html::tag(
					'option',
					array(
						'value'    => $code,
						'selected' => $code === $row['status'],
					),
					esc_html( Status::word( $code ) )
				);
			}

			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => 'estasoft-nd-app-status',
					),
					esc_html__( 'Application status', 'estasoft-news-distribution' )
				)
				. Html::tag(
					'select',
					array(
						'class' => 'estasoft-nd-field estasoft-nd-w-200',
						'id'    => 'estasoft-nd-app-status',
						'name'  => self::APP . '[status]',
					),
					$options
				)
				. Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html__( 'Set by hand. Choosing Requested or In review keeps the feed as it is; nothing is sent to the platform.', 'estasoft-news-distribution' )
				)
			);
		}

		$html .= Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => 'estasoft-nd-app-applied',
				),
				esc_html__( 'Applied on', 'estasoft-news-distribution' )
			)
			. Html::void(
				'input',
				array(
					'class'        => 'estasoft-nd-field estasoft-nd-w-200',
					'type'         => 'text',
					'id'           => 'estasoft-nd-app-applied',
					'name'         => self::APP . '[applied_on]',
					'value'        => $this->date_for_field( $row['applied_on'] ),
					'autocomplete' => 'off',
					'placeholder'  => $this->date_for_field( Clock::format( Clock::now(), 'Y-m-d' ) ),
				)
			)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: a date such as 5 Oct 2026. */
						__( 'Day, month and year, for example %s. The Applications screen shows the same status.', 'estasoft-news-distribution' ),
						$this->date_for_field( Clock::format( Clock::now(), 'Y-m-d' ) )
					)
				)
			)
		);

		return $html;
	}

	/**
	 * A feed address for each language the channel carries, with Copy and what
	 * the plugin knows: whether the feed is valid and, when the platform has
	 * fetched it, when.
	 *
	 * @param string   $what     What the address is, for the button's name, such as "SmartNews feed".
	 * @param callable $summary  Takes the result of check_output() and returns the plain-text summary of a valid feed.
	 * @param bool     $fetches  Whether to add when the platform last fetched the feed.
	 * @return string
	 */
	protected function feed_lines( string $what, callable $summary, bool $fetches ): string {
		$channel = $this->channel;

		if ( ! $channel instanceof AbstractChannel || ! method_exists( $channel, 'feed_url' ) ) {
			return '';
		}

		$html   = '';
		$states = $fetches ? $this->fetch_states_for() : array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$check = $channel->check_output( FeedDocument::body( $this->plugin, $channel, $language ), $language );
			$facts = $check['valid']
				? StatusView::inline( array() === ( $check['warnings'] ?? array() ) ? Status::CLEAN : Status::WARN, (string) call_user_func( $summary, $check ) )
				: StatusView::inline( Status::FAILED, __( 'Not valid', 'estasoft-news-distribution' ) );

			if ( isset( $states[ $language ] ) && (int) $states[ $language ]['last_ts'] > 0 ) {
				$facts .= Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %s: how long ago, such as "8 min ago". */
							__( 'Last fetched %s', 'estasoft-news-distribution' ),
							FetchSummary::ago( (int) $states[ $language ]['last_ts'] )
						)
					)
				);
			}

			$facts = Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $facts );

			$notes = '';

			foreach ( array_merge( (array) $check['problems'], (array) ( $check['warnings'] ?? array() ) ) as $note ) {
				$notes .= Html::tag( 'li', array(), esc_html( (string) $note ) );
			}

			$html .= $this->address_line( $language, $channel->feed_url( $language ), $what, $facts . ( '' === $notes ? '' : Html::tag( 'ul', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), $notes ) ) );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $html );
	}

	/**
	 * How the platform's fetches look, per language, from the fetch log.
	 *
	 * @return array<string, array{status: string, last_ts: int, fetches_24h: int, agent: string}>
	 */
	protected function fetch_states_for(): array {
		if ( null !== $this->states ) {
			return $this->states;
		}

		$languages = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			if ( $this->channel->accepts_language( (string) $language ) ) {
				$languages[] = (string) $language;
			}
		}

		$agents = array_values( array_filter( array_map( 'strval', (array) ( $this->plugin->rules()->pack( $this->channel->id() )['fetch']['agents'] ?? array() ) ) ) );

		$this->states = FetchSummary::states( array( $this->channel->id() ), $agents, $languages, $this->plugin->rules()->fetch_interval_hours( $this->channel->id() ) );

		return $this->states;
	}

	/**
	 * Whether the platform has fetched any language of the feed.
	 *
	 * @return bool
	 */
	protected function has_been_fetched(): bool {
		foreach ( $this->fetch_states_for() as $state ) {
			if ( (int) $state['last_ts'] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A date such as "28 Sep", from a Y-m-d date.
	 *
	 * @param string $date A date in Y-m-d form.
	 * @return string
	 */
	protected function short_date( string $date ): string {
		$time = strtotime( $date . ' 12:00:00 UTC' );

		return false === $time ? $date : gmdate( 'j M', $time );
	}

	/**
	 * A date such as "5 Oct 2026" for a field, from a Y-m-d date.
	 *
	 * @param string $date A date in Y-m-d form, or an empty string.
	 * @return string
	 */
	protected function date_for_field( string $date ): string {
		$time = '' === $date ? false : strtotime( $date . ' 12:00:00 UTC' );

		return false === $time ? '' : gmdate( 'j M Y', $time );
	}

	/**
	 * A Y-m-d date from what a person typed, or the text itself when it is not a date.
	 *
	 * @param string $text What was typed, such as "5 Oct 2026" or "2026-10-05".
	 * @return string
	 */
	protected function date_from_text( string $text ): string {
		$text = trim( $text );

		if ( '' === $text || Applications::is_date( $text ) ) {
			return $text;
		}

		$time = strtotime( $text . ' 12:00:00 UTC' );

		return false === $time ? $text : gmdate( 'Y-m-d', $time );
	}

	/**
	 * The message after a settings save.
	 *
	 * @return string
	 */
	protected function saved_message(): string {
		if ( Query::is( 'saved', '1' ) ) {
			return Message::text( 'success', __( 'Settings saved.', 'estasoft-news-distribution' ) );
		}

		if ( Query::is( 'error', '1' ) && array() !== $this->errors() ) {
			return Message::text( 'danger', __( 'Nothing was saved. Fix the highlighted settings and save again.', 'estasoft-news-distribution' ) );
		}

		return '';
	}

	/**
	 * The nonce action of the settings form.
	 *
	 * @return string
	 */
	protected function save_nonce(): string {
		return 'estasoft_nd_partner_save_' . $this->channel->id();
	}

	/**
	 * Whether this request is a post of the form with the given name.
	 *
	 * @param string $what The form's name, set in its hidden `estasoft_nd_do` field.
	 * @return bool
	 */
	protected function posted( string $what ): bool {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		if ( 'POST' !== $method || ! isset( $_POST['estasoft_nd_do'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether the form was sent; the nonce is checked on the next line, before its name is read.
			return false;
		}

		check_admin_referer( $this->save_nonce() );

		return sanitize_key( wp_unslash( $_POST['estasoft_nd_do'] ) ) === $what;
	}

	/**
	 * Saves the channel's settings and the application, then returns to the page.
	 *
	 * @return void
	 */
	private function save(): void {
		check_admin_referer( $this->save_nonce() );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		// The page's own fields, then the few others it posts: the application, what to remove or add, and the language boxes.
		$input = Field::posted( $this->form_fields(), $this->save_nonce() );
		$post  = array();

		foreach ( array( self::APP, 'estasoft_nd_remove', 'estasoft_nd_add', 'estasoft_nd_languages_set', 'estasoft_nd_languages' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$post[ $key ] = map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_textarea_field' );
			}
		}

		$errors = array();
		$input  = $this->prepare_input( $input, $post, $errors );
		$app    = $this->application_input( $post );
		$id     = $this->channel->id();

		if ( array() === $errors && array() !== $input ) {
			$result = $this->plugin->channel_settings()->save( $id, $this->form_fields(), $input );

			if ( is_wp_error( $result ) ) {
				$data   = $result->get_error_data();
				$errors = is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array( $id => $result->get_error_message() );
			}
		}

		if ( array() === $errors && null !== $app && array() !== $app ) {
			$saved = $this->plugin->applications()->save( $id, $app );

			if ( is_wp_error( $saved ) ) {
				$data   = $saved->get_error_data();
				$errors = is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array( $id => $saved->get_error_message() );
			}
		}

		$args = array();

		if ( array() !== $errors ) {
			set_transient(
				Admin::FORM_KEY . get_current_user_id(),
				array(
					'section' => $this->scope(),
					'values'  => $input,
					'errors'  => $errors,
				),
				2 * MINUTE_IN_SECONDS
			);
			$args['error'] = 1;
		} else {
			$args['saved'] = 1;
		}

		wp_safe_redirect( $this->url( $this->current_tab(), $args ) );
		exit;
	}

	/**
	 * Builds every feed now, checks it and lists what the plugin's own check found.
	 *
	 * @return string[] One sentence per language that has something to report.
	 */
	private function test_problems(): array {
		$channel = $this->channel;

		if ( ! $channel instanceof AbstractChannel ) {
			return array();
		}

		$lines = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$check = $channel->check_output( FeedDocument::body( $this->plugin, $channel, $language ), $language );

			foreach ( (array) $check['problems'] as $problem ) {
				$lines[] = strtoupper( $language ) . ': ' . $problem;
			}
		}

		return $lines;
	}
}
