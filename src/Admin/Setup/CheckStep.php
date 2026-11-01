<?php
/**
 * Step 3 of the setup: what the first check found, and what to do next on each platform.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Admin\Setup;

use Estasoft\NewsDistribution\Admin\Ui\AddressRow;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Channels\Group;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Core\Settings\Sections\Languages;
use Estasoft\NewsDistribution\Modules\SetupModule;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the result `FirstCheck` stored when the channels step was saved, so opening the step runs no
 * check and changes nothing. The banner names the platforms that would skip an article with no featured
 * image, from the platform's own rule pack (`Core\Rules`), not from a list kept here.
 */
final class CheckStep {

	/**
	 * The channels that have a sitemap to give to a search engine, with where to give it.
	 *
	 * @var array<string, string>
	 */
	private const CONSOLES = array(
		'google-news' => 'https://search.google.com/search-console',
		'bing'        => 'https://www.bing.com/webmasters',
		'yandex'      => 'https://webmaster.yandex.com',
	);

	private Plugin $plugin;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * The screen.
	 *
	 * @return string Escaped HTML.
	 */
	public function render(): string {
		$checked = Onboarding::state()['checked'];
		$back    = SetupModule::url( 'channels' );

		if ( ! isset( $checked['checked'] ) ) {
			return Frame::page(
				'check',
				__( 'Your first check', 'estasoft-news-distribution' ),
				'',
				Message::text( 'info', __( 'The first check has not run yet. Go back to Channels and choose Continue, and News Distribution checks your latest articles.', 'estasoft-news-distribution' ) )
				. Frame::actions(
					'check',
					array(
						'back'     => $back,
						'continue' => __( 'Go to Overview', 'estasoft-news-distribution' ),
						'go'       => 'finish',
					)
				)
			);
		}

		$chosen = array_map( 'strval', (array) ( $checked['chosen'] ?? array() ) );
		$count  = (int) $checked['checked'];
		$body   = '';

		if ( $count < 1 ) {
			$body .= Message::text( 'info', __( 'You have no published articles yet. News Distribution checks each article when you publish it, and the Overview shows what each channel did.', 'estasoft-news-distribution' ) );
		} else {
			$body .= Html::tag( 'div', array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush estasoft-nd-setup__result' ), $this->stats( $checked ) . $this->banner( $checked, $chosen ) );
		}

		$body .= $this->next_steps( $chosen );

		$content = Frame::form( 'check', '' ) . $body . Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
			esc_html__( 'Microsoft Start, Apple News and the other channels are in Channels. News Distribution prepares each feed and tells you what to do next.', 'estasoft-news-distribution' )
		)
			. Frame::actions(
				'check',
				array(
					'back'     => $back,
					'continue' => __( 'Go to Overview', 'estasoft-news-distribution' ),
					'go'       => 'finish',
				)
			);

		return Frame::page( 'check', __( 'Your first check', 'estasoft-news-distribution' ), $this->lead( $count, $chosen ), $content );
	}

	/**
	 * The line under the heading.
	 *
	 * @param int      $count  How many articles were looked at.
	 * @param string[] $chosen The channels that were on.
	 * @return string
	 */
	private function lead( int $count, array $chosen ): string {
		if ( $count < 1 ) {
			return __( 'There was nothing to look at yet.', 'estasoft-news-distribution' );
		}

		$names = array();

		foreach ( $chosen as $id ) {
			$channel = $this->plugin->registry()->get( $id );

			if ( null !== $channel && 'flipboard' !== $id ) {
				$names[] = $channel->label();
			}
		}

		// Feed readers are always on, so open readers are always part of what was checked.
		$names[] = __( 'the open readers you chose', 'estasoft-news-distribution' );

		return sprintf(
			/* translators: 1: how many articles, 2: a list of channel names such as "Google News, Bing and the open readers you chose". */
			_n( 'We looked at your %1$d latest article for %2$s.', 'We looked at your %1$d latest articles for %2$s.', $count, 'estasoft-news-distribution' ),
			$count,
			wp_sprintf( '%l', $names )
		);
	}

