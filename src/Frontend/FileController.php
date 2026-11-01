<?php
/**
 * Serves the small files platforms ask for at the site root.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The IndexNow key file and site verification files are answered from rewrite
 * rules, so no file is written to the site root. A module that owns a file name
 * answers the `estasoft_nd_internal_serve_file` filter; when nothing does, the
 * address is an ordinary WordPress 404.
 */
final class FileController {

	private Plugin $plugin;

	/**
	 * Creates the controller.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Answers the request when a module owns the file name.
	 *
	 * @param string $name File name from the address.
	 * @return void
	 */
	public function serve( string $name ): void {
		$response = $this->response_for( $name );

		if ( null !== $response ) {
			$response->send();
		}
	}

	/**
	 * The answer for a file name, or null when no module owns it.
	 *
	 * @param string $name File name from the address.
	 * @return Response|null
	 */
	public function response_for( string $name ): ?Response {
		$name = sanitize_file_name( $name );

		/**
		 * Supplies the content of a root-level file.
		 *
		 * Return array( 'body' => string, 'type' => string ) when this module owns
		 * the file name, or the value you were given when it does not.
		 *
		 * @param array|null $file Null, or the answer from an earlier module.
		 * @param string     $name File name, such as "abc123.txt".
		 */
		$file = apply_filters( 'estasoft_nd_internal_serve_file', null, $name );

		if ( ! is_array( $file ) || ! isset( $file['body'] ) ) {
			return null;
		}

		return new Response(
			200,
			array(
				'Content-Type'            => isset( $file['type'] ) ? (string) $file['type'] : 'text/plain; charset=UTF-8',
				'X-Robots-Tag'            => 'noindex',
				'Cache-Control'           => 'public, max-age=300',
				// A key or a verification file is read by a crawler, not shown in a page: nothing in it may run on this site's address.
				'Content-Security-Policy' => "default-src 'none'; sandbox",
			),
			(string) $file['body']
		);
	}
}
