/*
 * Channels hub: switches that save in place and a search that filters as you type.
 *
 * Without this script every switch is a "Turn on" or "Turn off" button in its
 * own form, and the search is a plain form. Here the same form is posted in the
 * background, so the server runs the same code either way, and the list is
 * replaced with what the server printed after the change.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );
	var search = root ? root.querySelector( '[data-hub-search]' ) : null;

	if ( ! root || ! search ) {
		return;
	}

	var screen = ( window.estasoftNDScreens || {} ).hub || {};
	var text = screen.text || {};
	var status = root.querySelector( '[data-hub-status]' );

	function fill( template, values ) {
		var list = Array.isArray( values ) ? values : [ values ];

		return String( template || '' ).replace( /%(?:(\d)\$)?s/g, function ( match, position ) {
			var index = position ? parseInt( position, 10 ) - 1 : 0;

			return list[ index ] === undefined ? '' : list[ index ];
		} );
	}

	function say( message ) {
		if ( ! status ) {
			return;
		}

		status.textContent = '';
		window.setTimeout( function () {
			status.textContent = message;
		}, 50 );
	}

	function all( selector, scope ) {
		return Array.prototype.slice.call( ( scope || root ).querySelectorAll( selector ) );
	}

	/* The switches are printed disabled; the buttons beside them are the way in until now. */
	function enable() {
		all( '[data-hub-toggle]' ).forEach( function ( input ) {
			input.disabled = false;
		} );
	}

	/* ---------- search ---------- */

	function narrow() {
		var query = search.value.trim().toLowerCase();
		var note = root.querySelector( '[data-hub-note]' );
		var shown = 0;

		all( '[data-hub-row]' ).forEach( function ( row ) {
			var match = query === '' || ( row.getAttribute( 'data-search' ) || '' ).indexOf( query ) !== -1;

			row.hidden = ! match;

			if ( match ) {
				shown++;
			}
		} );

		all( '[data-hub-group]' ).forEach( function ( group ) {
			var details = group.querySelector( 'details' );
			var matches = all( '[data-hub-row]:not([hidden])', group ).length;

			group.hidden = matches === 0;

			if ( ! details ) {
				return;
			}

			if ( query !== '' ) {
				if ( ! details.hasAttribute( 'data-was-open' ) ) {
					details.setAttribute( 'data-was-open', details.open ? 'true' : 'false' );
				}
				details.open = matches > 0;
			} else if ( details.hasAttribute( 'data-was-open' ) ) {
				details.open = details.getAttribute( 'data-was-open' ) === 'true';
				details.removeAttribute( 'data-was-open' );
			}
		} );

		if ( note ) {
			note.textContent = fill( text.showing, [ shown, note.getAttribute( 'data-hub-total' ) || shown ] );
		}

		// Keep the filter links on the same search.
		all( '.estasoft-nd-hub-filter a' ).forEach( function ( link ) {
			try {
				var url = new URL( link.href, window.location.href );

				if ( query === '' ) {
					url.searchParams.delete( 'q' );
				} else {
					url.searchParams.set( 'q', search.value.trim() );
				}

				link.href = url.toString();
			} catch ( error ) {
				// The link keeps its address.
			}
		} );
	}

	search.addEventListener( 'input', narrow );

	/* ---------- switches ---------- */

	function openStates() {
		var states = {};

		all( '[data-hub-group]' ).forEach( function ( group ) {
			var details = group.querySelector( 'details' );

			if ( details ) {
				states[ group.getAttribute( 'data-hub-group' ) ] = {
					open: details.hasAttribute( 'data-was-open' ) ? details.getAttribute( 'data-was-open' ) === 'true' : details.open,
					quiet: details.getAttribute( 'data-quiet' )
				};
			}
		} );

		return states;
	}

	/* Puts the list, the filter counts and the message from the page the server printed into this one. */
	function show( doc, states, focusId ) {
		var body = root.querySelector( '[data-hub-body]' );
		var fresh = doc.querySelector( '[data-hub-body]' );
		var bar = root.querySelector( '.estasoft-nd-hub-filter' );
		var freshBar = doc.querySelector( '.estasoft-nd-hub-filter' );
		var page = root.querySelector( '.estasoft-nd-page' );
		var freshPage = doc.querySelector( '.estasoft-nd-page' );

		if ( ! body || ! fresh ) {
			return false;
		}

		body.replaceWith( document.importNode( fresh, true ) );

		if ( bar && freshBar ) {
			bar.innerHTML = freshBar.innerHTML;
		}

		if ( page && freshPage ) {
			var oldNotice = page.querySelector( ':scope > .estasoft-nd-message' );
			var newNotice = freshPage.querySelector( ':scope > .estasoft-nd-message' );

			if ( oldNotice && newNotice ) {
				oldNotice.replaceWith( document.importNode( newNotice, true ) );
			} else if ( oldNotice ) {
				oldNotice.remove();
			} else if ( newNotice ) {
				var header = page.querySelector( ':scope > .estasoft-nd-page-header' );

				if ( header ) {
					header.after( document.importNode( newNotice, true ) );
				}
			}
		}

		all( '[data-hub-group]' ).forEach( function ( group ) {
			var details = group.querySelector( 'details' );
			var before = states[ group.getAttribute( 'data-hub-group' ) ];

			// A group keeps the way the person left it, unless it now needs them or no longer does.
			if ( details && before && before.quiet === details.getAttribute( 'data-quiet' ) ) {
				details.open = before.open;
			}
		} );

		enable();
		narrow();

		var again = focusId ? root.querySelector( '[data-hub-toggle="' + focusId + '"]' ) : null;

		if ( again ) {
			again.focus();
		}

		return true;
	}

	function change( input ) {
		var form = input.closest( 'form' );
		var id = input.getAttribute( 'data-hub-toggle' ) || '';
		var name = input.getAttribute( 'data-name' ) || id;
		var wanted = input.checked;
		var data;

		if ( ! form ) {
			return;
		}

		data = new FormData( form );
		data.set( 'turn', wanted ? 'on' : 'off' );
		data.set( 'q', search.value.trim() );

		input.disabled = true;
		input.setAttribute( 'aria-busy', 'true' );

		function undo( message ) {
			input.checked = ! wanted;
			input.disabled = false;
			input.removeAttribute( 'aria-busy' );
			say( message );
		}

		window.fetch( form.action, { method: 'POST', body: data, credentials: 'same-origin' } ).then( function ( response ) {
			return response.text().then( function ( html ) {
				return { ok: response.ok, url: response.url, html: html };
			} );
		} ).then( function ( result ) {
			var doc = new DOMParser().parseFromString( result.html, 'text/html' );
			var params;

			try {
				params = new URL( result.url, window.location.href ).searchParams;
			} catch ( error ) {
				params = new URLSearchParams( '' );
			}

			if ( ! result.ok || ! doc.querySelector( '[data-hub-body]' ) ) {
				undo( text.failed );
				return;
			}

			var states = openStates();
			var problem = params.get( 'problem' );

			if ( ! show( doc, states, id ) ) {
				undo( text.failed );
				return;
			}

			if ( problem === 'own_switches' ) {
				say( fill( text.own, name ) );
			} else if ( problem ) {
				say( text.failed );
			} else {
				say( fill( params.get( 'to' ) === 'on' ? text.on : text.off, name ) );
			}
		} ).catch( function () {
			undo( text.offline );
		} );
	}

	root.addEventListener( 'change', function ( event ) {
		var input = event.target;

		if ( input && input.matches && input.matches( '[data-hub-toggle]' ) ) {
			change( input );
		}
	} );

	enable();
	narrow();
}() );
