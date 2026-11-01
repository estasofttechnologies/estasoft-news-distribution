<?php
/**
 * Credit and rights for photos: the fields in the media library, the fill from the photo file, and platform copies.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Content\ImageCredits;
use Estasoft\NewsDistribution\Content\ImagePresets;
use Estasoft\NewsDistribution\Content\PhotoMetadata;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Settings\Sections\Credits;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * What this module does, and nothing else:
 *
 * - Registers the photo's fields as meta (`_estasoft_nd_credit`, `_estasoft_nd_credit_source`,
 *   `_estasoft_nd_credit_note`), so the media REST routes can carry them.
 * - Adds the box "News Distribution: credit and rights" to the attachment details of an image, in the
 *   media library and in the media modal, and saves it. A script adds the button "Fill from the photo
 *   file", which calls `POST /media/{id}/credit`.
 * - On upload, fills the empty credit, caption and source note from the photo file, when
 *   Settings, Image credits says so. A value that is already there is never replaced.
 * - When "When a rule fails" is "Skip the article on channels that ask for a credit", skips an article whose
 *   featured image has no credit on the channels whose rule pack has a `credit.missing` rule.
 * - Makes the platform copies of a featured image in the background when an article is published and
 *   "Make presets when I publish" is on, and removes a photo's copies when the photo is deleted.
 *
 * It calls no other server.
 */
final class ImageCreditsModule implements ModuleInterface {

	public const NONCE = 'estasoft_nd_credit';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_fields' ), 10, 2 );
		add_action( 'add_attachment', array( $this, 'on_upload' ) );
		add_action( 'delete_attachment', array( $this, 'on_delete' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 20 );
		add_action( 'wp_enqueue_media', array( $this, 'media_assets' ) );
		add_filter( 'estasoft_nd_internal_delivery_hold', array( $this, 'hold' ), 20, 4 );
		add_filter( 'estasoft_nd_internal_feed_articles', array( $this, 'feed_articles' ), 20, 2 );
		add_action( 'transition_post_status', array( $this, 'published' ), 20, 3 );
		add_action( ImagePresets::HOOK, array( $this, 'make_presets' ) );
	}

	/**
	 * @return ImageCredits
	 */
	private function credits(): ImageCredits {
		return $this->plugin->make( ImageCredits::class );
	}

