/*
 * Control tabs of Article check: Release plan, Takedown and Assign.
 *
 * Every form works without this script. Here the release form hides the rows that do not apply to the
 * mode that is chosen, and the assign list filters in place.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'estasoft-nd-app' );

	if ( ! root ) {
		return;
	}

	// Release plan: show the rows that belong to the chosen mode.
	var plan = root.querySelector( '[data-ctl-plan]' );
	var mode = plan ? plan.querySelector( '[data-ctl-mode]' ) : null;

	function applyMode() {
		var chosen = mode.value;

		plan.querySelectorAll( '[data-ctl-for]' ).forEach( function ( row ) {
			var wanted = String( row.getAttribute( 'data-ctl-for' ) ).split( ' ' );

			row.hidden = wanted.indexOf( chosen ) === -1;
		} );
	}

	if ( plan && mode ) {
		mode.addEventListener( 'change', applyMode );
		applyMode();
	}
}() );
