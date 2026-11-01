<?php
/**
 * Serves the IndexNow key file and makes the key.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Channels\IndexNow\IndexNowChannel;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * IndexNow checks that a notice comes from the site's owner by fetching a text
 * file named after the key, at the root of the site, that contains only the key.
 *
 * - The file is answered from the plugin's root-file rewrite rule through the
 *   filter `estasoft_nd_internal_serve_file`. No file is written to the site root.
 * - It is answered only while IndexNow is on, and only for the stored key. The old
 *   key's file answers 404 the moment the key is replaced.
 * - The key is made when IndexNow is turned on and has none: 32 random letters and
 *   digits. A person can replace it on the IndexNow page.
 */
final class IndexNowKeyModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Set while the module saves the key, so the save does not start another.
	 *
	 * @var bool
	 */
	private bool $busy = false;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'estasoft_nd_internal_serve_file', array( $this, 'serve_file' ), 10, 2 );
		add_action( 'estasoft_nd_output_changed', array( $this, 'ensure_key' ) );
	}

	/**
	 * Answers the key file.
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
		$found   = null === $channel ? null : $channel->key_file();

		if ( null === $found || ! hash_equals( $found['body'] . '.txt', $name ) ) {
			return $file;
		}

		return $found;
	}

	/**
	 * Makes a key when IndexNow is on and has none.
	 *
	 * @param mixed $changed What changed, such as "channels".
	 * @return void
	 */
	public function ensure_key( $changed ): void {
		if ( 'channels' !== $changed || $this->busy ) {
			return;
		}

		$channel = $this->channel();

		if ( null === $channel || ! $channel->is_enabled() || '' !== $channel->key() ) {
			return;
		}

		$this->busy = true;
		$this->plugin->channel_settings()->save( $channel->id(), $channel->fields(), array( 'key' => IndexNowChannel::new_key() ) );
		$this->busy = false;
	}

	/**
	 * The IndexNow channel, when it is registered.
	 *
	 * @return IndexNowChannel|null
	 */
	private function channel(): ?IndexNowChannel {
		$channel = $this->plugin->registry()->get( 'indexnow' );

		return $channel instanceof IndexNowChannel ? $channel : null;
	}
}
