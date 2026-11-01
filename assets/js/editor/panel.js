/*
 * The News Distribution panel in the block editor.
 *
 * Plain script, no build step, no JSX. It adds one panel to the Post tab of the document sidebar
 * (`PluginDocumentSettingPanel`) for the post types the site sends. The starting state is printed by
 * PHP into `window.estasoftND.panel` (Admin\Check\PanelState). After that the panel asks the REST API:
 *
 *   GET  /estasoft-nd/v1/articles/{id}/check   the stored check, read after each save
 *   POST /estasoft-nd/v1/articles/{id}/check   Run again
 *
 * The per-article choices (breaking news, minor edit, content label, access, channels switched off)
 * are the post meta `_estasoft_nd_options`. They are edited in the editor like any other field and saved
 * when the article is saved.
 *
 * The compact panel is the default: what to fix, the two sending switches, and two links. "Use the full
 * panel" shows every section. The choice is kept in this browser only.
 *
 * ADDING A SECTION (documented extension point)
 * ---------------------------------------------
 * Another feature adds a section in either of two ways. Both give a descriptor:
 *
 *   { id: 'source', title: 'Republished content', order: 40, mode: 'both', render: function ( api ) { return element; } }
 *
 *   - id      a short unique name. A second section with the same id is ignored.
 *   - title   the heading of the section (the panel shows it as a collapsible block).
 *   - order   a number; lower comes first among added sections. Default 50.
 *   - mode    'both' (default), 'full' (only in the full panel) or 'compact'.
 *   - render  returns the section's element. It receives an object with `postId`, `options` (the
 *             per-article choices), `setOption( changes )` (merges `changes` into the choices) and
 *             `check` (the latest check).
 *
 * 1. Push the descriptor onto `window.estasoftND.panelSections` (an array that exists once this script
 *    is loaded; create it with `|| []` if your script may run first):
 *        ( window.estasoftND = window.estasoftND || {} ).panelSections = ( window.estasoftND.panelSections || [] ).concat( [ descriptor ] );
 * 2. Or add a filter. The panel applies it every time it renders:
 *        wp.hooks.addFilter( 'estasoft_nd.panel_sections', 'my-feature/section', function ( sections ) { sections.push( descriptor ); return sections; } );
 *
 * `window.estasoftND.panelHostsSections` is true when this panel is on the page, so a feature can tell
 * whether it needs its own panel.
 */
