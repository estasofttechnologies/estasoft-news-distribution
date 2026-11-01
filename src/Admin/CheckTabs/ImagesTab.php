<?php
/**
 * Image presets: one featured image, cropped or sized for each platform.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\CheckTabs;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Check\Context;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Content\ImagePresets;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-check&tab=images`, a tab about the whole site: it has no article in the
 * address. It shows the featured image of one article (`&article=<id>`, or the newest article that has
 * one) against what each platform's rule pack asks for, and makes the platform copies.
 *
 * The page works without a script: making a copy, resetting the focal point and saving "Make presets
 * when I publish" are forms that post here (`handle()`). With the script, dragging the circle sets the
 * focal point and "Choose another image" opens the media library.
 *
 * It never edits an original. A copy is a separate file, and the note on the screen says so.
 */
final class ImagesTab extends AbstractCheckTab {

	public const NONCE = 'estasoft_nd_presets';

	public function slug(): string {
		return 'images';
	}

	public function title(): string {
		return __( 'Image presets', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'One featured image, cropped for each platform.', 'estasoft-news-distribution' );
	}

	public function order(): int {
		return 70;
	}

	public function needs_article(): bool {
		return false;
	}

	/**
	 * The breadcrumb names the article being looked at, like the tabs of an article do.
	 *
	 * @param int $post_id Always 0 for this tab.
	 * @return string The article's title, or the tab name when no article has a featured image yet.
	 */
	public function crumb( int $post_id ): string {
		unset( $post_id );

		$article = $this->article_id();

		return $article > 0 ? ( new Context( $this->plugin, $article ) )->title() : $this->title();
	}

	public function permission(): string {
		return Access::MANAGE;
	}

	/**
	 * The article being looked at: the one in the address, else the newest with a featured image.
	 *
	 * @return int 0 when there is none.
	 */
	private function article_id(): int {
		$asked = Query::int( 'article' );

		if ( $asked > 0 && $this->usable( $asked ) ) {
			return $asked;
		}

		$list = $this->recent( 1 );

		return array() === $list ? 0 : $list[0];
	}

	/**
	 * Whether the person may look at this article and it has a featured image that is a photo.
	 *
	 * @param int $post_id Article ID.
	 * @return bool
	 */
	private function usable( int $post_id ): bool {
		return in_array( get_post_type( $post_id ), $this->plugin->settings()->post_types(), true )
			&& $this->plugin->access()->can( Access::VIEW_OWN, $post_id )
			&& (int) get_post_thumbnail_id( $post_id ) > 0;
	}

