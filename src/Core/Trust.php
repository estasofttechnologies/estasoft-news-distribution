<?php
/**
 * The publisher trust checklist.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Core;

use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Google's news policies ask news sources for clear dates and bylines, information about
 * the authors, the publication and publisher, the company or network behind the content,
 * and contact information. This reads your own site against that list and says what it
 * found. It reads, and nothing else: it sends nothing, it never judges what a page says,
 * and it never checks that a contact address works.
 *
 * What it reads:
 *
 * - The last 18 published articles of the configured post types: their dates and authors.
 * - The author page text of each author (the user's biography).
 * - Published pages named `about` or `about-us`, and `contact` or `contact-us`, found by slug.
 * - The ownership, corrections and ethics pages the owner chose in the Trust settings.
 *
 * Get it with `Plugin::instance()->make( Trust::class )`.
 */
final class Trust {

	/**
	 * Settings field => the schema.org property it fills.
	 *
	 * @var array<string, string>
	 */
	public const PAGE_PROPERTIES = array(
		'ownership_page'   => 'ownershipFundingInfo',
		'corrections_page' => 'correctionsPolicy',
		'ethics_page'      => 'ethicsPolicy',
	);

	/**
	 * How many of the newest published articles are read.
	 */
	public const SAMPLE = 18;

	private const EARLIEST = 631152000;

	private Plugin $plugin;

	/**
	 * The articles read, once.
	 *
	 * @var \WP_Post[]|null
	 */
	private ?array $articles = null;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The six rows of the table, in the order Google lists them.
	 *
	 * Each row: `id`, `asks` (what Google asks for), `found` (what was found), `status`
	 * (`clean` or `attention`), `action_label`, `action_url` and `action_note` (empty when
	 * there is nothing to do).
	 *
	 * @return array<int, array{id: string, asks: string, found: string, status: string, action_label: string, action_url: string, action_note: string}>
	 */
	public function rows(): array {
		return array(
			$this->dates(),
			$this->bylines(),
			$this->author_bios(),
			$this->page_row(
				'about',
				__( 'Information about the publication and publisher', 'estasoft-news-distribution' ),
				$this->slugs( 'about' ),
				/* translators: %s: the address of the page, such as /about/. */
				__( 'An About page at %s', 'estasoft-news-distribution' ),
				__( 'No About page found at /about/ or /about-us/', 'estasoft-news-distribution' ),
				__( 'Add an About page', 'estasoft-news-distribution' )
			),
			$this->ownership(),
			$this->page_row(
				'contact',
				__( 'Contact information', 'estasoft-news-distribution' ),
				$this->slugs( 'contact' ),
				/* translators: %s: the address of the page, such as /contact/. */
				__( 'A Contact page at %s', 'estasoft-news-distribution' ),
				__( 'No Contact page found at /contact/ or /contact-us/', 'estasoft-news-distribution' ),
				__( 'Add a Contact page', 'estasoft-news-distribution' )
			),
		);
	}

	/**
	 * How many rows are clean.
	 *
	 * @return array{clean: int, attention: int, total: int}
	 */
	public function counts(): array {
		$clean = 0;
		$rows  = $this->rows();

		foreach ( $rows as $row ) {
			if ( Status::CLEAN === $row['status'] ) {
				++$clean;
			}
		}

		return array(
			'clean'     => $clean,
			'attention' => count( $rows ) - $clean,
			'total'     => count( $rows ),
		);
	}

