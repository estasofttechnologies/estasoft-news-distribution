<?php
/**
 * Article check for a person who may only see their own articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Check;

use Estasoft\NewsDistribution\Admin\CheckTabs\AbstractCheckTab;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\PageHeader;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\ArticleService;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Db\Schema;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Shown to an Author or a Contributor when Settings, Access lets them open Article check
 * ("their own articles"). They see their own articles, the fixes assigned to them and a plain
 * account of what their role can and cannot do. Nothing about anyone else's article is read:
 * the list comes from `Core\ArticleService::list()`, which limits it to the person's own.
 */
final class AuthorView {

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The screen: heading, who you are, your articles, the role table, and two notes.
	 *
	 * @param Links $links Addresses of other screens.
	 * @return string Escaped HTML.
	 */
	public function render( Links $links ): string {
		$user = wp_get_current_user();

		return PageHeader::render( __( 'Article check', 'estasoft-news-distribution' ) )
			. Message::bar(
				'info',
				esc_html(
					sprintf(
					/* translators: 1: the person's name, 2: their role such as "Author". */
						__( 'You are signed in as %1$s (%2$s). You see your own articles.', 'estasoft-news-distribution' ),
						$user->display_name,
						$this->role_name( $user )
					)
				)
			)
			. $this->articles( $user->ID )
			. $this->matrix( $links )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $this->how_it_works() . Card::cannot( $this->cannot() ) );
	}

	/**
	 * The word for the person's role.
	 *
	 * @param \WP_User $user The person.
	 * @return string
	 */
	private function role_name( \WP_User $user ): string {
		$names = wp_roles()->get_names();
		$role  = (string) reset( $user->roles );

		return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : __( 'No role', 'estasoft-news-distribution' );
	}

	/**
	 * "Your articles": each article, its result, what to do and who assigned it.
	 *
	 * @param int $user_id The person.
	 * @return string Escaped HTML.
	 */
	private function articles( int $user_id ): string {
		$list = $this->plugin->make( ArticleService::class )->list(
			array(
				'page'     => 1,
				'per_page' => 20,
			)
		);

		if ( array() === $list['items'] ) {
			return EmptyState::render(
				__( 'You have no articles yet', 'estasoft-news-distribution' ),
				__( 'When you write one, its checks appear here.', 'estasoft-news-distribution' )
			);
		}

		$assigned = $this->assigned( $user_id );
		$rows     = array();

		foreach ( $list['items'] as $item ) {
			$id     = (int) $item['id'];
			$report = $this->plugin->make( ArticleChecker::class )->stored( $id );
			$cell   = $this->todo( null === $report ? array() : Findings::sorted( $report->findings() ), $id );

			$rows[] = array(
				Html::tag(
					'a',
					array(
						'class' => 'estasoft-nd-strong',
						'href'  => add_query_arg(
							array(
								'page' => ArticleList::PAGE,
								'post' => $id,
							),
							admin_url( 'admin.php' )
						),
						'lang'  => (string) $item['language'],
						'dir'   => 'auto',
					),
					esc_html( '' === (string) $item['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : (string) $item['title'] )
				),
				self::labelled( __( 'Result', 'estasoft-news-distribution' ), $this->result( $item ) ),
				self::labelled( __( 'What to do', 'estasoft-news-distribution' ), $cell ),
				self::labelled(
					__( 'Assigned by', 'estasoft-news-distribution' ),
					isset( $assigned[ $id ] ) ? esc_html( $assigned[ $id ] ) : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not assigned', 'estasoft-news-distribution' ) )
				),
			);
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-card-yours',
				'aria-labelledby' => 'estasoft-nd-card-yours',
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card-head' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => 'estasoft-nd-card-yours',
					),
					esc_html__( 'Your articles', 'estasoft-news-distribution' )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Only the articles you wrote, and the fixes assigned to you.', 'estasoft-news-distribution' ) )
			)
			. Grid::render(
				'estasoft-nd-cols-yours',
				array(
					__( 'Article', 'estasoft-news-distribution' ),
					__( 'Result', 'estasoft-news-distribution' ),
					__( 'What to do', 'estasoft-news-distribution' ),
					__( 'Assigned by', 'estasoft-news-distribution' ),
				),
				$rows,
				__( 'Your articles', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * A cell with the name of its column in front of it. The name is hidden on a wide screen, where the
	 * column heading says it, and shown on a phone, where each row stacks and the heading row is hidden.
	 *
	 * @param string $label The column name.
	 * @param string $html  The cell, escaped HTML.
	 * @return string Escaped HTML.
	 */
	private static function labelled( string $label, string $html ): string {
		if ( '' === $label ) {
			return $html;
		}

		return Html::tag( 'span', array( 'class' => 'estasoft-nd-cell-label' ), esc_html( $label ) ) . Html::tag( 'div', array( 'class' => 'estasoft-nd-cell-value' ), $html );
	}

	/**
	 * The result of an article in a few words.
	 *
	 * @param array<string, mixed> $item The article summary.
	 * @return string Escaped HTML.
	 */
	private function result( array $item ): string {
		$counts = (array) $item['check']['counts'];
		$block  = (int) ( $counts[ Status::BLOCK ] ?? 0 );
		$to_fix = $block + (int) ( $counts[ Status::WARN ] ?? 0 );
		$skips  = count( (array) $item['check']['skipped_on'] );

		if ( null === $item['check']['checked'] ) {
			$tone = 'neutral';
			$icon = 'minus-circle';
			$text = __( 'Not checked yet', 'estasoft-news-distribution' );
		} elseif ( $skips > 0 ) {
			$tone = 'danger';
			$icon = 'x-circle';
			$text = sprintf(
				/* translators: %s: a number of channels. */
				_n( 'Skipped by %s channel', 'Skipped by %s channels', $skips, 'estasoft-news-distribution' ),
				number_format_i18n( $skips )
			);
		} elseif ( $to_fix > 0 ) {
			$tone = 'warning';
			$icon = 'warning';
			$text = sprintf(
				/* translators: %s: a number of things to fix. */
				_n( '%s to fix', '%s to fix', $to_fix, 'estasoft-news-distribution' ),
				number_format_i18n( $to_fix )
			);
		} else {
			$tone = 'success';
			$icon = 'check-circle';
			$text = __( 'No problems', 'estasoft-news-distribution' );
		}

		return Html::tag( 'span', array( 'class' => 'estasoft-nd-status estasoft-nd-status--' . $tone ), Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( $text ) ) );
	}

	/**
	 * The one or two things an author should do about an article.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings, most severe first.
	 * @param int                              $post_id  The article.
	 * @return string Escaped HTML.
	 */
	private function todo( array $findings, int $post_id ): string {
		$important = array_values(
			array_filter(
				$findings,
				static function ( array $finding ): bool {
					return Status::INFO !== ( $finding['severity'] ?? '' );
				}
			)
		);

		if ( array() === $important ) {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing to do', 'estasoft-news-distribution' ) );
		}

		return Findings::list( array_slice( $important, 0, 2 ), $post_id );
	}

	/**
	 * Fixes assigned to a person, by article: who assigned them and by when.
	 *
	 * Reads the `fixes` table, then lets the feature that owns assigning change the answer.
	 *
	 * @param int $user_id The person.
	 * @return array<int, string> Article ID => "Sara Haddad, by today 17:00".
	 */
	private function assigned( int $user_id ): array {
		global $wpdb;

		$table = Schema::table( 'fixes' );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT post_id, assigned_by, due_gmt FROM %i WHERE assignee_id = %d AND status = 'open' ORDER BY id DESC LIMIT 100", $table, $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out   = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$post_id = (int) $row['post_id'];

			if ( isset( $out[ $post_id ] ) ) {
				continue;
			}

			$by  = get_userdata( (int) $row['assigned_by'] );
			$due = null === $row['due_gmt'] ? 0 : Clock::from_gmt( (string) $row['due_gmt'] );
			$who = $by instanceof \WP_User ? $by->display_name : __( 'Someone on your team', 'estasoft-news-distribution' );

			$out[ $post_id ] = $due > 0
				? sprintf(
					/* translators: 1: a person's name, 2: a date and time such as "Today, 17:00". */
					__( '%1$s, by %2$s', 'estasoft-news-distribution' ),
					$who,
					lcfirst( Times::day( $due ) )
				)
				: $who;
		}

		/**
		 * Lets the feature that assigns fixes change who is shown as having assigned them (internal).
		 *
		 * @param array<int, string> $out     Article ID => text.
		 * @param int                $user_id The person.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_assigned_fixes', $out, $user_id );

		return is_array( $filtered ) ? $filtered : $out;
	}

	/**
	 * "Who can do what", worked out from the WordPress roles and the Access settings now in force.
	 *
	 * @param Links $links Addresses of other screens.
	 * @return string Escaped HTML.
	 */
	private function matrix( Links $links ): string {
		$roles    = array(
			'administrator' => __( 'Administrator', 'estasoft-news-distribution' ),
			'editor'        => __( 'Editor', 'estasoft-news-distribution' ),
			'author'        => __( 'Author', 'estasoft-news-distribution' ),
			'contributor'   => __( 'Contributor', 'estasoft-news-distribution' ),
		);
		$access   = $this->plugin->access();
		$choice   = (string) $this->plugin->settings()->get( 'access.article_check', 'editors' );
		$yes      = __( 'Yes', 'estasoft-news-distribution' );
		$no       = __( 'No', 'estasoft-news-distribution' );
		$can      = static function ( string $role, string $capability ): bool {
			$object = get_role( $role );

			return null !== $object && $object->has_cap( $capability );
		};
		$see      = array();
		$fix      = array();
		$override = array();
		$view     = array();
		$manage   = array();
		$takedown = array();
		$tab      = $links->all_tabs()['takedown'] ?? null;

		foreach ( array_keys( $roles ) as $role ) {
			if ( 'admins' === $choice ) {
				$sees      = $can( $role, 'manage_options' );
				$see[]     = $sees ? __( 'All articles', 'estasoft-news-distribution' ) : $no;
				$fix_value = $sees;
			} elseif ( $can( $role, 'edit_others_posts' ) ) {
				$see[]     = __( 'All articles', 'estasoft-news-distribution' );
				$fix_value = true;
			} elseif ( 'authors_own' === $choice && $can( $role, 'edit_posts' ) ) {
				$see[]     = $can( $role, 'publish_posts' ) ? __( 'Own articles', 'estasoft-news-distribution' ) : __( 'Own drafts', 'estasoft-news-distribution' );
				$fix_value = true;
			} else {
				$see[]     = $no;
				$fix_value = false;
			}

			$fix[]      = $fix_value ? $yes : $no;
			$override[] = $can( $role, $access->override_capability() ) ? $yes : $no;
			$view[]     = $can( $role, $access->capability_for( Access::VIEW ) ) ? $yes : $no;
			$manage[]   = $can( $role, $access->manage_capability() ) ? $yes : $no;

			if ( $tab instanceof AbstractCheckTab ) {
				$takedown[] = $can( $role, $access->capability_for( $tab->permission() ) ) ? $yes : $no;
			}
		}

		$table = array(
			array( __( 'See Article check', 'estasoft-news-distribution' ), $see ),
			array( __( 'Fix assigned to you', 'estasoft-news-distribution' ), $fix ),
			array( __( 'Publish anyway when blocked', 'estasoft-news-distribution' ), $override ),
		);

		if ( array() !== $takedown ) {
			$table[] = array( __( 'Take an article down', 'estasoft-news-distribution' ), $takedown );
		}

		$table[] = array( __( 'Overview, Channels, Reports', 'estasoft-news-distribution' ), $view );
		$table[] = array( __( 'Delivery log', 'estasoft-news-distribution' ), $view );
		$table[] = array( __( 'Change channels and settings', 'estasoft-news-distribution' ), $manage );

		$rows = array();

		foreach ( $table as $line ) {
			$cells = array( Html::tag( 'span', array( 'class' => 'estasoft-nd-strong' ), esc_html( $line[0] ) ) );

			foreach ( array_values( $line[1] ) as $index => $value ) {
				$cells[] = self::labelled( (string) ( array_values( $roles )[ $index ] ?? '' ), esc_html( $value ) );
			}

			$rows[] = $cells;
		}

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-card-matrix',
				'aria-labelledby' => 'estasoft-nd-card-matrix',
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card-head' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => 'estasoft-nd-card-matrix',
					),
					esc_html__( 'Who can do what', 'estasoft-news-distribution' )
				)
				. Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-muted' ),
					esc_html(
						sprintf(
							/* translators: %s: the site name. */
							__( 'How %s is set up now. An administrator can change three rows in Settings, Access and gate: See Article check, Publish anyway when blocked, and Change channels and settings.', 'estasoft-news-distribution' ),
							get_bloginfo( 'name' )
						)
					)
				)
			)
			. Grid::render( 'estasoft-nd-cols-matrix', array_merge( array( '' ), array_values( $roles ) ), $rows, __( 'Who can do what', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The "How it works" card.
	 *
	 * @return string Escaped HTML.
	 */
	private function how_it_works(): string {
		$items = array(
			__( 'Open an article from the list to see its checks and what to fix. No other screen is needed.', 'estasoft-news-distribution' ),
			__( 'A Contributor sees the checks on their own drafts in the editor, because they cannot publish.', 'estasoft-news-distribution' ),
			__( 'An Author who types the address of Channels, Reports or Settings sees WordPress’s own message, plus a way back.', 'estasoft-news-distribution' ),
		);
		$list  = '';

		foreach ( $items as $item ) {
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--ok' ), Icon::svg( 'check' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return Card::note( 'how-it-works', __( 'How it works', 'estasoft-news-distribution' ), Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list ) );
	}

	/**
	 * What this screen cannot do, in plain sentences.
	 *
	 * @return string[]
	 */
	private function cannot(): array {
		return array(
			__( 'Show an Author anything about another person’s article, even a title.', 'estasoft-news-distribution' ),
			__( 'Let an Author publish past a block. They ask an Editor, who can publish anyway with a reason.', 'estasoft-news-distribution' ),
			__( 'Change what WordPress lets a role do. The roles come from WordPress, and News Distribution only reads them.', 'estasoft-news-distribution' ),
		);
	}
}
