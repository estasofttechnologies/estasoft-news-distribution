<?php
/**
 * The SmartNews channel page and its readiness report.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\AppleNews\FeedDocument;
use Estasoft\NewsDistribution\Channels\SearchSitemap\FetchSummary;
use Estasoft\NewsDistribution\Channels\SmartNews\ReadinessReport;
use Estasoft\NewsDistribution\Channels\SmartNews\ReportLinks;
use Estasoft\NewsDistribution\Channels\SmartNews\ReportView;
use Estasoft\NewsDistribution\Channels\SmartNews\SmartNewsChannel;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=smartnews`, and `&tab=report` for
 * the readiness report. SmartNews decides for itself, so the page keeps the
 * application as a status the owner sets, and shows what News Distribution's own
 * check found in the feeds. The report is that check, laid out for the platform
 * and shared by an address the owner can switch off.
 */
final class SmartNewsScreen extends AbstractPartnerScreen {

	/**
	 * The platform id the report is made for.
	 */
	private const PLATFORM = 'smartnews';

	/**
	 * The SmartNews Partner Program, as a third-party page names it.
	 */
	private const PROGRAM = 'https://business.smartnews.com/publishers';

	/**
	 * The SmartFormat specification, from SmartNews's help center.
	 */
	private const SPECIFICATION = 'https://publishers.smartnews.com/hc/en-us/articles/56500436151065-SmartFormat-Specification-Version-2-2';

	/**
	 * The file types a logo may be.
	 *
	 * @var array<string, string>
	 */
	private const LOGO_TYPES = array(
		'png'          => 'image/png',
		'jpg|jpeg|jpe' => 'image/jpeg',
		'webp'         => 'image/webp',
	);

	public static function channel_id(): string {
		return 'smartnews';
	}

	protected function tab_names(): array {
		return array( '', 'report' );
	}

	public function heading(): string {
		return 'report' === $this->current_tab() ? __( 'Readiness report', 'estasoft-news-distribution' ) : parent::heading();
	}

	public function shows_status(): bool {
		return 'report' !== $this->current_tab();
	}

	public function description(): string {
		if ( 'report' === $this->current_tab() ) {
			return __( 'A page you can send a platform with your application, showing what News Distribution’s own check found in your feeds.', 'estasoft-news-distribution' );
		}

		$last = 0;

		foreach ( $this->fetch_states_for() as $state ) {
			$last = max( $last, (int) $state['last_ts'] );
		}

		return __( 'News Distribution builds the feed. SmartNews decides whether to list it.', 'estasoft-news-distribution' ) . ' ' . (
			$last > 0
				? sprintf(
					/* translators: %s: how long ago, such as "8 min ago". */
					__( 'It fetched the feed %s.', 'estasoft-news-distribution' ),
					FetchSummary::ago( $last )
				)
				: __( 'It has not fetched the feed yet.', 'estasoft-news-distribution' )
		);
	}

	protected function known_actions(): array {
		return array_merge(
			parent::known_actions(),
			array(
				'report_attach' => 'post',
				'report_revoke' => 'post',
			)
		);
	}

	protected function perform( string $action, array $input ): array {
		if ( 'report_revoke' === $action ) {
			ReportLinks::revoke( self::PLATFORM );

			return $this->remember( true, __( 'The link stopped working. You can make a new one.', 'estasoft-news-distribution' ) );
		}

		if ( 'report_attach' === $action ) {
			$link = ReportLinks::active( self::PLATFORM, Clock::now() );

			if ( null === $link ) {
				return $this->remember( false, __( 'Make a link first. Only a link that works can be attached.', 'estasoft-news-distribution' ) );
			}

			$on = empty( $link['attached'] );
			ReportLinks::attach( self::PLATFORM, $on, Clock::now() );

			return $this->remember(
				true,
				$on
					? __( 'The link is attached to the application email on the Applications screen.', 'estasoft-news-distribution' )
					: __( 'The link is no longer attached to the application email.', 'estasoft-news-distribution' )
			);
		}

		return parent::perform( $action, $input );
	}

	public function handle( string $tab ): void {
		if ( $this->posted( 'report' ) ) {
			$this->save_report();
		}

		parent::handle( $tab );
	}

	public function actions( string $tab ): string {
		if ( 'report' !== $this->current_tab() ) {
			return parent::actions( $tab );
		}

		$link = ReportLinks::active( self::PLATFORM, Clock::now() );

		if ( null === $link ) {
			return '';
		}

		return $this->post_button(
			'report_attach',
			empty( $link['attached'] ) ? __( 'Attach to an application email', 'estasoft-news-distribution' ) : __( 'Detach from the application email', 'estasoft-news-distribution' ),
			array( 'tab' => 'report' )
		);
	}

	public function enqueue( string $tab ): void {
		parent::enqueue( $tab );

		if ( 'report' === $this->current_tab() ) {
			Assets::screen_style( 'report' );
		}
	}

