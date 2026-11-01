<?php
/**
 * News Break local news: the rule that sends only articles that are local to the publisher.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\SaveBar;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Switcher;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Ai\Features\LocalNews;
use Estasoft\NewsDistribution\Ai\View\Parts;
use Estasoft\NewsDistribution\Ai\View\Runner;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=news-break&tab=local`, added to the News Break page by `AiModule`
 * through the `estasoft_nd_internal_channel_tabs` filter. It is present only while the assistant is ready.
 *
 * The owner switches the rule on and lists the places the AI may use as a guide. An article is read once, in the
 * background, a few at a time: when it is published and once for the latest articles when the rule is turned on.
 * A person can always override the result for one article, and the override stays on the article.
 *
 * Every form posts to this screen and works without a script. The Local, Skip and Clear buttons belong to one
 * form that sits after the settings form, so they can be pressed without saving the settings.
 */
final class LocalNewsTab {

	/**
	 * The screen's own name: it keys the form nonce.
	 */
	private const KEY = 'local-news';

	/**
	 * Articles shown before "Show all".
	 */
	private const SHOWN = 5;

	/**
	 * Id of the form the Local, Skip and Clear buttons belong to.
	 */
	private const OVERRIDE_FORM = 'estasoft-nd-ai-override';

	/**
	 * Id of the form "Read recent articles" belongs to.
	 */
	private const QUEUE_FORM = 'estasoft-nd-ai-queue';

	/**
	 * The running plugin.
	 *
	 * @var Plugin
	 */
	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	public function slug(): string {
		return 'local';
	}

	public function label(): string {
		return __( 'Local news', 'estasoft-news-distribution' );
	}

	/**
	 * An address of this channel's pages.
	 *
	 * @param string               $tab  Tab, or empty for Setup.
	 * @param array<string, mixed> $args More arguments.
	 * @return string
	 */
	private function url( string $tab = 'local', array $args = array() ): string {
		return add_query_arg( array_merge( '' === $tab ? array() : array( 'tab' => $tab ), $args ), Links::channel( 'news-break' ) );
	}

	public function enqueue(): void {
		Assets::screen_style( 'ai' );
		Assets::screen_script( 'ai' );
		Assets::screen_script( 'dirty-form' );
	}

	public function handle(): void {
		if ( ! Runner::posted( self::KEY ) ) {
			return;
		}

		$this->plugin->access()->require_or_die( Access::MANAGE );
		check_admin_referer( Runner::action( self::KEY ), Runner::NONCE );

		$do = isset( $_POST['estasoft_nd_ai_do'] ) ? sanitize_key( wp_unslash( $_POST['estasoft_nd_ai_do'] ) ) : '';

		if ( 'save' === $do ) {
			$this->save();
		} elseif ( 'queue' === $do ) {
			$this->queue();
		} elseif ( 'decide' === $do ) {
			$this->override();
		}

		wp_safe_redirect( $this->url() );
		exit;
	}

	/**
	 * Saves the switch, the places and how strict the rule is. A place typed in the box is added, and a place whose
	 * x was pressed is taken away.
	 *
	 * @return void
	 */
	private function save(): void {
		check_admin_referer( Runner::action( self::KEY ), Runner::NONCE );

		$places = isset( $_POST['places'] ) && is_array( $_POST['places'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['places'] ) ) : array();
		$add    = isset( $_POST['new_place'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['new_place'] ) ) ) : '';
		$remove = isset( $_POST['remove_place'] ) ? sanitize_text_field( wp_unslash( $_POST['remove_place'] ) ) : '';
		$on     = isset( $_POST['enabled'] ) && '1' === sanitize_key( wp_unslash( $_POST['enabled'] ) );
		$strict = isset( $_POST['strict'] ) ? sanitize_key( wp_unslash( $_POST['strict'] ) ) : 'balanced';

		if ( '' !== $remove ) {
			$places = array_values( array_diff( $places, array( $remove ) ) );
		}

