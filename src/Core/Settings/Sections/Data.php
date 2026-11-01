<?php
/**
 * Settings, Backup and uninstall.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Operations\DataView;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Keep a copy of the settings, undo a change, and decide what stays when the
 * plugin is deleted.
 *
 * Stored as `estasoft_nd_settings.data`: `delete_on_uninstall` (a boolean, read by
 * `uninstall.php` before WordPress is fully loaded, so it must stay a boolean in
 * the option) and `delete_article_ids`. This section is never part of an export,
 * an import, a reset or a restore point, so a restore cannot turn on "delete
 * everything" by accident.
 *
 * The screen is made of separate actions, each a form of its own (`DataView`);
 * the choice about deleting is saved through the ordinary settings save.
 *
 * Restore points are made before an import, a reset, a restore and a plugin update
 * (`Core\Operations\SettingsBackup`, `Core\Operations\UpdatePoints`). The newest ten
 * are kept for 90 days, and they live in the database, so the delete choice removes them.
 */
final class Data extends Section {

	public const NAME = 'data';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'delete_on_uninstall' => array(
				'type'    => 'bool',
				'label'   => __( 'When the plugin is deleted', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'delete_article_ids'  => array(
				'type'    => 'bool',
				'label'   => __( 'Also delete the article IDs stored with each post', 'estasoft-news-distribution' ),
				'default' => false,
			),
		);
	}

	public function title(): string {
		return __( 'Backup and uninstall', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Keep a copy of your settings, undo a change, and decide what stays when the plugin is deleted.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 80;
	}

	public function permission(): string {
		return 'admin';
	}

	public function has_save_bar(): bool {
		return false;
	}

	public function in_form(): bool {
		return false;
	}

	public function affects_output(): bool {
		return false;
	}

	/**
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string|null
	 */
	public function render_custom( array $values ): ?string {
		return ( new DataView( Plugin::instance() ) )->html( $values );
	}
}
