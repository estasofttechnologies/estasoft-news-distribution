<?php
/**
 * The Apple News channel page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\AppleNews\Markings;
use Estasoft\NewsDistribution\Channels\AppleNews\PageTags;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=apple-news`. Apple takes feeds
 * through News Publisher and decides for itself, so the page shows the feed
 * addresses, a tick box for "I requested approval from Apple" (a status the
 * owner sets by hand), the options for what the feed carries, the tags Apple
 * reads from article pages (checked on request), and the articles the owner
 * labeled syndicated or AI-assisted. It never shows a status it cannot read.
 */
final class AppleNewsScreen extends AbstractPartnerScreen {

	/**
	 * Apple's own guide to RSS feeds in News Publisher.
	 */
	private const RSS_GUIDE = 'https://support.apple.com/en-gu/guide/news-publisher/apdc2c7520ff/icloud';

	/**
	 * Apple's News Publisher guide.
	 */
	private const PUBLISHER_GUIDE = 'https://support.apple.com/guide/news-publisher/';

	public static function channel_id(): string {
		return 'apple-news';
	}

	public function description(): string {
		return __( 'Pulled channel: News Distribution builds the feed and Apple fetches it only after it approves your channel. Apple decides, and News Distribution cannot see its answer.', 'estasoft-news-distribution' );
	}

	protected function known_actions(): array {
		return array_merge( parent::known_actions(), array( 'tags' => 'post' ) );
	}

	protected function perform( string $action, array $input ): array {
		if ( 'tags' !== $action ) {
			return parent::perform( $action, $input );
		}

		$articles = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			if ( ! $this->channel->accepts_language( (string) $language ) ) {
				continue;
			}

			foreach ( $this->plugin->reader()->recent( (string) $language, 30 * DAY_IN_SECONDS, PageTags::LIMIT ) as $article ) {
				$articles[ $article->id() ] = $article;
			}
		}

		usort(
			$articles,
			static function ( $a, $b ): int {
				return $b->published_ts() <=> $a->published_ts();
			}
		);

		$result = PageTags::check( $articles );
		PageTags::store( $result );

		if ( 0 === (int) $result['total'] ) {
			return $this->remember( false, __( 'There are no recent articles to check.', 'estasoft-news-distribution' ) );
		}

		if ( 0 === (int) $result['reached'] ) {
			return $this->remember( false, __( 'Could not open your article pages from this site, so the tags were not read.', 'estasoft-news-distribution' ) );
		}

