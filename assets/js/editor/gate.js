/*
 * The pre-publish gate in the block editor.
 *
 * Plain script, no build step, no JSX. It adds one section to the "Are you ready to publish?" sidebar
 * (`PluginPrePublishPanel`). PHP prints the starting state into `window.estasoftND.gate`
 * (Modules\PrePublishGateModule); after that the panel asks the REST API:
 *
 *   GET  /estasoft-nd/v1/articles/{id}/gate?run=1        the article against the channels an administrator
 *                                                        requires to pass, from a fresh check
 *
 * "Publish anyway" does not call a route of its own any more. The reason goes with the save: for the next
 * save of this article the script adds the header `X-Estasoft-ND-Override` (the reason, percent-encoded as
 * UTF-8, because a header only carries Latin-1; a single "-" when the site does not ask for a reason), and the
 * server writes the audit row when the article really goes live.
 *
 * What it does, by the mode set in Settings, Access and gate:
 *
 *   off    the script is not loaded.
 *   warn   shows what does not pass. Publishing is never stopped.
 *   block  locks the Publish button of the sidebar (`lockPostSaving`) while a required channel has a blocking
 *          problem. An editor or administrator can publish anyway, with a reason; the server saves the reason in
 *          the delivery log with their name when the article goes live. In block mode the server refuses a
 *          publish that fails the gate too (Core\Gate::server_check), so a second tab or the REST API cannot get
 *          around the lock; its answer is shown here when it refuses.
 *
 * The lock lives only while the sidebar is open. The sidebar opens from the Publish button of the top bar;
 * if the lock were held all the time that button would be disabled, and nobody could open the panel that
 * says why. Closing the sidebar lets go of the lock.
 *
 * WHAT IT CANNOT DO, and says on the screen (`boot.limits`, written by Core\Gate::limits): the server does not
 * stop a scheduled post when WordPress publishes it on time, WP-CLI, an import, or an article that is already
 * published. In warn mode nothing is stopped anywhere.
 *
 * The check reads the SAVED article. When the editor has unsaved changes the panel says so and offers
 * "Save draft and check again".
 */
