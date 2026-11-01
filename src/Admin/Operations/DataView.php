<?php
/**
 * Settings, Backup and uninstall: the cards.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Operations;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\SettingsBackup;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Export, import (in two steps), restore points, reset a section, and the choice
 * of what happens when the plugin is deleted. Each action is a form of its own
 * that posts to `admin-post.php` (see `OperationsModule`), so the page works
 * without JavaScript. The choice about deleting is saved through the ordinary
 * settings save.
 */
final class DataView {

	private Plugin $plugin;

	private SettingsBackup $backup;

	/**
	 * Creates the view.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->backup = $plugin->make( SettingsBackup::class );
	}

	/**
	 * The page body: what happened last, then the five cards and the two notes.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function html( array $values ): string {
		return Flash::take()
			. $this->export_card()
			. $this->import_card()
			. $this->restore_card()
			. $this->reset_card()
			. $this->delete_card( $values )
			. $this->notes();
	}

	/**
	 * "What it cannot do" and "Where it is kept".
	 *
	 * @return string
	 */
	private function notes(): string {
		$cannot = Forms::cannot_note(
			array(
				__( 'Restore other plugins’ settings. Only News Distribution’s own are in the file.', 'estasoft-news-distribution' ),
				__( 'Bring back data after the plugin is deleted with Delete all data chosen. Export first.', 'estasoft-news-distribution' ),
				__( 'Encrypt the file. It holds no keys, so a stranger who finds it learns your choices, not your accounts.', 'estasoft-news-distribution' ),
			)
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::note( 'data-cannot', $cannot['title'], $cannot['html'] )
			. Card::note(
				'data-kept',
				__( 'Where it is kept', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Restore points live in the database with the other settings, so the delete choice removes them too. The exported file lives only where you save it.', 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * "Export settings".
	 *
	 * @return string
	 */
	private function export_card(): string {
		$choices = array(
			'settings'  => array(
				__( 'Settings', 'estasoft-news-distribution' ),
				__( 'Which channels are on, languages, page markup, date rules, platform rules, republished content, image credits, video, cache and access.', 'estasoft-news-distribution' ),
				true,
			),
			'alerts'    => array(
				__( 'Alert addresses', 'estasoft-news-distribution' ),
				__( 'The email addresses on the alert list. Off, because they are personal data.', 'estasoft-news-distribution' ),
				false,
			),
			'notes'     => array(
				__( 'Notes from the application tracker', 'estasoft-news-distribution' ),
				__( 'Staff names and notes from the application tracker. Off for the same reason.', 'estasoft-news-distribution' ),
				false,
			),
			'referrals' => array(
				__( 'Visit counts by source', 'estasoft-news-distribution' ),
				__( 'How many visits each article had from each source, by day, for the last 90 days. No visitor data. Off, because the file gets larger. Read on this site again, the counts are added and never lowered; read on another site, they are left out.', 'estasoft-news-distribution' ),
				false,
			),
		);

		$boxes = '';

		foreach ( $choices as $value => $choice ) {
			$boxes .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => 'include[]',
						'value'   => $value,
						'checked' => $choice[2],
					)
				)
				. Html::tag( 'span', array(), Html::tag( 'strong', array(), esc_html( $choice[0] ) ) . '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $choice[1] ) ) )
			);
		}

		$file = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			Button::submit( __( 'Download settings file', 'estasoft-news-distribution' ) )
			. Html::tag(
				'span',
				array(
					'class' => 'estasoft-nd-mono',
					'dir'   => 'ltr',
				),
				esc_html( $this->backup->filename() )
			)
		) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The file is plain text. It holds no secrets, but keep it like any other configuration file.', 'estasoft-news-distribution' ) );

		$form = Forms::post(
			'estasoft_nd_data_export',
			Forms::data_nonce(),
			Forms::row( 'estasoft-nd-export-include', __( 'Include', 'estasoft-news-distribution' ), $boxes )
			. Forms::row( 'estasoft-nd-export-never', __( 'Never included', 'estasoft-news-distribution' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Keys, tokens and passwords, connections, article text, logs and rule pack files.', 'estasoft-news-distribution' ) ) )
			. Forms::row( 'estasoft-nd-export-file', __( 'File', 'estasoft-news-distribution' ), $file )
		);

		return Card::flush(
			'data-export',
			__( 'Export settings', 'estasoft-news-distribution' ),
			__( 'Download your settings as a file, to keep a copy or to set up another site the same way.', 'estasoft-news-distribution' ),
			$form
		);
	}

	/**
	 * "Import settings", in its two steps.
	 *
	 * @return string
	 */
	private function import_card(): string {
		$step    = Query::key( 'step' );
		$json    = 'preview' === $step ? $this->backup->stashed() : '';
		$file    = '' === $json ? null : $this->backup->parse( $json );
		$preview = is_array( $file ) ? $this->backup->preview( $file ) : null;

		$choose = Forms::post(
			'estasoft_nd_data_import_preview',
			Forms::data_nonce(),
			Forms::row(
				'estasoft-nd-import-file',
				__( 'Settings file', 'estasoft-news-distribution' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
					Html::void(
						'input',
						array(
							'type'            => 'file',
							'name'            => 'settings_file',
							'id'              => 'estasoft-nd-import-file',
							'accept'          => '.json,application/json',
							'aria-labelledby' => 'estasoft-nd-import-file-label',
						)
					)
					. Button::submit( null === $preview ? __( 'See what would change', 'estasoft-news-distribution' ) : __( 'Choose another file', 'estasoft-news-distribution' ) )
				)
				. ( null === $preview ? '' : Html::tag(
					'p',
					array(),
					StatusView::inline(
						Status::HEALTHY,
						sprintf(
							/* translators: 1: number of settings, 2: plugin version, such as "1.0.0". */
							_n( 'Read %1$d setting from News Distribution %2$s', 'Read %1$d settings from News Distribution %2$s', $preview['file']['read'], 'estasoft-news-distribution' ),
							$preview['file']['read'],
							'' === $preview['file']['plugin_version'] ? '?' : $preview['file']['plugin_version']
						)
					)
				) )
			),
			array( 'enctype' => 'multipart/form-data' )
		);

		$body = $choose;

		if ( is_wp_error( $file ) ) {
			$body .= Html::tag( 'div', array( 'class' => 'estasoft-nd-ops-pad' ), Message::text( 'danger', $file->get_error_message() ) );
		}

		if ( null !== $preview ) {
			$body .= $this->preview_body( $preview );
		}

		return Card::flush(
			'data-import',
			__( 'Import settings', 'estasoft-news-distribution' ),
			__( 'Read a settings file made by News Distribution 1.0 or newer. You see what changes before anything is saved.', 'estasoft-news-distribution' ),
			$body
		);
	}

	/**
	 * The table of what an import would change, with Apply and Cancel.
	 *
	 * @param array<string, mixed> $preview What `SettingsBackup::preview()` returned.
	 * @return string
	 */
	private function preview_body( array $preview ): string {
		$words = array(
			SettingsBackup::RESULT_CHANGES => __( 'Changes', 'estasoft-news-distribution' ),
			SettingsBackup::RESULT_SAME    => __( 'Same', 'estasoft-news-distribution' ),
			SettingsBackup::RESULT_SKIPPED => __( 'Skipped', 'estasoft-news-distribution' ),
			SettingsBackup::RESULT_KEPT    => __( 'Kept as it is', 'estasoft-news-distribution' ),
		);

		$shown = array();
		$same  = array();

		foreach ( (array) $preview['rows'] as $row ) {
			$cells = array(
				Html::tag( 'strong', array(), esc_html( (string) $row['label'] ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['section'] ) ),
				esc_html( (string) $row['now'] ),
				esc_html( (string) $row['file'] ),
				esc_html( $words[ $row['result'] ] ?? (string) $row['result'] )
				. ( '' === (string) $row['reason'] ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['reason'] ) ) ),
			);

			if ( SettingsBackup::RESULT_SAME === $row['result'] ) {
				$same[] = $cells;
			} else {
				$shown[] = $cells;
			}
		}

		$headers = array(
			__( 'Setting', 'estasoft-news-distribution' ),
			__( 'Now', 'estasoft-news-distribution' ),
			__( 'In the file', 'estasoft-news-distribution' ),
			__( 'Result', 'estasoft-news-distribution' ),
		);

		$table = array() === $shown
			? ''
			: Grid::render( 'estasoft-nd-cols-ops-import', $headers, $shown, __( 'What the file would change', 'estasoft-news-distribution' ) );

		if ( array() !== $same ) {
			$table .= Html::tag(
				'details',
				array( 'class' => 'estasoft-nd-ops-same' ),
				Html::tag(
					'summary',
					array(),
					esc_html(
						sprintf(
							/* translators: %d: number of settings. */
							_n( 'Show the %d setting that is the same', 'Show the %d settings that are the same', count( $same ), 'estasoft-news-distribution' ),
							count( $same )
						)
					)
				)
				. Grid::render( 'estasoft-nd-cols-ops-import', $headers, $same, __( 'Settings that are the same', 'estasoft-news-distribution' ) )
			);
		}

		$counts  = (array) $preview['counts'];
		$changes = (int) $counts[ SettingsBackup::RESULT_CHANGES ];
		$parts   = array();

		$parts[] = sprintf(
			/* translators: %d: number of settings. */
			_n( '%d setting changes', '%d settings change', $changes, 'estasoft-news-distribution' ),
			$changes
		);

		if ( (int) $counts[ SettingsBackup::RESULT_SKIPPED ] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of settings. */
				_n( '%d is skipped', '%d are skipped', (int) $counts[ SettingsBackup::RESULT_SKIPPED ], 'estasoft-news-distribution' ),
				(int) $counts[ SettingsBackup::RESULT_SKIPPED ]
			);
		}

		if ( (int) $counts[ SettingsBackup::RESULT_KEPT ] > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of settings. */
				_n( '%d is kept as it is', '%d are kept as they are', (int) $counts[ SettingsBackup::RESULT_KEPT ], 'estasoft-news-distribution' ),
				(int) $counts[ SettingsBackup::RESULT_KEPT ]
			);
		}

