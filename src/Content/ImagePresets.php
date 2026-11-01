<?php
/**
 * Copies of a featured image cropped or sized for each platform.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Content;

use Estasoft\NewsDistribution\Core\CachePurge;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Core\RulePacks;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * A preset is what a platform's rule pack says about its image, turned into a size to aim for:
 *
 * - `crop` (the pack has a recommended width and height, such as Microsoft Start's 1,280 × 720): a copy
 *   cut to that shape, kept in view around the photo's focal point.
 * - `long_edge` (the pack has a long edge to reach, such as SmartNews's 1,500 px): a copy whose long
 *   edge is that long. A smaller original is enlarged, which makes it softer, so the screen says so.
 * - `width` (the pack has a recommended width, such as Google Discover's 1,200 px): nothing is made;
 *   the screen only says whether the original is wide enough, because a copy cannot add detail.
 *
 * Copies are separate files next to the original (`name-nd-<preset>.jpg`), never attachments of their own,
 * and the original is never edited. They are recorded in the photo's `_estasoft_nd_presets` meta, and
 * `url_for( $attachment, $preset )` is the address a channel can use in place of the original's.
 *
 * Every number comes from the rule packs in `rules/`, so it carries the pack's confidence: see the pack
 * for what is confirmed. SmartNews's long edge is not confirmed from a public source.
 *
 * A copy made again keeps its file name, so an image cached by a caching plugin or a CDN would outlive it. A copy that is
 * made, made again or removed is therefore reported to `Core\CachePurge::on_image_copy()`, which drops the copy's address
 * and the feeds of the channel that prints it once per request, if "An image copy is made, replaced or removed" is ticked.
 */
final class ImagePresets {

	public const FOCAL  = '_estasoft_nd_focal';
	public const COPIES = '_estasoft_nd_presets';
	public const HOOK   = 'estasoft_nd_make_presets';

	public const CROP      = 'crop';
	public const LONG_EDGE = 'long_edge';
	public const WIDTH     = 'width';

	/**
	 * The order the screen lists platforms in; any other pack with an image limit follows.
	 */
	private const ORDER = array( 'microsoft-start', 'smartnews', 'google-discover' );

	private Plugin $plugin;

	/**
	 * Creates the service.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The presets the rule packs define.
	 *
	 * @return array<string, array{id: string, label: string, kind: string, width: int, height: int, min_width: int, min_height: int}>
	 */
	public function definitions(): array {
		$rules = $this->plugin->rules();
		$packs = $this->plugin->make( RulePacks::class );
		$out   = array();
		$ids   = array_keys( $rules->all() );

		usort(
			$ids,
			static function ( string $a, string $b ): int {
				$pa = array_search( $a, self::ORDER, true );
				$pb = array_search( $b, self::ORDER, true );
				$pa = false === $pa ? 99 : $pa;
				$pb = false === $pb ? 99 : $pb;

				return $pa === $pb ? strcmp( $a, $b ) : $pa <=> $pb;
			}
		);

		foreach ( $ids as $platform ) {
			$platform = (string) $platform;
			$width    = (int) $rules->limit( $platform, 'image_recommended_width', 0 );
			$height   = (int) $rules->limit( $platform, 'image_recommended_height', 0 );
			$long     = (int) $rules->limit( $platform, 'thumbnail_long_edge_min', 0 );
			$kind     = '';

			if ( $width > 0 && $height > 0 ) {
				$kind = self::CROP;
			} elseif ( $long > 0 ) {
				$kind  = self::LONG_EDGE;
				$width = $long;
			} elseif ( $width > 0 ) {
				$kind = self::WIDTH;
			}

			if ( '' === $kind ) {
				continue;
			}

			$out[ $platform ] = array(
				'id'         => $platform,
				'label'      => $packs->label( $platform ),
				'kind'       => $kind,
				'width'      => $width,
				'height'     => $height,
				'min_width'  => (int) $rules->limit( $platform, 'image_min_width', 0 ),
				'min_height' => (int) $rules->limit( $platform, 'image_min_height', 0 ),
			);
		}

		return $out;
	}

