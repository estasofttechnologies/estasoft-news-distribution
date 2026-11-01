<?php
/**
 * One section of the settings, such as General or Access and gate.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A section class lives in src/Core/Settings/Sections/ and is found by name.
 * It declares its fields and its layout; the framework validates, stores,
 * serves and prints them. A section that needs more than a form overrides
 * render_extra() or, for a page that is not a form, render_custom().
 *
 * To add a section: create the class, give it a NAME constant that matches the
 * `section` part of its screen address, and list its fields and cards.
 *
 * Field spec keys the form understands, besides those the Validator reads:
 *
 * - row_label: the label printed beside the control (defaults to `label`).
 * - control: radio, select, checkbox, number, text, url, email, textarea, checkboxes,
 *   lang_map or color. Defaults follow the type (bool: checkbox, int: number,
 *   enum: select, list of enum: checkboxes, string: text).
 * - title: bold line next to a radio or checkbox.
 * - help: muted small text under the control.
 * - unit: text after a number, such as "minutes".
 * - width: control width in pixels.
 * - options: value => label, or value => array( 'label' => ..., 'help' => ... ).
 * - show_if: array( field => value ) shows the row only when the other field has that value.
 * - placeholder, dir, autocomplete.
 *
 * A layout is array( 'cards' => array( card ... ), 'cannot' => string[] ). A card is
 * array( 'id', 'title', 'description', 'rows' ). A row is array( 'field' => name ),
 * or array( 'label' => text, 'html' => callable ) for a computed row; the callable
 * returns escaped HTML.
 */
abstract class Section {

	/**
	 * Section name, as in `estasoft_nd_settings.<name>` and `/settings/<name>`.
	 * Every section class declares its own.
	 *
	 * @var string
	 */
	public const NAME = '';

	/**
	 * Field specs, keyed by field name. See Validator for the spec format.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract public function fields(): array;

	/**
	 * The name in the section list and the page heading.
	 *
	 * @return string
	 */
	abstract public function title(): string;

	/**
	 * One line under the page heading.
	 *
	 * @return string
	 */
	public function description(): string {
		return '';
	}

	/**
	 * Which group of the section list holds this section: site, content or operations.
	 *
	 * @return string
	 */
	public function group(): string {
		return 'site';
	}

	/**
	 * Position inside the group. Lower comes first.
	 *
	 * @return int
	 */
	public function order(): int {
		return 100;
	}

	/**
	 * Who may read and save the section: a permission name from Core\Access.
	 *
	 * @return string
	 */
	public function permission(): string {
		return 'manage';
	}

	/**
	 * Who may set or remove a secret field of this section, when that is stricter than who may save the section.
	 * An empty string (the default) adds no rule. `Settings::save_section()` refuses a save that touches a
	 * secret when the signed-in person lacks the permission, so every way in (the form, REST, the script) is covered.
	 *
	 * @return string A permission name from Core\Access, or an empty string.
	 */
	public function secret_permission(): string {
		return '';
	}

	/**
	 * Checks the secrets a save is about to store, for example that an address is the right kind. Called only for
	 * secrets that were typed, never for one that is kept.
	 *
	 * @param array<string, string> $changes Field => new value ("" removes the secret).
	 * @return array<string, string> Field => message. An empty list means all is well.
	 */
	public function check_secrets( array $changes ): array {
		return array();
	}

	/**
	 * Whether the section exists on this site. A section that depends on a
	 * WordPress version or on multisite returns false elsewhere, and then
	 * appears nowhere.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return true;
	}

	/**
	 * Whether the page ends with a save bar. Read-only pages return false.
	 *
	 * @return bool
	 */
	public function has_save_bar(): bool {
		return true;
	}

	/**
	 * Whether the settings page wraps the section in its one save form.
	 *
	 * A section made of separate actions (a backup page with its own upload,
	 * restore and reset buttons) returns false and prints a form for each
	 * action, because a form cannot sit inside another form.
	 *
	 * @return bool
	 */
	public function in_form(): bool {
		return true;
	}

	/**
	 * A whole page for one tab of this section, such as `&tab=emails`.
	 *
	 * Return escaped HTML, with its own single `h1`, to replace the settings
	 * page (its heading, side list and form). Return null for every tab the
	 * section does not own, and the normal settings page is printed.
	 *
	 * @param string $tab The `tab` argument of the address, or an empty string.
	 * @return string|null
	 */
	public function render_tab( string $tab ): ?string {
		return null;
	}

	/**
	 * Whether saving this section changes what feeds and sitemaps print, so
	 * the output cache must be dropped.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return true;
	}

	/**
	 * The cards and rows of the form. The default is one card holding every field.
	 *
	 * @return array{cards: array<int, array<string, mixed>>, cannot: string[]}
	 */
	public function layout(): array {
		$rows = array();

		foreach ( array_keys( $this->fields() ) as $field ) {
			$rows[] = array( 'field' => $field );
		}

		return array(
			'cards'  => array(
				array(
					'id'          => $this->name(),
					'title'       => $this->title(),
					'description' => '',
					'rows'        => $rows,
				),
			),
			'cannot' => array(),
		);
	}

	/**
	 * Extra markup after the form cards and before the "cannot" card. Return
	 * escaped HTML, or an empty string.
	 *
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string
	 */
	public function render_extra( array $values ): string {
		return '';
	}

	/**
	 * Replaces the whole form. Return escaped HTML to print instead of the cards,
	 * or null to use the layout.
	 *
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string|null
	 */
	public function render_custom( array $values ): ?string {
		return null;
	}

	/**
	 * Extra checks across fields, run after each field is valid.
	 *
	 * Return a list of field path => message. An empty list means all is well.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		return array();
	}

	/**
	 * Turns what a form posts into the shape the fields store, before it is validated.
	 *
	 * A form shows a language as a switch that is on or off, while the section
	 * stores the languages that are off. Such a section reads its form-only keys
	 * here and returns input that uses the stored field names. The default returns
	 * the input unchanged, and a script that posts the stored shape is not affected.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		return $input;
	}

	/**
	 * Runs after the section was saved.
	 *
	 * @param array<string, mixed> $values   The saved values.
	 * @param array<string, mixed> $previous The values before the save.
	 * @return void
	 */
	public function saved( array $values, array $previous ): void {
	}

	/**
	 * Default values for every field.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return Validator::defaults( $this->fields() );
	}

	/**
	 * Section name.
	 *
	 * @return string
	 */
	public function name(): string {
		return static::NAME;
	}
}