		if ( (int) $counts[ SettingsBackup::RESULT_SAME ] > 0 ) {
			$parts[] = __( 'and the rest are the same', 'estasoft-news-distribution' );
		}

		$summary = Message::bar(
			'info',
			Html::tag( 'strong', array(), esc_html__( 'Your current settings are saved as a restore point first.', 'estasoft-news-distribution' ) )
			. ' ' . esc_html( implode( ', ', $parts ) . '.' )
		);

		$buttons = '';

		if ( $changes > 0 ) {
			$buttons .= Button::submit(
				sprintf(
					/* translators: %d: number of settings that would change. */
					_n( 'Apply %d change', 'Apply %d changes', $changes, 'estasoft-news-distribution' ),
					$changes
				),
				array(
					'variant' => 'primary',
					'attrs'   => array(
						'name'  => 'op',
						'value' => 'apply',
					),
				)
			);
		}

		$buttons .= Button::submit(
			$changes > 0 ? __( 'Cancel', 'estasoft-news-distribution' ) : __( 'Close', 'estasoft-news-distribution' ),
			array(
				'attrs' => array(
					'name'  => 'op',
					'value' => 'cancel',
				),
			)
		);

		$apply = Forms::post(
			'estasoft_nd_data_import_apply',
			Forms::data_nonce(),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $summary . Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons ) ),
			array( 'class' => 'estasoft-nd-ops-pad' )
		);

		return $table . $apply;
	}

	/**
	 * "Restore points".
	 *
	 * @return string
	 */
	private function restore_card(): string {
		$points = $this->backup->points();

		if ( array() === $points ) {
			$body = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-ops-pad' ), esc_html__( 'No restore point yet. One is saved before every import, reset, restore and update.', 'estasoft-news-distribution' ) );
		} else {
			$rows = array();

			foreach ( $points as $point ) {
				$rows[] = array(
					esc_html( Format::when( $point['created'] ) ),
					esc_html( $this->backup->reason_text( $point['reason'], $point['section'], $point['version'], $point['how'] ) ),
					esc_html(
						sprintf(
							/* translators: %d: number of settings. */
							_n( '%d setting', '%d settings', $point['count'], 'estasoft-news-distribution' ),
							$point['count']
						)
					),
					Button::submit(
						__( 'Restore', 'estasoft-news-distribution' ),
						array(
							'size'  => 'sm',
							'attrs' => array(
								'name'       => 'point',
								'value'      => $point['id'],
								'aria-label' => sprintf(
									/* translators: %s: a date and time. */
									__( 'Restore the settings saved on %s', 'estasoft-news-distribution' ),
									Format::when( $point['created'] )
								),
							),
						)
					),
				);
			}

			$body = Forms::post(
				'estasoft_nd_data_restore',
				Forms::data_nonce(),
				Grid::render(
					'estasoft-nd-cols-ops-points',
					array(
						__( 'When', 'estasoft-news-distribution' ),
						__( 'Why', 'estasoft-news-distribution' ),
						__( 'Size', 'estasoft-news-distribution' ),
						'',
					),
					$rows,
					__( 'Restore points', 'estasoft-news-distribution' )
				)
			);
		}

		$foot = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ops-foot' ), esc_html__( 'Restoring replaces the current settings. A new restore point is saved first, so a restore can be undone.', 'estasoft-news-distribution' ) );

		if ( is_multisite() ) {
			$foot .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ops-foot' ), esc_html__( 'On a network, each site keeps its own restore points. An update started from the network admin saves one for the main site at once, and one for every other site the next time that site loads.', 'estasoft-news-distribution' ) );
		}

		return Card::flush(
			'data-restore',
			__( 'Restore points', 'estasoft-news-distribution' ),
			__( 'News Distribution saves one before every import, reset, restore and update. It keeps the last 10 for 90 days.', 'estasoft-news-distribution' ),
			$body . $foot
		);
	}

	/**
	 * "Reset a section".
	 *
	 * @return string
	 */
	private function reset_card(): string {
		$options = '';

		foreach ( $this->backup->sections() as $name => $section ) {
			$options .= Html::tag( 'option', array( 'value' => $name ), esc_html( $section->title() ) );
		}

		$select = Html::tag(
			'select',
			array(
				'class'            => 'estasoft-nd-field estasoft-nd-w-260',
				'id'               => 'estasoft-nd-reset-section',
				'name'             => 'section',
				'data-reset-label' => '#estasoft-nd-reset-button',
			),
			$options
		);

		$form = Forms::post(
			'estasoft_nd_data_reset',
			Forms::data_nonce(),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-row' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-form-label',
						'for'   => 'estasoft-nd-reset-section',
					),
					esc_html__( 'Section', 'estasoft-news-distribution' )
				) . Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-form-control' ),
					$select
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ops-pad estasoft-nd-stack estasoft-nd-gap-8' ),
				Html::tag(
					'p',
					array(),
					Button::submit(
						__( 'Reset section', 'estasoft-news-distribution' ),
						array( 'attrs' => array( 'id' => 'estasoft-nd-reset-button' ) )
					)
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Only this section changes. A restore point is saved first. Connections and keys are never reset.', 'estasoft-news-distribution' ) )
			)
		);

		return Card::flush(
			'data-reset',
			__( 'Reset a section', 'estasoft-news-distribution' ),
			__( 'Put one group of settings back to what a new install has.', 'estasoft-news-distribution' ),
			$form
		);
	}

	/**
	 * "When the plugin is deleted": the choice, and what it would remove now.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string
	 */
	private function delete_card( array $values ): string {
		$delete = ! empty( $values['delete_on_uninstall'] );
		$ids    = ! empty( $values['delete_article_ids'] );
		$name   = Field::name( 'delete_on_uninstall' );

		$radio = static function ( string $value, bool $on, string $title, string $help ) use ( $name ): string {
			return Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'    => 'radio',
						'name'    => $name,
						'value'   => $value,
						'checked' => $on,
					)
				)
				. Html::tag( 'span', array(), Html::tag( 'strong', array(), esc_html( $title ) ) . '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $help ) ) )
			);
		};

		$choice = $radio( '0', ! $delete, __( 'Keep my data', 'estasoft-news-distribution' ), __( 'Recommended. Only the code is removed. Settings, logs and notes stay, so a later install picks up where you stopped.', 'estasoft-news-distribution' ) )
			. $radio( '1', $delete, __( 'Delete all News Distribution data', 'estasoft-news-distribution' ), __( 'Removes what the table lists, the next time the plugin is deleted. Posts, media and other plugins are not touched. This cannot be undone.', 'estasoft-news-distribution' ) );

		$article_ids = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( 'delete_article_ids' ),
				'value' => '0',
			)
		) . Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'name'    => Field::name( 'delete_article_ids' ),
					'value'   => '1',
					'checked' => $ids,
				)
			)
			. Html::tag(
				'span',
				array(),
				Html::tag( 'strong', array(), esc_html__( 'Also delete the article IDs stored with each post', 'estasoft-news-distribution' ) )
				. '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Feeds then use new IDs if the plugin is installed again, and Microsoft Start and Yahoo News may treat old articles as new.', 'estasoft-news-distribution' ) )
			)
		);

		$numbers = $this->backup->inventory();

		$rows = array(
			array(
				esc_html__( 'Settings and rule packs', 'estasoft-news-distribution' ),
				esc_html(
					sprintf(
						/* translators: %s: number of settings. */
						_n( '%s setting', '%s settings', $numbers['settings'], 'estasoft-news-distribution' ),
						number_format_i18n( $numbers['settings'] )
					)
				),
				esc_html__( 'Deleted', 'estasoft-news-distribution' ),
			),
			array(
				esc_html__( 'Delivery log and fetch log', 'estasoft-news-distribution' ),
				esc_html(
					sprintf(
						/* translators: %s: number of rows. */
						_n( '%s row', '%s rows', $numbers['logs'], 'estasoft-news-distribution' ),
						number_format_i18n( $numbers['logs'] )
					)
				),
				esc_html__( 'Deleted', 'estasoft-news-distribution' ),
			),
			array(
				esc_html__( 'Notes, assignments, audit trail, AI log and restore points', 'estasoft-news-distribution' ),
				esc_html(
					sprintf(
						/* translators: %s: number of rows. */
						_n( '%s row', '%s rows', $numbers['notes'], 'estasoft-news-distribution' ),
						number_format_i18n( $numbers['notes'] )
					)
				),
				esc_html__( 'Deleted', 'estasoft-news-distribution' ),
			),
			array(
				esc_html__( 'Scheduled actions and cached feeds', 'estasoft-news-distribution' ),
				esc_html(
					sprintf(
						/* translators: %s: number of actions, such as "18" or "200 or more". */
						_n( '%s action', '%s actions', $numbers['actions'], 'estasoft-news-distribution' ),
						number_format_i18n( $numbers['actions'] ) . ( $numbers['actions_more'] ? '+' : '' )
					)
				),
				esc_html__( 'Always removed when the plugin is deactivated', 'estasoft-news-distribution' ),
			),
			array(
				esc_html__( 'Article IDs stored with each post', 'estasoft-news-distribution' ),
				esc_html(
					sprintf(
						/* translators: %s: number of posts. */
						_n( '%s post', '%s posts', $numbers['article_ids'], 'estasoft-news-distribution' ),
						number_format_i18n( $numbers['article_ids'] )
					)
				),
				esc_html__( 'Deleted only if you tick the second box', 'estasoft-news-distribution' ),
			),
		);

		$table = Grid::render(
			'estasoft-nd-cols-ops-delete',
			array(
				__( 'What', 'estasoft-news-distribution' ),
				__( 'Size now', 'estasoft-news-distribution' ),
				__( 'When you choose Delete', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'What deleting the plugin would remove', 'estasoft-news-distribution' )
		);

		$save = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ops-pad estasoft-nd-cluster estasoft-nd-gap-12' ),
			Button::submit( __( 'Save choice', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			. ( is_multisite() ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'On a network, each site keeps its own choice.', 'estasoft-news-distribution' ) ) : '' )
		);

		// The ordinary settings save: it checks the nonce of the section and its permission, and the script saves it without a page load.
		$form = Html::tag(
			'form',
			array(
				'method'             => 'post',
				'action'             => admin_url( 'admin-post.php' ),
				'novalidate'         => true,
				'data-settings-form' => true,
				'data-section'       => 'data',
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => 'estasoft_nd_save_section',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'section',
					'value' => 'data',
				)
			)
			. wp_nonce_field( 'estasoft_nd_save_data', '_wpnonce', true, false )
			. Forms::row( 'estasoft-nd-delete-choice', __( 'Your data', 'estasoft-news-distribution' ), $choice )
			. Forms::row( 'estasoft-nd-delete-ids', __( 'Article IDs', 'estasoft-news-distribution' ), $article_ids )
			. $table
			. $save
		);

		return Card::flush(
			'data-delete',
			__( 'When the plugin is deleted', 'estasoft-news-distribution' ),
			__( 'Deactivating never deletes anything. This is about deleting the plugin from the Plugins page.', 'estasoft-news-distribution' ),
			$form
		);
	}
}
