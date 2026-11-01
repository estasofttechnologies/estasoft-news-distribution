<?php
/**
 * What Settings, AI assistant prints that is not a plain field.
 *
 * @package Estasoft\NewsDistribution
 */

namespace Estasoft\NewsDistribution\Ai\View;

use Estasoft\NewsDistribution\Admin\Operations\Forms;
use Estasoft\NewsDistribution\Admin\Ui\Button;
use Estasoft\NewsDistribution\Admin\Ui\Card;
use Estasoft\NewsDistribution\Admin\Ui\Disclosure;
use Estasoft\NewsDistribution\Admin\Ui\Field;
use Estasoft\NewsDistribution\Admin\Ui\FieldRows;
use Estasoft\NewsDistribution\Admin\Ui\Html;
use Estasoft\NewsDistribution\Admin\Ui\Icon;
use Estasoft\NewsDistribution\Admin\Ui\Message;
use Estasoft\NewsDistribution\Admin\Ui\StatusView;
use Estasoft\NewsDistribution\Admin\Ui\Switcher;
use Estasoft\NewsDistribution\Admin\Ui\Tag;
use Estasoft\NewsDistribution\Ai\Availability;
use Estasoft\NewsDistribution\Ai\Client;
use Estasoft\NewsDistribution\Ai\Config;
use Estasoft\NewsDistribution\Ai\Consent;
use Estasoft\NewsDistribution\Ai\Policy;
use Estasoft\NewsDistribution\Ai\Reviewers;
use Estasoft\NewsDistribution\Ai\Usage;
use Estasoft\NewsDistribution\Core\Language\Catalog;
use Estasoft\NewsDistribution\Core\Operations\Flash;
use Estasoft\NewsDistribution\Core\Operations\Links;
use Estasoft\NewsDistribution\Core\Status;
use Estasoft\NewsDistribution\Network\AiPolicy;
use Estasoft\NewsDistribution\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * The cards of the settings page and the rows that are computed rather than a field. Every method
 * returns escaped HTML. Nothing here asks a provider anything: the page only reads what WordPress
 * and the plugin already know, so opening it sends nothing anywhere.
 */
final class SettingsView {

	public const TEST_ACTION = 'estasoft_nd_ai_test';

	private Plugin $plugin;

	private Availability $availability;

	private Config $config;

