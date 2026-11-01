<?php
/**
 * The parts of demo mode a person reads and presses: the banner's sentence, the row in Settings, Environment, and the confirmation.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Core\Demo\Demo;
use Estasoft\NewsDistribution\Modules\EnvironmentModule;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * "Exit demo" in the banner and in the row never acts by itself: it opens the confirmation, which says how many
 * samples will be removed. The confirmation's own "Exit demo" is the one that acts: a link to `admin-post.php`
 * that carries a nonce, like "Check again" on the same screen. It is a link and not a form because the row sits inside
 * the Settings form, and browsers drop a form inside a form. It works without a script.
 */
final class DemoPanel {

	/**
	 * The address of the confirmation: the Environment screen, opened at the demo row.
	 *
	 * @return string
	 */
	public static function confirm_url(): string {
		return EnvironmentModule::screen_url( array( 'demo' => 'exit' ) ) . '#estasoft-nd-card-environment-staging';
	}

	/**
	 * The sentence the banner says after "Demo mode."
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return string
	 */
	public static function effect( Plugin $plugin ): string {
		$demo = new Demo( $plugin );

		if ( ! $demo->has_samples() ) {
			return __( 'Nothing is pushed to platforms from this site.', 'estasoft-news-distribution' );
		}

		if ( ! $demo->has_pictures() ) {
			return __( 'These are sample articles without pictures, because this server cannot draw them. Nothing is sent to any platform.', 'estasoft-news-distribution' );
		}

		return __( 'These are sample articles. Nothing is sent to any platform.', 'estasoft-news-distribution' );
	}

	/**
	 * The row of Settings, Environment that says there are sample articles and offers the way out.
	 *
	 * With `?demo=exit` in the address it is the confirmation instead.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return string Escaped HTML.
	 */
	public static function row( Plugin $plugin ): string {
		$count = ( new Demo( $plugin ) )->counts()['articles'];

		if ( self::asked_to_confirm() ) {
			return self::confirmation( $count );
		}

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			esc_html(
				sprintf(
					/* translators: %s: a number of sample articles. */
					_n(
						'%s sample article is on this site. Exit demo removes it and turns demo mode off. Your own posts are not touched.',
						'%s sample articles are on this site. Exit demo removes them and turns demo mode off. Your own posts are not touched.',
						$count,
						'estasoft-news-distribution'
					),
					number_format_i18n( $count )
				)
			)
		) . Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-support-actions' ),
			Button::link( __( 'Exit demo', 'estasoft-news-distribution' ), self::confirm_url() )
		);
	}

	/**
	 * Whether the address asks for the confirmation.
	 *
	 * @return bool
	 */
	private static function asked_to_confirm(): bool {
		return Query::is( 'demo', 'exit' );
	}

	/**
	 * The address that ends the demo: `admin-post.php` with the action and a nonce.
	 *
	 * @return string
	 */
	public static function exit_url(): string {
		return wp_nonce_url(
			add_query_arg( array( 'action' => EnvironmentModule::ACTION_EXIT_DEMO ), admin_url( 'admin-post.php' ) ),
			EnvironmentModule::ACTION_EXIT_DEMO
		);
	}

	/**
	 * The question, with the count, and its two buttons.
	 *
	 * @param int $count How many sample articles will be removed.
	 * @return string Escaped HTML.
	 */
	private static function confirmation( int $count ): string {
		$text = sprintf(
			/* translators: %s: a number of sample articles. */
			_n(
				'This removes %s sample article and everything made for it, turns demo mode off, and takes you back to the setup. Your own posts are not touched.',
				'This removes %s sample articles and everything made for them, turns demo mode off, and takes you back to the setup. Your own posts are not touched.',
				$count,
				'estasoft-news-distribution'
			),
			number_format_i18n( $count )
		);

		return Message::bar(
			'warning',
			Html::tag( 'strong', array(), esc_html__( 'Exit demo?', 'estasoft-news-distribution' ) ) . ' ' . esc_html( $text ),
			Button::link( __( 'Exit demo', 'estasoft-news-distribution' ), self::exit_url(), array( 'variant' => 'primary' ) )
			. Button::link( __( 'Keep the demo', 'estasoft-news-distribution' ), EnvironmentModule::screen_url() . '#estasoft-nd-card-environment-staging' )
		);
	}
}
