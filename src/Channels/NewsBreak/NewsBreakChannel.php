<?php
/**
 * News Break channel: the rules that decide what it would receive.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\NewsBreak;

use Estasoft\NewsDistribution\Channels\AbstractChannel;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Channels\ValidationResult;
use Estasoft\NewsDistribution\Core\Article;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * News Break is applied for by hand and News Distribution serves no feed for it
 * yet, so this channel is manual: it holds the rules that decide which articles
 * would go into a feed (categories, tags, authors, post types, languages, a
 * featured image), shows what the rules do to the latest articles, and records
 * the application as a status the owner sets by hand (Core\Applications). It
 * cannot read News Break's answer. Your own site is never affected by the rules.
 *
 * The rule pack (rules/news-break.json) says where each rule comes from: News
 * Break focuses on local news (a third-party guide), and everything else is
 * News Distribution's own rule.
 */
final class NewsBreakChannel extends AbstractChannel {

	/**
	 * How many of the latest articles the preview covers.
	 */
	public const PREVIEW = 30;

	public function id(): string {
		return 'news-break';
	}

	public function label(): string {
		return __( 'News Break', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return Group::PARTNER;
	}

	public function order(): int {
		return 50;
	}

	public function mode(): string {
		return self::MODE_MANUAL;
	}

	public function gate_note(): string {
		return __( 'Categories, tags, authors and a featured image decide what this channel would receive.', 'estasoft-news-distribution' );
	}

	public function fields(): array {
		$list = static function ( string $label, string $help = '' ): array {
			return array(
				'type'      => 'list',
				'default'   => array(),
				'max_items' => 100,
				'item'      => array(
					'type' => 'int',
					'min'  => 1,
				),
				'label'     => $label,
				'help'      => $help,
			);
		};

		return array(
			'categories_include' => $list(
				__( 'Categories to include', 'estasoft-news-distribution' ),
				__( 'News Break focuses on local news, so local categories are the usual choice.', 'estasoft-news-distribution' )
			),
			'categories_exclude' => $list( __( 'Categories to exclude', 'estasoft-news-distribution' ) ),
			'tags_exclude'       => array(
				'type'      => 'list',
				'default'   => array(),
				'max_items' => 100,
				'item'      => array(
					'type' => 'string',
					'max'  => 60,
				),
				'label'     => __( 'Tags to exclude', 'estasoft-news-distribution' ),
			),
			'authors_mode'       => array(
				'type'    => 'enum',
				'default' => 'everyone',
				'label'   => __( 'Authors', 'estasoft-news-distribution' ),
				'options' => array(
					'everyone' => __( 'Everyone', 'estasoft-news-distribution' ),
					'chosen'   => __( 'Only chosen authors', 'estasoft-news-distribution' ),
				),
				'help'    => __( 'Anyone who can publish on the site. Choose “Only chosen authors” to pick people.', 'estasoft-news-distribution' ),
			),
			'authors'            => $list( __( 'Chosen authors', 'estasoft-news-distribution' ) ),
			'post_types'         => array(
				'type'      => 'list',
				'default'   => array( 'post' ),
				'max_items' => 40,
				'item'      => array(
					'type' => 'string',
					'max'  => 60,
				),
				'label'     => __( 'Post types', 'estasoft-news-distribution' ),
			),
			'languages_off'      => array(
				'type'      => 'list',
				'default'   => array(),
				'max_items' => 100,
				'item'      => array(
					'type' => 'string',
					'max'  => 20,
				),
				'label'     => __( 'Languages left out', 'estasoft-news-distribution' ),
			),
			'require_image'      => array(
				'type'    => 'bool',
				'default' => true,
				'control' => 'checkbox',
				'label'   => __( 'A featured image', 'estasoft-news-distribution' ),
				'help'    => __( 'Articles without a featured image are skipped for this channel when this is ticked.', 'estasoft-news-distribution' ),
			),
		);
	}

	public function validate( Article $article ): ValidationResult {
		$result = new ValidationResult();

		foreach ( $this->rules()->failures( $article ) as $failure ) {
			$result->add( $this->context->rules->severity( $this->id(), $failure['rule'], Status::BLOCK ), $failure['rule'], $failure['text'] );
		}

		return $result;
	}

	/**
	 * News Distribution serves no feed for News Break, so there is nothing to build.
	 *
	 * @param Article[] $articles Unused.
	 * @param string    $language Unused.
	 * @param int|null  $now      Unused.
	 * @return string
	 */
	public function build( array $articles, string $language, ?int $now = null ): string {
		unset( $articles, $language, $now );

		return '';
	}

	/**
	 * Until an application is recorded the pill follows the channel's switch
	 * (Not set up, then Healthy because nothing is fetched). Once the owner
	 * records one, it shows that hand-set status, or Needs attention when the
	 * reminder date has passed: News Distribution cannot see News Break's answer.
	 *
	 * @return string A Status code.
	 */
	public function health(): string {
		if ( ! $this->is_enabled() ) {
			return Status::NOT_SET_UP;
		}

		$status = $this->context->applications->display_status( $this->id() );

		return Status::NOT_REQUESTED === $status ? parent::health() : $status;
	}

	/**
	 * The rules as they are saved now.
	 *
	 * @return FeedRules
	 */
	public function rules(): FeedRules {
		return new FeedRules( $this->settings(), $this->context->settings->index_first_delay_seconds() );
	}

	/**
	 * What the rules do to the latest articles: one row each, newest first.
	 *
	 * @param int $now   Unix time to treat as now.
	 * @param int $limit How many articles to cover.
	 * @return array{rows: array<int, array{article: Article, result: array<string, mixed>}>, counts: array<string, int>, delay: int}
	 */
	public function preview( int $now, int $limit = self::PREVIEW ): array {
		$reader   = Plugin::instance()->reader();
		$articles = array();

		foreach ( $this->context->languages->languages() as $language ) {
			foreach ( $reader->recent( (string) $language, 30 * DAY_IN_SECONDS, $limit ) as $article ) {
				$articles[ $article->id() ] = $article;
			}
		}

		usort(
			$articles,
			static function ( Article $a, Article $b ): int {
				return $b->published_ts() <=> $a->published_ts();
			}
		);

		$rules   = $this->rules();
		$rows    = array();
		$results = array();

		foreach ( array_slice( $articles, 0, $limit ) as $article ) {
			$result    = $rules->result( $article, $now );
			$results[] = $result;
			$rows[]    = array(
				'article' => $article,
				'result'  => $result,
			);
		}

		return array(
			'rows'   => $rows,
			'counts' => FeedRules::counts( $results ),
			'delay'  => (int) round( $this->context->settings->index_first_delay_seconds() / MINUTE_IN_SECONDS ),
		);
	}

	/**
	 * The readiness checks on the Setup tab: what is set, and what the rules do
	 * to the latest articles. Each is a Status code (clean, warn or info) and a
	 * plain sentence.
	 *
	 * @param int $now Unix time to treat as now.
	 * @return array<int, array{code: string, status: string, text: string}>
	 */
	public function readiness( int $now ): array {
		$settings = $this->settings();
		$preview  = $this->preview( $now );
		$counts   = $preview['counts'];
		$checks   = array();

		$checks[] = array() !== (array) $settings['categories_include']
			? $this->check( 'focus.local', Status::CLEAN, __( 'Categories to include are chosen', 'estasoft-news-distribution' ) )
			: $this->check( 'focus.local', Status::INFO, __( 'No category is chosen, so every category is included. News Break focuses on local news, so local categories are the usual choice.', 'estasoft-news-distribution' ) );

		$checks[] = ! empty( $settings['require_image'] )
			? $this->check( 'rules.image_missing', Status::CLEAN, __( 'Articles without a featured image are skipped', 'estasoft-news-distribution' ) )
			: $this->check( 'rules.image_missing', Status::INFO, __( 'Articles without a featured image are allowed', 'estasoft-news-distribution' ) );

		$checks[] = $counts['match'] > 0
			? $this->check(
				'rules.match',
				Status::CLEAN,
				sprintf(
					/* translators: 1: the number of articles that pass the rules, 2: the number of latest articles checked. */
					_n( '%1$d of your last %2$d article passes the rules', '%1$d of your last %2$d articles pass the rules', $counts['match'], 'estasoft-news-distribution' ),
					$counts['match'],
					$counts['total']
				)
			)
			: $this->check( 'rules.match', Status::WARN, __( 'None of your latest articles passes the rules, so nothing would go into a feed', 'estasoft-news-distribution' ) );

		$row = $this->context->applications->get( $this->id() );

		$checks[] = Status::NOT_REQUESTED === $row['status']
			? $this->check( 'channel.manual', Status::INFO, __( 'You have not recorded an application. News Distribution cannot see News Break’s answer, so you record it here.', 'estasoft-news-distribution' ) )
			: $this->check(
				'channel.manual',
				Status::CLEAN,
				sprintf(
					/* translators: %s: an application status such as In review. */
					__( 'Application recorded as %s. You set this by hand.', 'estasoft-news-distribution' ),
					Status::word( $row['status'] )
				)
			);

		return $checks;
	}

	/**
	 * The plain-text line under the channel's name on the hub.
	 *
	 * @return string
	 */
	protected function hub_summary(): string {
		$row = $this->context->applications->get( $this->id() );

		if ( in_array( $row['status'], array( Status::REQUESTED, Status::IN_REVIEW ), true ) && '' !== $row['applied_on'] ) {
			$time = strtotime( $row['applied_on'] . ' 12:00:00 UTC' );

			return sprintf(
				/* translators: 1: an application status such as In review, 2: a date such as 28 Sep. */
				__( '%1$s since %2$s', 'estasoft-news-distribution' ),
				Status::word( $row['status'] ),
				false === $time ? $row['applied_on'] : gmdate( 'j M', $time )
			);
		}

		return __( 'Rules choose which articles it would receive.', 'estasoft-news-distribution' );
	}

	/**
	 * One readiness check.
	 *
	 * @param string $code   A rule code in the pack, or a name of its own.
	 * @param string $status A Status code.
	 * @param string $text   A plain sentence.
	 * @return array{code: string, status: string, text: string}
	 */
	private function check( string $code, string $status, string $text ): array {
		return array(
			'code'   => $code,
			'status' => $status,
			'text'   => $text,
		);
	}
}