		if ( '' !== $add ) {
			$places[] = $add;
		}

		$feature = $this->plugin->make( LocalNews::class );
		$before  = $feature->settings();
		$saved   = $feature->save(
			array(
				'enabled' => $on,
				'places'  => $places,
				'strict'  => $strict,
			)
		);

		if ( is_wp_error( $saved ) ) {
			Flash::set( 'danger', $saved->get_error_message() );

			return;
		}

		$message = __( 'Saved.', 'estasoft-news-distribution' );

		if ( $saved['enabled'] && ( ! $before['enabled'] || $before['places'] !== $saved['places'] ) ) {
			$queued = $feature->queue_recent();

			if ( $queued > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of articles. */
					_n( '%d recent article will be read in the background.', '%d recent articles will be read in the background.', $queued, 'estasoft-news-distribution' ),
					$queued
				);
			}
		}

		Flash::set( 'success', $message );
	}

	/**
	 * "Read recent articles": puts the latest articles that have no result in the background queue. Nothing is sent here.
	 *
	 * @return void
	 */
	private function queue(): void {
		$queued = $this->plugin->make( LocalNews::class )->queue_recent();

		Flash::set(
			$queued > 0 ? 'success' : 'info',
			$queued > 0
				? sprintf(
					/* translators: %d: number of articles. */
					_n( '%d article is waiting to be read. It is read in the background, a few at a time.', '%d articles are waiting to be read. They are read in the background, a few at a time.', $queued, 'estasoft-news-distribution' ),
					$queued
				)
				: __( 'Nothing to read: every recent article has a result already, or is waiting.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * Local, Skip or Clear for one article. The article's log row is found, or made, here and never while a page is shown.
	 *
	 * @return void
	 */
	private function override(): void {
		check_admin_referer( Runner::action( self::KEY ), Runner::NONCE );

		$posted = isset( $_POST['override'] ) ? sanitize_text_field( wp_unslash( $_POST['override'] ) ) : '';
		$parts  = explode( ':', $posted );
		$post   = (int) ( $parts[0] ?? 0 );
		$choice = sanitize_key( $parts[1] ?? '' );
		$about  = $this->plugin->make( LocalNews::class );

		if ( $post < 1 || ! in_array( $choice, array( 'local', 'skip', 'clear' ), true ) || null === get_post( $post ) || ! $about->can( $post ) ) {
			Flash::set( 'danger', __( 'That article cannot be changed here.', 'estasoft-news-distribution' ) );

			return;
		}

		$log = $about->row_for( $post );

		if ( $log < 1 ) {
			Flash::set( 'danger', __( 'Nothing was changed. The AI log is not available on this site.', 'estasoft-news-distribution' ) );

			return;
		}

		$this->plugin->make( Runner::class )->handle(
			self::KEY,
			$this->url(),
			array(
				'post'     => $post,
				'log'      => $log,
				'decision' => $choice,
			)
		);
	}

	public function render(): string {
		$feature  = $this->plugin->make( LocalNews::class );
		$settings = $feature->settings();
		$channel  = $this->plugin->registry()->get( 'news-break' );
		$name     = null === $channel ? 'News Break' : $channel->label();
		$health   = null === $channel ? '' : $channel->health();
		$flash    = Flash::take();

		$head = Html::tag(
			'header',
			array( 'class' => 'estasoft-nd-page-header' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-page-header__text' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Html::tag( 'h1', array( 'class' => 'estasoft-nd-page-title' ), esc_html( $name ) )
					. ( null === $channel ? '' : Tag::mode( $channel->mode() ) . StatusView::pill( $health ) )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Choose which of your articles this channel receives.', 'estasoft-news-distribution' ) )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-page-header__actions' ), $this->sections() )
		);

		$lead = '';

		if ( Status::NOT_SET_UP === $health ) {
			$lead = Message::bar(
				'info',
				Html::tag(
					'strong',
					array(),
					esc_html(
						sprintf(
							/* translators: 1: a channel name such as News Break, 2: a status such as Not set up. */
							__( '%1$s is %2$s, so nothing is sent yet.', 'estasoft-news-distribution' ),
							$name,
							Status::word( Status::NOT_SET_UP )
						)
					)
				) . ' ' . esc_html__( 'Once you set it up, its feed holds only the articles this rule treats as local.', 'estasoft-news-distribution' )
			);
		}

		$form = Html::tag(
			'form',
			array(
				'method'          => 'post',
				'action'          => $this->url(),
				'class'           => 'estasoft-nd-stack estasoft-nd-gap-16',
				'novalidate'      => true,
				'data-dirty-form' => true,
				'aria-label'      => __( 'Local news', 'estasoft-news-distribution' ),
			),
			Parts::hidden( self::KEY, 'save' )
			// The first button is the one Enter presses in the box for a new place, so it saves and never removes a place.
			. Html::tag(
				'button',
				array(
					'type'        => 'submit',
					'class'       => 'estasoft-nd-sr',
					'tabindex'    => '-1',
					'aria-hidden' => 'true',
				),
				esc_html__( 'Save changes', 'estasoft-news-distribution' )
			)
			. $this->rule_card( $settings )
			. $this->notes()
			. SaveBar::render()
		);

		return Parts::crumbs( __( 'Channels', 'estasoft-news-distribution' ), Links::admin( 'estasoft-nd-channels' ), $name )
			. $head
			. $flash
			. $lead
			. $form
			. $this->shared_forms();
	}

	/**
	 * Setup, Rules and Local news, so the person can go back.
	 *
	 * @return string Escaped HTML.
	 */
	private function sections(): string {
		$links = '';

		foreach (
			array(
				''      => __( 'Setup', 'estasoft-news-distribution' ),
				'rules' => __( 'Rules', 'estasoft-news-distribution' ),
				'local' => __( 'Local news', 'estasoft-news-distribution' ),
			) as $slug => $label
		) {
			$on     = 'local' === (string) $slug;
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

	/**
	 * One labelled row of the card.
	 *
	 * @param string $label   The label (plain).
	 * @param string $control Trusted HTML.
	 * @param string $field   The id of the field the label belongs to, or empty.
	 * @return string Escaped HTML.
	 */
	private function row( string $label, string $control, string $field = '' ): string {
		$attrs = array( 'class' => 'estasoft-nd-form-label' );

		if ( '' !== $field ) {
			$attrs['for'] = $field;
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag( '' === $field ? 'div' : 'label', $attrs, esc_html( $label ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-form-control' ), $control )
		);
	}

	/**
	 * "Local news": the switch, the places, how strict, and what the rule does to the latest articles.
	 *
	 * @param array{enabled: bool, places: string[], strict: string} $settings What the owner chose.
	 * @return string Escaped HTML.
	 */
	private function rule_card( array $settings ): string {
		$chips = '';

		foreach ( $settings['places'] as $place ) {
			$chips .= Html::tag(
				'span',
				array( 'class' => 'estasoft-nd-ai-place' ),
				Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => 'places[]',
						'value' => $place,
					)
				)
				. Html::tag( 'span', array( 'dir' => 'auto' ), esc_html( $place ) )
				. Html::tag(
					'button',
					array(
						'type'       => 'submit',
						'name'       => 'remove_place',
						'value'      => $place,
						'aria-label' => sprintf(
							/* translators: %s: a place name such as The city. */
							__( 'Remove %s', 'estasoft-news-distribution' ),
							$place
						),
					),
					Icon::svg( 'x' )
				)
			);
		}

		$levels  = array(
			'strict'   => __( 'Strict', 'estasoft-news-distribution' ),
			'balanced' => __( 'Balanced', 'estasoft-news-distribution' ),
			'broad'    => __( 'Broad', 'estasoft-news-distribution' ),
		);
		$options = '';

		foreach ( $levels as $value => $label ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => $value,
					'selected' => $value === $settings['strict'],
				),
				esc_html( $label )
			);
		}

		$rows = $this->row(
			__( 'Send only local news', 'estasoft-news-distribution' ),
			Switcher::render(
				'enabled',
				'estasoft-nd-ai-local-on',
				$settings['enabled'],
				__( 'News Break is built around local news. With this on, only articles that are local to you go in its feed. News Break decides what it shows.', 'estasoft-news-distribution' ),
				false
			)
		) . $this->row(
			__( 'Local to', 'estasoft-news-distribution' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-places' ), $chips )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Html::void(
					'input',
					array(
						'type'         => 'text',
						'id'           => 'estasoft-nd-ai-new-place',
						'name'         => 'new_place',
						'class'        => 'estasoft-nd-field estasoft-nd-w-280',
						'placeholder'  => __( 'Add a place', 'estasoft-news-distribution' ),
						'maxlength'    => '80',
						'autocomplete' => 'off',
					)
				)
				. Html::tag(
					'button',
					array(
						'type'  => 'submit',
						'name'  => 'add_place',
						'value' => '1',
						'class' => 'estasoft-nd-btn',
					),
					esc_html__( 'Add', 'estasoft-news-distribution' )
				)
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The AI uses these places as a guide. Add the names your readers use.', 'estasoft-news-distribution' ) ),
			'estasoft-nd-ai-new-place'
		) . $this->row(
			__( 'How strict', 'estasoft-news-distribution' ),
			Html::tag(
				'select',
				array(
					'id'    => 'estasoft-nd-ai-strict',
					'name'  => 'strict',
					'class' => 'estasoft-nd-field',
				),
				$options
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Strict sends fewer articles, with higher confidence. Broad sends more.', 'estasoft-news-distribution' ) ),
			'estasoft-nd-ai-strict'
		);

		return Card::flush(
			'ai-local',
			__( 'Local news', 'estasoft-news-distribution' ),
			'',
			$rows . $this->recent(),
			Parts::badge()
		);
	}

	/**
	 * "Your last 30 articles": what the rule does with each, and the buttons that override it.
	 *
	 * @return string Escaped HTML.
	 */
	private function recent(): string {
		$feature = $this->plugin->make( LocalNews::class );
		$all     = $feature->recent( LocalNews::RECENT );
		$full    = Query::is( 'all', '1' );
		$shown   = $full ? $all : array_slice( $all, 0, self::SHOWN );
		$count   = array(
			'local'  => 0,
			'skip'   => 0,
			'decide' => 0,
			'none'   => 0,
		);
		$rows    = array();

		foreach ( $all as $item ) {
			++$count[ $item['state']['state'] ];
		}

		foreach ( $shown as $item ) {
			$rows[] = $this->cells( $item );
		}

		$title = Html::tag(
			'h3',
			array( 'class' => 'estasoft-nd-title' ),
			esc_html(
				sprintf(
					/* translators: %d: number of articles. */
					_n( 'Your last %d article', 'Your last %d articles', max( 1, count( $all ) ), 'estasoft-news-distribution' ),
					max( 1, count( $all ) )
				)
			)
		);

		if ( array() === $all ) {
			return Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-recent' ), $title . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'There are no published articles yet.', 'estasoft-news-distribution' ) ) );
		}

		$line = sprintf(
			/* translators: 1: number of local articles, 2: number skipped, 3: number that need a decision. */
			__( 'What the rule would do today: %1$d local, %2$d skipped, %3$d need your decision.', 'estasoft-news-distribution' ),
			$count['local'],
			$count['skip'],
			$count['decide']
		);

		if ( $count['none'] > 0 ) {
			$line .= ' ' . sprintf(
				/* translators: %d: number of articles the AI has not read yet. */
				_n( '%d is not read yet.', '%d are not read yet.', $count['none'], 'estasoft-news-distribution' ),
				$count['none']
			);
		}

		$line .= ' ' . __( 'A skipped article, or one you have not decided yet, shows Skipped for News Break in Article check, with the reason.', 'estasoft-news-distribution' );

		$queue  = $feature->queue_state();
		$footer = sprintf(
			/* translators: 1: articles shown, 2: articles in all. */
			__( 'Showing %1$d of %2$d articles.', 'estasoft-news-distribution' ),
			count( $shown ),
			count( $all )
		) . ' ' . __( 'Leave a row as it is to use the result, or choose Local or Skip to override it. An override applies at once and stays on the article until you choose Clear.', 'estasoft-news-distribution' );

		$more = count( $shown ) < count( $all )
			? Button::link(
				sprintf(
					/* translators: %d: number of articles. */
					__( 'Show all %d', 'estasoft-news-distribution' ),
					count( $all )
				),
				$this->url( 'local', array( 'all' => '1' ) ),
				array( 'size' => 'sm' )
			)
			: '';

		$reader = Html::tag(
			'button',
			array(
				'type'  => 'submit',
				'form'  => self::QUEUE_FORM,
				'class' => 'estasoft-nd-btn estasoft-nd-btn--sm',
			),
			esc_html__( 'Read recent articles', 'estasoft-news-distribution' )
		);
		$state  = $queue['waiting'] > 0
			? sprintf(
				/* translators: %d: number of articles. */
				_n( '%d article is waiting to be read.', '%d articles are waiting to be read.', $queue['waiting'], 'estasoft-news-distribution' ),
				$queue['waiting']
			)
			: __( 'Articles with no result are read in the background, a few at a time.', 'estasoft-news-distribution' );

		if ( '' !== $queue['error'] ) {
			$state .= ' ' . $queue['error'];
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-recent' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-ai-recent__head' ), $title . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $line ) ) )
			. Parts::fit(
				Grid::render(
					'estasoft-nd-cols-ai-local',
					array(
						__( 'Article', 'estasoft-news-distribution' ),
						__( 'Result', 'estasoft-news-distribution' ),
						__( 'Confidence', 'estasoft-news-distribution' ),
						__( 'Why', 'estasoft-news-distribution' ),
						__( 'Your decision', 'estasoft-news-distribution' ),
					),
					$rows,
					__( 'What the local news rule does with your latest articles', 'estasoft-news-distribution' )
				),
				__( 'What the local news rule does with your latest articles', 'estasoft-news-distribution' ),
				true
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-recent__foot' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $footer ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $more . $reader . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $state ) ) )
			)
		);
	}

	/**
	 * One article's row.
	 *
	 * @param array{id: int, title: string, state: array<string, mixed>} $item An article with what the rule does with it.
	 * @return array<int, string> Cells.
	 */
	private function cells( array $item ): array {
		$state = $item['state'];
		$mark  = array(
			'local'  => View::mark( 'success', __( 'Local', 'estasoft-news-distribution' ) ),
			'skip'   => View::mark( 'danger', __( 'Skipped', 'estasoft-news-distribution' ) ),
			'decide' => View::mark( 'warning', __( 'Needs your decision', 'estasoft-news-distribution' ) ),
			'none'   => View::mark( 'neutral', __( 'Not read yet', 'estasoft-news-distribution' ) ),
		)[ $state['state'] ] ?? '';

		if ( 'override' === $state['source'] ) {
			$mark .= Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Your decision', 'estasoft-news-distribution' ) );
		}

		$read = (int) $state['confidence'] > 0;

		return array(
			Html::tag(
				'a',
				array(
					'class' => 'estasoft-nd-strong',
					'href'  => Links::check( (int) $item['id'] ),
					'dir'   => 'auto',
				),
				esc_html( '' === $item['title'] ? __( '(no title)', 'estasoft-news-distribution' ) : $item['title'] )
			),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ), $mark ),
			$read
				? esc_html(
					sprintf(
						/* translators: 1: High, Medium or Low, 2: a percentage such as 94. */
						__( '%1$s, %2$d%%', 'estasoft-news-distribution' ),
						(string) $state['word'],
						(int) $state['confidence']
					)
				)
				: Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), '—' ),
			'' === (string) $state['why'] ? Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), '—' ) : esc_html( (string) $state['why'] ),
			$this->buttons( (int) $item['id'], $state ),
		);
	}

	/**
	 * Local, Skip and, once one is set, Clear. They belong to the form after the settings form.
	 *
	 * @param int                  $post_id The article.
	 * @param array<string, mixed> $state   What the rule does with it.
	 * @return string Escaped HTML.
	 */
	private function buttons( int $post_id, array $state ): string {
		$set     = 'override' === $state['source'] ? $state['state'] : '';
		$buttons = '';

		foreach (
			array(
				'local' => __( 'Local', 'estasoft-news-distribution' ),
				'skip'  => __( 'Skip', 'estasoft-news-distribution' ),
			) as $value => $label
		) {
			$buttons .= Html::tag(
				'button',
				array(
					'type'         => 'submit',
					'form'         => self::OVERRIDE_FORM,
					'name'         => 'override',
					'value'        => $post_id . ':' . $value,
					'class'        => $value === $set ? 'is-on' : '',
					'aria-pressed' => $value === $set ? 'true' : 'false',
				),
				esc_html( $label )
			);
		}

		$clear = '' === $set ? '' : Html::tag(
			'button',
			array(
				'type'  => 'submit',
				'form'  => self::OVERRIDE_FORM,
				'name'  => 'override',
				'value' => $post_id . ':clear',
				'class' => 'estasoft-nd-btn estasoft-nd-btn--subtle estasoft-nd-btn--sm',
			),
			esc_html__( 'Clear', 'estasoft-news-distribution' )
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-seg',
					'role'       => 'group',
					'aria-label' => __( 'Your decision for this article', 'estasoft-news-distribution' ),
				),
				$buttons
			) . $clear
		);
	}

	/**
	 * The three cards under the list.
	 *
	 * @return string Escaped HTML.
	 */
	private function notes(): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-ai-trio' ),
			Card::note(
				'ai-decides',
				__( 'How it decides', 'estasoft-news-distribution' ),
				Parts::bullets(
					array(
						__( 'It reads the title and the first paragraphs of an article once: when it is published, and once for your last 30 articles when you turned this on', 'estasoft-news-distribution' ),
						__( 'You always see a reason and a confidence', 'estasoft-news-distribution' ),
						__( 'Your override stays on the article and decides it from then on', 'estasoft-news-distribution' ),
						__( 'It never changes your article', 'estasoft-news-distribution' ),
					)
				)
			)
			. Card::note(
				'ai-local-sent',
				__( 'What is sent', 'estasoft-news-distribution' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The title and the first paragraphs of each article, and the places you listed. 1 request per article.', 'estasoft-news-distribution' ) )
			)
			. Card::cannot(
				array(
					__( 'Make News Break accept your feed or show an article. News Break decides.', 'estasoft-news-distribution' ),
					__( 'Know more than the text says. It reads the title and the first paragraphs only.', 'estasoft-news-distribution' ),
					__( 'Send an article it is not sure about. You decide first.', 'estasoft-news-distribution' ),
				)
			)
		);
	}

	/**
	 * The forms the Local, Skip, Clear and Read buttons belong to. They hold only a nonce and what the form does.
	 *
	 * @return string Escaped HTML.
	 */
	private function shared_forms(): string {
		return Html::tag(
			'form',
			array(
				'id'     => self::OVERRIDE_FORM,
				'method' => 'post',
				'action' => $this->url(),
			),
			Parts::hidden( self::KEY, 'decide' )
		) . Html::tag(
			'form',
			array(
				'id'     => self::QUEUE_FORM,
				'method' => 'post',
				'action' => $this->url(),
			),
			Parts::hidden( self::KEY, 'queue' )
		);
	}
}
