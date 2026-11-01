<?php
/**
 * What every setup step shares: the heading block, the form, and the row of buttons.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Logo;
use Estasoft\NewsDistribution\Admin\Ui\Steps;
use Estasoft\NewsDistribution\Modules\SetupModule;

defined( 'ABSPATH' ) || exit;

/**
 * Every step is a form that posts to `admin-post.php`, so the setup works without a script.
 *
 * The buttons sit under the form, not inside it, and the main button points at the form with its
 * `form` attribute. That keeps the Enter key on the main button (it is the only submit button the form
 * owns) while "Skip for now" is a form of its own and "Back" is a plain link that saves nothing.
 */
final class Frame {

	public const FORM_ID = 'estasoft-nd-setup-form';

	/**
	 * The names of the numbered steps, in order.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'publication' => __( 'Publication', 'estasoft-news-distribution' ),
			'channels'    => __( 'Channels', 'estasoft-news-distribution' ),
			'check'       => __( 'First check', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The whole screen: the brand line, the stepper, the heading and the body.
	 *
	 * @param string $step  start, publication, channels or check.
	 * @param string $title The heading (plain).
	 * @param string $lead  One line under the heading (plain, may be empty).
	 * @param string $body  Trusted HTML of the step.
	 * @return string
	 */
	public static function page( string $step, string $title, string $lead, string $body ): string {
		$html = self::brand( Onboarding::START !== $step );

		if ( Onboarding::START !== $step ) {
			$position = array_search( $step, Onboarding::steps(), true );

			$html .= Steps::stepper( array_values( self::labels() ), false === $position ? 1 : $position + 1, __( 'Setup steps', 'estasoft-news-distribution' ) );
		}

		$html .= Html::tag(
			'header',
			array( 'class' => 'estasoft-nd-setup__header' ),
			Html::tag( 'h1', array( 'class' => 'estasoft-nd-headline' ), esc_html( $title ) )
			. ( '' !== $lead ? Html::tag( 'p', array( 'class' => 'estasoft-nd-lead' ), esc_html( $lead ) ) : '' )
		);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-setup' ), $html . $body );
	}

	/**
	 * The product name with its mark, and the "Setup" chip on the numbered steps.
	 *
	 * @param bool $chip Whether to show the chip.
	 * @return string
	 */
	private static function brand( bool $chip ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-setup__brand' ),
			Logo::mark()
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-setup__name' ), esc_html__( 'Estasoft News Distribution', 'estasoft-news-distribution' ) )
			. ( $chip ? Html::tag( 'span', array( 'class' => 'estasoft-nd-tier' ), esc_html__( 'Setup', 'estasoft-news-distribution' ) ) : '' )
		);
	}

	/**
	 * A hidden field.
	 *
	 * @param string $name  Field name.
	 * @param string $value Value.
	 * @return string
	 */
	public static function hidden( string $name, string $value ): string {
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => $name,
				'value' => $value,
			)
		);
	}

	/**
	 * What every setup form carries: where to post, the permission token and the step.
	 *
	 * @param string $step The step.
	 * @return string
	 */
	private static function fields( string $step ): string {
		return self::hidden( 'action', SetupModule::ACTION )
			. self::hidden( '_wpnonce', wp_create_nonce( SetupModule::ACTION ) )
			. self::hidden( 'step', $step );
	}

	/**
	 * The step's form.
	 *
	 * @param string $step   The step.
	 * @param string $inside Trusted HTML of the fields.
	 * @return string
	 */
	public static function form( string $step, string $inside ): string {
		return Html::tag(
			'form',
			array(
				'id'         => self::FORM_ID,
				'method'     => 'post',
				'action'     => admin_url( 'admin-post.php' ),
				'novalidate' => true,
			),
			self::fields( $step ) . $inside
		);
	}

	/**
	 * The row of buttons under a step.
	 *
	 * Options: `back` (address of the step before, or empty), `skip` ({label, go}, a form of its own),
	 * `continue` (the main button's label), `go` (what the main button posts, default "continue") and
	 * `note` (a muted line under the buttons).
	 *
	 * @param string               $step The step.
	 * @param array<string, mixed> $opts Options.
	 * @return string
	 */
	public static function actions( string $step, array $opts ): string {
		$start = '';
		$end   = '';

		if ( ! empty( $opts['back'] ) ) {
			$start = Button::link( __( 'Back', 'estasoft-news-distribution' ), (string) $opts['back'] );
		}

		if ( ! empty( $opts['skip'] ) ) {
			$end .= Html::tag(
				'form',
				array(
					'method' => 'post',
					'action' => admin_url( 'admin-post.php' ),
					'class'  => 'estasoft-nd-setup__skip',
				),
				self::fields( $step ) . Button::submit(
					(string) $opts['skip']['label'],
					array(
						'variant' => 'subtle',
						'attrs'   => array(
							'name'  => 'go',
							'value' => (string) $opts['skip']['go'],
						),
					)
				)
			);
		}

		$end .= Button::submit(
			(string) $opts['continue'],
			array(
				'variant' => 'primary',
				'attrs'   => array(
					'form'  => self::FORM_ID,
					'name'  => 'go',
					'value' => (string) ( $opts['go'] ?? 'continue' ),
				),
			)
		);

		$html = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-setup__actions' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__actions-start' ), $start )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__actions-end' ), $end )
		);

		if ( ! empty( $opts['note'] ) ) {
			$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-setup__note estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $opts['note'] ) );
		}

		return $html;
	}
}
