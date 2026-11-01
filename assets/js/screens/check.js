/*
 * Article check: Run again without a page load, and Copy entry on the feed entry tab.
 *
 * Both work without this script. Run again is a form that posts to the same page, and the entry is
 * plain text in a block a keyboard can scroll. Here Run again posts to the REST route the page and
 * the block editor panel share (POST /articles/{id}/check), then swaps in the rows the server
 * prints, so the screen and the API cannot disagree. If the route refuses or does not answer, the
 * form is sent the plain way.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var screen = ( window.estasoftNDScreens && window.estasoftNDScreens.check ) || {};
	var text = screen.text || {};
	var live = document.createElement( 'span' );

	live.className = 'estasoft-nd-sr';
	live.setAttribute( 'role', 'status' );
	root.appendChild( live );

	function say( message ) {
		// Clearing first makes a screen reader read the same sentence again.
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = message;
		}, 30 );
	}

	function all( selector, scope ) {
		return Array.prototype.slice.call( ( scope || root ).querySelectorAll( selector ) );
	}

	/* ---------- Run again ---------- */

	/**
	 * The page as the server prints it now, without the flag a plain post leaves in the address.
	 */
	function reload() {
		var url = new URL( window.location.href );

		url.searchParams.delete( 'checked' );

		return window.fetch( url.toString(), { credentials: 'same-origin' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'page' );
				}

				return response.text();
			} )
			.then( function ( html ) {
				return new DOMParser().parseFromString( html, 'text/html' );
			} );
	}

	function swap( selector, fresh ) {
		var now = root.querySelector( selector );
		var next = fresh.querySelector( selector );

		if ( now && next ) {
			now.replaceWith( next );
		}
	}

	function run( form ) {
		var button = form.querySelector( '[data-run-check]' );
		var post = button ? button.getAttribute( 'data-post' ) : '';
		var region = root.querySelector( '[data-check-region]' );

		if ( ! window.fetch || ! cfg.rest || ! post || ! region ) {
			form.submit();
			return;
		}

		button.disabled = true;
		region.setAttribute( 'aria-busy', 'true' );
		say( text.running || '' );

		window.fetch( cfg.rest + 'articles/' + encodeURIComponent( post ) + '/check', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
			body: '{}'
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'rest' );
				}

				return reload();
			} )
			.then( function ( fresh ) {
				swap( '[data-check-region]', fresh );
				swap( '.estasoft-nd-listcard', fresh );

				var again = root.querySelector( '[data-run-check]' );

				if ( again ) {
					again.focus();
				}

				say( text.done || '' );
			} )
			.catch( function () {
				// The route said no, or the page did not come back: the plain form still works.
				say( text.failed || '' );
				form.submit();
			} );
	}

	root.addEventListener( 'submit', function ( event ) {
		var form = event.target;

		if ( form && form.classList && form.classList.contains( 'estasoft-nd-runform' ) ) {
			event.preventDefault();
			run( form );
		}
	} );

	/* ---------- Copy entry ---------- */

	function copy( value ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( value );
		}

		return new Promise( function ( resolve, reject ) {
			var area = document.createElement( 'textarea' );

			area.value = value;
			area.setAttribute( 'readonly', '' );
			area.className = 'estasoft-nd-sr';
			document.body.appendChild( area );
			area.select();

			try {
				document.execCommand( 'copy' ) ? resolve() : reject( new Error( 'copy' ) );
			} catch ( error ) {
				reject( error );
			} finally {
				document.body.removeChild( area );
			}
		} );
	}

	all( '[data-copy-entry]' ).forEach( function ( button ) {
		var code = root.querySelector( '[data-entry="code"] code' );

		if ( ! code ) {
			return;
		}

		button.hidden = false;
		button.addEventListener( 'click', function () {
			copy( code.textContent || '' ).then(
				function () {
					say( text.copied || '' );
				},
				function () {
					say( text.copy_error || '' );
				}
			);
		} );
	} );
}() );
