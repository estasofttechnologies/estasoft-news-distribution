<?php
/**
 * The Site verification page.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Channels;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Channels\Verification\VerificationChannel;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Modules\VerificationModule;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `admin.php?page=estasoft-nd-channels&channel=verification[&engine=<id>]`
 * (the design board names it `tab=verification`; it is built as a channel page).
 *
 * A table of search engines with what News Distribution handles for each, what
 * its own check saw on the site and what the person marked, and a form for the
 * engine in hand. The form keeps a meta tag or a file, which `VerificationModule`
 * prints and answers without writing anything to the site root, and "Save and
 * check" asks the site's own address the way a visitor would. News Distribution
 * cannot see whether an engine accepted the proof, so the person marks that.
 */
final class VerificationScreen extends AbstractChannelScreen {

	/**
	 * How long a rejected form waits to be shown again, in seconds.
	 */
	private const STASH_TTL = 120;

	public static function channel_id(): string {
		return 'verification';
	}

	public function description(): string {
		return __( 'Prove that you own the site to each search engine, so you can submit sitemaps.', 'estasoft-news-distribution' );
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
		Assets::screen_style( 'verification' );
		Assets::screen_script( 'verification' );
	}

	public function render( string $tab ): string {
		$channel = $this->channel instanceof VerificationChannel ? $this->channel : null;

		if ( null === $channel ) {
			return '';
		}

		$engine = $this->engine();
		$stash  = $this->stash();
		$info   = Message::text( 'info', __( 'Keep each tag and file in place after you verify. Removing it can end the verification, and some engines check again from time to time.', 'estasoft-news-distribution' ) );
		$layout = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-verify' ),
			Html::tag( 'div', array( 'class' => 'estasoft-nd-verify__form' ), $this->form_card( $channel, $engine, $stash ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-verify__side' ), $this->explain_card( $channel, $engine ) )
		);

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-16' ), $this->after_save( $channel ) . $info . $this->table( $channel, $engine ) . $layout );
	}

	/**
	 * Saves one engine's form, then checks the site's own address.
	 *
	 * Runs on `admin-post.php` (`VerificationModule`). Nothing is kept when a
	 * value is rejected; the form is shown again with the reasons.
	 *
	 * @return array{saved: bool, engine: string}
	 */
	public function save(): array {
		check_admin_referer( VerificationModule::ACTION );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$engine = sanitize_key( (string) $this->read_field( 'engine', 'key' ) );
		$method = $this->read_field( 'method', 'key' ) ?? 'none';
		$code   = $this->read_field( 'code', 'tags' ) ?? '';
		$name   = $this->read_field( 'file_name', 'text' ) ?? '';
		$body   = $this->read_field( 'file_body', 'tags' ) ?? '';
		$marked = $this->read_field( 'marked', 'text' ) ?? '';
		$marked = '' !== $marked && '0' !== $marked;
		$result = null;

		if ( $this->channel instanceof VerificationChannel ) {
			$result = $this->channel->save_engine(
				$engine,
				array(
					'method'    => $method,
					'code'      => $code,
					'file_name' => $name,
					'file_body' => $body,
					'marked'    => $marked,
				)
			);
		}

		if ( ! $this->channel instanceof VerificationChannel || is_wp_error( $result ) ) {
			$data = is_wp_error( $result ) ? $result->get_error_data() : array();

			set_transient(
				$this->stash_key(),
				array(
					'engine' => $engine,
					'values' => array(
						'method'    => $method,
						'code'      => sanitize_text_field( $code ),
						'file_name' => $name,
						'file_body' => $body,
						'marked'    => $marked,
					),
					'errors' => is_array( $data ) && isset( $data['fields'] ) ? (array) $data['fields'] : array( 'method' => is_wp_error( $result ) ? $result->get_error_message() : '' ),
				),
				self::STASH_TTL
			);

			return array(
				'saved'  => false,
				'engine' => $engine,
			);
		}

		$this->channel->check( $engine );

		return array(
			'saved'  => true,
			'engine' => $engine,
		);
	}

	/**
	 * One value of the posted form, cleaned the way its kind needs.
	 *
	 * A code or a file may hold a meta tag or Bing's XML, so those keep the tags a verification may use and nothing else.
	 *
	 * @param string $key  The field.
	 * @param string $kind `key`, `text` or `tags`.
	 * @return string|null Null when the field was not posted.
	 */
	private function read_field( string $key, string $kind ): ?string {
		check_admin_referer( VerificationModule::ACTION );

		if ( ! isset( $_POST['verification'][ $key ] ) || ! is_string( $_POST['verification'][ $key ] ) ) {
			return null;
		}

		if ( 'key' === $kind ) {
			return sanitize_key( wp_unslash( $_POST['verification'][ $key ] ) );
		}

		if ( 'tags' === $kind ) {
			return trim( wp_kses( wp_unslash( $_POST['verification'][ $key ] ), VerificationChannel::body_tags() ) );
		}

		return trim( sanitize_text_field( wp_unslash( $_POST['verification'][ $key ] ) ) );
	}

	/**
	 * The engine the form is for: the one in the address, else the first.
	 *
	 * @return string
	 */
	private function engine(): string {
		$asked = Query::key( 'engine' );

		return isset( VerificationChannel::engines()[ $asked ] ) ? $asked : (string) array_key_first( VerificationChannel::engines() );
	}

	/**
	 * What "Save and check" found, shown once after the form was posted.
	 *
	 * @param VerificationChannel $channel The channel.
	 * @return string
	 */
	private function after_save( VerificationChannel $channel ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only chooses which message to show.
		if ( Query::is( 'error', '1' ) ) {
			return Message::text( 'danger', __( 'Nothing was saved. Fix the highlighted settings and save again.', 'estasoft-news-distribution' ) );
		}

		if ( ! Query::is( 'saved', '1' ) ) {
			return '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$engine = $this->engine();
		$name   = (string) VerificationChannel::engines()[ $engine ]['name'];
		$check  = $channel->record( $engine )['check'];
		$kind   = $this->what( $channel->record( $engine )['method'] );
		$text   = $this->result_text( $check['state'], $check['code'], $kind, $name );

		return Message::bar(
			$text['tone'],
			Html::tag( 'strong', array(), esc_html__( 'Saved.', 'estasoft-news-distribution' ) ) . ' ' . esc_html( $text['text'] )
		);
	}

	/**
	 * The table of search engines.
	 *
	 * @param VerificationChannel $channel The channel.
	 * @param string              $current The engine in hand.
	 * @return string
	 */
	private function table( VerificationChannel $channel, string $current ): string {
		$heads = '';

		foreach ( array(
			__( 'Search engine', 'estasoft-news-distribution' ),
			__( 'What News Distribution handles', 'estasoft-news-distribution' ),
			__( 'Your side', 'estasoft-news-distribution' ),
			__( 'You marked', 'estasoft-news-distribution' ),
			'',
		) as $head ) {
			$heads .= Html::tag(
				'div',
				array( 'role' => 'columnheader' ),
				'' === $head ? Html::tag( 'span', array( 'class' => 'estasoft-nd-sr' ), esc_html__( 'Actions', 'estasoft-news-distribution' ) ) : esc_html( $head )
			);
		}

		$rows = Html::tag(
			'div',
			array(
				'class' => 'estasoft-nd-grid-row estasoft-nd-grid-row--head estasoft-nd-cols-verify',
				'role'  => 'row',
			),
			$heads
		);

		foreach ( VerificationChannel::engines() as $engine => $info ) {
			$record = $channel->record( (string) $engine );
			$set_up = 'none' !== $record['method'];
			$link   = Html::tag(
				'a',
				array(
					'href'       => $this->url( '', array( 'engine' => (string) $engine ) ) . '#estasoft-nd-verify-form',
					'class'      => 'estasoft-nd-strong',
					'aria-label' => sprintf(
						/* translators: 1: "Manage" or "Start"; 2: a search engine name. */
						__( '%1$s %2$s', 'estasoft-news-distribution' ),
						$set_up || $record['marked'] ? __( 'Manage', 'estasoft-news-distribution' ) : __( 'Start', 'estasoft-news-distribution' ),
						$info['name']
					),
				),
				esc_html( $set_up || $record['marked'] ? __( 'Manage', 'estasoft-news-distribution' ) : __( 'Start', 'estasoft-news-distribution' ) )
			);
			$cells = array(
				Html::tag( 'strong', array(), esc_html( $info['name'] ) ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $info['handles'] ) ),
				$this->side_cell( $record ),
				$this->marked_cell( $record ),
				$link,
			);
			$html  = '';

			foreach ( $cells as $cell ) {
				$html .= Html::tag( 'div', array( 'role' => 'cell' ), $cell );
			}

			$rows .= Html::tag(
				'div',
				array(
					'class'        => 'estasoft-nd-grid-row estasoft-nd-cols-verify' . ( (string) $engine === $current ? ' estasoft-nd-grid-row--current' : '' ),
					'role'         => 'row',
					'aria-current' => (string) $engine === $current ? 'true' : null,
				),
				$html
			);
		}

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-scroll' ),
			Html::tag(
				'div',
				array(
					'class'      => 'estasoft-nd-table',
					'role'       => 'table',
					'aria-label' => __( 'Search engines and the state of their verification', 'estasoft-news-distribution' ),
				),
				$rows
			)
		);
	}

