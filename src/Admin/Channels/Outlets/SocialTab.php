<?php
/**
 * Outlets, Mastodon and Bluesky.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels\Outlets;

use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Outlets\AbstractOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\BlueskyOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\MastodonOutlet;
use Estasoft\NewsDistribution\Channels\Outlets\OutletInterface;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Two services on one tab, because they share what a post holds and when it
 * goes. Each is judged on its own: a service that fails its test stays off
 * and the other is not affected. A language with no account is skipped.
 */
final class SocialTab extends AbstractTab {

	public function slug(): string {
		return 'social';
	}

	public function render(): string {
		$main = $this->mastodon_card() . $this->bluesky_card() . $this->posting_card();
		$side = $this->preview_card() . $this->recent_card() . $this->limits_card();

		return $this->form( $this->split( $main, $side ), __( 'Mastodon and Bluesky', 'estasoft-news-distribution' ) );
	}

	/**
	 * The Mastodon outlet.
	 *
	 * @return MastodonOutlet|null
	 */
	private function mastodon(): ?MastodonOutlet {
		$outlet = $this->channel->outlet( 'mastodon' );

		return $outlet instanceof MastodonOutlet ? $outlet : null;
	}

	/**
	 * The Bluesky outlet.
	 *
	 * @return BlueskyOutlet|null
	 */
	private function bluesky(): ?BlueskyOutlet {
		$outlet = $this->channel->outlet( 'bluesky' );

		return $outlet instanceof BlueskyOutlet ? $outlet : null;
	}

