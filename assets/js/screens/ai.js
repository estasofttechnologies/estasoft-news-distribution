/*
 * The AI assistant's screens.
 *
 * Every form and link here works without this script. The script only adds four conveniences:
 *
 * - A form that asks the provider shows gray placeholders while it waits (`form[data-ai-form]`).
 * - "Send a test request" on the settings page asks in place and prints the answer beside the button
 *   (`[data-ai-test]`, POST /ai/test).
 * - "Copy email" copies the draft and "Open in mail" opens the draft as it is now in the box
 *   (`[data-ai-copy-source]`, `[data-ai-mail]`).
 * - The AI log filters apply when a choice changes (`form[data-ai-filter]`).
 *
 * The script never sends article text anywhere. Asking the provider is done by the server, after the
 * person presses a button and after the confirmation is recorded.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	root.classList.add( 'has-js' );

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var data = ( window.estasoftNDScreens || {} ).ai || {};
	var text = data.text || {};

	var live = document.createElement( 'span' );
	live.className = 'estasoft-nd-sr';
	live.setAttribute( 'role', 'status' );
	root.appendChild( live );

	/* Gray placeholders while the provider is asked. */
	function bindWait( form ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( event.defaultPrevented ) {
				return;
			}

			var wait = form.querySelector( '[data-ai-wait]' );

			form.setAttribute( 'aria-busy', 'true' );

			if ( wait ) {
				wait.hidden = false;
			}

			Array.prototype.forEach.call( form.querySelectorAll( 'button[type="submit"], button:not([type])' ), function ( button ) {
				if ( button.getAttribute( 'data-ai-keep' ) !== '1' ) {
					window.setTimeout( function () {
						button.setAttribute( 'aria-disabled', 'true' );
					}, 0 );
				}
			} );
		} );
	}

	Array.prototype.forEach.call( document.querySelectorAll( 'form[data-ai-form]' ), bindWait );

	/* Coming back with the Back button shows the form ready again. */
	window.addEventListener( 'pageshow', function ( event ) {
		if ( ! event.persisted ) {
			return;
		}

		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-ai-form]' ), function ( form ) {
			var wait = form.querySelector( '[data-ai-wait]' );

			form.removeAttribute( 'aria-busy' );

			if ( wait ) {
				wait.hidden = true;
			}

			Array.prototype.forEach.call( form.querySelectorAll( 'button[aria-disabled="true"]' ), function ( button ) {
				button.removeAttribute( 'aria-disabled' );
			} );
		} );
	} );

	/* A second press while a request is running does nothing. */
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( 'button[aria-disabled="true"]' ) : null;

		if ( button && button.form && button.form.hasAttribute( 'data-ai-form' ) ) {
			event.preventDefault();
		}
	}, true );

	/* "Send a test request". */
	var test = document.querySelector( '[data-ai-test]' );
	var out = document.getElementById( 'estasoft-nd-ai-test-result' );

	if ( test && out && window.fetch && cfg.rest ) {
		test.addEventListener( 'click', function ( event ) {
			event.preventDefault();

			if ( test.getAttribute( 'aria-disabled' ) === 'true' ) {
				return;
			}

			test.setAttribute( 'aria-disabled', 'true' );
			out.textContent = text.working || '';

			window.fetch( cfg.rest + test.getAttribute( 'data-ai-test' ), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: '{}'
			} ).then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body || {} };
				} );
			} ).then( function ( result ) {
				out.textContent = result.body.message || text.offline || '';
				out.setAttribute( 'data-state', result.ok && result.body.ok ? 'ok' : 'failed' );
			} ).catch( function () {
				out.textContent = text.offline || '';
				out.setAttribute( 'data-state', 'failed' );
			} ).then( function () {
				test.removeAttribute( 'aria-disabled' );
			} );
		} );
	}

	/* Copy the email draft, then record the decision. */
	function copyText( value ) {
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

	Array.prototype.forEach.call( document.querySelectorAll( 'textarea[data-ai-copy-source]' ), function ( area ) {
		var form = area.form;

		if ( ! form ) {
			return;
		}

		var submitting = false;

		form.addEventListener( 'submit', function ( event ) {
			var button = event.submitter;

			if ( submitting || ! button || button.value !== 'copy' ) {
				return;
			}

			event.preventDefault();

			var proceed = function () {
				submitting = true;

				if ( form.requestSubmit ) {
					form.requestSubmit( button );
				} else {
					form.submit();
				}
			};

			// If the browser refuses to copy, the decision is still recorded and the text stays in the box.
			copyText( area.value ).then( proceed, proceed );
		}, true );
	} );

	/* "Open in mail" uses the draft as it is now, edits included. */
	Array.prototype.forEach.call( document.querySelectorAll( 'a[data-ai-mail]' ), function ( link ) {
		link.addEventListener( 'click', function () {
			var area = document.querySelector( link.getAttribute( 'data-ai-mail' ) );

			if ( ! area ) {
				return;
			}

			link.setAttribute(
				'href',
				'mailto:?subject=' + encodeURIComponent( link.getAttribute( 'data-subject' ) || '' ) + '&body=' + encodeURIComponent( area.value )
			);
		} );
	} );

	/* Log filters apply when a choice changes. The Apply button stays for the keyboard and for no script. */
	Array.prototype.forEach.call( document.querySelectorAll( 'form[data-ai-filter]' ), function ( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( 'select' ), function ( select ) {
			select.addEventListener( 'change', function () {
				if ( form.requestSubmit ) {
					form.requestSubmit();
				} else {
					form.submit();
				}
			} );
		} );
	} );
}() );
