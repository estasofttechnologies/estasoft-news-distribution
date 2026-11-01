<?php
/**
 * The Listings page: Ground News and Particle.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\AddressRow;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\SaveBar;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Channels\Listings\ListingsChannel;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Applications;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Modules\ListingsModule;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=listings`. Platforms that decide
 * for themselves which publishers to include. The page prepares what to send and
 * keeps the person's own record (`Core\Applications`); it sends nothing and
 * cannot see any answer. "Write the email" is a `mailto:` link: the person's own
 * email app sends it.
 */
final class ListingsScreen extends AbstractChannelScreen {

	/**
	 * How long a rejected form waits to be shown again, in seconds.
	 */
	private const STASH_TTL = 120;

	public static function channel_id(): string {
		return 'listings';
	}

	public function description(): string {
		return __( 'Platforms that decide for themselves which publishers to include.', 'estasoft-news-distribution' );
	}

	/**
	 * The page is a checklist, not a switch: no mode tag and no status pill.
	 *
	 * @return bool
	 */
	public function shows_status(): bool {
		return false;
	}

	public function enqueue( string $tab ): void {
		Assets::screen_style( 'address-row' );
		Assets::screen_style( 'listings' );
		Assets::screen_script( 'address-row' );
		Assets::screen_script( 'dirty-form' );
	}

	public function render( string $tab ): string {
		$stash = $this->stash();
		$cards = '';

		foreach ( ListingsChannel::platforms() as $platform => $details ) {
			$cards .= $this->platform_card( (string) $platform, $details, (array) ( $stash['values'][ $platform ] ?? array() ), (string) ( $stash['errors'][ $platform ] ?? '' ) );
		}

		$info   = Message::bar(
			'info',
			esc_html__( 'Ground News and Particle do not take a feed from publishers: you ask to be listed and they decide.', 'estasoft-news-distribution' )
			. ' ' . Html::tag( 'strong', array(), esc_html__( 'News Distribution cannot see whether you are listed', 'estasoft-news-distribution' ) )
			. esc_html__( ', so set each status yourself.', 'estasoft-news-distribution' )
		);
		$cannot = Card::note(
			'cannot',
			__( 'What it cannot do', 'estasoft-news-distribution' ),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted' ),
				esc_html__( 'It cannot send a request for you or see the answer. “Write the email” opens a draft in your email app with the Ground News details filled in, and you press send. It cannot speed up a decision, and it cannot tell you when you are listed.', 'estasoft-news-distribution' )
			)
		);

