<?php
/**
 * Settings, Page markup.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core\Settings\Sections;

use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Code;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Clock as Time;
use Estasoft\NewsDistribution\Integrations\KnownPlugins;
use Estasoft\NewsDistribution\Markup\NewsArticleJsonLd;
use Estasoft\NewsDistribution\Markup\PageScan;
use Estasoft\NewsDistribution\Markup\PageTags;
use Estasoft\NewsDistribution\Modules\SiteScreensModule;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * What News Distribution prints in the head of an article page, and the paywall markup.
 *
 * Stored choices:
 *
 * - `jsonld`: print the NewsArticle data (JSON-LD).
 * - `tag_image`, `tag_author`, `tag_locale`, `tag_published_time`, `tag_modified_time`,
 *   `tag_canonical`, `tag_max_image_preview`: add that tag when the page has none.
 * - `other_plugin`: `leave` (add only what is missing) or `replace` (print its own and ask
 *   Yoast SEO or Rank Math to leave theirs out, where they have a way to do that).
 * - `paywall` and `paywall_selector`: mark an article that the editor set to subscribers
 *   only, registration required or metered, with the class that wraps the paid part.
 *
 * Everything is printed by Modules\MarkupModule, only on the public page of an article of
 * a post type chosen in General, and each value is escaped where it is made. The screen
 * reads, and the check button looks at a few of the site's own pages; it sends nothing
 * anywhere else.
 */
final class Markup extends AbstractSiteSection {

	public const NAME = 'markup';

	/**
	 * The last page check, once per request.
	 *
	 * @var array<string, mixed>|null|false
	 */
	private $scan = false;

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		$fields = array(
			'jsonld' => array(
				'type'      => 'bool',
				'label'     => __( 'Article data', 'estasoft-news-distribution' ),
				'row_label' => __( 'NewsArticle data', 'estasoft-news-distribution' ),
				'default'   => true,
				'control'   => 'checkbox',
				'title'     => __( 'Print article data (NewsArticle) in the page head', 'estasoft-news-distribution' ),
				'help'      => __( 'Headline, dates, author, picture and publisher, as JSON-LD. It is printed only for the post types chosen in General, and left out of a page where another plugin already prints article data.', 'estasoft-news-distribution' ),
			),
		);

		foreach ( PageTags::definitions() as $definition ) {
			$fields[ $definition['setting'] ] = array(
				'type'    => 'bool',
				'label'   => $definition['label'],
				'default' => true,
			);
		}

		$fields['other_plugin']     = array(
			'type'    => 'enum',
			'label'   => __( 'When another plugin already prints a tag', 'estasoft-news-distribution' ),
			'default' => 'leave',
			'control' => 'select',
			'width'   => 340,
			'options' => array(
				'leave'   => __( 'Leave it alone (recommended)', 'estasoft-news-distribution' ),
				'replace' => __( 'Replace it, where the other plugin allows', 'estasoft-news-distribution' ),
			),
			'help'    => $this->other_help(),
		);
		$fields['paywall']          = array(
			'type'      => 'bool',
			'label'     => __( 'Paywall markup', 'estasoft-news-distribution' ),
			'row_label' => __( 'Mark subscriber-only articles', 'estasoft-news-distribution' ),
			'default'   => true,
			'control'   => 'checkbox',
			'title'     => __( 'Add paywall markup', 'estasoft-news-distribution' ),
			'help'      => __( 'Used for articles set to Subscribers only, Registration required or Metered (readers get a few free articles) in the editor.', 'estasoft-news-distribution' ),
		);
		$fields['paywall_selector'] = array(
			'type'      => 'string',
			'format'    => 'text',
			'max'       => 200,
			'label'     => __( 'Paywalled part', 'estasoft-news-distribution' ),
			'row_label' => __( 'Paywalled part', 'estasoft-news-distribution' ),
			'default'   => '.paywall',
			'width'     => 220,
			'dir'       => 'ltr',
			'help'      => __( 'A CSS class that your theme puts on the part readers must pay or register for. Keep the dot (.paywall): Google asks for class selectors only, so an id is not accepted. Separate several classes with commas. A value without the dot is flagged when you save.', 'estasoft-news-distribution' ),
		);