	/**
	 * Registers the photo's fields as attachment meta.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$auth = static function ( $allowed, $meta_key, $post_id ): bool {
			return current_user_can( 'edit_post', (int) $post_id );
		};

		register_post_meta(
			'attachment',
			ImageCredits::CREDIT,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): string {
					return ImageCredits::clean_text( $value, ImageCredits::MAX_CREDIT );
				},
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			'attachment',
			ImageCredits::SOURCE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( ImageCredits::class, 'clean_source' ),
				'auth_callback'     => $auth,
			)
		);

		register_post_meta(
			'attachment',
			ImageCredits::NOTE,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $value ): string {
					return ImageCredits::clean_text( $value, ImageCredits::MAX_NOTE );
				},
				'auth_callback'     => $auth,
			)
		);
	}

	/**
	 * Adds the box to the attachment details of an image.
	 *
	 * @param mixed $form_fields The fields WordPress shows.
	 * @param mixed $post        The attachment.
	 * @return mixed
	 */
	public function fields( $form_fields, $post = null ) {
		if ( ! is_array( $form_fields ) || ! $post instanceof \WP_Post || ! $this->credits()->is_image( (int) $post->ID ) || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $form_fields;
		}

		$form_fields['estasoft_nd_rights'] = array(
			'label'         => '',
			'input'         => 'html',
			'html'          => $this->box( $post ),
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $form_fields;
	}

	/**
	 * The box: credit, source, source note, and what it means for the article that uses the photo.
	 *
	 * @param \WP_Post $post The attachment.
	 * @return string Escaped HTML.
	 */
	private function box( \WP_Post $post ): string {
		$id      = (int) $post->ID;
		$credits = $this->credits();
		$stored  = $credits->stored( $id );
		$found   = $credits->file_values( $id );
		$prefix  = 'attachments[' . $id . ']';
		$dom     = 'estasoft-nd-media-' . $id;
		$mark    = static function ( string $field ) use ( $stored ): string {
			return in_array( $field, $stored['from'], true )
				? ' <span class="estasoft-nd-media__from">' . esc_html__( 'From the photo file', 'estasoft-news-distribution' ) . '</span>'
				: '';
		};

		$options = '<option value="">' . esc_html__( 'Choose a source', 'estasoft-news-distribution' ) . '</option>';

		foreach ( ImageCredits::sources() as $value => $label ) {
			$options .= '<option value="' . esc_attr( $value ) . '"' . selected( $stored['source'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		$note_help = '' === $found[ PhotoMetadata::NOTICE ] && '' === $stored['note']
			? esc_html__( 'The photo file has no copyright notice, so nothing was filled in.', 'estasoft-news-distribution' )
			: esc_html__( 'The copyright notice or license details. It is filled from the photo file when the file has one.', 'estasoft-news-distribution' );

		$warning = '';

		if ( '' === $credits->credit_of( $id ) ) {
			$warning = '<p class="estasoft-nd-media__warn" data-credit-warning><span class="estasoft-nd-media__warn-text">' . $this->warning_text( $id ) . '</span></p>';
		}

		return '<div class="estasoft-nd-media" data-credit-box="' . esc_attr( (string) $id ) . '">'
			. '<h3 class="estasoft-nd-media__title">' . esc_html__( 'News Distribution: credit and rights', 'estasoft-news-distribution' ) . '</h3>'
			. '<p class="estasoft-nd-media__field"><label for="' . esc_attr( $dom . '-credit' ) . '">' . esc_html__( 'Credit', 'estasoft-news-distribution' ) . '</label>' . $mark( 'credit' )
			. '<input type="text" class="estasoft-nd-media__input" id="' . esc_attr( $dom . '-credit' ) . '" name="' . esc_attr( $prefix . '[estasoft_nd_credit]' ) . '" value="' . esc_attr( $stored['credit'] ) . '" maxlength="' . esc_attr( (string) ImageCredits::MAX_CREDIT ) . '" placeholder="' . esc_attr__( 'Photo: name or agency', 'estasoft-news-distribution' ) . '" data-credit-field="credit" />'
			. '<span class="estasoft-nd-media__help">' . esc_html__( 'When a photo file carries a credit, it is filled in here and marked “From the photo file”.', 'estasoft-news-distribution' ) . '</span></p>'
			. '<p class="estasoft-nd-media__field"><label for="' . esc_attr( $dom . '-source' ) . '">' . esc_html__( 'Source', 'estasoft-news-distribution' ) . '</label>'
			. '<select class="estasoft-nd-media__input estasoft-nd-media__input--select" id="' . esc_attr( $dom . '-source' ) . '" name="' . esc_attr( $prefix . '[estasoft_nd_credit_source]' ) . '" data-credit-field="source">' . $options . '</select>'
			. '<span class="estasoft-nd-media__help">' . esc_html__( 'The type of source: Our own photographer, News agency, Licensed, Creative Commons or Other.', 'estasoft-news-distribution' ) . '</span></p>'
			. '<p class="estasoft-nd-media__field"><label for="' . esc_attr( $dom . '-note' ) . '">' . esc_html__( 'Source note', 'estasoft-news-distribution' ) . '</label>' . $mark( 'note' )
			. '<input type="text" class="estasoft-nd-media__input" id="' . esc_attr( $dom . '-note' ) . '" name="' . esc_attr( $prefix . '[estasoft_nd_credit_note]' ) . '" value="' . esc_attr( $stored['note'] ) . '" maxlength="' . esc_attr( (string) ImageCredits::MAX_NOTE ) . '" placeholder="' . esc_attr__( 'Copyright notice or license details', 'estasoft-news-distribution' ) . '" data-credit-field="note" />'
			. '<span class="estasoft-nd-media__help" data-credit-note-help>' . $note_help . '</span></p>'
			. '<p class="estasoft-nd-media__actions"><button type="button" class="button estasoft-nd-media__fill" data-credit-fill hidden>' . esc_html__( 'Fill from the photo file', 'estasoft-news-distribution' ) . '</button> <span class="estasoft-nd-media__status" role="status" data-credit-result></span></p>'
			. $warning
			. '<p class="estasoft-nd-media__help">' . esc_html__( 'A value you type is never replaced by the file. An article whose lead image has no credit is skipped by channels that need one, if you chose that in Settings, Image credits.', 'estasoft-news-distribution' ) . '</p>'
			. '<p class="estasoft-nd-media__help"><strong>' . esc_html__( 'What it cannot do:', 'estasoft-news-distribution' ) . '</strong> ' . esc_html__( 'check that you hold the rights to a photo. It keeps the credit and source you give and does not know what you may publish.', 'estasoft-news-distribution' ) . '</p>'
			. '</div>';
	}

	/**
	 * The warning under a photo with no credit.
	 *
	 * @param int $attachment Attachment ID.
	 * @return string Escaped text.
	 */
	private function warning_text( int $attachment ): string {
		$users = get_posts(
			array(
				'post_type'      => $this->plugin->settings()->post_types(),
				'post_status'    => 'any',
				'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One post, only when the box is drawn for a photo with no credit.
				'meta_value'     => $attachment, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Same lookup.
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		if ( array() === $users ) {
			return esc_html__( 'No credit yet. Type one, or leave it empty and Article check flags the photo when it is used as a lead image.', 'estasoft-news-distribution' );
		}

		return esc_html(
			sprintf(
				/* translators: %s: the title of an article that uses this photo as its lead image. */
				__( 'No credit yet. Type one, or leave it empty and Article check flags the lead image of %s.', 'estasoft-news-distribution' ),
				wp_strip_all_tags( get_the_title( (int) $users[0] ) )
			)
		);
	}

	/**
	 * Saves the box. A field that was not sent is left as it is.
	 *
	 * @param mixed $post       The attachment as an array.
	 * @param mixed $attachment The submitted fields.
	 * @return mixed
	 */
	public function save_fields( $post, $attachment = array() ) {
		if ( ! is_array( $post ) || ! is_array( $attachment ) || empty( $post['ID'] ) ) {
			return $post;
		}

		$id = (int) $post['ID'];

		if ( ! current_user_can( 'edit_post', $id ) || ! $this->credits()->is_image( $id ) ) {
			return $post;
		}

		$stored = $this->credits()->stored( $id );
		$from   = $stored['from'];

		if ( isset( $attachment['estasoft_nd_credit'] ) ) {
			$value = ImageCredits::clean_text( $attachment['estasoft_nd_credit'], ImageCredits::MAX_CREDIT );

			if ( $value !== $stored['credit'] ) {
				$from = array_values( array_diff( $from, array( 'credit' ) ) );
			}

			'' === $value ? delete_post_meta( $id, ImageCredits::CREDIT ) : update_post_meta( $id, ImageCredits::CREDIT, $value );
		}

		if ( isset( $attachment['estasoft_nd_credit_source'] ) ) {
			$value = ImageCredits::clean_source( $attachment['estasoft_nd_credit_source'] );

			'' === $value ? delete_post_meta( $id, ImageCredits::SOURCE ) : update_post_meta( $id, ImageCredits::SOURCE, $value );
		}

		if ( isset( $attachment['estasoft_nd_credit_note'] ) ) {
			$value = ImageCredits::clean_text( $attachment['estasoft_nd_credit_note'], ImageCredits::MAX_NOTE );

			if ( $value !== $stored['note'] ) {
				$from = array_values( array_diff( $from, array( 'note' ) ) );
			}

			'' === $value ? delete_post_meta( $id, ImageCredits::NOTE ) : update_post_meta( $id, ImageCredits::NOTE, $value );
		}

		if ( $from !== $stored['from'] ) {
			array() === $from ? delete_post_meta( $id, ImageCredits::FROM ) : update_post_meta( $id, ImageCredits::FROM, $from );
		}

		return $post;
	}

	/**
	 * Fills the empty fields of a new photo from its file.
	 *
	 * @param mixed $attachment_id The new attachment.
	 * @return void
	 */
	public function on_upload( $attachment_id ): void {
		if ( empty( $this->credits()->settings()['on_upload'] ) ) {
			return;
		}

		$this->credits()->fill( (int) $attachment_id );
	}

	/**
	 * Removes the platform copies of a photo that is deleted.
	 *
	 * @param mixed $attachment_id The attachment.
	 * @return void
	 */
	public function on_delete( $attachment_id ): void {
		$this->plugin->make( ImagePresets::class )->forget( (int) $attachment_id );
	}

	/**
	 * Why a channel should skip an article because its featured image has no credit.
	 *
	 * Only when "Require a credit" is on, "When a rule fails" is the second choice, and the channel's rule pack has
	 * a `credit.missing` rule. No pack that ships has one yet, so today this skips nothing.
	 *
	 * @param int              $post_id Article ID.
	 * @param ChannelInterface $channel The channel.
	 * @return string Empty when the article is not skipped.
	 */
	private function skip_reason( int $post_id, ChannelInterface $channel ): string {
		$settings = $this->credits()->settings();

		if ( empty( $settings['require'] ) || Credits::SKIP !== ( $settings['on_fail'] ?? Credits::WARN ) ) {
			return '';
		}

		if ( array() === $this->plugin->rules()->rule( $channel->id(), 'credit.missing' ) ) {
			return '';
		}

		$attachment = (int) get_post_thumbnail_id( $post_id );

		if ( $attachment < 1 || '' !== $this->credits()->credit_of( $attachment ) ) {
			return '';
		}

		return __( 'Skipped: the featured image has no credit, and this channel asks for one.', 'estasoft-news-distribution' );
	}

	/**
	 * Skips a push when the featured image has no credit and the channel asks for one.
	 *
	 * @param mixed $hold    An answer given earlier, or null.
	 * @param mixed $channel The channel.
	 * @param mixed $event   The event.
	 * @return mixed
	 */
	public function hold( $hold, $channel = null, $event = null ) {
		if ( null !== $hold || ! $channel instanceof ChannelInterface || ! $event instanceof Event || $event->removes_content() || ChannelInterface::MODE_PUSHED !== $channel->mode() ) {
			return $hold;
		}

		$reason = $this->skip_reason( $event->post_id(), $channel );

		return '' === $reason ? $hold : array(
			'status'  => Status::SKIPPED,
			'message' => $reason,
		);
	}

	/**
	 * Takes articles whose featured image has no credit out of a pulled channel that asks for one.
	 *
	 * @param mixed $articles The articles.
	 * @param mixed $channel  The channel.
	 * @return mixed
	 */
	public function feed_articles( $articles, $channel = null ) {
		if ( ! is_array( $articles ) || ! $channel instanceof ChannelInterface ) {
			return $articles;
		}

		$kept = array();

		foreach ( $articles as $article ) {
			if ( $article instanceof Article && '' !== $this->skip_reason( $article->id(), $channel ) ) {
				continue;
			}

			$kept[] = $article;
		}

		return $kept;
	}

	/**
	 * Queues the platform copies of the featured image when an article goes live.
	 *
	 * @param mixed $new_status New status.
	 * @param mixed $old_status Old status.
	 * @param mixed $post       The post.
	 * @return void
	 */
	public function published( $new_status, $old_status, $post = null ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( empty( $this->credits()->settings()['presets_on_publish'] ) || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		if ( (int) get_post_thumbnail_id( $post ) > 0 ) {
			$this->plugin->make( ImagePresets::class )->queue( (int) $post->ID );
		}
	}

	/**
	 * Makes the copies. Called by the queue.
	 *
	 * @param mixed $post_id Article ID.
	 * @return void
	 */
	public function make_presets( $post_id = 0 ): void {
		$this->plugin->make( ImagePresets::class )->make_for_article( (int) $post_id );
	}

	/**
	 * Loads the Image credits screen's files.
	 *
	 * @return void
	 */
	public function assets(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' === $page && Credits::NAME === $section ) {
			Assets::screen_style( 'content-features' );
			Assets::screen_script( 'content-features' );
			Assets::screen_script( 'image-credits', $this->screen_data() );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen instanceof \WP_Screen && ( 'upload' === $screen->base || ( 'post' === $screen->base && 'attachment' === $screen->post_type ) ) ) {
			$this->media_assets();
		}
	}

	/**
	 * Loads the media library's stylesheet and the script for the fill button.
	 *
	 * @return void
	 */
	public function media_assets(): void {
		wp_enqueue_style( 'estasoft-nd-media-credit', ESTASOFT_ND_URL . 'assets/css/media-credit.css', array(), Assets::file_version( 'assets/css/media-credit.css' ) );
		wp_enqueue_script( 'estasoft-nd-media-credit', ESTASOFT_ND_URL . 'assets/js/screens/media-credit.js', array(), Assets::file_version( 'assets/js/screens/media-credit.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'estasoft-nd-media-credit',
			'window.estasoftNDMediaCredit = ' . wp_json_encode(
				array(
					'rest'  => esc_url_raw( rest_url( 'estasoft-nd/v1/' ) ),
					'nonce' => wp_create_nonce( 'wp_rest' ),
					'text'  => array(
						'working' => __( 'Reading the photo file…', 'estasoft-news-distribution' ),
						'failed'  => __( 'The photo file could not be read. Try again.', 'estasoft-news-distribution' ),
						'from'    => __( 'From the photo file', 'estasoft-news-distribution' ),
					),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * What the Image credits screen's script needs.
	 *
	 * @return array<string, mixed>
	 */
	private function screen_data(): array {
		return array(
			'text' => array(
				'scanning'  => __( 'Looking through the media library…', 'estasoft-news-distribution' ),
				/* translators: 1: how many photos were looked through, 2: how many photos the library has. */
				'scanned'   => __( 'Looked through %1$s of %2$s photos…', 'estasoft-news-distribution' ),
				'filling'   => __( 'Filling…', 'estasoft-news-distribution' ),
				/* translators: %s: how many photos were filled, such as 3. */
				'filled'    => __( 'Filled %s photos.', 'estasoft-news-distribution' ),
				'filled_1'  => __( 'Filled 1 photo.', 'estasoft-news-distribution' ),
				'undo'      => __( 'Undo', 'estasoft-news-distribution' ),
				/* translators: %s: how many fields went back to their previous value. */
				'undone'    => __( 'Undone. %s fields went back to their previous value.', 'estasoft-news-distribution' ),
				'failed'    => __( 'That did not work. Try again.', 'estasoft-news-distribution' ),
				/* translators: %s: how many photos are ticked, such as 3. */
				'fill_n'    => __( 'Fill %s selected', 'estasoft-news-distribution' ),
				/* translators: %s: how many photos have a credit in the file and none in WordPress, such as 412. */
				'all_n'     => __( 'Select all %s', 'estasoft-news-distribution' ),
				'none'      => __( 'No photo has a credit in the file and none in WordPress. A photo that already has a credit in WordPress is never listed or changed.', 'estasoft-news-distribution' ),
				/* translators: %s: the number of photos, such as 412. */
				'intro'     => __( '%s photos have a credit in the file and none in WordPress. A photo that already has a credit in WordPress is never listed or changed. Nothing changes until you press Fill.', 'estasoft-news-distribution' ),
				'intro_one' => __( '1 photo has a credit in the file and none in WordPress. A photo that already has a credit in WordPress is never listed or changed. Nothing changes until you press Fill.', 'estasoft-news-distribution' ),
				'will_fill' => __( 'Will fill', 'estasoft-news-distribution' ),
				'empty'     => __( 'Empty', 'estasoft-news-distribution' ),
				/* translators: 1: how many photos are shown, 2: how many are listed in all. */
				'showing'   => __( 'Showing %1$s of %2$s. Filling applies when you press it, not when you save settings. A line then says “Filled 3 photos” with an Undo link, and each change keeps the previous value.', 'estasoft-news-distribution' ),
			),
		);
	}
}