( function ( wp ) {
	'use strict';

	var root = window.estasoftND || {};
	var boot = root.panel;

	if ( ! boot || ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components || ! wp.i18n || ! wp.apiFetch || ! wp.hooks ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var Button = wp.components.Button;
	var ToggleControl = wp.components.ToggleControl;
	var SelectControl = wp.components.SelectControl;

	var FILTER = 'estasoft_nd.panel_sections';
	var MODE_KEY = 'estasoftNDPanelMode';
	var SEEN_KEY = 'estasoftNDPanelSeen';
	var PANEL_NAME = 'estasoft-nd-panel/estasoft-nd-panel';
	var CHECK_PATH = '/estasoft-nd/v1/articles/' + boot.postId + '/check';

	/* ---------- Small helpers ---------- */

	function assign( target ) {
		for ( var i = 1; i < arguments.length; i++ ) {
			var source = arguments[ i ] || {};

			Object.keys( source ).forEach( function ( key ) {
				target[ key ] = source[ key ];
			} );
		}

		return target;
	}

	function speak( message ) {
		if ( message && wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, 'polite' );
		}
	}

	function readMode() {
		try {
			return window.localStorage.getItem( MODE_KEY ) === 'full' ? 'full' : 'compact';
		} catch ( error ) {
			return 'compact';
		}
	}

	function writeMode( mode ) {
		try {
			window.localStorage.setItem( MODE_KEY, mode );
		} catch ( error ) {
			// Private windows and blocked storage: the panel simply opens compact next time.
		}
	}

	/** Whether the panel has already been opened for this person once; after that it stays as they left it. */
	function wasSeen() {
		try {
			return window.localStorage.getItem( SEEN_KEY ) === '1';
		} catch ( error ) {
			return false;
		}
	}

	function markSeen() {
		try {
			window.localStorage.setItem( SEEN_KEY, '1' );
		} catch ( error ) {
			// Without storage the panel opens once per page load, which is harmless.
		}
	}

	function sevWord( severity ) {
		if ( severity === 'block' ) {
			return __( 'Blocks the channel', 'estasoft-news-distribution' );
		}

		return severity === 'warn' ? __( 'Risk', 'estasoft-news-distribution' ) : __( 'Tip', 'estasoft-news-distribution' );
	}

	function tone( severity ) {
		return severity === 'block' ? 'danger' : ( severity === 'warn' ? 'warning' : 'info' );
	}

	function statusWord( status ) {
		var map = {
			publish: __( 'Published', 'estasoft-news-distribution' ),
			future: __( 'Scheduled', 'estasoft-news-distribution' ),
			pending: __( 'Pending review', 'estasoft-news-distribution' ),
			private: __( 'Private', 'estasoft-news-distribution' ),
			draft: __( 'Draft', 'estasoft-news-distribution' ),
			'auto-draft': __( 'Draft', 'estasoft-news-distribution' )
		};

		return map[ status ] || status;
	}

	function icon( kind ) {
		var marks = {
			warn: [ el( 'path', { key: 'a', d: 'M12 4l9 16H3z' } ), el( 'path', { key: 'b', d: 'M12 10v4' } ), el( 'path', { key: 'c', d: 'M12 17v.01' } ) ],
			x: [ el( 'circle', { key: 'a', cx: 12, cy: 12, r: 9 } ), el( 'path', { key: 'b', d: 'M9 9l6 6M15 9l-6 6' } ) ],
			check: [ el( 'circle', { key: 'a', cx: 12, cy: 12, r: 9 } ), el( 'path', { key: 'b', d: 'M8.5 12.5l2.5 2.5 4.5-5' } ) ],
			info: [ el( 'circle', { key: 'a', cx: 12, cy: 12, r: 9 } ), el( 'path', { key: 'b', d: 'M12 11v5' } ), el( 'path', { key: 'c', d: 'M12 8v.01' } ) ],
			down: [ el( 'path', { key: 'a', d: 'M7 10l5 5 5-5' } ) ],
			up: [ el( 'path', { key: 'a', d: 'M7 14l5-5 5 5' } ) ]
		};

		return el(
			'svg',
			{ className: 'estasoft-nd-pn-icon estasoft-nd-pn-icon--' + kind, viewBox: '0 0 24 24', width: 16, height: 16, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' },
			marks[ kind ]
		);
	}

	function sevIcon( severity ) {
		return icon( severity === 'block' ? 'x' : ( severity === 'warn' ? 'warn' : 'info' ) );
	}

	function areaOf( code ) {
		var prefix = String( code || '' ).split( '.' )[ 0 ];

		return prefix === 'image' || prefix === 'credit' ? 'image' : prefix;
	}

	/* ---------- Fixes that happen in the editor ---------- */

	function documents() {
		var docs = [ document ];
		var frames = document.querySelectorAll( 'iframe[name="editor-canvas"]' );

		Array.prototype.forEach.call( frames, function ( frame ) {
			try {
				if ( frame.contentDocument ) {
					docs.push( frame.contentDocument );
				}
			} catch ( error ) {
				// A frame from another origin cannot be read; the title stays where it is.
			}
		} );

		return docs;
	}

	function focusFirst( selector ) {
		var docs = documents();

		for ( var i = 0; i < docs.length; i++ ) {
			var node = docs[ i ].querySelector( selector );

			if ( node ) {
				node.focus();

				if ( node.scrollIntoView ) {
					node.scrollIntoView( { block: 'center' } );
				}

				return true;
			}
		}

		return false;
	}

	function openPanel( name ) {
		var select = wp.data.select( 'core/editor' );
		var dispatch = wp.data.dispatch( 'core/editor' );

		if ( select && select.isEditorPanelOpened && dispatch && dispatch.toggleEditorPanelOpened && ! select.isEditorPanelOpened( name ) ) {
			dispatch.toggleEditorPanelOpened( name );
		}
	}

	var FIXERS = {
		'edit-title': function () {
			focusFirst( '.editor-post-title__input, .wp-block-post-title, h1[role="textbox"]' );
		},
		'add-image': function () {
			openPanel( 'featured-image' );
			window.setTimeout( function () {
				focusFirst( '.editor-post-featured-image__toggle, .editor-post-featured-image__preview, .editor-post-featured-image button' );
			}, 150 );
		},
		'fix-date': function () {
			openPanel( 'post-status' );
			window.setTimeout( function () {
				focusFirst( '.editor-post-schedule__dialog-toggle, .editor-post-panel__row-control button' );
			}, 150 );
		},
		'set-author': function () {
			openPanel( 'post-status' );
			window.setTimeout( function () {
				focusFirst( '.editor-post-author__select, .editor-post-author select, .editor-post-panel__row-control select' );
			}, 150 );
		}
	};

	/**
	 * The fix for a finding: the one the check named, or the one the check codes table lists for its code
	 * (a platform's own copy of a finding can come without one).
	 */
	function fixOf( finding ) {
		var code = String( finding.code || '' );
		var prefix = code.split( '.' )[ 0 ];

		if ( finding.fix ) {
			return finding.fix;
		}

		if ( [ 'image.missing', 'image.too_small', 'image.bad_ratio', 'thumbnail.missing' ].indexOf( code ) !== -1 ) {
			return 'add-image';
		}

		if ( prefix === 'title' ) {
			return 'edit-title';
		}

		if ( prefix === 'date' ) {
			return 'fix-date';
		}

		return prefix === 'byline' ? 'set-author' : '';
	}

	function FixButton( props ) {
		var slug = props.slug;
		var label = slug ? boot.fixLabels[ slug ] : '';
		var url = slug ? boot.fixUrls[ slug ] : '';

		if ( ! label ) {
			return null;
		}

		var variant = props.variant || 'secondary';

		if ( url ) {
			return el(
				Button,
				{ variant: variant, size: 'compact', href: url, target: '_blank', rel: 'noopener noreferrer' },
				label,
				el( 'span', { className: 'screen-reader-text' }, ' ' + __( '(opens in a new tab)', 'estasoft-news-distribution' ) )
			);
		}

		if ( ! FIXERS[ slug ] ) {
			return null;
		}

		return el( Button, { variant: variant, size: 'compact', onClick: FIXERS[ slug ] }, label );
	}

	/* ---------- State ---------- */

	/**
	 * The check: the starting state, then what the REST routes answer.
	 */
	function useCheck() {
		var checkState = useState( boot.check || null );
		var busyState = useState( '' );
		var blockedState = useState( false );
		var setCheck = checkState[ 1 ];
		var setBusy = busyState[ 1 ];
		var setBlocked = blockedState[ 1 ];

		function load( run ) {
			setBusy( run ? 'running' : 'loading' );

			return wp.apiFetch( { path: CHECK_PATH, method: run ? 'POST' : 'GET' } )
				.then( function ( data ) {
					setCheck( data );
					setBlocked( false );
					setBusy( '' );

					if ( run ) {
						speak( __( 'Checked just now.', 'estasoft-news-distribution' ) );
					}
				} )
				.catch( function () {
					// The route said no (some roles may not use it) or did not answer. The starting state stays on screen.
					setBlocked( true );
					setBusy( '' );
					speak( __( 'Could not reach the check. What the panel shows may be out of date.', 'estasoft-news-distribution' ) );
				} );
		}

		return {
			check: checkState[ 0 ],
			busy: busyState[ 0 ],
			blocked: blockedState[ 0 ],
			refresh: function () {
				return load( false );
			},
			run: function () {
				return load( true );
			}
		};
	}

	/**
	 * Reads the check again a moment after the article has been saved: the server stores a new check when it saves.
	 */
	function useRefreshAfterSave( refresh ) {
		var saving = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return !! editor.isSavingPost() && ! editor.isAutosavingPost();
		}, [] );
		var was = useRef( false );
		var latest = useRef( refresh );

		latest.current = refresh;

		useEffect( function () {
			var timer = 0;

			if ( was.current && ! saving ) {
				timer = window.setTimeout( function () {
					latest.current();
				}, 900 );
			}

			was.current = saving;

			return function () {
				window.clearTimeout( timer );
			};
		}, [ saving ] );
	}

	/**
	 * What the editor holds for the article now: title, status, and the per-article choices.
	 */
	function useArticle() {
		var found = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};

			return {
				title: editor.getEditedPostAttribute( 'title' ) || '',
				status: editor.getEditedPostAttribute( 'status' ) || 'draft',
				stored: meta[ boot.metaKey ]
			};
		}, [] );
		var editor = useDispatch( 'core/editor' );
		var options = assign( {}, boot.options, found.stored && typeof found.stored === 'object' ? found.stored : {} );

		function setOption( changes ) {
			var meta = {};

			meta[ boot.metaKey ] = assign( {}, options, changes );
			editor.editPost( { meta: meta } );
		}

		return { title: found.title, status: found.status, options: options, setOption: setOption };
	}

	/* ---------- Pieces ---------- */

	function Block( props ) {
		return el(
			'section',
			{ className: 'estasoft-nd-pn-block' + ( props.className ? ' ' + props.className : '' ), 'aria-labelledby': props.title && props.id ? props.id : undefined },
			props.title ? el( 'h3', { className: 'estasoft-nd-pn-label', id: props.id }, props.title ) : null,
			props.children
		);
	}

	function Disclosure( props ) {
		var state = useState( !! props.open );
		var open = state[ 0 ];
		var body = 'estasoft-nd-pn-' + props.id;

		return el(
			'section',
			{ className: 'estasoft-nd-pn-block estasoft-nd-pn-disclosure' },
			el(
				'button',
				{ type: 'button', className: 'estasoft-nd-pn-disc', 'aria-expanded': open ? 'true' : 'false', 'aria-controls': body, onClick: function () {
					state[ 1 ]( ! open );
				} },
				el( 'span', { className: 'estasoft-nd-pn-disc__text' }, el( 'strong', null, props.title ), props.hint ? el( 'span', { className: 'estasoft-nd-pn-muted' }, props.hint ) : null ),
				icon( open ? 'up' : 'down' )
			),
			el( 'div', { id: body, className: 'estasoft-nd-pn-disc__body', hidden: ! open }, open ? props.children : null )
		);
	}

	function channelNames( ids, check ) {
		var labels = {};

		( boot.channels || [] ).forEach( function ( channel ) {
			labels[ channel.id ] = channel.label;
		} );

		Object.keys( ( check && check.channels ) || {} ).forEach( function ( id ) {
			labels[ id ] = labels[ id ] || check.channels[ id ].label;
		} );

		// Only channels that are on for this article are named; a channel set up by hand does nothing for one article.
		var known = {};

		( boot.channels || [] ).forEach( function ( channel ) {
			known[ channel.id ] = true;
		} );

		return ( ids || [] ).filter( function ( id ) {
			return known[ id ];
		} ).map( function ( id ) {
			return labels[ id ] || id;
		} );
	}

	function FindingCard( props ) {
		var finding = props.finding;
		var names = channelNames( finding.channels, props.check );

		return el(
			'li',
			{ className: 'estasoft-nd-pn-card estasoft-nd-pn-card--' + tone( finding.severity ) },
			el( 'div', { className: 'estasoft-nd-pn-card__head' }, sevIcon( finding.severity ), el( 'span', { className: 'estasoft-nd-pn-sev' }, sevWord( finding.severity ) ) ),
			el( 'p', { className: 'estasoft-nd-pn-card__msg' }, finding.message ),
			names.length ? el( 'p', { className: 'estasoft-nd-pn-muted' }, names.length > 3 ? sprintf( /* translators: %d: a number of channels. */ __( 'Matters for %d channels.', 'estasoft-news-distribution' ), names.length ) : sprintf( /* translators: %s: channel names. */ __( 'Matters for %s.', 'estasoft-news-distribution' ), names.join( ', ' ) ) ) : null,
			props.showFix === false ? null : el( FixButton, { slug: fixOf( finding ) } )
		);
	}

	function TranslationCard( props ) {
		var item = props.item;

		return el(
			'li',
			{ className: 'estasoft-nd-pn-card estasoft-nd-pn-card--warning' },
			el( 'div', { className: 'estasoft-nd-pn-card__head' }, icon( 'warn' ), el( 'span', { className: 'estasoft-nd-pn-sev' }, __( 'Risk', 'estasoft-news-distribution' ) ) ),
			el( 'p', { className: 'estasoft-nd-pn-card__msg' }, sprintf( /* translators: %s: a language name. */ __( '%s translation is missing', 'estasoft-news-distribution' ), item.name ) ),
			item.createUrl ? el( Button, { variant: 'secondary', size: 'compact', href: item.createUrl }, sprintf( /* translators: %s: a language name. */ __( 'Create %s translation', 'estasoft-news-distribution' ), item.name ) ) : null
		);
	}

	function sums( check ) {
		var findings = ( check && check.findings ) || [];
		var todo = [];
		var tips = [];

		findings.forEach( function ( finding ) {
			( finding.severity === 'info' ? tips : todo ).push( finding );
		} );

		var missing = ( boot.translations || [] ).filter( function ( item ) {
			return ! item.id;
		} );

		return { todo: todo, tips: tips, missing: missing, count: todo.length + missing.length };
	}

	function Heading( props ) {
		var info = props.info;
		var stored = props.check && props.check.stored;
		var text;

		if ( ! stored ) {
			text = __( 'Not checked yet', 'estasoft-news-distribution' );
		} else if ( info.count === 0 ) {
			text = __( 'Nothing to fix', 'estasoft-news-distribution' );
		} else {
			text = sprintf( /* translators: %d: a number of findings. */ _n( '%d thing to fix', '%d things to fix', info.count, 'estasoft-news-distribution' ), info.count );
		}

		return el(
			Fragment,
			null,
			el( 'p', { className: 'estasoft-nd-pn-label' }, props.full && stored && info.count > 0 && props.article.status !== 'publish' ? sprintf( /* translators: %d: a number of findings. */ _n( '%d thing to fix before you publish', '%d things to fix before you publish', info.count, 'estasoft-news-distribution' ), info.count ) : text ),
			el( 'p', { className: 'estasoft-nd-pn-muted' }, ( props.article.title || __( '(no title)', 'estasoft-news-distribution' ) ) + ' · ' + statusWord( props.article.status ) )
		);
	}

	function FixList( props ) {
		var info = props.info;

		if ( ! props.check || ! props.check.stored ) {
			return el( 'p', { className: 'estasoft-nd-pn-note' }, __( 'This article has not been checked yet. Save it, or press Run again. The check reads the saved article.', 'estasoft-news-distribution' ) );
		}

		if ( info.count === 0 ) {
			return el( 'p', { className: 'estasoft-nd-pn-ok' }, icon( 'check' ), el( 'span', null, __( 'No channel has a note about this article.', 'estasoft-news-distribution' ) ) );
		}

		// Several channels can name the same problem; the button that fixes it is shown on the first card only.
		var offered = {};

		return el(
			'ul',
			{ className: 'estasoft-nd-pn-cards' },
			info.todo.map( function ( finding, index ) {
				var slug = fixOf( finding );
				var show = ! slug || ! offered[ slug ];

				offered[ slug ] = true;

				return el( FindingCard, { key: 'f' + index, finding: finding, check: props.check, showFix: show } );
			} ),
			info.missing.map( function ( item ) {
				return el( TranslationCard, { key: 't' + item.code, item: item } );
			} )
		);
	}

	/**
	 * The full panel's summary: one amber box, a numbered list of what to fix, and a link for each fix that
	 * is done in the editor. The image fixes live in the Featured image section below.
	 */
	function Numbered( props ) {
		var info = props.info;

		if ( ! props.check || ! props.check.stored ) {
			return el( 'p', { className: 'estasoft-nd-pn-note' }, __( 'This article has not been checked yet. Save it, or press Run again. The check reads the saved article.', 'estasoft-news-distribution' ) );
		}

		if ( info.count === 0 ) {
			return el( 'p', { className: 'estasoft-nd-pn-ok' }, icon( 'check' ), el( 'span', null, __( 'No channel has a note about this article.', 'estasoft-news-distribution' ) ) );
		}

		var offered = {};
		var items = info.todo.map( function ( finding, index ) {
			var slug = fixOf( finding );
			var show = slug && ! offered[ slug ] && [ 'add-image', 'add-alt', 'add-credit' ].indexOf( slug ) === -1;

			offered[ slug ] = true;

			return el( 'li', { key: 'n' + index }, el( 'strong', null, sevWord( finding.severity ) + ': ' ), finding.message, show ? el( Fragment, null, ' ', el( FixButton, { slug: slug, variant: 'link' } ) ) : null );
		} ).concat( info.missing.map( function ( item ) {
			return el( 'li', { key: 't' + item.code }, sprintf( /* translators: %s: a language name. */ __( '%s translation is missing', 'estasoft-news-distribution' ), item.name ) );
		} ) );

		return el( 'div', { className: 'estasoft-nd-pn-box estasoft-nd-pn-box--warning' }, el( 'ol', { className: 'estasoft-nd-pn-numbered' }, items ) );
	}

	function RunAgain( props ) {
		var check = props.state.check;
		var checked = check && check.checked ? check.checked : '';
		var when = '';

		if ( checked ) {
			when = wp.date && wp.date.humanTimeDiff ? wp.date.humanTimeDiff( checked ) : checked;
		}

		return el(
			'div',
			{ className: 'estasoft-nd-pn-run' },
			el( Button, { variant: 'secondary', size: 'compact', isBusy: props.state.busy === 'running', disabled: !! props.state.busy, onClick: props.state.run }, props.state.busy === 'running' ? __( 'Checking…', 'estasoft-news-distribution' ) : __( 'Run again', 'estasoft-news-distribution' ) ),
			checked ? el( 'span', { className: 'estasoft-nd-pn-muted' }, sprintf( /* translators: %s: how long ago, such as "3 minutes ago". */ __( 'Checked %s', 'estasoft-news-distribution' ), when ) ) : null,
			props.state.blocked ? el( 'p', { className: 'estasoft-nd-pn-note', role: 'status' }, __( 'The check could not be reached from here, so this may be out of date. Reload the editor after saving.', 'estasoft-news-distribution' ) ) : null
		);
	}

	// The person's own choice for this article comes first: a channel switched off is Off, never a problem.
	function resultText( channel, check, chosen ) {
		var result = check && check.results ? check.results[ channel.id ] : null;
		var entry = check && check.channels ? check.channels[ channel.id ] : null;

		if ( chosen && chosen[ channel.id ] === false ) {
			return ( boot.statusWords.off || 'Off' ) + '. ' + __( 'Left out by a choice for this article.', 'estasoft-news-distribution' );
		}

		if ( result && result.status ) {
			return ( boot.statusWords[ result.status ] || result.status ) + ( result.message ? '. ' + result.message : '' );
		}

		if ( entry && entry.eligible === false ) {
			var first = '';

			( entry.findings || [] ).forEach( function ( finding ) {
				if ( ! first && finding.severity === 'block' ) {
					first = finding.message;
				}
			} );

			return sprintf( /* translators: %s: a reason in one sentence. */ __( 'Would skip this article. %s', 'estasoft-news-distribution' ), first ).trim();
		}

		return check && check.stored ? __( 'Included once the article is published', 'estasoft-news-distribution' ) : __( 'Not checked yet', 'estasoft-news-distribution' );
	}

	function AllChecks( props ) {
		var check = props.check;
		var info = props.info;
		var chosen = props.chosen && typeof props.chosen === 'object' ? props.chosen : {};

		return el(
			Fragment,
			null,
			info.tips.length ? el( 'ul', { className: 'estasoft-nd-pn-cards' }, info.tips.map( function ( finding, index ) {
				return el( FindingCard, { key: 'tip' + index, finding: finding, check: check } );
			} ) ) : null,
			( boot.channels || [] ).length ? el( 'ul', { className: 'estasoft-nd-pn-rows' }, boot.channels.map( function ( channel ) {
				return el( 'li', { key: channel.id, className: 'estasoft-nd-pn-row' }, el( 'strong', null, channel.label ), el( 'span', { className: 'estasoft-nd-pn-muted' }, resultText( channel, check, chosen ) ) );
			} ) ) : el( 'p', { className: 'estasoft-nd-pn-muted' }, __( 'No channel is on for this article’s language.', 'estasoft-news-distribution' ) )
		);
	}

	function Sending( props ) {
		var article = props.article;
		var off = ! boot.canEdit || ! boot.canSave;

		return el(
			Block,
			{ title: props.title, id: 'estasoft-nd-pn-sending' },
			el( ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Breaking news', 'estasoft-news-distribution' ),
				help: __( 'Sends to partners at once.', 'estasoft-news-distribution' ),
				checked: !! article.options.breaking,
				disabled: off,
				onChange: function ( value ) {
					article.setOption( { breaking: !! value } );
				}
			} ),
			el( ToggleControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Minor edit', 'estasoft-news-distribution' ),
				help: __( 'Do not send pushes for this change.', 'estasoft-news-distribution' ),
				checked: !! article.options.minor_edit,
				disabled: off,
				onChange: function ( value ) {
					article.setOption( { minor_edit: !! value } );
				}
			} ),
			el( 'p', { className: 'estasoft-nd-pn-muted' }, boot.canSave ? __( 'These are saved with the article. Minor edit covers the next save only.', 'estasoft-news-distribution' ) : __( 'This post type has no custom fields, so these choices cannot be saved here.', 'estasoft-news-distribution' ) )
		);
	}

	// Set when the person switches between the compact and the full panel, so the new link gets the focus.
	var focusSwitch = false;

	function MoreLinks( props ) {
		var ref = useRef( null );

		// The button that was pressed is gone after the switch; the same link in the other panel takes the focus.
		useEffect( function () {
			if ( focusSwitch && ref.current ) {
				focusSwitch = false;
				ref.current.focus();
			}
		}, [] );

		return el(
			Block,
			{ title: __( 'More', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-more' },
			boot.pageUrl ? el( 'p', null, el( 'a', { href: boot.pageUrl }, __( 'Open Article check', 'estasoft-news-distribution' ) ) ) : null,
			el( 'p', null, el( 'button', { type: 'button', className: 'estasoft-nd-pn-link', ref: ref, onClick: props.onToggle }, props.mode === 'full' ? __( 'Use the compact panel', 'estasoft-news-distribution' ) : __( 'Use the full panel', 'estasoft-news-distribution' ) ) )
		);
	}

	/* ---------- Sections added by other features ---------- */

	function collectSections( mode ) {
		var added = Array.isArray( root.panelSections ) ? root.panelSections.slice() : [];
		var filtered = wp.hooks.applyFilters( FILTER, added );
		var seen = {};
		var out = [];

		( Array.isArray( filtered ) ? filtered : added ).forEach( function ( section ) {
			if ( ! section || typeof section.render !== 'function' ) {
				return;
			}

			var id = String( section.id || section.name || '' );
			var shows = section.mode || 'both';

			if ( id && seen[ id ] ) {
				return;
			}

			if ( id ) {
				seen[ id ] = true;
			}

			if ( shows === 'both' || shows === mode ) {
				out.push( section );
			}
		} );

		out.sort( function ( a, b ) {
			return ( typeof a.order === 'number' ? a.order : 50 ) - ( typeof b.order === 'number' ? b.order : 50 );
		} );

		return out;
	}

	function AddedSections( props ) {
		var sections = props.sections;
		var api = { postId: boot.postId, options: props.article.options, setOption: props.article.setOption, check: props.check };

		return el(
			Fragment,
			null,
			sections.map( function ( section, index ) {
				var id = String( section.id || section.name || index );

				return el(
					Disclosure,
					{ key: 'ext-' + id, id: 'ext-' + id.replace( /[^a-z0-9_-]/gi, '' ), title: section.title || id, open: props.mode === 'full' },
					el( 'div', { className: 'estasoft-nd-pn-extbody' }, section.render( api ) )
				);
			} )
		);
	}

	/* ---------- The compact panel ---------- */

	function Compact( props ) {
		var info = sums( props.state.check );

		return el(
			'div',
			{ className: 'estasoft-nd-pn estasoft-nd-pn--compact' },
			el( Block, { id: 'estasoft-nd-pn-fix' }, el( Heading, { info: info, check: props.state.check, article: props.article, full: false } ), el( FixList, { info: info, check: props.state.check } ) ),
			el(
				Block,
				{ title: __( 'Everything else', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-else' },
				props.state.check && props.state.check.stored ? el( 'p', { className: 'estasoft-nd-pn-ok' }, icon( 'check' ), el( 'span', null, info.tips.length ? sprintf( /* translators: %d: a number of tips. */ _n( '%d tip, in the full list', '%d tips, in the full list', info.tips.length, 'estasoft-news-distribution' ), info.tips.length ) : __( 'No other findings.', 'estasoft-news-distribution' ) ) ) : null,
				el( 'p', { className: 'estasoft-nd-pn-muted' }, sprintf( /* translators: %d: a number of channels. */ _n( '%d channel is on for this article.', '%d channels are on for this article.', ( boot.channels || [] ).length, 'estasoft-news-distribution' ), ( boot.channels || [] ).length ) ),
				el( RunAgain, { state: props.state } )
			),
			el(
				Disclosure,
				{
					id: 'all-checks',
					title: __( 'Show all checks', 'estasoft-news-distribution' ),
					hint: props.state.check && props.state.check.stored ? sprintf( /* translators: 1: a number of things to fix, 2: a number of tips. */ __( '%1$d to fix, %2$d tips', 'estasoft-news-distribution' ), info.count, info.tips.length ) : ''
				},
				el( AllChecks, { check: props.state.check, info: info, chosen: props.article.options.channels } )
			),
			el( Sending, { article: props.article, title: __( 'Sending', 'estasoft-news-distribution' ) } ),
			el( AddedSections, { mode: 'compact', sections: props.sections, article: props.article, check: props.state.check } ),
			el( MoreLinks, { mode: 'compact', onToggle: props.onToggle } )
		);
	}

	/* ---------- The full panel ---------- */

	function Select( props ) {
		return el( SelectControl, {
			__nextHasNoMarginBottom: true,
			__next40pxDefaultSize: true,
			label: props.label,
			help: props.help,
			value: props.value,
			options: props.options,
			disabled: ! boot.canEdit || ! boot.canSave,
			onChange: props.onChange
		} );
	}

	function LanguageBlock() {
		var items = boot.translations || [];

		return el(
			Block,
			{ title: __( 'Language and translations', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-language' },
			el( 'p', null, el( 'span', { className: 'estasoft-nd-pn-chip' }, String( boot.language || '' ).toUpperCase() ), ' ', boot.languageName ),
			items.length ? el(
				'ul',
				{ className: 'estasoft-nd-pn-rows' },
				items.map( function ( item ) {
					var state = item.id ? ( item.status === 'publish' ? __( 'Published', 'estasoft-news-distribution' ) : __( 'Draft saved', 'estasoft-news-distribution' ) ) : __( 'Missing', 'estasoft-news-distribution' );

					return el(
						'li',
						{ key: item.code, className: 'estasoft-nd-pn-row' },
						el( 'strong', null, item.name ),
						el( 'span', { className: 'estasoft-nd-pn-muted' }, state ),
						item.editUrl ? el( 'a', { href: item.editUrl }, __( 'Open', 'estasoft-news-distribution' ) ) : null,
						item.createUrl ? el( 'a', { href: item.createUrl }, sprintf( /* translators: %s: a language name. */ __( 'Create %s translation', 'estasoft-news-distribution' ), item.name ) ) : null
					);
				} )
			) : null
		);
	}

	function ChannelsBlock( props ) {
		var article = props.article;
		var groups = [];
		var last = '';

		( boot.channels || [] ).forEach( function ( channel ) {
			if ( channel.group !== last ) {
				groups.push( { title: channel.groupTitle, channels: [] } );
				last = channel.group;
			}

			groups[ groups.length - 1 ].channels.push( channel );
		} );

		var chosen = article.options.channels && typeof article.options.channels === 'object' ? article.options.channels : {};

		return el(
			Block,
			{ title: __( 'Channels', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-channels' },
			el( 'p', { className: 'estasoft-nd-pn-muted' }, __( 'A channel that is on carries this article when its checks pass. Turn one off to leave this article out of it. This changes this article only.', 'estasoft-news-distribution' ) ),
			groups.map( function ( group ) {
				return el(
					'div',
					{ key: group.title, className: 'estasoft-nd-pn-group' },
					el( 'h4', { className: 'estasoft-nd-pn-sub' }, group.title ),
					group.channels.map( function ( channel ) {
						if ( channel.mode === 'pushed' || channel.mode === 'pulled' ) {
							return el( ToggleControl, {
								key: channel.id,
								__nextHasNoMarginBottom: true,
								label: channel.label,
								help: resultText( channel, props.check, chosen ),
								checked: chosen[ channel.id ] !== false,
								disabled: ! boot.canEdit || ! boot.canSave,
								onChange: function ( value ) {
									var next = assign( {}, chosen );

									next[ channel.id ] = !! value;
									article.setOption( { channels: next } );
								}
							} );
						}

						return el( 'div', { key: channel.id, className: 'estasoft-nd-pn-chrow' }, el( 'strong', null, channel.label ), el( 'span', { className: 'estasoft-nd-pn-muted' }, resultText( channel, props.check, chosen ) ) );
					} )
				);
			} ),
			( boot.notSetUp || [] ).length ? el( 'p', { className: 'estasoft-nd-pn-muted' }, sprintf( /* translators: %s: channel names. */ __( 'Not set up: %s.', 'estasoft-news-distribution' ), boot.notSetUp.join( ', ' ) ), ' ', boot.channelsUrl ? el( 'a', { href: boot.channelsUrl }, __( 'Open Channels', 'estasoft-news-distribution' ) ) : null ) : null
		);
	}

	function ImageFindings( props ) {
		var offered = {};
		var buttons = [];

		props.findings.forEach( function ( finding ) {
			var slug = fixOf( finding );

			if ( slug && ! offered[ slug ] ) {
				offered[ slug ] = true;
				buttons.push( el( FixButton, { key: slug, slug: slug } ) );
			}
		} );

		return el(
			Fragment,
			null,
			el( 'ul', { className: 'estasoft-nd-pn-rows estasoft-nd-pn-rows--plain' }, props.findings.map( function ( finding, index ) {
				return el( 'li', { key: 'i' + index, className: 'estasoft-nd-pn-line estasoft-nd-pn-line--' + tone( finding.severity ) }, sevIcon( finding.severity ), el( 'span', null, finding.message ) );
			} ) ),
			buttons.length ? el( 'div', { className: 'estasoft-nd-pn-actions' }, buttons ) : null
		);
	}

	function Full( props ) {
		var info = sums( props.state.check );
		var article = props.article;
		var image = info.todo.concat( info.tips ).filter( function ( finding ) {
			return areaOf( finding.code ) === 'image';
		} );

		return el(
			'div',
			{ className: 'estasoft-nd-pn estasoft-nd-pn--full' },
			el(
				Block,
				{ title: __( 'Summary', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-summary' },
				el( Heading, { info: info, check: props.state.check, article: article, full: true } ),
				el( Numbered, { info: info, check: props.state.check } ),
				el( 'p', { className: 'estasoft-nd-pn-muted' }, sprintf( /* translators: %d: a number of channels. */ _n( '%d channel is on for this article.', '%d channels are on for this article.', ( boot.channels || [] ).length, 'estasoft-news-distribution' ), ( boot.channels || [] ).length ) ),
				el( RunAgain, { state: props.state } )
			),
			el(
				Block,
				{ title: __( 'Featured image', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-image' },
				image.length ? el( ImageFindings, { findings: image } ) : el( 'p', { className: 'estasoft-nd-pn-ok' }, icon( 'check' ), el( 'span', null, props.state.check && props.state.check.stored ? __( 'No note about the featured image.', 'estasoft-news-distribution' ) : __( 'Not checked yet.', 'estasoft-news-distribution' ) ) )
			),
			el( LanguageBlock, null ),
			el(
				Block,
				{ title: __( 'Content label', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-label' },
				el( Select, {
					label: __( 'What kind of article is this?', 'estasoft-news-distribution' ),
					help: __( 'Used for the Apple News list of articles to mark, and for page labels when they are switched on.', 'estasoft-news-distribution' ),
					value: article.options.content_label || 'original',
					options: boot.contentLabels,
					onChange: function ( value ) {
						article.setOption( { content_label: value } );
					}
				} )
			),
			el(
				Block,
				{ title: __( 'Access', 'estasoft-news-distribution' ), id: 'estasoft-nd-pn-access' },
				el( Select, {
					label: __( 'Who can read this article?', 'estasoft-news-distribution' ),
					help: __( 'Written into the article’s structured data for search engines. It does not change who can read the article.', 'estasoft-news-distribution' ),
					value: article.options.access || 'everyone',
					options: boot.accessChoices,
					onChange: function ( value ) {
						article.setOption( { access: value } );
					}
				} )
			),
			el( Sending, { article: article, title: __( 'Timing', 'estasoft-news-distribution' ) } ),
			el( ChannelsBlock, { article: article, check: props.state.check } ),
			el( AddedSections, { mode: 'full', sections: props.sections, article: article, check: props.state.check } ),
			el( MoreLinks, { mode: 'full', onToggle: props.onToggle } )
		);
	}

	/* ---------- The panel ---------- */

	function Panel() {
		var PanelHost = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
		var modeState = useState( readMode() );
		var mode = modeState[ 0 ];
		var state = useCheck();
		var article = useArticle();
		var type = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostType();
		}, [] );

		useRefreshAfterSave( state.refresh );

		// The first time, the panel opens on its own: a note that needs a person should not sit behind a closed panel.
		var opened = useSelect( function ( select ) {
			var editor = select( 'core/editor' );

			return editor.isEditorPanelOpened ? !! editor.isEditorPanelOpened( PANEL_NAME ) : true;
		}, [] );
		var panels = useDispatch( 'core/editor' );

		useEffect( function () {
			if ( wasSeen() ) {
				return;
			}

			markSeen();

			if ( ! opened && panels.toggleEditorPanelOpened ) {
				panels.toggleEditorPanelOpened( PANEL_NAME );
			}
		}, [] );

		// Read the added sections even while the panel is shut, so a feature that waits to see whether a panel hosts it finds out.
		var sections = collectSections( mode );

		if ( ! PanelHost || type !== boot.postType ) {
			return null;
		}

		function toggle() {
			var next = mode === 'full' ? 'compact' : 'full';

			writeMode( next );
			focusSwitch = true;
			modeState[ 1 ]( next );
		}

		return el(
			PanelHost,
			{ name: 'estasoft-nd-panel', title: __( 'News Distribution', 'estasoft-news-distribution' ), className: 'estasoft-nd-panel', initialOpen: true },
			mode === 'full' ? el( Full, { state: state, article: article, sections: sections, onToggle: toggle } ) : el( Compact, { state: state, article: article, sections: sections, onToggle: toggle } )
		);
	}

	wp.plugins.registerPlugin( 'estasoft-nd-panel', { render: Panel } );
}( window.wp ) );