	/**
	 * The newest articles that have a featured image.
	 *
	 * @param int $limit How many.
	 * @return int[]
	 */
	private function recent( int $limit ): array {
		$args = array(
			'post_type'      => $this->plugin->settings()->post_types(),
			'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only articles that have a featured image, a handful of them.
			'meta_compare'   => 'EXISTS',
			'no_found_rows'  => true,
		);

		if ( $this->plugin->access()->sees_only_own() ) {
			$args['author'] = get_current_user_id();
		}

		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Handles the forms of this tab before anything is printed.
	 *
	 * @param int $post_id Always 0 for this tab.
	 * @return void
	 */
	public function handle( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only asks whether one of the tab's forms was posted; the nonce is checked next, before anything is read from it.
		if ( ! isset( $_POST['estasoft_nd_preset_make'] ) && ! isset( $_POST['estasoft_nd_preset_focal_reset'] ) && ! isset( $_POST['estasoft_nd_presets_save'] ) ) {
			return;
		}

		check_admin_referer( self::NONCE, '_wpnonce' );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$make  = isset( $_POST['estasoft_nd_preset_make'] ) ? sanitize_key( wp_unslash( $_POST['estasoft_nd_preset_make'] ) ) : '';
		$reset = isset( $_POST['estasoft_nd_preset_focal_reset'] );
		$save  = isset( $_POST['estasoft_nd_presets_save'] );
		$asked = isset( $_POST['article'] ) ? absint( wp_unslash( $_POST['article'] ) ) : 0;

		if ( '' === $make && ! $reset && ! $save ) {
			return;
		}

		$args       = array( 'article' => $asked );
		$presets    = $this->plugin->make( ImagePresets::class );
		$attachment = $asked > 0 ? (int) get_post_thumbnail_id( $asked ) : 0;

		if ( $save ) {
			$on     = ! empty( $_POST['estasoft_nd']['presets_on_publish'] ) && '0' !== sanitize_key( wp_unslash( $_POST['estasoft_nd']['presets_on_publish'] ) );
			$result = $this->plugin->settings()->save_section( 'credits', array( 'presets_on_publish' => $on ) );

			$args['presets'] = is_wp_error( $result ) ? 'save_failed' : 'saved';
		} elseif ( $attachment < 1 || ! current_user_can( 'edit_post', $attachment ) ) {
			$args['presets'] = 'no_image';
		} elseif ( $reset ) {
			$presets->set_focal( $attachment, null, null );
			$args['presets'] = 'focal_reset';
		} else {
			$copy = $presets->make( $attachment, $make );

			$args['presets'] = is_wp_error( $copy ) ? 'make_failed' : 'made';
			$args['preset']  = $make;
		}

		wp_safe_redirect( $this->url( 0, $args ) );
		exit;
	}

	public function enqueue(): void {
		Assets::screen_style( 'content-features' );
		Assets::screen_style( 'image-presets' );
		Assets::screen_script( 'image-presets', $this->script_data() );

		wp_enqueue_media();
	}

	/**
	 * What the script needs.
	 *
	 * @return array<string, mixed>
	 */
	private function script_data(): array {
		$post_id = $this->article_id();
		$type    = $post_id > 0 ? get_post_type_object( (string) get_post_type( $post_id ) ) : null;
		$base    = $type instanceof \WP_Post_Type ? ( is_string( $type->rest_base ) && '' !== $type->rest_base ? $type->rest_base : $type->name ) : 'posts';

		return array(
			'article'    => $post_id,
			'attachment' => $post_id > 0 ? (int) get_post_thumbnail_id( $post_id ) : 0,
			'postRest'   => $post_id > 0 ? esc_url_raw( rest_url( 'wp/v2/' . $base . '/' . $post_id ) ) : '',
			'text'       => array(
				'choose'  => __( 'Choose another image', 'estasoft-news-distribution' ),
				'use'     => __( 'Use this image', 'estasoft-news-distribution' ),
				'failed'  => __( 'That did not work. Try again.', 'estasoft-news-distribution' ),
				'working' => __( 'Saving…', 'estasoft-news-distribution' ),
				'saved'   => __( 'Focal point saved. The copies are made again when you make them or publish.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function render( int $post_id ): string {
		$article = $this->article_id();

		if ( $article < 1 ) {
			return EmptyState::render(
				__( 'No article has a featured image yet', 'estasoft-news-distribution' ),
				__( 'Presets are made from the featured image of an article. Add one to an article and it appears here.', 'estasoft-news-distribution' )
			);
		}

		$attachment = (int) get_post_thumbnail_id( $article );
		$presets    = $this->plugin->make( ImagePresets::class );
		$described  = $presets->describe( $attachment );
		$chosen     = Query::key( 'preset' );

		$html = $this->banner() . $this->picker( $article );

		if ( null === $described ) {
			return $html . Message::bar( 'warning', esc_html__( 'The size of this image cannot be read, so no preset can be worked out. Choose another image.', 'estasoft-news-distribution' ) );
		}

		$crop = null;

		foreach ( $described['presets'] as $row ) {
			if ( null !== $row['crop_fraction'] && ( '' === $chosen || $chosen === $row['id'] ) ) {
				$crop = $row;
				break;
			}
		}

		$left = $this->presets_card( $article, $attachment, $described, $chosen )
			. $this->enlarge_note( $described );

		$right = null === $crop ? '' : $this->crop_card( $article, $attachment, $described, $crop );
		$grid  = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-presets' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-presets__main' ), $left ) . ( '' === $right ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-presets__side' ), $right ) )
		);

		return $html . $grid . $this->cannot() . $this->make_form( $article );
	}

	/**
	 * The result of the last form.
	 *
	 * @return string Escaped HTML.
	 */
	private function banner(): string {
		$result = Query::key( 'presets' );
		$words  = array(
			'saved'       => array( 'success', __( 'Presets saved.', 'estasoft-news-distribution' ) ),
			'save_failed' => array( 'danger', __( 'Presets were not saved. Try again.', 'estasoft-news-distribution' ) ),
			'made'        => array( 'success', __( 'The copy was made. Your original was not changed.', 'estasoft-news-distribution' ) ),
			'make_failed' => array( 'danger', __( 'The copy could not be made. A copy cannot add detail to a picture that is too small, and this server must be able to resize images.', 'estasoft-news-distribution' ) ),
			'focal_reset' => array( 'success', __( 'Focal point reset. The copies are made again when you make them or publish.', 'estasoft-news-distribution' ) ),
			'no_image'    => array( 'danger', __( 'This article has no featured image you can edit.', 'estasoft-news-distribution' ) ),
		);

		return isset( $words[ $result ] ) ? Html::tag( 'div', array( 'class' => 'estasoft-nd-presets__banner' ), Message::text( $words[ $result ][0], $words[ $result ][1] ) ) : '';
	}

	/**
	 * The "which article" row: a plain form that reloads the tab.
	 *
	 * @param int $current The article shown.
	 * @return string Escaped HTML.
	 */
	private function picker( int $current ): string {
		$options = '';
		$ids     = $this->recent( 30 );

		if ( ! in_array( $current, $ids, true ) ) {
			array_unshift( $ids, $current );
		}

		foreach ( $ids as $id ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => (string) $id,
					'selected' => $id === $current,
				),
				esc_html( wp_strip_all_tags( get_the_title( $id ) ) )
			);
		}

		return Html::tag(
			'form',
			array(
				'method' => 'get',
				'action' => admin_url( 'admin.php' ),
				'class'  => 'estasoft-nd-presets__picker',
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'page',
					'value' => 'estasoft-nd-check',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'tab',
					'value' => 'images',
				)
			)
			. Html::tag( 'label', array( 'for' => 'estasoft-nd-presets-article' ), esc_html__( 'Article', 'estasoft-news-distribution' ) )
			. Html::tag(
				'select',
				array(
					'id'    => 'estasoft-nd-presets-article',
					'name'  => 'article',
					'class' => 'estasoft-nd-field',
				),
				$options
			)
			. Button::submit( __( 'Show', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The "Presets" card: the original, then one row for each platform, and the publish switch.
	 *
	 * @param int                  $article    Article ID.
	 * @param int                  $attachment Attachment ID.
	 * @param array<string, mixed> $described  What `ImagePresets::describe()` returned.
	 * @param string               $chosen     The preset whose crop is open.
	 * @return string Escaped HTML.
	 */
	private function presets_card( int $article, int $attachment, array $described, string $chosen ): string {
		$original = $described['original'];
		$rows     = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-preset-row estasoft-nd-preset-row--original' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-preset-row__text' ),
				Html::tag( 'strong', array(), esc_html__( 'Original', 'estasoft-news-distribution' ) )
				. Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: 1: width in pixels, 2: height in pixels, 3: ratio such as 3:2. */
							__( '%1$s × %2$s (%3$s). The source for every copy, and what your own pages show.', 'estasoft-news-distribution' ),
							number_format_i18n( $original['width'] ),
							number_format_i18n( $original['height'] ),
							ImagePresets::ratio_label( $original['width'], $original['height'] )
						)
					)
				)
			)
		);

		foreach ( $described['presets'] as $row ) {
			$rows .= $this->preset_row( $article, $row, $original, $chosen );
		}

		$on     = ! empty( $this->plugin->make( ImageCredits::class )->settings()['presets_on_publish'] );
		$footer = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-preset-foot' ),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => Field::name( 'presets_on_publish' ),
					'value' => '0',
				)
			) . Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-check estasoft-nd-check--top',
					'for'   => 'estasoft-nd-presets-on-publish',
				),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'id'      => 'estasoft-nd-presets-on-publish',
						'name'    => Field::name( 'presets_on_publish' ),
						'value'   => '1',
						'checked' => $on,
					)
				) . Html::tag(
					'span',
					array(),
					esc_html__( 'Make presets when I publish', 'estasoft-news-distribution' ) . '<br>'
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'News Distribution creates the copies for every channel that is on. It cannot add a picture: an article with no featured image is still skipped by channels that need one, such as Microsoft Start.', 'estasoft-news-distribution' ) )
				)
			)
		);

		$card = Card::flush(
			'presets',
			__( 'Presets', 'estasoft-news-distribution' ),
			__( 'Copies News Distribution makes for platforms. Making presets never changes your original or the image on your site.', 'estasoft-news-distribution' ),
			$rows . $footer
		);

		return Html::tag(
			'form',
			array(
				'method'             => 'post',
				'action'             => $this->url( 0, array( 'article' => $article ) ),
				'class'              => 'estasoft-nd-presets__form',
				'novalidate'         => true,
				'data-settings-form' => true,
				'data-section'       => 'credits',
				'data-dirty-guard'   => true,
				'data-attachment'    => (string) $attachment,
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'estasoft_nd_presets_save',
					'value' => '1',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'article',
					'value' => (string) $article,
				)
			)
			. wp_nonce_field( self::NONCE, '_wpnonce', false, false )
			. $card
			. $this->save_bar()
		);
	}

	/**
	 * The bar that saves the one setting here. Same markup as the settings screens, with its own words.
	 *
	 * @return string Escaped HTML.
	 */
	private function save_bar(): string {
		$state = Html::tag(
			'span',
			array(
				'class'              => 'estasoft-nd-savebar__state',
				'data-savebar-state' => true,
				'hidden'             => true,
			),
			Icon::svg( 'warning' ) . Html::tag( 'strong', array(), esc_html__( 'You have unsaved changes', 'estasoft-news-distribution' ) )
		);

		$actions = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-savebar__actions' ),
			Button::button(
				__( 'Discard', 'estasoft-news-distribution' ),
				array(
					'attrs' => array(
						'data-savebar-discard' => true,
						'hidden'               => true,
					),
				)
			)
			. Button::submit( __( 'Save presets', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
		);

		return Html::tag(
			'div',
			array(
				'class'        => 'estasoft-nd-savebar',
				'role'         => 'region',
				'aria-label'   => __( 'Unsaved changes', 'estasoft-news-distribution' ),
				'data-savebar' => true,
			),
			$state . $actions
		);
	}

	/**
	 * One platform's row.
	 *
	 * @param int                  $article  Article ID.
	 * @param array<string, mixed> $row      The preset with its plan.
	 * @param array<string, int>   $original The original's size.
	 * @param string               $chosen   The preset whose crop is open.
	 * @return string Escaped HTML.
	 */
	private function preset_row( int $article, array $row, array $original, string $chosen ): string {
		$label   = (string) $row['label'];
		$text    = '';
		$actions = '';
		$result  = $row['result'];
		$made    = $row['made'];

		if ( ImagePresets::CROP === $row['kind'] ) {
			$text = sprintf(
				/* translators: 1: platform, 2: recommended width, 3: recommended height, 4: ratio, 5: the crop's width, 6: the crop's height. */
				__( '%1$s recommends %2$s × %3$s (%4$s). The crop from your original is %5$s × %6$s.', 'estasoft-news-distribution' ),
				$label,
				number_format_i18n( (int) $row['width'] ),
				number_format_i18n( (int) $row['height'] ),
				ImagePresets::ratio_label( (int) $row['width'], (int) $row['height'] ),
				number_format_i18n( (int) $result['width'] ),
				number_format_i18n( (int) $result['height'] )
			);

			if ( (int) $row['min_width'] > 0 && (int) $row['min_height'] > 0 ) {
				$text = sprintf(
					/* translators: 1: platform, 2: recommended width, 3: recommended height, 4: ratio, 5: least width, 6: least height, 7: the crop's width, 8: the crop's height. */
					__( '%1$s recommends %2$s × %3$s (%4$s) and at least %5$s × %6$s. The crop from your original is %7$s × %8$s.', 'estasoft-news-distribution' ),
					$label,
					number_format_i18n( (int) $row['width'] ),
					number_format_i18n( (int) $row['height'] ),
					ImagePresets::ratio_label( (int) $row['width'], (int) $row['height'] ),
					number_format_i18n( (int) $row['min_width'] ),
					number_format_i18n( (int) $row['min_height'] ),
					number_format_i18n( (int) $result['width'] ),
					number_format_i18n( (int) $result['height'] )
				);
			}

			$actions = Button::link(
				__( 'Preview crop', 'estasoft-news-distribution' ),
				$this->url(
					0,
					array(
						'article' => $article,
						'preset'  => $row['id'],
					)
				),
				array(
					'attrs' => array( 'aria-current' => $chosen === $row['id'] ? 'true' : null ),
				)
			);

			if ( $row['copy'] && null === $made ) {
				$actions .= $this->make_button( (string) $row['id'], __( 'Make crop copy', 'estasoft-news-distribution' ) );
			}
		} elseif ( ImagePresets::LONG_EDGE === $row['kind'] ) {
			$text = sprintf(
				/* translators: 1: platform, 2: the long edge in pixels, 3: the original's long edge in pixels. */
				__( '%1$s recommends a long edge of %2$s px or more. Your original is %3$s px.', 'estasoft-news-distribution' ),
				$label,
				number_format_i18n( (int) $row['width'] ),
				number_format_i18n( max( (int) $original['width'], (int) $original['height'] ) )
			);

			if ( $row['copy'] && null === $made ) {
				$actions = $this->make_button(
					(string) $row['id'],
					sprintf(
						/* translators: %s: the long edge in pixels, such as 1,500. */
						__( 'Make %s px copy', 'estasoft-news-distribution' ),
						number_format_i18n( (int) $row['width'] )
					)
				);
			}
		} else {
			$text = sprintf(
				/* translators: 1: platform, 2: the width in pixels. */
				__( '%1$s recommends images at least %2$s px wide, and shows them large when your page allows large previews.', 'estasoft-news-distribution' ),
				'google-discover' === $row['id'] ? __( 'Google', 'estasoft-news-distribution' ) : $label,
				number_format_i18n( (int) $row['width'] )
			);
		}

		if ( null !== $made ) {
			$text .= ' ' . sprintf(
				/* translators: 1: the copy's width, 2: its height. */
				__( 'A copy of %1$s × %2$s is made.', 'estasoft-news-distribution' ),
				number_format_i18n( $made['width'] ),
				number_format_i18n( $made['height'] )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-preset-row' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-preset-row__text' ),
				Html::tag( 'strong', array(), esc_html( $label ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-preset-row__side' ),
				StatusView::pill( 'clean' === $row['status'] ? Status::CLEAN : Status::ATTENTION ) . $actions
			)
		);
	}

	/**
	 * A button that makes one copy. It posts the form at the end of the tab, so it works inside another form's card.
	 *
	 * @param string $preset Preset id.
	 * @param string $label  Button text.
	 * @return string Escaped HTML.
	 */
	private function make_button( string $preset, string $label ): string {
		return Button::submit(
			$label,
			array(
				'attrs' => array(
					'name'  => 'estasoft_nd_preset_make',
					'value' => $preset,
					'form'  => 'estasoft-nd-make-form',
				),
			)
		);
	}

	/**
	 * The form the make buttons and the reset button post.
	 *
	 * @param int $article Article ID.
	 * @return string Escaped HTML.
	 */
	private function make_form( int $article ): string {
		return Html::tag(
			'form',
			array(
				'id'     => 'estasoft-nd-make-form',
				'method' => 'post',
				'action' => $this->url( 0, array( 'article' => $article ) ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'article',
					'value' => (string) $article,
				)
			) . wp_nonce_field( self::NONCE, '_wpnonce', false, false )
		);
	}

	/**
	 * The note about enlarging, when a copy would be bigger than its original.
	 *
	 * @param array<string, mixed> $described What `ImagePresets::describe()` returned.
	 * @return string Escaped HTML, or an empty string.
	 */
	private function enlarge_note( array $described ): string {
		foreach ( $described['presets'] as $row ) {
			if ( ! empty( $row['enlarges'] ) ) {
				return Message::bar(
					'info',
					esc_html(
						sprintf(
							/* translators: 1: platform, such as SmartNews, 2: the long edge in pixels. */
							__( 'Enlarging a small image makes it softer. For the sharpest result in %1$s, upload an original that is at least %2$s px on its long edge.', 'estasoft-news-distribution' ),
							(string) $row['label'],
							number_format_i18n( (int) $row['width'] )
						)
					)
				);
			}
		}

		return '';
	}

	/**
	 * The "Crop for ..." card: the picture, the crop frame and the focal point.
	 *
	 * @param int                  $article    Article ID.
	 * @param int                  $attachment Attachment ID.
	 * @param array<string, mixed> $described  What `ImagePresets::describe()` returned.
	 * @param array<string, mixed> $row        The crop preset shown.
	 * @return string Escaped HTML.
	 */
	private function crop_card( int $article, int $attachment, array $described, array $row ): string {
		$url   = (string) wp_get_attachment_image_url( $attachment, 'large' );
		$url   = '' === $url ? (string) wp_get_attachment_url( $attachment ) : $url;
		$frac  = $row['crop_fraction'];
		$focal = $described['focal'];
		$alt   = (string) get_post_meta( $attachment, '_wp_attachment_image_alt', true );
		$ratio = ImagePresets::ratio_label( (int) $row['width'], (int) $row['height'] );

		$stage = Html::tag(
			'div',
			array(
				'class'           => 'estasoft-nd-stage',
				'data-stage'      => true,
				'data-crop'       => implode( ',', array( $frac['x'], $frac['y'], $frac['width'], $frac['height'] ) ),
				'data-focal'      => $focal['x'] . ',' . $focal['y'],
				'data-attachment' => (string) $attachment,
				'data-preset'     => (string) $row['id'],
			),
			Html::void(
				'img',
				array(
					'src'     => $url,
					'alt'     => $alt,
					'loading' => 'lazy',
				)
			)
			. Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-stage__crop',
					'data-frame' => true,
				),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-stage__label' ), esc_html( trim( $row['label'] . ' ' . $ratio ) ) )
			)
			. Html::tag(
				'span',
				array(
					'class'      => 'estasoft-nd-stage__focal',
					'data-point' => true,
					'hidden'     => true,
				),
				''
			)
		);

		$body = $stage
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The circle is the focal point: the crop keeps it in view. Drag it to the part of the picture that matters. Only Microsoft Start needs a crop; the other presets use the whole image.', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ),
				Button::submit(
					__( 'Reset focal point', 'estasoft-news-distribution' ),
					array(
						'attrs' => array(
							'name'             => 'estasoft_nd_preset_focal_reset',
							'value'            => '1',
							'form'             => 'estasoft-nd-make-form',
							'data-focal-reset' => true,
						),
					)
				)
				. Button::button(
					__( 'Choose another image', 'estasoft-news-distribution' ),
					array(
						'attrs' => array(
							'data-choose-image' => true,
							'hidden'            => true,
						),
					)
				)
			)
			. Html::tag(
				'p',
				array(
					'class'             => 'estasoft-nd-muted estasoft-nd-small',
					'data-focal-status' => true,
					'role'              => 'status',
				),
				''
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Choose another image opens your media library and changes this article’s featured image. The old image stays in the library, so you can switch back.', 'estasoft-news-distribution' ) );

		return Card::note(
			'crop-' . $article,
			sprintf(
				/* translators: %s: a platform, such as Microsoft Start. */
				__( 'Crop for %s', 'estasoft-news-distribution' ),
				(string) $row['label']
			),
			$body
		);
	}

	/**
	 * The "What it cannot do" card.
	 *
	 * @return string Escaped HTML.
	 */
	private function cannot(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::cannot(
				array(
					__( 'It cannot add a featured image to an article that has none, or make a platform accept an image it rejects. It never edits your original file: the copies are separate files.', 'estasoft-news-distribution' ),
				)
			)
		);
	}
}
