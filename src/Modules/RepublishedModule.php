<?php
/**
 * Republished content: the page tags, what the channels leave out, and the editor panel.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Channels\ChannelInterface;
use Estasoft\NewsDistribution\Content\Republished;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * For an article that is republished content (see `Content\Republished`):
 *
 * - The page. By the chosen handling it prints a noindex tag (`wp_robots`), a Googlebot-News
 *   noindex tag, or a canonical link to the original (`get_canonical_url`). It asks Yoast SEO,
 *   Rank Math and All in One SEO, through the filters they documented, to say the same, so
 *   two plugins do not disagree. Those hook names were not checked against each current release.
 * - The channels. Pulled feeds leave the article out (the internal filter
 *   `estasoft_nd_internal_feed_articles`) and pushed channels skip it (the Dispatcher's hold
 *   question), by the switches in Settings, Republished content. A takedown is never held.
 * - The editor. The block editor script that adds the "source" section to the News Distribution
 *   panel, the meta that carries its choices, and the settings screen's files.
 *
 * Nothing here calls another server.
 */
final class RepublishedModule implements ModuleInterface {

	private const META_KEYS = array( Republished::META, '_estasoft_nd_options' );

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'init', array( $this, 'register_meta' ), 20 );
		add_filter( 'wp_robots', array( $this, 'robots' ), 30 );
		add_action( 'wp_head', array( $this, 'news_tag' ), 1 );
		add_filter( 'get_canonical_url', array( $this, 'canonical' ), 20, 2 );
		add_filter( 'wpseo_canonical', array( $this, 'canonical_of_page' ), 20 );
		add_filter( 'rank_math/frontend/canonical', array( $this, 'canonical_of_page' ), 20 );
		add_filter( 'aioseo_canonical_url', array( $this, 'canonical_of_page' ), 20 );
		add_filter( 'wpseo_robots_array', array( $this, 'robots_list' ), 20 );
		add_filter( 'rank_math/frontend/robots', array( $this, 'robots_list' ), 20 );
		add_filter( 'estasoft_nd_internal_feed_articles', array( $this, 'feed_articles' ), 20, 3 );
		add_filter( 'estasoft_nd_internal_delivery_hold', array( $this, 'hold' ), 20, 4 );
		add_action( 'added_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'meta_changed' ), 10, 3 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'settings_assets' ), 20 );
	}

	/**
	 * Registers the meta that holds where an article came from, for the block editor.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$access = $this->plugin->access();

		foreach ( $this->plugin->settings()->post_types() as $type ) {
			register_post_meta(
				$type,
				Republished::META,
				array(
					'type'              => 'object',
					'single'            => true,
					'default'           => array(
						'source'   => '',
						'name'     => '',
						'url'      => '',
						'handling' => '',
					),
					'show_in_rest'      => array(
						'schema' => array(
							'type'                 => 'object',
							'properties'           => array(
								'source'   => array( 'type' => 'string' ),
								'name'     => array( 'type' => 'string' ),
								'url'      => array( 'type' => 'string' ),
								'handling' => array(
									'type' => 'string',
									'enum' => array_merge( array( '' ), Republished::HANDLINGS ),
								),
							),
							'additionalProperties' => false,
						),
					),
					'sanitize_callback' => array( Republished::class, 'clean_details' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id, $user_id = 0 ) use ( $access ): bool {
						return $access->can_edit_article( (int) $post_id, (int) $user_id );
					},
				)
			);
		}
	}

	/**
	 * Drops the feeds' saved copies when what decides an article's handling changes.
	 *
	 * @param mixed $meta_id  Meta row id, or a list of ids.
	 * @param mixed $post_id  Post ID.
	 * @param mixed $meta_key Meta key.
	 * @return void
	 */
	public function meta_changed( $meta_id, $post_id = 0, $meta_key = '' ): void {
		if ( in_array( (string) $meta_key, self::META_KEYS, true ) ) {
			$this->plugin->cache()->flush();
		}
	}

	/**
	 * The policy of the article being shown, or null when the page is not one.
	 *
	 * @return array<string, mixed>|null
	 */
	private function page_policy(): ?array {
		if ( is_admin() || ! is_singular( $this->plugin->settings()->post_types() ) ) {
			return null;
		}

		$id = (int) get_queried_object_id();

		return $id > 0 ? $this->content()->policy( $id ) : null;
	}

	/**
	 * The service.
	 *
	 * @return Republished
	 */
	private function content(): Republished {
		return $this->plugin->make( Republished::class );
	}

	/**
	 * Adds noindex to the page's robots meta tag when the copy is kept out of search.
	 *
	 * @param mixed $robots The directives, as WordPress builds them.
	 * @return mixed
	 */
	public function robots( $robots ) {
		$policy = $this->page_policy();

		if ( null === $policy || Republished::NOINDEX !== $policy['handling'] || ! is_array( $robots ) ) {
			return $robots;
		}

		unset( $robots['index'], $robots['max-image-preview'] );
		$robots['noindex'] = true;
		$robots['follow']  = true;

		return $robots;
	}

	/**
	 * Does the same for SEO plugins that build the robots tag themselves.
	 *
	 * @param mixed $robots Their directives.
	 * @return mixed
	 */
	public function robots_list( $robots ) {
		$policy = $this->page_policy();

		if ( null === $policy || Republished::NOINDEX !== $policy['handling'] || ! is_array( $robots ) ) {
			return $robots;
		}

		$robots['index'] = 'noindex';

		return $robots;
	}

	/**
	 * Prints the Googlebot-News tag that keeps a copy out of Google News only.
	 *
	 * @return void
	 */
	public function news_tag(): void {
		$policy = $this->page_policy();

		if ( null === $policy || Republished::NEWS_NOINDEX !== $policy['handling'] ) {
			return;
		}

		echo '<meta name="Googlebot-News" content="noindex, nofollow" />' . "\n";
	}

	/**
	 * Points a copy's canonical link at the original, when the handling says so.
	 *
	 * @param mixed $canonical The address WordPress would print.
	 * @param mixed $post      The post.
	 * @return mixed
	 */
	public function canonical( $canonical, $post = null ) {
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return $canonical;
		}

		$policy = $this->content()->policy( (int) $post->ID );

		if ( null === $policy || Republished::CANONICAL !== $policy['handling'] || '' === $policy['url'] ) {
			return $canonical;
		}

		return $policy['url'];
	}

	/**
	 * The same for an SEO plugin's canonical filter, for the page being shown.
	 *
	 * @param mixed $canonical The address the plugin would print.
	 * @return mixed
	 */
	public function canonical_of_page( $canonical ) {
		$policy = $this->page_policy();

		if ( null === $policy || Republished::CANONICAL !== $policy['handling'] || '' === $policy['url'] ) {
			return $canonical;
		}

		return $policy['url'];
	}

	/**
	 * Takes republished articles out of a pulled channel's document.
	 *
	 * @param mixed $articles The articles.
	 * @param mixed $channel  The channel.
	 * @return mixed
	 */
	public function feed_articles( $articles, $channel = null ) {
		if ( ! is_array( $articles ) || ! $channel instanceof ChannelInterface ) {
			return $articles;
		}

		$content = $this->content();
		$kept    = array();

		foreach ( $articles as $article ) {
			if ( $article instanceof Article ) {
				$policy = $content->policy( $article->id() );

				if ( null !== $policy && '' !== $content->skip_reason( $policy, $channel ) ) {
					continue;
				}
			}

			$kept[] = $article;
		}

		return $kept;
	}

	/**
	 * Skips a push for a republished article.
	 *
	 * @param mixed $hold    An answer given earlier, or null.
	 * @param mixed $channel The channel.
	 * @param mixed $event   The event.
	 * @return mixed
	 */
	public function hold( $hold, $channel = null, $event = null ) {
		if ( null !== $hold || ! $channel instanceof ChannelInterface || ! $event instanceof Event || $event->removes_content() ) {
			return $hold;
		}

		if ( ChannelInterface::MODE_PUSHED !== $channel->mode() ) {
			return $hold;
		}

		$content = $this->content();
		$policy  = $content->policy( $event->post_id() );

		if ( null === $policy ) {
			return $hold;
		}

		$reason = $content->skip_reason( $policy, $channel );

		if ( '' === $reason ) {
			return $hold;
		}

		return array(
			'status'  => Status::SKIPPED,
			'message' => $reason,
		);
	}

	/**
	 * Loads the script that adds the source section to the editor panel.
	 *
	 * @return void
	 */
	public function editor_assets(): void {
		$post = get_post();

		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		if ( ! $this->plugin->access()->can( Access::VIEW_OWN, (int) $post->ID ) ) {
			return;
		}

		$settings = $this->content()->settings();
		$handle   = 'estasoft-nd-editor-republished';
		$sources  = array();

		foreach ( $this->content()->sources() as $source ) {
			$sources[] = array(
				'id'       => $source['id'],
				'name'     => $source['name'],
				'handling' => $source['handling'],
			);
		}

		$options = array();

		foreach ( Republished::handling_options() as $code => $option ) {
			$options[] = array(
				'value' => $code,
				'label' => $option['title'],
				'help'  => $option['editor_help'],
			);
		}

		wp_enqueue_script(
			$handle,
			ESTASOFT_ND_URL . 'assets/js/editor/republished.js',
			array( 'wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-core-data', 'wp-i18n', 'wp-api-fetch', 'wp-hooks' ),
			Assets::file_version( 'assets/js/editor/republished.js' ),
			array( 'in_footer' => true )
		);

		wp_set_script_translations( $handle, 'estasoft-news-distribution', ESTASOFT_ND_PATH . 'languages' );
		wp_enqueue_style( 'estasoft-nd-editor-content', ESTASOFT_ND_URL . 'assets/css/editor-content.css', array(), Assets::file_version( 'assets/css/editor-content.css' ) );

		wp_add_inline_script(
			$handle,
			'window.estasoftNDRepublished = ' . wp_json_encode(
				array(
					'enabled'         => ! empty( $settings['enabled'] ),
					'postTypes'       => array_values( $this->plugin->settings()->post_types() ),
					'sources'         => $sources,
					'handlings'       => $options,
					'defaultHandling' => $this->content()->default_handling(),
					'previewPath'     => 'articles/%d/republished-preview',
					'settingsUrl'     => admin_url( 'admin.php?page=estasoft-nd-settings&section=republished' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Loads the settings screen's files.
	 *
	 * @return void
	 */
	public function settings_assets(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );

		if ( 'estasoft-nd-settings' === $page && 'republished' === $section ) {
			Assets::screen_style( 'content-features' );
			Assets::screen_script( 'content-features' );
		}
	}
}
