<?php
/**
 * The Assign tab: the problems found in one article, and who is fixing each.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Control;

use Estasoft\NewsDistribution\Admin\Check\Times;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Checks\ArticleChecker;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Fixes;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Draws what `Core\Fixes` keeps. An editor sees every problem in the latest check and who has it; an author
 * sees only the fixes handed to them, and can mark those done.
 *
 * "Assign" and "Reassign" do not post at once: they open the form below the list, filled in for that
 * problem, so the note and the email are chosen before anyone is told. The address says which problem:
 * `&assign=<code>`, and `&person=<id>` for the person already chosen in the list.
 */
final class AssignView {

	private Plugin $plugin;

	private Fixes $fixes;

	private int $post_id;

	private string $url;

	/**
	 * @param Plugin $plugin  The running plugin.
	 * @param int    $post_id Article ID.
	 * @param string $url     The tab's own address, where its forms post.
	 */
	public function __construct( Plugin $plugin, int $post_id, string $url ) {
		$this->plugin  = $plugin;
		$this->fixes   = $plugin->make( Fixes::class );
		$this->post_id = $post_id;
		$this->url     = $url;
	}

	/**
	 * The whole tab as escaped HTML.
	 *
	 * @return string
	 */
	public function render(): string {
		if ( ! $this->plugin->access()->can( Access::VIEW, $this->post_id ) ) {
			return Html::tag( 'div', array( 'class' => 'estasoft-nd-ctl-stack' ), Flash::take() . $this->own_card() );
		}

		$rows   = $this->rows();
		$filter = $this->filter();
		$shown  = array_values(
			array_filter(
				$rows,
				function ( array $row ) use ( $filter ): bool {
					if ( 'mine' === $filter ) {
						return null !== $row['fix'] && get_current_user_id() === (int) $row['fix']['assignee']['id'];
					}

					return 'open' !== $filter || null === $row['fix'];
				}
			)
		);

		$left = $this->filters( $rows ) . $this->list_card( $rows, $shown ) . $this->form_card( $rows );
		$side = $this->people_card( $rows ) . Card::cannot( array( __( 'Assigning sends a note and keeps track. It cannot make anyone fix the problem, and it does not change the article.', 'estasoft-news-distribution' ) ) );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ctl-stack' ),
			Flash::take() . Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-split' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $left )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-split__side estasoft-nd-split__side--wide' ), $side )
			)
		);
	}

	// ---------------------------------------------------------------------
	// What there is to show.
	// ---------------------------------------------------------------------

	/**
	 * One row for each problem in the latest check, and one for each open fix whose problem is gone.
	 *
	 * @return array<int, array{code: string, message: string, affects: string, found: bool, fix: array<string, mixed>|null}>
	 */
	private function rows(): array {
		$report = $this->plugin->make( ArticleChecker::class )->stored( $this->post_id );
		$open   = array();

		foreach ( $this->fixes->for_article( $this->post_id ) as $fix ) {
			if ( Fixes::OPEN === $fix['status'] && ! isset( $open[ $fix['code'] ] ) ) {
				$open[ $fix['code'] ] = $fix;
			}
		}

		$rows = array();
		$seen = array();

		foreach ( null === $report ? array() : $report->findings() as $finding ) {
			$code = (string) ( $finding['code'] ?? '' );

			if ( '' === $code || isset( $seen[ $code ] ) ) {
				continue;
			}

			$seen[ $code ] = true;
			$rows[]        = array(
				'code'    => $code,
				'message' => (string) $finding['message'],
				'affects' => $this->affects( (array) ( $finding['channels'] ?? array() ) ),
				'found'   => true,
				'fix'     => $open[ $code ] ?? null,
			);
		}

		foreach ( $open as $code => $fix ) {
			if ( ! isset( $seen[ $code ] ) ) {
				$rows[] = array(
					'code'    => (string) $code,
					'message' => (string) $fix['message'],
					'affects' => '',
					'found'   => false,
					'fix'     => $fix,
				);
			}
		}

		return $rows;
	}

	/**
	 * Which channels a problem holds back, in words.
	 *
	 * @param string[] $ids Channel ids; empty for every channel that is on.
	 * @return string
	 */
	private function affects( array $ids ): string {
		if ( array() === $ids ) {
			return __( 'Affects every channel that is on.', 'estasoft-news-distribution' );
		}

		$names = array();

		foreach ( $ids as $id ) {
			$channel = $this->plugin->registry()->get( (string) $id );

			if ( null !== $channel ) {
				$names[] = $channel->label();
			}
		}

		return array() === $names ? '' : sprintf(
			/* translators: %s: channel names such as "SmartNews and Microsoft Start". */
			__( 'Affects %s.', 'estasoft-news-distribution' ),
			wp_sprintf( '%l', $names )
		);
	}

	/**
	 * The filter in the address: all, mine or open.
	 *
	 * @return string
	 */
	private function filter(): string {
		$value = Query::key( 'filter', 'all' );

		return in_array( $value, array( 'mine', 'open' ), true ) ? $value : 'all';
	}

	// ---------------------------------------------------------------------
	// The list.
	// ---------------------------------------------------------------------

	/**
	 * The filter: all problems, the ones assigned to me, the ones nobody has.
	 *
	 * @param array<int, array<string, mixed>> $rows Every row.
	 * @return string Escaped HTML.
	 */
	private function filters( array $rows ): string {
		$me   = get_current_user_id();
		$mine = 0;
		$open = 0;

		foreach ( $rows as $row ) {
			if ( null === $row['fix'] ) {
				++$open;
			} elseif ( $me === (int) $row['fix']['assignee']['id'] ) {
				++$mine;
			}
		}

		$current = $this->filter();
		$links   = '';

		foreach ( array(
			'all'  => sprintf(
				/* translators: %d: how many problems. */
				__( 'All problems (%d)', 'estasoft-news-distribution' ),
				count( $rows )
			),
			'mine' => sprintf(
				/* translators: %d: how many problems. */
				__( 'Assigned to me (%d)', 'estasoft-news-distribution' ),
				$mine
			),
			'open' => sprintf(
				/* translators: %d: how many problems. */
				__( 'Not assigned (%d)', 'estasoft-news-distribution' ),
				$open
			),
		) as $key => $label ) {
			$links .= Html::tag(
				'a',
				array(
					'href'         => 'all' === $key ? $this->url : add_query_arg( 'filter', $key, $this->url ),
					'class'        => $current === $key ? 'is-on' : null,
					'aria-current' => $current === $key ? 'true' : null,
				),
				esc_html( $label )
			);
		}

		$user = wp_get_current_user();

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--padded estasoft-nd-ctl-filters' ),
			Html::tag(
				'nav',
				array(
					'class'      => 'estasoft-nd-seg',
					'aria-label' => __( 'Show problems', 'estasoft-news-distribution' ),
				),
				$links
			)
			. Form::note(
				sprintf(
					/* translators: %s: the signed-in person's name. */
					__( 'Counts are for this article. Assigned to me means assigned to %s, who is signed in.', 'estasoft-news-distribution' ),
					$user->display_name
				)
			)
		);
	}

	/**
	 * The list of problems.
	 *
	 * @param array<int, array<string, mixed>> $rows  Every row.
	 * @param array<int, array<string, mixed>> $shown The rows the filter keeps.
	 * @return string Escaped HTML.
	 */
	private function list_card( array $rows, array $shown ): string {
		$assigned = 0;

		foreach ( $rows as $row ) {
			if ( null !== $row['fix'] ) {
				++$assigned;
			}
		}

		if ( array() === $rows ) {
			$report = $this->plugin->make( ArticleChecker::class )->stored( $this->post_id );

			return Card::flush(
				'assign-list',
				__( 'Problems in this article', 'estasoft-news-distribution' ),
				'',
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-pad estasoft-nd-muted' ),
					esc_html(
						null === $report
							? __( 'This article has not been checked yet, so there is nothing to assign. Run the check from the Checks tab.', 'estasoft-news-distribution' )
							: __( 'The latest check found no problems, so there is nothing to assign.', 'estasoft-news-distribution' )
					)
				)
			);
		}

		$intro = sprintf(
			/* translators: 1: how many problems, 2: how many are assigned, 3: how many are not assigned. */
			__( '%1$s. %2$d assigned, %3$d not assigned.', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %d: how many problems. */
				_n( '%d problem', '%d problems', count( $rows ), 'estasoft-news-distribution' ),
				count( $rows )
			),
			$assigned,
			count( $rows ) - $assigned
		) . ' ' . __( 'Remind sends the person the same message again. Reassign lets you choose someone else.', 'estasoft-news-distribution' );

		$html = '';

		foreach ( $shown as $row ) {
			$html .= $this->row( $row );
		}

		if ( '' === $html ) {
			$html = Html::tag( 'p', array( 'class' => 'estasoft-nd-pad estasoft-nd-muted' ), esc_html__( 'No problem matches this filter.', 'estasoft-news-distribution' ) );
		}

		return Card::flush( 'assign-list', __( 'Problems in this article', 'estasoft-news-distribution' ), $intro, $html );
	}

	/**
	 * One problem.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return string Escaped HTML.
	 */
	private function row( array $row ): string {
		$fix  = $row['fix'];
		$what = Html::tag( 'strong', array( 'class' => 'estasoft-nd-ctl-fix__title' ), esc_html( (string) $row['message'] ) );

		if ( '' !== $row['affects'] ) {
			$what .= Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $row['affects'] ) );
		}

		if ( ! $row['found'] ) {
			$what .= Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The latest check no longer finds this. Mark it done when you have seen it is fixed.', 'estasoft-news-distribution' ) );
		}

		if ( null !== $fix && '' !== $fix['note'] ) {
			$what .= Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: %s: the note written when the fix was assigned. */
						__( 'Note: %s', 'estasoft-news-distribution' ),
						(string) $fix['note']
					)
				)
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ctl-fix' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-ctl-fix__what' ), $what )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ctl-fix__who' ), $this->who( $fix ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-ctl-fix__do' ), $this->actions( $row ) )
		);
	}

	/**
	 * The person who has the fix, and since when.
	 *
	 * @param array<string, mixed>|null $fix The open fix, or null.
	 * @return string Escaped HTML.
	 */
	private function who( ?array $fix ): string {
		if ( null === $fix ) {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not assigned', 'estasoft-news-distribution' ) );
		}

		$name = (string) $fix['assignee']['name'];
		$you  = get_current_user_id() === (int) $fix['assignee']['id'];
		$line = sprintf(
			/* translators: %s: a time such as 14:10. */
			__( 'Assigned %s', 'estasoft-news-distribution' ),
			Times::at( (int) $fix['created_ts'] )
		);

		if ( (int) $fix['due_ts'] > 0 ) {
			$line .= ' ' . sprintf(
				/* translators: %s: a date or time. */
				__( 'Due %s', 'estasoft-news-distribution' ),
				Times::at( (int) $fix['due_ts'] )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ctl-person' ),
			$this->avatar( $name, $you )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'strong',
					array(),
					esc_html(
						$you
							? sprintf(
								/* translators: %s: a person's name. */
								__( '%s (you)', 'estasoft-news-distribution' ),
								$name
							)
							: $name
					)
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-ctl-since' ), Icon::svg( 'info' ) . esc_html( $line ) )
			)
		);
	}

	/**
	 * A circle with a person's initials.
	 *
	 * @param string $name The name.
	 * @param bool   $you  Whether it is the person looking.
	 * @return string Escaped HTML.
	 */
	private function avatar( string $name, bool $you ): string {
		$letters = '';

		$words = preg_split( '/\s+/u', trim( $name ) );

		foreach ( array_slice( is_array( $words ) ? $words : array(), 0, 2 ) as $word ) {
			$letters .= mb_strtoupper( mb_substr( (string) $word, 0, 1 ) );
		}

		return Html::tag(
			'span',
			array(
				'class'       => 'estasoft-nd-ctl-avatar' . ( $you ? ' estasoft-nd-ctl-avatar--you' : '' ),
				'aria-hidden' => 'true',
			),
			esc_html( $letters )
		);
	}

	/**
	 * The buttons of one row.
	 *
	 * @param array<string, mixed> $row The row.
	 * @return string Escaped HTML.
	 */
	private function actions( array $row ): string {
		$fix = $row['fix'];

		if ( null === $fix ) {
			return $this->choose( (string) $row['code'] );
		}

		$html = $this->post_button( 'remind', (int) $fix['id'], __( 'Remind', 'estasoft-news-distribution' ) )
			. Button::link(
				__( 'Reassign', 'estasoft-news-distribution' ),
				add_query_arg( 'assign', rawurlencode( (string) $row['code'] ), $this->url ) . '#estasoft-nd-card-assign-form',
				array( 'size' => 'sm' )
			)
			. $this->post_button( 'done', (int) $fix['id'], __( 'Mark done', 'estasoft-news-distribution' ) );

		return $html;
	}

	/**
	 * A small button that posts one fix.
	 *
	 * @param string $what  The form: remind or done.
	 * @param int    $fix   Fix ID.
	 * @param string $label Button text.
	 * @return string Escaped HTML.
	 */
	private function post_button( string $what, int $fix, string $label ): string {
		return Form::open(
			$this->url,
			$what,
			$this->post_id,
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => Form::name( 'fix' ),
					'value' => (string) $fix,
				)
			) . Button::submit( $label, array( 'size' => 'sm' ) ),
			array( 'class' => 'estasoft-nd-ctl-inline' )
		);
	}

	/**
	 * "Choose a person" and Assign, for a problem nobody has. It opens the form below with the person chosen.
	 *
	 * @param string $code The problem.
	 * @return string Escaped HTML.
	 */
	private function choose( string $code ): string {
		$people = array( '' => __( 'Choose a person', 'estasoft-news-distribution' ) ) + $this->fixes->assignable( $this->post_id );
		$id     = 'estasoft-nd-ctl-person-' . md5( $code );

		return Html::tag(
			'form',
			array(
				'method' => 'get',
				'action' => admin_url( 'admin.php' ),
				'class'  => 'estasoft-nd-ctl-choose',
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'page',
					'value' => 'estasoft-nd-check',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'post',
					'value' => (string) $this->post_id,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'tab',
					'value' => 'assign',
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'assign',
					'value' => $code,
				)
			)
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => $id,
				),
				esc_html__( 'Person to assign this problem to', 'estasoft-news-distribution' )
			)
			. Form::select( $id, 'person', $people, '' )
			. Button::submit( __( 'Assign', 'estasoft-news-distribution' ), array( 'size' => 'sm' ) )
		);
	}

	// ---------------------------------------------------------------------
	// The form and the side.
	// ---------------------------------------------------------------------

	/**
	 * The "Assign a fix" form, open for the problem the address names.
	 *
	 * @param array<int, array<string, mixed>> $rows Every row.
	 * @return string Escaped HTML, or an empty string.
	 */
	private function form_card( array $rows ): string {
		$code = Query::text( 'assign' );
		$row  = null;

		foreach ( $rows as $candidate ) {
			if ( $candidate['code'] === $code && $candidate['found'] ) {
				$row = $candidate;
			}
		}

		if ( null === $row ) {
			return '';
		}

		$people = $this->fixes->assignable( $this->post_id );
		$chosen = Query::int( 'person' );

		if ( ! isset( $people[ $chosen ] ) ) {
			$chosen = null !== $row['fix'] ? (int) $row['fix']['assignee']['id'] : 0;
		}

		if ( ! isset( $people[ $chosen ] ) ) {
			$author = (int) get_post_field( 'post_author', $this->post_id );
			$chosen = isset( $people[ $author ] ) ? $author : (int) array_key_first( $people );
		}

		$note = null !== $row['fix'] ? (string) $row['fix']['note'] : '';

		$body = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Form::name( 'code' ),
				'value' => (string) $row['code'],
			)
		)
			. Form::row( 'estasoft-nd-ctl-assignee', __( 'Person', 'estasoft-news-distribution' ), Form::select( 'estasoft-nd-ctl-assignee', Form::name( 'assignee' ), $people, $chosen ), __( 'Only people with access to Article check can be assigned a fix.', 'estasoft-news-distribution' ) )
			. Form::row(
				'estasoft-nd-ctl-note',
				__( 'Note', 'estasoft-news-distribution' ),
				Html::tag(
					'textarea',
					array(
						'id'        => 'estasoft-nd-ctl-note',
						'name'      => Form::name( 'note' ),
						'class'     => 'estasoft-nd-field estasoft-nd-w-full',
						'rows'      => '3',
						'maxlength' => (string) Fixes::NOTE_MAX,
					),
					esc_textarea( $note )
				),
				__( 'Optional. It is added to the message the person gets.', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-row' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-form-label' ), esc_html__( 'Tell them by', 'estasoft-news-distribution' ) )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-form-control' ),
					Html::void(
						'input',
						array(
							'type'  => 'hidden',
							'name'  => Form::name( 'email' ),
							'value' => '0',
						)
					)
					. Html::tag(
						'label',
						array(
							'class' => 'estasoft-nd-check',
							'for'   => 'estasoft-nd-ctl-email',
						),
						Html::void(
							'input',
							array(
								'type'    => 'checkbox',
								'id'      => 'estasoft-nd-ctl-email',
								'name'    => Form::name( 'email' ),
								'value'   => '1',
								'checked' => true,
							)
						) . Html::tag( 'span', array(), esc_html__( 'Email', 'estasoft-news-distribution' ) )
					)
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ctl-foot' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Button::submit( __( 'Assign', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
					. Button::link( __( 'Cancel', 'estasoft-news-distribution' ), $this->url )
				)
			);

		return Card::flush(
			'assign-form',
			__( 'Assign a fix', 'estasoft-news-distribution' ),
			sprintf(
				/* translators: %s: the problem, such as "The image has no credit." */
				__( 'It is filled in for: %s', 'estasoft-news-distribution' ),
				(string) $row['message']
			),
			Form::open( $this->url, 'assign', $this->post_id, $body )
		);
	}

	/**
	 * "Assigned fixes": who has how many, and since when.
	 *
	 * @param array<int, array<string, mixed>> $rows Every row.
	 * @return string Escaped HTML.
	 */
	private function people_card( array $rows ): string {
		$people = array();

		foreach ( $rows as $row ) {
			$fix = $row['fix'];

			if ( null === $fix ) {
				continue;
			}

			$id = (int) $fix['assignee']['id'];

			if ( ! isset( $people[ $id ] ) ) {
				$people[ $id ] = array(
					'name'  => (string) $fix['assignee']['name'],
					'count' => 0,
					'since' => (int) $fix['created_ts'],
				);
			}

			++$people[ $id ]['count'];
			$people[ $id ]['since'] = min( $people[ $id ]['since'], (int) $fix['created_ts'] );
		}

		$html = '';

		foreach ( $people as $id => $person ) {
			$you   = get_current_user_id() === $id;
			$html .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-ctl-person' ),
				$this->avatar( $person['name'], $you )
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
					Html::tag(
						'strong',
						array(),
						esc_html(
							$you
								? sprintf(
									/* translators: %s: a person's name. */
									__( '%s (you)', 'estasoft-news-distribution' ),
									$person['name']
								)
								: $person['name']
						)
					)
					. Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
						esc_html(
							sprintf(
								/* translators: 1: how many fixes, 2: a time such as 14:10. */
								_n( '%1$d fix, since %2$s', '%1$d fixes, since %2$s', (int) $person['count'], 'estasoft-news-distribution' ),
								(int) $person['count'],
								Times::at( (int) $person['since'] )
							)
						)
					)
				)
			);
		}

		return Card::note(
			'assign-people',
			__( 'Assigned fixes', 'estasoft-news-distribution' ),
			( '' === $html ? Form::note( __( 'Nobody has a fix for this article yet.', 'estasoft-news-distribution' ) ) : Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $html ) )
			. Form::note( __( 'Only people with access to Article check can be assigned a fix.', 'estasoft-news-distribution' ) )
		);
	}

	// ---------------------------------------------------------------------
	// An author's view.
	// ---------------------------------------------------------------------

	/**
	 * The fixes handed to the signed-in author for this article.
	 *
	 * @return string Escaped HTML.
	 */
	private function own_card(): string {
		$html = '';

		foreach ( $this->fixes->for_article( $this->post_id ) as $fix ) {
			if ( Fixes::OPEN !== $fix['status'] ) {
				continue;
			}

			$from = sprintf(
				/* translators: 1: a person's name, 2: a time such as 14:10. */
				__( 'From %1$s, %2$s', 'estasoft-news-distribution' ),
				(string) $fix['assigned_by']['name'],
				Times::at( (int) $fix['created_ts'] )
			);
			$what  = Html::tag( 'strong', array( 'class' => 'estasoft-nd-ctl-fix__title' ), esc_html( (string) $fix['message'] ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $from ) );
			$what .= '' === $fix['note'] ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( (string) $fix['note'] ) );

			$edit = current_user_can( 'edit_post', $this->post_id ) ? Button::link( __( 'Edit post', 'estasoft-news-distribution' ), (string) get_edit_post_link( $this->post_id, 'raw' ), array( 'size' => 'sm' ) ) : '';

			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ctl-fix estasoft-nd-ctl-fix--own' ),
				Html::tag( 'div', array( 'class' => 'estasoft-nd-ctl-fix__what' ), $what )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-ctl-fix__do' ), $edit . $this->post_button( 'done', (int) $fix['id'], __( 'Mark done', 'estasoft-news-distribution' ) ) )
			);
		}

		if ( '' === $html ) {
			$html = Html::tag( 'p', array( 'class' => 'estasoft-nd-pad estasoft-nd-muted' ), esc_html__( 'Nobody has asked you to fix anything on this article.', 'estasoft-news-distribution' ) );
		}

		return Card::flush(
			'assign-own',
			__( 'Fixes assigned to you', 'estasoft-news-distribution' ),
			__( 'An editor asked you to look at these. Mark one done when you have fixed it.', 'estasoft-news-distribution' ),
			$html
		);
	}
}
