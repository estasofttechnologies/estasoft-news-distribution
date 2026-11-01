/*
 * Operations screens: Connections, Cache purge, Alerts, Privacy and data, Backup and uninstall.
 *
 * Every button on these screens posts a form, so nothing here is needed to use them. The script
 * only makes the two test buttons run without leaving the page (so unsaved changes in the form
 * stay), copies the suggested policy text, and writes the section name on the Reset button.
 */
( function () {
	'use strict';

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var data = ( window.estasoftNDScreens || {} ).operations || {};
	var text = data.text || {};
	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	/* ---------- the two test buttons ---------- */

	function showError( slot, message ) {
		if ( ! slot ) {
			return;
		}

		slot.textContent = message;
	}

	function runTest( button ) {
		var route = button.getAttribute( 'data-ops-test' );
		var holder = button.parentNode;
		var slot = holder ? holder.querySelector( '[data-result]' ) : null;
		var label = button.textContent;

		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		button.textContent = text.working || '…';

		window.fetch( cfg.rest + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: '{}'
		} ).then( function ( response ) {
			return response.json().then( function ( body ) {
				return { ok: response.ok, body: body };
			} );
		} ).then( function ( result ) {
			if ( ! result.ok ) {
				showError( slot, ( result.body && result.body.message ) || text.offline || '' );
				return;
			}

			if ( slot && result.body.html ) {
				slot.innerHTML = result.body.html;
			}

			var log = result.body.log_html ? document.getElementById( 'estasoft-nd-cache-log' ) : null;

			if ( log ) {
				log.innerHTML = result.body.log_html;
			}
		} ).catch( function () {
			showError( slot, text.offline || '' );
		} ).then( function () {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
			button.textContent = label;
		} );
	}

	root.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '[data-ops-test]' ) : null;

		if ( ! button || ! window.fetch || ! cfg.rest ) {
			return; // The button posts the form the plain way.
		}

		event.preventDefault();

		if ( ! button.disabled ) {
			runTest( button );
		}
	} );

	/* ---------- copy the policy text ---------- */

	Array.prototype.forEach.call( root.querySelectorAll( '[data-copy-from]' ), function ( button ) {
		var source = document.getElementById( button.getAttribute( 'data-copy-from' ) );
		var note = document.getElementById( 'estasoft-nd-policy-result' );

		if ( ! source || ! navigator.clipboard || ! navigator.clipboard.writeText ) {
			return; // Without a clipboard the text stays selectable and the button stays hidden.
		}

		button.hidden = false;

		button.addEventListener( 'click', function () {
			navigator.clipboard.writeText( source.innerText ).then( function () {
				if ( note ) {
					note.textContent = text.copied || '';
				}
			}, function () {
				if ( note ) {
					note.textContent = text.failed || '';
				}
			} );
		} );
	} );

	/* ---------- the Reset button names the section ---------- */

	Array.prototype.forEach.call( root.querySelectorAll( 'select[data-reset-label]' ), function ( select ) {
		var button = document.querySelector( select.getAttribute( 'data-reset-label' ) );

		function refresh() {
			var option = select.options[ select.selectedIndex ];

			if ( button && option && text.reset ) {
				button.textContent = text.reset.replace( '%s', option.textContent );
			}
		}

		select.addEventListener( 'change', refresh );
		refresh();
	} );

	/* ---------- a connection check can take a few seconds: show it, and do not send it twice ---------- */

	Array.prototype.forEach.call( root.querySelectorAll( 'form[data-ops-form]' ), function ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			var submitter = event.submitter;

			window.setTimeout( function () {
				Array.prototype.forEach.call( form.querySelectorAll( 'button' ), function ( button ) {
					button.disabled = true;
				} );

				if ( submitter && text.checking ) {
					submitter.textContent = text.checking;
				}
			}, 0 );
		} );
	} );
}() );
