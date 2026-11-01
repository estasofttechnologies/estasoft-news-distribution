<?php
/**
 * Serves site verification tags and files, and saves the verification form.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Channels\VerificationScreen;
use Estasoft\NewsDistribution\Channels\Verification\VerificationChannel;
use Estasoft\NewsDistribution\Frontend\Response;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Search engines ask for proof that the site is the owner's: a meta tag in the
 * home page, or a file in the site root. This module prints the tags and
 * answers the files from what the person pasted on the Site verification page.
 * No file is ever written to the site root.
 *
 * Files ending in .html or .txt come through the plugin's root-file rewrite rule
 * and the `estasoft_nd_internal_serve_file` filter. Bing's `BingSiteAuth.xml` is
 * answered from `parse_request`, because the rewrite rule does not cover .xml and
 * a rule for every .xml address would take over `wp-sitemap.xml`.
 */
final class VerificationModule implements ModuleInterface {

	public const ACTION = 'estasoft_nd_save_verification';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'wp_head', array( $this, 'print_tags' ), 1 );
		add_filter( 'estasoft_nd_internal_serve_file', array( $this, 'serve_file' ), 10, 2 );
		add_action( 'parse_request', array( $this, 'serve_xml' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
	}

	/**
	 * Prints the meta tags in the head of the home page.
	 *
	 * @return void
	 */
	public function print_tags(): void {
		if ( ! is_front_page() ) {
			return;
		}

		$channel = $this->channel();

		if ( null === $channel ) {
			return;
		}

		foreach ( $channel->meta_tags() as $name => $content ) {
			printf( '<meta name="%1$s" content="%2$s" />' . "\n", esc_attr( $name ), esc_attr( $content ) );
		}
	}

	/**
	 * Answers a root-level .html or .txt address that holds a verification file.
	 *
	 * @param mixed  $file The answer so far, or null.
	 * @param string $name The file name from the address.
	 * @return mixed
	 */
	public function serve_file( $file, string $name ) {
		if ( is_array( $file ) ) {
			return $file;
		}

		$channel = $this->channel();
		$found   = null === $channel ? null : $channel->file( $name );

		return null === $found ? $file : $found;
	}

	/**
	 * Answers `BingSiteAuth.xml` (any XML file name the person set) when it is a verification file.
	 *
	 * @param \WP $wp The request.
	 * @return void
	 */
	public function serve_xml( $wp ): void {
		$path = isset( $wp->request ) ? (string) $wp->request : '';

		if ( '' === $path || '.xml' !== strtolower( substr( $path, -4 ) ) || false !== strpos( $path, '/' ) ) {
			return;
		}

		$channel = $this->channel();
		$found   = null === $channel ? null : $channel->file( $path );

		if ( null === $found ) {
			return;
		}

		( new Response(
			200,
			array(
				'Content-Type'  => $found['type'],
				'X-Robots-Tag'  => 'noindex',
				'Cache-Control' => 'public, max-age=300',
			),
			$found['body']
		) )->send();
	}

	/**
	 * Saves one engine's form from the Site verification page, then returns to it.
	 *
	 * @return void
	 */
	public function save(): void {
		$channel = $this->channel();
		$result  = null === $channel ? array(
			'saved'  => false,
			'engine' => '',
		) : ( new VerificationScreen( $this->plugin, $channel ) )->save();
		$url     = add_query_arg(
			array(
				'page'                               => 'estasoft-nd-channels',
				'channel'                            => 'verification',
				'engine'                             => $result['engine'],
				$result['saved'] ? 'saved' : 'error' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url . '#estasoft-nd-verify-form' );
		exit;
	}

	/**
	 * The verification channel, or null when it is not loaded.
	 *
	 * @return VerificationChannel|null
	 */
	private function channel(): ?VerificationChannel {
		$channel = $this->plugin->registry()->get( 'verification' );

		return $channel instanceof VerificationChannel ? $channel : null;
	}
}