	protected function script_text(): array {
		return array(
			'describe' => sprintf(
				/* translators: 1: the number of characters SmartNews recommends staying under, 2: a placeholder the page fills with the number of characters typed. */
				__( 'SmartNews recommends fewer than %1$d characters. %2$s used.', 'estasoft-news-distribution' ),
				$this->description_limit(),
				'{used}'
			),
		);
	}

	public function render( string $tab ): string {
		return 'report' === $this->current_tab() ? $this->render_report() : $this->render_main();
	}

	/**
	 * The channel page.
	 *
	 * @return string
	 */
	private function render_main(): string {
		$main = $this->steps_card();
		$side = $this->check_card() . $this->rules() . $this->cannot_card( __( 'It cannot see SmartNews’s answer, make SmartNews fetch more often or list you, or enlarge a thumbnail that is too small. You record the application status by hand.', 'estasoft-news-distribution' ) );

		return $this->head( __( 'Its feed addresses answer 404 until you turn it on.', 'estasoft-news-distribution' ) )
			. $this->save_form( $this->split( $main, $side ), '', '', true )
			. $this->forms();
	}

	/**
	 * The three steps: the feed, the brand details, the application.
	 *
	 * @return string
	 */
	private function steps_card(): string {
		$row    = $this->plugin->applications()->get( self::PLATFORM );
		$values = $this->values();
		$logo   = (int) ( $values['logo_id'] ?? 0 ) > 0;
		$count  = 0;

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$count += $this->channel->accepts_language( (string) $language ) ? 1 : 0;
		}