		return $this->remember(
			true,
			sprintf(
				/* translators: 1: the number of pages read, 2: the number of articles. */
				_n( 'Read the tags on %1$d of %2$d page.', 'Read the tags on %1$d of %2$d pages.', (int) $result['reached'], 'estasoft-news-distribution' ),
				(int) $result['reached'],
				(int) $result['total']
			)
		);
	}

	public function render( string $tab ): string {
		$main = $this->steps_card() . $this->marking_card();
		$side = $this->tags_card() . $this->rules();

		return $this->head( __( 'Its feed addresses answer 404 until you turn it on.', 'estasoft-news-distribution' ) )
			. $this->banner()
			. $this->save_form(
				$this->split( $main, $side )
				. $this->cannot_card( __( 'It cannot see Apple’s review, approve the channel for you, make Apple fetch the feed, or publish in News Publisher. Apple decides. Until Apple first fetches your feed, News Distribution shows no visits and no Healthy status for Apple News.', 'estasoft-news-distribution' ) )
			)
			. $this->forms();
	}

	/**
	 * The bar that says what the status means while Apple has not fetched the feed.
	 *
	 * @return string
	 */
	private function banner(): string {
		if ( ! $this->channel->is_enabled() || $this->has_been_fetched() ) {
			return '';
		}

		$row  = $this->plugin->applications()->get( 'apple-news' );
		$lead = in_array( $row['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) && '' !== $row['applied_on']
			? sprintf(
				/* translators: 1: an application status such as In review, 2: a date such as 28 Sep. */
				__( '%1$s since %2$s.', 'estasoft-news-distribution' ),
				Status::word( $row['status'] ),
				$this->short_date( $row['applied_on'] )
			)
			: sprintf(
				/* translators: %s: an application status such as Not requested. */
				__( '%s.', 'estasoft-news-distribution' ),
				Status::word( $row['status'] )
			);

		return Message::bar(
			'info',
			Html::tag( 'strong', array(), esc_html( $lead ) ) . ' ' . esc_html__( 'You set this status by hand. Apple has not fetched the feed yet, so this page shows no visits and no fetch times. They appear only after Apple first fetches your feed.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The three steps: the feed, the channel in News Publisher, the options.
	 *
	 * @return string
	 */
	private function steps_card(): string {
		$row = $this->plugin->applications()->get( 'apple-news' );

		return $this->steps(
			'apple-news',
			__( 'Set up Apple News', 'estasoft-news-distribution' ),
			array(
				array(
					'id'    => 'feed',
					'title' => __( 'Your feed is ready', 'estasoft-news-distribution' ),
					'text'  => __( 'News Distribution builds one Apple News feed for each language, with the full text of each article.', 'estasoft-news-distribution' ),
					'state' => 'done',
					'body'  => $this->feed_lines(
						__( 'Apple News feed', 'estasoft-news-distribution' ),
						static function ( array $check ): string {
							return sprintf(
								/* translators: %s: the number of articles in the feed. */
								_n( 'Valid RSS, %s item', 'Valid RSS, %s items', (int) $check['entries'], 'estasoft-news-distribution' ),
								number_format_i18n( (int) $check['entries'] )
							);
						},
						true
					),
				),
				array(
					'id'    => 'create',
					'title' => __( 'Create your channel in News Publisher', 'estasoft-news-distribution' ),
					'text'  => __( 'Apple takes feeds through News Publisher. Create a channel, add these feed URLs and request approval. Apple decides, and News Distribution cannot see the result.', 'estasoft-news-distribution' ),
					'state' => Status::LISTED === $row['status'] ? 'done' : 'now',
					'body'  => $this->approval_body( $row ),
				),
				array(
					'id'    => 'choose',
					'title' => __( 'Choose what Apple receives', 'estasoft-news-distribution' ),
					'text'  => __( 'These options apply to this feed only.', 'estasoft-news-distribution' ),
					'state' => '',
					'body'  => Html::tag(
						'div',
						array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ),
						$this->field( 'warn_title_length' ) . $this->field( 'strip_markup' ) . $this->field( 'index_first' )
					),
				),
			)
		);
	}

	/**
	 * The tick box, what the status means and the links to Apple's pages.
	 *
	 * @param array<string, string> $row The application record.
	 * @return string
	 */
	private function approval_body( array $row ): string {
		$ticked = Status::NOT_REQUESTED !== $row['status'];

		$box = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => self::APP . '[requested]',
				'value' => '0',
			)
		) . Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check' ),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'name'    => self::APP . '[requested]',
					'value'   => '1',
					'checked' => $ticked,
				)
			) . Html::tag( 'span', array(), esc_html__( 'I requested approval from Apple', 'estasoft-news-distribution' ) )
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ),
			$box
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->status_line( $row ) ) )
			. $this->external_link( __( 'News Publisher', 'estasoft-news-distribution' ), self::PUBLISHER_GUIDE )
			. $this->external_link( __( 'Apple’s RSS guidelines', 'estasoft-news-distribution' ), self::RSS_GUIDE )
		);
	}

	/**
	 * What the recorded status means, in one or two sentences.
	 *
	 * @param array<string, string> $row The application record.
	 * @return string
	 */
	private function status_line( array $row ): string {
		$word = Status::word( $row['status'] );

		if ( Status::NOT_REQUESTED === $row['status'] ) {
			return sprintf(
				/* translators: %s: an application status. */
				__( 'Status: %s. Tick the box once you have asked Apple. You set the status by hand.', 'estasoft-news-distribution' ),
				$word
			);
		}

		if ( in_array( $row['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) ) {
			return sprintf(
				/* translators: 1: an application status such as In review, 2: a date such as 28 Sep. */
				__( 'Status: %1$s. You applied on %2$s and set the status by hand. When Apple answers, update it in Applications. News Distribution marks the channel Healthy after Apple’s first fetch.', 'estasoft-news-distribution' ),
				$word,
				'' === $row['applied_on'] ? __( 'a day you did not record', 'estasoft-news-distribution' ) : $this->short_date( $row['applied_on'] )
			);
		}

		return sprintf(
			/* translators: %s: an application status such as Listed. */
			__( 'Status: %s. You set this by hand. News Distribution cannot check it with Apple. Change it in Applications when it changes.', 'estasoft-news-distribution' ),
			$word
		);
	}

	/**
	 * "Syndicated and AI-assisted articles": the counts and the list.
	 *
	 * @return string
	 */
	private function marking_card(): string {
		$rows   = Markings::recent( $this->plugin );
		$counts = Markings::counts( $rows );
		$badges = Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-badge estasoft-nd-badge--info' ),
			Icon::svg( 'info' ) . esc_html(
				sprintf(
					/* translators: %d: the number of syndicated articles. */
					_n( '%d syndicated', '%d syndicated', $counts['syndicated'], 'estasoft-news-distribution' ),
					$counts['syndicated']
				)
			)
		) . Html::tag(
			'span',
			array( 'class' => 'estasoft-nd-badge estasoft-nd-badge--info' ),
			Icon::svg( 'info' ) . esc_html(
				sprintf(
					/* translators: %d: the number of AI-assisted articles. */
					_n( '%d AI-assisted', '%d AI-assisted', $counts['ai'], 'estasoft-news-distribution' ),
					$counts['ai']
				)
			)
		);

		$list = '';

		foreach ( $rows as $row ) {
			$marks = array();

			if ( $row['syndicated'] ) {
				$marks[] = __( 'Syndicated', 'estasoft-news-distribution' );
			}

			if ( $row['ai'] ) {
				$marks[] = __( 'AI-assisted', 'estasoft-news-distribution' );
			}

			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
				Html::tag(
					'a',
					array( 'href' => get_edit_post_link( (int) $row['id'], 'raw' ) ),
					esc_html( (string) $row['title'] )
				) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( implode( ', ', $marks ) ) )
			);
		}

		$body = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			esc_html__( 'Apple News asks you to mark syndicated and AI-generated content in News Publisher. News Distribution cannot mark it for you: it lists the articles you labeled so you can find them and mark them there.', 'estasoft-news-distribution' )
		) . Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $badges );

		if ( '' !== $list ) {
			$body .= Html::tag(
				'details',
				array( 'class' => 'estasoft-nd-which' ),
				Html::tag( 'summary', array(), esc_html__( 'See which articles', 'estasoft-news-distribution' ) )
				. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list )
			);
		}

		return Card::note( 'marking', __( 'Syndicated and AI-assisted articles', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * "Tags Apple reads from your pages": what the last check found, and the button that runs it.
	 *
	 * @return string
	 */
	private function tags_card(): string {
		$stored = PageTags::stored();
		$check  = $this->post_button(
			'tags',
			array() === $stored ? __( 'Check my pages', 'estasoft-news-distribution' ) : __( 'Check again', 'estasoft-news-distribution' ),
			array( 'size' => 'sm' )
		);
		$intro  = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted' ),
			esc_html__( 'Apple takes the author, thumbnail and language from each article page, not from the feed.', 'estasoft-news-distribution' )
		);

		if ( array() === $stored || 0 === (int) ( $stored['reached'] ?? 0 ) ) {
			return Card::note(
				'tags',
				__( 'Tags Apple reads from your pages', 'estasoft-news-distribution' ),
				$intro
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Not checked yet. The check opens your latest article pages from this site and reads the tags. It reaches no other site.', 'estasoft-news-distribution' ) )
				. Html::tag( 'div', array(), $check )
			);
		}

		$totals  = PageTags::totals( $stored );
		$reached = $totals['reached'];
		$lines   = '';
		$lines  .= Html::tag(
			'li',
			array(),
			StatusView::inline(
				$totals['author'] === $reached ? Status::CLEAN : Status::WARN,
				sprintf(
					/* translators: 1: the number of articles with the tag, 2: the number of articles read. */
					_n( 'Author tag on %1$d of %2$d article', 'Author tag on %1$d of %2$d articles', $reached, 'estasoft-news-distribution' ),
					$totals['author'],
					$reached
				)
			)
		);

		$image = sprintf(
			/* translators: 1: the number of articles with the tag, 2: the number of articles read. */
			_n( 'Page image tag on %1$d of %2$d article', 'Page image tag on %1$d of %2$d articles', $reached, 'estasoft-news-distribution' ),
			$totals['image'],
			$reached
		);

		if ( $totals['no_featured'] > 0 ) {
			$image .= ' ' . sprintf(
				/* translators: %d: the number of articles with no featured image. */
				_n( '(%d has no featured image)', '(%d have no featured image)', $totals['no_featured'], 'estasoft-news-distribution' ),
				$totals['no_featured']
			);
		}

		$lines .= Html::tag( 'li', array(), StatusView::inline( $totals['image'] === $reached ? Status::CLEAN : Status::WARN, $image ) );

		$lines .= Html::tag(
			'li',
			array(),
			StatusView::inline(
				0 === $totals['missing_language'] ? Status::CLEAN : Status::WARN,
				0 === $totals['missing_language']
					? sprintf(
						/* translators: 1: the number of articles with the tag, 2: the number of articles read. */
						_n( 'Language tag on %1$d of %2$d article', 'Language tag on %1$d of %2$d articles', $reached, 'estasoft-news-distribution' ),
						$totals['language'],
						$reached
					)
					: sprintf(
						/* translators: %d: the number of articles without the tag. */
						_n( 'Language tag missing on %d article', 'Language tag missing on %d articles', $totals['missing_language'], 'estasoft-news-distribution' ),
						$totals['missing_language']
					)
			)
		);

		$body = $intro
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $lines )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: the number of articles read, 2: when the check ran, such as "today at 14:20" or "21 Oct 2026, 09:10". */
						_n( 'Technical names: og:image (page image tag) and og:locale (language tag). Counts cover %1$d article, checked %2$s.', 'Technical names: og:image (page image tag) and og:locale (language tag). Counts cover the last %1$d articles, checked %2$s.', $reached, 'estasoft-news-distribution' ),
						$reached,
						Clock::format( (int) $stored['checked_at'], 'Y-m-d' ) === Clock::format( Clock::now(), 'Y-m-d' )
							? sprintf(
								/* translators: %s: a time such as 14:20. */
								__( 'today at %s', 'estasoft-news-distribution' ),
								Clock::format( (int) $stored['checked_at'], 'H:i' )
							)
							: Clock::datetime( (int) $stored['checked_at'] )
					)
				)
			)
			. $this->unfinished_list( $stored )
			. Html::tag( 'div', array(), $check );

		return Card::note( 'tags', __( 'Tags Apple reads from your pages', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * A list of the articles a check found something missing on.
	 *
	 * @param array<string, mixed> $stored The stored check.
	 * @return string
	 */
	private function unfinished_list( array $stored ): string {
		$items = '';

		foreach ( (array) ( $stored['rows'] ?? array() ) as $row ) {
			if ( empty( $row['fetched'] ) ) {
				continue;
			}

			$missing = array();

			if ( empty( $row['author'] ) ) {
				$missing[] = __( 'author', 'estasoft-news-distribution' );
			}

			if ( empty( $row['image'] ) ) {
				$missing[] = __( 'image', 'estasoft-news-distribution' );
			}

			if ( empty( $row['tag_lang'] ) ) {
				$missing[] = __( 'language', 'estasoft-news-distribution' );
			}

			if ( array() === $missing ) {
				continue;
			}

			$items .= Html::tag(
				'li',
				array(),
				Html::tag( 'a', array( 'href' => get_edit_post_link( (int) $row['id'], 'raw' ) ), esc_html( (string) $row['title'] ) )
				. ' ' . Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
					esc_html(
						sprintf(
							/* translators: %s: a list of missing tags, such as "image, language". */
							__( 'Missing: %s', 'estasoft-news-distribution' ),
							implode( ', ', $missing )
						)
					)
				)
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return Html::tag(
			'details',
			array( 'class' => 'estasoft-nd-which' ),
			Html::tag( 'summary', array(), esc_html__( 'See which articles', 'estasoft-news-distribution' ) )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $items )
		);
	}

	/**
	 * "Rules News Distribution applies", with the technical name of the feed link.
	 *
	 * @return string
	 */
	private function rules(): string {
		$card = $this->rules_card(
			'apple-news',
			__( 'Apple', 'estasoft-news-distribution' ),
			array(
				'feed.format'          => __( 'RSS 2.0 in UTF-8, over HTTPS', 'estasoft-news-distribution' ),
				'item.required_fields' => __( 'Title, link and description on every item', 'estasoft-news-distribution' ),
				'body.full_text'       => __( 'Full text in the feed', 'estasoft-news-distribution' ),
				'feed.atom_self'       => __( 'A link to the feed’s own address', 'estasoft-news-distribution' ),
				'body.clean'           => __( 'No ads, related-story or social links in the body', 'estasoft-news-distribution' ),
				'guid.stable'          => __( 'Same guid when an article is updated', 'estasoft-news-distribution' ),
			)
		);

		$note = Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'Technical name for the feed link: atom:link with rel=self.', 'estasoft-news-distribution' )
		);

		return (string) preg_replace( '#</ul>#', '</ul>' . $note, $card, 1 );
	}
}