	/**
	 * "Your side": what the last check of the site's own address saw.
	 *
	 * @param array<string, mixed> $record The engine's record.
	 * @return string
	 */
	private function side_cell( array $record ): string {
		$method = (string) $record['method'];
		$state  = (string) $record['check']['state'];

		if ( 'none' === $method ) {
			return $this->state( 'minus-circle', 'off', __( 'Nothing added yet', 'estasoft-news-distribution' ) );
		}

		if ( 'dns' === $method ) {
			return $this->state( 'minus-circle', 'off', __( 'DNS record is yours to add', 'estasoft-news-distribution' ) );
		}

		if ( '' === $state || 'none' === $state ) {
			return $this->state( 'minus-circle', 'off', __( 'Not checked yet', 'estasoft-news-distribution' ) );
		}

		$map = array(
			'live'      => array( 'check-circle', 'ok', 'meta' === $method ? __( 'Tag is live on the home page', 'estasoft-news-distribution' ) : __( 'File is live in the site root', 'estasoft-news-distribution' ) ),
			'no_tag'    => array( 'warning', 'bad', __( 'Tag not found on the home page', 'estasoft-news-distribution' ) ),
			'not_found' => array( 'x-circle', 'fail', __( 'Not found', 'estasoft-news-distribution' ) ),
			'differs'   => array( 'warning', 'bad', __( 'Contents differ', 'estasoft-news-distribution' ) ),
			'error'     => array( 'warning', 'bad', __( 'Your site answered with an error', 'estasoft-news-distribution' ) ),
			'failed'    => array( 'warning', 'bad', __( 'Your site did not answer', 'estasoft-news-distribution' ) ),
			'plain'     => array( 'warning', 'bad', __( 'Plain permalinks cannot serve the file', 'estasoft-news-distribution' ) ),
		);
		$row = $map[ $state ] ?? $map['failed'];

		return $this->state( $row[0], $row[1], $row[2] );
	}