		return $fields;
	}

	public function title(): string {
		return __( 'Page markup', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 10;
	}

	/**
	 * Tags in the page head do not change what feeds and sitemaps print.
	 *
	 * @return bool
	 */
	public function affects_output(): bool {
		return false;
	}

	/**
	 * Every comma-separated entry of the paywalled part has to be a class selector.
	 *
	 * @param array<string, mixed> $values Cleaned values.
	 * @return array<string, string>
	 */
	public function cross_check( array $values ): array {
		$selector = trim( (string) ( $values['paywall_selector'] ?? '' ) );

		if ( '' === $selector ) {
			if ( ! empty( $values['paywall'] ) ) {
				return array(
					'paywall_selector' => __( 'Paywalled part is empty. Enter the class your theme uses, such as .paywall, or turn off the paywall markup.', 'estasoft-news-distribution' ),
				);
			}

			return array();
		}

		foreach ( explode( ',', $selector ) as $entry ) {
			$entry = trim( $entry );

			if ( ! NewsArticleJsonLd::is_class_selector( $entry ) ) {
				return array(
					'paywall_selector' => sprintf(
						/* translators: %s: the text that was entered, such as #members. */
						__( 'Paywalled part: “%s” is not a class selector. Start it with a dot, for example .paywall. Google does not accept an id such as #members.', 'estasoft-news-distribution' ),
						$entry
					),
				);
			}
		}

		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function layout(): array {
		$notes = array(
			array(
				'title' => __( 'Image credits and captions', 'estasoft-news-distribution' ),
				'html'  => Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'These are set in Image credits: what to require, what to do when a rule fails, and whether the credit is written into the caption.', 'estasoft-news-distribution' ) )
					. $this->credits_link(),
			),
		);

		return array(
			'cards'  => array(
				array(
					'id'          => 'tags',
					'title'       => __( 'Page tags', 'estasoft-news-distribution' ),
					'description' => __( 'Tags in the head of each article page. Apple News reads the author, image and language from the page, not from the feed. News Distribution adds a tag only when the page has none.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Tags', 'estasoft-news-distribution' ),
							'wide'  => true,
							'html'  => array( $this, 'render_tags' ),
						),
						array( 'field' => 'other_plugin' ),
					),
				),
				array(
					'id'          => 'jsonld',
					'title'       => __( 'Article data', 'estasoft-news-distribution' ),
					'description' => __( 'A block of structured data in the page that tells search engines what the article is.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'jsonld' ),
						array(
							'label' => __( 'Found on your pages', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_jsonld_status' ),
						),
						array(
							'label' => __( 'Example from your latest article', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_example' ),
						),
					),
				),
				array(
					'id'          => 'paywall',
					'title'       => __( 'Paywalled articles', 'estasoft-news-distribution' ),
					'description' => __( 'Tells Google that the paywalled part is a paywall, not text hidden from readers on purpose.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'paywall' ),
						array( 'field' => 'paywall_selector' ),
						array(
							'label' => __( 'What News Distribution prints', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'render_paywall_preview' ),
						),
					),
				),
			),
			'notes'  => $notes,
			'cannot' => array(
				__( 'Make Google index the text behind a paywall. Google can do that only if it is allowed to read the page.', 'estasoft-news-distribution' ),
				__( 'Remove a tag that your theme or another plugin prints and gives no way to turn off.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The help under "When another plugin already prints a tag", with the plugins that were found.
	 *
	 * @return string
	 */
	private function other_help(): string {
		$names = array();

		foreach ( KnownPlugins::found() as $found ) {
			if ( 'seo' === $found['kind'] ) {
				$names[] = $found['name'];
			}
		}

		if ( array() === $names ) {
			return __( 'No SEO plugin that prints page tags was found. With Leave it alone, News Distribution adds only what is missing, so no tag appears twice. With Replace it, it prints its own tag and asks the other plugin to leave that one out; if that plugin has no way to do that, its tag stays and this screen says so.', 'estasoft-news-distribution' );
		}

		if ( 1 === count( $names ) ) {
			return sprintf(
				/* translators: %s: the name of a plugin, such as Yoast SEO. */
				__( '%1$s was found. With Leave it alone, News Distribution adds only what is missing, so no tag appears twice. With Replace it, it prints its own tag and asks %1$s to leave that one out; if %1$s has no way to do that, its tag stays and this screen says so.', 'estasoft-news-distribution' ),
				$names[0]
			);
		}

		return sprintf(
			/* translators: %s: names of plugins, such as "Yoast SEO and Rank Math". */
			__( '%s were found. With Leave it alone, News Distribution adds only what is missing, so no tag appears twice. With Replace it, it prints its own tag and asks them to leave that one out; if one has no way to do that, its tag stays and this screen says so.', 'estasoft-news-distribution' ),
			wp_sprintf( '%l', $names )
		);
	}

	/**
	 * The last page check.
	 *
	 * @return array<string, mixed>|null
	 */
	private function scan(): ?array {
		if ( false === $this->scan ) {
			$this->scan = ( new PageScan( Plugin::instance() ) )->last();
		}

		return $this->scan;
	}

	/**
	 * What each tag is for, in a few words.
	 *
	 * @return array<string, string>
	 */
	private static function tag_notes(): array {
		return array(
			'image'     => __( 'Page image tag: the picture a platform shows for the page.', 'estasoft-news-distribution' ),
			'author'    => __( 'Author tag: who wrote the article.', 'estasoft-news-distribution' ),
			'locale'    => __( 'Page language tag.', 'estasoft-news-distribution' ),
			'published' => __( 'Publish time tag.', 'estasoft-news-distribution' ),
			'modified'  => __( 'Update time tag.', 'estasoft-news-distribution' ),
			'canonical' => __( 'Canonical link: the one address that stands for the article.', 'estasoft-news-distribution' ),
			'preview'   => __( 'Image size hint for search: allows a large preview picture.', 'estasoft-news-distribution' ),
		);
	}

	/**
	 * The list of tags, each with its switch and what the last check saw.
	 *
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string Escaped HTML.
	 */
	public function render_tags( array $values ): string {
		$notes = self::tag_notes();
		$mode  = 'replace' === (string) ( $values['other_plugin'] ?? 'leave' ) ? 'replace' : 'leave';
		$rows  = '';

		foreach ( PageTags::definitions() as $id => $definition ) {
			$setting = $definition['setting'];
			$on      = (bool) ( $values[ $setting ] ?? true );
			$control = Field::id( self::NAME, $setting );
			$status  = $this->tag_status( $id, $on, $mode );

			$rows .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-tagrow' ),
				Html::void(
					'input',
					array(
						'type'  => 'hidden',
						'name'  => Field::name( $setting ),
						'value' => '0',
					)
				)
				. Html::void(
					'input',
					array(
						'type'             => 'checkbox',
						'class'            => 'estasoft-nd-toggle',
						'id'               => $control,
						'name'             => Field::name( $setting ),
						'value'            => '1',
						'checked'          => $on,
						'aria-describedby' => $control . '-note',
					)
				)
				. Html::tag(
					'label',
					array(
						'for'   => $control,
						'class' => 'estasoft-nd-tagrow__text',
					),
					Html::tag( 'code', array( 'class' => 'estasoft-nd-tagrow__name' ), esc_html( $definition['label'] ) )
					. Html::tag(
						'span',
						array(
							'class' => 'estasoft-nd-muted estasoft-nd-small',
							'id'    => $control . '-note',
						),
						esc_html( $notes[ $id ] ?? '' )
					)
				)
				. self::result( $status['tone'], $status['text'] )
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-tagrows' ), $rows ) . $this->check_row();
	}

	/**
	 * The "Check the pages" button and when it last ran.
	 *
	 * @return string
	 */
	private function check_row(): string {
		$scan = $this->scan();

		if ( null === $scan ) {
			$text = sprintf(
				/* translators: %s: a number of pages. */
				__( 'Not checked yet. The check reads up to %s recent article pages of your own site and nothing else.', 'estasoft-news-distribution' ),
				number_format_i18n( PageScan::MAX_PAGES )
			);
		} else {
			$text = sprintf(
				/* translators: 1: a number of pages, 2: a time such as 14:08. */
				_n( 'Checked %1$s recent article page at %2$s.', 'Checked %1$s recent article pages at %2$s.', (int) $scan['pages'], 'estasoft-news-distribution' ),
				number_format_i18n( (int) $scan['pages'] ),
				Time::format( (int) $scan['ran_at'] )
			);

			if ( (int) $scan['failed'] > 0 ) {
				$text .= ' ' . sprintf(
					/* translators: %s: a number of pages. */
					_n( '%s page did not answer.', '%s pages did not answer.', (int) $scan['failed'], 'estasoft-news-distribution' ),
					number_format_i18n( (int) $scan['failed'] )
				);
			}
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-tagrows__foot' ),
			Button::link( __( 'Check the pages', 'estasoft-news-distribution' ), self::action_url( SiteScreensModule::ACTION_MARKUP_SCAN ), array( 'size' => 'sm' ) )
			. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) )
		);
	}

	/**
	 * What the last check found for one tag, in words.
	 *
	 * @param string $id   The tag id.
	 * @param bool   $on   Whether the tag is turned on.
	 * @param string $mode leave or replace.
	 * @return array{tone: string, text: string}
	 */
	private function tag_status( string $id, bool $on, string $mode ): array {
		$scan = $this->scan();

		if ( null === $scan ) {
			return array(
				'tone' => 'neutral',
				'text' => __( 'Not checked yet', 'estasoft-news-distribution' ),
			);
		}

		$pages = (int) $scan['pages'];

		if ( 0 === $pages ) {
			return array(
				'tone' => 'warning',
				'text' => __( 'No page answered, so nothing could be checked', 'estasoft-news-distribution' ),
			);
		}

		$count   = $scan['tags'][ $id ] ?? array(
			'other'   => 0,
			'ours'    => 0,
			'nothing' => 0,
			'missing' => 0,
		);
		$found   = (int) $count['other'] + (int) $count['ours'];
		$missing = (int) $count['missing'];
		$nothing = (int) $count['nothing'];
		$stays   = 'replace' === $mode ? (int) $count['other'] : 0;
		$parts   = array();
		$tone    = 'success';

		if ( $found > 0 ) {
			$parts[] = sprintf(
				/* translators: 1: a number of pages, 2: all the pages that were checked. */
				_n( 'Found on %1$s of %2$s page', 'Found on %1$s of %2$s pages', $pages, 'estasoft-news-distribution' ),
				number_format_i18n( $found ),
				number_format_i18n( $pages )
			);
		}

		if ( $missing > 0 ) {
			$tone    = $on ? 'info' : 'warning';
			$parts[] = $on
				? sprintf(
					/* translators: %s: a number of pages. */
					_n( 'Missing on %s page, so News Distribution adds it', 'Missing on %s pages, so News Distribution adds it', $missing, 'estasoft-news-distribution' ),
					number_format_i18n( $missing )
				)
				: sprintf(
					/* translators: %s: a number of pages. */
					_n( 'Missing on %s page. It is turned off, so News Distribution does not add it', 'Missing on %s pages. It is turned off, so News Distribution does not add it', $missing, 'estasoft-news-distribution' ),
					number_format_i18n( $missing )
				);
		}

		if ( $nothing > 0 ) {
			$tone    = 'warning';
			$parts[] = self::nothing_text( $id, $nothing );
		}

		if ( $stays > 0 ) {
			$tone    = 'warning';
			$parts[] = sprintf(
				/* translators: %s: a number of pages. */
				_n( 'Another plugin’s tag stays on %s page: News Distribution cannot ask it to leave the tag out', 'Another plugin’s tag stays on %s pages: News Distribution cannot ask it to leave the tag out', $stays, 'estasoft-news-distribution' ),
				number_format_i18n( $stays )
			);
		}

		return array(
			'tone' => $tone,
			'text' => implode( '. ', $parts ) . ( count( $parts ) > 1 ? '.' : '' ),
		);
	}

	/**
	 * Why a tag was not added on some pages although it is on.
	 *
	 * @param string $id    The tag id.
	 * @param int    $count How many pages.
	 * @return string
	 */
	private static function nothing_text( string $id, int $count ): string {
		$number = number_format_i18n( $count );

		if ( 'image' === $id ) {
			return sprintf(
				/* translators: %s: a number of pages. */
				_n( '%s has no featured image, so there is nothing to add', '%s have no featured image, so there is nothing to add', $count, 'estasoft-news-distribution' ),
				$number
			);
		}

		if ( 'author' === $id ) {
			return sprintf(
				/* translators: %s: a number of pages. */
				_n( '%s has no author, so there is nothing to add', '%s have no author, so there is nothing to add', $count, 'estasoft-news-distribution' ),
				$number
			);
		}

		return sprintf(
			/* translators: %s: a number of pages. */
			_n( '%s has no value for it, so there is nothing to add', '%s have no value for it, so there is nothing to add', $count, 'estasoft-news-distribution' ),
			$number
		);
	}

	/**
	 * What the last check found about article data.
	 *
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string Escaped HTML.
	 */
	public function render_jsonld_status( array $values ): string {
		$scan = $this->scan();

		if ( null === $scan ) {
			return self::result( 'neutral', __( 'Not checked yet. “Check the pages” above looks at this too.', 'estasoft-news-distribution' ) );
		}

		if ( 0 === (int) $scan['pages'] ) {
			return self::result( 'warning', __( 'No page answered, so nothing could be checked.', 'estasoft-news-distribution' ) );
		}

		$data  = $scan['jsonld'];
		$parts = array();
		$tone  = 'info';

		if ( (int) $data['ours'] > 0 ) {
			$tone    = 'success';
			$parts[] = sprintf(
				/* translators: %s: a number of pages. */
				_n( 'News Distribution prints article data on %s page', 'News Distribution prints article data on %s pages', (int) $data['ours'], 'estasoft-news-distribution' ),
				number_format_i18n( (int) $data['ours'] )
			);
		}

		if ( (int) $data['other'] > 0 ) {
			$parts[] = sprintf(
				/* translators: %s: a number of pages. */
				_n( 'Another plugin or the theme already prints article data on %s page, so News Distribution leaves its own out there', 'Another plugin or the theme already prints article data on %s pages, so News Distribution leaves its own out there', (int) $data['other'], 'estasoft-news-distribution' ),
				number_format_i18n( (int) $data['other'] )
			);
		}

		if ( (int) $data['none'] > 0 ) {
			$tone    = ! empty( $values['jsonld'] ) ? 'warning' : 'info';
			$parts[] = sprintf(
				/* translators: %s: a number of pages. */
				_n( '%s page has none', '%s pages have none', (int) $data['none'], 'estasoft-news-distribution' ),
				number_format_i18n( (int) $data['none'] )
			);
		}

		return self::result( $tone, implode( '. ', $parts ) . '.' );
	}

	/**
	 * The data News Distribution would print for the latest article.
	 *
	 * @return string Escaped HTML.
	 */
	public function render_example(): string {
		$plugin   = Plugin::instance();
		$language = (string) $plugin->languages()->default_language();
		$articles = $plugin->reader()->recent( $language, 30 * DAY_IN_SECONDS, 1 );

		if ( array() === $articles ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No article from the last 30 days to show. Publish one and it appears here.', 'estasoft-news-distribution' ) );
		}

		$text = NewsArticleJsonLd::pretty( ( new NewsArticleJsonLd( $plugin ) )->build( $articles[0] ) );

		return Code::block( $text, __( 'Article data for the latest article', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Printed as it is, but on one line, and only when the setting above is on.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The paywall part, for the class in the field.
	 *
	 * @param array<string, mixed> $values The section's values as the screen shows them.
	 * @return string Escaped HTML.
	 */
	public function render_paywall_preview( array $values ): string {
		$fragment = NewsArticleJsonLd::paywall_fragment( (string) ( $values['paywall_selector'] ?? '' ) );

		if ( array() === $fragment ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Nothing is printed until a class is set, such as .paywall.', 'estasoft-news-distribution' ) );
		}

		return Code::block(
			NewsArticleJsonLd::pretty( array_merge( array( '@type' => 'NewsArticle' ), $fragment ) ),
			__( 'The paywall part of the article data', 'estasoft-news-distribution' )
		) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'This is only the paywall part. It is added to the article data of an article that is set to Subscribers only, Registration required or Metered. It shows the saved value.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The link to the Image credits section, when that section exists.
	 *
	 * @return string
	 */
	private function credits_link(): string {
		if ( null === Plugin::instance()->settings()->section_object( 'credits' ) ) {
			return '';
		}

		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-strong' ),
			'<a href="' . esc_url( self::url_of( 'credits' ) ) . '">' . esc_html__( 'Open Image credits', 'estasoft-news-distribution' ) . '</a>'
		);
	}
}
