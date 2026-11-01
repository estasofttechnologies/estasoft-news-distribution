<?php
/**
 * The first-run setup: opening it once after activation, and saving each step.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Modules;

use Estasoft\NewsDistribution\Admin\Setup\ChannelChoices;
use Estasoft\NewsDistribution\Admin\Setup\FirstCheck;
use Estasoft\NewsDistribution\Admin\Setup\Onboarding;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Access;
use Estasoft\NewsDistribution\Core\Demo\Demo;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Settings\Sections\General;
use Estasoft\NewsDistribution\Core\Settings\Sections\Languages;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * What happens around the setup screen (`Admin\Pages\SetupPage`):
 *
 * - **No redirect.** Activating the plugin sends nobody anywhere and prints no notice. The way in is the
 *   "Start setup" link in the plugin's row on the Plugins screen, and "Run the setup" on the Overview.
 * - **The saves.** Every step is a form that posts to `admin-post.php` (`estasoft_nd_setup`), so the setup
 *   works without a script. A step that fails keeps what was typed and the messages in a short-lived
 *   transient and returns to the same step.
 * - **The demo.** `estasoft_nd_setup_demo` (and "Try it with sample articles" on the start screen) starts demo
 *   mode through `Core\Demo\Demo::start()`: it turns on demo mode (Settings, Environment), makes the sample
 *   articles, and records it. Demo mode holds every push back and does not serve pulled feeds. The way out is
 *   "Exit demo" (`Modules\EnvironmentModule`).
 */
final class SetupModule implements ModuleInterface {

	public const ACTION      = 'estasoft_nd_setup';
	public const ACTION_DEMO = 'estasoft_nd_setup_demo';
	public const FORM_KEY    = 'estasoft_nd_setup_form_';
	public const PAGE        = 'estasoft-nd-setup';

	private Plugin $plugin;

	/**
	 * Adds the hooks.
	 *
	 * @param Plugin $plugin The running plugin.
	 * @return void
	 */
	public function register( Plugin $plugin ): void {
		$this->plugin = $plugin;

		add_action( 'admin_post_' . self::ACTION, array( $this, 'save' ) );
		add_action( 'admin_post_' . self::ACTION_DEMO, array( $this, 'start_demo' ) );
	}

	/**
	 * Where the person is in the setup.
	 *
	 * @return array{status: string, step: string, redirected: bool, updated: int, preset: string, checked: array<string, mixed>}
	 */
	public static function state(): array {
		return Onboarding::state();
	}

	/**
	 * Address of a setup step.
	 *
	 * @param string $step start, publication, channels or check.
	 * @return string
	 */
	public static function url( string $step = Onboarding::START ): string {
		return Links::admin( self::PAGE, array( 'step' => Onboarding::clean_step( $step ) ) );
	}

