<?php
/**
 * Translations: which of your languages each article exists in.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Reports;

use Estasoft\NewsDistribution\Admin\Reports\Parts\Scripts;
use Estasoft\NewsDistribution\Admin\Reports\Parts\View;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\CommandBar;
use Estasoft\NewsDistribution\Admin\Ui\EmptyState;
use Estasoft\NewsDistribution\Admin\Ui\Grid;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Core\Clock;
use Estasoft\NewsDistribution\Core\Translations;
use Estasoft\NewsDistribution\Support\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Built on `Core\Translations::coverage()`, which finds the versions of each recent
 * article in the translation plugin. This report adds what each version is (published,
 * a draft, or written in the wrong letters) and what to do about it. It reads the
 * database only and never fetches a page.
 */
final class TranslationsReport extends AbstractReport {

	/**
	 * How many rows the screen shows before "Show all".
	 */
	private const SHOWN = 6;

	public function name(): string {
		return 'translations';
	}

	public function title(): string {
		return __( 'Translations', 'estasoft-news-distribution' );
	}

	public function description(): string {
		return __( 'Which of your languages each article exists in.', 'estasoft-news-distribution' );
	}

	public function group(): string {
		return 'content';
	}

	public function order(): int {
		return 10;
	}

	/**
	 * Only a site with more than one language has anything to compare.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return count( $this->plugin->languages()->languages() ) > 1;
	}

	/**
	 * The figures, for the screen and for `GET /reports/translations`.
	 *
	 * @return array<string, mixed>
	 */
	public function data(): array {
		$model = $this->model();
		$rows  = array();

		foreach ( $model['rows'] as $row ) {
			$versions = array();

			foreach ( $row['versions'] as $code => $version ) {
				$versions[ $code ] = array(
					'article' => $version['id'],
					'status'  => $version['status'],
				);
			}

			$rows[] = array(
				'group'     => $row['group'],
				'title'     => $row['title'],
				'published' => $row['published_ts'] > 0 ? Clock::iso( $row['published_ts'] ) : null,
				'versions'  => $versions,
				'complete'  => $row['complete'],
				'needs'     => $row['needs'],
			);
		}

		return array(
			'plugin'    => $model['plugin'],
			'days'      => $model['days'],
			'languages' => $model['languages'],
			'articles'  => count( $model['rows'] ),
			'complete'  => count(
				array_filter(
					$model['rows'],
					static function ( array $row ): bool {
						return $row['complete'];
					}
				)
			),
			'counts'    => $model['counts'],
			'rows'      => $rows,
		);
	}

	/**
	 * Reads the coverage and gives each version a status.
	 *
	 * Version statuses: `published`, `draft` (any version that is not public yet),
	 * `missing`, and `wrong_script` (a published Kurdish version written in Arabic letters).
	 *
	 * @return array<string, mixed>
	 */
	private function model(): array {
		$coverage = $this->plugin->make( Translations::class )->coverage( 30, 50 );
		$codes    = array();

		foreach ( $coverage['languages'] as $language ) {
			if ( empty( $language['off'] ) ) {
				$codes[] = (string) $language['code'];
			}
		}

		$rows   = array();
		$counts = array(
			'all'     => 0,
			'missing' => 0,
			'draft'   => 0,
			'fix'     => 0,
		);

		foreach ( $coverage['rows'] as $row ) {
			$versions = array();
			$needs    = array();

			foreach ( $codes as $code ) {
				$id = (int) ( $row['posts'][ $code ] ?? 0 );

				$versions[ $code ] = array(
					'id'     => $id,
					'status' => $this->version_status( $id, $code ),
				);

				if ( 'published' !== $versions[ $code ]['status'] ) {
					$needs[ $versions[ $code ]['status'] ][] = $code;
				}
			}

			$complete = array() === $needs;
			$rows[]   = array(
				'group'        => (int) $row['group'],
				'title'        => (string) $row['title'],
				'published_ts' => (int) $row['published_ts'],
				'versions'     => $versions,
				'complete'     => $complete,
				'needs'        => $needs,
			);

			++$counts['all'];
			$counts['missing'] += isset( $needs['missing'] ) ? 1 : 0;
			$counts['draft']   += isset( $needs['draft'] ) ? 1 : 0;
			$counts['fix']     += isset( $needs['wrong_script'] ) ? 1 : 0;
		}

		$languages = array();

		foreach ( $codes as $code ) {
			$languages[] = array(
				'code'    => $code,
				'name'    => View::language( $code ),
				'default' => $code === $this->plugin->languages()->default_language(),
			);
		}

		return array(
			'plugin'    => $this->plugin->languages()->name(),
			'days'      => (int) $coverage['days'],
			'languages' => $languages,
			'rows'      => $rows,
			'counts'    => $counts,
		);
	}