( function ( wp ) {
	'use strict';

	var boot = ( window.estasoftND || {} ).gate;

	if ( ! boot || ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components || ! wp.i18n || ! wp.apiFetch ) {
		return;
	}

	var Panel = ( wp.editor && wp.editor.PluginPrePublishPanel ) || ( wp.editPost && wp.editPost.PluginPrePublishPanel );

	if ( ! Panel ) {
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
	var TextControl = wp.components.TextControl;

	var LOCK = 'estasoft-nd-gate';
	var BASE = '/estasoft-nd/v1/articles/';
	var HEADER = boot.overrideHeader || 'X-Estasoft-ND-Override';

	/*
	 * The reason typed for "Publish anyway", held only until the next save of this article has been sent.
	 * Null means nothing is armed. An empty string means the site does not ask for a reason.
	 */
	var armed = null;

	function is_this_post( options ) {
		var found = /\/wp\/v2\/[^/?]+\/(\d+)(?:\?|$)/.exec( ( options && ( options.path || options.url ) ) || '' );

		return !! found && parseInt( found[ 1 ], 10 ) === boot.postId;
	}

	// Adds the header to the save of this article, and lets go of the reason once that save has been sent.
	wp.apiFetch.use( function ( options, next ) {
		var method = String( ( options && options.method ) || 'GET' ).toUpperCase();

		if ( null === armed || ( 'PUT' !== method && 'POST' !== method && 'PATCH' !== method ) || ! is_this_post( options ) ) {
			return next( options );
		}

		var headers = {};

		headers[ HEADER ] = encodeURIComponent( armed || '-' );
		options = Object.assign( {}, options, { headers: Object.assign( {}, options.headers || {}, headers ) } );
		armed = null;

		return next( options );
	} );

	/* ---------- Small helpers ---------- */

	function say( message, politeness ) {
		if ( wp.a11y && wp.a11y.speak ) {
			wp.a11y.speak( message, politeness || 'polite' );
		}
	}

	function message_of( error ) {
		return error && error.message ? error.message : __( 'That did not work. Try again.', 'estasoft-news-distribution' );
	}

	function icon( kind ) {
		var path = {
			pass: 'M5.2 8.2l2 2 3.6-4',
			warn: 'M8 6.4v3M8 11.4v.1',
			block: 'M5.8 5.8l4.4 4.4M10.2 5.8l-4.4 4.4',
			na: 'M5 8h6'
		}[ kind ] || 'M5 8h6';
		var outline = 'warn' === kind ? 'M8 2.3l6.2 11H1.8z' : 'M8 1.5a6.5 6.5 0 1 0 0 13a6.5 6.5 0 0 0 0-13z';

		return el(
			'svg',
			{ className: 'estasoft-nd-gate__icon estasoft-nd-gate__icon--' + kind, viewBox: '0 0 16 16', width: 16, height: 16, 'aria-hidden': 'true', focusable: 'false' },
			el( 'path', { d: outline } ),
			el( 'path', { d: path } )
		);
	}

	/* ---------- One line for a required channel ---------- */

	function ChannelLine( props ) {
		var line = props.line;
		var kind = { pass: 'pass', warn: 'warn', block: 'block', na: 'na' }[ line.status ] || 'na';
		var words = {
			pass: __( 'Passes', 'estasoft-news-distribution' ),
			warn: __( 'Risk', 'estasoft-news-distribution' ),
			block: 'block' === boot.mode ? __( 'Blocks publishing', 'estasoft-news-distribution' ) : __( 'Does not pass', 'estasoft-news-distribution' ),
			na: __( 'Does not apply', 'estasoft-news-distribution' )
		}[ kind ];

		return el(
			'li',
			{ className: 'estasoft-nd-gate__line' },
			icon( kind ),
			el( 'span', { className: 'estasoft-nd-gate__text' },
				el( 'span', { className: 'screen-reader-text' }, words + '. ' ),
				el( 'strong', null, line.label + ': ' ),
				line.message
			)
		);
	}

	/* ---------- What the open sidebar shows ---------- */

	function Body( props ) {
		var postId = boot.postId;
		var stateHook = useState( { phase: 'loading', data: null, error: '' } );
		var state = stateHook[ 0 ];
		var setState = stateHook[ 1 ];
		var reasonHook = useState( '' );
		var reason = reasonHook[ 0 ];
		var setReason = reasonHook[ 1 ];
		var problemHook = useState( '' );
		var problem = problemHook[ 0 ];
		var setProblem = problemHook[ 1 ];
		var busyHook = useState( false );
		var busy = busyHook[ 0 ];
		var setBusy = busyHook[ 1 ];
		var reasonRef = useRef( null );

		var editor = useSelect( function ( select ) {
			var store = select( 'core/editor' );
			var post = store.getCurrentPost ? store.getCurrentPost() : null;

			return {
				dirty: store.isEditedPostDirty(),
				status: store.getCurrentPostAttribute( 'status' ),
				scheduled: store.isEditedPostBeingScheduled(),
				visibility: store.getEditedPostVisibility ? store.getEditedPostVisibility() : 'public',
				canPublish: ! post || ! post._links || !! post._links[ 'wp:action-publish' ]
			};
		}, [] );
		var dispatch = useDispatch( 'core/editor' );

		var data = state.data;
		var blocked = !! ( data && data.blocked );
		var live = 'publish' === editor.status || 'future' === editor.status || 'private' === editor.status;
		// A private article is not public, so the server does not hold it back and neither does the panel.
		var locking = 'block' === boot.mode && blocked && editor.canPublish && ! live && 'private' !== editor.visibility;

		function run() {
			setState( function ( now ) {
				return { phase: 'loading', data: now.data, error: '' };
			} );

			return wp.apiFetch( { path: BASE + postId + '/gate?run=1' } ).then(
				function ( result ) {
					setState( { phase: 'ready', data: result, error: '' } );
					say( result.blocked
						? __( 'Publishing is on hold: a channel does not pass.', 'estasoft-news-distribution' )
						: __( 'The article passes the channels that must pass.', 'estasoft-news-distribution' ) );
				},
				function ( error ) {
					setState( { phase: 'error', data: null, error: message_of( error ) } );
				}
			);
		}

		function saveAndRun() {
			setBusy( true );

			Promise.resolve( dispatch.savePost() ).then( run ).then( function () {
				setBusy( false );
			}, function () {
				setBusy( false );
			} );
		}

		// Check when the sidebar opens.
		useEffect( function () {
			run();
		}, [] );

		// Hold the Publish button back while the gate blocks, and let go when the sidebar closes.
		useEffect( function () {
			if ( ! locking ) {
				return undefined;
			}

			dispatch.lockPostSaving( LOCK );

			return function () {
				dispatch.unlockPostSaving( LOCK );
			};
		}, [ locking ] );

		function publish() {
			var status = 'publish';

			if ( 'private' === editor.visibility ) {
				status = 'private';
			} else if ( editor.scheduled ) {
				status = 'future';
			}

			dispatch.unlockPostSaving( LOCK );
			dispatch.editPost( { status: status } );

			return dispatch.savePost();
		}

		// After a save that did not publish, hold the Publish button back again, unless the article went live.
		function relock() {
			var status = wp.data.select( 'core/editor' ).getCurrentPostAttribute( 'status' );

			if ( locking && 'publish' !== status && 'future' !== status && 'private' !== status ) {
				dispatch.lockPostSaving( LOCK );
			}
		}

		// The server's answer when it refused the save, as the editor recorded it.
		function refusal() {
			var core = wp.data.select( 'core' );
			var editorStore = wp.data.select( 'core/editor' );

			if ( ! core || ! core.getLastEntitySaveError || ! editorStore.getCurrentPostType ) {
				return '';
			}

			var error = core.getLastEntitySaveError( 'postType', editorStore.getCurrentPostType(), postId );

			return error && error.message ? String( error.message ) : '';
		}

		function publishAnyway() {
			var typed = reason.trim();

			if ( boot.reasonRequired && typed.length < boot.reasonMin ) {
				setProblem( sprintf(
					/* translators: %d: the least number of characters. */
					__( 'Write a reason of at least %d characters to publish anyway.', 'estasoft-news-distribution' ),
					boot.reasonMin
				) );
				say( __( 'Write a reason to publish anyway.', 'estasoft-news-distribution' ), 'assertive' );

				if ( reasonRef.current && reasonRef.current.focus ) {
					reasonRef.current.focus();
				}

				return;
			}

			setProblem( '' );
			setBusy( true );
			say( __( 'Publishing with your reason.', 'estasoft-news-distribution' ) );

			armed = typed;

			Promise.resolve( publish() ).then( function () {
				armed = null;
				setBusy( false );

				var refused = refusal();

				if ( refused ) {
					setProblem( refused );
					say( refused, 'assertive' );
				}

				relock();
				run();
			}, function () {
				armed = null;
				setBusy( false );
				relock();
			} );
		}

		/* ---------- The content ---------- */

		var children = [];

		if ( 'loading' === state.phase && ! data ) {
			children.push( el( 'p', { key: 'loading', className: 'estasoft-nd-gate__muted', role: 'status' }, __( 'Checking the article…', 'estasoft-news-distribution' ) ) );
		}

		if ( 'error' === state.phase ) {
			children.push(
				el( 'p', { key: 'error', className: 'estasoft-nd-gate__problem', role: 'alert' }, state.error ),
				el( Button, { key: 'retry', variant: 'secondary', onClick: run }, __( 'Check again', 'estasoft-news-distribution' ) )
			);
		}

		if ( data ) {
			var required = 'block' === boot.mode
				? __( 'An administrator requires these channels to pass, in Settings, Access and gate.', 'estasoft-news-distribution' )
				: __( 'An administrator asks these channels to pass, in Settings, Access and gate. This does not stop you from publishing.', 'estasoft-news-distribution' );

			if ( ! boot.hasRequirements ) {
				required = __( 'No channel is chosen yet. An administrator picks the channels that must pass in Settings, Access and gate.', 'estasoft-news-distribution' );
			}

			children.push( el( 'p', { key: 'required', className: 'estasoft-nd-gate__muted' }, required ) );

			if ( data.channels.length ) {
				children.push( el( 'ul', { key: 'lines', className: 'estasoft-nd-gate__lines' }, data.channels.map( function ( line ) {
					return el( ChannelLine, { key: line.id, line: line } );
				} ) ) );
			}

			if ( blocked && 'block' === boot.mode ) {
				children.push( el(
					'div',
					{ key: 'hold', className: 'estasoft-nd-gate__hold', role: 'alert' },
					el( 'strong', null, icon( 'warn' ), __( 'Publishing is on hold', 'estasoft-news-distribution' ) ),
					el( 'span', null, sprintf(
						/* translators: %s: channel names such as "Google News, Apple News". */
						_n( '%s does not pass yet. Fix the problem and check again, or publish anyway with a reason below.', '%s do not pass yet. Fix the problems and check again, or publish anyway with a reason below.', data.failing.length, 'estasoft-news-distribution' ),
						data.failing.join( ', ' )
					) )
				) );
			} else if ( data.failing.length ) {
				children.push( el(
					'div',
					{ key: 'warn', className: 'estasoft-nd-gate__hold estasoft-nd-gate__hold--soft' },
					el( 'strong', null, __( 'Not blocking', 'estasoft-news-distribution' ) ),
					el( 'span', null, sprintf(
						/* translators: %s: channel names. */
						_n( '%s does not pass yet. You can still publish.', '%s do not pass yet. You can still publish.', data.failing.length, 'estasoft-news-distribution' ),
						data.failing.join( ', ' )
					) )
				) );
			}

			if ( data.others.length ) {
				children.push(
					el( 'p', { key: 'others', className: 'estasoft-nd-gate__muted' }, sprintf(
						/* translators: %d: how many other problems. */
						_n( '%d other thing to fix does not block publishing:', '%d other things to fix do not block publishing:', data.others.length, 'estasoft-news-distribution' ),
						data.others.length
					) ),
					el( 'ul', { key: 'others-list', className: 'estasoft-nd-gate__others' }, data.others.map( function ( other, index ) {
						return el( 'li', { key: other.code + index }, other.message );
					} ) )
				);
			}

			if ( editor.dirty ) {
				children.push( el( 'p', { key: 'dirty', className: 'estasoft-nd-gate__muted' }, __( 'This check reads the saved article, and you have changes that are not saved yet.', 'estasoft-news-distribution' ) ) );
			}

			children.push( el(
				Button,
				{ key: 'again', variant: 'secondary', isBusy: busy || 'loading' === state.phase, disabled: busy || 'loading' === state.phase, onClick: editor.dirty ? saveAndRun : run },
				editor.dirty ? __( 'Save draft and check again', 'estasoft-news-distribution' ) : __( 'Check again', 'estasoft-news-distribution' )
			) );

			if ( blocked && 'block' === boot.mode && boot.canOverride ) {
				children.push( el(
					'div',
					{ key: 'anyway', className: 'estasoft-nd-gate__anyway' },
					el( 'strong', null, __( 'Publish anyway', 'estasoft-news-distribution' ) ),
					el( 'p', { className: 'estasoft-nd-gate__muted' }, __( 'Editors and administrators can do this. Your reason is saved in the delivery log with your name.', 'estasoft-news-distribution' ) ),
					el( TextControl, {
						label: boot.reasonRequired ? __( 'Reason (required)', 'estasoft-news-distribution' ) : __( 'Reason (optional)', 'estasoft-news-distribution' ),
						value: reason,
						placeholder: __( 'For the delivery log', 'estasoft-news-distribution' ),
						maxLength: boot.reasonMax,
						ref: reasonRef,
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setReason( value );
							setProblem( '' );
						},
						help: boot.reasonRequired
							? sprintf(
								/* translators: %d: the least number of characters. */
								__( 'At least %d characters. The article goes out as it is, and each platform decides what to do with it. Pushes already sent cannot be taken back.', 'estasoft-news-distribution' ),
								boot.reasonMin
							)
							: __( 'The article goes out as it is, and each platform decides what to do with it. Pushes already sent cannot be taken back.', 'estasoft-news-distribution' )
					} ),
					problem ? el( 'p', { className: 'estasoft-nd-gate__problem', role: 'alert' }, problem ) : null,
					el( Button, { variant: 'secondary', isDestructive: true, isBusy: busy, disabled: busy, onClick: publishAnyway }, __( 'Publish anyway', 'estasoft-news-distribution' ) )
				) );
			} else if ( blocked && 'block' === boot.mode ) {
				children.push( el( 'p', { key: 'nobody', className: 'estasoft-nd-gate__muted' }, __( 'You cannot publish anyway. Fix the problem above, or ask an editor or administrator.', 'estasoft-news-distribution' ) ) );
			}
		}

		// The limit is always on the screen.
		children.push( el( 'p', { key: 'limits', className: 'estasoft-nd-gate__limits' }, boot.limits ) );

		return el( 'div', { className: 'estasoft-nd-gate__body' }, children );
	}

	/* ---------- The plugin ---------- */

	function Gate() {
		return el(
			Panel,
			{ title: __( 'News Distribution', 'estasoft-news-distribution' ), initialOpen: true, className: 'estasoft-nd-gate' },
			el( Fragment, null, el( Body, null ) )
		);
	}

	wp.plugins.registerPlugin( 'estasoft-nd-gate', { render: Gate } );
}( window.wp ) );
