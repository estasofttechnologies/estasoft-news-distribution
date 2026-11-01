/* Reports, Referrals: a changed period is sent at once, so the Show button is only for people without a script. */
( function () {
	'use strict';

	var selects = document.querySelectorAll( 'select[data-auto-submit]' );

	Array.prototype.forEach.call( selects, function ( select ) {
		select.addEventListener( 'change', function () {
			if ( ! select.form ) {
				return;
			}

			if ( typeof select.form.requestSubmit === 'function' ) {
				select.form.requestSubmit();
			} else {
				select.form.submit();
			}
		} );
	} );
}() );