	/**
	 * What one version of an article is.
	 *
	 * @param int    $post_id  Article ID, or 0 when the language has no version.
	 * @param string $language Language code.
	 * @return string published, draft, missing or wrong_script.
	 */
	private function version_status( int $post_id, string $language ): string {
		if ( $post_id < 1 ) {
			return 'missing';
		}

		if ( 'publish' !== get_post_status( $post_id ) ) {
			return 'draft';
		}

		if ( Scripts::kurdish_in_arabic_letters( $language, (string) get_the_title( $post_id ), (string) get_post_field( 'post_content', $post_id ) ) ) {
			return 'wrong_script';
		}

		return 'published';
	}

	/**
	 * The filter in the address: all, missing, draft or fix.
	 *
	 * @return string
	 */
	private function filter(): string {
		$filter = Query::key( 'filter', 'all' );

		return in_array( $filter, array( 'missing', 'draft', 'fix' ), true ) ? $filter : 'all';
	}

	/**
	 * Whether a row belongs to a filter.
	 *
	 * @param array<string, mixed> $row    A row of the model.
	 * @param string               $filter all, missing, draft or fix.
	 * @return bool
	 */
	private function matches( array $row, string $filter ): bool {
		switch ( $filter ) {
			case 'missing':
				return isset( $row['needs']['missing'] );
			case 'draft':
				return isset( $row['needs']['draft'] );
			case 'fix':
				return isset( $row['needs']['wrong_script'] );
			default:
				return true;
		}
	}

	/**
	 * The address that creates a translation, or an empty string when the translation plugin gives none.
	 *
	 * @param int    $source_id Article to translate from.
	 * @param string $language  Language to create.
	 * @return string
	 */
	private function new_translation_url( int $source_id, string $language ): string {
		/**
		 * Gives the address that creates a translation (internal). Return an empty string to use the default.
		 *
		 * @param string $url       Address, or empty.
		 * @param int    $source_id Article to translate from.
		 * @param string $language  Language to create.
		 */
		$given = apply_filters( 'estasoft_nd_internal_new_translation_url', '', $source_id, $language );

		if ( is_string( $given ) && '' !== $given ) {
			return $given;
		}

		if ( function_exists( 'PLL' ) ) {
			$polylang = PLL(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Polylang's own function.

			if ( is_object( $polylang ) && isset( $polylang->links ) && is_object( $polylang->links ) && method_exists( $polylang->links, 'get_new_post_translation_link' ) ) {
				$url = $polylang->links->get_new_post_translation_link( $source_id, $language );

				return is_string( $url ) ? $url : '';
			}
		}

		return '';
	}

	/**
	 * The button at the end of a row.
	 *
	 * @param array<string, mixed> $row A row of the model.
	 * @return string
	 */
	private function action( array $row ): string {
		$needs = $row['needs'];

		if ( array() === $needs ) {
			return Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Complete', 'estasoft-news-distribution' ) );
		}

		if ( isset( $needs['wrong_script'] ) ) {
			$id = (int) $row['versions'][ $needs['wrong_script'][0] ]['id'];

			return $this->link( __( 'Review the Kurdish text', 'estasoft-news-distribution' ), (string) get_edit_post_link( $id, 'raw' ) );
		}

		if ( isset( $needs['draft'] ) ) {
			$code = $needs['draft'][0];
			$id   = (int) $row['versions'][ $code ]['id'];

			return $this->link(
				sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( 'Open %s draft', 'estasoft-news-distribution' ),
					View::language_short( $code )
				),
				(string) get_edit_post_link( $id, 'raw' )
			);
		}

		$missing = $needs['missing'];
		$source  = 0;

		foreach ( $row['versions'] as $version ) {
			if ( $version['id'] > 0 ) {
				$source = (int) $version['id'];
				break;
			}
		}

		$label = 1 === count( $missing )
			? sprintf(
				/* translators: %s: a language name such as Kurdish. */
				__( 'Create %s translation', 'estasoft-news-distribution' ),
				View::language_short( $missing[0] )
			)
			: __( 'Create translation', 'estasoft-news-distribution' );

		$url = $this->new_translation_url( $source, $missing[0] );

		// Without an address from the translation plugin, the article's own edit screen is where its translation tools are.
		return '' !== $url
			? $this->link( $label, $url )
			: $this->link( __( 'Open the article', 'estasoft-news-distribution' ), (string) get_edit_post_link( $source, 'raw' ) );
	}

	/**
	 * A text link that reads as the row's action.
	 *
	 * @param string $label Link text.
	 * @param string $url   Address.
	 * @return string
	 */
	private function link( string $label, string $url ): string {
		return '' === $url ? '' : Html::tag(
			'a',
			array(
				'class' => 'estasoft-nd-strong',
				'href'  => $url,
			),
			esc_html( $label )
		);
	}

