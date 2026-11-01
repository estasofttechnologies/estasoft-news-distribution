<?php
/**
 * The News Break channel page: the application, and the rules that choose its articles.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\NewsBreak\NewsBreakChannel;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\UiLanguages;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=news-break`, with `&tab=rules` for
 * the platform rules. News Break is applied for by hand and News Distribution
 * serves no feed for it yet, so the Setup tab records the application and shows
 * the readiness checks, and the Rules tab chooses which articles would go into
 * a feed and shows what the rules do to the latest ones. Nothing here reads
 * News Break's answer or changes your own site.
 */
final class NewsBreakScreen extends AbstractPartnerScreen {

	public static function channel_id(): string {
		return 'news-break';
	}

	protected function has_feed(): bool {
		return false;
	}

	protected function tab_names(): array {
		return array( '', 'rules' );
	}

	public function description(): string {
		if ( 'rules' === $this->current_tab() ) {
			return __( 'Choose which of your articles would go to News Break. News Distribution serves no News Break feed yet, so nothing is fetched: the list shows what the rules would let through.', 'estasoft-news-distribution' );
		}

		return __( 'News Break is applied for by hand. News Distribution cannot see its answer, so you record the application here. Rules choose which articles it would receive.', 'estasoft-news-distribution' );
	}

	/**
	 * The switch between Setup and Rules.
	 *
	 * @param string $tab The tab slug.
	 * @return string
	 */
	public function actions( string $tab ): string {
		unset( $tab );

		$open  = $this->current_tab();
		$links = '';

		foreach (
			array(
				''      => __( 'Setup', 'estasoft-news-distribution' ),
				'rules' => __( 'Rules', 'estasoft-news-distribution' ),
			) as $slug => $label
		) {
			$on     = (string) $slug === $open;
			$attrs  = array(
				'href'  => $this->url( (string) $slug ),
				'class' => $on ? 'is-on' : '',
			);
			$links .= Html::tag( 'a', $on ? array_merge( $attrs, array( 'aria-current' => 'page' ) ) : $attrs, esc_html( $label ) );
		}

		return Html::tag(
			'nav',
			array(
				'class'      => 'estasoft-nd-seg',
				'aria-label' => __( 'News Break sections', 'estasoft-news-distribution' ),
			),
			$links
		);
	}

	public function render( string $tab ): string {
		return 'rules' === $this->current_tab() ? $this->render_rules() : $this->render_setup();
	}

