<?php
/**
 * The AI assistant's hooks.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Channels\LocalNewsTab;
use Estasoft\NewsDistribution\Ai\Channels\PlatformHelpTab;
use Estasoft\NewsDistribution\Ai\Client;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Consent;
use Estasoft\NewsDistribution\Ai\Features\Digest;
use Estasoft\NewsDistribution\Ai\Features\Fix;
use Estasoft\NewsDistribution\Ai\Features\LocalNews;
use Estasoft\NewsDistribution\Ai\Features\Summary;
use Estasoft\NewsDistribution\Ai\Features\Translate;
use Estasoft\NewsDistribution\Ai\Marker;
use Estasoft\NewsDistribution\Ai\View\ConsentView;
use Estasoft\NewsDistribution\Ai\View\DigestView;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Ai\View\SettingsView;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Everything here is quiet until a person has turned the assistant on and confirmed what is sent:
 *
 * - the guard that stops a request whose provider was not confirmed (WordPress's `wp_ai_client_before_generate_result`);
 * - the answer to "may the assistant run for this article", which is the one thing `Core\Access` asks;
 * - the two form posts of the settings page, Send a test request and the confirmation;
 * - the keeping of the AI-assisted mark, and the rule that an AI translation draft is not published before it is approved;
 * - the title and the summary a person chose to use in feeds only, the News Break local-news rule and its background run;
 * - the opening paragraph of the weekly summary, while the queue sends it.
 *
 * Nothing here sends text to a provider. Requests are made only by `Client::ask()`, from a REST route a person
 * called, from the background run the owner turned on, or from the Monday job. A publish only puts an article in the
 * local-news waiting list.
 */
final class AiModule implements ModuleInterface {

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_filter( 'estasoft_nd_internal_ai_available', array( $this, 'available' ) );
		add_action( 'wp_ai_client_before_generate_result', array( Client::class, 'guard' ) );

		add_action( 'updated_post_meta', array( $this, 'watch' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'watch' ), 10, 4 );
		add_filter( 'wp_insert_post_data', array( $this, 'hold_drafts' ), 10, 2 );
		add_filter( 'estasoft_nd_internal_article_title', array( $this, 'article_title' ), 10, 2 );
		add_filter( 'estasoft_nd_internal_article_summary', array( $this, 'article_summary' ), 10, 2 );

		add_filter( 'estasoft_nd_news_break_local', array( $this, 'local' ), 10, 2 );
		add_action( LocalNews::HOOK, array( $this, 'local_batch' ) );
		add_action( 'transition_post_status', array( $this, 'published' ), 10, 3 );

		add_filter( 'estasoft_nd_internal_digest_model', array( $this, 'digest' ), 10, 2 );
		add_filter( 'estasoft_nd_internal_alerts_tab', array( $this, 'weekly_tab' ), 10, 2 );
		add_filter( 'estasoft_nd_internal_channel_tabs', array( $this, 'channel_tabs' ), 10, 2 );
		add_filter( 'estasoft_nd_internal_network_ai_providers', array( $this, 'network_providers' ) );

