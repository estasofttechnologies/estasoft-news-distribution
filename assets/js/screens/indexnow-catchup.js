/*
 * IndexNow catch-up: keeps an eye on the running catch-up. Every few seconds it
 * asks the job (GET /jobs/{id}) how far it got. When a batch went out or the run
 * ended, it reloads the page so the bar, the words and the batch table all say
 * the same thing. Without this script the page shows the state it loaded with
 * and a person reloads it by hand; nothing else depends on it.
 */
( function () {
	'use strict';

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var data = ( window.estasoftNDScreens || {} )[ 'indexnow-catchup' ] || {};

	if ( ! data.job || ! cfg.rest || ! window.fetch ) {
		return;
	}

	var interval = Number( data.interval ) > 1000 ? Number( data.interval ) : 5000;
	var timer = null;
	var failures = 0;

	// A network hiccup is tried again a little later each time, and given up after a while.
	function again() {
		window.clearTimeout( timer );
		timer = window.setTimeout( poll, Math.min( 60000, interval * Math.pow( 2, Math.min( failures, 4 ) ) ) );
	}

	function failed( error ) {
		var status = error && error.status ? error.status : 0;

		failures += 1;

		// A job that is gone (404, 410) or a session that ended (401, 403) will not come back by asking again.
		if ( status === 404 || status === 410 || status === 401 || status === 403 || failures > 12 ) {
			return;
		}

		again();
	}

	function poll() {
		if ( document.hidden ) {
			again();
			return;
		}

		window.fetch( cfg.rest + 'jobs/' + encodeURIComponent( data.job ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				var error = new Error( 'job' );
				error.status = response.status;
				throw error;
			}

			return response.json();
		} ).then( function ( job ) {
			var ended = job.state === 'done' || job.state === 'failed';

			if ( ended || Number( job.done ) !== Number( data.sent ) ) {
				window.location.reload();
				return;
			}

			failures = 0;
			again();
		} ).catch( failed );
	}

	again();
} )();
