<?php
/**
 * The line under the "Count visits by source" switch on Settings, Privacy and data.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports\Parts;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Referrals\Counter;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Says what has been counted and links to the report. "Delete the counts" is a link to the
 * report's confirmation page, not a button here, because this screen is one form and a form
 * cannot sit inside another.
 */
final class ReferralsPrivacy {

	private Plugin $plugin;

	/**
	 * Creates the view.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The "Counts" row.
	 *
	 * @return string Escaped HTML.
	 */
	public function row(): string {
		$counter = $this->plugin->make( Counter::class );
		$since   = $counter->since();
		$report  = Links::admin( 'estasoft-nd-reports', array( 'report' => 'referrals' ) );

		if ( '' === $since ) {
			$text = $counter->enabled()
				? __( 'Counting is on. Nothing has been counted yet.', 'estasoft-news-distribution' )
				: __( 'Counting is off. Nothing has been counted.', 'estasoft-news-distribution' );
		} else {
			$when = wp_date( get_option( 'date_format' ), (int) strtotime( $since . ' 12:00:00 UTC' ), new \DateTimeZone( 'UTC' ) );
			$text = sprintf(
				/* translators: 1: "Counting is on" or "Counting is off", 2: a date such as "14 Oct 2026". */
				__( '%1$s. Counts go back to %2$s.', 'estasoft-news-distribution' ),
				$counter->enabled() ? __( 'Counting is on', 'estasoft-news-distribution' ) : __( 'Counting is off, and the counts already made are kept', 'estasoft-news-distribution' ),
				$when
			);
		}

		$buttons = Button::link( __( 'Open the Referrals report', 'estasoft-news-distribution' ), $report, array( 'size' => 'sm' ) );

		if ( $counter->rows() > 0 ) {
			$buttons .= Button::link( __( 'Delete the counts', 'estasoft-news-distribution' ), add_query_arg( 'confirm', 'delete', $report ), array( 'size' => 'sm' ) );
		}

		return Html::tag( 'p', array(), esc_html( $text ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons );
	}
}