		if ( is_admin() ) {
			add_action( 'admin_post_' . SettingsView::TEST_ACTION, array( $this, 'test' ) );
			add_action( 'admin_post_' . ConsentView::ACTION, array( $this, 'consent' ) );
			add_action( 'admin_init', array( $this, 'weekly_handle' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		}
	}

	/**
	 * Whether the assistant may run: WordPress 7.0 or newer with AI switched on, a provider connected and
	 * confirmed, and the owner's switch on. Tabs, buttons and routes ask this and are absent when it says no.
	 *
	 * @param mixed $available What was decided before; false.
	 * @return bool
	 */
	public function available( $available = false ): bool {
		unset( $available );

		return $this->plugin->make( Availability::class )->usable();
	}

	/**
	 * Keeps the AI-assisted mark honest when a person changes the content label.
	 *
	 * @param mixed $meta_id    Meta row.
	 * @param mixed $post_id    Post.
	 * @param mixed $meta_key   Meta key.
	 * @param mixed $meta_value New value.
	 * @return void
	 */
	public function watch( $meta_id, $post_id, $meta_key, $meta_value ): void {
		$this->plugin->make( Marker::class )->watch( $meta_id, $post_id, $meta_key, $meta_value );
	}

	/**
	 * Keeps an AI translation draft a draft until it is approved.
	 *
	 * @param mixed $data    The data about to be saved.
	 * @param mixed $postarr The arguments given.
	 * @return mixed
	 */
	public function hold_drafts( $data, $postarr = array() ) {
		return $this->plugin->make( Translate::class )->guard( $data, $postarr );
	}

	/**
	 * The title feeds print: the one a person chose to use in feeds only, or the article's own.
	 *
	 * @param mixed $title The title.
	 * @param mixed $post  The post.
	 * @return mixed
	 */
	public function article_title( $title, $post = null ) {
		if ( ! $post instanceof \WP_Post || ! is_string( $title ) ) {
			return $title;
		}

		$feed = Fix::feed_title( (int) $post->ID );

		return '' === $feed ? $title : $feed;
	}

	/**
	 * The summary feeds print: the one a person chose to use in feeds only, or nothing, so each channel keeps its own way of writing one.
	 *
	 * @param mixed $summary The summary so far.
	 * @param mixed $post    The post.
	 * @return mixed
	 */
	public function article_summary( $summary, $post = null ) {
		if ( ! $post instanceof \WP_Post || ! is_string( $summary ) ) {
			return $summary;
		}

		$feed = Summary::feed_summary( (int) $post->ID );

		return '' === $feed ? $summary : $feed;
	}

	/**
	 * News Break's local-news rule.
	 *
	 * @param mixed $local   What is known so far.
	 * @param mixed $article The article.
	 * @return mixed
	 */
	public function local( $local, $article = null ) {
		return $this->plugin->make( LocalNews::class )->filter( $local, $article );
	}

	/**
	 * The background run of the local-news rule.
	 *
	 * @return void
	 */
	public function local_batch(): void {
		$this->plugin->make( LocalNews::class )->run_batch();
	}

	/**
	 * Puts a newly published article in the local-news waiting list. Nothing is sent to a provider here.
	 *
	 * @param mixed $status New status.
	 * @param mixed $old    Old status.
	 * @param mixed $post   The post.
	 * @return void
	 */
	public function published( $status, $old, $post = null ): void {
		if ( 'publish' !== $status || 'publish' === $old || ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->plugin->settings()->post_types(), true ) ) {
			return;
		}

		$local = $this->plugin->make( LocalNews::class );

		if ( $local->active() ) {
			$local->enqueue( array( (int) $post->ID ) );
		}
	}

	/**
	 * The paragraph at the top of the weekly summary, while the queue sends it.
	 *
	 * @param mixed $model  What the email prints.
	 * @param mixed $digest The figures behind it.
	 * @return mixed
	 */
	public function digest( $model, $digest = array() ) {
		return $this->plugin->make( Digest::class )->filter( $model, $digest );
	}

	/**
	 * The weekly summary with the AI wording explained, `&section=alerts&tab=weekly-ai`.
	 *
	 * @param mixed  $page The page so far.
	 * @param string $tab  The `tab` argument.
	 * @return mixed
	 */
	public function weekly_tab( $page, $tab = '' ) {
		if ( 'weekly-ai' !== $tab || ! $this->plugin->make( Availability::class )->usable() ) {
			return $page;
		}

		$this->plugin->access()->require_or_die( Access::MANAGE );

		return ( new DigestView( $this->plugin ) )->page();
	}

	/**
	 * The form of the weekly summary tab (Make a preview paragraph). It runs before any output so it can send the person back.
	 *
	 * @return void
	 */
	public function weekly_handle(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );
		$tab     = Query::key( 'tab' );

