<?php
/**
 * The plain pictures of the demo's sample articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Demo;

defined( 'ABSPATH' ) || exit;

/**
 * Draws a small gradient with GD and adds it to the media library as the featured image of a sample.
 *
 * Nothing is downloaded and nothing is stock: the picture is a colored gradient, 1,200 by 675 pixels, so it meets
 * the size and shape the article checks ask for. When GD is not installed `available()` is false, the samples are
 * made without pictures, and the demo banner says so.
 */
final class SampleImages {

	public const WIDTH  = 1200;
	public const HEIGHT = 675;

	/**
	 * Whether this server can draw the pictures.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		/**
		 * Lets a host or a test say pictures cannot be drawn, so the samples are made without them (internal).
		 *
		 * @param bool $allowed Whether pictures may be drawn. True by default.
		 */
		$allowed = (bool) apply_filters( 'estasoft_nd_internal_demo_pictures', true );

		return $allowed && function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) && function_exists( 'imagefilledrectangle' );
	}

	/**
	 * The bytes of one picture: a vertical gradient from a lighter to a darker shade of one color.
	 *
	 * @param array<int, int> $color Red, green and blue of the base color.
	 * @return string JPEG bytes, or an empty string when the picture could not be drawn.
	 */
	public static function bytes( array $color ): string {
		if ( ! self::available() ) {
			return '';
		}

		$image = imagecreatetruecolor( self::WIDTH, self::HEIGHT );

		if ( false === $image ) {
			return '';
		}

		for ( $y = 0; $y < self::HEIGHT; $y += 3 ) {
			$mix   = $y / self::HEIGHT;
			$red   = (int) round( min( 255, $color[0] + ( 255 - $color[0] ) * 0.35 * ( 1 - $mix ) - $color[0] * 0.25 * $mix ) );
			$green = (int) round( min( 255, $color[1] + ( 255 - $color[1] ) * 0.35 * ( 1 - $mix ) - $color[1] * 0.25 * $mix ) );
			$blue  = (int) round( min( 255, $color[2] + ( 255 - $color[2] ) * 0.35 * ( 1 - $mix ) - $color[2] * 0.25 * $mix ) );
			$shade = imagecolorallocate( $image, max( 0, $red ), max( 0, $green ), max( 0, $blue ) );

			imagefilledrectangle( $image, 0, $y, self::WIDTH - 1, min( self::HEIGHT - 1, $y + 2 ), $shade );
		}

		ob_start();
		imagejpeg( $image, null, 82 );
		$bytes = (string) ob_get_clean();

		unset( $image );

		return $bytes;
	}

	/**
	 * Adds a picture to the media library.
	 *
	 * The attachment carries the sample marker, belongs to the article, and has the alt text unless `$alt` is empty.
	 *
	 * @param string          $key       The sample's key, such as "festival-en".
	 * @param int             $post_id   The article's post ID.
	 * @param int             $author    The person the picture belongs to.
	 * @param array<int, int> $color     Red, green and blue of the base color.
	 * @param string          $alt       The alt text, or an empty string for none.
	 * @param string          $title     The attachment's title.
	 * @param int             $timestamp Unix time to date the picture.
	 * @return int The attachment ID, or 0 when the picture could not be made.
	 */
	public static function add( string $key, int $post_id, int $author, array $color, string $alt, string $title, int $timestamp ): int {
		$bytes = self::bytes( $color );

		if ( '' === $bytes ) {
			return 0;
		}

		$stored = wp_upload_bits( 'estasoft-nd-sample-' . sanitize_file_name( $key ) . '.jpg', null, $bytes );

		if ( ! empty( $stored['error'] ) || empty( $stored['file'] ) ) {
			return 0;
		}

		$date = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $timestamp ) );
		$id   = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $title,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $author,
				'post_date'      => $date,
				'post_date_gmt'  => gmdate( 'Y-m-d H:i:s', $timestamp ),
				'meta_input'     => array( Demo::META => $key ),
			),
			(string) $stored['file'],
			$post_id
		);

		if ( ! is_int( $id ) || $id < 1 ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, (string) $stored['file'] ) );

		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		return $id;
	}
}