	/**
	 * The size of the original, in pixels.
	 *
	 * @param int $attachment Attachment ID.
	 * @return array{width: int, height: int}|null Null when the size cannot be read.
	 */
	public function original( int $attachment ): ?array {
		$meta = wp_get_attachment_metadata( $attachment );

		if ( is_array( $meta ) && ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
			return array(
				'width'  => (int) $meta['width'],
				'height' => (int) $meta['height'],
			);
		}

		$file = get_attached_file( $attachment );
		$size = is_string( $file ) && is_readable( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A file that is not an image is a normal case.

		return is_array( $size ) && $size[0] > 0 && $size[1] > 0 ? array(
			'width'  => (int) $size[0],
			'height' => (int) $size[1],
		) : null;
	}

	/**
	 * The point of the picture that matters, as fractions of its width and height.
	 *
	 * @param int $attachment Attachment ID.
	 * @return array{x: float, y: float, set: bool}
	 */
	public function focal( int $attachment ): array {
		$stored = get_post_meta( $attachment, self::FOCAL, true );

		if ( is_array( $stored ) && isset( $stored['x'], $stored['y'] ) ) {
			return array(
				'x'   => max( 0.0, min( 1.0, (float) $stored['x'] ) ),
				'y'   => max( 0.0, min( 1.0, (float) $stored['y'] ) ),
				'set' => true,
			);
		}

		return array(
			'x'   => 0.5,
			'y'   => 0.5,
			'set' => false,
		);
	}

	/**
	 * Sets or clears the focal point. Copies made for the old point are no longer right, so they are removed.
	 *
	 * @param int        $attachment Attachment ID.
	 * @param float|null $x          Fraction of the width, or null to clear the point.
	 * @param float|null $y          Fraction of the height.
	 * @return void
	 */
	public function set_focal( int $attachment, ?float $x, ?float $y ): void {
		if ( null === $x || null === $y ) {
			delete_post_meta( $attachment, self::FOCAL );
		} else {
			update_post_meta(
				$attachment,
				self::FOCAL,
				array(
					'x' => round( max( 0.0, min( 1.0, $x ) ), 4 ),
					'y' => round( max( 0.0, min( 1.0, $y ) ), 4 ),
				)
			);
		}

		$this->forget( $attachment );
	}

	/**
	 * The ratio as small numbers, such as "3:2" for 1,200 × 800.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string
	 */
	public static function ratio_label( int $width, int $height ): string {
		if ( $width < 1 || $height < 1 ) {
			return '';
		}

		$ratio = $width / $height;

		foreach ( array( array( 1, 1 ), array( 4, 3 ), array( 3, 2 ), array( 16, 10 ), array( 16, 9 ), array( 2, 1 ), array( 3, 4 ), array( 2, 3 ), array( 9, 16 ) ) as $pair ) {
			if ( abs( $ratio - $pair[0] / $pair[1] ) < 0.01 ) {
				return $pair[0] . ':' . $pair[1];
			}
		}

		return number_format_i18n( $ratio, 2 ) . ':1';
	}

	/**
	 * What a preset means for one original.
	 *
	 * `crop` is the rectangle cut from the original (`x`, `y`, `width`, `height`) for a crop preset. `result` is the
	 * size of the copy that would be made. `status` is `clean` or `attention`. `copy` says whether a copy is worth
	 * making: it is false when the original already does the job. `enlarges` is true when the copy would be
	 * bigger than the original.
	 *
	 * @param array{width: int, height: int} $original The original's size.
	 * @param array<string, mixed>           $preset   A preset from `definitions()`.
	 * @param array{x: float, y: float}      $focal    The focal point.
	 * @return array{status: string, copy: bool, enlarges: bool, result: array{width: int, height: int}, crop: array{x: int, y: int, width: int, height: int}|null, below_minimum: bool}
	 */
	public function plan( array $original, array $preset, array $focal ): array {
		$w    = (int) $original['width'];
		$h    = (int) $original['height'];
		$kind = (string) $preset['kind'];

		if ( self::CROP === $kind ) {
			$ratio = (int) $preset['width'] / max( 1, (int) $preset['height'] );
			$cw    = $w;
			$ch    = (int) round( $w / $ratio );

			if ( $ch > $h ) {
				$ch = $h;
				$cw = (int) round( $h * $ratio );
			}

			$x = (int) round( max( 0, min( $w - $cw, (float) $focal['x'] * $w - $cw / 2 ) ) );
			$y = (int) round( max( 0, min( $h - $ch, (float) $focal['y'] * $h - $ch / 2 ) ) );

			// Cut larger than the recommended size is brought down to it; smaller stays as it is.
			$rw = min( $cw, (int) $preset['width'] );
			$rh = (int) round( $rw / $ratio );

			$below = ( (int) $preset['min_width'] > 0 && $rw < (int) $preset['min_width'] ) || ( (int) $preset['min_height'] > 0 && $rh < (int) $preset['min_height'] );
			$same  = abs( $w / max( 1, $h ) - $ratio ) < 0.01 && $w <= (int) $preset['width'];

			return array(
				'status'        => $below ? 'attention' : 'clean',
				'copy'          => ! $same,
				'enlarges'      => false,
				'result'        => array(
					'width'  => $rw,
					'height' => $rh,
				),
				'crop'          => array(
					'x'      => $x,
					'y'      => $y,
					'width'  => $cw,
					'height' => $ch,
				),
				'below_minimum' => $below,
			);
		}

		if ( self::LONG_EDGE === $kind ) {
			$long  = max( $w, $h );
			$scale = (int) $preset['width'] / max( 1, $long );
			$ok    = $long >= (int) $preset['width'];

			return array(
				'status'        => $ok ? 'clean' : 'attention',
				'copy'          => ! $ok,
				'enlarges'      => ! $ok,
				'result'        => array(
					'width'  => $ok ? $w : (int) round( $w * $scale ),
					'height' => $ok ? $h : (int) round( $h * $scale ),
				),
				'crop'          => null,
				'below_minimum' => false,
			);
		}

		return array(
			'status'        => $w >= (int) $preset['width'] ? 'clean' : 'attention',
			'copy'          => false,
			'enlarges'      => false,
			'result'        => array(
				'width'  => $w,
				'height' => $h,
			),
			'crop'          => null,
			'below_minimum' => false,
		);
	}

	/**
	 * Everything a screen needs to show a photo's presets.
	 *
	 * @param int $attachment Attachment ID.
	 * @return array{original: array{width: int, height: int}, focal: array{x: float, y: float, set: bool}, presets: array<int, array<string, mixed>>}|null Null when the size of the original cannot be read.
	 */
	public function describe( int $attachment ): ?array {
		$original = $this->original( $attachment );

		if ( null === $original ) {
			return null;
		}

		$focal = $this->focal( $attachment );
		$out   = array();

		foreach ( $this->definitions() as $preset ) {
			$plan = $this->plan( $original, $preset, $focal );
			$copy = $this->copy_of( $attachment, $preset['id'] );
			$frac = null;

			if ( null !== $plan['crop'] ) {
				$frac = array(
					'x'      => round( $plan['crop']['x'] / max( 1, $original['width'] ), 5 ),
					'y'      => round( $plan['crop']['y'] / max( 1, $original['height'] ), 5 ),
					'width'  => round( $plan['crop']['width'] / max( 1, $original['width'] ), 5 ),
					'height' => round( $plan['crop']['height'] / max( 1, $original['height'] ), 5 ),
				);
			}

			$out[] = array_merge(
				$preset,
				$plan,
				array(
					'crop_fraction' => $frac,
					'made'          => null === $copy ? null : array(
						'url'    => $copy['url'],
						'width'  => $copy['width'],
						'height' => $copy['height'],
					),
				)
			);
		}

		return array(
			'original' => $original,
			'focal'    => $focal,
			'presets'  => $out,
		);
	}

	/**
	 * The copy made for a preset.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param string $preset     Preset id.
	 * @return array{url: string, width: int, height: int, path: string}|null Null when there is no copy, or its file is gone.
	 */
	public function copy_of( int $attachment, string $preset ): ?array {
		$all   = get_post_meta( $attachment, self::COPIES, true );
		$entry = is_array( $all ) && isset( $all[ $preset ] ) && is_array( $all[ $preset ] ) ? $all[ $preset ] : null;
		$file  = get_attached_file( $attachment );
		$url   = wp_get_attachment_url( $attachment );

		if ( null === $entry || empty( $entry['file'] ) || ! is_string( $file ) || ! is_string( $url ) ) {
			return null;
		}

		$path = trailingslashit( dirname( $file ) ) . wp_basename( (string) $entry['file'] );

		if ( ! is_readable( $path ) ) {
			return null;
		}

		return array(
			'url'    => trailingslashit( dirname( $url ) ) . rawurlencode( wp_basename( (string) $entry['file'] ) ),
			'width'  => (int) ( $entry['width'] ?? 0 ),
			'height' => (int) ( $entry['height'] ?? 0 ),
			'path'   => $path,
		);
	}

	/**
	 * The address a channel should use for a platform's image: the copy when there is one, else an empty string.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param string $preset     Preset id, which is the platform's id: "microsoft-start", "smartnews".
	 * @return string
	 */
	public function url_for( int $attachment, string $preset ): string {
		$copy = $this->copy_of( $attachment, $preset );

		return null === $copy ? '' : $copy['url'];
	}

	/**
	 * Makes one copy. The original is never changed.
	 *
	 * @param int    $attachment Attachment ID.
	 * @param string $preset_id  Preset id.
	 * @return array{url: string, width: int, height: int, path: string}|\WP_Error
	 */
	public function make( int $attachment, string $preset_id ) {
		$presets  = $this->definitions();
		$original = $this->original( $attachment );
		$file     = get_attached_file( $attachment );

		if ( ! isset( $presets[ $preset_id ] ) ) {
			return new \WP_Error( 'estasoft_nd_preset_unknown', __( 'That preset does not exist.', 'estasoft-news-distribution' ) );
		}

		if ( null === $original || ! is_string( $file ) || ! is_readable( $file ) ) {
			return new \WP_Error( 'estasoft_nd_preset_file', __( 'The original image file cannot be read.', 'estasoft-news-distribution' ) );
		}

		$preset = $presets[ $preset_id ];
		$plan   = $this->plan( $original, $preset, $this->focal( $attachment ) );

		if ( self::WIDTH === $preset['kind'] ) {
			return new \WP_Error( 'estasoft_nd_preset_nothing', __( 'A copy cannot add detail to a picture that is too small. Upload a larger original.', 'estasoft-news-distribution' ) );
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return new \WP_Error( 'estasoft_nd_preset_editor', __( 'This server cannot resize images, so no copy was made.', 'estasoft-news-distribution' ) );
		}

		if ( null !== $plan['crop'] ) {
			$done = $editor->crop( $plan['crop']['x'], $plan['crop']['y'], $plan['crop']['width'], $plan['crop']['height'], $plan['result']['width'], $plan['result']['height'] );
		} elseif ( $plan['enlarges'] ) {
			// WordPress never makes a picture larger by itself, and a long-edge preset asks for exactly that when the
			// original is shorter than the size. Only this one resize is allowed to, and the screen says it is softer.
			$allow = static function ( $dimensions, $orig_w, $orig_h, $dest_w, $dest_h ): array {
				unset( $dimensions );

				return array( 0, 0, 0, 0, (int) $dest_w, (int) $dest_h, (int) $orig_w, (int) $orig_h );
			};

			add_filter( 'image_resize_dimensions', $allow, 10, 5 );

			$done = $editor->resize( $plan['result']['width'], $plan['result']['height'], false );

			remove_filter( 'image_resize_dimensions', $allow, 10 );
		} else {
			$done = $editor->resize( $plan['result']['width'], $plan['result']['height'], false );
		}

		if ( is_wp_error( $done ) ) {
			return $done;
		}

		$info  = pathinfo( $file );
		$dest  = trailingslashit( $info['dirname'] ) . $info['filename'] . '-nd-' . $preset_id . '.' . ( $info['extension'] ?? 'jpg' );
		$saved = $editor->save( $dest );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$all               = get_post_meta( $attachment, self::COPIES, true );
		$all               = is_array( $all ) ? $all : array();
		$all[ $preset_id ] = array(
			'file'   => wp_basename( (string) $saved['path'] ),
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
			'at'     => time(),
		);

		update_post_meta( $attachment, self::COPIES, $all );

		// A feed that was built before the copy existed still names the original, so cached feeds are built again.
		$this->plugin->cache()->flush();

		$copy = $this->copy_of( $attachment, $preset_id );

		if ( null === $copy ) {
			return new \WP_Error( 'estasoft_nd_preset_save', __( 'The copy could not be saved.', 'estasoft-news-distribution' ) );
		}

		// A copy made again has the address it had, so a stored image would stay. The purge itself waits for shutdown.
		$this->plugin->make( CachePurge::class )->on_image_copy( $attachment, $preset_id, array( $copy['url'] ) );

		return $copy;
	}

	/**
	 * Makes the copies a featured image needs, for the channels that are on.
	 *
	 * @param int $post_id Article ID.
	 * @return array<string, array{url: string, width: int, height: int, path: string}|\WP_Error> Result by preset id.
	 */
	public function make_for_article( int $post_id ): array {
		$attachment = (int) get_post_thumbnail_id( $post_id );
		$results    = array();

		if ( $attachment < 1 || ! $this->plugin->make( ImageCredits::class )->is_image( $attachment ) ) {
			return $results;
		}

		$original = $this->original( $attachment );

		if ( null === $original ) {
			return $results;
		}

		$on = array();

		foreach ( $this->plugin->registry()->enabled() as $channel ) {
			$on[ $channel->id() ] = true;
		}

		foreach ( $this->definitions() as $id => $preset ) {
			if ( self::WIDTH === $preset['kind'] || ! isset( $on[ $id ] ) ) {
				continue;
			}

			$plan = $this->plan( $original, $preset, $this->focal( $attachment ) );

			if ( ! $plan['copy'] || null !== $this->copy_of( $attachment, $id ) ) {
				continue;
			}

			$results[ $id ] = $this->make( $attachment, $id );
		}

		return $results;
	}

	/**
	 * Removes the copies of a photo and forgets them.
	 *
	 * @param int $attachment Attachment ID.
	 * @return void
	 */
	public function forget( int $attachment ): void {
		$all     = get_post_meta( $attachment, self::COPIES, true );
		$removed = array();

		foreach ( is_array( $all ) ? $all : array() as $id => $entry ) {
			$copy = $this->copy_of( $attachment, (string) $id );

			// The address is read before the file goes; a copy whose file is already gone was still stored somewhere.
			$removed[ (string) $id ] = null !== $copy ? $copy['url'] : $this->entry_url( $attachment, is_array( $entry ) ? $entry : array() );

			if ( null !== $copy && is_file( $copy['path'] ) ) {
				wp_delete_file( $copy['path'] );
			}
		}

		delete_post_meta( $attachment, self::COPIES );

		if ( is_array( $all ) && array() !== $all ) {
			// A cached feed would still name a file that was just removed.
			$this->plugin->cache()->flush();
		}

		foreach ( $removed as $preset => $url ) {
			if ( '' !== $url ) {
				$this->plugin->make( CachePurge::class )->on_image_copy( $attachment, $preset, array( $url ) );
			}
		}
	}

	/**
	 * The address a stored copy has, without asking whether its file is still there.
	 *
	 * @param int                  $attachment Attachment ID.
	 * @param array<string, mixed> $entry      One entry of the photo's copies.
	 * @return string An empty string when the photo has no address.
	 */
	private function entry_url( int $attachment, array $entry ): string {
		$url = wp_get_attachment_url( $attachment );

		if ( ! is_string( $url ) || empty( $entry['file'] ) ) {
			return '';
		}

		return trailingslashit( dirname( $url ) ) . rawurlencode( wp_basename( (string) $entry['file'] ) );
	}

	/**
	 * Asks for the copies of an article to be made in the background.
	 *
	 * @param int $post_id Article ID.
	 * @return void
	 */
	public function queue( int $post_id ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			Queue::once( 0, self::HOOK, array( $post_id ), 'estasoft_nd' );

			return;
		}

		wp_schedule_single_event( time() + 5, self::HOOK, array( $post_id ) );
	}
}
