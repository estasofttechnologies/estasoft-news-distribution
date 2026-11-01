<?php
/**
 * Admin notices: what happened, one button, and a way to hide the ones that may be hidden.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Admin\Overview\Facts;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Consent;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\RulePackWatch;
use Estasoft\NewsDistribution\Core\RulePacks;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Integrations\ConflictDetector;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Notices show on News Distribution screens only, never on the Dashboard, the Plugins screen or another
 * plugin's screens, and only for a person with the Manage permission. There is no welcome note, no
 * review request and no upgrade prompt.
 *
 * - An error stays until the problem is gone and cannot be hidden.
 * - A warning or a note has a Dismiss button. It hides the notice for that person only: for
 *   14 days, or until what the notice is about changes (its token). Each dismissal is kept in
 *   the user meta `estasoft_nd_dismissed_notices` as `{ id: { at, token } }`.
 * - Each notice says what happened, then what to do, with one button. WordPress's own notice
 *   classes are used, so a screen reader finds them where it expects: an error has role alert,
 *   the others role status.
 *
 * Dismiss is a small form that posts to `admin-post.php`, so it works without a script.
 *
 * A notice can be hidden "until it changes": the rule pack and AI provider notices carry a token (the packs
 * and versions, or the providers that went missing) and stay hidden, however long ago they were hidden, until
 * that token is different.
 *
 * Every condition reads stored results only: the permalink setting, the delivery log, the
 * fetch log, the plugin list, the rule packs and WordPress's list of AI connectors. Nothing here calls another
 * server.
 */
final class NoticesModule implements ModuleInterface {

	public const META   = 'estasoft_nd_dismissed_notices';
	public const ACTION = 'estasoft_nd_dismiss_notice';

