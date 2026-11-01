<?php
/**
 * Settings, Privacy and data: the tables and text.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Operations\PrivacyInventory;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Prints `PrivacyInventory`. The screen, the suggested policy text and the
 * readme's "External services" section all read the same lists, so they agree.
 */
final class PrivacyView {

	private Plugin $plugin;

	private PrivacyInventory $inventory;

	/**
	 * Creates the view.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin    = $plugin;
		$this->inventory = $plugin->make( PrivacyInventory::class );
	}

	/**
	 * "What News Distribution stores".
	 *
	 * @return string Escaped HTML.
	 */
	public function stored_table(): string {
		$rows = array();

		foreach ( $this->inventory->stored() as $row ) {
			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( (string) $row['what'] ) ),
				esc_html( (string) $row['why'] ),
				esc_html( (string) $row['contains'] ),
				esc_html( (string) $row['kept'] ),
			);
		}

		return Grid::render(
			'estasoft-nd-cols-ops-stored',
			array(
				__( 'What', 'estasoft-news-distribution' ),
				__( 'Why', 'estasoft-news-distribution' ),
				__( 'It contains', 'estasoft-news-distribution' ),
				__( 'Kept for', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'What News Distribution stores', 'estasoft-news-distribution' )
		);
	}

	/**
	 * "Outside services".
	 *
	 * @return string Escaped HTML.
	 */
	public function services_table(): string {
		$rows = array();

		foreach ( $this->inventory->services() as $row ) {
			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( (string) $row['name'] ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['kind'] ) ),
				StatusView::inline( (string) $row['state'], ! empty( $row['on'] ) ? __( 'On', 'estasoft-news-distribution' ) : null ),
				esc_html( (string) $row['receives'] ),
			);
		}

		return Grid::render(
			'estasoft-nd-cols-ops-services',
			array(
				__( 'Service', 'estasoft-news-distribution' ),
				__( 'State', 'estasoft-news-distribution' ),
				__( 'What it receives', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Outside services', 'estasoft-news-distribution' )
		) . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ops-foot' ),
			esc_html__( 'Platforms that read your feeds ask your site for them. News Distribution does not send them anything about visitors.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The "Delivery and fetch logs" row of "How long logs are kept".
	 *
	 * @return string Escaped HTML.
	 */
	public function log_days_row(): string {
		$days = $this->plugin->settings()->log_days();

		return Html::tag(
			'p',
			array(),
			sprintf(
				/* translators: %s: link with the text "Settings, General". */
				esc_html__( '30 days by default. You can set 7 to 90 days in %s.', 'estasoft-news-distribution' ),
				Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'general' ) ) ), esc_html__( 'Settings, General', 'estasoft-news-distribution' ) )
			)
		) . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html(
				sprintf(
					/* translators: %d: number of days now in use. */
					_n( 'Now: %d day.', 'Now: %d days.', $days, 'estasoft-news-distribution' ),
					$days
				)
			)
		);
	}

	/**
	 * Help under the AI log period.
	 *
	 * @return string Plain text.
	 */
	public function ai_log_help(): string {
		$help = __( 'A site can keep its log for less time than the network sets, not longer.', 'estasoft-news-distribution' );

		if ( is_multisite() ) {
			$help .= ' ' . __( 'The network administrator sets the longest time in Network, AI policy.', 'estasoft-news-distribution' );
		}

		return $help . ' ' . __( 'A shorter time deletes older entries at the next clean-up, and that cannot be undone.', 'estasoft-news-distribution' );
	}

	/**
	 * The suggested text, its state and its two buttons.
	 *
	 * @return string Escaped HTML.
	 */
	public function policy_block(): string {
		$paragraphs = '';

		foreach ( (array) preg_split( '/\n\s*\n/', $this->inventory->policy_text(), -1, PREG_SPLIT_NO_EMPTY ) as $paragraph ) {
			$paragraphs .= Html::tag( 'p', array(), esc_html( trim( (string) $paragraph ) ) );
		}

		$buttons = Button::button(
			__( 'Copy text', 'estasoft-news-distribution' ),
			array(
				'attrs' => array(
					'data-copy-from' => 'estasoft-nd-policy-text',
					'hidden'         => true,
				),
			)
		);

		if ( current_user_can( 'manage_privacy_options' ) ) {
			$buttons .= Button::link( __( 'Open the Privacy Policy Guide', 'estasoft-news-distribution' ), admin_url( 'options-privacy.php?tab=policyguide' ) );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ops-pad estasoft-nd-stack estasoft-nd-gap-12' ),
			StatusView::inline( Status::HEALTHY, __( 'Added to the Privacy Policy Guide', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-ops-policy',
					'id'         => 'estasoft-nd-policy-text',
					'tabindex'   => '0',
					'role'       => 'group',
					'aria-label' => __( 'Suggested privacy policy text', 'estasoft-news-distribution' ),
				),
				$paragraphs
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons . Forms::result( 'estasoft-nd-policy-result' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Check the text with whoever writes your policy. It describes News Distribution, not your whole site.', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The "Export and erase" row of "Personal data".
	 *
	 * @return string Escaped HTML.
	 */
	public function erase_row(): string {
		return StatusView::inline( Status::HEALTHY, __( 'Included in WordPress’s personal data tools', 'estasoft-news-distribution' ) )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html__( 'A person’s name in assigned fixes, the audit trail, delivery notes, the application tracker and the AI log, and an alert address, are included when you export or erase that person’s data in Tools. Erasing keeps the record and removes the person from it.', 'estasoft-news-distribution' )
			);
	}

	/**
	 * The "Keep or delete" row of "Personal data".
	 *
	 * @return string Escaped HTML.
	 */
	public function keep_row(): string {
		return Html::tag(
			'p',
			array(),
			sprintf(
				/* translators: %s: a link with the text "Backup and uninstall". */
				esc_html__( 'To keep a copy of your data first, or to choose what happens to it when you delete the plugin, open %s.', 'estasoft-news-distribution' ),
				Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'data' ) ) ), esc_html__( 'Backup and uninstall', 'estasoft-news-distribution' ) )
			)
		);
	}
}