	/**
	 * The published page chosen for a setting, or null.
	 *
	 * @param string $field A key of PAGE_PROPERTIES.
	 * @return \WP_Post|null
	 */
	public function chosen( string $field ): ?\WP_Post {
		$id = (int) $this->plugin->settings()->get( 'trust.' . $field, 0 );

		if ( $id < 1 ) {
			return null;
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status ? $post : null;
	}

	/**
	 * What News Distribution prints in the structured data of the home page.
	 *
	 * Empty until at least one page is chosen.
	 *
	 * @param bool $example Fill in an example address for every page that is not chosen.
	 * @return array<string, mixed>
	 */
	public function markup( bool $example = false ): array {
		$data = array();

		foreach ( self::PAGE_PROPERTIES as $field => $property ) {
			$page = $this->chosen( $field );

			if ( null !== $page ) {
				$data[ $property ] = (string) get_permalink( $page );
			} elseif ( $example ) {
				$data[ $property ] = home_url( '/' . ( 'ownership_page' === $field ? 'about/ownership' : ( 'corrections_page' === $field ? 'corrections' : 'standards' ) ) . '/' );
			}
		}

		if ( array() === $data ) {
			return array();
		}

		return array_merge(
			array(
				'@context' => 'https://schema.org',
				'@type'    => 'NewsMediaOrganization',
				'name'     => $this->plugin->settings()->publication_name(),
				'url'      => home_url( '/' ),
			),
			$data
		);
	}

	/**
	 * The newest 100 published pages, newest first, then the pages whose address is the kind people use for a policy page, and any page already chosen.
	 *
	 * @return array<string, string> Page ID => title.
	 */
	public function page_options(): array {
		$options = array( '0' => __( 'Choose a page', 'estasoft-news-distribution' ) );
		$ids     = get_posts(
			array(
				'post_type'           => 'page',
				'post_status'         => 'publish',
				'posts_per_page'      => 100,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'lang'                => '',
				'suppress_filters'    => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The pages are looked for in every language; another plugin's filters must not narrow it.
			)
		);

		// The pages people usually choose are old, so they may be beyond the newest hundred.
		$likely = get_posts(
			array(
				'post_type'           => 'page',
				'post_status'         => 'publish',
				'posts_per_page'      => 50,
				'post_name__in'       => array( 'about', 'about-us', 'ownership', 'funding', 'corrections', 'corrections-policy', 'ethics', 'standards', 'editorial-standards', 'editorial-policy' ),
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'lang'                => '',
				'suppress_filters'    => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The pages are looked for in every language; another plugin's filters must not narrow it.
			)
		);

		foreach ( $likely as $id ) {
			if ( ! in_array( (int) $id, $ids, true ) ) {
				$ids[] = (int) $id;
			}
		}

		foreach ( array_keys( self::PAGE_PROPERTIES ) as $field ) {
			$chosen = (int) $this->plugin->settings()->get( 'trust.' . $field, 0 );

			if ( $chosen > 0 && ! in_array( $chosen, $ids, true ) ) {
				$ids[] = $chosen;
			}
		}

		foreach ( $ids as $id ) {
			$title = trim( wp_strip_all_tags( get_the_title( (int) $id ) ) );

			$options[ (string) $id ] = '' === $title ? __( '(no title)', 'estasoft-news-distribution' ) : $title;
		}

		return $options;
	}

	/**
	 * Row: clear dates.
	 *
	 * @return array<string, string>
	 */
	private function dates(): array {
		$articles = $this->articles();
		$total    = count( $articles );
		$dated    = 0;

		foreach ( $articles as $article ) {
			if ( Clock::from_gmt( (string) $article->post_date_gmt ) >= self::EARLIEST ) {
				++$dated;
			}
		}

		$found = 0 === $total
			? __( 'No published articles to read yet.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: 1: number of articles with a publish date, 2: number of articles read. */
				_n( 'A publish date on %1$s of the last %2$s article', 'A publish date on %1$s of the last %2$s articles', $total, 'estasoft-news-distribution' ),
				number_format_i18n( $dated ),
				number_format_i18n( $total )
			);

		return $this->row(
			'dates',
			__( 'Clear dates', 'estasoft-news-distribution' ),
			$found,
			$total > 0 && $dated === $total,
			__( 'Open Article check', 'estasoft-news-distribution' ),
			add_query_arg( 'page', 'estasoft-nd-check', admin_url( 'admin.php' ) )
		);
	}

	/**
	 * Row: bylines.
	 *
	 * @return array<string, string>
	 */
	private function bylines(): array {
		$survey   = $this->plugin->make( Bylines::class )->survey( self::SAMPLE );
		$total    = $survey['checked'];
		$real     = $survey['real'];
		$bare     = $survey['bare'];
		$replaced = $survey['replaced'];
		$names    = $survey['names'];

		if ( 0 === $total ) {
			$found = __( 'No published articles to read yet.', 'estasoft-news-distribution' );
		} else {
			$found = sprintf(
				/* translators: 1: number of articles with a real author name, 2: number of articles read. */
				_n( 'A real name on %1$s of the last %2$s article.', 'A real name on %1$s of the last %2$s articles.', $total, 'estasoft-news-distribution' ),
				number_format_i18n( $real ),
				number_format_i18n( $total )
			);

			if ( $replaced > 0 ) {
				$found .= ' ' . sprintf(
					/* translators: 1: number of articles, 2: the names, such as admin. */
					_n( '%1$s is by %2$s.', '%1$s are by %2$s.', $replaced, 'estasoft-news-distribution' ),
					number_format_i18n( $replaced ),
					implode( ', ', $names )
				);
			}

			if ( $bare > 0 ) {
				$found .= ' ' . sprintf(
					/* translators: %s: number of articles. */
					_n( '%s has no author.', '%s have no author.', $bare, 'estasoft-news-distribution' ),
					number_format_i18n( $bare )
				);
			}
		}

		return $this->row(
			'bylines',
			__( 'Bylines', 'estasoft-news-distribution' ),
			$found,
			$total > 0 && $real === $total,
			__( 'Open Authors settings', 'estasoft-news-distribution' ),
			add_query_arg(
				array(
					'page'    => 'estasoft-nd-settings',
					'section' => 'authors',
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Row: information about the authors.
	 *
	 * @return array<string, string>
	 */
	private function author_bios(): array {
		$bylines = $this->plugin->make( Bylines::class );
		$ids     = array();

		foreach ( $this->articles() as $post ) {
			$ids[ (int) $post->post_author ] = true;
		}

		$with    = 0;
		$without = 0;

		foreach ( array_keys( $ids ) as $user_id ) {
			if ( $bylines->is_not_a_person( (string) get_the_author_meta( 'display_name', $user_id ) ) ) {
				continue;
			}

			if ( '' !== trim( (string) get_the_author_meta( 'description', $user_id ) ) ) {
				++$with;
			} else {
				++$without;
			}
		}

		$total = $with + $without;
		$found = 0 === $total
			? __( 'No named authors to read yet.', 'estasoft-news-distribution' )
			: sprintf(
				/* translators: 1: number of authors with a biography, 2: number of authors. */
				_n( '%1$s of %2$s author has a bio on their author page', '%1$s of %2$s authors have a bio on their author page', $total, 'estasoft-news-distribution' ),
				number_format_i18n( $with ),
				number_format_i18n( $total )
			);

		$row = $this->row(
			'author_bios',
			__( 'Information about the authors', 'estasoft-news-distribution' ),
			$found,
			$total > 0 && 0 === $without,
			__( 'Edit bios in Users', 'estasoft-news-distribution' ),
			admin_url( 'users.php' )
		);

		if ( $without > 0 ) {
			$row['action_note'] = sprintf(
				/* translators: %s: number of authors without a bio. */
				_n( '%s author has none.', '%s authors have none.', $without, 'estasoft-news-distribution' ),
				number_format_i18n( $without )
			);
		}

		return $row;
	}

	/**
	 * Row: the company or network behind the content, from the chosen page.
	 *
	 * @return array<string, string>
	 */
	private function ownership(): array {
		$page = $this->chosen( 'ownership_page' );

		return $this->row(
			'ownership',
			__( 'Information about the company or network behind the content', 'estasoft-news-distribution' ),
			null === $page
				? __( 'No ownership or funding page chosen', 'estasoft-news-distribution' )
				: sprintf(
					/* translators: %s: the address of the page, such as /about/ownership/. */
					__( 'The ownership and funding page is at %s', 'estasoft-news-distribution' ),
					$this->path( $page )
				),
			null !== $page,
			__( 'Choose a page below', 'estasoft-news-distribution' ),
			'#' . Field::id( 'trust', 'ownership_page' )
		);
	}

	/**
	 * A row for a page found by its slug.
	 *
	 * @param string   $id         Row id.
	 * @param string   $asks       What Google asks for.
	 * @param string[] $slugs      Slugs to look for.
	 * @param string   $found_text Sentence with %s for the address, when found.
	 * @param string   $none_text  Sentence when nothing is found.
	 * @param string   $action     Label of the link to add the page.
	 * @return array<string, string>
	 */
	private function page_row( string $id, string $asks, array $slugs, string $found_text, string $none_text, string $action ): array {
		$page = $this->find_page( $slugs );

		return $this->row(
			$id,
			$asks,
			null === $page ? $none_text : sprintf( $found_text, $this->path( $page ) ),
			null !== $page,
			$action,
			add_query_arg( 'post_type', 'page', admin_url( 'post-new.php' ) )
		);
	}

	/**
	 * One row. The action is shown only when the row needs attention.
	 *
	 * @param string $id     Row id.
	 * @param string $asks   What Google asks for.
	 * @param string $found  What was found.
	 * @param bool   $clean  Whether the row is clean.
	 * @param string $label  Label of the action link.
	 * @param string $url    Address of the action link.
	 * @return array<string, string>
	 */
	private function row( string $id, string $asks, string $found, bool $clean, string $label, string $url ): array {
		return array(
			'id'           => $id,
			'asks'         => $asks,
			'found'        => $found,
			'status'       => $clean ? Status::CLEAN : Status::ATTENTION,
			'action_label' => $clean ? '' : $label,
			'action_url'   => $clean ? '' : $url,
			'action_note'  => '',
		);
	}

	/**
	 * The slugs to look for a kind of page: about or contact.
	 *
	 * @param string $kind `about` or `contact`.
	 * @return string[]
	 */
	private function slugs( string $kind ): array {
		$slugs = array( $kind, $kind . '-us' );

		/**
		 * The slugs the publisher trust check looks for a page under.
		 *
		 * Internal. Return a list of slugs, such as `about`, `about-us`, `qui-sommes-nous`.
		 *
		 * @param string[] $slugs Slugs.
		 * @param string   $kind  `about` or `contact`.
		 */
		$filtered = apply_filters( 'estasoft_nd_internal_trust_slugs', $slugs, $kind );

		return is_array( $filtered ) ? array_values( array_filter( array_map( 'sanitize_title', $filtered ) ) ) : $slugs;
	}

	/**
	 * The first published page found under one of the slugs.
	 *
	 * @param string[] $slugs Slugs.
	 * @return \WP_Post|null
	 */
	private function find_page( array $slugs ): ?\WP_Post {
		foreach ( $slugs as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );

			if ( $page instanceof \WP_Post && 'publish' === $page->post_status ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * A page's address without the site address, such as /about/.
	 *
	 * @param \WP_Post $page The page.
	 * @return string
	 */
	private function path( \WP_Post $page ): string {
		$path = (string) wp_parse_url( (string) get_permalink( $page ), PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		$more = (string) wp_parse_url( (string) get_permalink( $page ), PHP_URL_QUERY );

		return '' === $more ? $path : $path . '?' . $more;
	}

	/**
	 * The newest published articles, read once.
	 *
	 * @return \WP_Post[]
	 */
	private function articles(): array {
		if ( null === $this->articles ) {
			$posts          = get_posts(
				array(
					'post_type'           => $this->plugin->settings()->post_types(),
					'post_status'         => 'publish',
					'posts_per_page'      => self::SAMPLE,
					'orderby'             => 'date',
					'order'               => 'DESC',
					'no_found_rows'       => true,
					'ignore_sticky_posts' => true,
					'lang'                => '',
					'suppress_filters'    => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- The sample covers every language; another plugin's filters must not narrow it.
				)
			);
			$this->articles = array_values( array_filter( $posts, static fn( $post ): bool => $post instanceof \WP_Post ) );
		}

		return $this->articles;
	}
}