	/**
	 * The cell of one language in a row.
	 *
	 * @param array<string, mixed> $version A version of the model.
	 * @return string
	 */
	private function cell( array $version ): string {
		switch ( $version['status'] ) {
			case 'published':
				return View::mark( 'success', __( 'Published', 'estasoft-news-distribution' ) );
			case 'draft':
				return View::mark( 'warning', __( 'Draft', 'estasoft-news-distribution' ) );
			case 'wrong_script':
				return Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
					View::mark( 'warning', __( 'Wrong script', 'estasoft-news-distribution' ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Arabic letters. Kurmanji uses Latin', 'estasoft-news-distribution' ) )
				);
			default:
				return View::mark( 'warning', __( 'Missing', 'estasoft-news-distribution' ) );
		}
	}

	/**
	 * The lead message: an article that may be in the wrong language, or what is missing.
	 *
	 * @param array<string, mixed> $model The model.
	 * @return string
	 */
	private function lead( array $model ): string {
		$fixes = array_values(
			array_filter(
				$model['rows'],
				static function ( array $row ): bool {
					return isset( $row['needs']['wrong_script'] );
				}
			)
		);

		if ( array() !== $fixes ) {
			$first = $fixes[0];
			$id    = (int) $first['versions'][ $first['needs']['wrong_script'][0] ]['id'];

			return View::lead(
				'warning',
				sprintf(
					/* translators: %s: number of articles. */
					_n( '%s article may be in the wrong language.', '%s articles may be in the wrong language.', count( $fixes ), 'estasoft-news-distribution' ),
					number_format_i18n( count( $fixes ) )
				),
				count( $fixes ) > 1
					? sprintf(
						/* translators: 1: article title, 2: number of other articles. */
						_n( 'The Kurdish version of %1$s and %2$s other is written in Arabic letters. Kurmanji uses Latin letters. If it is Sorani, News Distribution does not support it.', 'The Kurdish version of %1$s and %2$s others is written in Arabic letters. Kurmanji uses Latin letters. If it is Sorani, News Distribution does not support it.', count( $fixes ) - 1, 'estasoft-news-distribution' ),
						$first['title'],
						number_format_i18n( count( $fixes ) - 1 )
					)
					: sprintf(
						/* translators: %s: article title. */
						__( 'The Kurdish version of %s is written in Arabic letters. Kurmanji uses Latin letters. If it is Sorani, News Distribution does not support it.', 'estasoft-news-distribution' ),
						$first['title']
					),
				Button::link( __( 'Review the Kurdish text', 'estasoft-news-distribution' ), (string) get_edit_post_link( $id, 'raw' ), array( 'size' => 'sm' ) )
			);
		}

		if ( $model['counts']['missing'] > 0 ) {
			return View::lead(
				'info',
				sprintf(
					/* translators: %s: number of articles. */
					_n( '%s article is missing a translation.', '%s articles are missing a translation.', $model['counts']['missing'], 'estasoft-news-distribution' ),
					number_format_i18n( $model['counts']['missing'] )
				)
			);
		}

		if ( $model['counts']['all'] > 0 ) {
			return View::lead( 'success', __( 'Every recent article exists in every language.', 'estasoft-news-distribution' ) );
		}

		return '';
	}

	public function render(): string {
		$model = $this->model();
		$now   = Clock::now();

		if ( count( $model['languages'] ) < 2 ) {
			return EmptyState::render(
				__( 'Your site has one language', 'estasoft-news-distribution' ),
				__( 'There is nothing to compare. Add a language in your translation plugin and turn it on in Settings, Languages.', 'estasoft-news-distribution' )
			);
		}

		$filter = $this->filter();
		$all    = Query::is( 'all', '1' );
		$rows   = array_values(
			array_filter(
				$model['rows'],
				function ( array $row ) use ( $filter ): bool {
					return $this->matches( $row, $filter );
				}
			)
		);
		$shown  = $all ? $rows : array_slice( $rows, 0, self::SHOWN );
		$cells  = array();

		foreach ( $shown as $row ) {
			$present = array_keys(
				array_filter(
					$row['versions'],
					static function ( array $version ): bool {
						return $version['id'] > 0;
					}
				)
			);
			$detail  = sprintf(
				/* translators: %s: a day and time such as "today, 14:23". */
				__( 'Published %s', 'estasoft-news-distribution' ),
				View::day( (int) $row['published_ts'], $now )
			);

			if ( 1 === count( $present ) ) {
				$detail .= sprintf(
					/* translators: %s: a language name such as Arabic. */
					__( '. %s only', 'estasoft-news-distribution' ),
					View::language_short( (string) $present[0] )
				);
			}

			$line = array(
				Html::tag(
					'div',
					array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
					Html::tag( 'span', array_merge( array( 'class' => 'estasoft-nd-strong' ), View::text_attributes( (string) $row['title'] ) ), esc_html( (string) $row['title'] ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $detail ) )
				),
			);

			foreach ( $row['versions'] as $version ) {
				$line[] = $this->cell( $version );
			}

			$line[]  = $this->action( $row );
			$cells[] = $line;
		}

		$headers = array( __( 'Article', 'estasoft-news-distribution' ) );

		foreach ( $model['languages'] as $language ) {
			$headers[] = (string) $language['name'];
		}

		$headers[] = __( 'Action', 'estasoft-news-distribution' );

		$hidden   = count( $rows ) - count( $shown );
		$complete = count(
			array_filter(
				array_slice( $rows, count( $shown ) ),
				static function ( array $row ): bool {
					return $row['complete'];
				}
			)
		);
		$text     = sprintf(
			/* translators: 1: rows shown, 2: rows in all. */
			_n( 'Showing %1$s of %2$s article, newest first.', 'Showing %1$s of %2$s articles, newest first.', count( $rows ), 'estasoft-news-distribution' ),
			number_format_i18n( count( $shown ) ),
			number_format_i18n( count( $rows ) )
		);

		if ( $hidden > 0 && $complete === $hidden ) {
			$text .= ' ' . sprintf(
				/* translators: %s: number of articles. */
				_n( 'The other %s is complete.', 'The other %s are complete.', $hidden, 'estasoft-news-distribution' ),
				number_format_i18n( $hidden )
			);
		}

		$more = $hidden > 0
			? Button::link(
				sprintf(
					/* translators: %s: number of articles. */
					__( 'Show all %s', 'estasoft-news-distribution' ),
					number_format_i18n( count( $rows ) )
				),
				$this->url(
					array(
						'filter' => 'all' === $filter ? null : $filter,
						'all'    => '1',
					)
				),
				array( 'size' => 'sm' )
			)
			: '';

		$segments = CommandBar::segments(
			array(
				'all'     => array(
					/* translators: %s: number of articles. */
					'label' => sprintf( __( 'All (%s)', 'estasoft-news-distribution' ), number_format_i18n( $model['counts']['all'] ) ),
					'url'   => $this->url(),
				),
				'missing' => array(
					/* translators: %s: number of articles. */
					'label' => sprintf( __( 'Missing a translation (%s)', 'estasoft-news-distribution' ), number_format_i18n( $model['counts']['missing'] ) ),
					'url'   => $this->url( array( 'filter' => 'missing' ) ),
				),
				'draft'   => array(
					/* translators: %s: number of articles. */
					'label' => sprintf( __( 'Draft (%s)', 'estasoft-news-distribution' ), number_format_i18n( $model['counts']['draft'] ) ),
					'url'   => $this->url( array( 'filter' => 'draft' ) ),
				),
				'fix'     => array(
					/* translators: %s: number of articles. */
					'label' => sprintf( __( 'Needs a fix (%s)', 'estasoft-news-distribution' ), number_format_i18n( $model['counts']['fix'] ) ),
					'url'   => $this->url( array( 'filter' => 'fix' ) ),
				),
			),
			$filter,
			__( 'Show articles', 'estasoft-news-distribution' )
		);

		$note = sprintf(
			/* translators: 1: number of languages, 2: the translation plugin's name such as Polylang. */
			_n( 'Only the %1$s language turned on in Settings is shown. Links come from %2$s.', 'Only the %1$s languages turned on in Settings are shown. Links come from %2$s.', count( $model['languages'] ), 'estasoft-news-distribution' ),
			number_format_i18n( count( $model['languages'] ) ),
			$this->plugin_name( (string) $model['plugin'] )
		);

		$table = array() === $cells
			? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-card-pad' ), esc_html__( 'No articles match.', 'estasoft-news-distribution' ) )
			: Grid::render( 'estasoft-nd-cols-translations-' . min( 6, max( 2, count( $model['languages'] ) ) ), $headers, $cells, __( 'Translations by article', 'estasoft-news-distribution' ) );

		return $this->lead( $model )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card estasoft-nd-card--padded' ),
				$segments . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $note ) )
			)
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ),
				$table . ( array() === $cells ? '' : Html::tag( 'div', array( 'class' => 'estasoft-nd-table-footer' ), View::footer( $text, $more ) ) )
			);
	}

	/**
	 * The translation plugin's name for a sentence.
	 *
	 * @param string $name The resolver's name: polylang, wpml or single.
	 * @return string
	 */
	private function plugin_name( string $name ): string {
		switch ( $name ) {
			case 'polylang':
				return 'Polylang';
			case 'wpml':
				return 'WPML';
			default:
				return __( 'your translation plugin', 'estasoft-news-distribution' );
		}
	}
}
