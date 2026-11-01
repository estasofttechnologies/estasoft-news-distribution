<?php
/**
 * Settings, Authors.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Core\Bylines;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Where an article's author comes from, and which names are not people.
 *
 * Stored as `estasoft_nd_settings.authors`: `order`, `custom_field`, `not_people` and
 * `newsroom_name`. `Core\Bylines` reads them, so feeds, sitemaps and the byline check agree.
 *
 * The form has no script requirement. Each source has a position number, posted as
 * `order_position[<source>]`, and `prepare_input()` turns the numbers into `order`. The
 * script on this screen adds drag and arrow buttons and keeps the numbers in step.
 */
final class Authors extends Section {

	public const NAME = 'authors';

	/**
	 * How many of the newest articles the warning and the example read.
	 */
	private const SAMPLE = 18;

	/**
	 * The result of reading the newest articles, kept for the page.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $survey = null;

	/**
	 * The sources, by the name the form shows.
	 *
	 * @return array<string, string>
	 */
	public static function source_options(): array {
		return array(
			Bylines::SOURCE_COAUTHORS    => __( 'Co-author names', 'estasoft-news-distribution' ),
			Bylines::SOURCE_POST_AUTHOR  => __( 'The post’s author', 'estasoft-news-distribution' ),
			Bylines::SOURCE_CUSTOM_FIELD => __( 'A custom field', 'estasoft-news-distribution' ),
			Bylines::SOURCE_NEWSROOM     => __( 'The newsroom name', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'order'         => array(
				'type'      => 'list',
				'label'     => __( 'Where the author comes from', 'estasoft-news-distribution' ),
				'default'   => Bylines::sources(),
				'max_items' => 4,
				'item'      => array(
					'type'    => 'enum',
					'options' => array( self::class, 'source_options' ),
				),
			),
			'custom_field'  => array(
				'type'         => 'string',
				'label'        => __( 'Custom field name', 'estasoft-news-distribution' ),
				'default'      => '',
				'max'          => 100,
				'width'        => 260,
				'placeholder'  => 'byline',
				'autocomplete' => 'off',
				'help'         => __( 'The name of the field that holds the byline. Leave it empty to skip this source.', 'estasoft-news-distribution' ),
			),
			'not_people'    => array(
				'type'      => 'list',
				'label'     => __( 'Replace these names', 'estasoft-news-distribution' ),
				'default'   => Bylines::default_not_people(),
				'max_items' => 50,
				'item'      => array(
					'type' => 'string',
					'max'  => 60,
				),
			),
			'newsroom_name' => array(
				'type'         => 'string',
				'label'        => __( 'With', 'estasoft-news-distribution' ),
				'row_label'    => __( 'With', 'estasoft-news-distribution' ),
				'default'      => '',
				'max'          => 100,
				'width'        => 280,
				'placeholder'  => $this->fallback_name(),
				'autocomplete' => 'off',
				'help'         => __( 'Sent in place of those names, and when an article has no author. Leave it empty to use the publication name followed by “staff”.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function title(): string {
		return __( 'Authors', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'These apply to every channel unless a channel says otherwise.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 40;
	}

	/**
	 * Turns the position numbers of the form into the stored order. The newsroom name
	 * is always the last resort, so it stays last however the numbers are set.
	 *
	 * @param array<string, mixed> $input Unslashed input as submitted.
	 * @return array<string, mixed>
	 */
	public function prepare_input( array $input ): array {
		if ( ! isset( $input['order_position'] ) ) {
			return $input;
		}

		$positions = is_array( $input['order_position'] ) ? $input['order_position'] : array();
		$current   = Plugin::instance()->make( Bylines::class )->order();
		$ranked    = array();

		foreach ( $current as $index => $source ) {
			if ( Bylines::SOURCE_NEWSROOM === $source ) {
				continue;
			}

			$wanted = isset( $positions[ $source ] ) && is_scalar( $positions[ $source ] ) ? (int) $positions[ $source ] : 1000;

			$ranked[ $source ] = array( $wanted > 0 ? $wanted : 1000, $index );
		}

		uasort(
			$ranked,
			static function ( array $a, array $b ): int {
				return $a <=> $b;
			}
		);

		unset( $input['order_position'] );

		$input['order'] = array_merge( array_keys( $ranked ), array( Bylines::SOURCE_NEWSROOM ) );

		return $input;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'source',
					'title'       => __( 'Where the author comes from', 'estasoft-news-distribution' ),
					'description' => __( 'Platforms often ask for an author on every article. News Distribution uses the first source below that has a real name.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => '',
							'html'  => array( $this, 'render_sources' ),
						),
						array( 'field' => 'custom_field' ),
					),
				),
				array(
					'id'          => 'people',
					'title'       => __( 'Names that are not people', 'estasoft-news-distribution' ),
					'description' => __( 'Accounts such as admin are replaced in the feeds and sitemaps News Distribution builds. It cannot change the author tag your theme or SEO plugin prints on the page.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Replace these names', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_names' ),
						),
						array( 'field' => 'newsroom_name' ),
						array(
							'label' => '',
							'html'  => array( $this, 'render_warning' ),
						),
					),
				),
			),
			'cannot' => array(
				__( 'Change the author tag your theme or SEO plugin prints on the page.', 'estasoft-news-distribution' ),
				__( 'Make a platform show a name. Each platform decides what it displays.', 'estasoft-news-distribution' ),
				__( 'Tell whether a name is a real person. It replaces only the names in the list above.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The two cards beside each other: what each platform is sent, and the example.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_extra( array $values ): string {
		$platforms = array(
			array(
				__( 'SmartNews', 'estasoft-news-distribution' ),
				__( 'One author on every item. Several names are joined in one value.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'News Break', 'estasoft-news-distribution' ),
				__( 'Each name as its own author.', 'estasoft-news-distribution' ),
			),
			array(
				__( 'Apple News', 'estasoft-news-distribution' ),
				__( 'Reads the author from a tag on the article page, not from the feed.', 'estasoft-news-distribution' ),
			),
		);
		$list      = '';

		foreach ( $platforms as $platform ) {
			$list .= Html::tag(
				'div',
				array(),
				Html::tag( 'strong', array(), esc_html( $platform[0] ) ) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $platform[1] ) )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-notes' ),
			Card::note( 'authors-platforms', __( 'What News Distribution sends each platform', 'estasoft-news-distribution' ), $list )
			. Card::note( 'authors-sent', __( 'What is sent', 'estasoft-news-distribution' ), $this->sent_body() )
		);
	}

	/**
	 * The ordered list of sources, with a position number and, with the script on, drag and arrows.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_sources( array $values ): string {
		$bylines = Plugin::instance()->make( Bylines::class );
		$stored  = isset( $values['order'] ) && is_array( $values['order'] ) ? array_values( array_map( 'strval', $values['order'] ) ) : $bylines->order();
		$order   = array();

		foreach ( $stored as $source ) {
			if ( isset( self::source_options()[ $source ] ) && ! in_array( $source, $order, true ) && Bylines::SOURCE_NEWSROOM !== $source ) {
				$order[] = $source;
			}
		}

		foreach ( Bylines::sources() as $source ) {
			if ( ! in_array( $source, $order, true ) && Bylines::SOURCE_NEWSROOM !== $source ) {
				$order[] = $source;
			}
		}

		$field  = isset( $values['custom_field'] ) ? trim( (string) $values['custom_field'] ) : '';
		$items  = '';
		$count  = count( $order );
		$labels = self::source_options();

		foreach ( $order as $index => $source ) {
			$items .= $this->source_item( $source, $labels[ $source ], $index + 1, $count, $field );
		}

		$items .= $this->newsroom_item( $values );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-sources-wrap' ),
			Html::tag(
				'ol',
				array(
					'class'        => 'estasoft-nd-sources',
					'data-sources' => '1',
				),
				$items
			)
			. Html::tag(
				'p',
				array(
					'class'           => 'estasoft-nd-muted estasoft-nd-small',
					'data-order-help' => 'plain',
				),
				esc_html__( 'Choose a position for each source, then save. The newsroom name always comes last.', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'p',
				array(
					'class'           => 'estasoft-nd-muted estasoft-nd-small',
					'data-order-help' => 'script',
					'hidden'          => true,
				),
				esc_html__( 'Drag the handle, or use the arrows, to change the order. A source that is off is skipped.', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * The "Replace these names" row: the names as chips, and a box to add one.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_names( array $values ): string {
		$names = isset( $values['not_people'] ) && is_array( $values['not_people'] ) ? $values['not_people'] : Bylines::default_not_people();
		$chips = '';
		$seen  = array();

		foreach ( $names as $name ) {
			$name = trim( (string) $name );

			if ( '' === $name || in_array( $name, $seen, true ) ) {
				continue;
			}

			$seen[] = $name;
			$chips .= $this->chip( $name );
		}

		$empty = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( 'not_people' ) . '[]',
				'value' => '',
			)
		);
		$add   = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-sr',
					'for'   => 'estasoft-nd-add-name',
				),
				esc_html__( 'Add a name', 'estasoft-news-distribution' )
			)
			. Html::void(
				'input',
				array(
					'class'        => 'estasoft-nd-field estasoft-nd-w-200',
					'type'         => 'text',
					'id'           => 'estasoft-nd-add-name',
					'name'         => Field::name( 'not_people' ) . '[]',
					'value'        => '',
					'maxlength'    => 60,
					'placeholder'  => __( 'Add a name', 'estasoft-news-distribution' ),
					'autocomplete' => 'off',
				)
			)
			. Html::tag(
				'button',
				array(
					'type'          => 'button',
					'class'         => 'estasoft-nd-btn',
					'data-add-name' => '1',
					'hidden'        => true,
				),
				esc_html__( 'Add', 'estasoft-news-distribution' )
			)
		);

		return $empty
			. Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-chips',
					'data-chips' => '1',
				),
				$chips
			)
			. $add
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Names are matched as whole words, without regard to capital letters. To remove a name, clear its box.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The bar that says how many of the newest articles are by a name that is not a person.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_warning( array $values ): string {
		$survey = $this->survey();

		if ( 0 === $survey['checked'] ) {
			return '';
		}

		$bylines = Plugin::instance()->make( Bylines::class );

		if ( 0 === $survey['replaced'] ) {
			return Message::text(
				'success',
				sprintf(
					/* translators: %s: how many articles were read, such as 18. */
					_n( 'The last %s article is not by a name from this list.', 'None of your last %s articles are by a name from this list.', (int) $survey['checked'], 'estasoft-news-distribution' ),
					number_format_i18n( (int) $survey['checked'] )
				)
			);
		}

