<?php
/**
 * The page a readiness report link opens.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Channels\SmartNews;

use Estasoft\NewsDistribution\Admin\Assets;
use Estasoft\NewsDistribution\Admin\Output;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Frontend\Response;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `/news-distribution-report/{token}/` shows the snapshot behind a link: only the
 * sections the owner ticked, with no admin screen, no secret and no visitor
 * data. A token that is unknown, expired or revoked answers 404. The page is
 * not indexed, is never cached, sends no referrer and loads nothing from
 * another site. Adding `?print=1` opens the same page and asks the browser to
 * print it, which is how a PDF is saved.
 *
 * The routing hooks are in Modules\SmartNewsReportModule.
 */
final class ReportPage {

	/**
	 * The query variable the rewrite rule fills.
	 */
	public const VAR = 'estasoft_nd_report';

	/**
	 * Adds the rewrite rule for the shared page.
	 *
	 * @return void
	 */
	public static function add_rule(): void {
		add_rewrite_rule( '^' . ReportLinks::PATH . '/([A-Za-z0-9]{32})/?$', 'index.php?' . self::VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Registers the query variable.
	 *
	 * @param mixed $vars Query variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = is_array( $vars ) ? $vars : array();
		$vars[] = self::VAR;

		return $vars;
	}

	/**
	 * Answers a request for the shared page, then stops. Any other request is left alone.
	 *
	 * @param mixed $wp The WordPress request object.
	 * @return void
	 */
	public static function route( $wp ): void {
		if ( ! $wp instanceof \WP || empty( $wp->query_vars[ self::VAR ] ) ) {
			return;
		}

		$method    = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$for_print = Query::is( 'print', '1' );

		self::respond( sanitize_text_field( (string) $wp->query_vars[ self::VAR ] ), $for_print )->send( 'HEAD' === $method );
	}

	/**
	 * The response for a token.
	 *
	 * @param string $token The token from the address.
	 * @param bool   $for_print Whether to ask the browser to print the page.
	 * @return Response
	 */
	public static function respond( string $token, bool $for_print = false ): Response {
		$headers = array(
			'X-Robots-Tag'    => 'noindex, nofollow',
			'Cache-Control'   => 'no-store, max-age=0',
			'Referrer-Policy' => 'no-referrer',
		);
		$link    = ReportLinks::find( $token, Clock::now() );

		if ( null === $link ) {
			$headers['Content-Type'] = 'text/plain; charset=UTF-8';

			return new Response( 404, $headers, 'Not found' );
		}

		$origin = self::asset_origin();

		$headers['Content-Type']            = 'text/html; charset=UTF-8';
		$headers['Content-Security-Policy'] = "default-src 'none'; style-src {$origin}; script-src {$origin}; img-src 'self' data: https:; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

		return new Response( 200, $headers, self::document( $link, $for_print ) );
	}

	/**
	 * The whole page for a link.
	 *
	 * @param array<string, mixed> $link  The stored link.
	 * @param bool                 $for_print Whether to load the print script.
	 * @return string
	 */
	public static function document( array $link, bool $for_print ): string {
		$report = (array) $link['report'];
		$body   = ReportView::render( $report, (array) $link['include'], true );
		$title  = (string) ( $report['title'] ?? '' );
		$locale = str_replace( '_', '-', get_locale() );
		$dir    = is_rtl() ? 'rtl' : 'ltr';
		$head   = self::assets( $for_print );
		$script = $head['script'];

		$expires = sprintf(
			/* translators: %s: a date and time such as 28 Oct 2026, 14:35. */
			__( 'This link works until %s.', 'estasoft-news-distribution' ),
			Clock::datetime( (int) $link['expires'] )
		);

		return '<!doctype html>' . "\n"
			. '<html lang="' . esc_attr( $locale ) . '" dir="' . esc_attr( $dir ) . '">' . "\n"
			. '<head>' . "\n"
			. '<meta charset="utf-8">' . "\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<meta name="robots" content="noindex, nofollow">' . "\n"
			. '<meta name="referrer" content="no-referrer">' . "\n"
			. '<title>' . esc_html( $title ) . '</title>' . "\n"
			. $head['style'] . "\n"
			. '</head>' . "\n"
			. '<body class="estasoft-nd-public">' . "\n"
			. '<main class="estasoft-nd-public__page">' . "\n"
			. wp_kses( $body, Output::allowed() ) . "\n"
			. '<p class="estasoft-nd-report__expires estasoft-nd-small">' . esc_html( $expires ) . '</p>' . "\n"
			. '</main>' . "\n"
			. $script . "\n"
			. '</body>' . "\n"
			. '</html>' . "\n";
	}

	/**
	 * The stylesheet link and, for the print view, the script tag, printed through
	 * WordPress's own enqueue functions so the page loads only the plugin's files.
	 *
	 * @param bool $for_print Whether to include the print script.
	 * @return array{style: string, script: string}
	 */
	private static function assets( bool $for_print ): array {
		wp_enqueue_style(
			'estasoft-nd-report',
			ESTASOFT_ND_URL . 'assets/css/screens/report.css',
			array(),
			Assets::file_version( 'assets/css/screens/report.css' )
		);

		ob_start();
		wp_print_styles( 'estasoft-nd-report' );
		$style = (string) ob_get_clean();
		$js    = '';

		if ( $for_print ) {
			wp_enqueue_script(
				'estasoft-nd-report-print',
				ESTASOFT_ND_URL . 'assets/js/screens/report-print.js',
				array(),
				Assets::file_version( 'assets/js/screens/report-print.js' ),
				array( 'in_footer' => false )
			);

			ob_start();
			wp_print_scripts( 'estasoft-nd-report-print' );
			$js = (string) ob_get_clean();
		}

		return array(
			'style'  => trim( $style ),
			'script' => trim( $js ),
		);
	}

	/**
	 * The origin the plugin's own files are served from, for the content security policy.
	 *
	 * @return string Such as https://example.com
	 */
	private static function asset_origin(): string {
		$parts = wp_parse_url( ESTASOFT_ND_URL );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return "'self'";
		}

		return ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' ) . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
