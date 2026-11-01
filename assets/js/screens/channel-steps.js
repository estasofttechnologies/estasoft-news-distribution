/*
 * Channel step pages (WebSub, IndexNow, Microsoft Start, Yahoo News).
 *
 * Without this script every hub address row is on the page and everything else
 * works through plain links and forms. With it, the extra hub rows stay out of
 * the way until "Add another hub" shows the next one.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	var button = root.querySelector( '[data-add-hub]' );

	if ( ! button ) {
		return;
	}

	function hidden() {
		return Array.prototype.slice.call( root.querySelectorAll( '.estasoft-nd-hub-extra:not(.is-shown)' ) );
	}

	function update() {
		if ( hidden().length === 0 ) {
			button.hidden = true;
		}
	}

	button.addEventListener( 'click', function () {
		var next = hidden()[ 0 ];

		if ( ! next ) {
			return;
		}

		next.classList.add( 'is-shown' );

		var field = next.querySelector( 'input' );

		if ( field ) {
			field.focus();
		}

		update();
	} );

	update();
} )();