	/**
	 * How long Dismiss hides a warning, in days.
	 */
	public const DAYS = 14;

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_notices', array( $this, 'show' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'dismiss' ) );
		add_action( RulePackWatch::HOOK, array( $this, 'compare_batch' ), 10, 2 );
	}

	/**
	 * One background batch of the rule pack count. The queue calls this.
	 *
	 * @param mixed $job   Job id.
	 * @param mixed $batch Batch number.
	 * @return void
	 */
	public function compare_batch( $job = '', $batch = 0 ): void {
		$this->plugin->make( RulePackWatch::class )->process( $job, $batch );
	}

	/**
	 * Where the person is: a News Distribution screen's slug (with the channel or the settings section after a
	 * colon when there is one), or an empty list on any other screen.
	 *
	 * @return string[] Every name that fits the place.
	 */
	private function places(): array {
		$page = Query::key( 'page' );

		if ( 0 !== strpos( $page, 'estasoft-nd' ) || 'estasoft-nd-setup' === $page ) {
			return array();
		}

		$channel = Query::key( 'channel' );
		$places  = array( 'plugin', $page );

		if ( '' !== $channel ) {
			$places[] = $page . ':' . $channel;
		}

		$section = Query::key( 'section' );

		if ( '' !== $section ) {
			$places[] = $page . ':' . $section;
		}

		return $places;
	}

	/**
	 * Loads the notice style where a notice may show.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( array() === $this->places() || ! $this->plugin->access()->can( Access::MANAGE ) ) {
			return;
		}

		wp_enqueue_style(
			'estasoft-nd-notices',
			ESTASOFT_ND_URL . 'assets/css/screens/notices.css',
			array(),
			Assets::file_version( 'assets/css/screens/notices.css' )
		);
	}

	/**
	 * Prints the notices that fit the screen.
	 *
	 * @return void
	 */
	public function show(): void {
		$places = $this->places();

		if ( array() === $places || ! $this->plugin->access()->can( Access::MANAGE ) ) {
			return;
		}

		$user = get_current_user_id();

		foreach ( $this->notices() as $notice ) {
			if ( array() === array_intersect( $places, $notice['screens'] ) || $this->is_dismissed( $user, $notice ) ) {
				continue;
			}

			Output::show( $this->render( $notice ) );
		}
	}

	/**
	 * Every notice whose condition holds now, errors first.
	 *
	 * Each notice is `id`, `level` (error, warning or info), `title`, `text`, `actions` (trusted HTML),
	 * `screens` (where it shows), `dismiss` (whether the person may hide it) and `token` (what a dismissal
	 * is tied to: it shows again when the token changes).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function notices(): array {
		$list = array_merge(
			$this->permalinks(),
			$this->queue(),
			$this->conflicts(),
			$this->fetch(),
			$this->rule_pack(),
			$this->ai_provider()
		);

		$rank = array(
			'error'   => 0,
			'warning' => 1,
			'info'    => 2,
		);

		usort(
			$list,
			static function ( array $a, array $b ) use ( $rank ): int {
				return $rank[ $a['level'] ] <=> $rank[ $b['level'] ];
			}
		);

		return $list;
	}

	/**
	 * Builds one notice.
	 *
	 * @param string               $id      Stable id.
	 * @param string               $level   error, warning or info.
	 * @param string               $title   What happened, plain text.
	 * @param string               $text    What to do, plain text.
	 * @param string[]             $actions Trusted HTML of buttons or links.
	 * @param string[]             $screens Where it shows.
	 * @param array<string, mixed> $extra   `dismiss` (default true except for an error), `token` and `persist` (a dismissal lasts until the token changes, not for 14 days).
	 * @return array<string, mixed>
	 */
	private function notice( string $id, string $level, string $title, string $text, array $actions, array $screens, array $extra = array() ): array {
		return array(
			'id'      => $id,
			'level'   => $level,
			'title'   => $title,
			'text'    => $text,
			'actions' => $actions,
			'screens' => $screens,
			'dismiss' => $extra['dismiss'] ?? 'error' !== $level,
			'token'   => (string) ( $extra['token'] ?? '' ),
			'persist' => ! empty( $extra['persist'] ),
		);
	}

	/**
	 * A button that looks like WordPress's own.
	 *
	 * @param string $label Plain label.
	 * @param string $url   Address.
	 * @return string
	 */
	private function button( string $label, string $url ): string {
		return Html::tag(
			'a',
			array(
				'class' => 'button',
				'href'  => $url,
			),
			esc_html( $label )
		);
	}

	/**
	 * Plain permalinks: the sitemap and feed addresses cannot be served.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function permalinks(): array {
		if ( '' !== (string) get_option( 'permalink_structure', '' ) ) {
			return array();
		}

		return array(
			$this->notice(
				'permalinks',
				'error',
				__( 'Sitemaps cannot be served.', 'estasoft-news-distribution' ),
				__( 'This site uses plain permalinks, and News Distribution needs addresses such as /news-sitemap.xml.', 'estasoft-news-distribution' ),
				array( $this->button( __( 'Change permalinks', 'estasoft-news-distribution' ), admin_url( 'options-permalink.php' ) ) ),
				array( 'plugin' )
			),
		);
	}

	/**
	 * The queue is missing, or pushes have been waiting for hours.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function queue(): array {
		$fix = array( $this->button( __( 'How to fix this', 'estasoft-news-distribution' ), EnvironmentModule::screen_url() ) );

		if ( ! $this->plugin->queue()->is_available() ) {
			return array(
				$this->notice(
					'queue',
					'error',
					__( 'The background queue is not available.', 'estasoft-news-distribution' ),
					__( 'Action Scheduler is not loaded, so pushes cannot be sent or tried again. Pulled sitemaps and feeds still work.', 'estasoft-news-distribution' ),
					$fix,
					array( 'plugin' )
				),
			);
		}

		$oldest = Facts::queue_stalled_since( $this->plugin );

		if ( 0 === $oldest ) {
			return array();
		}

		$hours = (int) floor( ( Clock::now() - $oldest ) / HOUR_IN_SECONDS );

		return array(
			$this->notice(
				'queue',
				'error',
				__( 'The background queue is not running.', 'estasoft-news-distribution' ),
				sprintf(
					/* translators: %d: a number of hours. */
					_n( 'Action Scheduler has not run for %d hour, so pushes are waiting. Pulled sitemaps and feeds still work.', 'Action Scheduler has not run for %d hours, so pushes are waiting. Pulled sitemaps and feeds still work.', $hours, 'estasoft-news-distribution' ),
					$hours
				),
				$fix,
				array( 'plugin' )
			),
		);
	}

	/**
	 * Another plugin that prints a Google News sitemap.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function conflicts(): array {
		$found = ( new ConflictDetector( $this->plugin ) )->detect();
		$list  = array();

		foreach ( (array) $found['rows'] as $row ) {
			if ( Status::CONFLICT !== ( $row['status'] ?? '' ) || 'news' !== ( $row['kind'] ?? '' ) ) {
				continue;
			}

			$action = empty( $row['action']['url'] ) ? array() : array( $this->button( (string) $row['action']['label'], (string) $row['action']['url'] ) );

			if ( 'gn-publisher' === $row['id'] ) {
				$title = sprintf(
					/* translators: %s: the name of another plugin, such as GN Publisher. */
					__( 'The %s plugin also prints a Google News sitemap.', 'estasoft-news-distribution' ),
					(string) $row['name']
				);
				$text = __( 'Google may see 2 sitemaps with different rules.', 'estasoft-news-distribution' );
			} else {
				$title = sprintf(
					/* translators: %s: the name of another plugin. */
					__( '%s may also print a Google News sitemap.', 'estasoft-news-distribution' ),
					(string) $row['name']
				);
				$text = trim( (string) $row['result'] . ' ' . (string) $row['todo'] );
			}

			$list[] = $this->notice( 'conflict-' . sanitize_key( (string) $row['id'] ), 'warning', $title, $text, $action, array( 'plugin' ) );
		}

		return $list;
	}

	/**
	 * Google has not fetched a language's news sitemap for a long time, or never has.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch(): array {
		$channel = $this->plugin->registry()->get( 'google-news' );

		if ( null === $channel || ! $channel->is_enabled() ) {
			return array();
		}

		$names = array();
		$late  = array();
		$codes = array();

		foreach ( Facts::fetch_states( $this->plugin, $channel ) as $code => $state ) {
			if ( 'on_time' === $state['status'] ) {
				continue;
			}

			$codes[] = (string) $code;
			$names[] = Catalog::name( (string) $code );
			$late[]  = array(
				'name' => Catalog::name( (string) $code ),
				'last' => (int) $state['last_ts'],
			);
		}

		if ( array() === $late ) {
			return array();
		}

		if ( 1 === count( $late ) ) {
			$only = $late[0];

			if ( $only['last'] > 0 ) {
				$title = sprintf(
					/* translators: 1: a language name such as Arabic, 2: how long, such as "26 hours". */
					__( 'Google has not fetched the %1$s news sitemap for %2$s.', 'estasoft-news-distribution' ),
					$only['name'],
					$this->length( $only['last'] )
				);
			} else {
				$title = sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( 'Google has never fetched the %s news sitemap.', 'estasoft-news-distribution' ),
					$only['name']
				);
			}

			$text = __( 'Check that it is submitted in Search Console.', 'estasoft-news-distribution' );
		} else {
			$title = sprintf(
				/* translators: %d: a number of news sitemaps. */
				__( 'Google has not fetched %d of your news sitemaps.', 'estasoft-news-distribution' ),
				count( $late )
			);
			$text = sprintf(
				/* translators: %s: a list of language names. */
				__( 'These are %s. Check that each one is submitted in Search Console.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $names )
			);
		}

		return array(
			$this->notice(
				'fetch-google-news',
				'warning',
				$title,
				$text,
				array( $this->button( __( 'Open Site verification', 'estasoft-news-distribution' ), Links::channel( 'verification' ) ) ),
				array( 'estasoft-nd', 'estasoft-nd-channels:verification' ),
				array( 'token' => implode( ',', $codes ) )
			),
		);
	}

	/**
	 * A rule pack changed: which platforms, and how many of the articles of the last 14 days now get a different
	 * check result. Rule packs come with the plugin and are never downloaded, so the notice says "changed", not
	 * "available". It is hidden until the next change of a pack.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rule_pack(): array {
		$watch = $this->plugin->make( RulePackWatch::class );

		$watch->detect();

		$change = $watch->current();

		if ( null === $change ) {
			return array();
		}

		$packs = $this->plugin->make( RulePacks::class );
		$names = array();

		foreach ( $change['platforms'] as $platform => $row ) {
			$names[] = $packs->label( (string) $platform );
		}

		if ( 1 === count( $names ) ) {
			$only  = reset( $change['platforms'] );
			$title = sprintf(
				/* translators: 1: a platform name such as Apple News, 2: a rule pack version such as 2026.10.1. */
				__( 'The %1$s rule pack changed to version %2$s.', 'estasoft-news-distribution' ),
				$names[0],
				(string) $only['to']
			);
		} else {
			$shown = array_slice( $names, 0, 3 );
			$more  = count( $names ) - count( $shown );

			if ( $more > 0 ) {
				$shown[] = sprintf(
					/* translators: %d: a number of further platforms. */
					_n( '%d more', '%d more', $more, 'estasoft-news-distribution' ),
					$more
				);
			}

			$title = sprintf(
				/* translators: %s: a list of platform names, such as "Apple News, Bing and 2 more". */
				__( 'The rule packs changed for %s.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $shown )
			);
		}

		// Articles that differ for at least one of the packs that changed, each counted once.
		$affected = (int) $change['affected'];

		if ( 'counting' === $change['state'] ) {
			$text = __( 'Counting how many of your articles from the last 14 days get a different check result. Refresh this page in a minute.', 'estasoft-news-distribution' );
		} elseif ( 'failed' === $change['state'] ) {
			$text = __( 'The count of your articles from the last 14 days could not finish. Article check shows where each article stands now.', 'estasoft-news-distribution' );
		} elseif ( $affected < 1 ) {
			$text = __( 'None of your articles from the last 14 days gets a different check result.', 'estasoft-news-distribution' );
		} else {
			$text = sprintf(
				/* translators: %d: a number of articles. */
				_n( '%d of your articles from the last 14 days now gets a different check result.', '%d of your articles from the last 14 days now get a different check result.', $affected, 'estasoft-news-distribution' ),
				$affected
			);
		}

		$target = $affected > 0 && 'done' === $change['state'] ? Links::check() : Links::settings( 'rules' );

		return array(
			$this->notice(
				'rule-pack',
				'info',
				$title,
				$text,
				array( $this->button( __( 'Review changes', 'estasoft-news-distribution' ), $target ) ),
				array( 'estasoft-nd', 'estasoft-nd-settings:rules' ),
				array(
					'token'   => $change['token'],
					'persist' => true,
				)
			),
		);
	}

	/**
	 * The assistant is on and the owner confirmed an AI provider that is no longer installed, and no other provider
	 * can take its place, so the assistant is in its `no_provider` state. It shows on the screens that use AI: Article
	 * check, Settings, AI assistant and the two channel pages with an AI helper. WordPress 7.0 and newer only: older
	 * versions have no AI providers to remove. It goes away when a provider is connected again or the assistant is
	 * turned off, and a person who hides it sees it again only when the list of missing providers changes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function ai_provider(): array {
		$availability = $this->plugin->make( Availability::class );

		if ( ! $availability instanceof Availability || ! $availability->supported() ) {
			return array();
		}

		$record = $this->plugin->make( Consent::class )->record();

		if ( null === $record || array() === $record['providers'] || ! $this->plugin->make( Config::class )->enabled() ) {
			return array();
		}

		if ( 'no_provider' !== $availability->connection() ) {
			return array();
		}

		$installed = array();

		foreach ( $availability->connectors() as $connector ) {
			if ( $connector['installed'] ) {
				$installed[] = $connector['id'];
			}
		}

		$missing = array_values( array_diff( $record['providers'], $installed ) );

		if ( array() === $missing ) {
			return array();
		}

		sort( $missing );

		return array(
			$this->notice(
				'ai-provider-removed',
				'warning',
				__( 'The AI provider plugin was removed.', 'estasoft-news-distribution' ),
				__( 'AI suggestions are paused. Nothing else changes.', 'estasoft-news-distribution' ),
				array( $this->button( __( 'Open AI assistant', 'estasoft-news-distribution' ), Links::settings( 'ai' ) ) ),
				array( 'estasoft-nd-check', 'estasoft-nd-settings:ai', 'estasoft-nd-channels:smartnews', 'estasoft-nd-channels:news-break' ),
				array(
					'token'   => implode( ',', $missing ),
					'persist' => true,
				)
			),
		);
	}

	/**
	 * "26 hours" or "3 days".
	 *
	 * @param int $since Unix time of the last fetch.
	 * @return string
	 */
	private function length( int $since ): string {
		$seconds = max( 0, Clock::now() - $since );

		if ( $seconds < 3 * DAY_IN_SECONDS ) {
			$hours = max( 1, (int) floor( $seconds / HOUR_IN_SECONDS ) );

			/* translators: %d: a number of hours. */
			return sprintf( _n( '%d hour', '%d hours', $hours, 'estasoft-news-distribution' ), $hours );
		}

		$days = (int) floor( $seconds / DAY_IN_SECONDS );

		/* translators: %d: a number of days. */
		return sprintf( _n( '%d day', '%d days', $days, 'estasoft-news-distribution' ), $days );
	}

	/**
	 * One notice as markup.
	 *
	 * @param array<string, mixed> $notice The notice.
	 * @return string
	 */
	private function render( array $notice ): string {
		$buttons = '';

		foreach ( $notice['actions'] as $action ) {
			$buttons .= $action;
		}

		if ( $notice['dismiss'] ) {
			$buttons .= $this->dismiss_form( (string) $notice['id'] );
		}

		return Html::tag(
			'div',
			array(
				'class'          => 'notice notice-' . $notice['level'] . ' estasoft-nd-notice',
				'role'           => 'error' === $notice['level'] ? 'alert' : 'status',
				'data-notice-id' => $notice['id'],
			),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-notice__text' ), Html::tag( 'strong', array(), esc_html( (string) $notice['title'] ) ) . ' ' . esc_html( (string) $notice['text'] ) )
			. ( '' === $buttons ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-notice__actions' ), $buttons ) )
		);
	}

	/**
	 * The Dismiss button: a form, so it works without a script.
	 *
	 * @param string $id The notice.
	 * @return string
	 */
	private function dismiss_form( string $id ): string {
		return Html::tag(
			'form',
			array(
				'class'  => 'estasoft-nd-notice__dismiss',
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
			),
			wp_nonce_field( self::ACTION, '_wpnonce', true, false )
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => self::ACTION,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'notice',
					'value' => $id,
				)
			)
			. Html::tag(
				'button',
				array(
					'type'       => 'submit',
					'class'      => 'button-link',
					'aria-label' => __( 'Dismiss this notice', 'estasoft-news-distribution' ),
				),
				esc_html__( 'Dismiss', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * What a person has dismissed.
	 *
	 * @param int $user_id The person.
	 * @return array<string, array{at: int, token: string}>
	 */
	public static function dismissed( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META, true );
		$clean  = array();

		foreach ( is_array( $stored ) ? $stored : array() as $id => $row ) {
			if ( is_array( $row ) && isset( $row['at'] ) ) {
				$clean[ (string) $id ] = array(
					'at'    => (int) $row['at'],
					'token' => (string) ( $row['token'] ?? '' ),
				);
			}
		}

		return $clean;
	}

	/**
	 * Whether a person has hidden a notice and the hiding still holds.
	 *
	 * @param int                  $user_id The person.
	 * @param array<string, mixed> $notice  The notice.
	 * @return bool
	 */
	private function is_dismissed( int $user_id, array $notice ): bool {
		if ( ! $notice['dismiss'] ) {
			return false;
		}

		$row = self::dismissed( $user_id )[ (string) $notice['id'] ] ?? null;

		if ( null === $row || $row['token'] !== $notice['token'] ) {
			return false;
		}

		return ! empty( $notice['persist'] ) || Clock::now() < $row['at'] + ( self::DAYS * DAY_IN_SECONDS );
	}

	/**
	 * Saves a dismissal and returns to the screen the person was on.
	 *
	 * @return void
	 */
	public function dismiss(): void {
		check_admin_referer( self::ACTION );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$id = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( $_POST['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.

		foreach ( $this->notices() as $notice ) {
			if ( $notice['id'] === $id && $notice['dismiss'] ) {
				$user       = get_current_user_id();
				$all        = self::dismissed( $user );
				$all[ $id ] = array(
					'at'    => Clock::now(),
					'token' => (string) $notice['token'],
				);
				update_user_meta( $user, self::META, $all );
				break;
			}
		}

		$back = wp_get_referer();

		wp_safe_redirect( false === $back ? admin_url( 'plugins.php' ) : $back );
		exit;
	}
}
