<?php
/**
 * Settings, Video.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\Switcher;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Core\Settings\Section;
use Estasoft\NewsDistribution\Markup\NewsArticleJsonLd;
use Estasoft\NewsDistribution\Markup\VideoJsonLd;

defined( 'ABSPATH' ) || exit;

/**
 * VideoObject markup for the videos in an article, and the optional lookup of their details.
 *
 * Stored as `estasoft_nd_settings.video`:
 *
 * - `enabled`: add the markup. On by default; a video is printed only when it has a name, a thumbnail and an upload date.
 * - `youtube`, `vimeo`, `own`: which sites' videos get markup. `own` is video the site hosts itself (a Video block,
 *   a video tag or a video file address).
 * - `which`: `all` or `first`.
 * - `no_date`: `flag` (the video is left out and Article check lists it as a risk) or `ask` (the video is left out and
 *   Article check lists it as a tip, and the editor panel asks for the date). Either way the article's own date is never used.
 * - `lookup`: ask the video site for the details, in the queue. Off by default.
 *
 * The markup is printed by `Modules\VideoModule` through `Markup\VideoJsonLd`.
 */
final class Video extends Section {

	public const NAME = 'video';

	public const ALL   = 'all';
	public const FIRST = 'first';

	public const FLAG = 'flag';
	public const ASK  = 'ask';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return array(
			'enabled' => array(
				'type'    => 'bool',
				'label'   => __( 'Embedded videos', 'estasoft-news-distribution' ),
				'default' => true,
			),
			'youtube' => array(
				'type'    => 'bool',
				'label'   => __( 'YouTube', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'YouTube', 'estasoft-news-distribution' ),
			),
			'vimeo'   => array(
				'type'    => 'bool',
				'label'   => __( 'Vimeo', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'Vimeo', 'estasoft-news-distribution' ),
			),
			'own'     => array(
				'type'    => 'bool',
				'label'   => __( 'Your own videos', 'estasoft-news-distribution' ),
				'default' => true,
				'control' => 'checkbox',
				'title'   => __( 'Videos on your own site', 'estasoft-news-distribution' ),
				'help'    => __( 'A video from your media library or a video file address, in a Video block or a video tag.', 'estasoft-news-distribution' ),
			),
			'which'   => array(
				'type'    => 'enum',
				'label'   => __( 'Which videos', 'estasoft-news-distribution' ),
				'default' => self::ALL,
				'control' => 'radio',
				'options' => array(
					self::ALL   => __( 'Every video in the article', 'estasoft-news-distribution' ),
					self::FIRST => array(
						'label' => __( 'The first video only', 'estasoft-news-distribution' ),
						'help'  => __( 'Later videos get no markup. Use it when the first video is the story and the rest are small clips.', 'estasoft-news-distribution' ),
					),
				),
			),
			'no_date' => array(
				'type'    => 'enum',
				'label'   => __( 'When a video has no upload date', 'estasoft-news-distribution' ),
				'default' => self::FLAG,
				'control' => 'select',
				'options' => array(
					self::FLAG => __( 'Leave it out and flag it', 'estasoft-news-distribution' ),
					self::ASK  => __( 'Ask me in the editor', 'estasoft-news-distribution' ),
				),
				'width'   => 360,
				'help'    => __( 'A flagged video shows in Article check as a risk; with “Ask me in the editor” it shows as a tip, and the article panel asks for the date. Either way the video gets no markup until it has a date. News Distribution never uses the article’s date in its place, because that would tell search the video is newer than it is.', 'estasoft-news-distribution' ),
			),
			'lookup'  => array(
				'type'    => 'bool',
				'label'   => __( 'Look up details', 'estasoft-news-distribution' ),
				'default' => false,
			),
		);
	}

	public function title(): string {
		return __( 'Video', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Markup that helps search understand the videos in an article.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 90;
	}

	/**
	 * Video markup is printed on the page and never put in a feed.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return false;
	}

	/**
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout(): array {
		return array(
			'cards'  => array(
				array(
					'id'          => 'markup',
					'title'       => __( 'Video markup', 'estasoft-news-distribution' ),
					'description' => __( 'Adds VideoObject markup for the videos embedded in an article, so search can understand and show the video. Google asks for a name, a thumbnail and an upload date.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Embedded videos', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_enabled' ),
						),
						array(
							'label' => __( 'Sites', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_sites' ),
						),
						array( 'field' => 'which' ),
						array( 'field' => 'no_date' ),
					),
				),
				array(
					'id'          => 'details',
					'title'       => __( 'Details from the video site', 'estasoft-news-distribution' ),
					'description' => __( 'Without a lookup, you type each video’s details in the editor. With it, News Distribution asks the video site.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Look up details', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_lookup' ),
						),
						array(
							'label' => __( 'What is sent when it is on', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_sent' ),
						),
						array(
							'html' => array( $this, 'render_off_note' ),
							'wide' => true,
						),
					),
				),
				array(
					'id'          => 'added',
					'title'       => __( 'What is added', 'estasoft-news-distribution' ),
					'description' => __( 'The three highlighted lines are the ones Google asks for. The rest are recommended.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'html' => array( $this, 'render_sample' ),
							'wide' => true,
						),
					),
				),
			),
			'notes'  => array(),
			'cannot' => array(
				__( 'Promise a video result in search. The markup helps Google understand the video, and Google decides what to show.', 'estasoft-news-distribution' ),
				__( 'Fill a field a video site does not provide. You are asked for it in the editor.', 'estasoft-news-distribution' ),
				__( 'Mark up a video that is private or removed. It is skipped when the site says so, and only when “Look up details” is on, because that is the only time it asks.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The markup switch, without the word On or Off in front.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_enabled( array $values ): string {
		return Switcher::render(
			Field::name( 'enabled' ),
			Field::id( self::NAME, 'enabled' ),
			! empty( $values['enabled'] ),
			__( 'Add markup for embedded videos', 'estasoft-news-distribution' ),
			false
		);
	}

	/**
	 * The sites, one check box each.
	 *
	 * @param array<string, mixed>  $values The section's values.
	 * @param array<string, string> $errors Rejected fields.
	 * @return string Escaped HTML.
	 */
	public function render_sites( array $values, array $errors = array() ): string {
		$html   = '';
		$fields = $this->fields();

		foreach ( array( 'youtube', 'vimeo', 'own' ) as $name ) {
			$html .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Field::render( self::NAME, $name, $fields[ $name ], $values[ $name ] ?? true, $errors[ $name ] ?? '' )
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $html )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Video from other sites is left alone.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The lookup switch. The word and the sentence after it change with the switch (see content-features.js).
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function render_lookup( array $values ): string {
		$on       = ! empty( $values['lookup'] );
		$on_text  = __( 'News Distribution asks YouTube or Vimeo, in the background, for each video’s title and thumbnail, and for its upload date, description and duration where the site gives them.', 'estasoft-news-distribution' );
		$off_text = __( 'Turn on to ask YouTube or Vimeo for each video’s title and thumbnail, and for its upload date, description and duration where the site gives them.', 'estasoft-news-distribution' );
		$id       = Field::id( self::NAME, 'lookup' );

		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( 'lookup' ),
				'value' => '0',
			)
		) . Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-switch',
				'for'   => $id,
			),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'class'   => 'estasoft-nd-toggle',
					'id'      => $id,
					'name'    => Field::name( 'lookup' ),
					'value'   => '1',
					'checked' => $on,
				)
			) . Html::tag(
				'span',
				array(),
				Html::tag(
					'strong',
					array(
						'data-switch-word' => true,
						'data-on'          => __( 'On.', 'estasoft-news-distribution' ),
						'data-off'         => __( 'Off.', 'estasoft-news-distribution' ),
					),
					esc_html( $on ? __( 'On.', 'estasoft-news-distribution' ) : __( 'Off.', 'estasoft-news-distribution' ) )
				) . ' ' . Html::tag(
					'span',
					array(
						'data-switch-text' => true,
						'data-on'          => $on_text,
						'data-off'         => $off_text,
					),
					esc_html( $on ? $on_text : $off_text )
				)
			)
		);
	}

	/**
	 * "What is sent when it is on".
	 *
	 * @return string Escaped HTML.
	 */
	public function render_sent(): string {
		$items = array(
			array( 'check', __( 'The video’s address, from your server, in the background after an article with that video is saved', 'estasoft-news-distribution' ), 'ok' ),
			array( 'minus-circle', __( 'Nothing about your readers, and no cookies', 'estasoft-news-distribution' ), 'no' ),
			array( 'check', __( 'Each answer is saved with the article, so a video is asked about once', 'estasoft-news-distribution' ), 'ok' ),
		);
		$list  = '';

		foreach ( $items as $item ) {
			$list .= Html::tag(
				'li',
				array( 'class' => 'estasoft-nd-rule estasoft-nd-rule--' . $item[2] ),
				Icon::svg( $item[0] ) . Html::tag( 'span', array(), esc_html( $item[1] ) )
			);
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $list );
	}

	/**
	 * The note under the lookup.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_off_note(): string {
		return Message::bar(
			'info',
			'<strong>' . esc_html__( 'Off by default.', 'estasoft-news-distribution' ) . '</strong> ' . esc_html__( 'Turning it on makes outside requests. They are listed under External services in the plugin’s readme, with the sites they call.', 'estasoft-news-distribution' )
		);
	}

	/**
	 * The sample: what is printed for one video, drawn with the same builder as the page.
	 *
	 * The details are sample data, so they are not translated.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_sample(): string {
		$data = VideoJsonLd::build(
			array(
				'name'        => 'Ferry crossing, first trip',
				'thumbnail'   => 'https://i.ytimg.com/vi/abcdefghijk/hqdefault.jpg',
				'upload_date' => '2026-10-19T08:00:00+02:00',
				'description' => 'The first crossing of the new ferry.',
				'duration'    => 134,
				'embed_url'   => 'https://www.youtube.com/embed/abcdefghijk',
			)
		);
		$text = NewsArticleJsonLd::pretty( $data );
		$flag = array();

		foreach ( (array) preg_split( '/\r\n|\r|\n/', $text ) as $index => $line ) {
			if ( 1 === preg_match( '/^\s*"(?:name|thumbnailUrl|uploadDate)"/', (string) $line ) ) {
				$flag[] = array(
					'line' => $index + 1,
					'tone' => 'ok',
				);
			}
		}

		return Html::tag( 'p', array( 'class' => 'estasoft-nd-small' ), Tag::plain( __( 'Sample data', 'estasoft-news-distribution' ) ) )
			. Code::block( $text, __( 'Video markup for one video', 'estasoft-news-distribution' ), $flag )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Printed on one line for each video, on the article page. A video that lacks a name, a thumbnail or an upload date gets none.', 'estasoft-news-distribution' ) );
	}
}
