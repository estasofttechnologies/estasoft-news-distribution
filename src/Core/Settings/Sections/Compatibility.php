<?php
/**
 * Settings, Compatibility, and the switch from GN Publisher.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Core\Clock as Time;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Integrations\ConflictDetector;
use Estasoft\NewsDistribution\Integrations\GnPublisher;
use Estasoft\NewsDistribution\Modules\GnPublisherRedirectModule as Actions;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the plugins that print the same sitemaps, feeds or tags, or that cache them,
 * and walks the owner through moving a Google News sitemap from GN Publisher to
 * News Distribution (`&tab=gn-publisher`).
 *
 * It reads what the site prints and says what it read. It never changes another
 * plugin's settings or data, and it never redirects: the old sitemap address is
 * answered in place (see Modules\GnPublisherRedirectModule).
 *
 * Stored choices:
 *
 * - `answer_old_address`: News Distribution answers at the old sitemap address.
 * - `old_sitemap_path`: that address, as a path such as `/news-sitemap.xml`.
 *
 * Both are saved by step 4 of the switch, or through `PUT /settings/compatibility`.
 * The main screen is a read-only list, so it has no save bar and no form of its own
 * apart from the "Check again" button.
 */
final class Compatibility extends AbstractSiteSection {

	public const NAME = 'compatibility';

	/**
	 * The first part of an address that WordPress uses itself, which cannot be "the old sitemap".
	 */
	private const RESERVED = array( 'wp-admin', 'wp-json', 'wp-login.php', 'wp-content', 'wp-includes', 'wp-cron.php', 'xmlrpc.php', 'robots.txt', 'feed' );

	/**
	 * What the detector found, once per request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $detected = null;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'answer_old_address' => array(
				'type'    => 'bool',
				'label'   => __( 'Also answer at the old address', 'estasoft-news-distribution' ),
				'default' => false,
			),
			'old_sitemap_path'   => array(
				'type'    => 'string',
				'label'   => __( 'Old sitemap address', 'estasoft-news-distribution' ),
				'format'  => 'text',
				'max'     => 200,
				'default' => '',
			),
		);
	}

	public function title(): string {
		return __( 'Compatibility', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'operations';
	}

	public function order(): int {
		return 60;
	}

	/**
	 * The screen is a list and a set of buttons, so it has no save bar and no form around it.
	 *
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
	 * What the old address answers does not change what feeds and sitemaps print.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return false;
	}

	/**
	 * Accepts a pasted address in any usual form: with the site's name, or without the first slash.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		if ( isset( $input['old_sitemap_path'] ) && is_string( $input['old_sitemap_path'] ) ) {
			$input['old_sitemap_path'] = self::tidy_path( $input['old_sitemap_path'] );
		}

		return $input;
	}

	/**
	 * Turns what a person typed into a path.
	 *
	 * @param string $value The text.
	 * @return string A path that starts with a slash, or an empty string.
	 */
	public static function tidy_path( string $value ): string {
		$value = trim( $value );

		if ( preg_match( '#^https?://#i', $value ) ) {
			$path  = wp_parse_url( $value, PHP_URL_PATH );
			$value = is_string( $path ) ? $path : '';
		}

		if ( '' !== $value && '/' !== $value[0] ) {
			$value = '/' . $value;
		}

		return $value;
	}

