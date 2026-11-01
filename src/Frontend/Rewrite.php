<?php
/**
 * Public addresses: feeds, sitemaps and files the platforms ask for.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Frontend;

use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Feeds are served from rewrite rules, not files, so nothing is written to the
 * site root. The controller answers during `parse_request`, before WordPress
 * builds the main query, which keeps a feed request cheap.
 *
 * Root-level files (the IndexNow key, a search engine's verification file) have no rewrite rule: a rule for "any
 * name that ends in .txt or .html" would also catch a post on a site whose permalinks end in .html, and would turn
 * every missing file of that kind into the home page. `route()` looks at the address itself and answers only when a
 * module owns the name.
 */
final class Rewrite {

	public const VAR_FEED    = 'estasoft_nd_feed';
	public const VAR_PARTNER = 'estasoft_nd_partner';

	private Plugin $plugin;

	/**
	 * Creates the router.
	 *
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Hooks the rules and the controllers.
	 *
	 * @return void
	 */
	public function register(): void {
		// Late, so the feeds that other plugins add on `init` are known when the pattern leaves their names alone.
		add_action( 'init', array( self::class, 'add_rules' ), 98 );
		add_action( 'init', array( $this, 'flush_if_dirty' ), 99 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'route' ), 0 );
	}

	/**
	 * Adds the rewrite rules. Also called on activation, before the rules are flushed.
	 *
	 * @return void
	 */
	public static function add_rules(): void {
		add_rewrite_rule( self::feed_pattern(), 'index.php?' . self::VAR_FEED . '=$matches[1]', 'top' );
		add_rewrite_rule( '^partner-feed/([a-z0-9-]+)/?$', 'index.php?' . self::VAR_PARTNER . '=$matches[1]', 'top' );
	}

	/**
	 * The pattern of a feed address, `feed/{slug}`, without the names WordPress (or another plugin) already gives a feed.
	 *
	 * The rule is added at the top, so it would answer `/feed/atom/` and `/feed/rss2/` before WordPress could, and the
	 * answer would be a 404.
	 *
	 * @return string
	 */
	public static function feed_pattern(): string {
		global $wp_rewrite;

		$taken = array( 'feed', 'rdf', 'rss', 'rss2', 'atom' );

		if ( $wp_rewrite instanceof \WP_Rewrite && is_array( $wp_rewrite->feeds ) ) {
			$taken = array_merge( $taken, $wp_rewrite->feeds );
		}

		$taken = array_map(
			static function ( $name ): string {
				return preg_quote( (string) $name, '#' );
			},
			array_unique( $taken )
		);

		return '^feed/(?!(?:' . implode( '|', $taken ) . ')/?$)([a-z0-9-]+)/?$';
	}

	/**
	 * The file name in an address such as `/abc12345.txt`, or an empty string when the address is not one.
	 *
	 * @param string $request The address without the site's own path, as WordPress gives it (`$wp->request`).
	 * @return string
	 */
	public static function file_name( string $request ): string {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{8,128}\.(?:txt|html)$/', $request ) ? $request : '';
	}

	/**
	 * Registers the query variables.
	 *
	 * @param string[] $vars Query variables.
	 * @return string[]
	 */
	public function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::VAR_FEED;
		$vars[] = self::VAR_PARTNER;

		return $vars;
	}

	/**
	 * Flushes the rules once after an update that changed them.
	 *
	 * @return void
	 */
	public function flush_if_dirty(): void {
		if ( get_option( 'estasoft_nd_rewrite_dirty' ) ) {
			delete_option( 'estasoft_nd_rewrite_dirty' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Sends a matching request to the right controller.
	 *
	 * @param \WP $wp The WordPress request object.
	 * @return void
	 */
	public function route( $wp ): void {
		if ( ! $wp instanceof \WP ) {
			return;
		}

		$vars = $wp->query_vars;

		if ( ! empty( $vars[ self::VAR_FEED ] ) ) {
			( new FeedController( $this->plugin ) )->serve( sanitize_key( (string) $vars[ self::VAR_FEED ] ) );
			return;
		}

		if ( ! empty( $vars[ self::VAR_PARTNER ] ) ) {
			/**
			 * Serves a partner feed. The partner module listens and answers; if nothing does, the address is a 404.
			 *
			 * @param string $slug Partner slug from the address.
			 */
			do_action( 'estasoft_nd_internal_serve_partner_feed', sanitize_key( (string) $vars[ self::VAR_PARTNER ] ) );
			FeedController::not_found();
			return;
		}

		// Nothing answers unless a module owns the name, so any other address goes on as WordPress would have handled it.
		$file = self::file_name( isset( $wp->request ) ? (string) $wp->request : '' );

		if ( '' !== $file ) {
			( new FileController( $this->plugin ) )->serve( $file );
		}
	}
}
