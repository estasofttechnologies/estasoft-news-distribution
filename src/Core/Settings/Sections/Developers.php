<?php
/**
 * Settings, Developers.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Html;

defined( 'ABSPATH' ) || exit;

/**
 * The hooks other code can use, and how to add a platform. It reads and saves
 * nothing: it is a page of documentation that lives in the plugin so it cannot
 * drift from it. Every hook listed here is fired by the code today; a test greps
 * the source for each one. A hook that is not in the code does not belong on this page.
 *
 * Hooks whose name has `_internal_` in it are not for other plugins and are not listed.
 */
final class Developers extends AbstractSiteSection {

	public const NAME = 'developers';

	/**
	 * Nothing is stored.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array();
	}

	public function title(): string {
		return __( 'Developers', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 90;
	}

	/**
	 * @return bool
	 */
	public function has_save_bar(): bool {
		return false;
	}

	/**
	 * @return bool
	 */
	public function in_form(): bool {
		return false;
	}

	/**
	 * @return bool
	 */
	public function affects_output(): bool {
		return false;
	}

	/**
	 * The hooks, in the order they are listed.
	 *
	 * Each entry: `name`, `type` (action or filter), `args` (code, as written in the
	 * function signature), `after` (a sentence about the arguments, or empty), `returns`
	 * (what a callback gives back) and `does`. `core` marks the first five, which the
	 * development spec calls the stable ones. The names are checked against the source by a test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function hooks(): array {
		$nothing = __( 'Returns nothing.', 'estasoft-news-distribution' );

		return array(
			array(
				'name'    => 'estasoft_nd_register_channels',
				'type'    => 'action',
				'core'    => true,
				'args'    => array( 'ChannelRegistry $registry' ),
				'after'   => '',
				'returns' => $nothing,
				'does'    => __( 'Fires once the built-in channels are registered. Other plugins and your own adapters register here.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_post_event',
				'type'    => 'action',
				'core'    => true,
				'args'    => array( 'Event $event' ),
				'after'   => __( 'It holds the post, its language and the type: publish, update, unpublish or delete.', 'estasoft-news-distribution' ),
				'returns' => $nothing,
				'does'    => __( 'Fires when an article is published, updated, unpublished or deleted.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_deliver',
				'type'    => 'action',
				'core'    => true,
				'args'    => array( 'string $channel_id', 'int $article_id', 'string $event_type', 'int $attempt' ),
				'after'   => __( 'The event is publish, update, unpublish or delete, and the attempt is 1 to 4.', 'estasoft-news-distribution' ),
				'returns' => __( 'Returns nothing; the result goes in the delivery log.', 'estasoft-news-distribution' ),
				'does'    => __( 'Runs one delivery in the background queue (Action Scheduler), with retries. News Distribution listens to it itself.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_language_resolver',
				'type'    => 'filter',
				'core'    => true,
				'args'    => array( 'string $language', 'int $post_id' ),
				'after'   => __( '$language is the one News Distribution detected.', 'estasoft-news-distribution' ),
				'returns' => __( 'Returns a language code (en, ku or ar). Anything else keeps the detected language.', 'estasoft-news-distribution' ),
				'does'    => __( 'Replaces how News Distribution finds an article’s language.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_purge_urls',
				'type'    => 'action',
				'core'    => true,
				'args'    => array( 'array $urls' ),
				'after'   => __( 'Each is a full address.', 'estasoft-news-distribution' ),
				'returns' => $nothing,
				'does'    => __( 'Fires with the addresses to drop from a cache after an article is published or really changes. A caching plugin or your own snippet can listen and purge them.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_loaded',
				'type'    => 'action',
				'core'    => false,
				'args'    => array( 'Plugin $plugin' ),
				'after'   => '',
				'returns' => $nothing,
				'does'    => __( 'Fires once the plugin has started.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_settings_saved',
				'type'    => 'action',
				'core'    => false,
				'args'    => array( 'string $name', 'array $saved', 'array $previous' ),
				'after'   => __( 'The section’s name, its values now and its values before.', 'estasoft-news-distribution' ),
				'returns' => $nothing,
				'does'    => __( 'Fires after a settings section was saved.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_output_changed',
				'type'    => 'action',
				'core'    => false,
				'args'    => array( 'string $name' ),
				'after'   => __( 'The section that changed, or a word such as channels or restore.', 'estasoft-news-distribution' ),
				'returns' => $nothing,
				'does'    => __( 'Fires when a saved setting changes what feeds and sitemaps print. The output cache drops its copies.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_delivery_failed',
				'type'    => 'action',
				'core'    => false,
				'args'    => array( 'int $article_id', 'string $channel_id', 'string $language', 'string $event_type', 'int $attempt', 'int $code', 'string $message' ),
				'after'   => __( '$code is the platform’s HTTP status, or 0.', 'estasoft-news-distribution' ),
				'returns' => $nothing,
				'does'    => __( 'Fires when a delivery failed for good and will not be tried again. A failure that is being retried does not fire it.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_maintenance',
				'type'    => 'action',
				'core'    => false,
				'args'    => array( 'int $days' ),
				'after'   => __( '$days is how many days the logs are kept.', 'estasoft-news-distribution' ),
				'returns' => $nothing,
				'does'    => __( 'Fires after the daily clean-up, so your own code can add to it.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_feed_headers',
				'type'    => 'filter',
				'core'    => false,
				'args'    => array( 'array $headers', 'ChannelInterface $channel', 'string $language' ),
				'after'   => '',
				'returns' => __( 'Returns the headers.', 'estasoft-news-distribution' ),
				'does'    => __( 'Filters the HTTP headers of a feed response.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_fetch_agents',
				'type'    => 'filter',
				'core'    => false,
				'args'    => array( 'array $map' ),
				'after'   => __( 'Each key is a lower-case fragment of a user agent and each value is a platform name.', 'estasoft-news-distribution' ),
				'returns' => __( 'Returns the map.', 'estasoft-news-distribution' ),
				'does'    => __( 'Filters the platforms the fetch log can name.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_application_platforms',
				'type'    => 'filter',
				'core'    => false,
				'args'    => array( 'array $platforms' ),
				'after'   => __( 'Each key is a platform id and each value is its name.', 'estasoft-news-distribution' ),
				'returns' => __( 'Returns the list.', 'estasoft-news-distribution' ),
				'does'    => __( 'Filters the platforms in the application tracker.', 'estasoft-news-distribution' ),
			),
			array(
				'name'    => 'estasoft_nd_feed_hubs',
				'type'    => 'filter',
				'core'    => false,
				'args'    => array( 'array $hubs', 'string $slug' ),
				'after'   => __( '$slug is the feed’s address segment, such as main.', 'estasoft-news-distribution' ),
				'returns' => __( 'Returns the hub addresses to name in that feed.', 'estasoft-news-distribution' ),
				'does'    => __( 'Filters the WebSub hubs a feed names.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The two example adapters, as PHP source.
	 *
	 * They are the smallest classes that work against the plugin's real classes. A
	 * test loads them and registers them, so they cannot drift.
	 *
	 * @return array<string, array{title: string, class: string, code: string}>
	 */
	public static function examples(): array {
		$pulled = <<<'PHP'
<?php
use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Plugin;

final class Example_Channel extends AbstractChannel {
	public function id(): string { return 'example'; }
	public function label(): string { return 'Example platform'; }
	public function group(): string { return Group::PARTNER; }
	public function order(): int { return 100; }

	public function validate( Article $article ): ValidationResult {
		$result = new ValidationResult();
		// Add problems with $result->add_error( $code, $message ) or add_warning().
		return $result;
	}

	public function build( array $articles, string $language, ?int $now = null ): string {
		// Return the feed or sitemap as a string.
		return '';
	}
}

add_action( 'estasoft_nd_register_channels', function ( $registry ) {
	$registry->register( new Example_Channel( Plugin::instance()->context() ) );
} );
PHP;

		$pushed = <<<'PHP'
<?php
use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\DeliveryResult;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Event;
use Estasoft\NewsDistribution\Plugin;

final class Example_Push_Channel extends AbstractChannel {
	public function id(): string { return 'example-push'; }
	public function label(): string { return 'Example notice'; }
	public function group(): string { return Group::PUSHES; }
	public function order(): int { return 100; }
	public function mode(): string { return self::MODE_PUSHED; }

	public function build( array $articles, string $language, ?int $now = null ): string {
		return '';
	}

	// $article is null when the article was removed.
	public function deliver( Event $event, ?Article $article ): DeliveryResult {
		// Send the notice, then return one of:
		//   DeliveryResult::success( $http_status, $message )
		//   DeliveryResult::failure( $message, $http_status )  (tried again, up to attempt 4, when the status is one worth retrying)
		//   DeliveryResult::skipped( $message )
		return DeliveryResult::success();
	}
}

add_action( 'estasoft_nd_register_channels', function ( $registry ) {
	$registry->register( new Example_Push_Channel( Plugin::instance()->context() ) );
} );
PHP;

		return array(
			'pulled' => array(
				'title' => __( 'A pulled channel', 'estasoft-news-distribution' ),
				'class' => 'Example_Channel',
				'code'  => $pulled,
			),
			'pushed' => array(
				'title' => __( 'A pushed channel', 'estasoft-news-distribution' ),
				'class' => 'Example_Push_Channel',
				'code'  => $pushed,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function layout(): array {
		$planned = Html::tag(
			'ul',
			array( 'class' => 'estasoft-nd-dev-list' ),
			Html::tag( 'li', array(), esc_html__( 'A REST route for each channel’s output', 'estasoft-news-distribution' ) )
			. Html::tag( 'li', array(), esc_html__( 'WP-CLI commands to validate and rebuild a channel', 'estasoft-news-distribution' ) )
			. Html::tag( 'li', array(), esc_html__( 'A test helper that checks an adapter against sample articles', 'estasoft-news-distribution' ) )
		) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The hooks above are in the plugin now. These three are planned and not built yet.', 'estasoft-news-distribution' ) );

		return array(
			'cards'  => array(
				array(
					'id'          => 'hooks',
					'title'       => __( 'Hooks', 'estasoft-news-distribution' ),
					'description' => __( 'New platforms appear faster than one team can add them, so anyone can write an adapter and a regional platform does not have to wait for a release. These hooks exist in the plugin today. Each name starts with estasoft_nd_.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Hooks', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => function (): string {
								return $this->hooks_table( true );
							},
						),
					),
				),
				array(
					'id'          => 'more-hooks',
					'title'       => __( 'More hooks', 'estasoft-news-distribution' ),
					'description' => __( 'These exist in the plugin today too. A hook with _internal_ in its name is for News Distribution only, is not listed, and can change without notice.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'More hooks', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => function (): string {
								return $this->hooks_table( false );
							},
						),
					),
				),
				array(
					'id'          => 'platform',
					'title'       => __( 'Add a platform', 'estasoft-news-distribution' ),
					'description' => __( 'An adapter is one class. A pulled channel (a feed or a sitemap) implements build(). A pushed channel (a notice) implements deliver() instead. These are the smallest working examples.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Examples', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_examples' ),
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'Planned, not built', 'estasoft-news-distribution' ),
					'html'  => $planned,
				),
			),
			'cannot' => array(
				__( 'Get a platform to accept your feed. The platform still decides whether to fetch or list it.', 'estasoft-news-distribution' ),
				__( 'Make an adapter supported. Estasoft supports the built-in channels. An adapter you write or install is your own code, and it runs with your site’s permissions.', 'estasoft-news-distribution' ),
				__( 'Change what another channel checks. An adapter adds a channel and does not edit the rules of the others.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * A table of hooks.
	 *
	 * @param bool $core True for the five the spec names, false for the others.
	 * @return string Escaped HTML.
	 */
	private function hooks_table( bool $core ): string {
		$rows = array();

		foreach ( self::hooks() as $hook ) {
			if ( $core !== $hook['core'] ) {
				continue;
			}

			$codes = array();

			foreach ( $hook['args'] as $arg ) {
				$codes[] = Html::tag( 'code', array( 'dir' => 'ltr' ), esc_html( $arg ) );
			}

			$receives = esc_html__( 'Receives', 'estasoft-news-distribution' ) . ' ' . implode( ', ', $codes ) . '.';

			if ( '' !== $hook['after'] ) {
				$receives .= ' ' . esc_html( $hook['after'] );
			}

			$receives .= ' ' . esc_html( $hook['returns'] );

			$rows[] = array(
				Html::tag( 'code', array( 'dir' => 'ltr' ), esc_html( $hook['name'] ) ),
				esc_html( 'filter' === $hook['type'] ? __( 'Filter', 'estasoft-news-distribution' ) : __( 'Action', 'estasoft-news-distribution' ) ),
				$receives,
				esc_html( $hook['does'] ),
			);
		}

		return self::grid(
			'estasoft-nd-cols-hooks',
			array(
				__( 'Hook', 'estasoft-news-distribution' ),
				__( 'Type', 'estasoft-news-distribution' ),
				__( 'Receives and returns', 'estasoft-news-distribution' ),
				__( 'What it does', 'estasoft-news-distribution' ),
			),
			$rows,
			$core ? __( 'Hooks', 'estasoft-news-distribution' ) : __( 'More hooks', 'estasoft-news-distribution' ),
			false
		);
	}

	/**
	 * The two example classes.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_examples(): string {
		$html = '';

		foreach ( self::examples() as $key => $example ) {
			$html .= Html::tag( 'h3', array( 'class' => 'estasoft-nd-dev-sub' ), esc_html( $example['title'] ) )
				. Code::block( $example['code'], $example['title'] );
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-dev-examples' ),
			$html . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'News Distribution handles the queue, the retries, the delivery log and the checks that run on every article. Your adapter only builds or sends.', 'estasoft-news-distribution' ) )
		);
	}
}
