/* Settings, Compatibility, the switch from GN Publisher: "I turned it off" moves the highlight from step 3 to step 4. */
( function () {
	'use strict';

	var box = document.querySelector( '[data-gn-turned-off]' );
	var three = document.querySelector( '[data-gn-step="3"]' );
	var four = document.querySelector( '[data-gn-step="4"]' );

	if ( ! box || ! three || ! four || ! three.classList.contains( 'estasoft-nd-step--now' ) ) {
		return;
	}

	function mark( step, now ) {
		var number = step.querySelector( '.estasoft-nd-step__num' );

		step.classList.toggle( 'estasoft-nd-step--now', now );

		if ( number && ! number.classList.contains( 'estasoft-nd-step__num--done' ) ) {
			number.classList.toggle( 'estasoft-nd-step__num--now', now );
		}
	}

	box.addEventListener( 'change', function () {
		mark( three, ! box.checked );
		mark( four, box.checked );
	} );
}() );