	/**
	 * The Setup tab: the rules in brief, the application and the readiness checks.
	 *
	 * @return string
	 */
	private function render_setup(): string {
		$channel = $this->channel;

		if ( ! $channel instanceof NewsBreakChannel ) {
			return '';
		}

		$now      = Clock::now();
		$row      = $this->plugin->applications()->get( $channel->id() );
		$settings = $this->values();
		$preview  = $channel->preview( $now );
		$counts   = $preview['counts'];

		$steps = $this->steps(
			'news-break',
			__( 'Set up News Break', 'estasoft-news-distribution' ),
			array(
				array(
					'id'    => 'rules',
					'title' => __( 'Choose what it receives', 'estasoft-news-distribution' ),
					'text'  => __( 'Rules decide which of your articles would go to News Break: categories, tags, authors, post types and languages.', 'estasoft-news-distribution' ),
					'state' => array() !== (array) ( $settings['categories_include'] ?? array() ) ? 'done' : '',
					'body'  => Html::tag(
						'div',
						array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ),
						Html::tag(
							'p',
							array( 'class' => 'estasoft-nd-muted' ),
							esc_html(
								sprintf(
									/* translators: 1: the number of latest articles that pass the rules, 2: the number of latest articles checked. */
									_n( '%1$d of your last %2$d article passes the rules.', '%1$d of your last %2$d articles pass the rules.', $counts['match'], 'estasoft-news-distribution' ),
									$counts['match'],
									$counts['total']
								)
							)
						)
						. Html::tag( 'div', array(), Button::link( __( 'Open the rules', 'estasoft-news-distribution' ), $this->url( 'rules' ) ) )
					),
				),
				array(
					'id'    => 'apply',
					'title' => __( 'Apply to News Break', 'estasoft-news-distribution' ),
					'text'  => __( 'Apply on News Break’s publisher page. News Break decides, and News Distribution cannot see its answer, so you record it here.', 'estasoft-news-distribution' ),
					'state' => Status::LISTED === $row['status'] ? 'done' : 'now',
					'body'  => Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $this->application_fields( true ) ),
				),
			)
		);

		$checks = '';

		foreach ( $channel->readiness( $now ) as $check ) {
			$checks .= Html::tag( 'li', array(), StatusView::inline( (string) $check['status'], (string) $check['text'] ) );
		}

		$side = Card::note( 'ready', __( 'Readiness checks', 'estasoft-news-distribution' ), Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $checks ) )
			. $this->rules_card(
				'news-break',
				__( 'News Break', 'estasoft-news-distribution' ),
				array(
					'rules.category_not_included|rules.category_excluded' => __( 'Categories to include and to exclude', 'estasoft-news-distribution' ),
					'rules.tag_excluded'      => __( 'Tags to exclude', 'estasoft-news-distribution' ),
					'rules.author_not_listed' => __( 'Chosen authors only, when you limit them', 'estasoft-news-distribution' ),
					'rules.post_type_off|rules.language_off' => __( 'The post types and languages you ticked', 'estasoft-news-distribution' ),
					'rules.image_missing'     => __( 'A featured image, when you require one', 'estasoft-news-distribution' ),
				)
			)
			. $this->cannot_card( $this->cannot_text() );

		return $this->head( __( 'The rules still work, and the list on the Rules tab shows what they would let through.', 'estasoft-news-distribution' ) )
			. $this->save_form( $this->split( $steps, $side ) )
			. $this->forms();
	}

	/**
	 * The Rules tab.
	 *
	 * @return string
	 */
	private function render_rules(): string {
		$channel = $this->channel;

		if ( ! $channel instanceof NewsBreakChannel ) {
			return '';
		}

		$rows = $this->rows(
			array(
				array(
					'label' => __( 'Categories to include', 'estasoft-news-distribution' ),
					'html'  => function (): string {
						return $this->category_chips( 'categories_include', __( 'Add a category to include', 'estasoft-news-distribution' ), (string) $this->channel->fields()['categories_include']['help'] );
					},
				),
				array(
					'label' => __( 'Categories to exclude', 'estasoft-news-distribution' ),
					'html'  => function (): string {
						return $this->category_chips( 'categories_exclude', __( 'Add a category to exclude', 'estasoft-news-distribution' ), '' );
					},
				),
				array(
					'label' => __( 'Tags to exclude', 'estasoft-news-distribution' ),
					'html'  => function (): string {
						return $this->tag_chips();
					},
				),
				array(
					'label' => __( 'Authors', 'estasoft-news-distribution' ),
					'html'  => function (): string {
						return $this->authors_control();
					},
				),
				array(
					'label' => __( 'Post types', 'estasoft-news-distribution' ),
					'html'  => function ( array $values, array $errors ): string {
						return $this->post_type_boxes( $values, $errors );
					},
				),
				array(
					'label' => __( 'Languages', 'estasoft-news-distribution' ),
					'html'  => function ( array $values, array $errors ): string {
						return $this->language_boxes( $values, $errors );
					},
				),
				array(
					'label' => __( 'Also require', 'estasoft-news-distribution' ),
					'html'  => function (): string {
						return $this->field( 'require_image' );
					},
				),
			)
		);

		$main = Card::flush(
			'receives',
			__( 'What this channel receives', 'estasoft-news-distribution' ),
			__( 'An article goes into the feed only when it passes every rule. Your own site is never affected.', 'estasoft-news-distribution' ),
			$rows
		);

		return $this->head( __( 'The rules still work, and the list shows what they would let through.', 'estasoft-news-distribution' ) )
			. $this->save_form(
				$this->split( $main, $this->preview_card( $channel ) )
				. $this->cannot_card( $this->cannot_text() ),
				'rules',
				__( 'Save rules', 'estasoft-news-distribution' )
			)
			. $this->forms();
	}

	/**
	 * What the page says it cannot do.
	 *
	 * @return string
	 */
	private function cannot_text(): string {
		return __( 'It cannot make News Break fetch a feed or accept your articles, and News Distribution serves no News Break feed yet. Rules only decide which articles would go into one, and your own site is never affected. Rules cannot read an article’s topic: they use categories, tags, authors, post types and languages.', 'estasoft-news-distribution' );
	}

	/**
	 * "Your last 30 articles": what the saved rules do to the latest articles.
	 *
	 * @param NewsBreakChannel $channel The channel.
	 * @return string
	 */
	private function preview_card( NewsBreakChannel $channel ): string {
		$preview = $channel->preview( Clock::now() );
		$counts  = $preview['counts'];
		$shown   = 5;
		$title   = sprintf(
			/* translators: %d: the number of latest articles the list covers. */
			_n( 'Your last %d article', 'Your last %d articles', $counts['total'], 'estasoft-news-distribution' ),
			$counts['total']
		);

		if ( 0 === $counts['total'] ) {
			return Card::note( 'last', __( 'Your latest articles', 'estasoft-news-distribution' ), Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'There are no articles yet, so there is nothing to show.', 'estasoft-news-distribution' ) ) );
		}

		$summary = sprintf(
			/* translators: 1: the number of latest articles that pass the rules, 2: the number of latest articles checked. */
			_n( '%1$d of %2$d article matches these rules.', '%1$d of %2$d articles match these rules.', $counts['match'], 'estasoft-news-distribution' ),
			$counts['match'],
			$counts['total']
		) . ' ' . sprintf(
			/* translators: %d: the number of articles that would be in the feed now. */
			_n( '%d is in the feed.', '%d are in the feed.', $counts['in_feed'], 'estasoft-news-distribution' ),
			$counts['in_feed']
		);

		if ( $counts['waiting'] > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: 1: the number of articles that wait, 2: the index-first delay in minutes. */
				_n( '%1$d waits for the %2$d-minute index-first delay.', '%1$d wait for the %2$d-minute index-first delay.', $counts['waiting'], 'estasoft-news-distribution' ),
				$counts['waiting'],
				$preview['delay']
			);
		}

		if ( $counts['skipped'] > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: the number of articles the rules leave out. */
				_n( '%d is skipped.', '%d are skipped.', $counts['skipped'], 'estasoft-news-distribution' ),
				$counts['skipped']
			);
		}

		$summary .= ' ' . sprintf(
			/* translators: %d: how many of the newest articles the list shows first. */
			_n( 'Showing the newest %d.', 'Showing the newest %d.', min( $shown, $counts['total'] ), 'estasoft-news-distribution' ),
			min( $shown, $counts['total'] )
		);

		$first = '';
		$rest  = '';

		foreach ( $preview['rows'] as $index => $row ) {
			$item = $this->preview_row( $row['article'], $row['result'] );

			if ( $index < $shown ) {
				$first .= $item;
			} else {
				$rest .= $item;
			}
		}

		$body = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $summary ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The list uses the rules as last saved.', 'estasoft-news-distribution' ) )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-nb-list' ), $first );

		if ( '' !== $rest ) {
			$body .= Html::tag(
				'details',
				array( 'class' => 'estasoft-nd-which' ),
				Html::tag(
					'summary',
					array(),
					esc_html(
						sprintf(
							/* translators: %d: the number of articles. */
							_n( 'See all %d article', 'See all %d articles', $counts['total'], 'estasoft-news-distribution' ),
							$counts['total']
						)
					)
				)
				. Html::tag( 'ul', array( 'class' => 'estasoft-nd-nb-list' ), $rest )
			);
		}

		return Card::note( 'last', $title, $body );
	}

	/**
	 * One article of the list: its title, what would happen to it and why.
	 *
	 * @param \Estasoft\NewsDistribution\Core\Article $article The article.
	 * @param array<string, mixed>                    $result  What the rules say about it.
	 * @return string
	 */
	private function preview_row( $article, array $result ): string {
		$status = (string) $result['status'];
		$detail = (string) $result['detail'];
		$word   = (string) $result['text'];

		if ( Status::QUEUED === $status ) {
			$time = Clock::format( (int) $result['joins_at'], 'H:i' );
			$word = sprintf(
				/* translators: %s: a time such as 14:38. */
				__( 'Waiting %s', 'estasoft-news-distribution' ),
				$time
			);

			$detail = trim(
				$detail . ( '' === $detail ? '' : '. ' ) . sprintf(
					/* translators: %s: a time such as 14:38. */
					__( 'Joins the feed at %s.', 'estasoft-news-distribution' ),
					$time
				)
			);
		}

		return Html::tag(
			'li',
			array( 'class' => 'estasoft-nd-nb-row' ),
			Html::tag( 'strong', array( 'class' => 'estasoft-nd-nb-row__title' ), esc_html( $article->title() ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-nb-row__status' ), StatusView::inline( $status, $word ) )
			. ( '' === $detail ? '' : Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $detail ) ) )
		);
	}

	/**
	 * Category chips with a list to add another.
	 *
	 * @param string $field The channel field that holds the category ids.
	 * @param string $add   The accessible name of the list that adds one.
	 * @param string $help  A line of help under the control, or an empty string.
	 * @return string
	 */
	private function category_chips( string $field, string $add, string $help ): string {
		$values = $this->values();
		$chosen = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $values[ $field ] ?? array() ) ) ) ) );
		$names  = array();

		foreach ( get_categories( array( 'hide_empty' => false ) ) as $term ) {
			$names[ (int) $term->term_id ] = (string) $term->name;
		}

		$chips   = '';
		$options = Html::tag( 'option', array( 'value' => '' ), esc_html__( 'Add a category', 'estasoft-news-distribution' ) );

		foreach ( $chosen as $id ) {
			$chips .= $this->chip( $field, (string) $id, $names[ $id ] ?? (string) $id );
		}

		foreach ( $names as $id => $name ) {
			if ( ! in_array( $id, $chosen, true ) ) {
				$options .= Html::tag( 'option', array( 'value' => (string) $id ), esc_html( $name ) );
			}
		}

		$control = $this->marker( $field )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-chips' ), $chips )
			. Html::tag(
				'select',
				array(
					'class'          => 'estasoft-nd-field estasoft-nd-w-200',
					'name'           => 'estasoft_nd_add[' . $field . ']',
					'aria-label'     => $add,
					'data-chips-add' => $field,
				),
				$options
			);

		return $this->chips_wrap( $field, $control, $help );
	}

	/**
	 * The tags to exclude as chips, with a box to type another.
	 *
	 * @return string
	 */
	private function tag_chips(): string {
		$values = $this->values();
		$chips  = '';

		foreach ( (array) ( $values['tags_exclude'] ?? array() ) as $tag ) {
			if ( '' !== trim( (string) $tag ) ) {
				$chips .= $this->chip( 'tags_exclude', (string) $tag, (string) $tag );
			}
		}

		$control = $this->marker( 'tags_exclude' )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-chips' ), $chips )
			. Html::void(
				'input',
				array(
					'class'          => 'estasoft-nd-field estasoft-nd-w-260',
					'type'           => 'text',
					'name'           => 'estasoft_nd_add[tags_exclude]',
					'placeholder'    => __( 'Add a tag and press Enter', 'estasoft-news-distribution' ),
					'aria-label'     => __( 'Add a tag to exclude', 'estasoft-news-distribution' ),
					'autocomplete'   => 'off',
					'data-chips-add' => 'tags_exclude',
				)
			);

		return $this->chips_wrap( 'tags_exclude', $control, __( 'Write several tags with commas between them. A tag matches the way WordPress writes it, so “Wire” and “wire” are the same.', 'estasoft-news-distribution' ) );
	}

	/**
	 * One chip: a ticked box that reads as a label with a cross. Unticking it removes the value when the form is saved.
	 *
	 * @param string $field The channel field.
	 * @param string $value The value.
	 * @param string $label What the chip says.
	 * @return string
	 */
	private function chip( string $field, string $value, string $label ): string {
		return Html::tag(
			'li',
			array(),
			Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-chip' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => Field::name( $field ) . '[]',
						'value'   => $value,
						'checked' => true,
					)
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-chip__text' ), esc_html( $label ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-chip__x' ), Html::tag( 'span', array( 'aria-hidden' => 'true' ), '×' ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html__( 'Untick to remove', 'estasoft-news-distribution' ) ) )
			)
		);
	}

	/**
	 * The empty value that lets a list with every box cleared still arrive.
	 *
	 * @param string $field The channel field.
	 * @return string
	 */
	private function marker( string $field ): string {
		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( $field ),
				'value' => '',
			)
		);
	}

	/**
	 * Wraps chips and the control that adds one.
	 *
	 * @param string $field   The channel field.
	 * @param string $control Trusted HTML.
	 * @param string $help    A line of help, or an empty string.
	 * @return string
	 */
	private function chips_wrap( string $field, string $control, string $help ): string {
		$errors = $this->errors();
		$html   = Html::tag( 'div', array( 'class' => 'estasoft-nd-chip-box estasoft-nd-stack estasoft-nd-gap-8' ), $control );

		if ( isset( $errors[ $field ] ) ) {
			$html .= $this->error_line( (string) $errors[ $field ] );
		}

		return Html::tag(
			'div',
			array(
				'class'      => 'estasoft-nd-stack estasoft-nd-gap-8',
				'data-chips' => $field,
			),
			$html . ( '' === $help ? '' : Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $help ) ) )
		);
	}

	/**
	 * A message under a control.
	 *
	 * @param string $message The message.
	 * @return string
	 */
	private function error_line( string $message ): string {
		return Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-field-error',
				'role'  => 'alert',
			),
			\Estasoft\NewsDistribution\Admin\Ui\Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $message ) )
		);
	}

	/**
	 * Everyone, or only the people you choose.
	 *
	 * @return string
	 */
	private function authors_control(): string {
		$values = $this->values();
		$mode   = (string) ( $values['authors_mode'] ?? 'everyone' );
		$chosen = array_map( 'intval', (array) ( $values['authors'] ?? array() ) );
		$boxes  = $this->marker( 'authors' );

		foreach ( get_users(
			array(
				'capability' => 'edit_posts',
				'orderby'    => 'display_name',
				'order'      => 'ASC',
			)
		) as $user ) {
			$boxes .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => Field::name( 'authors' ) . '[]',
						'value'   => (string) $user->ID,
						'checked' => in_array( (int) $user->ID, $chosen, true ),
					)
				) . Html::tag( 'span', array(), esc_html( (string) $user->display_name ) )
			);
		}

		return $this->field( 'authors_mode' ) . Html::tag(
			'div',
			array(
				'class'        => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-nested',
				'role'         => 'group',
				'aria-label'   => __( 'Chosen authors', 'estasoft-news-distribution' ),
				'data-show-if' => (string) wp_json_encode( array( 'authors_mode' => 'chosen' ) ),
				'hidden'       => 'chosen' === $mode ? null : true,
			),
			$boxes
		);
	}

	/**
	 * The public post types, each with a box.
	 *
	 * @param array<string, mixed>  $values The values to show.
	 * @param array<string, string> $errors The messages of a refused save.
	 * @return string
	 */
	private function post_type_boxes( array $values, array $errors ): string {
		$chosen = array_map( 'strval', (array) ( $values['post_types'] ?? array() ) );
		$html   = $this->marker( 'post_types' );

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}

			$html .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => Field::name( 'post_types' ) . '[]',
						'value'   => $type->name,
						'checked' => in_array( $type->name, $chosen, true ),
					)
				) . Html::tag( 'strong', array(), esc_html( (string) $type->labels->name ) )
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $html . ( isset( $errors['post_types'] ) ? $this->error_line( (string) $errors['post_types'] ) : '' ) );
	}

	/**
	 * The site's languages, each with a box. A ticked box is a language News Break may receive.
	 *
	 * @param array<string, mixed>  $values The values to show.
	 * @param array<string, string> $errors The messages of a refused save.
	 * @return string
	 */
	private function language_boxes( array $values, array $errors ): string {
		$off  = array_map( 'strval', (array) ( $values['languages_off'] ?? array() ) );
		$html = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'estasoft_nd_languages_set',
				'value' => '1',
			)
		);

		foreach ( $this->plugin->languages()->languages() as $code ) {
			$code  = (string) $code;
			$html .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check' ),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'name'    => 'estasoft_nd_languages[]',
						'value'   => $code,
						'checked' => ! in_array( $code, $off, true ),
					)
				) . Html::tag(
					'strong',
					array(
						'lang' => $code,
						'dir'  => \Estasoft\NewsDistribution\Core\Language\Catalog::direction( $code ),
					),
					esc_html( UiLanguages::native_name( $code ) )
				)
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $html . ( isset( $errors['languages_off'] ) ? $this->error_line( (string) $errors['languages_off'] ) : '' ) );
	}

	protected function prepare_input( array $input, array $post, array &$errors ): array {
		$add = isset( $post['estasoft_nd_add'] ) && is_array( $post['estasoft_nd_add'] ) ? $post['estasoft_nd_add'] : array();

		foreach ( array( 'categories_include', 'categories_exclude', 'authors' ) as $name ) {
			if ( ! array_key_exists( $name, $input ) ) {
				continue;
			}

			$list   = array_map( 'absint', array_map( 'strval', (array) $input[ $name ] ) );
			$list[] = absint( (string) ( $add[ $name ] ?? 0 ) );

			$input[ $name ] = array_values( array_unique( array_filter( $list ) ) );
		}

		if ( array_key_exists( 'tags_exclude', $input ) ) {
			$input['tags_exclude'] = $this->tags_from( (array) $input['tags_exclude'], (string) ( $add['tags_exclude'] ?? '' ) );
		}

		if ( array_key_exists( 'post_types', $input ) ) {
			$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', (array) $input['post_types'] ) ) ) ) );

			if ( array() === $types ) {
				$errors['post_types'] = __( 'Choose at least one post type, or no article can pass.', 'estasoft-news-distribution' );
			}

			$input['post_types'] = $types;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The form's nonce was checked before this.
		if ( ! empty( $post['estasoft_nd_languages_set'] ) ) {
			$on  = isset( $post['estasoft_nd_languages'] ) ? array_map( 'strval', (array) $post['estasoft_nd_languages'] ) : array();
			$off = array();

			foreach ( $this->plugin->languages()->languages() as $code ) {
				if ( ! in_array( (string) $code, $on, true ) ) {
					$off[] = (string) $code;
				}
			}

			if ( count( $off ) === count( (array) $this->plugin->languages()->languages() ) && array() !== $off ) {
				$errors['languages_off'] = __( 'Leave at least one language on, or no article can pass.', 'estasoft-news-distribution' );
			}

			$input['languages_off'] = $off;
		}

		return $input;
	}

	/**
	 * The tags to exclude: the chips that stay ticked, then what was typed, without repeats.
	 *
	 * @param array<int, mixed> $kept  The ticked chips.
	 * @param string            $typed What was typed in the box, one tag or several with commas.
	 * @return string[]
	 */
	private function tags_from( array $kept, string $typed ): array {
		$parts = preg_split( '/[,\n]+/', $typed );
		$all   = array_merge( array_map( 'strval', $kept ), is_array( $parts ) ? $parts : array() );
		$seen  = array();
		$list  = array();

		foreach ( $all as $tag ) {
			$tag = trim( sanitize_text_field( (string) $tag ) );
			$key = strtolower( $tag );

			if ( '' === $tag || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$list[]       = $tag;
		}

		return $list;
	}
}
