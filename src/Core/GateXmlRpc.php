<?php
/**
 * Tells an XML-RPC client the truth when the gate kept its article from going live.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The gate keeps an article that fails from going live in `wp_insert_post_data`, which is the one place every save
 * passes, XML-RPC included. But the methods of an XML-RPC server answer with `true` (an edit) or the new ID, so a
 * client that asked to publish would be told it worked. This stands in front of each method that can publish: it
 * runs the server's own method, and if the gate kept the article back it answers with an error that says so.
 *
 * It is installed through the `xmlrpc_methods` filter, which is how WordPress lets a plugin wrap a method. Each
 * method is reached by `__call()`, named as the client names it (`wp.editPost`), because the server only accepts an
 * object and a method name. A method another plugin already replaced is left as it is.
 */
final class GateXmlRpc {

	/**
	 * The methods that can make an article public.
	 */
	public const METHODS = array(
		'wp.newPost',
		'wp.editPost',
		'wp.newPage',
		'wp.editPage',
		'blogger.newPost',
		'blogger.editPost',
		'metaWeblog.newPost',
		'metaWeblog.editPost',
		'mt.publishPost',
	);

	/**
	 * The error code: the HTTP status the REST API answers the same refusal with.
	 */
	public const CODE = 409;

	private Plugin $plugin;

	/**
	 * The server's own method for each XML-RPC name.
	 *
	 * @var array<string, string>
	 */
	private array $targets = array();

	/**
	 * The server that answers, when it is not the global one (tests).
	 *
	 * @var object|null
	 */
	private ?object $server;

	/**
	 * Creates the service.
	 *
	 * @param Plugin      $plugin The running plugin.
	 * @param object|null $server The XML-RPC server, or null for WordPress's own (`$wp_xmlrpc_server`).
	 */
	public function __construct( Plugin $plugin, ?object $server = null ) {
		$this->plugin = $plugin;
		$this->server = $server;
	}

	/**
	 * `xmlrpc_methods`: puts this in front of each method that can publish.
	 *
	 * @param mixed $methods The server's methods: XML-RPC name => `this:method`.
	 * @return mixed
	 */
	public function wrap( $methods ) {
		if ( ! is_array( $methods ) ) {
			return $methods;
		}

		foreach ( self::METHODS as $name ) {
			if ( isset( $methods[ $name ] ) && is_string( $methods[ $name ] ) && 0 === strpos( $methods[ $name ], 'this:' ) ) {
				$this->targets[ $name ] = substr( $methods[ $name ], 5 );
				$methods[ $name ]       = array( $this, $name );
			}
		}

		return $methods;
	}

	/**
	 * Runs the server's method and changes the answer if the gate kept the article back.
	 *
	 * @param string            $name      The XML-RPC name the client called.
	 * @param array<int, mixed> $arguments One entry: what the server passes to the method.
	 * @return mixed What the method answered, or an `IXR_Error`.
	 */
	public function __call( string $name, array $arguments ) {
		$target = $this->targets[ $name ] ?? '';
		$server = $this->server ?? ( $GLOBALS['wp_xmlrpc_server'] ?? null );

		if ( '' === $target || ! is_object( $server ) || ! method_exists( $server, $target ) ) {
			return new \IXR_Error( -32601, 'server error. requested class method "' . $name . '" does not exist.' );
		}

		$gate = $this->plugin->make( Gate::class );
		$gate->forget_held();

		$result = $server->$target( $arguments[0] ?? array() );
		$held   = $gate->take_held();

		if ( null === $held || $result instanceof \IXR_Error || false === $result ) {
			return $result;
		}

		$can  = $gate->can_override( (int) $held['post_id'] );
		$text = $gate->refusal_text( $held['result'], $can );

		if ( true === $result ) {
			return new \IXR_Error(
				self::CODE,
				$text . ' ' . __( 'The other changes were saved, and the article is not published.', 'estasoft-news-distribution' )
			);
		}

		return new \IXR_Error(
			self::CODE,
			$text . ' ' . sprintf(
				/* translators: %s: the ID of the article, as the XML-RPC server answered it. */
				__( 'The article was saved without being published (ID %s). Do not send it again; change it, or publish anyway with a reason.', 'estasoft-news-distribution' ),
				is_scalar( $result ) ? (string) $result : ''
			)
		);
	}
}
