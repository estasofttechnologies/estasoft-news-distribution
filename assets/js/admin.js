/*
 * Estasoft News Distribution admin script.
 *
 * Plain JavaScript, no build step. Every screen works without it: forms post to
 * admin-post.php and the server prints the result. With the script, settings
 * save through the REST API without a page load, rows that depend on another
 * setting show and hide as it changes, and the save bar appears when something
 * is edited.
 */
( function () {
	'use strict';

	var cfg = window.estasoftND || { rest: '', nonce: '', text: {} };
	var text = cfg.text || {};
	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	root.classList.add( 'has-js' );

	/* ---------- helpers ---------- */

	function all( selector, scope ) {
		return Array.prototype.slice.call( ( scope || root ).querySelectorAll( selector ) );
	}

	/**
	 * Reads a form into the nested object the REST API takes. Names look like
	 * estasoft_nd[names][ku] or estasoft_nd[post_types][].
	 */
	function readForm( form ) {
		var data = new FormData( form );
		var out = {};

		data.forEach( function ( value, key ) {
			var match = /^estasoft_nd((?:\[[^\]]*\])+)$/.exec( key );

			if ( ! match ) {
				return;
			}

			var path = match[ 1 ].slice( 1, -1 ).split( '][' );
			var node = out;

			for ( var i = 0; i < path.length; i++ ) {
				var part = path[ i ];
				var last = i === path.length - 1;
				var next = path[ i + 1 ];

				if ( part === '' ) {
					// Name ended with [] : push onto the list.
					if ( ! Array.isArray( node ) ) {
						return;
					}
					node.push( value );
					return;
				}

				if ( last ) {
					node[ part ] = value;
					return;
				}

				if ( next === '' ) {
					if ( ! Array.isArray( node[ part ] ) ) {
						node[ part ] = [];
					}
				} else if ( typeof node[ part ] !== 'object' || node[ part ] === null || Array.isArray( node[ part ] ) ) {
					node[ part ] = {};
				}

				node = node[ part ];
			}
		} );

		return out;
	}

	function snapshot( form ) {
		var parts = [];

		new FormData( form ).forEach( function ( value, key ) {
			if ( typeof value === 'string' ) {
				parts.push( key + '=' + value );
			}
		} );

		return parts.join( '&' );
	}

	/** The value a named field has right now, as the server would read it. */
	function fieldValue( form, name ) {
		var fields = form.elements[ 'estasoft_nd[' + name + ']' ];

		if ( ! fields ) {
			return '';
		}

		// A select has a length (its options) but is one control, not a group of them.
		if ( ! fields.length || 'SELECT' === fields.tagName ) {
			fields = [ fields ];
		}

		var value = '';

		Array.prototype.forEach.call( fields, function ( field ) {
			if ( field.type === 'radio' ) {
				if ( field.checked ) {
					value = field.value;
				}
			} else if ( field.type === 'checkbox' ) {
				value = field.checked ? field.value : '0';
			} else if ( field.type !== 'hidden' || value === '' ) {
				value = field.value;
			}
		} );

		return value;
	}

	/* ---------- toast ---------- */

	var toastTimer = null;

	function toast( message, tone ) {
		var old = root.querySelector( '.estasoft-nd-toast' );

		if ( old ) {
			old.remove();
		}

		var box = document.createElement( 'div' );
		box.className = 'estasoft-nd-toast';
		box.setAttribute( 'role', tone === 'danger' ? 'alert' : 'status' );

		var bar = document.createElement( 'div' );
		bar.className = 'estasoft-nd-message estasoft-nd-message--' + ( tone || 'success' );
		bar.textContent = message;

		box.appendChild( bar );
		root.appendChild( box );

		window.clearTimeout( toastTimer );
		toastTimer = window.setTimeout( function () {
			box.remove();
		}, tone === 'danger' ? 8000 : 3500 );
	}

	/* ---------- rows that depend on another setting ---------- */

	function updateConditions( form ) {
		all( '[data-show-if]', form ).forEach( function ( el ) {
			var rule;

			try {
				rule = JSON.parse( el.getAttribute( 'data-show-if' ) );
			} catch ( e ) {
				return;
			}

			var visible = Object.keys( rule ).every( function ( name ) {
				var wanted = rule[ name ];

				if ( wanted === true ) {
					wanted = '1';
				} else if ( wanted === false ) {
					wanted = '0';
				}

				return fieldValue( form, name ) === String( wanted );
			} );

			el.hidden = ! visible;
		} );
	}

	/* ---------- settings form ---------- */

	function clearErrors( form ) {
		all( '[data-field-error]', form ).forEach( function ( el ) {
			el.hidden = true;
			el.textContent = '';
		} );
		all( '[aria-invalid="true"]', form ).forEach( function ( el ) {
			el.removeAttribute( 'aria-invalid' );
		} );
	}

	function showErrors( form, fields ) {
		var first = null;

		Object.keys( fields ).forEach( function ( path ) {
			var top = path.split( '.' )[ 0 ];
			var slot = form.querySelector( '[data-field-error="' + top + '"]' );
			var input = form.elements[ 'estasoft_nd[' + top + ']' ] || form.querySelector( '[name^="estasoft_nd[' + top + '"]' );

			if ( slot ) {
				slot.textContent = fields[ path ];
				slot.hidden = false;
			}

			if ( input ) {
				var target = input.length ? input[ 0 ] : input;
				target.setAttribute( 'aria-invalid', 'true' );
				first = first || target;
			}
		} );

		if ( first && first.focus ) {
			first.focus();
		}
	}

	function initSettingsForm( form ) {
		var bar = form.querySelector( '[data-savebar]' );
		var state = form.querySelector( '[data-savebar-state]' );
		var discard = form.querySelector( '[data-savebar-discard]' );
		var submit = bar ? bar.querySelector( 'button[type="submit"]' ) : null;
		var baseline = snapshot( form );
		var savedView = fieldValue( form, 'view' );
		var busy = false;

		function isDirty() {
			return snapshot( form ) !== baseline;
		}

		function refresh() {
			var dirty = isDirty();

			if ( bar ) {
				bar.classList.toggle( 'is-dirty', dirty );
			}
			if ( state ) {
				state.hidden = ! dirty;
			}
			if ( discard ) {
				discard.hidden = ! dirty;
			}
		}

		form.addEventListener( 'input', function () {
			updateConditions( form );
			refresh();
		} );
		form.addEventListener( 'change', function () {
			updateConditions( form );
			refresh();
		} );

		if ( discard ) {
			discard.addEventListener( 'click', function () {
				form.reset();
				clearErrors( form );
				updateConditions( form );
				refresh();
			} );
		}

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! busy && isDirty() ) {
				event.preventDefault();
				event.returnValue = text.leave || '';
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			if ( ! window.fetch || ! cfg.rest ) {
				return; // The browser posts the form the plain way.
			}

			event.preventDefault();

			if ( busy ) {
				return;
			}

			busy = true;
			clearErrors( form );

			var label = submit ? submit.textContent : '';

			if ( submit ) {
				submit.disabled = true;
				submit.textContent = text.saving || '…';
			}

			window.fetch( cfg.rest + ( form.getAttribute( 'data-endpoint' ) || 'settings/' + encodeURIComponent( form.getAttribute( 'data-section' ) ) ), {
				method: 'PUT',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify( readForm( form ) )
			} ).then( function ( response ) {
				return response.json().then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} ).then( function ( result ) {
				if ( result.ok ) {
					baseline = snapshot( form );
					refresh();
					toast( text.saved || 'Saved.', 'success' );

					// The app bar depends on the chosen view, so it has to be drawn again.
					if ( fieldValue( form, 'view' ) !== savedView ) {
						window.location.reload();
					}
					return;
				}

				var fields = result.body && result.body.data && result.body.data.fields;

				if ( fields ) {
					showErrors( form, fields );
				}

				toast( ( result.body && result.body.message ) || text.failed || 'Could not save.', 'danger' );
			} ).catch( function () {
				toast( text.offline || 'Could not reach your site.', 'danger' );
			} ).then( function () {
				busy = false;

				if ( submit ) {
					submit.disabled = false;
					submit.textContent = label;
				}
			} );
		} );

		updateConditions( form );
		refresh();
	}

	all( 'form[data-settings-form]' ).forEach( initSettingsForm );


	/* ---------- Dialogs ---------- */

	var openDialog = null;
	var dialogTrigger = null;

	function closeDialog() {
		if ( openDialog ) {
			openDialog.hidden = true;
			openDialog = null;
		}
		if ( dialogTrigger && dialogTrigger.focus ) {
			dialogTrigger.focus();
		}
		dialogTrigger = null;
	}

	root.addEventListener( 'click', function ( event ) {
		var opener = event.target.closest ? event.target.closest( '[data-open-dialog]' ) : null;

		if ( opener ) {
			var dialog = document.getElementById( opener.getAttribute( 'data-open-dialog' ) );

			if ( dialog ) {
				event.preventDefault();
				dialogTrigger = opener;
				openDialog = dialog;
				dialog.hidden = false;
				var focusable = dialog.querySelector( 'button, a[href], input, select' );
				if ( focusable ) {
					focusable.focus();
				}
			}
			return;
		}

		if ( event.target.closest && event.target.closest( '[data-close-dialog]' ) ) {
			closeDialog();
		} else if ( openDialog && event.target === openDialog ) {
			closeDialog();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' && openDialog ) {
			closeDialog();
		}
	} );

	/* ---------- More menu ---------- */

	var menus = all( 'details[data-more]' );

	document.addEventListener( 'click', function ( event ) {
		menus.forEach( function ( menu ) {
			if ( menu.open && ! menu.contains( event.target ) ) {
				menu.open = false;
			}
		} );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Escape' ) {
			return;
		}

		menus.forEach( function ( menu ) {
			if ( menu.open ) {
				menu.open = false;
				var summary = menu.querySelector( 'summary' );
				if ( summary ) {
					summary.focus();
				}
			}
		} );
	} );
}() );