		if ( 'estasoft-nd-settings' !== $page || 'alerts' !== $section || 'weekly-ai' !== $tab ) {
			return;
		}

		if ( ! Runner::posted( DigestView::KEY ) ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::MANAGE );
		$this->plugin->make( Runner::class )->handle( DigestView::KEY, DigestView::url() );
	}

	/**
	 * The tabs the assistant adds to a channel page: SmartNews helper and News Break local news. Absent unless usable.
	 *
	 * @param mixed  $tabs       Tabs added so far.
	 * @param string $channel_id The channel.
	 * @return mixed
	 */
	public function channel_tabs( $tabs, $channel_id = '' ) {
		if ( ! is_array( $tabs ) || ! $this->plugin->make( Availability::class )->usable() ) {
			return $tabs;
		}

		if ( 'smartnews' === $channel_id ) {
			$tabs[] = new PlatformHelpTab( $this->plugin );
		} elseif ( 'news-break' === $channel_id && $this->plugin->make( Config::class )->feature_on( 'local-news' ) ) {
			$tabs[] = new LocalNewsTab( $this->plugin );
		}

		return $tabs;
	}

	/**
	 * Lets a network allow any provider this site's WordPress has connectors for.
	 *
	 * @param mixed $providers Provider id => label.
	 * @return mixed
	 */
	public function network_providers( $providers ) {
		if ( ! is_array( $providers ) ) {
			return $providers;
		}

		foreach ( $this->plugin->make( Availability::class )->connectors() as $provider ) {
			if ( ! isset( $providers[ $provider['id'] ] ) ) {
				$providers[ $provider['id'] ] = $provider['name'];
			}
		}

		return $providers;
	}

	/**
	 * Send a test request, from the settings form. The nonce is the one of the form the button sits in.
	 *
	 * @return void
	 */
	public function test(): void {
		check_admin_referer( 'estasoft_nd_save_ai' );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		$result = $this->plugin->make( Client::class )->test();

		if ( is_wp_error( $result ) ) {
			Flash::set( 'warning', $result->get_error_message() );
		} else {
			Flash::set( 'success', (string) $result['message'] );
		}

		wp_safe_redirect( Links::settings( 'ai' ) . '#estasoft-nd-card-ai-connection' );
		exit;
	}

	/**
	 * The confirmation dialog: records consent and turns the assistant on.
	 *
	 * @return void
	 */
	public function consent(): void {
		check_admin_referer( ConsentView::ACTION );
		$this->plugin->access()->require_or_die( Access::ADMIN );

		if ( empty( $_POST['confirm'] ) ) {
			wp_safe_redirect(
				Links::settings(
					'ai',
					array(
						'dialog' => 'consent',
						'need'   => '1',
					)
				)
			);
			exit;
		}

		$done = $this->plugin->make( Consent::class )->grant( get_current_user_id() );

		if ( is_wp_error( $done ) ) {
			Flash::set( 'warning', $done->get_error_message() );
		} else {
			Flash::set( 'success', __( 'Confirmed. The assistant is on. Nothing is sent until someone asks for a suggestion, and nothing is applied until a person accepts it.', 'estasoft-news-distribution' ) );
		}

		wp_safe_redirect( Links::settings( 'ai' ) );
		exit;
	}

	/**
	 * Loads the assistant's files on its settings page and on the weekly summary tab.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$page    = Query::key( 'page' );
		$section = Query::key( 'section' );
		$tab     = Query::key( 'tab' );

		if ( 'estasoft-nd-settings' !== $page || ! ( 'ai' === $section || ( 'alerts' === $section && 'weekly-ai' === $tab ) ) ) {
			return;
		}

		Assets::screen_style( 'ai' );
		Assets::screen_script(
			'ai',
			array(
				'text' => array(
					'working' => __( 'Working…', 'estasoft-news-distribution' ),
					'offline' => __( 'Could not reach your site. Check your connection and try again.', 'estasoft-news-distribution' ),
				),
			)
		);
	}
}
