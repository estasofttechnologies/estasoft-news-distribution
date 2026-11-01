<?php
/**
 * Plugin bootstrap and service wiring.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution;

use Estasoft\NewsDistribution\Channels\ChannelContext;
use Estasoft\NewsDistribution\Channels\ChannelRegistry;
use Estasoft\NewsDistribution\Channels\ChannelSettings;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\ArticleId;
use Estasoft\NewsDistribution\Core\Audit;
use Estasoft\NewsDistribution\Core\Cache;
use Estasoft\NewsDistribution\Core\ContentReader;
use Estasoft\NewsDistribution\Core\DeliveryLog;
use Estasoft\NewsDistribution\Core\Dispatcher;
use Estasoft\NewsDistribution\Core\EventListener;
use Estasoft\NewsDistribution\Core\FetchLog;
use Estasoft\NewsDistribution\Core\Jobs;
use Estasoft\NewsDistribution\Core\Language\LanguageResolverFactory;
use Estasoft\NewsDistribution\Core\Language\LanguageResolverInterface;
use Estasoft\NewsDistribution\Core\Maintenance;
use Estasoft\NewsDistribution\Core\Queue;
use Estasoft\NewsDistribution\Core\Rules;
use Estasoft\NewsDistribution\Core\Secrets;
use Estasoft\NewsDistribution\Core\Settings;
use Estasoft\NewsDistribution\Support\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and connects them to WordPress. Screens, routes and
 * channels receive what they need from here; nothing else keeps global state.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	private Settings $settings;
	private Access $access;
	private Secrets $secrets;
	private Cache $cache;
	private DeliveryLog $log;
	private FetchLog $fetch_log;
	private Audit $audit;
	private Queue $queue;
	private LanguageResolverInterface $languages;
	private ContentReader $reader;
	private ChannelSettings $channel_settings;
	private Applications $applications;
	private Rules $rules;
	private ArticleId $article_ids;
	private ChannelRegistry $registry;
	private Jobs $jobs;

	/**
	 * Services made on first use by make().
	 *
	 * @var array<string, object>
	 */
	private array $made = array();
	private ChannelContext $context;

	/**
	 * Starts the plugin. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init();
		}
	}

	/**
	 * Returns the running plugin, starting it if needed.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		self::boot();
		return self::$instance;
	}

	/**
	 * Builds the services. Nothing is hooked into WordPress yet, and nothing
	 * reads the database.
	 */
	private function __construct() {
		$this->settings         = new Settings();
		$this->access           = new Access( $this->settings );
		$this->secrets          = new Secrets();
		$this->cache            = new Cache();
		$this->log              = new DeliveryLog();
		$this->fetch_log        = new FetchLog();
		$this->audit            = new Audit();
		$this->queue            = new Queue();
		$this->languages        = LanguageResolverFactory::detect();
		$this->reader           = new ContentReader( $this->languages, $this->settings );
		$this->channel_settings = new ChannelSettings();
		$this->applications     = new Applications();
		$this->rules            = new Rules();
		$this->article_ids      = new ArticleId();
		$this->registry         = new ChannelRegistry();
		$this->jobs             = new Jobs();
		$this->context          = new ChannelContext(
			$this->settings,
			$this->channel_settings,
			$this->secrets,
			$this->applications,
			$this->rules,
			$this->log,
			$this->fetch_log,
			$this->languages
		);
	}

	/**
	 * Connects the services to WordPress.
	 *
	 * @return void
	 */
	private function init(): void {
		add_action( 'init', array( ArticleId::class, 'register_meta' ) );

		Upgrader::maybe_upgrade();
		$this->register_channels();

		$this->register_modules();

		( new EventListener( $this->settings, $this->languages, $this->article_ids ) )->register();
		( new Dispatcher( $this->registry, $this->reader, $this->queue, $this->log ) )->register();
		( new Maintenance( $this ) )->register();

		// Any post event, and any saved setting that changes output, makes every cached feed stale.
		add_action( 'estasoft_nd_post_event', array( $this->cache, 'flush' ) );
		add_action( 'estasoft_nd_output_changed', array( $this->cache, 'flush' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		( new Frontend\Rewrite( $this ) )->register();

		if ( is_admin() ) {
			( new Admin\Admin( $this ) )->register();
		}

		/**
		 * Fires once the plugin has started.
		 *
		 * @param Plugin $plugin The running plugin.
		 */
		do_action( 'estasoft_nd_loaded', $this );
	}

	/**
	 * Registers the built-in channels, then lets other plugins register theirs.
	 *
	 * Built-in channels are found by folder: src/Channels/<Name>/<Name>Channel.php.
	 *
	 * @return void
	 */
	private function register_channels(): void {
		foreach ( Discovery::nested( 'Channels', 'Channel' ) as $class ) {
			if ( class_exists( $class ) && is_subclass_of( $class, Channels\AbstractChannel::class ) ) {
				$this->registry->register( new $class( $this->context ) );
			}
		}

		/**
		 * Fires once the built-in channels are registered.
		 *
		 * Other plugins and your own code register channels here.
		 *
		 * @param ChannelRegistry $registry The channel registry.
		 */
		do_action( 'estasoft_nd_register_channels', $this->registry );
	}

	/**
	 * Starts every module found in src/Modules/. See Modules\ModuleInterface.
	 *
	 * @return void
	 */
	private function register_modules(): void {
		foreach ( Discovery::classes( 'Modules' ) as $class ) {
			if ( class_exists( $class ) && is_subclass_of( $class, Modules\ModuleInterface::class ) ) {
				( new $class() )->register( $this );
			}
		}
	}

	/**
	 * Registers every REST controller found in src/Rest/.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		foreach ( Discovery::classes( 'Rest' ) as $class ) {
			if ( class_exists( $class ) && is_subclass_of( $class, Rest\Controller::class ) ) {
				( new $class( $this ) )->register_routes();
			}
		}
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function access(): Access {
		return $this->access;
	}

	public function secrets(): Secrets {
		return $this->secrets;
	}

	public function cache(): Cache {
		return $this->cache;
	}

	public function log(): DeliveryLog {
		return $this->log;
	}

	public function fetch_log(): FetchLog {
		return $this->fetch_log;
	}

	public function audit(): Audit {
		return $this->audit;
	}

	public function queue(): Queue {
		return $this->queue;
	}

	public function languages(): LanguageResolverInterface {
		return $this->languages;
	}

	public function reader(): ContentReader {
		return $this->reader;
	}

	public function channel_settings(): ChannelSettings {
		return $this->channel_settings;
	}

	public function applications(): Applications {
		return $this->applications;
	}

	public function rules(): Rules {
		return $this->rules;
	}

	public function article_ids(): ArticleId {
		return $this->article_ids;
	}

	/**
	 * A shared instance of a service class that takes the plugin in its
	 * constructor, made on first use. This is how a workstream adds a service
	 * without editing this file: `Plugin::instance()->make( Checks\ArticleChecker::class )`.
	 *
	 * @param string $name Fully qualified class name.
	 * @return object
	 */
	public function make( string $name ): object {
		if ( ! isset( $this->made[ $name ] ) ) {
			$this->made[ $name ] = new $name( $this );
		}

		return $this->made[ $name ];
	}

	public function jobs(): Jobs {
		return $this->jobs;
	}

	public function registry(): ChannelRegistry {
		return $this->registry;
	}

	public function context(): ChannelContext {
		return $this->context;
	}
}