		$names    = implode( ', ', $survey['names'] );
		$newsroom = $bylines->newsroom_name();
		$sent     = '' === $newsroom
			? __( 'In feeds and sitemaps they are sent without an author.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: %s: the name sent instead, such as Example Times staff. */
				__( 'In feeds and sitemaps they are sent as %s.', 'estasoft-news-distribution' ),
				$newsroom
			);

		return Message::bar(
			'warning',
			Html::tag(
				'strong',
				array(),
				esc_html(
					sprintf(
						/* translators: 1: number of articles, 2: number of articles read, 3: the names, such as admin. */
						_n( '%1$s of your last %2$s articles is by %3$s.', '%1$s of your last %2$s articles are by %3$s.', (int) $survey['replaced'], 'estasoft-news-distribution' ),
						number_format_i18n( (int) $survey['replaced'] ),
						number_format_i18n( (int) $survey['checked'] ),
						$names
					)
				)
			)
			. ' ' . esc_html( $sent )
			. ' ' . esc_html(
				sprintf(
					/* translators: %s: the names, such as admin. */
					__( 'Apple News reads the author from the page, so it may still show %s.', 'estasoft-news-distribution' ),
					$names
				)
			)
		);
	}

	/**
	 * One movable source.
	 *
	 * @param string $source   Source key.
	 * @param string $label    Name the form shows.
	 * @param int    $position Position, from 1.
	 * @param int    $movable  How many sources can be moved.
	 * @param string $field    The custom field name, or an empty string.
	 * @return string Escaped HTML.
	 */
	private function source_item( string $source, string $label, int $position, int $movable, string $field ): string {
		$bylines = Plugin::instance()->make( Bylines::class );
		$help    = '';
		$state   = '';
		$icon    = 'info';
		$kind    = 'position';

		switch ( $source ) {
			case Bylines::SOURCE_COAUTHORS:
				$plugin = $bylines->coauthor_plugin();
				$kind   = 'fixed';
				$icon   = '' === $plugin ? 'minus-circle' : 'check-circle';
				$state  = '' === $plugin ? __( 'Not installed', 'estasoft-news-distribution' ) : __( 'Installed', 'estasoft-news-distribution' );
				$help   = '' === $plugin
					? __( 'No co-author plugin is on your site, so this source is skipped.', 'estasoft-news-distribution' )
					: sprintf(
						/* translators: %s: the name of a plugin, such as PublishPress Authors. */
						__( '%s is on your site.', 'estasoft-news-distribution' ),
						$plugin
					);
				break;

			case Bylines::SOURCE_POST_AUTHOR:
				$help = __( 'The author’s display name.', 'estasoft-news-distribution' );
				break;

			case Bylines::SOURCE_CUSTOM_FIELD:
				if ( '' === $field ) {
					$kind  = 'fixed';
					$icon  = 'minus-circle';
					$state = __( 'Off', 'estasoft-news-distribution' );
					$help  = __( 'Name a field below to use it.', 'estasoft-news-distribution' );
				} else {
					$help = sprintf(
						/* translators: %s: the name of a custom field, such as byline. */
						__( 'A field named %s.', 'estasoft-news-distribution' ),
						$field
					);
				}
				break;
		}

		$first = __( 'Used first', 'estasoft-news-distribution' );
		$later = __( 'Used when the sources above have no name', 'estasoft-news-distribution' );

		if ( 'position' === $kind ) {
			$state = 1 === $position ? $first : $later;
		}

		$options = '';

		for ( $number = 1; $number <= $movable; $number++ ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => (string) $number,
					'selected' => $number === $position,
				),
				esc_html( number_format_i18n( $number ) )
			);
		}

		$state_attrs = array(
			'class'     => 'estasoft-nd-source__state',
			'data-kind' => $kind,
		);

		if ( 'position' === $kind ) {
			$state_attrs['data-first'] = $first;
			$state_attrs['data-later'] = $later;
		}

		$controls = Html::tag(
			'span',
			array(
				'class'  => 'estasoft-nd-source__buttons',
				'hidden' => true,
			),
			$this->move_button( 'up', $label ) . $this->move_button( 'down', $label )
		);
		$select   = Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-source__position' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Position', 'estasoft-news-distribution' ) )
			. Html::tag(
				'select',
				array(
					'class' => 'estasoft-nd-field',
					'name'  => Field::name( 'order_position', $source ),
				),
				$options
			)
		);

		return Html::tag(
			'li',
			array(
				'class'       => 'estasoft-nd-source',
				'data-source' => $source,
			),
			Html::tag(
				'span',
				array(
					'class'       => 'estasoft-nd-source__handle',
					'aria-hidden' => 'true',
					'hidden'      => true,
				),
				''
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-source__text' ), Html::tag( 'strong', array(), esc_html( $label ) ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $help ) ) )
			. Html::tag( 'span', $state_attrs, Icon::svg( $icon ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-source__state-text' ), esc_html( $state ) ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-source__order' ), $select . $controls )
		);
	}

	/**
	 * The newsroom name, which is always the last resort and does not move.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	private function newsroom_item( array $values ): string {
		$name = trim( (string) ( $values['newsroom_name'] ?? '' ) );
		$name = '' === $name ? $this->fallback_name() : $name;
		$help = '' === $name ? __( 'Not set. Set a name under Names that are not people.', 'estasoft-news-distribution' ) : $name;

		return Html::tag(
			'li',
			array(
				'class'       => 'estasoft-nd-source estasoft-nd-source--fixed',
				'data-source' => Bylines::SOURCE_NEWSROOM,
			),
			Html::tag(
				'span',
				array(
					'class'       => 'estasoft-nd-source__handle',
					'aria-hidden' => 'true',
					'hidden'      => true,
				),
				''
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-source__text' ), Html::tag( 'strong', array(), esc_html__( 'The newsroom name', 'estasoft-news-distribution' ) ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $help ) ) )
			. Html::tag(
				'span',
				array(
					'class'     => 'estasoft-nd-source__state',
					'data-kind' => 'fixed',
				),
				Icon::svg( 'info' ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-source__state-text' ), esc_html__( 'Last resort', 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * An arrow button, shown only when the script is running.
	 *
	 * @param string $direction up or down.
	 * @param string $label     Name of the source, for the button's name.
	 * @return string Escaped HTML.
	 */
	private function move_button( string $direction, string $label ): string {
		$name = 'up' === $direction
			/* translators: %s: the name of an author source, such as The post's author. */
			? sprintf( __( 'Move %s up', 'estasoft-news-distribution' ), $label )
			/* translators: %s: the name of an author source, such as The post's author. */
			: sprintf( __( 'Move %s down', 'estasoft-news-distribution' ), $label );

		return Html::tag(
			'button',
			array(
				'type'       => 'button',
				'class'      => 'estasoft-nd-btn estasoft-nd-source__move',
				'data-move'  => $direction,
				'aria-label' => $name,
			),
			Icon::svg( 'up' === $direction ? 'chevron-up' : 'chevron-down' )
		);
	}

	/**
	 * One name as a chip. Its box is ticked while the name is on the list.
	 *
	 * @param string $name The name.
	 * @return string Escaped HTML.
	 */
	private function chip( string $name ): string {
		return Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-chip' ),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'name'    => Field::name( 'not_people' ) . '[]',
					'value'   => $name,
					'checked' => true,
				)
			)
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-chip__name' ), esc_html( $name ) )
			. Html::tag(
				'span',
				array(
					'class'       => 'estasoft-nd-chip__x',
					'aria-hidden' => 'true',
				),
				Icon::svg( 'x' )
			)
			. Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-sr' ),
				esc_html__( 'Replace this name. Clear the box to stop replacing it.', 'estasoft-news-distribution' )
			)
		);
	}

	/**
	 * The body of the "What is sent" card, for the newest article.
	 *
	 * @return string Escaped HTML.
	 */
	private function sent_body(): string {
		$latest = $this->survey()['latest'];

		if ( null === $latest ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Publish an article to see here what is sent for it.', 'estasoft-news-distribution' ) );
		}

		$bylines   = Plugin::instance()->make( Bylines::class );
		$byline    = $bylines->resolve( $latest );
		$coauthors = $bylines->coauthors( $latest->id() );
		$title     = '' === trim( $latest->title() ) ? __( '(no title)', 'estasoft-news-distribution' ) : $latest->title();
		$lines     = Html::tag(
			'p',
			array(),
			esc_html__( 'WordPress author:', 'estasoft-news-distribution' ) . ' ' . Html::tag( 'code', array(), esc_html( $latest->author_name() ) )
		);

		if ( array() !== $coauthors ) {
			$lines .= Html::tag( 'p', array(), esc_html__( 'Co-author:', 'estasoft-news-distribution' ) . ' ' . esc_html( implode( ', ', $coauthors ) ) );
		}

		$lines .= Html::tag(
			'p',
			array(),
			Html::tag(
				'strong',
				array(),
				esc_html__( 'Sent:', 'estasoft-news-distribution' ) . ' ' . esc_html( '' === $byline['joined'] ? __( 'no author', 'estasoft-news-distribution' ) : $byline['joined'] )
			)
		);

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			sprintf(
				/* translators: %s: the title of an article. */
				esc_html__( 'For “%s”.', 'estasoft-news-distribution' ),
				'<bdi>' . esc_html( $title ) . '</bdi>'
			)
		)
			. $lines
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html__( 'With two co-authors, News Break gets two authors and the others get both names joined with a comma.', 'estasoft-news-distribution' )
			);
	}

	/**
	 * The newest articles, read once for this page.
	 *
	 * @return array<string, mixed>
	 */
	private function survey(): array {
		if ( null === $this->survey ) {
			$this->survey = Plugin::instance()->make( Bylines::class )->survey( self::SAMPLE );
		}

		return $this->survey;
	}

	/**
	 * What is sent when the owner sets no newsroom name: the publication name and "staff".
	 *
	 * @return string An empty string when the site has no name.
	 */
	private function fallback_name(): string {
		$publication = Plugin::instance()->settings()->publication_name();

		if ( '' === $publication ) {
			return '';
		}

		return sprintf(
			/* translators: %s: the publication name, such as Example Times. The result is used as an author name in feeds. */
			__( '%s staff', 'estasoft-news-distribution' ),
			$publication
		);
	}
}