	/**
	 * "You marked": the person's own word that the engine accepted the proof.
	 *
	 * @param array<string, mixed> $record The engine's record.
	 * @return string
	 */
	private function marked_cell( array $record ): string {
		if ( ! empty( $record['marked'] ) ) {
			return $this->state( 'check-circle', 'ok', __( 'Verified', 'estasoft-news-distribution' ) );
		}

		return 'none' === $record['method']
			? $this->state( 'minus-circle', 'off', __( 'Not set up', 'estasoft-news-distribution' ) )
			: $this->state( 'minus-circle', 'off', __( 'Not marked yet', 'estasoft-news-distribution' ) );
	}

	/**
	 * An icon and a short text.
	 *
	 * @param string $icon  Icon name.
	 * @param string $state ok, off, bad or fail.
	 * @param string $text  Plain text.
	 * @return string
	 */
	private function state( string $icon, string $state, string $text ): string {
		return Html::tag( 'span', array( 'class' => 'estasoft-nd-vstate estasoft-nd-vstate--' . $state ), Icon::svg( $icon ) . Html::tag( 'span', array(), esc_html( $text ) ) );
	}

	/**
	 * The form for one engine.
	 *
	 * @param VerificationChannel  $channel The channel.
	 * @param string               $engine  Engine id.
	 * @param array<string, mixed> $stash   A rejected save to show again.
	 * @return string
	 */
	private function form_card( VerificationChannel $channel, string $engine, array $stash ): string {
		$info   = VerificationChannel::engines()[ $engine ];
		$record = $channel->record( $engine );
		$errors = array();
		$values = array(
			'method'    => $record['method'],
			'code'      => $record['code'],
			'file_name' => $record['file_name'],
			'file_body' => $record['file_body'],
			'marked'    => $record['marked'],
		);

		if ( ( $stash['engine'] ?? '' ) === $engine ) {
			$values = array_merge( $values, (array) ( $stash['values'] ?? array() ) );
			$errors = (array) ( $stash['errors'] ?? array() );
		}

		$method = in_array( (string) $values['method'], VerificationChannel::METHODS, true ) ? (string) $values['method'] : 'none';
		$body   = $this->method_radios( $info, $method, (string) ( $errors['method'] ?? '' ) )
			. $this->group( 'meta', $method, $this->text_row( 'code', __( 'Meta tag code', 'estasoft-news-distribution' ), (string) $values['code'], __( 'Paste the code, or the whole meta tag the search engine shows you.', 'estasoft-news-distribution' ), (string) ( $errors['code'] ?? '' ), 'ltr' ) )
			. $this->group(
				'file',
				$method,
				$this->text_row( 'file_name', __( 'File name', 'estasoft-news-distribution' ), (string) $values['file_name'], $info['file_hint'], (string) ( $errors['file_name'] ?? '' ), 'ltr', $this->file_placeholder( $engine ) )
				. $this->body_row( $engine, (string) $values['file_body'], (string) ( $errors['file_body'] ?? '' ) )
			)
			. $this->group( 'dns', $method, Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $info['dns_help'] . ' ' . __( 'News Distribution cannot see DNS records, so it cannot check this method.', 'estasoft-news-distribution' ) ) ) );

		$buttons = Button::submit( __( 'Save and check', 'estasoft-news-distribution' ), array( 'variant' => 'primary' ) )
			. Button::link(
				/* translators: %s: a search engine name. */
				sprintf( __( 'Open %s', 'estasoft-news-distribution' ), $info['name'] ),
				$info['open_url'],
				array(
					'attrs' => array(
						'target' => '_blank',
						'rel'    => 'noopener noreferrer',
					),
				)
			);

		return Html::tag(
			'form',
			array(
				'id'               => 'estasoft-nd-verify-form',
				'method'           => 'post',
				'action'           => admin_url( 'admin-post.php' ),
				'class'            => 'estasoft-nd-card estasoft-nd-card--padded estasoft-nd-gap-16',
				'novalidate'       => true,
				'data-verify-form' => true,
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => VerificationModule::ACTION,
				)
			)
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'verification[engine]',
					'value' => $engine,
				)
			)
			. wp_nonce_field( VerificationModule::ACTION, '_wpnonce', true, false )
			. Html::tag( 'h2', array( 'class' => 'estasoft-nd-title' ), esc_html( $info['name'] ) )
			. $body
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $buttons )
			. $this->after_block( $info, $method, (bool) $values['marked'], (string) $record['marked_on'] )
		);
	}

	/**
	 * The method choice.
	 *
	 * @param array<string, string> $info   The engine's details.
	 * @param string                $method The chosen method.
	 * @param string                $error  The reason a rejected save gave.
	 * @return string
	 */
	private function method_radios( array $info, string $method, string $error ): string {
		$options = array(
			'meta' => array( __( 'Meta tag', 'estasoft-news-distribution' ), __( 'News Distribution prints it in the head of your home page.', 'estasoft-news-distribution' ) ),
			'file' => array( $info['file_label'], __( 'News Distribution serves the file from the site root.', 'estasoft-news-distribution' ) ),
			'dns'  => array( $info['dns_label'], $info['dns_help'] ),
			'none' => array( __( 'Not set up', 'estasoft-news-distribution' ), __( 'News Distribution prints and serves nothing for this search engine.', 'estasoft-news-distribution' ) ),
		);
		$html    = '';

		foreach ( $options as $key => $option ) {
			$html .= Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'    => 'radio',
						'name'    => 'verification[method]',
						'value'   => $key,
						'checked' => (string) $key === $method,
					)
				) . Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html( $option[0] ) ) . '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( $option[1] ) )
				)
			);
		}

		return Html::tag(
			'fieldset',
			array( 'class' => 'estasoft-nd-verify__method' ),
			Html::tag( 'legend', array( 'class' => 'estasoft-nd-strong' ), esc_html__( 'Method', 'estasoft-news-distribution' ) )
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $html )
			. ( '' !== $error ? Html::tag(
				'p',
				array(
					'class' => 'estasoft-nd-verify__error estasoft-nd-small',
					'role'  => 'alert',
				),
				Icon::svg( 'warning' ) . esc_html( $error )
			) : '' )
		);
	}

	/**
	 * The fields for one method. They are all shown without a script; with it only the chosen method's are.
	 *
	 * @param string $method The method the fields belong to.
	 * @param string $chosen The chosen method.
	 * @param string $inner  Trusted HTML.
	 * @return string
	 */
	private function group( string $method, string $chosen, string $inner ): string {
		return Html::tag(
			'div',
			array(
				'class'             => 'estasoft-nd-verify__group estasoft-nd-stack estasoft-nd-gap-16',
				'data-method-group' => $method,
				'hidden'            => $method !== $chosen,
			),
			$inner
		);
	}

	/**
	 * A one-line field with its help and the reason a rejected save gave.
	 *
	 * @param string $field       Field name inside `verification[...]`.
	 * @param string $label       Plain label.
	 * @param string $value       Current value.
	 * @param string $help        Plain help text.
	 * @param string $error       The reason a rejected save gave.
	 * @param string $dir         Text direction of the value.
	 * @param string $placeholder Placeholder, or empty.
	 * @return string
	 */
	private function text_row( string $field, string $label, string $value, string $help, string $error, string $dir, string $placeholder = '' ): string {
		$id      = 'estasoft-nd-verify-' . $field;
		$help_id = $id . '-help';

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-verify__row' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-strong',
					'for'   => $id,
				),
				esc_html( $label )
			)
			. Html::void(
				'input',
				array(
					'type'             => 'text',
					'id'               => $id,
					'name'             => 'verification[' . $field . ']',
					'value'            => $value,
					'dir'              => $dir,
					'class'            => 'estasoft-nd-field estasoft-nd-verify__input',
					'placeholder'      => '' !== $placeholder ? $placeholder : null,
					'autocomplete'     => 'off',
					'spellcheck'       => 'false',
					'aria-describedby' => $help_id,
					'aria-invalid'     => '' !== $error ? 'true' : null,
				)
			)
			. $this->help_or_error( $help_id, $help, $error )
		);
	}

	/**
	 * The file contents box.
	 *
	 * @param string $engine Engine id.
	 * @param string $value  Current contents.
	 * @param string $error  The reason a rejected save gave.
	 * @return string
	 */
	private function body_row( string $engine, string $value, string $error ): string {
		$id      = 'estasoft-nd-verify-file_body';
		$help_id = $id . '-help';
		$helps   = array(
			'google' => __( 'Leave this empty and News Distribution writes the line Google expects. Paste the contents only if Google shows something different.', 'estasoft-news-distribution' ),
			'bing'   => __( 'Paste the XML Bing shows you, with the code inside the user element.', 'estasoft-news-distribution' ),
			'yandex' => __( 'Paste the contents Yandex shows you, with the code after “Verification:”.', 'estasoft-news-distribution' ),
			'baidu'  => __( 'Paste the contents Baidu shows you.', 'estasoft-news-distribution' ),
		);

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4 estasoft-nd-verify__row' ),
			Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-strong',
					'for'   => $id,
				),
				esc_html__( 'File contents', 'estasoft-news-distribution' )
			)
			. Html::tag(
				'textarea',
				array(
					'id'               => $id,
					'name'             => 'verification[file_body]',
					'rows'             => 4,
					'dir'              => 'ltr',
					'class'            => 'estasoft-nd-field estasoft-nd-verify__input estasoft-nd-verify__textarea estasoft-nd-mono',
					'spellcheck'       => 'false',
					'aria-describedby' => $help_id,
					'aria-invalid'     => '' !== $error ? 'true' : null,
				),
				esc_textarea( $value )
			)
			. $this->help_or_error( $help_id, $helps[ $engine ] ?? '', $error )
		);
	}

	/**
	 * The line under a field: its help, or the reason a rejected save gave.
	 *
	 * @param string $id    Id of the line, for aria-describedby.
	 * @param string $help  Plain help text.
	 * @param string $error The reason a rejected save gave.
	 * @return string
	 */
	private function help_or_error( string $id, string $help, string $error ): string {
		if ( '' !== $error ) {
			return Html::tag(
				'p',
				array(
					'id'    => $id,
					'class' => 'estasoft-nd-verify__error estasoft-nd-small',
					'role'  => 'alert',
				),
				Icon::svg( 'warning' ) . esc_html( $error )
			);
		}

		return Html::tag(
			'p',
			array(
				'id'    => $id,
				'class' => 'estasoft-nd-muted estasoft-nd-small',
			),
			esc_html( $help )
		);
	}

	/**
	 * The example shown in an empty file name box.
	 *
	 * @param string $engine Engine id.
	 * @return string
	 */
	private function file_placeholder( string $engine ): string {
		$examples = array(
			'google' => 'google1234567890abcdef.html',
			'bing'   => 'BingSiteAuth.xml',
			'yandex' => 'yandex_a1b2c3d4e5f6.html',
			'baidu'  => 'baidu_verify_a1b2c3d4e5.html',
		);

		return $examples[ $engine ] ?? '';
	}

	/**
	 * "After you verify": the person's own mark that the engine accepted the proof.
	 *
	 * @param array<string, string> $info      The engine's details.
	 * @param string                $method    The chosen method.
	 * @param bool                  $marked    Whether it is marked.
	 * @param string                $marked_on The day it was marked, or an empty string.
	 * @return string
	 */
	private function after_block( array $info, string $method, bool $marked, string $marked_on ): string {
		$id    = 'estasoft-nd-verify-marked';
		$kind  = $this->what( $method );
		$label = sprintf(
			/* translators: 1: a search engine name; 2: "the file", "the tag" or "the record". */
			__( 'I clicked Verify in %1$s and it accepted %2$s', 'estasoft-news-distribution' ),
			$info['name'],
			$kind
		);
		$day  = $marked ? \Estasoft\NewsDistribution\Channels\Listings\ListingsChannel::short_date( $marked_on ) : '';
		$help = ( '' !== $day
			? sprintf(
				/* translators: %s: a short date such as "14 Oct". */
				__( 'You marked this on %s.', 'estasoft-news-distribution' ),
				$day
			) . ' '
			: '' )
			. __( 'News Distribution can check that the file is live on your site. It cannot see whether the engine accepted it, so you mark it yourself.', 'estasoft-news-distribution' );

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-verify__after estasoft-nd-stack estasoft-nd-gap-8' ),
			Html::tag( 'h3', array( 'class' => 'estasoft-nd-strong' ), esc_html__( 'After you verify', 'estasoft-news-distribution' ) )
			. Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'verification[marked]',
					'value' => '0',
				)
			)
			. Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-check estasoft-nd-check--top',
					'for'   => $id,
				),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'id'      => $id,
						'name'    => 'verification[marked]',
						'value'   => '1',
						'checked' => $marked,
					)
				) . Html::tag( 'span', array(), esc_html( $label ) )
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $help ) )
		);
	}

	/**
	 * "What Save and check tells you": the results the page can show.
	 *
	 * @param VerificationChannel $channel The channel.
	 * @param string              $engine  Engine id.
	 * @return string
	 */
	private function explain_card( VerificationChannel $channel, string $engine ): string {
		$info   = VerificationChannel::engines()[ $engine ];
		$method = $channel->record( $engine )['method'];
		$kind   = $this->what( $method );
		$list   = '';

		foreach ( array( 'live', 'not_found', 'differs', 'error' ) as $state ) {
			$row   = $this->result_text( $state, 403, $kind, $info['name'] );
			$icons = array(
				'live'      => array( 'check-circle', 'ok' ),
				'not_found' => array( 'x-circle', 'fail' ),
				'differs'   => array( 'warning', 'bad' ),
				'error'     => array( 'warning', 'bad' ),
			);
			$list .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule estasoft-nd-vstate--' . $icons[ $state ][1] ), Icon::svg( $icons[ $state ][0] ) . Html::tag( 'span', array(), esc_html( $row['text'] ) ) );
		}

		return Html::tag(
			'section',
			array( 'class' => 'estasoft-nd-card estasoft-nd-card--padded estasoft-nd-gap-12' ),
			Html::tag( 'h2', array( 'class' => 'estasoft-nd-title' ), esc_html__( 'What Save and check tells you', 'estasoft-news-distribution' ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'News Distribution asks your own site for the file, the way a visitor would, and shows one of these results.', 'estasoft-news-distribution' ) )
			. Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-small' ), $list )
			. Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: a search engine name; 2: the same name. */
						__( 'What it cannot do: see whether %1$s accepted the file. Only %2$s shows that.', 'estasoft-news-distribution' ),
						$info['name'],
						$info['name']
					)
				)
			)
		);
	}

	/**
	 * What a check result means, in plain words.
	 *
	 * @param string $state One of the states a check keeps.
	 * @param int    $code  The HTTP status the site answered with.
	 * @param string $kind  "the file", "the tag" or "the record".
	 * @param string $name  The search engine's name.
	 * @return array{tone: string, text: string}
	 */
	private function result_text( string $state, int $code, string $kind, string $name ): array {
		$is_tag = $this->tag_word() === $kind;

		switch ( $state ) {
			case 'live':
				return array(
					'tone' => 'success',
					'text' => $is_tag
						? __( 'The tag is live on your home page.', 'estasoft-news-distribution' )
						: __( 'The file is live and its contents match.', 'estasoft-news-distribution' ),
				);
			case 'no_tag':
				return array(
					'tone' => 'warning',
					'text' => __( 'The tag is not on your home page. A caching plugin may be showing an older copy. Clear its cache and check again.', 'estasoft-news-distribution' ),
				);
			case 'not_found':
				return array(
					'tone' => 'danger',
					'text' => $is_tag
						? __( 'Not found. Your home page did not answer. Check that it opens.', 'estasoft-news-distribution' )
						: __( 'Not found. Nothing answers at that address. Check the file name.', 'estasoft-news-distribution' ),
				);
			case 'differs':
				return array(
					'tone' => 'warning',
					'text' => sprintf(
						/* translators: %s: a search engine name. */
						__( 'The file is there but its contents differ. Paste what %s shows.', 'estasoft-news-distribution' ),
						$name
					),
				);
			case 'error':
				return array(
					'tone' => 'warning',
					'text' => $code > 0 && 403 !== $code
						? sprintf(
							/* translators: %d: an HTTP status such as 500. */
							__( 'Your site answered with an error (%d). A firewall or caching plugin may be blocking the file.', 'estasoft-news-distribution' ),
							$code
						)
						: __( 'Your site answered with an error, for example 403. A firewall or caching plugin may be blocking the file.', 'estasoft-news-distribution' ),
				);
			case 'plain':
				return array(
					'tone' => 'warning',
					'text' => __( 'Your site uses plain permalinks, so the file cannot be served at its address. Choose another structure in Settings, Permalinks, then check again.', 'estasoft-news-distribution' ),
				);
			case 'failed':
				return array(
					'tone' => 'warning',
					'text' => __( 'Your site did not answer the check. Open your site in a browser to see whether it is up.', 'estasoft-news-distribution' ),
				);
			default:
				return array(
					'tone' => 'info',
					'text' => __( 'There is nothing for News Distribution to check for this method.', 'estasoft-news-distribution' ),
				);
		}
	}

	/**
	 * "the file", "the tag" or "the record", for a method.
	 *
	 * @param string $method The method.
	 * @return string
	 */
	private function what( string $method ): string {
		if ( 'meta' === $method ) {
			return $this->tag_word();
		}

		return 'dns' === $method ? __( 'the record', 'estasoft-news-distribution' ) : __( 'the file', 'estasoft-news-distribution' );
	}

	/**
	 * The words "the tag".
	 *
	 * @return string
	 */
	private function tag_word(): string {
		return __( 'the tag', 'estasoft-news-distribution' );
	}

	/**
	 * The rejected save to show again, read once.
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
		return 'estasoft_nd_verification_' . get_current_user_id();
	}
}