	/**
	 * A one-button form that turns on demo mode.
	 *
	 * @param string $label Button text (plain).
	 * @param string $classes The button's class names.
	 * @return string Escaped HTML.
	 */
	public static function demo_form( string $label, string $classes = 'button' ): string {
		return Html::tag(
			'form',
			array(
				'method' => 'post',
				'action' => admin_url( 'admin-post.php' ),
				'class'  => 'estasoft-nd-notice__demo',
			),
			Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => 'action',
					'value' => self::ACTION_DEMO,
				)
			)
			. wp_nonce_field( self::ACTION_DEMO, '_wpnonce', true, false )
			. Html::tag(
				'button',
				array(
					'type'  => 'submit',
					'class' => $classes,
				),
				esc_html( $label )
			)
		);
	}

	/**
	 * What a failed step left for the form to show, taken once.
	 *
	 * @param string $step The step that is open.
	 * @return array{values: array<string, mixed>, errors: array<string, string>}
	 */
	public static function flash( string $step ): array {
		$key  = self::FORM_KEY . get_current_user_id();
		$data = get_transient( $key );

		if ( ! is_array( $data ) || (string) ( $data['step'] ?? '' ) !== $step ) {
			return array(
				'values' => array(),
				'errors' => array(),
			);
		}

		delete_transient( $key );

		return array(
			'values' => (array) ( $data['values'] ?? array() ),
			'errors' => array_map( 'strval', (array) ( $data['errors'] ?? array() ) ),
		);
	}

	/**
	 * Turns on demo mode from the start screen.
	 *
	 * @return void
	 */
	public function start_demo(): void {
		check_admin_referer( self::ACTION_DEMO );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		$this->enter_demo();

		wp_safe_redirect( Links::admin( 'estasoft-nd' ) );
		exit;
	}

	/**
	 * Saves a step and moves on.
	 *
	 * @return void
	 */
	public function save(): void {
		check_admin_referer( self::ACTION );
		$this->plugin->access()->require_or_die( Access::MANAGE );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked above.
		$step = Onboarding::clean_step( isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '' );
		$go   = isset( $_POST['go'] ) ? sanitize_key( wp_unslash( $_POST['go'] ) ) : 'continue';
		$post = map_deep( wp_unslash( $_POST ), 'sanitize_text_field' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'skip_setup' === $go ) {
			Onboarding::save( array( 'status' => Onboarding::STATUS_SKIPPED ) );
			$this->go( Links::admin( 'estasoft-nd' ) );
		}

		if ( 'back' === $go ) {
			$this->go( self::url( Onboarding::previous( $step ) ) );
		}

		switch ( $step ) {
			case 'publication':
				$this->save_publication( $post );
				break;
			case 'channels':
				$this->save_channels( $post, 'skip_step' === $go );
				break;
			case 'check':
				Onboarding::save(
					array(
						'status' => Onboarding::STATUS_DONE,
						'step'   => 'check',
					)
				);
				$this->go( Links::admin( 'estasoft-nd' ) );
				break;
			default:
				$this->save_start( $post );
		}
	}

	/**
	 * The start screen: set up, or look around in demo mode.
	 *
	 * @param array<string, mixed> $post The cleaned form.
	 * @return void
	 */
	private function save_start( array $post ): void {
		if ( 'demo' === ( isset( $post['mode'] ) && is_string( $post['mode'] ) ? $post['mode'] : 'setup' ) ) {
			$this->enter_demo();
			$this->go( Links::admin( 'estasoft-nd' ) );
		}

		Onboarding::save(
			array(
				'status' => Onboarding::STATUS_STARTED,
				'step'   => 'publication',
			)
		);

		$this->go( self::url( 'publication' ) );
	}

	/**
	 * The publication step: the name, the languages and the post types.
	 *
	 * @param array<string, mixed> $post The cleaned form.
	 * @return void
	 */
	private function save_publication( array $post ): void {
		$name   = isset( $post['publication_name'] ) && is_string( $post['publication_name'] ) ? trim( wp_strip_all_tags( $post['publication_name'] ) ) : '';
		$errors = array();

		if ( '' === $name ) {
			$errors['publication_name'] = __( 'Enter a publication name.', 'estasoft-news-distribution' );
		} elseif ( mb_strlen( $name ) > 80 ) {
			$errors['publication_name'] = __( 'Use 80 characters or fewer.', 'estasoft-news-distribution' );
		}

		$types  = array();
		$known  = array_keys( General::post_type_options() );
		$posted = isset( $post['post_types'] ) && is_array( $post['post_types'] ) ? array_map( 'strval', $post['post_types'] ) : array();

		foreach ( $known as $type ) {
			if ( in_array( $type, $posted, true ) ) {
				$types[] = $type;
			}
		}

		if ( array() === $types ) {
			$errors['post_types'] = __( 'Choose at least one post type.', 'estasoft-news-distribution' );
		}

		$site = Languages::site_languages();
		$on   = array();

		foreach ( isset( $post['languages'] ) && is_array( $post['languages'] ) ? $post['languages'] : array() as $code ) {
			if ( is_string( $code ) && in_array( $code, $site, true ) ) {
				$on[] = $code;
			}
		}

		$default = $this->default_language( $site );

		if ( '' !== $default && ! in_array( $default, $on, true ) ) {
			$on[] = $default;
		}

		if ( array() === $errors ) {
			$saved = $this->plugin->settings()->save_section(
				'general',
				array(
					'publication_name' => $name,
					'post_types'       => $types,
				)
			);

			if ( is_wp_error( $saved ) ) {
				$errors = $this->errors_of( $saved, 'publication_name' );
			}
		}

		if ( array() === $errors && count( $site ) > 1 ) {
			$saved = $this->plugin->settings()->save_section(
				'languages',
				array(
					'shown' => $site,
					'on'    => $on,
				)
			);

			if ( is_wp_error( $saved ) ) {
				$errors = $this->errors_of( $saved, 'languages' );
			}
		}

		if ( array() !== $errors ) {
			set_transient(
				self::FORM_KEY . get_current_user_id(),
				array(
					'step'   => 'publication',
					'values' => array(
						'publication_name' => $name,
						'languages'        => $on,
						'post_types'       => $types,
					),
					'errors' => $errors,
				),
				2 * MINUTE_IN_SECONDS
			);

			$this->go( add_query_arg( 'error', 1, self::url( 'publication' ) ) );
		}

		Onboarding::save(
			array(
				'status' => Onboarding::STATUS_STARTED,
				'step'   => 'channels',
			)
		);

		$this->go( self::url( 'channels' ) );
	}

	/**
	 * The channels step: turn the chosen channels on, run the first check, show the result.
	 *
	 * @param array<string, mixed> $post The cleaned form.
	 * @param bool                 $skip Whether the person chose "Skip for now": nothing is changed.
	 * @return void
	 */
	private function save_channels( array $post, bool $skip ): void {
		$choices = new ChannelChoices( $this->plugin );
		$preset  = Onboarding::state()['preset'];
		$chosen  = $choices->on_now();

		if ( ! $skip ) {
			$read   = $choices->read( $post );
			$preset = $read['preset'];
			$chosen = $read['ids'];

			$choices->apply( $chosen );
		}

		$checked           = ( new FirstCheck( $this->plugin ) )->run();
		$checked['chosen'] = $choices->on_now();

		Onboarding::save(
			array(
				'status'  => Onboarding::STATUS_STARTED,
				'step'    => 'check',
				'preset'  => $preset,
				'checked' => $checked,
			)
		);

		$this->go( self::url( 'check' ) );
	}

	/**
	 * Starts the demo: demo mode on, the sample articles made, and the choice recorded.
	 *
	 * @return void
	 */
	private function enter_demo(): void {
		( new Demo( $this->plugin ) )->start( get_current_user_id() );
	}

	/**
	 * The language that cannot be turned off.
	 *
	 * @param string[] $site The site's language codes.
	 * @return string An empty string when the site has one language.
	 */
	private function default_language( array $site ): string {
		if ( count( $site ) < 2 ) {
			return '';
		}

		$default = (string) $this->plugin->settings()->get( 'languages.default', '' );

		if ( '' === $default ) {
			$default = (string) $this->plugin->languages()->default_language();
		}

		foreach ( $site as $code ) {
			if ( $code === $default || Catalog::primary( $code ) === Catalog::primary( $default ) ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Messages from a failed save, by field.
	 *
	 * @param \WP_Error $error    The error.
	 * @param string    $fallback The field to show the message on when it does not name one.
	 * @return array<string, string>
	 */
	private function errors_of( \WP_Error $error, string $fallback ): array {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['fields'] ) && array() !== $data['fields'] ) {
			return array_map( 'strval', (array) $data['fields'] );
		}

		return array( $fallback => $error->get_error_message() );
	}

	/**
	 * Redirects and stops.
	 *
	 * @param string $url Where to.
	 * @return void
	 */
	private function go( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