	/**
	 * The address has to be a plain path, and has to be turned on only with an address.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		$path = (string) ( $values['old_sitemap_path'] ?? '' );

		if ( '' === $path ) {
			if ( ! empty( $values['answer_old_address'] ) ) {
				return array(
					'old_sitemap_path' => __( 'Old sitemap address is empty. Enter the address the other plugin used, such as /news-sitemap.xml, or turn off “Also answer at the old address”.', 'estasoft-news-distribution' ),
				);
			}

			return array();
		}

		if ( ! preg_match( '#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*/?$#', $path ) ) {
			return array(
				'old_sitemap_path' => __( 'Old sitemap address must start with / and use letters, numbers, dots, dashes and slashes only, for example /news-sitemap.xml. Leave out the part after a question mark.', 'estasoft-news-distribution' ),
			);
		}

		$parts = explode( '/', ltrim( $path, '/' ) );
		$first = strtolower( $parts[0] );

		if ( in_array( $first, self::RESERVED, true ) || 0 === strpos( $first, 'wp-sitemap' ) ) {
			return array(
				'old_sitemap_path' => __( 'Old sitemap address is an address WordPress uses itself. Use the address the other plugin printed, such as /news-sitemap.xml.', 'estasoft-news-distribution' ),
			);
		}

		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function layout(): array {
		$notes = array();
		$gn    = $this->conflict_card();

		if ( null !== $gn ) {
			$notes[] = $gn;
		}

		foreach ( $this->detected()['rows'] as $row ) {
			if ( 'cache' === $row['kind'] ) {
				$notes[] = $this->cache_card( $row );
			}
		}

		return array(
			'cards'  => array(
				array(
					'id'          => 'plugins',
					'title'       => __( 'Other plugins found', 'estasoft-news-distribution' ),
					'description' => __( 'News Distribution lists the plugins it found that print the same sitemaps, feeds or tags, or that cache them. It can see that a plugin is installed and whether something is printed twice. It cannot see how the plugin is set up.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Plugins', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_plugins' ),
						),
					),
				),
			),
			'notes'  => $notes,
			'cannot' => array(
				__( 'Change another plugin’s settings. It tells you what it found and what to change', 'estasoft-news-distribution' ),
				__( 'See how another plugin is set up. It sees that the plugin is installed and what it prints', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * What the detector found.
	 *
	 * @return array<string, mixed>
	 */
	private function detected(): array {
		if ( null === $this->detected ) {
			$this->detected = ( new ConflictDetector( Plugin::instance() ) )->detect();
		}

		return $this->detected;
	}

	/**
	 * The table of plugins.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_plugins(): string {
		$rows = array();

		foreach ( $this->detected()['rows'] as $row ) {
			$todo = is_array( $row['action'] )
				? Button::link(
					(string) $row['action']['label'],
					(string) $row['action']['url'],
					array(
						'variant' => 'primary',
						'size'    => 'sm',
					)
				)
				: Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $row['todo'] ) );

			$rows[] = array(
				Html::tag( 'strong', array(), esc_html( (string) $row['name'] ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-compat-sub' ), esc_html( (string) $row['result'] ) ),
				self::result_pill( (string) $row['status'] ),
				$todo,
			);
		}

		if ( array() === $rows ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-compat-empty' ), esc_html__( 'No other plugin that prints sitemaps, feeds or tags, or that caches them, was found.', 'estasoft-news-distribution' ) );
		}

		return self::grid(
			'estasoft-nd-cols-plugins',
			array(
				__( 'Plugin', 'estasoft-news-distribution' ),
				__( 'Result', 'estasoft-news-distribution' ),
				__( 'What to do', 'estasoft-news-distribution' ),
			),
			$rows,
			__( 'Other plugins found', 'estasoft-news-distribution' ),
			false
		);
	}

	/**
	 * A conflict is red on the board; the word comes from the shared status table.
	 *
	 * @param string $status Status::CONFLICT or Status::NO_CONFLICT.
	 * @return string
	 */
	private static function result_pill( string $status ): string {
		if ( Status::CONFLICT !== $status ) {
			return StatusView::pill( $status );
		}

		return Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-badge estasoft-nd-badge--danger' ),
			Icon::svg( 'x-circle' ) . esc_html( Status::describe( Status::CONFLICT )['word'] )
		);
	}

	/**
	 * The card for the plugin that prints a news sitemap, when there is one.
	 *
	 * @return array{title: string, html: string}|null
	 */
	private function conflict_card(): ?array {
		$found = null;

		foreach ( $this->detected()['rows'] as $row ) {
			if ( 'news' === $row['kind'] ) {
				$found = $row;
				break;
			}
		}

		if ( null === $found ) {
			return null;
		}

		$gn      = new GnPublisher( Plugin::instance() );
		$scan    = $gn->last_scan();
		$others  = $gn->others( $scan );
		$ran_at  = (int) $this->detected()['scan_ran_at'];
		$sitemap = false;

		foreach ( $others as $item ) {
			if ( 'news_sitemap' === $item['type'] ) {
				$sitemap = true;
			}
		}

		$body = '';

		if ( GnPublisher::PLUGIN_ID !== $found['id'] ) {
			$body .= Html::tag( 'p', array(), esc_html( (string) $found['result'] ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( (string) $found['todo'] ) );
		} elseif ( null === $scan ) {
			$body .= Html::tag( 'p', array(), esc_html__( 'GN Publisher is active. News Distribution has not looked at what it prints yet.', 'estasoft-news-distribution' ) );
		} elseif ( array() === $others ) {
			$body .= Html::tag( 'p', array(), esc_html__( 'GN Publisher is active, but News Distribution did not see a news sitemap or a news feed from it, so it may be turned off.', 'estasoft-news-distribution' ) );
		} else {
			$body .= Html::tag(
				'p',
				array(),
				esc_html(
					$sitemap
						? __( 'News Distribution found a Google News sitemap printed by the GN Publisher plugin, and it prints its own. Keeping both on can give Google two sitemaps with different contents, so pick one.', 'estasoft-news-distribution' )
						: __( 'News Distribution found a news feed printed by the GN Publisher plugin, and it prints its own. Keeping both on can give Google two sources with different contents, so pick one.', 'estasoft-news-distribution' )
				)
			);

			$steps = Html::tag(
				'li',
				array(),
				esc_html(
					$sitemap
						? __( 'Turn off the Google News sitemap and the feed in the GN Publisher plugin. News Distribution cannot do this for you.', 'estasoft-news-distribution' )
						: __( 'Turn off the news feed in the GN Publisher plugin. News Distribution cannot do this for you.', 'estasoft-news-distribution' )
				)
			);

			if ( $sitemap ) {
				$steps .= Html::tag( 'li', array(), esc_html__( 'Let News Distribution answer at the old sitemap address. Google already knows it, so nothing is resubmitted in Search Console.', 'estasoft-news-distribution' ) );
			}

			$steps .= Html::tag( 'li', array(), esc_html__( 'Check again here.', 'estasoft-news-distribution' ) );
			$body  .= Html::tag( 'ol', array( 'class' => 'estasoft-nd-compat-steps' ), $steps );
		}

		$label = null === $scan ? __( 'Check now', 'estasoft-news-distribution' ) : __( 'Check again', 'estasoft-news-distribution' );
		$when  = $ran_at > 0
			? Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: a time such as 14:08. */
						__( 'Last checked %s', 'estasoft-news-distribution' ),
						Time::format( $ran_at )
					)
				)
			)
			: '';

		$body .= Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			self::post_form( Actions::ACTION_SCAN, $label ) . $when
		);

		return array(
			'title' => (string) $found['name'],
			'html'  => Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $body ),
		);
	}

	/**
	 * The card for a plugin that caches pages.
	 *
	 * @param array<string, mixed> $row A detector row.
	 * @return array{title: string, html: string}
	 */
	private function cache_card( array $row ): array {
		return array(
			'title' => (string) $row['name'],
			'html'  => Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ),
				Html::tag( 'p', array(), esc_html__( 'If feed caching is on, platform fetches are hidden from the Fetch monitor and a stale feed can be served.', 'estasoft-news-distribution' ) )
				. Code::block( '/feed/*', __( 'Address to add to the never-cached list', 'estasoft-news-distribution' ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Add this address to the plugin’s list of pages that are never cached.', 'estasoft-news-distribution' ) )
			),
		);
	}

	/**
	 * A button that posts to one of the module's actions. It works without JavaScript.
	 *
	 * @param string               $action  The admin-post action, which is also the nonce name.
	 * @param string               $label   The button's words.
	 * @param array<string, mixed> $options Button options.
	 * @param string               $fields  Trusted HTML for more fields inside the form.
	 * @return string
	 */
	private static function post_form( string $action, string $label, array $options = array(), string $fields = '' ): string {
		return Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => $action,
				)
			)
			. wp_nonce_field( $action, '_wpnonce', true, false )
			. $fields
			. Button::submit( $label, $options )
		);
	}

	/**
	 * The switch from GN Publisher.
	 *
	 * @param string $tab The `tab` argument.
	 * @return string|null
	 */
	public function render_tab( string $tab ): ?string {
		if ( 'gn-publisher' !== $tab ) {
			return null;
		}

		$plugin  = Plugin::instance();
		$gn      = new GnPublisher( $plugin );
		$scan    = $gn->last_scan();
		$compare = $gn->last_compare();
		$check   = $gn->last_check();
		$others  = $gn->others( $scan );
		$flag    = Query::key( 'gn' );
		$notice  = '';

		if ( 'error' === $flag ) {
			$stored = get_transient( Actions::NOTICE_KEY . get_current_user_id() );
			delete_transient( Actions::NOTICE_KEY . get_current_user_id() );
			$notice = Message::text( 'danger', is_string( $stored ) && '' !== $stored ? $stored : __( 'Nothing was saved.', 'estasoft-news-distribution' ) );
		} elseif ( 'saved' === $flag ) {
			$notice = Message::text( 'success', __( 'Saved.', 'estasoft-news-distribution' ) );
		}

		// A step is done when its result is known. The first one that is not done is the one to do now.
		$compared = null !== $compare && ( ! empty( $compare['available'] ) || 'no_other' === ( $compare['reason'] ?? '' ) );
		$done     = array(
			1 => null !== $scan,
			2 => $compared || ( null !== $scan && array() === $others ),
			3 => false,
			4 => $gn->answers_old_address(),
			5 => null !== $check && 'ok' === $check['result'],
		);
		$now      = 3;

		foreach ( $done as $number => $is_done ) {
			if ( ! $is_done ) {
				$now = $number;
				break;
			}
		}

		$bodies = array(
			1 => $this->step_find( $scan, $others ),
			2 => $this->step_compare( $gn, $scan, $compare ),
			3 => $this->step_turn_off( $gn ),
			4 => $notice . $this->step_answer( $gn, $others ),
			5 => $this->step_check( $check ),
		);
		$heads  = array(
			1 => array( __( 'Find what the other plugin prints', 'estasoft-news-distribution' ), __( 'News Distribution lists the news sitemaps and feeds it finds on your site.', 'estasoft-news-distribution' ) ),
			2 => array( __( 'Compare the two', 'estasoft-news-distribution' ), __( 'Both should list the same recent articles before you switch.', 'estasoft-news-distribution' ) ),
			3 => array( __( 'Turn off the other plugin’s output', 'estasoft-news-distribution' ), '' ),
			4 => array( __( 'Answer at the old address', 'estasoft-news-distribution' ), __( 'Google already knows the old sitemap address. Once the other plugin is off, News Distribution answers there with its own sitemap, so you do not resubmit anything in Search Console. Do this straight after step 3: until then the old address does not answer.', 'estasoft-news-distribution' ) ),
			5 => array( __( 'Check the result', 'estasoft-news-distribution' ), __( 'News Distribution fetches the old address and your new sitemap, and confirms that only News Distribution answers.', 'estasoft-news-distribution' ) ),
		);

		$items = '';

		foreach ( $heads as $number => $head ) {
			$items .= $this->step( $number, $head[0], $head[1], $bodies[ $number ], $done[ $number ], $now === $number );
		}

		$crumbs = Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-crumbs',
				'aria-label' => __( 'Breadcrumb', 'estasoft-news-distribution' ),
			),
			'<a href="' . esc_url( self::url_of( 'general' ) ) . '">' . esc_html__( 'Settings', 'estasoft-news-distribution' ) . '</a> / <a href="' . esc_url( self::url_of( self::NAME ) ) . '">' . esc_html__( 'Compatibility', 'estasoft-news-distribution' ) . '</a>'
		);

		$notes = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes estasoft-nd-gn-notes' ),
			Card::note(
				'gn-wrong',
				__( 'If something goes wrong', 'estasoft-news-distribution' ),
				Html::tag(
					'ol',
					array( 'class' => 'estasoft-nd-compat-steps' ),
					Html::tag( 'li', array(), esc_html__( 'Turn off “Also answer at the old address”.', 'estasoft-news-distribution' ) )
					. Html::tag( 'li', array(), esc_html__( 'Turn the other plugin’s sitemap back on.', 'estasoft-news-distribution' ) )
				) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing was deleted, so nothing needs restoring.', 'estasoft-news-distribution' ) )
			)
			. Card::note(
				'gn-readers',
				__( 'What changes for readers', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing for readers of your site. Anything that reads the other plugin’s feed address, such as a feed reader, stops getting updates once you turn that feed off, so point it at a News Distribution feed first (step 3).', 'estasoft-news-distribution' ) )
			)
			. Card::cannot(
				array(
					__( 'Change or delete another plugin’s data or settings.', 'estasoft-news-distribution' ),
					__( 'Redirect your pages.', 'estasoft-news-distribution' ),
					__( 'Resubmit sitemaps for you. Search Console needs no change.', 'estasoft-news-distribution' ),
				)
			)
		);

		return $crumbs
			. PageHeader::render(
				__( 'Switch from GN Publisher', 'estasoft-news-distribution' ),
				__( 'Move your Google News sitemap and feed to News Distribution. The old sitemap address keeps answering, so nothing is resubmitted in Search Console.', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-card estasoft-nd-card--steps estasoft-nd-gn-steps',
					'role'       => 'group',
					'aria-label' => __( 'Steps to switch from GN Publisher', 'estasoft-news-distribution' ),
				),
				$items
			)
			. $notes;
	}

	/**
	 * One numbered step.
	 *
	 * @param int    $number  Its number.
	 * @param string $title   Its heading (plain).
	 * @param string $text    The line under the heading (plain), or empty.
	 * @param string $body    Trusted HTML.
	 * @param bool   $is_done Whether its result is known.
	 * @param bool   $is_now  Whether it is the one to do now.
	 * @return string
	 */
	private function step( int $number, string $title, string $text, string $body, bool $is_done, bool $is_now ): string {
		$title_id = 'estasoft-nd-gn-step-title-' . $number;

		if ( $is_done ) {
			$badge = Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-step__num estasoft-nd-step__num--done' ),
				Icon::svg( 'check' ) . Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-sr' ),
					esc_html(
						sprintf(
							/* translators: %d: the number of a step. */
							__( 'Step %d is done.', 'estasoft-news-distribution' ),
							$number
						)
					)
				)
			);
		} else {
			$badge = Html::tag(
				'span',
				array( 'class' => Html::classes( 'estasoft-nd-step__num', $is_now ? 'estasoft-nd-step__num--now' : '' ) ),
				esc_html( (string) $number )
			);
		}

		$head = Html::tag(
			'h2',
			array(
				'class' => 'estasoft-nd-title',
				'id'    => $title_id,
			),
			esc_html( $title )
		);

		if ( '' !== $text ) {
			$head .= Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) );
		}

		return Html::tag(
			'section',
			array(
				'id'              => 'step-' . $number,
				'class'           => Html::classes( 'estasoft-nd-step', 'estasoft-nd-gn-step', $is_now ? 'estasoft-nd-step--now' : '' ),
				'aria-labelledby' => $title_id,
				'data-gn-step'    => (string) $number,
			),
			$badge . Html::tag( 'div', array( 'class' => 'estasoft-nd-gn-step__body' ), Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $head ) . $body )
		);
	}

	/**
	 * Step 1.
	 *
	 * @param array<string, mixed>|null $scan   The last scan.
	 * @param array<int, array>         $others What was found that is not News Distribution's.
	 * @return string
	 */
	private function step_find( ?array $scan, array $others ): string {
		$look = static function ( string $label ): string {
			return self::post_form( Actions::ACTION_LOOK, $label );
		};

		if ( null === $scan ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not looked at yet.', 'estasoft-news-distribution' ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster' ), $look( __( 'Look for them', 'estasoft-news-distribution' ) ) );
		}

		if ( empty( $scan['reachable'] ) ) {
			return Message::text( 'danger', __( 'News Distribution could not read your site’s own addresses. Nothing was changed. Try again in a few minutes.', 'estasoft-news-distribution' ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster' ), $look( __( 'Look again', 'estasoft-news-distribution' ) ) );
		}

		if ( array() === $others ) {
			return Html::tag( 'p', array(), '<strong>' . esc_html__( 'No other news sitemap or feed found.', 'estasoft-news-distribution' ) . '</strong> ' . esc_html__( 'There is nothing to switch from.', 'estasoft-news-distribution' ) )
				. $this->found_footer( (int) $scan['ran_at'] )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster' ), $look( __( 'Look again', 'estasoft-news-distribution' ) ) );
		}

		$rows = '';

		foreach ( $others as $item ) {
			$rows .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-gn-found__row' ),
				Html::tag( 'strong', array(), esc_html( 'news_sitemap' === $item['type'] ? __( 'A Google News sitemap', 'estasoft-news-distribution' ) : __( 'A news feed', 'estasoft-news-distribution' ) ) )
				. Html::tag( 'code', array( 'dir' => 'ltr' ), esc_html( (string) $item['url'] ) )
				. self::result( 'info', __( 'Found', 'estasoft-news-distribution' ) )
			);
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-gn-found' ), $rows )
			. $this->found_footer( (int) $scan['ran_at'] )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster' ), $look( __( 'Look again', 'estasoft-news-distribution' ) ) );
	}

	/**
	 * The line under the found addresses.
	 *
	 * @param int $ran_at When News Distribution looked.
	 * @return string
	 */
	private function found_footer( int $ran_at ): string {
		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html(
				sprintf(
					/* translators: %s: a time such as 14:08. */
					__( 'News Distribution reads your robots.txt and checks the usual addresses. Last looked at %s.', 'estasoft-news-distribution' ),
					Time::format( $ran_at )
				)
			)
		);
	}

	/**
	 * Step 2.
	 *
	 * @param GnPublisher               $gn      The helper.
	 * @param array<string, mixed>|null $scan    The last scan.
	 * @param array<string, mixed>|null $compare The last comparison.
	 * @return string
	 */
	private function step_compare( GnPublisher $gn, ?array $scan, ?array $compare ): string {
		if ( null === $scan ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Look for the other plugin in step 1 first.', 'estasoft-news-distribution' ) );
		}

		if ( array() === $gn->others( $scan ) ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No other news sitemap or feed was found, so there is nothing to compare.', 'estasoft-news-distribution' ) );
		}

		if ( null === $compare ) {
			$channel = Plugin::instance()->registry()->get( 'google-news' );

			if ( null === $channel || ! $channel->is_enabled() ) {
				return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The Google News channel is off, so News Distribution lists nothing to compare. Turn it on in Channels, then look again in step 1.', 'estasoft-news-distribution' ) );
			}

			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not compared yet. Press “Look again” in step 1.', 'estasoft-news-distribution' ) );
		}

		if ( empty( $compare['available'] ) ) {
			$reasons = array(
				'channel_off'  => __( 'The Google News channel is off, so News Distribution lists nothing to compare. Turn it on in Channels, then look again in step 1.', 'estasoft-news-distribution' ),
				'fetch_failed' => __( 'The other plugin’s address could not be read. Nothing was changed. Try again in a few minutes.', 'estasoft-news-distribution' ),
			);

			return Message::text( 'warning', $reasons[ $compare['reason'] ] ?? __( 'The two lists could not be compared.', 'estasoft-news-distribution' ) );
		}

		$hours = (int) $compare['window_hours'];
		$total = (int) $compare['total'];

		if ( 0 === $total ) {
			return Html::tag(
				'p',
				array(),
				esc_html(
					sprintf(
						/* translators: %s: a number of hours. */
						__( 'No article from the last %s hours is listed by either plugin.', 'estasoft-news-distribution' ),
						number_format_i18n( $hours )
					)
				)
			);
		}

		$parts = array(
			sprintf(
				/* translators: %s: a number of articles. */
				_n( '%s is listed by both plugins', '%s are listed by both plugins', (int) $compare['same'], 'estasoft-news-distribution' ),
				number_format_i18n( (int) $compare['same'] )
			),
		);

		if ( (int) $compare['only_ours'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: a number of articles. */
				__( '%s only by News Distribution', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $compare['only_ours'] )
			);
		}

		if ( (int) $compare['only_other'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: a number of articles. */
				__( '%s only by the other plugin', 'estasoft-news-distribution' ),
				number_format_i18n( (int) $compare['only_other'] )
			);
		}

		$summary = Html::tag(
			'p',
			array(),
			Html::tag(
				'strong',
				array(),
				esc_html(
					sprintf(
						/* translators: 1: a number of articles, 2: a number of hours. */
						_n( '%1$s article from the last %2$s hours:', '%1$s articles from the last %2$s hours:', $total, 'estasoft-news-distribution' ),
						number_format_i18n( $total ),
						number_format_i18n( $hours )
					)
				)
			) . ' ' . esc_html( implode( ', ', $parts ) . '.' )
		);

		$shown = array_slice( (array) $compare['rows'], 0, 8 );
		$rows  = array();

		foreach ( $shown as $row ) {
			$difference = array(
				'same'       => self::result( 'success', __( 'Same', 'estasoft-news-distribution' ) ),
				'only_ours'  => self::result( 'info', __( 'Only in News Distribution', 'estasoft-news-distribution' ) ),
				'only_other' => self::result( 'warning', __( 'Only in the other plugin', 'estasoft-news-distribution' ) ),
			);

			$rows[] = array(
				esc_html( '' === (string) $row['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $row['title'] ),
				esc_html( $row['other'] ? __( 'Listed', 'estasoft-news-distribution' ) : __( 'Missing', 'estasoft-news-distribution' ) ),
				esc_html( $row['ours'] ? __( 'Listed', 'estasoft-news-distribution' ) : __( 'Missing', 'estasoft-news-distribution' ) ),
				$difference[ $row['difference'] ] ?? '',
			);
		}

		$footer = count( $shown ) < $total
			? sprintf(
				/* translators: 1: the number of articles shown, 2: all articles, 3: the number not shown. */
				__( 'Showing %1$s of %2$s articles, the ones that differ first; the other %3$s are listed by both. The other plugin’s feed may be cached, which can explain an article it is missing.', 'estasoft-news-distribution' ),
				number_format_i18n( count( $shown ) ),
				number_format_i18n( $total ),
				number_format_i18n( $total - count( $shown ) )
			)
			: __( 'The other plugin’s feed may be cached, which can explain an article it is missing.', 'estasoft-news-distribution' );

		return $summary
			. self::grid(
				'estasoft-nd-cols-compare',
				array(
					__( 'Article', 'estasoft-news-distribution' ),
					__( 'GN Publisher', 'estasoft-news-distribution' ),
					__( 'News Distribution', 'estasoft-news-distribution' ),
					__( 'Difference', 'estasoft-news-distribution' ),
				),
				$rows,
				__( 'Articles from the last 48 hours in the two lists', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					$footer . ' ' . sprintf(
						/* translators: %s: a time such as 14:08. */
						__( 'Compared at %s.', 'estasoft-news-distribution' ),
						Time::format( (int) $compare['ran_at'] )
					)
				)
			);
	}

	/**
	 * Step 3.
	 *
	 * @param GnPublisher $gn The helper.
	 * @return string
	 */
	private function step_turn_off( GnPublisher $gn ): string {
		$active = null !== $gn->plugin();
		$text   = $active
			? __( 'News Distribution never changes another plugin’s settings, so you do this part. Open the settings of the GN Publisher plugin and turn off its Google News sitemap and its feed, or deactivate the plugin.', 'estasoft-news-distribution' )
			: __( 'News Distribution never changes another plugin’s settings, so you do this part. GN Publisher is not active on this site. If another plugin prints the news sitemap, turn it off in that plugin.', 'estasoft-news-distribution' );

		$html = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html__( 'If anything else reads the other plugin’s feed, such as a Google Publisher Center source or a feed reader, point it at a News Distribution address first:', 'estasoft-news-distribution' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=estasoft-nd-channels' ) ) . '">' . esc_html__( 'Channels shows them.', 'estasoft-news-distribution' ) . '</a>'
			);

		if ( $active ) {
			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster' ),
				Button::link( __( 'Open the plugin’s settings', 'estasoft-news-distribution' ), $gn->settings_url() )
			);
		}

		return $html . Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
			Html::void(
				'input',
				array(
					'type'               => 'checkbox',
					'data-gn-turned-off' => '1',
					'aria-describedby'   => 'estasoft-nd-gn-turned-off-help',
				)
			) . Html::tag(
				'span',
				array(),
				Html::tag( 'strong', array(), esc_html__( 'I turned it off', 'estasoft-news-distribution' ) )
				. '<br>' . Html::tag(
					'span',
					array(
						'class' => 'estasoft-nd-muted',
						'id'    => 'estasoft-nd-gn-turned-off-help',
					),
					esc_html__( 'This opens step 4. It does not check anything; step 5 does.', 'estasoft-news-distribution' )
				)
			)
		);
	}

	/**
	 * Step 4.
	 *
	 * @param GnPublisher       $gn     The helper.
	 * @param array<int, array> $others What was found that is not News Distribution's.
	 * @return string
	 */
	private function step_answer( GnPublisher $gn, array $others ): string {
		$path = $gn->old_path();

		if ( '' === $path ) {
			$path = '/news-sitemap.xml';

			foreach ( $others as $item ) {
				if ( 'news_sitemap' === $item['type'] && '' !== (string) $item['path'] ) {
					$path = (string) $item['path'];
					break;
				}
			}
		}

		$fields = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'answer_old_address',
				'value' => '0',
			)
		)
			. Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => 'answer_old_address',
						'value'   => '1',
						'checked' => (bool) Plugin::instance()->settings()->get( 'compatibility.answer_old_address', false ),
					)
				) . Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html__( 'Also answer at the old address', 'estasoft-news-distribution' ) )
					. '<br>' . Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-muted' ),
						esc_html(
							sprintf(
								/* translators: %s: an address such as https://example.com/news-sitemap.xml. */
								__( 'News Distribution answers at %s with its own sitemap. It does not redirect: Yandex, for one, flags a redirected sitemap as something to fix.', 'estasoft-news-distribution' ),
								home_url( $path )
							)
						)
					)
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-label',
						'for'   => 'estasoft-nd-gn-path',
					),
					esc_html__( 'Old sitemap address', 'estasoft-news-distribution' )
				)
				. Html::void(
					'input',
					array(
						'type'             => 'text',
						'id'               => 'estasoft-nd-gn-path',
						'name'             => 'old_sitemap_path',
						'class'            => 'estasoft-nd-field estasoft-nd-gn-path',
						'value'            => $path,
						'dir'              => 'ltr',
						'autocomplete'     => 'off',
						'aria-describedby' => 'estasoft-nd-gn-path-help',
					)
				)
				. Html::tag(
					'span',
					array(
						'class' => 'estasoft-nd-muted estasoft-nd-small',
						'id'    => 'estasoft-nd-gn-path-help',
					),
					esc_html__( 'The address the other plugin printed, without your site’s name, such as /news-sitemap.xml.', 'estasoft-news-distribution' )
				)
			);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-gn-answer' ), self::post_form( Actions::ACTION_SAVE, __( 'Save this setting', 'estasoft-news-distribution' ), array(), $fields ) );
	}

	/**
	 * Step 5.
	 *
	 * @param array<string, mixed>|null $check The last check.
	 * @return string
	 */
	private function step_check( ?array $check ): string {
		$run  = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster' ),
			self::post_form( Actions::ACTION_CHECK, __( 'Run the check', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
		);
		$body = Html::tag(
			'div',
			array(
				'class'     => 'estasoft-nd-gn-result',
				'aria-live' => 'polite',
			),
			$this->check_result( $check )
		);

		return $run . $body;
	}

	/**
	 * What the last check found.
	 *
	 * @param array<string, mixed>|null $check The last check.
	 * @return string
	 */
	private function check_result( ?array $check ): string {
		if ( null === $check ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The check has not run yet.', 'estasoft-news-distribution' ) );
		}

		$new_ok = ! empty( $check['new']['ok'] );

		switch ( $check['result'] ) {
			case 'ok':
				$tone  = 'success';
				$title = __( 'No problems found.', 'estasoft-news-distribution' );
				$text  = __( 'Only News Distribution answers at the old address and the new one.', 'estasoft-news-distribution' );
				break;

			case 'other_still':
				$tone  = 'warning';
				$title = __( 'The old address still answers with the other plugin’s sitemap.', 'estasoft-news-distribution' );
				$text  = __( 'Turn it off in step 3 and run the check again.', 'estasoft-news-distribution' );
				break;

			case 'unreachable':
				$tone  = 'danger';
				$title = __( 'The check could not reach your site.', 'estasoft-news-distribution' );
				$text  = __( 'Nothing was changed. Try again in a few minutes.', 'estasoft-news-distribution' );
				break;

			default:
				$tone  = 'warning';
				$title = $new_ok ? __( 'Nobody answers at the old address.', 'estasoft-news-distribution' ) : __( 'The new sitemap does not answer.', 'estasoft-news-distribution' );
				$text  = $new_ok
					? __( 'Turn on “Also answer at the old address” in step 4, save it and run the check again.', 'estasoft-news-distribution' )
					: __( 'Turn on the Google News channel and run the check again.', 'estasoft-news-distribution' );
		}

		$icons = array(
			'success' => 'check-circle',
			'warning' => 'warning',
			'danger'  => 'x-circle',
		);

		$line = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-gn-result__line' ),
			Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . $tone ),
				Icon::svg( $icons[ $tone ] )
			) . Html::tag( 'span', array(), Html::tag( 'strong', array(), esc_html( $title ) ) . ' ' . esc_html( $text ) )
		);

		$detail = '';

		if ( 'unreachable' !== $check['result'] ) {
			$by = array(
				'us'     => __( 'answered by News Distribution', 'estasoft-news-distribution' ),
				'other'  => __( 'answered by another plugin', 'estasoft-news-distribution' ),
				'nobody' => __( 'no sitemap', 'estasoft-news-distribution' ),
			);

			$detail = Html::tag(
				'ul',
				array( 'class' => 'estasoft-nd-gn-result__detail estasoft-nd-muted estasoft-nd-small' ),
				Html::tag(
					'li',
					array(),
					esc_html(
						sprintf(
							/* translators: 1: an address, 2: an HTTP status number, 3: who answered, such as "answered by News Distribution". */
							__( 'Old address %1$s: HTTP %2$s, %3$s.', 'estasoft-news-distribution' ),
							(string) $check['old']['url'],
							(string) (int) $check['old']['status'],
							$by[ $check['old']['answered_by'] ] ?? $by['nobody']
						)
					)
				)
				. ( '' !== (string) $check['new']['url'] ? Html::tag(
					'li',
					array(),
					esc_html(
						sprintf(
							/* translators: 1: an address, 2: an HTTP status number. */
							__( 'New sitemap %1$s: HTTP %2$s.', 'estasoft-news-distribution' ),
							(string) $check['new']['url'],
							(string) (int) $check['new']['status']
						)
					)
				) : '' )
			);
		}

		return $line . $detail . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html(
				sprintf(
					/* translators: %s: a time such as 14:08. */
					__( 'Last checked %s.', 'estasoft-news-distribution' ),
					Time::format( (int) $check['ran_at'] )
				)
			)
		);
	}
}