		return $this->steps(
			'smartnews',
			__( 'Set up SmartNews', 'estasoft-news-distribution' ),
			array(
				array(
					'id'    => 'feed',
					'title' => __( 'Your feed is ready', 'estasoft-news-distribution' ),
					'text'  => __( 'One SmartFormat feed per language: RSS 2.0 with the content, dc, media and snf namespaces.', 'estasoft-news-distribution' ),
					'state' => 'done',
					'body'  => $this->feed_lines(
						__( 'SmartNews feed', 'estasoft-news-distribution' ),
						static function ( array $check ): string {
							$items    = sprintf(
								/* translators: %s: the number of articles in the feed. */
								_n( '%s item', '%s items', (int) $check['entries'], 'estasoft-news-distribution' ),
								number_format_i18n( (int) $check['entries'] )
							);
							$warnings = count( (array) $check['warnings'] );

							if ( 0 === $warnings ) {
								return sprintf(
									/* translators: %s: the number of articles, such as "6 items". */
									__( 'Clean, %s', 'estasoft-news-distribution' ),
									$items
								);
							}

							return sprintf(
								/* translators: 1: the number of articles, such as "6 items", 2: the number of warnings. */
								_n( '%1$s, %2$d warning', '%1$s, %2$d warnings', $warnings, 'estasoft-news-distribution' ),
								$items,
								$warnings
							);
						},
						true
					) . Html::tag(
						'p',
						array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
						esc_html__( 'This is News Distribution’s own SmartNews check. It is not a SmartNews result.', 'estasoft-news-distribution' )
					),
				),
				array(
					'id'    => 'brand',
					'title' => __( 'Add your brand details', 'estasoft-news-distribution' ),
					'text'  => __( 'SmartNews shows your logo above every article.', 'estasoft-news-distribution' ),
					'state' => $logo ? 'done' : 'now',
					'body'  => $this->brand_fields(),
				),
				array(
					'id'    => 'apply',
					'title' => __( 'Apply to SmartNews', 'estasoft-news-distribution' ),
					'text'  => sprintf(
						/* translators: %d: the number of feed addresses. */
						_n(
							'Apply through the SmartNews Partner Program and send your %d feed address. SmartNews decides whether to list you. News Distribution cannot see its answer, so you record it here.',
							'Apply through the SmartNews Partner Program and send your %d feed addresses. SmartNews decides whether to list you. News Distribution cannot see its answer, so you record it here.',
							$count,
							'estasoft-news-distribution'
						),
						$count
					),
					'state' => Status::LISTED === $row['status'] ? 'done' : ( $logo ? 'now' : '' ),
					'body'  => Html::tag(
						'div',
						array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ),
						$this->application_fields( true )
						. Html::tag(
							'div',
							array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-16' ),
							$this->external_link( __( 'SmartNews Partner Program', 'estasoft-news-distribution' ), self::PROGRAM )
							. $this->external_link( __( 'SmartFormat specification', 'estasoft-news-distribution' ), self::SPECIFICATION )
						)
					),
				),
			)
		);
	}

	/**
	 * The brand details: two logos, the description and the refresh hint.
	 *
	 * @return string
	 */
	private function brand_fields(): string {
		$values = $this->values();
		$errors = $this->errors();
		$used   = function_exists( 'mb_strlen' ) ? mb_strlen( trim( (string) ( $values['description'] ?? '' ) ), 'UTF-8' ) : strlen( trim( (string) ( $values['description'] ?? '' ) ) );
		$hidden = '';

		// The saved ids travel with the form, so a save that is turned down does not lose a logo that was just chosen.
		foreach ( array( 'logo_id', 'dark_logo_id' ) as $name ) {
			$hidden .= Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => Field::name( $name ),
					'value' => (string) (int) ( $values[ $name ] ?? 0 ),
				)
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-16' ),
			$hidden
			. $this->logo_box(
				'logo_id',
				'estasoft_nd_logo',
				__( 'Logo', 'estasoft-news-distribution' ),
				__( 'PNG, 700 × 100, transparent recommended.', 'estasoft-news-distribution' ),
				__( 'Required. It must read clearly on light and dark backgrounds.', 'estasoft-news-distribution' ),
				(string) ( $errors['logo_id'] ?? '' )
			)
			. $this->logo_box(
				'dark_logo_id',
				'estasoft_nd_dark_logo',
				__( 'Dark-mode logo', 'estasoft-news-distribution' ),
				__( 'PNG, 700 × 100. Optional.', 'estasoft-news-distribution' ),
				__( 'Shown instead of the logo when the reader uses dark mode. SmartNews recommends adding one.', 'estasoft-news-distribution' ),
				(string) ( $errors['dark_logo_id'] ?? '' )
			)
			. $this->labelled(
				'description',
				__( 'Site description', 'estasoft-news-distribution' ),
				array(
					'width' => 360,
					'help'  => sprintf(
						/* translators: 1: the number of characters SmartNews recommends staying under, 2: the number of characters used. */
						__( 'SmartNews recommends fewer than %1$d characters. %2$d used.', 'estasoft-news-distribution' ),
						$this->description_limit(),
						$used
					),
				)
			)
			. $this->labelled( 'refresh_minutes', __( 'Refresh hint', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The limit SmartNews recommends for the description, from the rule pack.
	 *
	 * @return int
	 */
	private function description_limit(): int {
		return (int) $this->plugin->rules()->limit( self::PLATFORM, 'description_recommended_max', 30 );
	}

	/**
	 * One logo: what it is now, the button that picks another and what to expect.
	 *
	 * @param string $field Channel field name that holds the image id.
	 * @param string $key   The name of the file input.
	 * @param string $label The logo's name.
	 * @param string $note  What kind of image to choose.
	 * @param string $help  What the logo is for.
	 * @param string $error The message of a refused save, or an empty string.
	 * @return string
	 */
	private function logo_box( string $field, string $key, string $label, string $note, string $help, string $error ): string {
		$values  = $this->values();
		$id      = (int) ( $values[ $field ] ?? 0 );
		$url     = $id > 0 ? (string) wp_get_attachment_image_url( $id, 'full' ) : '';
		$name    = $id > 0 ? wp_basename( (string) get_attached_file( $id ) ) : '';
		$control = 'estasoft-nd-f-' . $key;
		$help_id = $control . '-help';
		$width   = (int) $this->plugin->rules()->limit( self::PLATFORM, 'logo_width', 700 );
		$height  = (int) $this->plugin->rules()->limit( self::PLATFORM, 'logo_height', 100 );
		$preview = '';

		if ( '' !== $url ) {
			$preview .= Html::void(
				'img',
				array(
					'src'     => $url,
					'alt'     => '',
					'loading' => 'lazy',
				)
			);
		}

		$preview .= Html::tag(
			'span',
			array(
				'class'          => 'estasoft-nd-drop__name',
				'data-file-name' => true,
			),
			esc_html(
				'' !== $name ? $name : sprintf(
					/* translators: 1: the logo width in pixels, 2: the logo height in pixels. */
					__( '%1$d × %2$d preview', 'estasoft-news-distribution' ),
					$width,
					$height
				)
			)
		);

		$remove = '';

		if ( 'dark_logo_id' === $field && $id > 0 ) {
			$remove = Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check' ),
				Html::void(
					'input',
					array(
						'type'  => 'checkbox',
						'name'  => 'estasoft_nd_remove[' . $field . ']',
						'value' => '1',
					)
				) . Html::tag( 'span', array(), esc_html__( 'Remove the dark-mode logo', 'estasoft-news-distribution' ) )
			);
		}

		$box = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-drop' ),
			Html::tag( 'span', array( 'class' => 'estasoft-nd-drop__preview' ), $preview )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-drop__control' ),
				Html::void(
					'input',
					array(
						'type'             => 'file',
						'class'            => 'estasoft-nd-drop__file',
						'id'               => $control,
						'name'             => $key,
						'accept'           => 'image/png,image/jpeg,image/webp',
						'aria-describedby' => $help_id,
					)
				)
				. Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-btn estasoft-nd-drop__button',
						'for'   => $control,
					),
					esc_html( $id > 0 ? __( 'Replace image', 'estasoft-news-distribution' ) : __( 'Choose image', 'estasoft-news-distribution' ) )
				)
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $note ) )
			)
		);

		$html = Html::tag( 'span', array( 'class' => 'estasoft-nd-label' ), esc_html( $label ) ) . $box . $remove;

		if ( '' !== $error ) {
			$html .= Html::tag(
				'p',
				array(
					'class' => 'estasoft-nd-field-error',
					'role'  => 'alert',
				),
				Icon::svg( 'x-circle' ) . Html::tag( 'span', array(), esc_html( $error ) )
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			$html . Html::tag(
				'p',
				array(
					'class' => 'estasoft-nd-muted estasoft-nd-small',
					'id'    => $help_id,
				),
				esc_html( $help )
			)
		);
	}

	protected function prepare_input( array $input, array $post, array &$errors ): array {
		$names = array(
			'logo_id'      => 'estasoft_nd_logo',
			'dark_logo_id' => 'estasoft_nd_dark_logo',
		);
		$drop  = isset( $post['estasoft_nd_remove'] ) && is_array( $post['estasoft_nd_remove'] ) ? $post['estasoft_nd_remove'] : array();

		foreach ( $names as $field => $key ) {
			$message = '';
			$id      = $this->upload( $key, $message );

			if ( '' !== $message ) {
				$errors[ $field ] = $message;
			} elseif ( $id > 0 ) {
				$input[ $field ] = $id;
			} elseif ( 'dark_logo_id' === $field && ! empty( $drop[ $field ] ) ) {
				$input[ $field ] = 0;
			}
		}

		$logo = isset( $input['logo_id'] ) ? (int) $input['logo_id'] : 0;

		if ( ! isset( $errors['logo_id'] ) && ( $logo < 1 || 'attachment' !== get_post_type( $logo ) ) ) {
			$errors['logo_id'] = __( 'Add a logo to save these details.', 'estasoft-news-distribution' );
		}

		return $input;
	}

	/**
	 * Takes an uploaded image into the Media Library.
	 *
	 * @param string $key     The name of the file input.
	 * @param string $message Set to a sentence when the file was refused.
	 * @return int The attachment id, or 0 when nothing was chosen or the file was refused.
	 */
	private function upload( string $key, string &$message ): int {
		check_admin_referer( $this->page_nonce() );

		// Only the error code is read here; WordPress reads and checks the file itself in media_handle_upload() below.
		$code = isset( $_FILES[ $key ]['error'] ) ? (int) $_FILES[ $key ]['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $code ) {
			return 0;
		}

		if ( UPLOAD_ERR_OK !== $code ) {
			$message = __( 'The image could not be uploaded. Try a smaller file.', 'estasoft-news-distribution' );

			return 0;
		}

		$object = get_post_type_object( 'attachment' );

		if ( null === $object || ! current_user_can( (string) $object->cap->create_posts ) ) {
			$message = __( 'Your account cannot upload images.', 'estasoft-news-distribution' );

			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$id = media_handle_upload(
			$key,
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => self::LOGO_TYPES,
			)
		);

		if ( is_wp_error( $id ) ) {
			$message = $id->get_error_message();

			return 0;
		}

		return (int) $id;
	}

	/**
	 * "Latest feed check": what the plugin's own check found in the feeds now.
	 *
	 * @return string
	 */
	private function check_card(): string {
		$channel = $this->channel;

		if ( ! $channel instanceof SmartNewsChannel ) {
			return '';
		}

		$counts   = array();
		$small    = array();
		$notes    = array();
		$problems = array();
		$skipped  = 0;
		$live     = 0;

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$language = (string) $language;

			if ( ! $channel->accepts_language( $language ) ) {
				continue;
			}

			$entry = FeedDocument::entry( $this->plugin, $channel, $language );
			$check = $channel->inspect( (string) ( $entry['body'] ?? '' ) );

			$live               += $check['live'];
			$skipped            += max( 0, (int) ( $entry['articles'] ?? 0 ) - $check['live'] );
			$counts[ $language ] = $check['live'];

			foreach ( $check['items'] as $item ) {
				if ( ! empty( $item['small'] ) ) {
					$small[] = $item;
				}
			}

			foreach ( (array) $check['warnings'] as $note ) {
				$notes[ (string) $note ] = (string) $note;
			}

			foreach ( (array) $check['problems'] as $problem ) {
				$problems[ (string) $problem ] = strtoupper( $language ) . ': ' . $problem;
			}
		}

		$edge  = number_format_i18n( $channel->minimum_edge() );
		$lines = '';
		$parts = array();

		foreach ( $counts as $language => $count ) {
			$parts[] = strtoupper( (string) $language ) . ' ' . number_format_i18n( $count );
		}

		$lines .= Html::tag(
			'li',
			array(),
			StatusView::inline(
				array() === $problems ? Status::CLEAN : Status::FAILED,
				sprintf(
					/* translators: 1: the number of articles in the feeds, 2: the languages with their counts, such as "EN 6, KU 3". */
					_n( '%1$s item in the feed: %2$s', '%1$s items in the feed: %2$s', $live, 'estasoft-news-distribution' ),
					number_format_i18n( $live ),
					implode( ', ', $parts )
				)
			)
		);

		foreach ( $problems as $problem ) {
			$lines .= Html::tag( 'li', array(), StatusView::inline( Status::FAILED, $problem ) );
		}

		foreach ( $notes as $note ) {
			$lines .= Html::tag( 'li', array(), StatusView::inline( Status::WARN, $note ) );
		}

		$lines .= Html::tag(
			'li',
			array(),
			array() === $small
				? StatusView::inline(
					Status::CLEAN,
					sprintf(
						/* translators: %s: a number of pixels, such as 1,500. */
						__( 'No thumbnail under %s px.', 'estasoft-news-distribution' ),
						$edge
					)
				)
				: StatusView::inline(
					Status::WARN,
					sprintf(
						/* translators: 1: the number of articles with a small thumbnail, 2: the number of articles in the feeds, 3: a number of pixels, such as 1,500. */
						__( '%1$d of the %2$d have a thumbnail under %3$s px', 'estasoft-news-distribution' ),
						count( $small ),
						$live,
						$edge
					)
				)
		);

		$words  = array() === $small ? '' : _n( 'It stays in the feed.', 'They stay in the feed.', count( $small ), 'estasoft-news-distribution' ) . ' ';
		$words .= sprintf(
			/* translators: %s: a number of pixels, such as 1,500. */
			__( 'News Distribution warns when the long edge is under %s px and does not skip the article.', 'estasoft-news-distribution' ),
			$edge
		) . ' ' . (
			0 === $skipped
				? __( 'No article is left out of the feed.', 'estasoft-news-distribution' )
				: sprintf(
					/* translators: %d: the number of articles left out of the feeds. */
					_n( '%d article is left out: it has no featured image or no text.', '%d articles are left out: they have no featured image or no text.', $skipped, 'estasoft-news-distribution' ),
					$skipped
				)
		);

		$body = Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $lines )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $words ) );

		if ( array() !== $small ) {
			$items = '';

			foreach ( $small as $item ) {
				$items .= Html::tag(
					'li',
					array(),
					Html::tag( 'a', array( 'href' => (string) $item['link'] ), esc_html( (string) $item['title'] ) )
					. ' ' . Html::tag(
						'span',
						array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
						esc_html(
							sprintf(
								/* translators: 1: the width in pixels, 2: the height in pixels. */
								__( '%1$d × %2$d px', 'estasoft-news-distribution' ),
								(int) $item['width'],
								(int) $item['height']
							)
						)
					)
				);
			}

			$body .= Html::tag(
				'details',
				array( 'class' => 'estasoft-nd-which' ),
				Html::tag(
					'summary',
					array(),
					esc_html(
						sprintf(
							/* translators: %d: the number of articles. */
							_n( 'See the %d article', 'See the %d articles', count( $small ), 'estasoft-news-distribution' ),
							count( $small )
						)
					)
				)
				. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $items )
			);
		}

		$body .= Html::tag( 'div', array(), Button::link( __( 'Make a readiness report', 'estasoft-news-distribution' ), $this->url( 'report' ), array( 'size' => 'sm' ) ) );

		return Card::note( 'check', __( 'Latest feed check', 'estasoft-news-distribution' ), $body );
	}

	/**
	 * "Rules News Distribution applies".
	 *
	 * @return string
	 */
	private function rules(): string {
		$channel = $this->channel;
		$edge    = $channel instanceof SmartNewsChannel ? $channel->minimum_edge() : 1500;
		$items   = (int) $this->plugin->rules()->limit( self::PLATFORM, 'feed_items', 100 );

		return $this->rules_card(
			self::PLATFORM,
			__( 'SmartNews', 'estasoft-news-distribution' ),
			array(
				'item.fulltext'              => __( 'Full text with the lead image in content:encoded', 'estasoft-news-distribution' ),
				'item.author|item.thumbnail' => __( 'Author and thumbnail on every item', 'estasoft-news-distribution' ),
				'thumbnail.size'             => sprintf(
					/* translators: %s: a number of pixels, such as 1,500. */
					__( 'Warning for a thumbnail under %s px on the long edge', 'estasoft-news-distribution' ),
					number_format_i18n( $edge )
				),
				'item.canonical'             => __( 'Canonical URL, with UTM parameters removed', 'estasoft-news-distribution' ),
				'item.date'                  => __( 'RFC 822 dates with a time zone', 'estasoft-news-distribution' ),
				'feed.size'                  => sprintf(
					/* translators: %s: the number of items a feed is kept to, such as 100. */
					__( 'Feed kept to about %s items and under 1 MB', 'estasoft-news-distribution' ),
					number_format_i18n( $items )
				),
				'item.deleted'               => __( 'Unpublished articles marked deleted', 'estasoft-news-distribution' ),
			)
		);
	}

	/**
	 * The readiness report page.
	 *
	 * @return string
	 */
	private function render_report(): string {
		$now    = Clock::now();
		$link   = ReportLinks::active( self::PLATFORM, $now );
		$choice = $this->choice( $link );
		$report = $this->plugin->make( ReadinessReport::class )->build(
			array(
				'samples'       => 2,
				'contact_name'  => $choice['contact_name'],
				'contact_email' => $choice['contact_email'],
			)
		);

		if ( array() === $report ) {
			return $this->notice() . $this->cannot_card( __( 'The report needs the SmartNews channel, which is not available.', 'estasoft-news-distribution' ) );
		}

		$main = Html::tag(
			'section',
			array(
				'class'      => 'estasoft-nd-card estasoft-nd-card--note estasoft-nd-report-card',
				'aria-label' => __( 'The report as the platform sees it', 'estasoft-news-distribution' ),
			),
			ReportView::render( $report, $choice['sections'], false )
		);
		$side = $this->report_options( $report, $choice )
			. $this->share_card( $link, $choice )
			. $this->cannot_card( __( 'It cannot send the report for you, and it cannot show that SmartNews accepts the feeds: the report only lists what News Distribution’s own check found. A link you revoked stops working, but a copy someone saved as a PDF stays with them.', 'estasoft-news-distribution' ) );

		$form = Html::tag(
			'form',
			array(
				'method'           => 'post',
				'action'           => $this->url( 'report' ),
				'class'            => 'estasoft-nd-stack estasoft-nd-gap-16',
				'novalidate'       => true,
				'data-report-form' => true,
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'estasoft_nd_do',
					'value' => 'report',
				)
			)
			. wp_nonce_field( $this->report_nonce(), '_wpnonce', true, false )
			. $this->split( $main, $side )
		);

		return $this->notice()
			. $this->off_bar( __( 'The feed addresses in the report answer 404 until you turn it on.', 'estasoft-news-distribution' ) )
			. $this->question(
				array(
					'revoke-link' => array(
						'title'   => __( 'Revoke this link?', 'estasoft-news-distribution' ),
						'text'    => __( 'The address stops working at once. You can make a new link afterwards.', 'estasoft-news-distribution' ),
						'confirm' => __( 'Revoke link', 'estasoft-news-distribution' ),
						'action'  => 'report_revoke',
						'tab'     => 'report',
					),
				)
			)
			. $form
			. $this->forms();
	}

	/**
	 * "Report for": the platform, what to include and who to contact.
	 *
	 * @param array<string, mixed> $report The report.
	 * @param array<string, mixed> $choice What the person chose.
	 * @return string
	 */
	private function report_options( array $report, array $choice ): string {
		$boxes = '';

		foreach ( ReportView::sections( count( (array) $report['samples'] ) ) as $key => $section ) {
			$boxes .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'               => 'checkbox',
						'name'               => 'report[sections][]',
						'value'              => $key,
						'checked'            => in_array( $key, $choice['sections'], true ),
						'data-report-toggle' => $key,
					)
				) . Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html( $section['label'] ) ) . '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $section['help'] ) )
				)
			);
		}

		$platform = Html::tag(
			'select',
			array(
				'class' => 'estasoft-nd-field estasoft-nd-w-full',
				'id'    => 'estasoft-nd-report-platform',
				'name'  => 'report[platform]',
			),
			Html::tag(
				'option',
				array(
					'value'    => self::PLATFORM,
					'selected' => true,
				),
				esc_html( (string) $report['platform_name'] )
			)
		);

		$body = Html::tag( 'div', array(), $platform )
			. Html::tag(
				'fieldset',
				array( 'class' => 'estasoft-nd-report-include' ),
				Html::tag( 'legend', array( 'class' => 'estasoft-nd-label' ), esc_html__( 'Include', 'estasoft-news-distribution' ) )
				. Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $boxes )
			)
			. $this->contact_input( 'contact_name', __( 'Contact name', 'estasoft-news-distribution' ), 'text', (string) $choice['contact_name'] )
			. $this->contact_input( 'contact_email', __( 'Contact email', 'estasoft-news-distribution' ), 'email', (string) $choice['contact_email'] )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-nojs-only' ),
				Button::submit(
					__( 'Update preview', 'estasoft-news-distribution' ),
					array(
						'attrs' => array(
							'name'  => 'step',
							'value' => 'preview',
						),
					)
				)
			);

		return Card::note( 'report-for', __( 'Report for', 'estasoft-news-distribution' ), Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-16' ), $body ) );
	}

	/**
	 * A contact detail's label and input.
	 *
	 * @param string $name  The field name under `report`.
	 * @param string $label The label.
	 * @param string $type  The input type.
	 * @param string $value The value.
	 * @return string
	 */
	private function contact_input( string $name, string $label, string $type, string $value ): string {
		$id = 'estasoft-nd-report-' . str_replace( '_', '-', $name );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => $id,
				),
				esc_html( $label )
			) . Html::void(
				'input',
				array(
					'class'               => 'estasoft-nd-field estasoft-nd-w-full',
					'type'                => $type,
					'id'                  => $id,
					'name'                => 'report[' . $name . ']',
					'value'               => $value,
					'autocomplete'        => 'off',
					'data-report-contact' => str_replace( 'contact_', '', $name ),
				)
			)
		);
	}

	/**
	 * "Share": the address, how long it works and what can be done with it.
	 *
	 * @param array<string, mixed>|null $link   The link that works now, if any.
	 * @param array<string, mixed>      $choice What the person chose.
	 * @return string
	 */
	private function share_card( ?array $link, array $choice ): string {
		$options = '';

		foreach ( ReportLinks::DAYS as $days ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => (string) $days,
					'selected' => (int) $choice['days'] === $days,
				),
				esc_html(
					sprintf(
						/* translators: %d: a number of days. */
						_n( '%d day', '%d days', $days, 'estasoft-news-distribution' ),
						$days
					)
				)
			);
		}

		$days = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-label',
					'for'   => 'estasoft-nd-report-days',
				),
				esc_html__( 'Link works for', 'estasoft-news-distribution' )
			) . Html::tag(
				'select',
				array(
					'class' => 'estasoft-nd-field estasoft-nd-w-200',
					'id'    => 'estasoft-nd-report-days',
					'name'  => 'report[days]',
				),
				$options
			)
		);

		$make = static function ( string $label, bool $primary ): string {
			return Button::submit(
				$label,
				array(
					'variant' => $primary ? 'primary' : '',
					'attrs'   => array(
						'name'  => 'step',
						'value' => 'make',
					),
				)
			);
		};

		if ( null === $link ) {
			return Card::note(
				'share',
				__( 'Share', 'estasoft-news-distribution' ),
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-16' ),
					Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No link yet. Making one gives you an address you can send. Anyone with it can read the report until it expires.', 'estasoft-news-distribution' ) )
					. $days
					. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $make( __( 'Make link', 'estasoft-news-distribution' ), true ) )
					. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->share_note() ) )
				)
			);
		}

		$token  = (string) $link['token'];
		$status = sprintf(
			/* translators: %s: the date and time the link stops working. */
			__( 'Works until %s.', 'estasoft-news-distribution' ),
			Clock::datetime( (int) $link['expires'] )
		);

		if ( ! empty( $link['attached'] ) ) {
			$status .= ' ' . __( 'Attached to the application email.', 'estasoft-news-distribution' );
		}

		return Card::note(
			'share',
			__( 'Share', 'estasoft-news-distribution' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-16' ),
				Html::tag(
					'code',
					array(
						'class'      => 'estasoft-nd-url estasoft-nd-mono estasoft-nd-share-url',
						'dir'        => 'ltr',
						'tabindex'   => '0',
						'aria-label' => __( 'Address of the shared report', 'estasoft-news-distribution' ),
					),
					esc_html( ReportLinks::shown( $token ) )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $status ) )
				. $days
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Button::button(
						__( 'Copy link', 'estasoft-news-distribution' ),
						array(
							'variant' => 'primary',
							'attrs'   => array(
								'data-copy'         => ReportLinks::url( $token ),
								'data-copied-label' => __( 'Copied', 'estasoft-news-distribution' ),
							),
						)
					)
					. Button::link(
						__( 'Download PDF', 'estasoft-news-distribution' ),
						ReportLinks::url( $token, true ),
						array(
							'attrs' => array(
								'target' => '_blank',
								'rel'    => 'noopener noreferrer',
							),
						)
					)
					. $this->confirmable(
						'revoke-link',
						__( 'Revoke link', 'estasoft-news-distribution' ),
						__( 'Revoke this link?', 'estasoft-news-distribution' ),
						__( 'The address stops working at once. You can make a new link afterwards. A copy someone saved as a PDF stays with them.', 'estasoft-news-distribution' ),
						__( 'Revoke link', 'estasoft-news-distribution' ),
						'report_revoke',
						'report'
					)
				)
				. Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					$make( __( 'Make a new link', 'estasoft-news-distribution' ), false )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'The old address stops working.', 'estasoft-news-distribution' ) )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $this->share_note() ) )
			)
		);
	}

	/**
	 * What the share card says about the link.
	 *
	 * @return string
	 */
	private function share_note(): string {
		return __( '“Attach to an application email” adds the link to the email on the Applications screen. The page shows only what you include: no admin screens, no visitor data. The contact details appear because Contact is ticked. Anyone with the link can open it until it expires. “Revoke link” asks you to confirm, then stops the link at once; you can make a new link afterwards. “Download PDF” opens the print view: choose Save as PDF there.', 'estasoft-news-distribution' );
	}

	/**
	 * What the report shows: the last choices, or those of the link that works, or the defaults.
	 *
	 * @param array<string, mixed>|null $link The link that works now, if any.
	 * @return array{sections: string[], contact_name: string, contact_email: string, days: int}
	 */
	private function choice( ?array $link ): array {
		$user    = wp_get_current_user();
		$contact = null === $link ? array() : (array) ( $link['report']['contact'] ?? array() );
		$choice  = array(
			'sections'      => null === $link ? ReportView::keys() : array_values( (array) $link['include'] ),
			'contact_name'  => (string) ( $contact['name'] ?? ( $user->exists() ? $user->display_name : '' ) ),
			'contact_email' => (string) ( $contact['email'] ?? ( $user->exists() ? $user->user_email : '' ) ),
			'days'          => null === $link ? ReportLinks::DAYS[0] : (int) $link['days'],
		);
		$stored  = get_transient( $this->choice_key() );

		if ( is_array( $stored ) ) {
			$choice['sections']      = array_values( array_intersect( ReportView::keys(), (array) ( $stored['sections'] ?? array() ) ) );
			$choice['contact_name']  = (string) ( $stored['contact_name'] ?? $choice['contact_name'] );
			$choice['contact_email'] = (string) ( $stored['contact_email'] ?? $choice['contact_email'] );
			$choice['days']          = in_array( (int) ( $stored['days'] ?? 0 ), ReportLinks::DAYS, true ) ? (int) $stored['days'] : $choice['days'];
		}

		return $choice;
	}

	/**
	 * Where the last choices of the report form are kept.
	 *
	 * @return string
	 */
	private function choice_key(): string {
		return 'estasoft_nd_report_choice_' . get_current_user_id();
	}

	/**
	 * The nonce action of the report form.
	 *
	 * @return string
	 */
	private function report_nonce(): string {
		return 'estasoft_nd_report_' . self::PLATFORM;
	}

	/**
	 * Keeps the choices, and makes a link when that is what was asked for. Then
	 * returns to the report.
	 *
	 * @return void
	 */
	private function save_report(): void {
		check_admin_referer( $this->report_nonce() );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- The nonce was checked above.
		$ticked   = isset( $_POST['report']['sections'] ) && is_array( $_POST['report']['sections'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['report']['sections'] ) ) : array();
		$sections = array_values( array_intersect( ReportView::keys(), $ticked ) );
		$name     = isset( $_POST['report']['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['report']['contact_name'] ) ) : '';
		$email    = isset( $_POST['report']['contact_email'] ) && is_string( $_POST['report']['contact_email'] ) ? sanitize_email( wp_unslash( $_POST['report']['contact_email'] ) ) : '';
		$days     = isset( $_POST['report']['days'] ) ? absint( $_POST['report']['days'] ) : ReportLinks::DAYS[0];
		$step     = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : 'preview';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		set_transient(
			$this->choice_key(),
			array(
				'sections'      => $sections,
				'contact_name'  => $name,
				'contact_email' => $email,
				'days'          => $days,
			),
			HOUR_IN_SECONDS
		);

		$args = array();

		if ( 'make' === $step ) {
			if ( array() === $sections ) {
				$args = $this->remember( false, __( 'Tick at least one thing to include, then make the link.', 'estasoft-news-distribution' ) );
			} else {
				$report = $this->plugin->make( ReadinessReport::class )->build(
					array(
						'samples'       => 2,
						'contact_name'  => $name,
						'contact_email' => $email,
					)
				);

				if ( array() === $report ) {
					$args = $this->remember( false, __( 'The report could not be made.', 'estasoft-news-distribution' ) );
				} else {
					$earlier = null !== ReportLinks::active( self::PLATFORM, Clock::now() );
					$made    = ReportLinks::create( self::PLATFORM, $report, $sections, $days, Clock::now() );
					$words   = sprintf(
						/* translators: %d: the number of days the link works. */
						_n( 'Link made. It works for %d day.', 'Link made. It works for %d days.', (int) $made['days'], 'estasoft-news-distribution' ),
						(int) $made['days']
					);
					$args = $this->remember( true, $earlier ? $words . ' ' . __( 'The earlier link stopped working.', 'estasoft-news-distribution' ) : $words );
				}
			}
		}

		wp_safe_redirect( $this->url( 'report', $args ) );
		exit;
	}
}