	/**
	 * The two figures.
	 *
	 * @param array<string, mixed> $checked What the first check stored.
	 * @return string
	 */
	private function stats( array $checked ): string {
		$total    = (int) $checked['checked'];
		$ready    = (int) $checked['ready'];
		$no_image = (int) ( $checked['no_image'] ?? 0 );
		$problem  = max( 0, $total - $ready );
		$cards    = Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-result' ),
			Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-setup__figure' ),
				Html::tag( 'span', array( 'class' => 'estasoft-nd-setup__figure-icon estasoft-nd-setup__figure-icon--ok' ), Icon::svg( 'check-circle' ) )
				. esc_html(
					sprintf(
						/* translators: %d: how many articles. */
						_n( '%d is ready', '%d are ready', $ready, 'estasoft-news-distribution' ),
						$ready
					)
				)
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'on every channel you chose', 'estasoft-news-distribution' ) )
		);

		if ( $problem > 0 ) {
			if ( $no_image > 0 && $no_image >= $problem ) {
				$title = sprintf(
					/* translators: %d: how many articles. */
					_n( '%d has no image', '%d have no image', $no_image, 'estasoft-news-distribution' ),
					$no_image
				);
				$text = __( 'They appear without a picture', 'estasoft-news-distribution' );
			} else {
				$title = sprintf(
					/* translators: %d: how many articles. */
					_n( '%d needs a fix', '%d need a fix', $problem, 'estasoft-news-distribution' ),
					$problem
				);
				$text = __( 'The Article check says what to change', 'estasoft-news-distribution' );
			}

			$cards .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-result' ),
				Html::tag(
					'p',
					array( 'class' => 'estasoft-nd-setup__figure' ),
					Html::tag( 'span', array( 'class' => 'estasoft-nd-setup__figure-icon estasoft-nd-setup__figure-icon--warn' ), Icon::svg( 'warning' ) )
					. esc_html( $title )
				)
				. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__stats' ), $cards );
	}

	/**
	 * The warning under the figures, when some article has a finding.
	 *
	 * @param array<string, mixed> $checked What the first check stored.
	 * @param string[]             $chosen  The channels that were on.
	 * @return string
	 */
	private function banner( array $checked, array $chosen ): string {
		$total    = (int) $checked['checked'];
		$problem  = max( 0, $total - (int) $checked['ready'] );
		$no_image = (int) ( $checked['no_image'] ?? 0 );

		if ( $problem < 1 ) {
			return '';
		}

		$review = Button::link(
			__( 'Review in Article check', 'estasoft-news-distribution' ),
			Links::admin( 'estasoft-nd-check', array( 'filter' => 'problems' ) ),
			array( 'size' => 'sm' )
		);

		if ( $no_image < 1 || $no_image < $problem ) {
			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-setup__banner-row' ),
				Message::bar(
					'warning',
					Html::tag(
						'strong',
						array(),
						esc_html(
							sprintf(
								/* translators: %d: how many articles. */
								_n( '%d article needs a fix.', '%d articles need a fix.', $problem, 'estasoft-news-distribution' ),
								$problem
							)
						)
					) . ' ' . esc_html__( 'The Article check says what to change for each one. Nothing is held back until you choose to.', 'estasoft-news-distribution' ),
					$review
				)
			);
		}

		$include = array();
		$skips   = array();
		$later   = array();

		foreach ( $this->plugin->registry()->all() as $channel ) {
			$id    = $channel->id();
			$rule  = $this->plugin->rules()->rule( $id, 'image.missing' );
			$block = array() !== $rule && Status::BLOCK === $this->plugin->rules()->severity( $id, 'image.missing' );

			if ( in_array( $id, $chosen, true ) ) {
				if ( $block ) {
					$skips[] = $channel->label();
				} elseif ( 'flipboard' !== $id ) {
					$include[] = $channel->label();
				}
			} elseif ( $block && Group::PARTNER === $channel->group() ) {
				$later[] = $channel->label();
			}
		}

		$include[] = __( 'the open readers you chose', 'estasoft-news-distribution' );

		$text = Html::tag(
			'strong',
			array(),
			esc_html(
				sprintf(
					/* translators: %d: how many articles. */
					_n( '%d article has no featured image.', '%d articles have no featured image.', $no_image, 'estasoft-news-distribution' ),
					$no_image
				)
			)
		) . ' ' . esc_html(
			sprintf(
				/* translators: %s: a list of channel names such as "Google News, Bing and the open readers you chose". */
				__( '%s will still include them.', 'estasoft-news-distribution' ),
				wp_sprintf( '%l', $include )
			)
		);

		if ( array() !== $skips ) {
			$text .= ' ' . esc_html(
				sprintf(
					/* translators: %s: a list of channel names. */
					__( '%s will skip them.', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $skips )
				)
			);
		}

		if ( array() !== $later ) {
			$text .= ' ' . esc_html(
				sprintf(
					/* translators: %s: a list of channel names such as Microsoft Start. */
					__( 'If you add %s later, News Distribution skips them there.', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $later )
				)
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-setup__banner-row' ), Message::bar( 'warning', $text, $review ) );
	}

	/**
	 * "What to do next": one block for each thing the person still does.
	 *
	 * @param string[] $chosen The channels that are on.
	 * @return string
	 */
	private function next_steps( array $chosen ): string {
		$blocks = '';

		foreach ( array( 'google-news', 'bing', 'yandex' ) as $id ) {
			if ( in_array( $id, $chosen, true ) && null !== $this->plugin->registry()->get( $id ) ) {
				$blocks .= $this->console_block( $id );
			}
		}

		$blocks .= $this->readers_block( in_array( 'flipboard', $chosen, true ) );

		$others = array();

		foreach ( $chosen as $id ) {
			$channel = $this->plugin->registry()->get( $id );

			if ( null !== $channel && ! isset( self::CONSOLES[ $id ] ) && 'flipboard' !== $id ) {
				$others[ $id ] = $channel->label();
			}
		}

		foreach ( $others as $id => $label ) {
			$blocks .= $this->block(
				$label,
				__( 'News Distribution prepares everything here and tells you what to do next.', 'estasoft-news-distribution' ),
				'',
				Html::tag(
					'a',
					array( 'href' => Links::channel( (string) $id ) ),
					esc_html(
						sprintf(
							/* translators: %s: the name of a channel. */
							__( 'Open %s in Channels', 'estasoft-news-distribution' ),
							$label
						)
					)
				)
			);
		}

		return Html::tag(
			'section',
			array( 'aria-labelledby' => 'estasoft-nd-setup-next-title' ),
			Html::tag(
				'h2',
				array(
					'class' => 'estasoft-nd-title estasoft-nd-setup__next-title',
					'id'    => 'estasoft-nd-setup-next-title',
				),
				esc_html__( 'What to do next', 'estasoft-news-distribution' )
			)
			. Html::tag( 'div', array( 'class' => 'estasoft-nd-card estasoft-nd-card--flush' ), $blocks )
		);
	}

	/**
	 * One block of the list.
	 *
	 * @param string $title   The heading (plain).
	 * @param string $text    What to do (plain).
	 * @param string $rows    Trusted HTML of address rows, or empty.
	 * @param string $link    Trusted HTML of a link, or empty.
	 * @return string
	 */
	private function block( string $title, string $text, string $rows, string $link ): string {
		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-setup__block' ),
			Html::tag( 'h3', array( 'class' => 'estasoft-nd-strong' ), esc_html( $title ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html( $text ) )
			. ( '' !== $rows ? Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $rows ) : '' )
			. ( '' !== $link ? Html::tag( 'p', array( 'class' => 'estasoft-nd-setup__block-links' ), $link ) : '' )
		);
	}

	/**
	 * The block for a search engine: the sitemap, and where to give it.
	 *
	 * @param string $id The channel id.
	 * @return string
	 */
	private function console_block( string $id ): string {
		$channel = $this->plugin->registry()->get( $id );

		if ( null === $channel ) {
			return '';
		}

		$external = static function ( string $label, string $url ): string {
			return Html::tag(
				'a',
				array(
					'href'   => $url,
					'target' => '_blank',
					'rel'    => 'noopener',
				),
				esc_html( $label ) . Icon::svg( 'external-link' )
			);
		};

		if ( 'google-news' === $id ) {
			$rows  = '';
			$count = 0;

			foreach ( Languages::site_languages() as $language ) {
				if ( ! $channel->accepts_language( $language ) ) {
					continue;
				}

				++$count;
				$rows .= AddressRow::language(
					$language,
					add_query_arg( 'lang', rawurlencode( $language ), home_url( '/feed/' . $channel->feed_slug() . '/' ) ),
					__( 'Google News sitemap', 'estasoft-news-distribution' )
				);
			}

			return $this->block(
				__( 'Give Google your news sitemap', 'estasoft-news-distribution' ),
				$count > 1
					? __( 'Add each address under Sitemaps in Google Search Console. There is one address for each language.', 'estasoft-news-distribution' )
					: __( 'Add this address under Sitemaps in Google Search Console.', 'estasoft-news-distribution' ),
				$rows,
				$external( __( 'Open Google Search Console', 'estasoft-news-distribution' ), self::CONSOLES[ $id ] )
			);
		}

		$channel_link = Html::tag( 'a', array( 'href' => Links::channel( $id ) ), esc_html__( 'Get the address in Channels', 'estasoft-news-distribution' ) );

		if ( 'bing' === $id ) {
			return $this->block(
				__( 'Give Bing your search sitemap', 'estasoft-news-distribution' ),
				__( 'Bing tries to fetch a sitemap as soon as you submit it in Bing Webmaster Tools. Bing decides what to index.', 'estasoft-news-distribution' ),
				'',
				$external( __( 'Open Bing Webmaster Tools', 'estasoft-news-distribution' ), self::CONSOLES[ $id ] ) . ' ' . $channel_link
			);
		}

		return $this->block(
			__( 'Give Yandex your search sitemap', 'estasoft-news-distribution' ),
			__( 'Yandex usually reads a new sitemap within 2 weeks. Yandex decides what to index.', 'estasoft-news-distribution' ),
			'',
			$external( __( 'Open Yandex Webmaster', 'estasoft-news-distribution' ), self::CONSOLES[ $id ] ) . ' ' . $channel_link
		);
	}

	/**
	 * The block for the open readers: there is nothing to do.
	 *
	 * @param bool $flipboard Whether Flipboard is on.
	 * @return string
	 */
	private function readers_block( bool $flipboard ): string {
		return $this->block(
			$flipboard ? __( 'Flipboard and feed readers', 'estasoft-news-distribution' ) : __( 'Feed readers', 'estasoft-news-distribution' ),
			__( 'Nothing to do. Anyone can follow your feed, and News Distribution advertises it on every page.', 'estasoft-news-distribution' ),
			'',
			''
		);
	}
}
