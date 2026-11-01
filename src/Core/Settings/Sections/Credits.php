<?php
/**
 * Settings, Image credits.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Switcher;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Credits for photos: what is filled from the photo file, what Article check asks for, and what a feed writes.
 *
 * Stored as `estasoft_nd_settings.credits`:
 *
 * - `on_upload` (bool): fill the credit, caption and source note from the photo file when a photo is uploaded.
 * - `no_credit` (`flag` or `publication`): when the file has no credit, leave it empty so Article check flags it,
 *   or use the publication name.
 * - `require` (bool): list a featured image with no credit as a note to fix in Article check.
 * - `on_fail` (`warn` or `skip`): only tell me, or skip the article on channels whose rule pack asks for a credit.
 * - `in_caption` (bool): in feeds, write the credit after the caption.
 * - `presets_on_publish` (bool): make the platform copies of the featured image when an article is published
 *   (the choice on the Image presets tab of Article check).
 *
 * The behaviour is in `Content\ImageCredits`, `Content\ImagePresets` and `Modules\ImageCreditsModule`.
 */
final class Credits extends Section {

	public const NAME = 'credits';

	public const WARN = 'warn';
	public const SKIP = 'skip';

	public const FLAG        = 'flag';
	public const PUBLICATION = 'publication';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'on_upload'          => array(
				'type'    => 'bool',
				'label'   => __( 'On upload', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'no_credit'          => array(
				'type'    => 'enum',
				'label'   => __( 'When the file has no credit', 'estasoft-news-distribution' ),
				'default' => self::FLAG,
				'control' => 'select',
				'options' => array(
					self::FLAG        => __( 'Leave it empty and flag it', 'estasoft-news-distribution' ),
					self::PUBLICATION => __( 'Use the publication name', 'estasoft-news-distribution' ),
				),
				'width'   => 360,
				'help'    => __( 'A flagged photo shows as a missing credit in Article check.', 'estasoft-news-distribution' ),
			),
			'require'            => array(
				'type'    => 'bool',
				'label'   => __( 'Require a credit', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'on_fail'            => array(
				'type'    => 'enum',
				'label'   => __( 'When a rule fails', 'estasoft-news-distribution' ),
				'default' => self::WARN,
				'control' => 'select',
				'options' => array(
					self::WARN => __( 'Only tell me', 'estasoft-news-distribution' ),
					self::SKIP => __( 'Skip the article on channels that ask for a credit', 'estasoft-news-distribution' ),
				),
				'width'   => 420,
				'help'    => __( 'The second choice affects only a channel whose rule pack asks for a credit. Platform rules shows which ones do. A note never stops you from publishing; the gate does that, and it is set in Access and gate.', 'estasoft-news-distribution' ),
			),
			'in_caption'         => array(
				'type'    => 'bool',
				'label'   => __( 'Put the credit in the caption', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'presets_on_publish' => array(
				'type'    => 'bool',
				'label'   => __( 'Make presets when I publish', 'estasoft-news-distribution' ),
				'default' => true,
			),
		);
	}

	public function title(): string {
		return __( 'Image credits', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 80;
	}

	/**
	 * Credits are read when a feed is built, so changing them rebuilds what is cached.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return true;
	}

	/**
	 * The help under "When the file has no credit", with the name the publication goes by.
	 *
	 * @return string Plain text.
	 */
	private function no_credit_help(): string {
		$name = trim( (string) Plugin::instance()->settings()->get( 'general.publication_name', '' ) );

		if ( '' === $name ) {
			return __( 'A flagged photo shows as a missing credit in Article check. The publication name is set in General.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: the name of the publication, such as Example Times. */
			__( 'A flagged photo shows as a missing credit in Article check. The publication name is %s.', 'estasoft-news-distribution' ),
			$name
		);
	}

	/**
	 * Without a name to use, "Use the publication name" cannot do what it says.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		if ( self::PUBLICATION === ( $values['no_credit'] ?? '' ) && '' === trim( (string) Plugin::instance()->settings()->get( 'general.publication_name', '' ) ) ) {
			return array(
				'no_credit' => __( 'Add the publication name in General first, or leave the credit empty and flag it.', 'estasoft-news-distribution' ),
			);
		}

		return array();
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'file',
					'title'       => __( 'Credit from the photo file', 'estasoft-news-distribution' ),
					'description' => __( 'Photographers and agencies write the credit and caption into the file itself (IPTC and EXIF). When you upload a photo, News Distribution reads them and fills the empty fields. It never replaces what a person typed.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'On upload', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_on_upload' ),
						),
						array(
							'label' => __( 'What it fills', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_fills' ),
						),
						array(
							'label' => __( 'When the file has no credit', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_no_credit' ),
						),
					),
				),
				array(
					'id'          => 'require',
					'title'       => __( 'What to require', 'estasoft-news-distribution' ),
					'description' => __( 'Credits are for readers and for the photographers. Platforms decide what they accept.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Require a credit', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_require' ),
						),
						array( 'field' => 'on_fail' ),
						array(
							'label' => __( 'Put the credit in the caption', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_in_caption' ),
						),
					),
				),
				array(
					'id'          => 'existing',
					'title'       => __( 'Photos you already have', 'estasoft-news-distribution' ),
					'description' => '',
					'rows'        => array(
						array(
							'html' => array( $this, 'render_existing' ),
							'wide' => true,
						),
					),
				),
			),
			'cannot' => array(
				__( 'Check licenses. It copies the credit as written and does not know what you may publish.', 'estasoft-news-distribution' ),
				__( 'Match an agency’s credit style. “AP Photo/Name” stays as the file has it.', 'estasoft-news-distribution' ),
				__( 'Read files that were stripped of their metadata, which many social apps and optimizers do.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * A switch with a line of text and no On or Off in front of it.
	 *
	 * @param string               $field  Field name.
	 * @param array<string, mixed> $values The section's values.
	 * @param string               $text   The text beside the switch.
	 * @return string Escaped HTML.
	 */
	private function switch_for( string $field, array $values, string $text ): string {
		return Switcher::render( Field::name( $field ), Field::id( self::NAME, $field ), ! empty( $values[ $field ] ), $text, false );
	}

	/**
	 * The "When the file has no credit" row, with the name the publication goes by in its help.
	 *
	 * @param array<string, mixed>  $values The section's values.
	 * @param array<string, string> $errors Rejected fields.
	 * @return string Escaped HTML.
	 */
	public function render_no_credit( array $values, array $errors = array() ): string {
		$spec         = $this->fields()['no_credit'];
		$spec['help'] = $this->no_credit_help();

		return Field::render( self::NAME, 'no_credit', $spec, $values['no_credit'] ?? self::FLAG, $errors['no_credit'] ?? '' );
	}

	/**
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_on_upload( array $values ): string {
		return $this->switch_for( 'on_upload', $values, __( 'Fill the credit, caption and source note from the photo file', 'estasoft-news-distribution' ) );
	}

	/**
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_require( array $values ): string {
		return $this->switch_for( 'require', $values, __( 'List a featured image with no credit as a note to fix in Article check', 'estasoft-news-distribution' ) );
	}

	/**
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_in_caption( array $values ): string {
		return $this->switch_for( 'in_caption', $values, __( 'In feeds, write the credit after the caption, for example “Anna Verhoeven / Example Times”', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Your theme’s own captions on the page are not changed.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The "What it fills" row: what is filled, and what never is.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_fills(): string {
		$items = array(
			array( 'check', __( 'Credit', 'estasoft-news-distribution' ), __( 'From the file’s credit line, then its creator, then the camera’s artist field.', 'estasoft-news-distribution' ), 'ok' ),
			array( 'check', __( 'Caption', 'estasoft-news-distribution' ), __( 'From the file’s description, when the caption is empty.', 'estasoft-news-distribution' ), 'ok' ),
			array( 'check', __( 'Source note', 'estasoft-news-distribution' ), __( 'From the file’s copyright notice, when the note is empty.', 'estasoft-news-distribution' ), 'ok' ),
			array( 'info', __( 'Source', 'estasoft-news-distribution' ), __( 'Not filled. You choose the type: our own photographer, news agency, licensed, Creative Commons or other.', 'estasoft-news-distribution' ), 'info' ),
			array( 'minus-circle', __( 'Alt text', 'estasoft-news-distribution' ), __( 'Never. Alt text describes the picture for readers who cannot see it, and a photographer’s caption does not.', 'estasoft-news-distribution' ), 'no' ),
		);
		$list  = '';

		foreach ( $items as $item ) {
			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--' . $item[3] ),
				Icon::svg( $item[0] ) . Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html( $item[1] ) ) . '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $item[2] ) )
				)
			);
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list );
	}

	/**
	 * The "Photos you already have" card: what the last look through the library found, with the fill buttons.
	 *
	 * The list is built from what the last scan stored. A script does the scan and the fill (`image-credits.js`),
	 * which is why the buttons are hidden until it runs.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_existing(): string {
		$credits = Plugin::instance()->make( ImageCredits::class );
		$found   = $credits->candidates( 10 );
		$total   = $found['total'];
		$rows    = array();

		foreach ( $found['items'] as $item ) {
			$rows[] = $this->photo_cells( (int) $item['id'], $item['file'], $item['in_file'], false );
		}

		// A row a script copies for each photo it lists; it stays hidden.
		$rows[] = $this->photo_cells( 0, '', '', true );

		$intro = $this->existing_intro( $found );
		$table = Html::tag(
			'div',
			array(
				'class'            => 'estasoft-nd-credit-table',
				'data-credit-list' => true,
				'hidden'           => 0 === $total,
			),
			Grid::render( 'estasoft-nd-cols-credits', array( __( 'Photo', 'estasoft-news-distribution' ), __( 'Result', 'estasoft-news-distribution' ), __( 'In the file', 'estasoft-news-distribution' ), __( 'In WordPress', 'estasoft-news-distribution' ) ), $rows, __( 'Photos that have a credit in the file and none in WordPress', 'estasoft-news-distribution' ) )
		);

		$actions = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-credit-actions' ),
			Button::button(
				__( 'Look through the library', 'estasoft-news-distribution' ),
				array(
					'attrs' => array(
						'data-credit-scan' => true,
						'hidden'           => true,
					),
				)
			)
			. Button::button(
				sprintf(
					/* translators: %s: how many photos are ticked, such as 3. */
					__( 'Fill %s selected', 'estasoft-news-distribution' ),
					number_format_i18n( min( $total, 10 ) )
				),
				array(
					'attrs' => array(
						'data-credit-fill' => true,
						'hidden'           => true,
					),
				)
			)
			. Button::button(
				sprintf(
					/* translators: %s: how many photos have a credit in the file and none in WordPress, such as 412. */
					__( 'Select all %s', 'estasoft-news-distribution' ),
					number_format_i18n( $total )
				),
				array(
					'attrs' => array(
						'data-credit-all' => true,
						'hidden'          => true,
					),
				)
			)
			. Html::tag(
				'span',
				array(
					'class'            => 'estasoft-nd-muted estasoft-nd-small',
					'data-credit-note' => true,
				),
				esc_html( $this->existing_note( $found ) )
			)
		);

		return Html::tag(
			'div',
			array(
				'class'            => 'estasoft-nd-credit-bulk',
				'data-credit-bulk' => true,
				'data-total'       => (string) $total,
				'data-scanned'     => (string) $found['at'],
			),
			Html::tag(
				'p',
				array(
					'class'             => 'estasoft-nd-muted estasoft-nd-pad',
					'data-credit-intro' => true,
				),
				esc_html( $intro )
			)
			. $table
			. Html::tag(
				'p',
				array(
					'class'              => 'estasoft-nd-pad estasoft-nd-credit-status',
					'role'               => 'status',
					'data-credit-status' => true,
					'hidden'             => true,
				),
				''
			)
			. $actions
		);
	}

	/**
	 * The four cells of one photo's row.
	 *
	 * @param int    $id      Attachment ID, or 0 for the blank row a script copies.
	 * @param string $file    File name.
	 * @param string $in_file The credit in the file.
	 * @param bool   $blank   Whether this is the blank row.
	 * @return array<int, string>
	 */
	private function photo_cells( int $id, string $file, string $in_file, bool $blank ): array {
		$dom = 'estasoft-nd-photo-' . ( $blank ? 'blank' : (string) $id );

		return array(
			Html::tag(
				'label',
				array(
					'class'             => 'estasoft-nd-check',
					'for'               => $dom,
					'data-credit-blank' => $blank ? true : null,
				),
				Html::void(
					'input',
					array(
						'type'            => 'checkbox',
						'id'              => $dom,
						'value'           => $blank ? '' : (string) $id,
						'checked'         => true,
						'data-credit-row' => true,
					)
				) . Html::tag( 'code', array(), esc_html( $file ) )
			),
			StatusView::inline( Status::WILL_FILL ),
			Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $in_file ) ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Empty', 'estasoft-news-distribution' ) ),
		);
	}

	/**
	 * The sentence above the list.
	 *
	 * @param array{total: int, scanned: int, library: int, at: int, done: bool, items: array<int, mixed>} $found What the last look found.
	 * @return string Plain text.
	 */
	private function existing_intro( array $found ): string {
		if ( 0 === $found['at'] ) {
			return __( 'Look through your media library for photos that have a credit in the file and none in WordPress. A photo that already has a credit in WordPress is never listed or changed. Nothing changes until you press Fill.', 'estasoft-news-distribution' );
		}

		if ( 0 === $found['total'] ) {
			return __( 'No photo has a credit in the file and none in WordPress. A photo that already has a credit in WordPress is never listed or changed.', 'estasoft-news-distribution' );
		}

		return sprintf(
			/* translators: %s: the number of photos, such as 412. */
			_n(
				'%s photo has a credit in the file and none in WordPress. A photo that already has a credit in WordPress is never listed or changed. Nothing changes until you press Fill.',
				'%s photos have a credit in the file and none in WordPress. A photo that already has a credit in WordPress is never listed or changed. Nothing changes until you press Fill.',
				$found['total'],
				'estasoft-news-distribution'
			),
			number_format_i18n( $found['total'] )
		);
	}

	/**
	 * The small note beside the buttons.
	 *
	 * @param array{total: int, scanned: int, library: int, at: int, done: bool, items: array<int, mixed>} $found What the last look found.
	 * @return string Plain text.
	 */
	private function existing_note( array $found ): string {
		if ( 0 === $found['total'] ) {
			return '';
		}

		return sprintf(
			/* translators: 1: how many photos are shown, such as 4. 2: how many are listed in all, such as 412. */
			__( 'Showing %1$s of %2$s. Filling applies when you press it, not when you save settings. A line then says “Filled 3 photos” with an Undo link, and each change keeps the previous value.', 'estasoft-news-distribution' ),
			number_format_i18n( count( $found['items'] ) ),
			number_format_i18n( $found['total'] )
		);
	}
}
