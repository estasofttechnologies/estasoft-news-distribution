/*
 * First-run setup. Every step works without this file, because each step is a form that posts to
 * admin-post.php. The script saves clicks: it lights the chosen card, ticks the channels of a chosen
 * setup, keeps the "0 of 2 selected" counts, and shows a missing name before the page is sent.
 */
( function () {
	'use strict';

	var root = document.querySelector( '.estasoft-nd-setup' );

	if ( ! root ) {
		return;
	}

	function all( selector, scope ) {
		return Array.prototype.slice.call( ( scope || root ).querySelectorAll( selector ) );
	}

	// A chosen card is outlined. The style sheet does it with :has(); this covers browsers without it.
	function lightCards( radio ) {
		all( 'input[type="radio"][name="' + radio.name + '"]' ).forEach( function ( other ) {
			var card = other.closest( '.estasoft-nd-choice' );

			if ( card ) {
				card.classList.toggle( 'estasoft-nd-choice--on', other.checked );
			}
		} );
	}

	all( '.estasoft-nd-choice input[type="radio"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			lightCards( radio );
		} );
	} );

	// Channels: the setup cards tick the boxes, and the counts follow the boxes.
	var form = document.getElementById( 'estasoft-nd-setup-form' );
	var shown = form ? form.querySelector( 'input[name="preset_shown"]' ) : null;

	function boxes() {
		return all( 'input[type="checkbox"][name="channels[]"]' );
	}

	function updateCounts() {
		all( '.estasoft-nd-setup__count[data-group]' ).forEach( function ( count ) {
			var fold = count.closest( 'details' );
			var inside = fold ? all( 'input[type="checkbox"][name="channels[]"]', fold ) : [];
			var on = inside.filter( function ( box ) {
				return box.checked;
			} ).length;
			var pattern = count.getAttribute( 'data-pattern' ) || '';

			count.textContent = pattern.replace( '%1$s', String( on ) ).replace( '%2$s', String( inside.length ) );
		} );
	}

	function chosenPreset() {
		var picked = form ? form.querySelector( 'input[name="preset"]:checked' ) : null;

		return picked ? picked.value : '';
	}

	// Once the script has ticked the boxes, the boxes are what the person means, so the page is told
	// that the boxes match the card that is chosen.
	function boxesDecide() {
		if ( shown ) {
			shown.value = chosenPreset();
		}
	}

	all( 'input[name="preset"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', function () {
			var wanted = ( radio.getAttribute( 'data-channels' ) || '' ).split( ' ' ).filter( Boolean );

			boxes().forEach( function ( box ) {
				box.checked = wanted.indexOf( box.value ) !== -1;
			} );

			boxesDecide();
			updateCounts();
		} );
	} );

	boxes().forEach( function ( box ) {
		box.addEventListener( 'change', function () {
			boxesDecide();
			updateCounts();
		} );
	} );

	// The publication name is required. Say so here, in the same words the server uses.
	var name = document.getElementById( 'estasoft-nd-setup-name' );

	function clearNameError() {
		var existing = document.getElementById( 'estasoft-nd-setup-name-error' );

		if ( existing && existing.parentNode ) {
			existing.parentNode.removeChild( existing );
		}

		if ( name ) {
			name.removeAttribute( 'aria-invalid' );
			name.classList.remove( 'has-error' );
			name.setAttribute( 'aria-describedby', 'estasoft-nd-setup-name-help' );
		}
	}

	if ( form && name ) {
		form.addEventListener( 'submit', function ( event ) {
			if ( name.value.trim() !== '' ) {
				return;
			}

			event.preventDefault();
			clearNameError();

			var message = document.createElement( 'p' );
			var text = document.createElement( 'span' );

			message.className = 'estasoft-nd-field-error';
			message.id = 'estasoft-nd-setup-name-error';
			message.setAttribute( 'role', 'alert' );
			text.textContent = name.getAttribute( 'data-error' ) || '';
			message.appendChild( text );

			name.parentNode.insertBefore( message, name.nextSibling );
			name.setAttribute( 'aria-invalid', 'true' );
			name.classList.add( 'has-error' );
			name.setAttribute( 'aria-describedby', 'estasoft-nd-setup-name-help estasoft-nd-setup-name-error' );
			name.focus();
		} );

		name.addEventListener( 'input', function () {
			if ( name.value.trim() !== '' ) {
				clearNameError();
			}
		} );
	}
}() );
