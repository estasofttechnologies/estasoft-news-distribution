<?php
/**
 * Base for one tab of the Outlets page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Admin\Channels\OutletsScreen;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsChannel;
use Estasoft\NewsDistribution\Channels\Outlets\OutletsState;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Every tab returns escaped HTML. Forms post to this same page (see Actions);
 * there is one `do` button per action, and the first button of a form is a
 * hidden "Save", so pressing Enter in a field saves and never sends a test.
 */
abstract class AbstractTab {

	protected OutletsScreen $screen;
	protected OutletsChannel $channel;
	protected Plugin $plugin;

	/**
	 * The article a preview is drawn from, read once.
	 *
	 * @var array{0: Article, 1: bool}|null
	 */
	private ?array $preview = null;

	/**
	 * Creates the tab.
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
	 * The tab's slug in the address.
	 *
	 * @return string
	 */
	abstract public function slug(): string;

	/**
	 * The tab's cards.
	 *
	 * @return string Escaped HTML.
	 */
	abstract public function render(): string;

	/**
	 * What the outlets remembered.
	 *
	 * @return OutletsState
	 */
	protected function state(): OutletsState {
		return $this->channel->state();
	}

	/**
	 * The channel's settings as a screen shows them: secrets as masks, a rejected save put back.
	 *
	 * @return array<string, mixed>
	 */
	protected function values(): array {
		return $this->screen->view_values();
	}