		return $this->page_form( $info . $cards . $cannot );
	}

	/**
	 * Saves the status and date of each platform from the form.
	 *
	 * Runs on `admin-post.php` (`ListingsModule`). A bad date or status saves
	 * nothing for that platform and is shown again beside it.
	 *
	 * @return bool Whether everything was saved.
	 */
	public function save(): bool {
		check_admin_referer( ListingsModule::ACTION );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$input  = isset( $_POST['listings'] ) && is_array( $_POST['listings'] ) ? map_deep( wp_unslash( $_POST['listings'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
		$errors = array();
		$apps   = $this->plugin->applications();

		foreach ( array_keys( ListingsChannel::platforms() ) as $platform ) {
			$row = isset( $input[ $platform ] ) && is_array( $input[ $platform ] ) ? $input[ $platform ] : null;

			if ( null === $row ) {
				continue;
			}

			$day = Applications::parse_date( (string) ( $row['applied_on'] ?? '' ) );

			if ( null === $day ) {
				$errors[ $platform ] = __( 'Write the date as day, month and year, for example 14 Sep 2026.', 'estasoft-news-distribution' );
				continue;
			}

			$result = $apps->save(
				(string) $platform,
				array(
					'status'     => sanitize_key( (string) ( $row['status'] ?? '' ) ),
					'applied_on' => $day,
				)
			);

			if ( is_wp_error( $result ) ) {
				$errors[ $platform ] = $result->get_error_message();
			}
		}

		if ( array() !== $errors ) {
			set_transient(
				$this->stash_key(),
				array(
					'values' => $this->flat( $input ),
					'errors' => $errors,
				),
				self::STASH_TTL
			);
		}

		return array() === $errors;
	}

	/**
	 * One platform: what it is, how to ask, what to send and where the person stands.
	 *
	 * @param string               $platform Platform id.
	 * @param array<string, mixed> $details  The platform's details.
	 * @param array<string, mixed> $typed    What a rejected save had in the boxes.
	 * @param string               $error    The reason a rejected save gave.
	 * @return string
	 */
	private function platform_card( string $platform, array $details, array $typed, string $error ): string {
		$apps   = $this->plugin->applications();
		$record = $apps->get( $platform );
		$status = $apps->display_status( $platform );

		return Card::flush(
			'listing-' . $platform,
			(string) $details['name'],
			'',
			$this->intro( $platform, $details, $record ) . $this->send_block( $platform ) . $this->status_row( $platform, $record, $typed, $error ),
			StatusView::pill( $status )
		);
	}

	/**
	 * The paragraph about the platform and the buttons that go with it.
	 *
	 * @param string                $platform Platform id.
	 * @param array<string, mixed>  $details  The platform's details.
	 * @param array<string, string> $record   The saved record.
	 * @return string
	 */
	private function intro( string $platform, array $details, array $record ): string {
		$buttons = '';

		if ( 'ground-news' === $platform ) {
			$text = sprintf(
				/* translators: %s: an email address. */
				__( 'Ground News takes no publisher feed. Its help page asks news organizations to use the News Source Submission Form on its site, with the organization’s name and website. You can also write to %s. Ground News reviews every submission and gives no timeframe.', 'estasoft-news-distribution' ),
				(string) $details['email']
			);

			$buttons .= Button::link( __( 'Write the email', 'estasoft-news-distribution' ), $this->mailto( $details ) );
		} else {
			$day  = ListingsChannel::short_date( $record['applied_on'] );
			$text = __( 'Particle works with publishers through partnerships, so there is no form to fill in. Reach its team through its website, then record the date you asked.', 'estasoft-news-distribution' )
				. ' ' . ( '' !== $day
					? sprintf(
						/* translators: %s: a short date such as "14 Sep". */
						__( 'You asked on %s.', 'estasoft-news-distribution' ),
						$day
					)
					: __( 'You have not asked yet.', 'estasoft-news-distribution' ) );
		}

		foreach ( array( 'form', 'help', 'site' ) as $kind ) {
			if ( '' !== (string) $details[ $kind . '_url' ] ) {
				$buttons .= $this->external( (string) $details[ $kind . '_label' ], (string) $details[ $kind . '_url' ] );
			}
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-listing__intro' ),
			Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-16' ), $buttons )
		);
	}

	/**
	 * "What to send": the details a platform asks for, each with Copy.
	 *
	 * @param string $platform Platform id.
	 * @return string
	 */
	private function send_block( string $platform ): string {
		$facts = $this->facts();

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-listing__send' ),
			Html::tag( 'h3', array( 'class' => 'estasoft-nd-title estasoft-nd-title--sm' ), esc_html__( 'What to send', 'estasoft-news-distribution' ) )
			. Html::tag(
				'div',
				array(
					'class'    => 'estasoft-nd-stack estasoft-nd-gap-8',
					'data-for' => $platform,
				),
				AddressRow::value( __( 'Publication', 'estasoft-news-distribution' ), $facts['name'] )
				. AddressRow::value( __( 'Website', 'estasoft-news-distribution' ), $facts['url'] )
				. AddressRow::value( __( 'Languages', 'estasoft-news-distribution' ), $facts['languages'] )
			)
		);
	}

	/**
	 * The status and date boxes.
	 *
	 * @param string                $platform Platform id.
	 * @param array<string, string> $record   The saved record.
	 * @param array<string, mixed>  $typed    What a rejected save had in the boxes.
	 * @param string                $error    The reason a rejected save gave.
	 * @return string
	 */
	private function status_row( string $platform, array $record, array $typed, string $error ): string {
		$chosen    = (string) ( $typed['status'] ?? $record['status'] );
		$date      = isset( $typed['applied_on'] ) ? (string) $typed['applied_on'] : Applications::show_date( $record['applied_on'] );
		$status_id = 'estasoft-nd-listing-' . $platform . '-status';
		$date_id   = 'estasoft-nd-listing-' . $platform . '-date';
		$options   = '';

		foreach ( Applications::statuses() as $code ) {
			$options .= Html::tag(
				'option',
				array(
					'value'    => $code,
					'selected' => $code === $chosen,
				),
				esc_html( Status::word( $code ) )
			);
		}

		$hint_id = $date_id . '-hint';
		$hint    = Html::tag(
			'p',
			array(
				'class' => 'estasoft-nd-muted estasoft-nd-small estasoft-nd-listing__hint',
				'id'    => $hint_id,
			),
			esc_html__( 'Set by hand. Day, month and year, for example 14 Sep 2026. The Applications screen shows the same status and date.', 'estasoft-news-distribution' )
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-listing__status' ),
			Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-strong',
						'for'   => $status_id,
					),
					esc_html__( 'Status', 'estasoft-news-distribution' )
				)
				. Html::tag(
					'select',
					array(
						'id'    => $status_id,
						'name'  => 'listings[' . $platform . '][status]',
						'class' => 'estasoft-nd-field',
					),
					$options
				)
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'label',
					array(
						'class' => 'estasoft-nd-strong',
						'for'   => $date_id,
					),
					esc_html__( 'Date you requested', 'estasoft-news-distribution' )
				)
				. Html::void(
					'input',
					array(
						'type'             => 'text',
						'id'               => $date_id,
						'name'             => 'listings[' . $platform . '][applied_on]',
						'value'            => $date,
						'class'            => 'estasoft-nd-field estasoft-nd-listing__date',
						'placeholder'      => __( 'No date yet', 'estasoft-news-distribution' ),
						'autocomplete'     => 'off',
						'aria-describedby' => $hint_id,
						'aria-invalid'     => '' !== $error ? 'true' : null,
					)
				)
			)
			. ( '' !== $error
				? Html::tag(
					'p',
					array(
						'class' => 'estasoft-nd-listing__error estasoft-nd-small',
						'role'  => 'alert',
					),
					Icon::svg( 'warning' ) . esc_html( $error )
				)
				: $hint )
		);
	}

	/**
	 * What the person sends to a platform: the publication, the website and the languages.
	 *
	 * @return array{name: string, url: string, languages: string}
	 */
	private function facts(): array {
		$names = array();

		foreach ( $this->plugin->languages()->languages() as $language ) {
			$names[] = AbstractSitemapScreen::language_label( (string) $language );
		}

		return array(
			'name'      => $this->plugin->settings()->publication_name( $this->plugin->languages()->default_language() ),
			'url'       => untrailingslashit( home_url() ),
			'languages' => implode( ', ', $names ),
		);
	}

	/**
	 * A `mailto:` link that opens a draft in the person's email app. Nothing is sent.
	 *
	 * @param array<string, mixed> $details The platform's details.
	 * @return string
	 */
	private function mailto( array $details ): string {
		$facts   = $this->facts();
		$subject = sprintf(
			/* translators: %s: the name of a publication. */
			__( 'News source submission: %s', 'estasoft-news-distribution' ),
			$facts['name']
		);
		$body = sprintf(
			/* translators: 1: the platform name such as Ground News, 2: the publication name, 3: the website address, 4: the languages, such as "English, Arabic". */
			__( "Hello,\n\nI would like to ask for %2\$s to be considered as a source on %1\$s.\n\nPublication: %2\$s\nWebsite: %3\$s\nLanguages: %4\$s\n\nThank you.", 'estasoft-news-distribution' ),
			(string) $details['name'],
			$facts['name'],
			$facts['url'],
			$facts['languages']
		);

		return 'mailto:' . (string) $details['email'] . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( $body );
	}

	/**
	 * A link to another site, with the external-link icon.
	 *
	 * @param string $label Link text.
	 * @param string $url   Address.
	 * @return string
	 */
	private function external( string $label, string $url ): string {
		return Html::tag(
			'a',
			array(
				'href'   => $url,
				'target' => '_blank',
				'rel'    => 'noopener noreferrer',
				'class'  => 'estasoft-nd-cluster estasoft-nd-gap-4 estasoft-nd-strong',
			),
			esc_html( $label ) . Icon::svg( 'external-link' ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html__( '(opens in a new tab)', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * Wraps the cards in the page's own form: it posts to `admin-post.php`, and the
	 * save bar appears when something changes.
	 *
	 * @param string $inner Trusted HTML.
	 * @return string
	 */
	private function page_form( string $inner ): string {
		$message = '';

		if ( Query::is( 'saved', '1' ) ) {
			$message = Message::text( 'success', __( 'Saved.', 'estasoft-news-distribution' ) );
		} elseif ( Query::is( 'error', '1' ) ) {
			$message = Message::text( 'danger', __( 'Nothing was saved for the platform marked below. Fix it and save again.', 'estasoft-news-distribution' ) );
		}

		return Html::tag(
			'form',
			array(
				'method'          => 'post',
				'action'          => admin_url( 'admin-post.php' ),
				'class'           => 'estasoft-nd-stack estasoft-nd-gap-16',
				'novalidate'      => true,
				'data-dirty-form' => true,
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => ListingsModule::ACTION,
				)
			)
			. wp_nonce_field( ListingsModule::ACTION, '_wpnonce', true, false )
			. $message
			. $inner
			. SaveBar::render()
		);
	}

	/**
	 * The rejected save to show again, read once. It is only shown after a redirect that says it was rejected.
	 *
	 * @return array<string, mixed>
	 */
	private function stash(): array {
		if ( ! Query::has( 'error' ) ) {
			return array();
		}

		$found = get_transient( $this->stash_key() );

		if ( ! is_array( $found ) ) {
			return array();
		}

		delete_transient( $this->stash_key() );

		return $found;
	}

	/**
	 * Where a rejected save waits for the person who made it.
	 *
	 * @return string
	 */
	private function stash_key(): string {
		return 'estasoft_nd_listings_' . get_current_user_id();
	}

	/**
	 * The submitted boxes as platform => field => text, with anything else dropped.
	 *
	 * @param array<mixed> $input The posted `listings` array.
	 * @return array<string, array<string, string>>
	 */
	private function flat( array $input ): array {
		$out = array();

		foreach ( array_keys( ListingsChannel::platforms() ) as $platform ) {
			$row = isset( $input[ $platform ] ) && is_array( $input[ $platform ] ) ? $input[ $platform ] : array();

			$out[ $platform ] = array(
				'status'     => sanitize_key( (string) ( $row['status'] ?? '' ) ),
				'applied_on' => sanitize_text_field( (string) ( $row['applied_on'] ?? '' ) ),
			);
		}

		return $out;
	}
}
