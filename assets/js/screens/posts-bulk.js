/*
 * News Distribution on the Posts list.
 *
 * The bulk actions, the row action and the notice work without this script. Here:
 *
 * 1. A run that is still going is followed (`GET /jobs/{id}`). When it ends the page loads again with the
 *    job in the address, so the notice and the News Distribution column show the result.
 * 2. Choosing "Hold partner feeds" asks why, in a dialog, before the form is sent. Without the script the
 *    server answers with a notice that says a reason is needed.
 * 3. The Quick Edit "Hold partner feeds" field is filled from the row's hidden data, shows its reason
 *    field when it is checked, and stops the Update until the reason is written.
 */
( function () {
	'use strict';

	var cfg = ( window.estasoftNDScreens || {} )[ 'posts-bulk' ];

	if ( ! cfg ) {
		return;
	}

	var text = cfg.text || {};

	function fmt( template, first, second ) {
		return String( template ).replace( '%1$d', first ).replace( '%2$d', second );
	}

	function read( scope, selector ) {
		var found = scope.querySelector( selector );

		return found ? found.textContent : '';
	}

	/* ---------- 1. Follow a running job ---------- */

	var notice = document.querySelector( '.estasoft-nd-bulk-notice[data-job]' );
	var state = notice ? notice.getAttribute( 'data-state' ) : '';

	if ( notice && ( 'queued' === state || 'running' === state ) ) {
		var job = notice.getAttribute( 'data-job' );
		var again = notice.getAttribute( 'data-url' ) || window.location.href;
		var line = notice.querySelector( '.estasoft-nd-bulk-notice__progress' );
		var bar = notice.querySelector( '.estasoft-nd-bulk-notice__bar' );
		var tries = 0;

		var tell = function ( message ) {
			if ( line ) {
				line.textContent = message;
			}
		};

		var tick = function () {
			tries += 1;

			if ( tries > 400 ) {
				tell( text.still || '' );

				return;
			}

			window.fetch( cfg.rest + 'jobs/' + encodeURIComponent( job ), {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.nonce }
			} ).then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} ).then( function ( result ) {
				if ( ! result.ok || 'done' === result.body.state || 'failed' === result.body.state ) {
					window.location.assign( again );

					return;
				}

				tell( fmt( text.progress || '%1$d / %2$d', result.body.done, result.body.total ) );

				if ( bar ) {
					bar.value = result.body.percent;
				}

				window.setTimeout( tick, 1500 );
			} ).catch( function () {
				tell( text.lost || '' );
			} );
		};

		window.setTimeout( tick, 1200 );
	}

	/* ---------- 2. Ask why before a bulk hold ---------- */

	var form = document.getElementById( 'posts-filter' );
	var dialog = document.getElementById( 'estasoft-nd-hold-dialog' );

	if ( form && dialog ) {
		var input = dialog.querySelector( 'input' );
		var problem = dialog.querySelector( '.estasoft-nd-bulk-dialog__error' );
		var ok = dialog.querySelector( '[data-estasoft-nd-ok]' );
		var cancel = dialog.querySelector( '[data-estasoft-nd-cancel]' );
		var opener = null;

		var chosen = function () {
			var top = document.getElementById( 'bulk-action-selector-top' );
			var bottom = document.getElementById( 'bulk-action-selector-bottom' );

			if ( top && '-1' !== top.value ) {
				return top.value;
			}

			return bottom ? bottom.value : '';
		};

		var close = function () {
			dialog.hidden = true;
			document.removeEventListener( 'keydown', keys, true );

			if ( opener && opener.focus ) {
				opener.focus();
			}
		};

		var send = function () {
			var reason = input.value.trim();

			if ( '' === reason ) {
				problem.hidden = false;
				input.setAttribute( 'aria-invalid', 'true' );
				input.focus();

				return;
			}

			var hidden = form.querySelector( 'input[name="' + cfg.field + '"]' );

			if ( ! hidden ) {
				hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = cfg.field;
				form.appendChild( hidden );
			}

			hidden.value = reason;
			dialog.hidden = true;
			document.removeEventListener( 'keydown', keys, true );
			form.submit();
		};

		var keys = function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				close();

				return;
			}

			if ( 'Enter' === event.key && event.target === input ) {
				event.preventDefault();
				send();

				return;
			}

			if ( 'Tab' === event.key ) {
				var stops = [ input, ok, cancel ];
				var at = stops.indexOf( document.activeElement );
				var next = event.shiftKey ? ( at <= 0 ? stops.length - 1 : at - 1 ) : ( at + 1 ) % stops.length;

				event.preventDefault();
				stops[ next ].focus();
			}
		};

		form.addEventListener( 'submit', function ( event ) {
			if ( chosen() !== cfg.hold ) {
				return;
			}

			var filled = form.querySelector( 'input[name="' + cfg.field + '"]' );

			if ( filled && filled.value.trim() ) {
				return;
			}

			if ( ! form.querySelector( 'input[name="post[]"]:checked' ) ) {
				return;
			}

			event.preventDefault();
			opener = event.submitter || document.activeElement;
			input.value = '';
			input.removeAttribute( 'aria-invalid' );
			problem.hidden = true;
			dialog.hidden = false;
			document.addEventListener( 'keydown', keys, true );
			input.focus();
		} );

		input.addEventListener( 'input', function () {
			problem.hidden = true;
			input.removeAttribute( 'aria-invalid' );
		} );
		ok.addEventListener( 'click', send );
		cancel.addEventListener( 'click', close );
		dialog.addEventListener( 'mousedown', function ( event ) {
			if ( event.target === dialog ) {
				close();
			}
		} );
	}

	/* ---------- 3. Quick Edit ---------- */

	var edit = window.inlineEditPost;

	if ( edit && edit.edit && edit.save ) {
		var parts = function ( id ) {
			var row = document.getElementById( 'edit-' + id );
			var box = row ? row.querySelector( '.estasoft-nd-qe' ) : null;

			if ( ! box ) {
				return null;
			}

			return {
				row: row,
				box: box,
				hold: box.querySelector( '.estasoft-nd-qe__hold' ),
				check: box.querySelector( 'input[name="estasoft_nd_hold"]' ),
				why: box.querySelector( '.estasoft-nd-qe__reason' ),
				reason: box.querySelector( 'input[name="estasoft_nd_hold_reason"]' ),
				error: box.querySelector( '.estasoft-nd-qe__error' )
			};
		};

		var idOf = function ( ref ) {
			return 'object' === typeof ref ? edit.getId( ref ) : ref;
		};

		var shows = function ( p ) {
			p.why.hidden = ! p.check.checked;

			if ( ! p.check.checked ) {
				p.error.hidden = true;
				p.reason.removeAttribute( 'aria-invalid' );
			}
		};

		var baseEdit = edit.edit;
		var baseSave = edit.save;

		edit.edit = function ( ref ) {
			var result = baseEdit.apply( this, arguments );
			var id = idOf( ref );
			var p = parts( id );
			var data = document.getElementById( 'inline_' + id );

			if ( ! p || ! data ) {
				return result;
			}

			var kind = read( data, '.estasoft_nd_hold_state' );

			p.hold.hidden = 'held' !== kind && 'free' !== kind;
			p.box.hidden = p.hold.hidden && ! p.box.querySelector( '.estasoft-nd-qe__extra' );
			p.check.checked = 'held' === kind;
			p.reason.value = read( data, '.estasoft_nd_hold_reason' );
			p.error.hidden = true;
			shows( p );

			p.check.addEventListener( 'change', function () {
				shows( p );

				if ( p.check.checked ) {
					p.reason.focus();
				}
			} );

			p.reason.addEventListener( 'input', function () {
				p.error.hidden = true;
				p.reason.removeAttribute( 'aria-invalid' );
			} );

			return result;
		};

		edit.save = function ( ref ) {
			var p = parts( idOf( ref ) );

			if ( p && ! p.hold.hidden && p.check.checked && '' === p.reason.value.trim() ) {
				p.error.hidden = false;
				p.reason.setAttribute( 'aria-invalid', 'true' );
				p.reason.focus();

				return false;
			}

			return baseSave.apply( this, arguments );
		};
	}
}() );
