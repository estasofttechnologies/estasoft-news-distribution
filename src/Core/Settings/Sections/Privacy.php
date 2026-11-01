<?php
/**
 * Settings, Privacy and data.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Operations\PrivacyView;
use Estasoft\NewsDistribution\Admin\Reports\Parts\ReferralsPrivacy;
use Estasoft\NewsDistribution\Core\Operations\PrivacyInventory;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * What the plugin stores, which outside services it can talk to, how long logs
 * are kept, and the text for WordPress's Privacy Policy Guide. The lists are
 * `Core\Operations\PrivacyInventory`; the exporter and eraser for staff names
 * are `Core\Operations\PersonalData`.
 *
 * Two settings: `ai_log_months` (12, 6, 3 or 1), only on a site that has an AI log, and
 * `count_visits`, the switch for the Referrals report's counting (off by default; see
 * `Core\Referrals\Counter` and `Modules\ReferralsModule`).
 */
final class Privacy extends Section {

	public const NAME = 'privacy';

	/**
	 * The periods a site can choose for its AI log, with their words.
	 *
	 * @return array<string, string> Months => label.
	 */
	public static function month_options(): array {
		$inventory = Plugin::instance()->make( PrivacyInventory::class );
		$cap       = $inventory->network_cap_months();
		$options   = array();

		foreach ( $inventory->ai_month_options() as $months ) {
			$label = sprintf(
				/* translators: %d: number of months. */
				_n( '%d month', '%d months', $months, 'estasoft-news-distribution' ),
				$months
			);

			if ( $cap > 0 && $months === $cap ) {
				$label = sprintf(
					/* translators: %s: a length of time, such as "12 months". */
					__( '%s (the most your network allows)', 'estasoft-news-distribution' ),
					$label
				);
			}

			$options[ (string) $months ] = $label;
		}

		return $options;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'ai_log_months' => array(
				'type'    => 'enum',
				'label'   => __( 'AI log', 'estasoft-news-distribution' ),
				'default' => '12',
				'control' => 'select',
				'width'   => 360,
				'options' => array( self::class, 'month_options' ),
				'help'    => ( new PrivacyView( Plugin::instance() ) )->ai_log_help(),
			),
			// Read by Core\Referrals\Counter. Off until an administrator turns it on; the Referrals report has the same switch.
			'count_visits'  => array(
				'type'    => 'bool',
				'label'   => __( 'Count visits by source', 'estasoft-news-distribution' ),
				'default' => false,
				'control' => 'checkbox',
				'help'    => __( 'Adds 1 to a count each time a reader arrives at an article from another site. It stores no cookies, no IP addresses and no visitor data, and keeps the counts for 90 days. Some readers are not counted, so every number is a floor. If you use a page cache, clear it after you turn this on.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'Privacy and data', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'What News Distribution stores, who receives what, and how long it is kept.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 70;
	}

	public function permission(): string {
		return 'admin';
	}

	public function affects_output(): bool {
		return false;
	}

	/**
	 * Always: "Count visits by source" is on every site, whether or not there is an AI log.
	 *
	 * @return bool
	 */
	public function has_save_bar(): bool {
		return true;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, cannot: string[]}
	 */
	public function layout(): array {
		$view = new PrivacyView( Plugin::instance() );
		$logs = array(
			array(
				'label' => __( 'Delivery and fetch logs', 'estasoft-news-distribution' ),
				'html'  => array( $view, 'log_days_row' ),
			),
		);

		if ( Plugin::instance()->make( PrivacyInventory::class )->has_ai_log() ) {
			$logs[] = array( 'field' => 'ai_log_months' );
		}

		return array(
			'cards'  => array(
				array(
					'id'          => 'stored',
					'title'       => __( 'What News Distribution stores', 'estasoft-news-distribution' ),
					'description' => __( 'Every item, why it is kept, and for how long.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'wide' => true,
							'html' => array( $view, 'stored_table' ),
						),
					),
				),
				array(
					'id'          => 'services',
					'title'       => __( 'Outside services', 'estasoft-news-distribution' ),
					'description' => __( 'News Distribution talks to these only when you turn them on.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'wide' => true,
							'html' => array( $view, 'services_table' ),
						),
					),
				),
				array(
					'id'          => 'logs',
					'title'       => __( 'How long logs are kept', 'estasoft-news-distribution' ),
					'description' => __( 'Older entries are deleted by a daily clean-up.', 'estasoft-news-distribution' ),
					'rows'        => $logs,
				),
				array(
					'id'          => 'visits',
					'title'       => __( 'Visits by source', 'estasoft-news-distribution' ),
					'description' => __( 'Which platforms send readers to your articles. Off until you turn it on.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'count_visits' ),
						array(
							'label' => __( 'Counts', 'estasoft-news-distribution' ),
							'html'  => array( new ReferralsPrivacy( Plugin::instance() ), 'row' ),
						),
					),
				),
				array(
					'id'          => 'policy',
					'title'       => __( 'Suggested privacy policy text', 'estasoft-news-distribution' ),
					'description' => __( 'Shown in WordPress’s Privacy Policy Guide, so you can copy it into your own policy.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'wide' => true,
							'html' => array( $view, 'policy_block' ),
						),
					),
				),
				array(
					'id'          => 'personal',
					'title'       => __( 'Personal data', 'estasoft-news-distribution' ),
					'description' => __( 'The only personal data News Distribution holds is about your own staff: names, and the email addresses you enter for alerts.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Export and erase', 'estasoft-news-distribution' ),
							'html'  => array( $view, 'erase_row' ),
						),
						array(
							'label' => __( 'Keep or delete', 'estasoft-news-distribution' ),
							'html'  => array( $view, 'keep_row' ),
						),
					),
				),
			),
			'cannot' => array(
				__( 'Control what an outside service does with what it receives. Each service has its own privacy policy.', 'estasoft-news-distribution' ),
				__( 'Take back what a platform already has. Deleting data here removes News Distribution’s own copy only.', 'estasoft-news-distribution' ),
				__( 'Write your site’s whole privacy policy. The text above covers News Distribution only.', 'estasoft-news-distribution' ),
			),
		);
	}
}
