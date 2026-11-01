<?php
/**
 * Shared parts of the caching plugin adapters.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Integrations\CachePlugins;

defined( 'ABSPATH' ) || exit;

/**
 * Looks a function up before calling it, catches whatever the plugin throws,
 * and reports the same result shape for every plugin.
 *
 * Tests give the constructor `array( 'function_name' => callable )` to stand
 * in for the plugin's own functions, so no fake function is ever declared
 * globally.
 */
abstract class AbstractCachePlugin implements CachePluginInterface {

	/**
	 * Stand-ins for the plugin's functions, by function name. Empty in production.
	 *
	 * @var array<string, callable>
	 */
	private array $stand_ins;

	/**
	 * Creates the adapter.
	 *
	 * @param array<string, callable> $stand_ins Stand-ins for the plugin's functions. For tests.
	 */
	public function __construct( array $stand_ins = array() ) {
		$this->stand_ins = $stand_ins;
	}

	/**
	 * Whether a function of the plugin can be called.
	 *
	 * @param string $name Function name.
	 * @return bool
	 */
	protected function has_function( string $name ): bool {
		return isset( $this->stand_ins[ $name ] ) || function_exists( $name );
	}

	/**
	 * Calls a function of the plugin.
	 *
	 * @param string $name Function name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed What the function returned.
	 */
	protected function call_function( string $name, ...$args ) {
		$target = $this->stand_ins[ $name ] ?? $name;

		return $target( ...$args );
	}

	/**
	 * The result of a call that went through.
	 *
	 * @param int $asked How many addresses the plugin was asked about.
	 * @return array{ok: bool, asked: int, message: string}
	 */
	protected function done( int $asked ): array {
		return array(
			'ok'      => true,
			'asked'   => $asked,
			'message' => __( 'It answered without an error.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The result of a call that did not go through.
	 *
	 * @param int    $asked   How many addresses the plugin was asked about.
	 * @param string $message What went wrong, in a sentence.
	 * @return array{ok: bool, asked: int, message: string}
	 */
	protected function failed( int $asked, string $message ): array {
		return array(
			'ok'      => false,
			'asked'   => $asked,
			'message' => $message,
		);
	}

	/**
	 * The result for a plugin that is not installed.
	 *
	 * @return array{ok: bool, asked: int, message: string}
	 */
	protected function absent(): array {
		return $this->failed( 0, __( 'The plugin is not installed.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The result for an error thrown by the plugin.
	 *
	 * @param int        $asked   How many addresses the plugin was asked about.
	 * @param \Throwable $error   What was thrown.
	 * @return array{ok: bool, asked: int, message: string}
	 */
	protected function threw( int $asked, \Throwable $error ): array {
		return $this->failed(
			$asked,
			sprintf(
				/* translators: 1: caching plugin name, 2: the error text. */
				__( '%1$s returned an error: %2$s', 'estasoft-news-distribution' ),
				$this->label(),
				$error->getMessage()
			)
		);
	}

	/**
	 * Drops addresses one at a time through a function that takes one address.
	 *
	 * @param string   $name Function name.
	 * @param string[] $urls     Addresses.
	 * @return array{ok: bool, asked: int, message: string}
	 */
	protected function purge_each( string $name, array $urls ): array {
		if ( ! $this->has_function( $name ) ) {
			return $this->absent();
		}

		$asked = 0;

		try {
			foreach ( $urls as $url ) {
				$this->call_function( $name, $url );
				++$asked;
			}
		} catch ( \Throwable $error ) {
			return $this->threw( $asked, $error );
		}

		return $this->done( $asked );
	}
}
