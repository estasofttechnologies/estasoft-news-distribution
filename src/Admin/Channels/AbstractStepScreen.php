<?php
/**
 * What the Microsoft Start, Yahoo News, WebSub and IndexNow pages share.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Admin;
use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\AddressRow;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Dialog;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Removed;
use Estasoft\NewsDistribution\Core\Retry;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * A channel page that reads as a short list of steps: numbered sections in one
 * card, with the cards that explain the rules and the limits beside it.
 *
 * Everything works without a script. A button that reaches another server (a test,
 * a check) is a link that carries a nonce; a button that changes something is a
 * form post with a nonce. Both are handled in handle(), which checks the
 * permission, runs perform() and returns to the page with the result remembered
 * for one view. Scripts only improve it (copy, add a row, live progress).
 */
abstract class AbstractStepScreen extends AbstractChannelScreen {

	/**
	 * Forms printed after the page. A button inside the settings form that must
	 * post somewhere else points at one of these with its `form` attribute.
	 *
	 * @var array<string, string>
	 */
	private array $forms = array();

	/**
	 * The actions this page takes, as name => "get" or "post". A "get" action is
	 * a link with a nonce, for a test or a check that only reads or asks. A "post"
	 * action changes something.
	 *
	 * @return array<string, string>
	 */
	abstract protected function known_actions(): array;

	/**
	 * Does one action. The permission and the nonce were checked before this.
	 *
	 * @param string               $action The action name.
	 * @param array<string, mixed> $input  The posted values, unslashed, or an empty array for a link.
	 * @return array<string, scalar> Query arguments for the address the person returns to.
	 */
	abstract protected function perform( string $action, array $input ): array;

	/**
	 * The values a form action reads, each cleaned as it is read. A screen whose actions need a value from the form
	 * says so here; a link or a button with no value needs nothing.
	 *
	 * @return array<string, mixed>
	 */
	protected function step_input(): array {
		return array();
	}

	/**
	 * Runs the action a link or a form asked for, then returns to the page.
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function handle( string $tab ): void {
		$post = 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' );

		// A plain view of the page names no action and does nothing here.
		if ( ! ( $post ? isset( $_POST['do'] ) : isset( $_GET['do'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Only asks whether an action was named; the nonce is checked on the next line, before the name is read.
			return;
		}

		check_admin_referer( $this->page_nonce() );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$action = sanitize_key( wp_unslash( $post ? $_POST['do'] : $_GET['do'] ) );
		$known  = $this->known_actions();

		if ( '' === $action || ! isset( $known[ $action ] ) || ( $post ? 'post' : 'get' ) !== $known[ $action ] ) {
			return;
		}

		$args = $this->perform( $action, $post ? $this->step_input() : array() );

		wp_safe_redirect( $this->url( $tab, $args ) );
		exit;
	}

	/**
	 * The nonce action of the page's actions. It names the page, not the action: the action is read after the nonce passes.
	 *
	 * @return string
	 */
	protected function page_nonce(): string {
		return 'estasoft_nd_do_' . $this->channel->id();
	}

	/**
	 * A link that runs a "get" action, with its nonce.
	 *
	 * @param string                $action The action name.
	 * @param string                $tab    The tab to return to.
	 * @param array<string, scalar> $args   More query arguments.
	 * @return string
	 */
	protected function action_url( string $action, string $tab = '', array $args = array() ): string {
		return wp_nonce_url( $this->url( $tab, array_merge( $args, array( 'do' => $action ) ) ), $this->page_nonce() );
	}

