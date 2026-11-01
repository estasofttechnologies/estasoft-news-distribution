<?php
/**
 * Prints a settings section from its layout.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Ui;

use Estasoft\NewsDistribution\Core\Settings\Section;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Section::layout() and builds the cards, the rows, the note cards and
 * the "What it cannot do" card. Nothing here knows any one section.
 */
final class SectionForm {

	private Section $section;

	/**
	 * Values as the screen shows them.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Error messages by field path.
	 *
	 * @var array<string, string>
	 */
	private array $errors;

	/**
	 * @param Section               $section The section.
	 * @param array<string, mixed>  $values  Values to show.
	 * @param array<string, string> $errors  Rejected fields and their messages.
	 */
	public function __construct( Section $section, array $values, array $errors = array() ) {
		$this->section = $section;
		$this->values  = $values;
		$this->errors  = $errors;
	}

	/**
	 * The cards, the notes and the "cannot" card.
	 *
	 * @return string Escaped HTML.
	 */
	public function html(): string {
		$custom = $this->section->render_custom( $this->values );

		if ( null !== $custom ) {
			return $custom;
		}

		$layout = $this->section->layout();
		$html   = '';

		foreach ( $layout['cards'] as $card ) {
			$rows = '';

			foreach ( (array) $card['rows'] as $row ) {
				$rows .= $this->row( (array) $row );
			}

			$html .= Card::flush(
				$this->section->name() . '-' . (string) $card['id'],
				(string) $card['title'],
				(string) ( $card['description'] ?? '' ),
				$rows
			);
		}

		$html .= $this->section->render_extra( $this->values );

		$notes = '';

		foreach ( (array) ( $layout['notes'] ?? array() ) as $index => $note ) {
			$notes .= Card::note(
				'note-' . $this->section->name() . '-' . $index,
				(string) $note['title'],
				isset( $note['html'] ) ? (string) $note['html'] : Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $note['text'] ) )
			);
		}

		$notes .= Card::cannot( array_map( 'strval', (array) ( $layout['cannot'] ?? array() ) ) );

		if ( '' !== $notes ) {
			$html .= Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $notes );
		}

		return $html;
	}

	/**
	 * One row: a field, or a computed row.
	 *
	 * @param array<string, mixed> $row Row from the layout.
	 * @return string
	 */
	private function row( array $row ): string {
		return ( new FieldRows( $this->section->name(), $this->section->fields(), $this->values, $this->errors ) )->row( $row );
	}
}