	/**
	 * @param Plugin $plugin The running plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin       = $plugin;
		$this->availability = $plugin->make( Availability::class );
		$this->config       = $plugin->make( Config::class );
	}

	/**
	 * The cards, rows and notes of the page.
	 *
	 * @param array<string, array<string, mixed>> $fields The section's fields, to set the row labels.
	 * @return array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]}
	 */
	public function layout( array $fields ): array {
		unset( $fields );

		$features = array(
			'fix'       => array(
				__( 'Fix suggestions', 'estasoft-news-distribution' ),
				__( 'Titles and alt text that follow each platform’s rules.', 'estasoft-news-distribution' ),
			),
			'ask'       => array(
				__( 'Ask the assistant', 'estasoft-news-distribution' ),
				__( 'Answers about why an article is or is not on a channel, from the rules and the checks.', 'estasoft-news-distribution' ),
			),
			'translate' => array(
				__( 'Translation drafts', 'estasoft-news-distribution' ),
				__( 'Drafts in your other languages. Never published without a signed-off reviewer’s approval.', 'estasoft-news-distribution' ),
			),
			'local'     => array(
				__( 'Local news and repeats', 'estasoft-news-distribution' ),
				__( 'Decides which articles are local, for the News Break rule, and spots near-copies of an article.', 'estasoft-news-distribution' ),
			),
			'image'     => array(
				__( 'Image helper', 'estasoft-news-distribution' ),
				__( 'Spots a logo used as the lead image, and suggests a focal point and alt text.', 'estasoft-news-distribution' ),
			),
			'summary'   => array(
				__( 'Summaries and search descriptions', 'estasoft-news-distribution' ),
				__( 'Suggests a short summary for feeds and apps, and a description for search results. You choose which one to use, and where.', 'estasoft-news-distribution' ),
			),
			'caption'   => array(
				__( 'Image captions', 'estasoft-news-distribution' ),
				__( 'Suggests a caption for the featured image from the article’s words. The AI does not see the photo, and the credit is never changed.', 'estasoft-news-distribution' ),
			),
			'digest'    => array(
				__( 'Weekly summary in plain language', 'estasoft-news-distribution' ),
				__( 'Written in each person’s own language.', 'estasoft-news-distribution' ),
			),
		);

		$feature_rows = array();

		foreach ( $features as $toggle => $words ) {
			$feature_rows[] = array(
				'label' => $words[0],
				'html'  => function ( array $values ) use ( $toggle, $words ): string {
					return $this->feature_switch( $toggle, $words[1], $values );
				},
			);
		}

		return array(
			'cards'  => array(
				array(
					'id'          => 'connection',
					'title'       => __( 'Connection', 'estasoft-news-distribution' ),
					'description' => __( 'Bring your own AI. News Distribution uses the providers you connect in WordPress. You pay your provider. News Distribution sells no credits and never sees your keys.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Status', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'status_row' ),
						),
						array(
							'label' => __( 'Providers', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'providers_row' ),
						),
						array( 'field' => 'models' ),
						array(
							'label' => __( 'Test', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'test_row' ),
						),
					),
				),
				array(
					'id'          => 'features',
					'title'       => __( 'What the assistant may do', 'estasoft-news-distribution' ),
					'description' => __( 'Each feature is off until you turn it on. Nothing is applied for you: the AI suggests, the rules check, and a person decides.', 'estasoft-news-distribution' ),
					'rows'        => $feature_rows,
				),
				array(
					'id'          => 'sent',
					'title'       => __( 'What is sent to your provider', 'estasoft-news-distribution' ),
					'description' => __( 'Only what the feature needs, and never anything you have not chosen.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'label' => __( 'Articles', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'articles_row' ),
						),
						array(
							'label' => __( 'People', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'people_row' ),
						),
						array(
							'label' => __( 'Never sent', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'never_row' ),
						),
					),
				),
				array(
					'id'          => 'limits',
					'title'       => __( 'Limits', 'estasoft-news-distribution' ),
					'description' => __( 'Your provider may set its own limits. News Distribution keeps these as well, so it can pause the assistant before a limit is reached.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array( 'field' => 'daily_limit' ),
						array( 'field' => 'monthly_tokens' ),
						array(
							'label' => __( 'Your price, optional', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'price_row' ),
						),
						array( 'field' => 'on_limit' ),
						array(
							'label' => __( 'Used so far', 'estasoft-news-distribution' ),
							'html'  => array( $this, 'used_row' ),
						),
					),
				),
				array(
					'id'          => 'reviewers',
					'title'       => __( 'Who can approve a translation', 'estasoft-news-distribution' ),
					'description' => __( 'A language needs a signed-off reviewer before AI drafts into it can be published.', 'estasoft-news-distribution' ),
					'rows'        => array(
						array(
							'wide' => true,
							'html' => array( $this, 'reviewers_block' ),
						),
					),
				),
			),
			'notes'  => array(
				array(
					'title' => __( 'Before you rely on it', 'estasoft-news-distribution' ),
					'html'  => $this->rely_list(),
				),
			),
			'cannot' => array(
				__( 'Publish, send or change anything by itself. A person decides every time.', 'estasoft-news-distribution' ),
				__( 'See your provider’s price, bills or key. Only a test request shows that a provider answers.', 'estasoft-news-distribution' ),
				__( 'Make a platform accept a suggestion or an article. Each platform decides.', 'estasoft-news-distribution' ),
			),
		);
	}

	/**
	 * The page without its form, for when the confirmation dialog is open over it.
	 *
	 * @param array{cards: array<int, array<string, mixed>>, notes: array<int, array<string, string>>, cannot: string[]} $layout The layout.
	 * @param array<string, array<string, mixed>>                                                                        $fields The section's fields.
	 * @param array<string, mixed>                                                                                       $values The values to show.
	 * @return string Escaped HTML.
	 */
	public function read_only( array $layout, array $fields, array $values ): string {
		$rows_html = new FieldRows( 'ai', $fields, $values );
		$html      = '';

		foreach ( $layout['cards'] as $card ) {
			$rows = '';

			foreach ( (array) $card['rows'] as $row ) {
				$rows .= $rows_html->row( (array) $row );
			}

			$html .= Card::flush( 'ai-' . (string) $card['id'], (string) $card['title'], (string) ( $card['description'] ?? '' ), $rows );
		}

		$notes = '';

		foreach ( $layout['notes'] as $index => $note ) {
			$notes .= Card::note( 'note-ai-' . $index, (string) $note['title'], (string) $note['html'] );
		}

		$notes .= Card::cannot( $layout['cannot'] );

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-split__main' ), $html . Html::tag( 'div', array( 'class' => 'estasoft-nd-notes' ), $notes ) );
	}

	/**
	 * The Status row: what WordPress reports, with Check again.
	 *
	 * @return string Escaped HTML.
	 */
	public function status_row(): string {
		$connection = $this->availability->connection();
		$version    = (string) get_bloginfo( 'version' );

		switch ( $connection ) {
			case 'ok':
				$line = StatusView::inline(
					Status::HEALTHY,
					sprintf(
						/* translators: %s: the WordPress version, such as 7.0.4. */
						__( 'Healthy: WordPress %s has AI switched on and a provider connected', 'estasoft-news-distribution' ),
						$version
					)
				);
				break;
			case 'locked':
				$line = StatusView::inline( Status::NOT_SET_UP, __( 'Locked by your network. Your network administrator has turned the AI assistant off for this site.', 'estasoft-news-distribution' ) );
				break;
			case 'no_provider':
				$line = StatusView::inline( Status::NOT_SET_UP, __( 'No provider set up. Connect an AI provider to use the assistant.', 'estasoft-news-distribution' ) );
				break;
			default:
				$line = StatusView::inline( Status::ATTENTION, __( 'AI is switched off for this WordPress, so the assistant cannot run. A setting, a constant or a plugin turned it off.', 'estasoft-news-distribution' ) );
		}

		$again = Button::link( __( 'Check again', 'estasoft-news-distribution' ), Links::settings( 'ai' ), array( 'size' => 'sm' ) );

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ), $line . $again )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'This asks WordPress only. Nothing is sent to a provider.', 'estasoft-news-distribution' ) )
			. $this->states_link();
	}

	/**
	 * A link to the reference page of every state, shown to people who want to see what the others see.
	 *
	 * @return string
	 */
	private function states_link(): string {
		return Html::tag(
			'p',
			array( 'class' => 'estasoft-nd-small' ),
			Html::tag( 'a', array( 'href' => esc_url( Links::settings( 'ai', array( 'view' => 'states' ) ) ) ), esc_html__( 'See every state the assistant can be in', 'estasoft-news-distribution' ) )
		);
	}

	/**
	 * The Providers row: each provider WordPress knows, whether it is installed, and where to connect it.
	 *
	 * @return string Escaped HTML.
	 */
	public function providers_row(): string {
		$providers = array_values(
			array_filter(
				$this->availability->providers(),
				static function ( array $provider ): bool {
					return $provider['allowed'];
				}
			)
		);
		$lines     = '';

		foreach ( $providers as $provider ) {
			$name = Html::tag( 'strong', array(), esc_html( $provider['name'] ) )
				. ( '' === $provider['family'] ? '' : ' ' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( '(' . $provider['family'] . ')' ) ) );

			if ( $provider['installed'] ) {
				$end = StatusView::inline( Status::INSTALLED )
					. Button::link( __( 'Open Connectors', 'estasoft-news-distribution' ), $this->availability->connectors_url(), array( 'size' => 'sm' ) );
			} else {
				$end = StatusView::inline( Status::NOT_INSTALLED )
					. Button::link( __( 'Get the provider plugin', 'estasoft-news-distribution' ), $provider['plugin_url'], array( 'size' => 'sm' ) );
			}

			$lines .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-provider' ),
				Html::tag( 'span', array(), $name ) . Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ), $end )
			);
		}

		if ( '' === $lines ) {
			$lines = Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'WordPress lists no AI providers on this site yet.', 'estasoft-news-distribution' ) );
		}

		$policy = $this->plugin->make( Policy::class );
		$notes  = '';

		if ( $policy->exists() ) {
			$labels = AiPolicy::providers();
			$names  = array();

			foreach ( $policy->providers() as $id ) {
				$names[] = $labels[ $id ] ?? $id;
			}

			$notes .= $this->lock_line(
				sprintf(
					/* translators: %s: the names of the providers the network allows, such as "Anthropic, OpenAI and Google". */
					__( 'Set by your network: %s are allowed. Other providers are hidden.', 'estasoft-news-distribution' ),
					wp_sprintf( '%l', $names )
				)
			);
		}

		$help = __( 'News Distribution cannot tell which of these has a key saved. A test request finds out. When two providers could answer, WordPress picks the first one that offers a model from your preferred list below.', 'estasoft-news-distribution' );
		$now  = $this->first_choice();

		if ( '' !== $now ) {
			$help .= ' ' . sprintf(
				/* translators: %s: the name of an AI provider, such as "Anthropic". */
				__( 'Today that is %s.', 'estasoft-news-distribution' ),
				$now
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $lines )
			. $notes
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $help ) )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Keys live in WordPress, under Settings, Connectors. News Distribution never sees or stores them.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The provider the first preferred model most likely belongs to, by the name of the model.
	 *
	 * It is a guess from the model's name, and only said when it matches a provider that can be used.
	 *
	 * @return string The provider's name, or an empty string when it cannot be told.
	 */
	private function first_choice(): string {
		$usable = $this->availability->usable_providers();

		if ( count( $usable ) < 2 ) {
			return '';
		}

		$families = array(
			'claude'  => 'anthropic',
			'gemini'  => 'google',
			'gpt'     => 'openai',
			'o1'      => 'openai',
			'o3'      => 'openai',
			'o4'      => 'openai',
			'chatgpt' => 'openai',
		);

		foreach ( $this->config->models() as $model ) {
			foreach ( $families as $prefix => $provider_id ) {
				if ( 0 !== stripos( $model, $prefix ) ) {
					continue;
				}

				foreach ( $usable as $provider ) {
					if ( $provider['id'] === $provider_id ) {
						return $provider['name'];
					}
				}
			}
		}

		return '';
	}

	/**
	 * A line with a lock: something the network decides.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	private function lock_line( string $text ): string {
		return Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted estasoft-nd-ai-lock' ), Icon::svg( 'lock' ) . Html::tag( 'span', array(), esc_html( $text ) ) );
	}

	/**
	 * The Test row. A test sends text to the provider, so it is offered only after the confirmation.
	 *
	 * @return string Escaped HTML.
	 */
	public function test_row(): string {
		$connection = $this->availability->connection();
		$flash      = Flash::take();
		$help       = __( 'Sends the words “Reply with OK” and nothing from your site. It costs a few tokens, paid to your provider. If it fails, the result says why, for example that the key is not valid.', 'estasoft-news-distribution' );

		if ( 'ok' !== $connection ) {
			return $flash . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Connect an AI provider first. Then you can send a test request.', 'estasoft-news-distribution' ) );
		}

		if ( ! $this->plugin->make( Consent::class )->valid() ) {
			return $flash . Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
				Button::link( __( 'Read and confirm', 'estasoft-news-distribution' ), ConsentView::url(), array( 'size' => 'sm' ) )
				. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'A test request sends text to your provider, so it waits for your confirmation.', 'estasoft-news-distribution' ) )
			) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $help ) );
		}

		return $flash . Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-12' ),
			Button::submit(
				__( 'Send a test request', 'estasoft-news-distribution' ),
				array(
					'attrs' => array(
						'name'         => 'action',
						'value'        => self::TEST_ACTION,
						'data-ai-test' => 'ai/test',
					),
				)
			) . Forms::result( 'estasoft-nd-ai-test-result', '' === $flash ? $this->probe_line() : '' )
		) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $help ) );
	}

	/**
	 * How the last test request went, as one line.
	 *
	 * @return string Escaped HTML, or an empty string when none was sent.
	 */
	public function probe_line(): string {
		$probe = $this->plugin->make( Usage::class )->probe();

		if ( array() === $probe ) {
			return '';
		}

		if ( empty( $probe['ok'] ) ) {
			return StatusView::inline( Status::FAILED, Client::reason_text( (string) ( $probe['reason'] ?? 'provider' ) ) );
		}

		$name = (string) $probe['provider'];

		foreach ( $this->availability->providers() as $provider ) {
			if ( $provider['id'] === $name ) {
				$name = $provider['name'];
			}
		}

		return StatusView::inline(
			Status::TEST_PASSED,
			sprintf(
				/* translators: 1: provider name, 2: model name, 3: seconds, 4: number of tokens. */
				__( 'Test passed: %1$s answered with %2$s in %3$s seconds. %4$s tokens used, billed by your provider.', 'estasoft-news-distribution' ),
				$name,
				'' === (string) $probe['model'] ? '—' : (string) $probe['model'],
				number_format_i18n( (int) $probe['ms'] / 1000, 1 ),
				number_format_i18n( (int) $probe['tokens'] )
			)
		);
	}

	/**
	 * One feature switch with its sentence.
	 *
	 * @param string               $toggle A key of Config::TOGGLES.
	 * @param string               $help   The sentence beside the switch.
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	private function feature_switch( string $toggle, string $help, array $values ): string {
		$features = isset( $values['features'] ) && is_array( $values['features'] ) ? $values['features'] : array();

		return Switcher::render( Field::name( 'features', $toggle ), 'estasoft-nd-f-ai-feature-' . $toggle, ! empty( $features[ $toggle ] ), $help, false );
	}

	/**
	 * The Articles row: published articles always, drafts only when the owner chooses.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function articles_row( array $values ): string {
		$policy = $this->plugin->make( Policy::class );
		$on     = ! empty( $values['drafts'] ) && $policy->allows_drafts();

		$published = Html::tag(
			'label',
			array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
			Html::void(
				'input',
				array(
					'type'     => 'checkbox',
					'checked'  => true,
					'disabled' => true,
				)
			) . Html::tag(
				'span',
				array(),
				Html::tag( 'strong', array(), esc_html__( 'Published articles', 'estasoft-news-distribution' ) )
				. '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Always included for the features you turn on.', 'estasoft-news-distribution' ) )
			)
		);

		if ( $policy->allows_drafts() ) {
			$drafts = Html::void(
				'input',
				array(
					'type'  => 'hidden',
					'name'  => Field::name( 'drafts' ),
					'value' => '0',
				)
			) . Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-check estasoft-nd-check--top',
					'for'   => 'estasoft-nd-f-ai-drafts',
				),
				Html::void(
					'input',
					array(
						'type'    => 'checkbox',
						'id'      => 'estasoft-nd-f-ai-drafts',
						'name'    => Field::name( 'drafts' ),
						'value'   => '1',
						'checked' => $on,
					)
				) . Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html__( 'Also drafts and scheduled posts', 'estasoft-news-distribution' ) )
					. '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'This lets the AI check a draft before you publish it, for example the image helper. A draft may hold an embargoed story, so turn this off if you use embargoes.', 'estasoft-news-distribution' ) )
				)
			);

			if ( $policy->exists() ) {
				$drafts .= Html::tag( 'p', array( 'class' => 'estasoft-nd-small estasoft-nd-muted' ), esc_html__( 'Your network allows this choice. The default is Off.', 'estasoft-news-distribution' ) );
			}
		} else {
			$drafts = Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'     => 'checkbox',
						'disabled' => true,
					)
				) . Html::tag(
					'span',
					array(),
					Html::tag( 'strong', array(), esc_html__( 'Also drafts and scheduled posts', 'estasoft-news-distribution' ) )
					. '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Off. Drafts are never sent.', 'estasoft-news-distribution' ) )
				)
			) . $this->lock_line( __( 'Set by your network. Sites cannot turn this on.', 'estasoft-news-distribution' ) );
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-12' ), $published . $drafts );
	}

	/**
	 * The People row: author names are off, and a network can keep them off.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function people_row( array $values ): string {
		$policy = $this->plugin->make( Policy::class );
		$words  = Html::tag( 'strong', array(), esc_html__( 'Author names', 'estasoft-news-distribution' ) )
			. '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Off. Bylines are added back by News Distribution after the AI has finished.', 'estasoft-news-distribution' ) );

		if ( ! $policy->allows_authors() ) {
			return Html::tag(
				'label',
				array( 'class' => 'estasoft-nd-check estasoft-nd-check--top' ),
				Html::void(
					'input',
					array(
						'type'     => 'checkbox',
						'disabled' => true,
					)
				) . Html::tag( 'span', array(), $words )
			) . $this->lock_line( __( 'Set by your network. Sites cannot turn this on.', 'estasoft-news-distribution' ) );
		}

		return Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => Field::name( 'authors' ),
				'value' => '0',
			)
		) . Html::tag(
			'label',
			array(
				'class' => 'estasoft-nd-check estasoft-nd-check--top',
				'for'   => 'estasoft-nd-f-ai-authors',
			),
			Html::void(
				'input',
				array(
					'type'    => 'checkbox',
					'id'      => 'estasoft-nd-f-ai-authors',
					'name'    => Field::name( 'authors' ),
					'value'   => '1',
					'checked' => ! empty( $values['authors'] ),
				)
			) . Html::tag(
				'span',
				array(),
				Html::tag( 'strong', array(), esc_html__( 'Author names', 'estasoft-news-distribution' ) )
				. '<br>' . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Off means bylines are added back by News Distribution after the AI has finished. On sends the author’s name with the article.', 'estasoft-news-distribution' ) )
			)
		);
	}

	/**
	 * The Never sent row.
	 *
	 * @return string Escaped HTML.
	 */
	public function never_row(): string {
		$items = '';

		foreach (
			array(
				__( 'Keys, passwords and tokens', 'estasoft-news-distribution' ),
				__( 'Visitor data and IP addresses', 'estasoft-news-distribution' ),
				__( 'Email addresses', 'estasoft-news-distribution' ),
				__( 'The photo itself, when the AI writes a caption', 'estasoft-news-distribution' ),
				__( 'Anything outside the articles you choose', 'estasoft-news-distribution' ),
			) as $item
		) {
			$items .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'minus-circle' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $items );
	}

	/**
	 * The price row: currency and the two prices, with the sentence that explains them.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function price_row( array $values ): string {
		$fields = $this->plugin->settings()->section_object( 'ai' );
		$spec   = null === $fields ? array() : $fields->fields();

		$control = static function ( string $name ) use ( $spec, $values ): string {
			$label = Html::tag(
				'label',
				array(
					'class' => 'estasoft-nd-small estasoft-nd-muted',
					'for'   => Field::id( 'ai', $name ),
				),
				esc_html( (string) ( $spec[ $name ]['label'] ?? $name ) )
			);

			return Html::tag( 'span', array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ), $label . Field::render( 'ai', $name, (array) ( $spec[ $name ] ?? array() ), $values[ $name ] ?? '' ) );
		};

		return Html::tag(
			'div',
			array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-16' ),
			$control( 'currency' ) . $control( 'price_in' ) . $control( 'price_out' )
		) . Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html__( 'Take both prices from your provider’s price list, for example 3.00. Leave both empty to see tokens only. With prices, News Distribution shows an estimate, never a bill.', 'estasoft-news-distribution' ) );
	}

	/**
	 * The Used so far row: today's requests and this month's tokens as two bars.
	 *
	 * @return string Escaped HTML.
	 */
	public function used_row(): string {
		$usage    = $this->plugin->make( Usage::class );
		$requests = $usage->requests_today();
		$daily    = $this->config->daily_limit();
		$tokens   = $usage->tokens_month();
		$monthly  = $this->config->monthly_limit();

		$bar = static function ( string $label, int $now, int $max, string $text ): string {
			$percent = $max > 0 ? min( 100, (int) round( $now / $max * 100 ) ) : 0;

			return Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-4' ),
				Html::tag(
					'span',
					array(
						'class'         => 'estasoft-nd-progress',
						'role'          => 'progressbar',
						'aria-valuemin' => '0',
						'aria-valuemax' => (string) $max,
						'aria-valuenow' => (string) min( $now, $max ),
						'aria-label'    => $label,
					),
					Html::tag( 'span', array( 'style' => 'width:' . $percent . '%' ), '' )
				) . Html::tag( 'span', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $text ) )
			);
		};

		$html = $bar(
			__( 'Requests today', 'estasoft-news-distribution' ),
			$requests,
			$daily,
			sprintf(
				/* translators: 1: requests made today, 2: requests allowed a day. */
				__( 'Today: %1$s of %2$s requests', 'estasoft-news-distribution' ),
				number_format_i18n( $requests ),
				number_format_i18n( $daily )
			)
		) . $bar(
			__( 'Tokens this month', 'estasoft-news-distribution' ),
			$tokens,
			$monthly,
			sprintf(
				/* translators: 1: tokens used this month, 2: tokens allowed a month. */
				__( 'This month: %1$s of %2$s tokens', 'estasoft-news-distribution' ),
				number_format_i18n( $tokens ),
				number_format_i18n( $monthly )
			)
		);

		$estimate = $usage->estimate();

		if ( null !== $estimate ) {
			$html .= Html::tag(
				'p',
				array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ),
				esc_html(
					sprintf(
						/* translators: 1: an amount of money such as 1.24, 2: a currency code such as USD. */
						__( 'Estimate at your prices: %1$s %2$s. Your provider’s bill is what counts.', 'estasoft-news-distribution' ),
						number_format_i18n( $estimate['amount'], 2 ),
						$estimate['currency']
					)
				)
			);
		}

		// The network's budget is a reason to pause that no setting of this site can lift.
		if ( $this->plugin->make( Policy::class )->network_budget_used() ) {
			$html .= Message::text(
				'warning',
				sprintf(
					/* translators: %s: the date the count starts again, such as "1 Nov 2026". */
					__( 'The network’s monthly AI budget is used, so the assistant is paused on every site until %s. Ask your network administrator if you need it sooner.', 'estasoft-news-distribution' ),
					wp_date( 'j M Y', $usage->resets_at( 'network' ), new \DateTimeZone( 'UTC' ) )
				)
			);
		}

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8 estasoft-nd-ai-used' ), $html );
	}

	/**
	 * The reviewers card: each language, who is signed off, and the boxes to choose.
	 *
	 * @param array<string, mixed> $values The section's values.
	 * @return string Escaped HTML.
	 */
	public function reviewers_block( array $values ): string {
		unset( $values );

		$reviewers = $this->plugin->make( Reviewers::class );
		$languages = $reviewers->languages();

		if ( array() === $languages ) {
			return Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'Your site writes in one language, so there are no translation drafts to approve.', 'estasoft-news-distribution' ) );
		}

		$lines  = '';
		$choose = '';
		$people = $reviewers->candidates();
		$chosen = 0;

		foreach ( $languages as $language ) {
			$signed = $reviewers->signed_off( $language );

			$status = array() === $signed
				? StatusView::inline( Status::NOT_SET_UP )
				: StatusView::inline( Status::CLEAN, __( 'Signed off', 'estasoft-news-distribution' ) );

			$chosen += count( $signed );

			$lines .= Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-reviewer' ),
				Html::tag(
					'span',
					array( 'class' => 'estasoft-nd-cluster estasoft-nd-gap-8' ),
					Tag::lang( $language )
					. ( array() === $signed ? '' : Html::tag( 'strong', array(), esc_html( implode( ', ', $signed ) ) ) )
					. Html::tag( 'span', array( 'class' => 'estasoft-nd-muted' ), esc_html( Catalog::name( $language ) ) )
				) . $status
			);

			$boxes = '';

			foreach ( $people as $id => $name ) {
				$boxes .= Html::tag(
					'label',
					array( 'class' => 'estasoft-nd-check' ),
					Html::void(
						'input',
						array(
							'type'    => 'checkbox',
							'name'    => 'estasoft_nd[reviewer_pick][' . $language . '][]',
							'value'   => (string) $id,
							'checked' => isset( $signed[ $id ] ),
						)
					) . Html::tag( 'span', array(), esc_html( $name ) )
				);
			}

			$choose .= Html::tag(
				'fieldset',
				array( 'class' => 'estasoft-nd-ai-pick' ),
				Html::tag( 'legend', array( 'class' => 'estasoft-nd-strong' ), esc_html( Catalog::name( $language ) ) )
				. ( '' === $boxes ? Html::tag( 'p', array( 'class' => 'estasoft-nd-muted' ), esc_html__( 'No editors found.', 'estasoft-news-distribution' ) ) : $boxes )
			);
		}

		$marker = Html::void(
			'input',
			array(
				'type'  => 'hidden',
				'name'  => 'estasoft_nd[reviewer_pick][_]',
				'value' => '',
			)
		);

		$second = $this->plugin->make( Policy::class )->second_approver();
		$note   = $second
			? __( 'Your network requires a second approver for a language with no signed-off reviewer.', 'estasoft-news-distribution' )
			: __( 'Without a signed-off reviewer, a draft in that language cannot be published.', 'estasoft-news-distribution' );

		return Html::tag( 'div', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $lines )
			. Html::tag(
				'div',
				array( 'class' => 'estasoft-nd-ai-choose' ),
				$marker . Disclosure::render(
					__( 'Choose reviewers', 'estasoft-news-distribution' ),
					__( 'People who can edit other people’s posts. Each one signs off for the languages you tick.', 'estasoft-news-distribution' ),
					sprintf(
					/* translators: %s: number of people signed off. */
						_n( '%s signed off', '%s signed off', $chosen, 'estasoft-news-distribution' ),
						number_format_i18n( $chosen )
					),
					$choose
				)
			)
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-muted estasoft-nd-small' ), esc_html( $note ) );
	}

	/**
	 * The three things to know before relying on the assistant, with a link to the AI log.
	 *
	 * @return string Escaped HTML.
	 */
	private function rely_list(): string {
		$items = '';

		foreach (
			array(
				__( 'Google asks publishers to fact-check AI-generated content, including titles and other metadata, before publishing', 'estasoft-news-distribution' ),
				__( 'Apple News asks for AI-generated articles to be marked', 'estasoft-news-distribution' ),
				__( 'A log records every suggestion and who decided', 'estasoft-news-distribution' ),
			) as $item
		) {
			$items .= Html::tag( 'li', array( 'class' => 'estasoft-nd-rule' ), Icon::svg( 'info' ) . Html::tag( 'span', array(), esc_html( $item ) ) );
		}

		$log = Links::admin( 'estasoft-nd-reports', array( 'report' => 'ai-log' ) );

		return Html::tag( 'ul', array( 'class' => 'estasoft-nd-stack estasoft-nd-gap-8' ), $items )
			. Html::tag( 'p', array( 'class' => 'estasoft-nd-small' ), Html::tag( 'a', array( 'href' => esc_url( $log ) ), esc_html__( 'Read the AI log', 'estasoft-news-distribution' ) ) );
	}
}