	/**
	 * A button that posts a "post" action from a form printed after the page, so it
	 * can sit inside another form without nesting.
	 *
	 * @param string               $action The action name.
	 * @param string               $label  Button label (plain).
	 * @param array<string, mixed> $opts   Button options. `fields` adds hidden values, `tab` is the tab to return to.
	 * @return string
	 */
	protected function post_button( string $action, string $label, array $opts = array() ): string {
		$id     = 'estasoft-nd-do-' . $action;
		$fields = (array) ( $opts['fields'] ?? array() );
		unset( $opts['fields'] );

		$inputs = '';

		foreach ( array_merge( array( 'do' => $action ), $fields ) as $name => $value ) {
			$inputs .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => (string) $name,
					'value' => (string) $value,
				)
			);
		}

		$this->forms[ $id ] = Html::tag(
			'form',
			array(
				'id'     => $id,
				'method' => 'post',
				'action' => $this->url( (string) ( $opts['tab'] ?? '' ) ),
			),
			$inputs . wp_nonce_field( $this->page_nonce(), '_wpnonce', false, false )
		);

		unset( $opts['tab'] );
		$opts['attrs'] = array_merge( (array) ( $opts['attrs'] ?? array() ), array( 'form' => $id ) );

		return Button::submit( $label, $opts );
	}

	/**
	 * A form that posts a "post" action, for controls that sit together with the
	 * button (a choice and the button that acts on it). Not for use inside the
	 * settings form: a form never holds a form.
	 *
	 * @param string $action The action name.
	 * @param string $tab    The tab to return to.
	 * @param string $inner  Trusted HTML: the controls and the button.
	 * @return string
	 */
	protected function post_form( string $action, string $tab, string $inner ): string {
		return Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => $this->url( $tab ),
				'class'  => 'estasoft-nd-stack estasoft-nd-gap-12',
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'do',
					'value' => $action,
				)
			)
			. wp_nonce_field( $this->page_nonce(), '_wpnonce', true, false )
			. $inner
		);
	}

	/**
	 * The forms that go after the page.
	 *
	 * @return string
	 */
	protected function forms(): string {
		return implode( '', $this->forms );
	}

	/**
	 * The result of the last action, shown once, and the question a link asked.
	 *
	 * @return string
	 */
	protected function notice(): string {
		$html = '';
		$key  = $this->result_key();

		if ( Query::has( 'done' ) ) {
			$result = get_transient( $key );

			if ( is_array( $result ) ) {
				delete_transient( $key );
				$html .= Message::bar( ! empty( $result['ok'] ) ? 'success' : 'danger', esc_html( (string) ( $result['message'] ?? '' ) ) );
			}
		}

		return $html;
	}

	/**
	 * Keeps the result of a test a person started as the channel's latest test and
	 * remembers a sentence about it for the next view.
	 *
	 * @param array<string, mixed> $result What the channel's test returned: ok, message and more.
	 * @return array<string, scalar> The query argument that makes the page show it.
	 */
	protected function finish_test( array $result ): array {
		$ok = ! empty( $result['ok'] );

		if ( ! isset( $result['time'] ) ) {
			$result['time'] = Clock::now();
		}

		$this->plugin->channel_settings()->set_last_test( $this->channel->id(), $result );

		return $this->remember(
			$ok,
			$ok
				? sprintf(
					/* translators: 1: the words "Test passed", 2: what the test found, such as "The hub answered 204 No Content." */
					__( '%1$s. %2$s', 'estasoft-news-distribution' ),
					Status::word( Status::TEST_PASSED ),
					(string) ( $result['message'] ?? '' )
				)
				: (string) ( $result['message'] ?? '' )
		);
	}

	/**
	 * Remembers what an action did, to show on the next view.
	 *
	 * @param bool   $ok      Whether it worked.
	 * @param string $message A sentence.
	 * @return array<string, scalar> The query argument that makes the page show it.
	 */
	protected function remember( bool $ok, string $message ): array {
		set_transient(
			$this->result_key(),
			array(
				'ok'      => $ok,
				'message' => $message,
			),
			2 * MINUTE_IN_SECONDS
		);

		return array( 'done' => 1 );
	}

	/**
	 * Where the result of an action is kept.
	 *
	 * @return string
	 */
	private function result_key(): string {
		return 'estasoft_nd_done_' . get_current_user_id() . '_' . sanitize_key( $this->channel->id() );
	}

	/**
	 * Asks before something that cannot be undone. With a script it is a dialog;
	 * without one the link opens the page with the question in a bar.
	 *
	 * @param string               $id      Short name of the question.
	 * @param string               $trigger Label of the button that asks.
	 * @param string               $title   The question.
	 * @param string               $text    One or two sentences about what happens and what does not.
	 * @param string               $confirm Label of the button that does it.
	 * @param string               $action  The "post" action the button runs.
	 * @param string               $tab     The tab to return to.
	 * @param array<string, mixed> $opts Options of the trigger button.
	 * @return string The trigger and the dialog.
	 */
	protected function confirmable( string $id, string $trigger, string $title, string $text, string $confirm, string $action, string $tab = '', array $opts = array() ): string {
		$opts['attrs'] = array_merge( (array) ( $opts['attrs'] ?? array() ), array( 'data-open-dialog' => 'estasoft-nd-dlg-' . sanitize_key( $id ) ) );

		return Button::link( $trigger, $this->url( $tab, array( 'confirm' => $id ) ), $opts )
			. Dialog::render(
				$id,
				$title,
				$text,
				$this->post_button(
					$action,
					$confirm,
					array(
						'variant' => 'primary',
						'tab'     => $tab,
					)
				)
			);
	}

	/**
	 * The question for a link that was followed without a script.
	 *
	 * @param array<string, array{title: string, text: string, confirm: string, action: string, tab?: string}> $questions Questions by id.
	 * @return string
	 */
	protected function question( array $questions ): string {
		$asked = Query::key( 'confirm' );

		if ( '' === $asked || ! isset( $questions[ $asked ] ) ) {
			return '';
		}

		$ask = $questions[ $asked ];

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html( $ask['title'] ) ) . ' ' . esc_html( $ask['text'] ),
			$this->post_button(
				$ask['action'],
				$ask['confirm'],
				array(
					'variant' => 'primary',
					'tab'     => (string) ( $ask['tab'] ?? '' ),
				)
			)
			. Button::link( __( 'Cancel', 'estasoft-news-distribution' ), $this->url( (string) ( $ask['tab'] ?? '' ) ) )
		);
	}

	/**
	 * Loads the styles and scripts every step page uses.
	 *
	 * @param string $tab The tab slug.
	 * @return void
	 */
	public function enqueue( string $tab ): void {
		Assets::screen_style( 'address-row' );
		Assets::screen_script( 'address-row' );
		Assets::screen_style( 'channel-steps' );
		Assets::screen_script( 'channel-steps' );
	}

	/**
	 * The two columns of the page: the main cards and the side cards.
	 *
	 * @param string $main Trusted HTML.
	 * @param string $side Trusted HTML.
	 * @return string
	 */
	protected function split( string $main, string $side ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-split' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $main )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side' ), $side )
		);
	}

	/**
	 * One card of numbered steps. A done step shows a check, the step that waits
	 * for the person is tinted and its number is outlined.
	 *
	 * @param string                                                                                  $id    Unique id.
	 * @param string                                                                                  $label What the list is, for screen readers.
	 * @param array<int, array{id: string, title: string, text: string, state: string, body: string}> $steps The steps, in order.
	 * @return string
	 */
	protected function steps( string $id, string $label, array $steps ): string {
		$items = '';

		foreach ( array_values( $steps ) as $index => $step ) {
			$number   = $index + 1;
			$title_id = 'estasoft-nd-step-' . sanitize_key( $id . '-' . $step['id'] );
			$state    = (string) $step['state'];

			if ( 'done' === $state ) {
				$badge = Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-step__num estasoft-nd-step__num--done' ),
					Icon::svg( 'check' ) . Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-sr' ),
						esc_html(
							sprintf(
								/* translators: %d: the number of a step. */
								__( 'Step %d is done.', 'estasoft-news-distribution' ),
								$number
							)
						)
					)
				);
			} else {
				$badge = Html::tag(
					'span',
					array( 'class' => Html::classes( 'estasoft-nd-step__num', 'now' === $state ? 'estasoft-nd-step__num--now' : '' ) ),
					esc_html( (string) $number )
				);
			}

			$head = Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title',
					'id'    => $title_id,
				),
				esc_html( (string) $step['title'] )
			);

			if ( '' !== (string) $step['text'] ) {
				$head .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $step['text'] ) );
			}

			$items .= Html::tag(
				'section',
				array(
					'class'           => Html::classes( 'estasoft-nd-step', 'now' === $state ? 'estasoft-nd-step--now' : '' ),
					'aria-labelledby' => $title_id,
				),
				$badge . Html::tag( 'div', array( 'class' => 'estasoft-nd-step__body' ), Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $head ) . (string) $step['body'] )
			);
		}

		return Html::tag(
			'div',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-card--steps',
				'role'       => 'group',
				'aria-label' => $label,
				'id'         => 'estasoft-nd-steps-' . sanitize_key( $id ),
			),
			$items
		);
	}

	/**
	 * A feed address: the language, the address and Copy, then what is known about it.
	 *
	 * @param string $language Language code.
	 * @param string $url      The address.
	 * @param string $what     What it is, for the button's name, such as "Microsoft Start feed".
	 * @param string $facts    Trusted HTML shown after the button, or empty.
	 * @return string
	 */
	protected function address_line( string $language, string $url, string $what, string $facts = '' ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-address-line' ),
			AddressRow::language( $language, $url, $what ) . ( '' !== $facts ? Html::tag( 'div', array( 'class' => 'estasoft-nd-address-line__facts' ), $facts ) : '' )
		);
	}

	/**
	 * The "Rules News Distribution applies" card. A rule that is not confirmed from
	 * the platform's own page adds the careful note.
	 *
	 * @param string                $platform_id The rule pack's platform id.
	 * @param string                $platform    The platform's name.
	 * @param array<string, string> $rules       Rule codes (several joined with "|") => the sentence to print.
	 * @return string
	 */
	protected function rules_card( string $platform_id, string $platform, array $rules ): string {
		$list    = '';
		$careful = false;
		$packs   = $this->plugin->rules();

		foreach ( $rules as $codes => $sentence ) {
			$found = false;

			foreach ( explode( '|', (string) $codes ) as $code ) {
				$rule = $packs->rule( $platform_id, $code );

				if ( array() !== $rule ) {
					$found   = true;
					$careful = $careful || 'confirmed' !== (string) ( $rule['confidence'] ?? '' );
				}
			}

			if ( $found ) {
				$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--ok' ), Icon::svg( 'check' ) . Html::tag( 'span', array(), esc_html( (string) $sentence ) ) );
			}
		}

		$foot = sprintf(
			/* translators: %s: the version of the rule pack, such as 2026.09.2. */
			__( 'Rule pack %s', 'estasoft-news-distribution' ),
			$packs->version( $platform_id )
		);

		$body = Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html( $foot ) . ' · ' . Html::tag(
					'a',
					array(
						'href' => add_query_arg(
							array(
								'page'    => 'estasoft-nd-settings',
								'section' => 'rules',
							),
							admin_url( 'admin.php' )
						),
					),
					esc_html__( 'Platform rules', 'estasoft-news-distribution' )
				)
			);

		if ( $careful ) {
			$body .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: the name of a platform, such as Yahoo News. */
						__( 'Some of these are News Distribution\'s own choices or come from a standard, not from a page %s publishes. Check with the platform if a feed is refused.', 'estasoft-news-distribution' ),
						$platform
					)
				)
			);
		}

		return Card::note( 'rules', __( 'Rules News Distribution applies', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * A note card with one paragraph.
	 *
	 * @param string $id    Unique id.
	 * @param string $title Heading.
	 * @param string $text  The paragraph.
	 * @return string
	 */
	protected function paragraph_card( string $id, string $title, string $text ): string {
		return Card::note( $id, $title, Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) ) );
	}

	/**
	 * The "What it cannot do" card with one paragraph.
	 *
	 * @param string $text The paragraph.
	 * @return string
	 */
	protected function cannot_card( string $text ): string {
		return $this->paragraph_card( 'cannot', __( 'What it cannot do', 'estasoft-news-distribution' ), $text );
	}

	/**
	 * A link to another site, with the external-link icon.
	 *
	 * @param string $label Link text.
	 * @param string $url   Address.
	 * @return string
	 */
	protected function external_link( string $label, string $url ): string {
		return Html::tag(
			'a',
			array(
				'href'   => $url,
				'target' => '_blank',
				'rel'    => 'noopener noreferrer',
				'class'  => 'estasoft-nd-cluster estasoft-nd-gap-4 estasoft-nd-strong',
			),
			esc_html( $label ) . Icon::svg( 'external-link' )
		);
	}

	/**
	 * A link to another page of the plugin.
	 *
	 * @param string                $label Link text.
	 * @param string                $page  The page slug, such as "estasoft-nd-check".
	 * @param array<string, scalar> $args  More query arguments.
	 * @return string
	 */
	protected function page_link( string $label, string $page, array $args = array() ): string {
		return Html::tag(
			'a',
			array( 'href' => add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) ),
			esc_html( $label )
		);
	}

	/**
	 * A bar that says the channel is off, with a button that turns it on. It posts
	 * to the channel form handler, so it works without a script.
	 *
	 * @param string $text What does not work while the channel is off.
	 * @return string
	 */
	protected function off_bar( string $text ): string {
		if ( $this->channel->is_enabled() ) {
			return '';
		}

		$id   = $this->channel->id();
		$form = Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => 'estasoft_nd_save_channel',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'channel',
					'value' => $id,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'estasoft_nd[enabled]',
					'value' => '1',
				)
			)
			. wp_nonce_field( Admin::NONCE_CHANNEL, '_wpnonce', true, false )
			. Button::submit( __( 'Turn on', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
		);

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html__( 'This channel is off.', 'estasoft-news-distribution' ) ) . ' ' . esc_html( $text ),
			$form
		);
	}

	/**
	 * Some of the channel's settings as a stack of controls, in the order given.
	 * A rejected save puts back what was typed and names the field that failed.
	 *
	 * @param string[]                $names  Field names.
	 * @param array<string, string>   $helps  Help text to use instead of the field's own, by field name.
	 * @param array<string, string[]> $nested Fields that show under a switch only while it is on, by the switch's name.
	 * @return string
	 */
	protected function toggles( array $names, array $helps = array(), array $nested = array() ): string {
		$specs  = $this->channel->fields();
		$values = $this->values();
		$errors = $this->errors();
		$html   = '';

		foreach ( $names as $name ) {
			if ( ! isset( $specs[ $name ] ) ) {
				continue;
			}

			$spec = $specs[ $name ];

			if ( isset( $helps[ $name ] ) ) {
				$spec['help'] = $helps[ $name ];
			}

			$html .= Field::render( $this->scope(), $name, $spec, $values[ $name ] ?? Field::default_of( $spec ), (string) ( $errors[ $name ] ?? '' ) );

			if ( isset( $nested[ $name ] ) ) {
				$html .= $this->nested( $name, $nested[ $name ], $values, $errors );
			}
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $html );
	}

	/**
	 * The settings that belong under a switch and show only while it is on.
	 *
	 * @param string               $toggle The switch's field name.
	 * @param string[]             $names  The fields under it.
	 * @param array<string, mixed> $values Values as shown.
	 * @param array<string, mixed> $errors Messages by field name.
	 * @return string
	 */
	private function nested( string $toggle, array $names, array $values, array $errors ): string {
		$specs = $this->channel->fields();
		$html  = '';

		foreach ( $names as $name ) {
			if ( ! isset( $specs[ $name ] ) ) {
				continue;
			}

			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => Field::id( $this->scope(), $name ),
					),
					esc_html( (string) ( $specs[ $name ]['label'] ?? $name ) )
				)
				. Field::render( $this->scope(), $name, $specs[ $name ], $values[ $name ] ?? Field::default_of( $specs[ $name ] ), (string) ( $errors[ $name ] ?? '' ) )
			);
		}

		return Html::tag(
			'div',
			array(
				'class'        => 'estasoft-nd-nested',
				'data-show-if' => (string) wp_json_encode( array( $toggle => true ) ),
				'hidden'       => empty( $values[ $toggle ] ),
			),
			$html
		);
	}

	/**
	 * A list of short sentences, each with the same small icon in front.
	 *
	 * @param string   $icon  Icon name.
	 * @param string[] $items Sentences (plain).
	 * @param bool     $rule  Whether the items read as rules the plugin applies (a green check) or as notes.
	 * @return string
	 */
	protected function icon_list( string $icon, array $items, bool $rule ): string {
		$html = '';

		foreach ( $items as $text ) {
			$html .= Html::tag(
				'li',
				array( 'class' => $rule ? 'estasoft-nd-rule estasoft-nd-rule--ok' : '' ),
				Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( $text ) )
			);
		}

		return Html::tag( 'ul', array( 'class' => $rule ? 'estasoft-nd-stack estasoft-nd-gap-8' : 'estasoft-nd-facts-list' ), $html );
	}

	/**
	 * A card with a short table of delivery log rows, or a plain empty note when
	 * there are none. The table runs edge to edge under the heading.
	 *
	 * @param string                         $id          Unique id.
	 * @param string                         $title       Heading.
	 * @param string                         $intro       One line under the heading (plain).
	 * @param string                         $cols_class  Class that sets the grid columns.
	 * @param array<int, string>             $headers     Column headings (plain).
	 * @param array<int, array<int, string>> $rows        Rows of cells; each cell is trusted HTML.
	 * @param string                         $actions     Trusted HTML at the end of the heading, or empty.
	 * @param string                         $empty_title What is missing (plain).
	 * @param string                         $empty_text  What puts rows here (plain).
	 * @return string
	 */
	protected function rows_card( string $id, string $title, string $intro, string $cols_class, array $headers, array $rows, string $actions, string $empty_title, string $empty_text ): string {
		if ( array() === $rows ) {
			return Card::flush(
				$id,
				$title,
				'',
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-empty' ),
					Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html( $empty_title ) )
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $empty_text ) )
				)
			);
		}

		return Card::flush( $id, $title, $intro, Grid::render( $cols_class, $headers, $rows, $title ), $actions );
	}

	/**
	 * The delivery log, filtered to this channel.
	 *
	 * @return string
	 */
	protected function log_url(): string {
		return add_query_arg(
			array(
				'page'    => 'estasoft-nd-log',
				'channel' => $this->channel->id(),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Of the rows given, newest first, the ones that are still being tried again
	 * and the ones that gave up. Only the newest row of an article and event counts,
	 * so a delivery that failed and then went through is in neither list. Tests a
	 * person started are left out.
	 *
	 * @param array<int, array<string, mixed>> $rows Delivery log rows, newest first.
	 * @return array{retrying: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>}
	 */
	protected function newest( array $rows ): array {
		$seen = array();
		$out  = array(
			'retrying' => array(),
			'failed'   => array(),
		);

		foreach ( $rows as $row ) {
			$key = $row['post_id'] . '|' . $row['event'];

			// A test a person started is answered in a bar of its own, once.
			if ( isset( $seen[ $key ] ) || 'test' === $row['event'] ) {
				continue;
			}

			$seen[ $key ] = true;

			if ( Status::RETRY === $row['status'] ) {
				$out['retrying'][] = $row;
			} elseif ( Status::FAILED === $row['status'] ) {
				$out['failed'][] = $row;
			}
		}

		return $out;
	}

	/**
	 * The title of the article a delivery log row is about.
	 *
	 * @param array<string, mixed> $row A delivery log row.
	 * @return string Plain text, or an empty string for a row that is about no article.
	 */
	protected function row_title( array $row ): string {
		$id = (int) $row['post_id'];

		if ( $id < 1 ) {
			return '';
		}

		$title = get_the_title( $id );

		if ( '' === $title ) {
			$title = Removed::recall( $id )['title'];
		}

		return '' !== $title ? $title : sprintf(
			/* translators: %d: an article number. */
			__( 'Article %d', 'estasoft-news-distribution' ),
			$id
		);
	}

	/**
	 * What the other side answered: the code and its words. A message that already
	 * names the code is not given the code twice.
	 *
	 * @param array<string, mixed> $row A delivery log row.
	 * @return string Plain text.
	 */
	protected function answer_words( array $row ): string {
		$code = (int) $row['response_code'];
		$text = trim( (string) $row['message'] );

		if ( $code > 0 && false === strpos( $text, (string) $code ) ) {
			return trim( $code . ' ' . $text );
		}

		return $text;
	}

	/**
	 * What the other side answered, in one line: the code and its words, and when
	 * the next try is.
	 *
	 * @param array<string, mixed> $row A delivery log row.
	 * @return string Plain text.
	 */
	protected function answer_text( array $row ): string {
		if ( Status::QUEUED === $row['status'] ) {
			return trim( (string) $row['message'] );
		}

		$out  = $this->answer_words( $row );
		$next = (int) $row['run_at_ts'] > 0 ? Clock::format( (int) $row['run_at_ts'] ) : '';

		if ( Status::RETRY === $row['status'] && '' !== $next && false === strpos( $out, $next ) ) {
			$out .= ' ' . sprintf(
				/* translators: %s: the time of the next try, such as 14:37. */
				__( 'Next try at %s.', 'estasoft-news-distribution' ),
				$next
			);
		}

		return trim( $out );
	}

	/**
	 * The result of a delivery log row as an inline status. A row that waits says when.
	 *
	 * @param array<string, mixed> $row A delivery log row.
	 * @return string Trusted HTML.
	 */
	protected function result_cell( array $row ): string {
		$status = (string) $row['status'];
		$over   = null;

		if ( Status::QUEUED === $status && (int) $row['run_at_ts'] > 0 ) {
			$over = sprintf(
				/* translators: 1: the word "Waiting", 2: the time the delivery runs, such as 14:38. */
				__( '%1$s %2$s', 'estasoft-news-distribution' ),
				Status::word( Status::QUEUED ),
				Clock::format( (int) $row['run_at_ts'] )
			);
		}

		$html = StatusView::inline( $status, $over );

		if ( in_array( $status, array( Status::SENT, Status::RETRY, Status::FAILED ), true ) && (int) $row['attempt'] > 0 ) {
			$html .= Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: the number of the attempt, 2: the most attempts there are. */
						__( 'Attempt %1$d of %2$d', 'estasoft-news-distribution' ),
						(int) $row['attempt'],
						Retry::MAX_ATTEMPTS
					)
				)
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $html );
	}
}