	/**
	 * A table of accounts, one row per language.
	 *
	 * @param string   $label   What the table lists, for a screen reader.
	 * @param string   $account The title of the middle column.
	 * @param string[] $rows    Escaped HTML of each row's three cells, already wrapped.
	 * @return string
	 */
	private function table( string $label, string $account, array $rows ): string {
		$head = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-grid-row estasoft-nd-grid-row--head estasoft-nd-outlets-cols-acc',
				'role'  => 'row',
			),
			Html::tag( 'span', array( 'role' => 'columnheader' ), esc_html__( 'Language', 'estasoft-news-distribution' ) )
			. Html::tag( 'span', array( 'role' => 'columnheader' ), esc_html( $account ) )
			. Html::tag( 'span', array( 'role' => 'columnheader' ), esc_html__( 'Status', 'estasoft-news-distribution' ) )
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-scroll' ),
			Html::tag(
				'div',
				array(
					'role'       => 'table',
					'aria-label' => $label,
				),
				$head . implode( '', $rows )
			)
		);
	}

	/**
	 * One row of a table of accounts.
	 *
	 * @param string $code   Language code.
	 * @param string $middle Escaped HTML of the account cell.
	 * @param string $status Escaped HTML of the status cell.
	 * @return string
	 */
	private function account_row( string $code, string $middle, string $status ): string {
		return Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-grid-row estasoft-nd-outlets-cols-acc',
				'role'  => 'row',
			),
			Html::tag( 'div', array( 'role' => 'cell' ), $this->lang( $code ) )
			. Html::tag(
				'div',
				array(
					'role'  => 'cell',
					'class' => 'estasoft-nd-stack estasoft-nd-gap-8',
				),
				$middle
			)
			. Html::tag(
				'div',
				array(
					'role'  => 'cell',
					'class' => 'estasoft-nd-stack estasoft-nd-gap-4',
				),
				$status
			)
		);
	}

	/**
	 * The test button of one account and the line that says where the post goes.
	 *
	 * @param string $outlet  Outlet id.
	 * @param string $code    Language code.
	 * @param string $where   Where the post goes, such as "to @name, followers only".
	 * @param string $account The account's name, for the button's label.
	 * @return string
	 */
	private function test_line( string $outlet, string $code, string $where, string $account ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
			$this->action_button(
				'test:' . $outlet . ':' . $code,
				__( 'Send a test post', 'estasoft-news-distribution' ),
				array(
					'size'       => 'sm',
					'aria_label' => sprintf(
						/* translators: %s: the name of an account. */
						__( 'Send a test post to %s', 'estasoft-news-distribution' ),
						$account
					),
				)
			) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $where ) )
		);
	}

	/**
	 * The Mastodon card.
	 *
	 * @return string
	 */
	private function mastodon_card(): string {
		$outlet = $this->mastodon();

		if ( null === $outlet ) {
			return '';
		}

		$id     = 'estasoft-nd-card-mastodon';
		$values = $this->values();
		$rows   = array();

		foreach ( $this->channel->site_languages() as $code ) {
			$field  = 'mastodon_token_' . AbstractOutlet::suffix( $code );
			$is_set = ! empty( $values[ $field . '_is_set' ] );
			$handle = $this->state()->handle( 'mastodon:' . $code );
			$middle = $this->secret_input(
				$field,
				sprintf(
					/* translators: %s: a language name such as English. */
					__( '%s access token', 'estasoft-news-distribution' ),
					$this->language_name( $code )
				)
			);

			if ( $is_set ) {
				$middle .= $this->test_line(
					'mastodon',
					$code,
					'' !== $handle
						? sprintf(
							/* translators: %s: an account such as @name@server. */
							__( 'to %s, followers only', 'estasoft-news-distribution' ),
							$handle
						)
						: __( 'to the account this token belongs to, followers only', 'estasoft-news-distribution' ),
					'' !== $handle ? $handle : $this->language_name( $code )
				);
			}

			$rows[] = $this->account_row(
				$code,
				$middle,
				$is_set
					? $this->test_status( 'mastodon:' . $code )
					: Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Leave empty to skip', 'estasoft-news-distribution' ) )
			);
		}

		$server_id = Field::id( $this->screen->scope_id(), 'mastodon_server' );
		$server    = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-strong',
					'for'   => $server_id,
				),
				esc_html__( 'Server address', 'estasoft-news-distribution' )
			)
			. $this->text_input(
				'mastodon_server',
				__( 'Server address', 'estasoft-news-distribution' ),
				array(
					'type'        => 'url',
					'dir'         => 'ltr',
					'placeholder' => 'https://mastodon.example',
					'maxlength'   => 200,
				),
				false
			)
		);

		$tokens_set = false;

		foreach ( $this->channel->site_languages() as $code ) {
			$tokens_set = $tokens_set || ! empty( $values[ 'mastodon_token_' . AbstractOutlet::suffix( $code ) . '_is_set' ] );
		}

		$steps  = $this->step(
			1,
			__( 'Create an application on your server', 'estasoft-news-distribution' ),
			__( 'In your Mastodon account open Preferences, Development, then New application. Give it the permission to write statuses (write:statuses) and to read the account’s profile (profile), and no more. The second one lets News Distribution check the token without posting. Copy the access token it shows.', 'estasoft-news-distribution' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'One token belongs to one account, so each language that posts to its own account needs its own.', 'estasoft-news-distribution' ) ),
			false,
			$tokens_set
		);
		$steps .= $this->step(
			2,
			__( 'Enter the server and a token for each language', 'estasoft-news-distribution' ),
			__( 'The server address is the same for every language. Each token belongs to one account, shown next to its test button.', 'estasoft-news-distribution' ),
			$server
			. $this->table( __( 'Mastodon accounts', 'estasoft-news-distribution' ), __( 'Token', 'estasoft-news-distribution' ), $rows )
			. $this->limit_line()
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Each test post goes to the one account named beside its button. It is for followers only and says it is a test. Delete it from Mastodon when you have seen it.', 'estasoft-news-distribution' ) ),
			! $tokens_set,
			false
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $id,
			),
			$this->service_head( $id, $outlet->label(), 'mastodon' ) . $steps
		);
	}

	/**
	 * What the server's limit is and where it came from.
	 *
	 * @return string
	 */
	private function limit_line(): string {
		$outlet = $this->mastodon();

		if ( null === $outlet || '' === MastodonOutlet::server( $this->channel->settings() ) ) {
			return '';
		}

		$info = $outlet->limit_info();

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html(
				'server' === $info['source']
					? sprintf(
						/* translators: %d: the largest number of characters in a post. */
						_n( 'Your server allows %d character in a post.', 'Your server allows %d characters in a post.', $info['max'], 'estasoft-news-distribution' ),
						$info['max']
					)
					: sprintf(
						/* translators: %d: the largest number of characters in a post. */
						_n( 'Your server did not say how long a post may be, so %d character is used.', 'Your server did not say how long a post may be, so %d characters are used.', $info['max'], 'estasoft-news-distribution' ),
						$info['max']
					)
			)
		);
	}

	/**
	 * The Bluesky card.
	 *
	 * @return string
	 */
	private function bluesky_card(): string {
		$outlet = $this->bluesky();

		if ( null === $outlet ) {
			return '';
		}

		$id     = 'estasoft-nd-card-bluesky';
		$values = $this->values();
		$rows   = array();
		$any    = false;

		foreach ( $this->channel->site_languages() as $code ) {
			$suffix   = AbstractOutlet::suffix( $code );
			$handle   = trim( (string) ( $values[ 'bluesky_handle_' . $suffix ] ?? '' ) );
			$has_pass = ! empty( $values[ 'bluesky_password_' . $suffix . '_is_set' ] );
			$name     = $this->language_name( $code );
			$middle   = $this->text_input(
				'bluesky_handle_' . $suffix,
				sprintf(
					/* translators: %s: a language name such as English. */
					__( '%s handle', 'estasoft-news-distribution' ),
					$name
				),
				array(
					'dir'         => 'ltr',
					'maxlength'   => 253,
					'placeholder' => 'name.bsky.social',
				)
			) . $this->secret_input(
				'bluesky_password_' . $suffix,
				sprintf(
					/* translators: %s: a language name such as English. */
					__( '%s app password', 'estasoft-news-distribution' ),
					$name
				),
				false
			);

			if ( '' === $handle && ! $has_pass ) {
				$middle .= Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %s: a language name such as Kurdish. */
							__( 'Leave both empty to skip %s on Bluesky.', 'estasoft-news-distribution' ),
							$name
						)
					)
				);
				$status = StatusView::inline( Status::NOT_SET_UP, __( 'Not set up', 'estasoft-news-distribution' ) )
					. Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
						esc_html(
							sprintf(
								/* translators: %s: a language name such as Kurdish. */
								__( '%s posts are skipped until you add an account.', 'estasoft-news-distribution' ),
								$name
							)
						)
					);
			} else {
				$any = true;

				if ( '' !== $handle && $has_pass ) {
					$middle .= $this->test_line(
						'bluesky',
						$code,
						sprintf(
							/* translators: %s: a Bluesky handle such as name.bsky.social. */
							__( 'to %s, public', 'estasoft-news-distribution' ),
							BlueskyOutlet::normalise_handle( $handle )
						),
						BlueskyOutlet::normalise_handle( $handle )
					);
				}

				$status = $this->test_status( 'bluesky:' . $code );
			}

			$rows[] = $this->account_row( $code, $middle, $status );
		}

		$steps  = $this->step(
			1,
			__( 'Create an app password', 'estasoft-news-distribution' ),
			__( 'In Bluesky’s settings, create an app password for News Distribution. Use an app password, never your account password, so you can delete it without changing anything else.', 'estasoft-news-distribution' ),
			'',
			false,
			$any
		);
		$steps .= $this->step(
			2,
			__( 'Enter a handle and the app password for each language', 'estasoft-news-distribution' ),
			__( 'Leave a language empty to skip it.', 'estasoft-news-distribution' ),
			$this->table( __( 'Bluesky accounts', 'estasoft-news-distribution' ), __( 'Handle and app password', 'estasoft-news-distribution' ), $rows )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Each test post goes to the one account named beside its button. Bluesky posts are public, so a test post is public too. It says it is a test. Delete it from Bluesky when you have seen it.', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Tokens and passwords are stored encrypted in your database and shown only as dots. They are never written to the delivery log, and they are sent only to the service they belong to.', 'estasoft-news-distribution' ) ),
			! $any,
			false
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $id,
			),
			$this->service_head( $id, $outlet->label(), 'bluesky' ) . $steps
		);
	}

	/**
	 * What is posted and when, and the button that saves and turns the services on.
	 *
	 * @return string
	 */
	private function posting_card(): string {
		$id      = 'estasoft-nd-card-posting';
		$values  = $this->values();
		$minutes = max( 1, (int) round( $this->plugin->settings()->index_first_delay_seconds() / MINUTE_IN_SECONDS ) );

		$content = $this->checkbox( 'social_headline', __( 'The headline', 'estasoft-news-distribution' ) )
			. $this->checkbox( 'social_summary', __( 'A short summary', 'estasoft-news-distribution' ), __( 'Shortened to fit. The headline and the link are never cut.', 'estasoft-news-distribution' ) )
			. $this->checkbox( 'social_card', __( 'A link card on Bluesky', 'estasoft-news-distribution' ), __( 'Title, description and thumbnail. Bluesky does not build the card itself, so News Distribution uploads the thumbnail with the post. Mastodon builds its own preview from the page, so this does not apply there.', 'estasoft-news-distribution' ) )
			. $this->checkbox( 'social_hashtags', __( 'Hashtags from the article’s tags', 'estasoft-news-distribution' ), __( 'Off by default.', 'estasoft-news-distribution' ) );

		$select_id = Field::id( $this->screen->scope_id(), 'mastodon_visibility' );
		$options   = '';

		foreach ( array(
			'public'   => __( 'Everyone (public)', 'estasoft-news-distribution' ),
			'unlisted' => __( 'Everyone, but not in public timelines (unlisted)', 'estasoft-news-distribution' ),
		) as $value => $label ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => $value,
					'selected' => (string) ( $values['mastodon_visibility'] ?? 'public' ) === $value,
				),
				esc_html( $label )
			);
		}

		$rows  = $this->form_row( 'content', __( 'Content', 'estasoft-news-distribution' ), $content );
		$rows .= $this->form_row( 'length', __( 'Length now', 'estasoft-news-distribution' ), $this->lengths() );
		$rows .= Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-form-label',
					'for'   => $select_id,
				),
				esc_html__( 'Who sees a Mastodon post', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-form-control' ),
				Html::tag(
					'select',
					array(
						'class' => 'estasoft-nd-field estasoft-nd-outlets-select',
						'id'    => $select_id,
						'name'  => Field::name( 'mastodon_visibility' ),
					),
					$options
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Applies to Mastodon only. Bluesky posts are always public.', 'estasoft-news-distribution' ) )
			)
		);
		$rows .= $this->form_row(
			'timing',
			__( 'Timing', 'estasoft-news-distribution' ),
			$this->rules(
				array(
					sprintf(
						/* translators: %d: minutes of the index-first delay. */
						_n( 'After your index-first delay (%d minute), so your own page is crawled first', 'After your index-first delay (%d minutes), so your own page is crawled first', $minutes, 'estasoft-news-distribution' ),
						$minutes
					),
					__( 'Breaking news goes at once', 'estasoft-news-distribution' ),
					__( 'One post per language, to that language’s account', 'estasoft-news-distribution' ),
				),
				'check'
			)
		);
		$rows .= $this->form_row(
			'failure',
			__( 'If it fails', 'estasoft-news-distribution' ),
			$this->rules(
				array(
					__( 'Up to 4 attempts, with longer waits between them', 'estasoft-news-distribution' ),
					__( 'A retry never posts twice on Mastodon: each post carries a key that Mastodon remembers for up to an hour', 'estasoft-news-distribution' ),
					__( 'On Bluesky, News Distribution looks at the account’s latest posts before it retries, so the same post is not made twice', 'estasoft-news-distribution' ),
				),
				'check'
			)
		);

		$both_on = Status::NOT_SET_UP !== $this->channel->service_status( 'mastodon' )['code'] && Status::NOT_SET_UP !== $this->channel->service_status( 'bluesky' )['code'];

		$foot = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-row estasoft-nd-stack estasoft-nd-gap-8' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				( $both_on
					? $this->action_button( 'save', __( 'Save changes', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
					: $this->action_button( 'social_on', __( 'Save and turn on', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-outlets-help' ), esc_html( $this->roll_up() ) )
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Saving tests any token you changed first. A service that fails its test stays off and posts nothing, and the other service is not affected. A language left empty is skipped.', 'estasoft-news-distribution' ) )
		);

		return Html::tag(
			'section',
			array(
				'class'           => 'estasoft-nd-card estasoft-nd-card--flush',
				'aria-labelledby' => $id,
			),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card-head' ),
				Html::tag(
					'h2',
					array(
						'class' => 'estasoft-nd-title',
						'id'    => $id,
					),
					esc_html__( 'What is posted, and when', 'estasoft-news-distribution' )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'The same for both. A post goes to the account for its own language.', 'estasoft-news-distribution' ) )
			) . $rows . $foot
		);
	}

	/**
	 * A row of the posting card with a group label.
	 *
	 * @param string $key   Short name, for the label's id.
	 * @param string $label The label.
	 * @param string $inner Escaped HTML of the control.
	 * @return string
	 */
	private function form_row( string $key, string $label, string $inner ): string {
		$id = 'estasoft-nd-group-' . $key;

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-form-row' ),
			Html::tag(
				'div',
				array(
					'class' => 'estasoft-nd-form-label',
					'id'    => $id,
				),
				esc_html( $label )
			)
			. Html::tag(
				'div',
				array(
					'class'           => 'estasoft-nd-form-control',
					'role'            => 'group',
					'aria-labelledby' => $id,
				),
				$inner
			)
		);
	}

	/**
	 * How long the sample post is for each service, as bars.
	 *
	 * @return string
	 */
	private function lengths(): string {
		$mastodon = $this->mastodon();
		$bluesky  = $this->bluesky();

		if ( null === $mastodon || null === $bluesky ) {
			return '';
		}

		list( $article ) = $this->preview_article();

		$settings = $this->channel->settings();
		$info     = $mastodon->limit_info();
		$text     = $mastodon->status_text( $article, $settings );
		$count    = mb_strlen( $text ) - mb_strlen( $article->permalink() ) + $info['link'];
		$max_b    = (int) $this->plugin->rules()->limit( 'outlets', 'bluesky_graphemes_max', 300 );
		$units    = BlueskyOutlet::units( $bluesky->post_text( $article, $settings ) );

		$name_m = 'server' === $info['source']
			? __( 'Mastodon, your server’s limit', 'estasoft-news-distribution' )
			: __( 'Mastodon, the default limit', 'estasoft-news-distribution' );

		return $this->bar( $name_m, $count, $info['max'] )
			. $this->bar( __( 'Bluesky', 'estasoft-news-distribution' ), $units, $max_b )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'For the sample post shown beside this card. Mastodon counts every link as 23 characters, however long it is. Bluesky counts what a reader sees as one character, so an emoji counts once, and it counts the link as written. The link card is separate and adds nothing to the count.', 'estasoft-news-distribution' ) );
	}

	/**
	 * One bar with its caption.
	 *
	 * @param string $label Name of the service.
	 * @param int    $now   Length now.
	 * @param int    $max   Largest length.
	 * @return string
	 */
	private function bar( string $label, int $now, int $max ): string {
		$max     = max( 1, $max );
		$percent = (int) min( 100, round( $now / $max * 100 ) );

		return Html::tag(
			'div',
			array(),
			Html::tag(
				'span',
				array(
					'class'         => 'estasoft-nd-progress',
					'role'          => 'progressbar',
					'aria-valuemin' => '0',
					'aria-valuemax' => (string) $max,
					'aria-valuenow' => (string) $now,
					'aria-label'    => $label,
				),
				Html::tag( 'span', array( 'style' => 'width:' . $percent . '%' ), '' )
			)
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: the name of a service, 2: length now, 3: largest length. */
						_n( '%1$s: %2$d of %3$d character', '%1$s: %2$d of %3$d characters', $max, 'estasoft-news-distribution' ),
						$label,
						$now,
						$max
					)
				)
			)
		);
	}

	/**
	 * What saving does for each service now, in one sentence each.
	 *
	 * @return string
	 */
	private function roll_up(): string {
		$out = array();

		foreach ( array( 'mastodon', 'bluesky' ) as $id ) {
			$outlet = $this->channel->outlet( $id );

			if ( null !== $outlet ) {
				$out[] = ServiceLine::sentence( $this->channel, $outlet );
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * How a post looks on each service.
	 *
	 * @return string
	 */
	private function preview_card(): string {
		$mastodon = $this->mastodon();
		$bluesky  = $this->bluesky();

		if ( null === $mastodon || null === $bluesky ) {
			return '';
		}

		list( $article, $example ) = $this->preview_article();

		$settings = $this->channel->settings();
		$lang     = $article->language();
		$attrs    = array(
			'class' => 'estasoft-nd-outlets-sample',
			'lang'  => $lang,
			'dir'   => Catalog::direction( $lang ),
		);

		$m_blocks = explode( "\n\n", $mastodon->status_text( $article, $settings ) );
		$m_html   = '';

		foreach ( $m_blocks as $index => $block ) {
			$is_url = $article->permalink() === $block;
			$strong = 0 === $index && ! empty( $settings['social_headline'] ) && ! $is_url;

			$m_html .= Html::tag(
				'p',
				array( 'class' => trim( ( $strong ? 'estasoft-nd-strong ' : '' ) . ( $is_url ? 'estasoft-nd-outlets-link estasoft-nd-small' : '' ) ) ),
				esc_html( $block )
			);
		}

		$b_blocks = explode( "\n\n", $bluesky->post_text( $article, $settings ) );
		$b_html   = '';

		foreach ( $b_blocks as $index => $block ) {
			if ( $article->permalink() === $block && ! empty( $settings['social_card'] ) ) {
				continue;
			}

			$b_html .= Html::tag( 'p', array( 'class' => 0 === $index && ! empty( $settings['social_headline'] ) ? 'estasoft-nd-strong' : '' ), esc_html( $block ) );
		}

		if ( ! empty( $settings['social_card'] ) ) {
			$b_html .= $this->link_card( $article );
		}

		return $this->side_card(
			__( 'How a post looks', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				$attrs,
				Html::tag( 'p', array( 'class' => 'estasoft-nd-label' ), esc_html( $mastodon->label() ) )
				. $m_html
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Mastodon adds its own link preview from the page, so it can look different from this.', 'estasoft-news-distribution' ) )
			)
			. Html::tag( 'div', $attrs, Html::tag( 'p', array( 'class' => 'estasoft-nd-label' ), esc_html( $bluesky->label() ) ) . $b_html )
			. $this->example_tag( $example )
		);
	}

	/**
	 * The link card Bluesky shows under a post: the thumbnail when there is one, the host and the title.
	 *
	 * @param Article $article The article.
	 * @return string
	 */
	private function link_card( Article $article ): string {
		$host  = (string) wp_parse_url( $article->permalink(), PHP_URL_HOST );
		$thumb = '' === $article->thumbnail_url()
			? ''
			: Html::void(
				'img',
				array(
					'class'   => 'estasoft-nd-outlets-thumb',
					'src'     => $article->thumbnail_url(),
					'alt'     => '',
					'loading' => 'lazy',
				)
			);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-outlets-linkcard' ),
			$thumb . Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-outlets-linkcard__text' ),
				Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $host ) )
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-strong' ), esc_html( $article->title() ) )
			)
		);
	}

	/**
	 * The articles sent or waiting most recently, with what happened at each service.
	 *
	 * @return string
	 */
	private function recent_card(): string {
		$services = array();

		foreach ( array( 'mastodon', 'bluesky' ) as $id ) {
			$outlet = $this->channel->outlet( $id );

			if ( null !== $outlet && Status::NOT_SET_UP !== $this->channel->service_status( $id )['code'] ) {
				$services[ $id ] = $outlet;
			}
		}

		if ( array() === $services ) {
			return '';
		}

		$posts = array();

		// Articles still in the queue come first.
		$queued = $this->plugin->log()->query(
			array(
				'channel'  => 'outlets',
				'status'   => Status::QUEUED,
				'per_page' => 6,
			)
		)['rows'];

		foreach ( $queued as $row ) {
			$latest = $this->plugin->log()->latest_for_post( (int) $row['post_id'] );

			if ( isset( $latest['outlets'] ) && Status::QUEUED === $latest['outlets']['status'] && ! isset( $posts[ (int) $row['post_id'] ] ) ) {
				$posts[ (int) $row['post_id'] ] = array(
					'title'    => get_the_title( (int) $row['post_id'] ),
					'language' => (string) $row['language'],
					'waiting'  => (int) $row['run_at_ts'],
					'rows'     => array(),
				);
			}
		}

		foreach ( $this->state()->recent() as $entry ) {
			if ( ! isset( $posts[ $entry['post'] ] ) ) {
				$posts[ $entry['post'] ] = array(
					'title'    => $entry['title'],
					'language' => $this->plugin->languages()->language_of_post( $entry['post'] ),
					'waiting'  => 0,
					'rows'     => $entry['rows'],
				);
			}
		}

		$html  = '';
		$count = 0;

		foreach ( $posts as $post ) {
			if ( ++$count > 4 ) {
				break;
			}

			$items = '';

			foreach ( $services as $id => $outlet ) {
				$items .= Html::tag(
					'li',
					array( 'class' => 'estasoft-nd-outlets-recent' ),
					$this->lang( $post['language'] ) . Html::tag( 'span', array(), esc_html( $outlet->label() ) ) . Html::tag( 'span', array(), $this->recent_status( $outlet, $post ) )
				);
			}

			$html .= Html::tag( 'p', array( 'class' => 'estasoft-nd-label' ), esc_html( $post['title'] ) )
				. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $items );
		}

		if ( '' === $html ) {
			$html = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing has been posted yet. New articles appear here.', 'estasoft-news-distribution' ) );
		}

		return $this->side_card( __( 'Recent posts', 'estasoft-news-distribution' ), $html );
	}

	/**
	 * What happened to one article at one service.
	 *
	 * @param OutletInterface                                                                                 $outlet The service.
	 * @param array{title: string, language: string, waiting: int, rows: array<string, array<string, mixed>>} $post   The article.
	 * @return string
	 */
	private function recent_status( OutletInterface $outlet, array $post ): string {
		$settings = $this->channel->settings();
		$target   = null;

		foreach ( $outlet->targets( $settings, $this->channel->site_languages() ) as $candidate ) {
			if ( $candidate->language() === $post['language'] && $candidate->is_complete() ) {
				$target = $candidate;
			}
		}

		if ( null === $target ) {
			return StatusView::inline(
				Status::FAILED,
				sprintf(
					/* translators: %s: a language name such as Kurdish. */
					__( 'Skipped: no %s account', 'estasoft-news-distribution' ),
					$this->language_name( $post['language'] )
				)
			);
		}

		if ( $post['waiting'] > 0 ) {
			return StatusView::inline(
				Status::QUEUED,
				sprintf(
					/* translators: %s: a time such as 14:38. */
					__( 'Waiting %s', 'estasoft-news-distribution' ),
					Clock::format( $post['waiting'] )
				)
			);
		}

		$row = $post['rows'][ $target->id() ] ?? null;

		if ( null === $row ) {
			return StatusView::inline( Status::NEVER, __( 'Not sent', 'estasoft-news-distribution' ) );
		}

		return 'sent' === $row['status']
			? StatusView::inline(
				Status::SENT,
				sprintf(
					/* translators: %s: a time such as 13:56. */
					__( 'Sent %s', 'estasoft-news-distribution' ),
					$this->when( (int) $row['time'] )
				)
			)
			: StatusView::inline(
				Status::FAILED,
				sprintf(
					/* translators: %s: a time such as 13:56. */
					__( 'Failed %s', 'estasoft-news-distribution' ),
					$this->when( (int) $row['time'] )
				)
			);
	}

	/**
	 * What the services limit, and what News Distribution does about it.
	 *
	 * @return string
	 */
	private function limits_card(): string {
		$default = (int) $this->plugin->rules()->limit( 'outlets', 'mastodon_default_max', 500 );

		return $this->side_card(
			__( 'Limits', 'estasoft-news-distribution' ),
			$this->rules(
				array(
					sprintf(
						/* translators: %d: the number of characters used when a server does not say. */
						__( 'Mastodon servers set their own character limit and rules. News Distribution asks your server for its limit when you connect, and uses %d when the server does not say.', 'estasoft-news-distribution' ),
						$default
					),
					__( 'News Distribution waits when a server asks it to slow down.', 'estasoft-news-distribution' ),
					__( 'It cannot post to a Mastodon or Bluesky account you do not hold a token for, and it does not read replies.', 'estasoft-news-distribution' ),
				)
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'These services change their rules. Check them before you post at volume.', 'estasoft-news-distribution' ) )
		);
	}
}