	/**
	 * Wraps cards in a form that posts back to this tab.
	 *
	 * @param string                $inner  Escaped HTML of the cards.
	 * @param string                $label  What the form is, for people using a screen reader.
	 * @param array<string, string> $hidden Extra hidden values, such as the endpoint number.
	 * @param bool                  $dirty  Whether the form has a save bar that appears when something changes.
	 * @return string
	 */
	protected function form( string $inner, string $label, array $hidden = array(), bool $dirty = true ): string {
		$fields = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'estasoft_nd_outlets',
				'value' => '1',
			)
		) . Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'tab',
				'value' => $this->slug(),
			)
		);

		foreach ( $hidden as $name => $value ) {
			$fields .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => (string) $name,
					'value' => (string) $value,
				)
			);
		}

		// The first button of a form answers the Enter key. It only saves.
		$default = Html::tag(
			'button',
			array(
				'type'     => 'submit',
				'name'     => 'estasoft_nd_do',
				'value'    => 'save',
				'class'    => 'estasoft-nd-sr',
				'tabindex' => '-1',
			),
			esc_html__( 'Save settings', 'estasoft-news-distribution' )
		);

		return Html::tag(
			'form',
			array(
				'method'          => 'post',
				'action'          => $this->screen->url( $this->slug() ),
				'class'           => 'estasoft-nd-stack estasoft-nd-gap-24',
				'novalidate'      => true,
				'data-dirty-form' => $dirty ? true : null,
				'aria-label'      => $label,
			),
			$default . wp_nonce_field( Actions::NONCE, '_wpnonce', true, false ) . $fields . $inner
		);
	}

	/**
	 * A submit button that names its action.
	 *
	 * @param string               $action  The action, such as "turn_on".
	 * @param string               $label   Button text.
	 * @param array<string, mixed> $options Button options: variant, size, class, aria_label.
	 * @return string
	 */
	protected function action_button( string $action, string $label, array $options = array() ): string {
		$attrs = array(
			'name'  => 'estasoft_nd_do',
			'value' => $action,
		);

		if ( isset( $options['aria_label'] ) ) {
			$attrs['aria-label'] = (string) $options['aria_label'];
		}

		return Button::submit(
			$label,
			array(
				'variant' => (string) ( $options['variant'] ?? '' ),
				'size'    => (string) ( $options['size'] ?? '' ),
				'class'   => (string) ( $options['class'] ?? '' ),
				'attrs'   => $attrs,
			)
		);
	}

	/**
	 * The bar that appears when a form has unsaved changes.
	 *
	 * @param string $save Label of the save button.
	 * @return string
	 */
	protected function savebar( string $save ): string {
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
			) . $this->action_button( 'save', $save, array( 'variant' => 'primary' ) )
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
	 * Whether the person may enter or remove tokens, passwords and addresses that are kept secret.
	 *
	 * @return bool
	 */
	protected function can_change_secrets(): bool {
		return $this->screen->can_change_secrets();
	}

	/**
	 * A text box with a label only a screen reader needs.
	 *
	 * @param string               $field      Setting name.
	 * @param string               $label      The label.
	 * @param array<string, mixed> $attrs      Extra attributes for the input.
	 * @param bool                 $with_label Whether to print the label. False when a visible label already points at the box.
	 * @return string
	 */
	protected function text_input( string $field, string $label, array $attrs = array(), bool $with_label = true ): string {
		$values = $this->values();
		$id     = Field::id( $this->screen->scope_id(), $field );
		$error  = (string) ( $this->screen->field_errors()[ $field ] ?? '' );
		$input  = Html::void(
			'input',
			array_merge(
				array(
					'class'            => 'estasoft-nd-field estasoft-nd-outlets-input',
					'type'             => 'text',
					'id'               => $id,
					'name'             => Field::name( $field ),
					'value'            => isset( $values[ $field ] ) && is_scalar( $values[ $field ] ) ? (string) $values[ $field ] : '',
					'autocomplete'     => 'off',
					'aria-describedby' => '' !== $error ? $id . '-error' : null,
					'aria-invalid'     => '' !== $error ? 'true' : null,
				),
				$attrs
			)
		);

		return ( $with_label ? Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-sr',
				'for'   => $id,
			),
			esc_html( $label )
		) : '' ) . $input . $this->error_line( $id, $error );
	}

	/**
	 * The message under a field a save refused.
	 *
	 * @param string $id    The field's id.
	 * @param string $error The message, or an empty string.
	 * @return string
	 */
	protected function error_line( string $id, string $error ): string {
		if ( '' === $error ) {
			return '';
		}

		return Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-field-error',
				'id'    => $id . '-error',
				'role'  => 'alert',
			),
			Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $error ) )
		);
	}

	/**
	 * A box for a secret. A saved secret shows as a mask and is never shown again; typing replaces it.
	 * A person who cannot change secrets sees whether one is saved and nothing to type in.
	 *
	 * @param string $field      Setting name.
	 * @param string $label      The label.
	 * @param bool   $show_mask  Whether to print the mask. A secret that holds a token inside an address never does.
	 * @param bool   $with_label Whether to print the label (for a screen reader). False when a visible label already points at the box.
	 * @return string
	 */
	protected function secret_input( string $field, string $label, bool $show_mask = true, bool $with_label = true ): string {
		$values = $this->values();
		$id     = Field::id( $this->screen->scope_id(), $field );
		$is_set = ! empty( $values[ $field . '_is_set' ] );
		$html   = '';

		if ( $is_set ) {
			$html .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-6' ),
				Icon::svg( 'lock' )
				. ( $show_mask ? Html::tag( 'span', array( 'class' => 'estasoft-nd-mono' ), esc_html( (string) ( $values[ $field ] ?? '' ) ) ) : '' )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Saved', 'estasoft-news-distribution' ) )
			);
		}

		if ( ! $this->can_change_secrets() ) {
			return $html . ( $is_set ? '' : Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Only an administrator can enter this.', 'estasoft-news-distribution' ) ) );
		}

		$html .= ( $with_label ? Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-sr',
				'for'   => $id,
			),
			esc_html( $label )
		) : '' ) . Html::void(
			'input',
			array(
				'class'        => 'estasoft-nd-field estasoft-nd-outlets-input',
				'type'         => 'password',
				'id'           => $id,
				'name'         => Field::name( $field ),
				'value'        => '',
				'autocomplete' => 'new-password',
				'placeholder'  => $is_set ? __( 'Type a new value to replace it', 'estasoft-news-distribution' ) : $label,
			)
		);

		if ( $is_set ) {
			$html .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-small' ),
				Html::void(
					'input',
					array(
						'type'  => 'checkbox',
						'name'  => Field::name( $field . '__remove' ),
						'value' => '1',
					)
				) . esc_html__( 'Remove the saved value', 'estasoft-news-distribution' )
			);
		}

		return $html;
	}

	/**
	 * A checkbox with a bold title and a line of help, as a setting.
	 *
	 * @param string $field Setting name.
	 * @param string $title Bold title.
	 * @param string $help  Help under the title, or an empty string.
	 * @return string
	 */
	protected function checkbox( string $field, string $title, string $help = '' ): string {
		$values = $this->values();

		return Field::render(
			$this->screen->scope_id(),
			$field,
			array(
				'type'  => 'bool',
				'title' => $title,
				'help'  => $help,
			),
			! empty( $values[ $field ] )
		);
	}

	/**
	 * A language as a short tag.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	protected function lang( string $code ): string {
		return Tag::lang( $code );
	}

	/**
	 * A card in the side column: a title and what goes in it.
	 *
	 * @param string $title The title.
	 * @param string $inner Escaped HTML.
	 * @return string
	 */
	protected function side_card( string $title, string $inner ): string {
		return Html::tag(
			'section',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--padded' ),
			Html::tag( 'h2', array( 'class' => 'estasoft-nd-title' ), esc_html( $title ) ) . $inner
		);
	}

	/**
	 * A list of short facts, each with a small icon.
	 *
	 * @param string[] $items Plain texts.
	 * @param string   $icon  Icon name.
	 * @return string
	 */
	protected function rules( array $items, string $icon = 'info' ): string {
		$list = '';

		foreach ( $items as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( (string) $item ) ) );
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list );
	}

	/**
	 * A "What it cannot do" card.
	 *
	 * @param string[] $items Plain texts.
	 * @return string
	 */
	protected function cannot( array $items ): string {
		return $this->side_card( __( 'What it cannot do', 'estasoft-news-distribution' ), $this->rules( $items, 'minus-circle' ) );
	}

	/**
	 * The main column and the side column.
	 *
	 * @param string $main Escaped HTML.
	 * @param string $side Escaped HTML.
	 * @return string
	 */
	protected function split( string $main, string $side ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-split' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main estasoft-nd-outlets-main' ), $main )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-outlets-side' ), $side )
		);
	}

	/**
	 * The name of a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	protected function language_name( string $code ): string {
		return Catalog::name( $code );
	}

	/**
	 * A time as a person reads it: the time today, "yesterday", or the date.
	 *
	 * @param int $ts Unix time.
	 * @return string
	 */
	protected function when( int $ts ): string {
		$today = Clock::format( Clock::now(), 'Y-m-d' );
		$day   = Clock::format( $ts, 'Y-m-d' );

		if ( $day === $today ) {
			return Clock::format( $ts );
		}

		if ( Clock::format( Clock::now() - DAY_IN_SECONDS, 'Y-m-d' ) === $day ) {
			return __( 'yesterday', 'estasoft-news-distribution' );
		}

		return Clock::format( $ts, 'j M' );
	}

	/**
	 * What the last test of a target found, as a status a person can read at a glance.
	 *
	 * @param string $target_id Target id, such as "telegram:en".
	 * @return string Escaped HTML.
	 */
	protected function test_status( string $target_id ): string {
		$test = $this->state()->test( $target_id );

		if ( array() === $test ) {
			return StatusView::inline( Status::NEVER, __( 'Not tested', 'estasoft-news-distribution' ) );
		}

		if ( $test['ok'] ) {
			return StatusView::pill( Status::TEST_PASSED );
		}

		return StatusView::inline( Status::FAILED, __( 'Test failed', 'estasoft-news-distribution' ) )
			. ( '' !== $test['message'] ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $test['message'] ) ) : '' );
	}

	/**
	 * The article a preview is drawn from: the newest published one in the site's first language, or an
	 * example when nothing is published yet.
	 *
	 * @return array{0: Article, 1: bool} The article, and whether it is only an example.
	 */
	protected function preview_article(): array {
		if ( null !== $this->preview ) {
			return $this->preview;
		}

		$languages = $this->channel->site_languages();
		$language  = (string) ( $languages[0] ?? '' );
		$found     = '' === $language ? array() : $this->plugin->reader()->recent( $language, 30 * DAY_IN_SECONDS, 1 );

		if ( array() !== $found ) {
			$this->preview = array( $found[0], false );

			return $this->preview;
		}

		$this->preview = array(
			new Article(
				array(
					'id'           => 0,
					'title'        => __( 'Ferry service to the island starts next week', 'estasoft-news-distribution' ),
					'content'      => __( 'The new route links the island with the harbor twice a day.', 'estasoft-news-distribution' ),
					'permalink'    => home_url( '/ferry-service-starts-next-week/' ),
					'language'     => $language,
					'author_name'  => '',
					'published_ts' => Clock::now(),
					'modified_ts'  => Clock::now(),
				)
			),
			true,
		);

		return $this->preview;
	}

	/**
	 * A tag that says a preview is drawn from an example, not from an article of the site.
	 *
	 * @param bool $is_example Whether it is an example.
	 * @return string
	 */
	protected function example_tag( bool $is_example ): string {
		return $is_example ? Html::tag( 'p', array(), Tag::plain( __( 'Example', 'estasoft-news-distribution' ) ) ) : '';
	}

	/**
	 * A heading row for a service card: its name, its health and the reason, with a button to turn it off.
	 *
	 * @param string $id     The id of the heading.
	 * @param string $title  The service's name.
	 * @param string $outlet Outlet id.
	 * @return string
	 */
	protected function service_head( string $id, string $title, string $outlet ): string {
		$status = $this->channel->service_status( $outlet );
		$off    = Status::NOT_SET_UP !== $status['code']
			? $this->action_button(
				$outlet . '_off',
				__( 'Turn off', 'estasoft-news-distribution' ),
				array(
					'size'       => 'sm',
					'aria_label' => sprintf(
						/* translators: %s: a service name such as Mastodon. */
						__( 'Turn off %s', 'estasoft-news-distribution' ),
						$title
					),
				)
			)
			: '';

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card-head' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => $id,
					),
					esc_html( $title )
				)
				. StatusView::pill( $status['code'] )
				. ( '' !== $status['detail'] ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-outlets-detail' ), esc_html( $status['detail'] ) ) : '' )
				. $off
			)
		);
	}

	/**
	 * One numbered step of a setup card.
	 *
	 * @param int    $number  Step number.
	 * @param string $title   Step title.
	 * @param string $text    What to do.
	 * @param string $extra   More escaped HTML for the step.
	 * @param bool   $current Whether this is the step the person is on.
	 * @param bool   $done    Whether the step is finished.
	 * @return string
	 */
	protected function step( int $number, string $title, string $text, string $extra = '', bool $current = false, bool $done = false ): string {
		$mark = $done
			? Html::tag( 'div', array( 'class' => 'estasoft-nd-step__num estasoft-nd-step__num--done' ), Icon::svg( 'check' ) )
			: Html::tag( 'div', array( 'class' => 'estasoft-nd-step__num' . ( $current ? ' estasoft-nd-step__num--now' : '' ) ), esc_html( (string) $number ) );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-step' . ( $current ? ' estasoft-nd-outlets-step--now' : '' ) ),
			$mark . Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-outlets-step__body' ),
				Html::tag(
					'div',
					array(),
					Html::tag( 'h3', array( 'class' => 'estasoft-nd-title' ), esc_html( $title ) )
					. ( '' !== $text ? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) ) : '' )
				) . $extra
			)
		);
	}
}
