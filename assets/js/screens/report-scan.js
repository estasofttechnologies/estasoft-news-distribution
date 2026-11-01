/*
 * Reports that run a check in the queue (self-test, hreflang, Discover): follows the running job.
 * Every few seconds it asks the job (GET /jobs/{id}) how far it got, moves the bar and the words,
 * and when the job ends it opens the report again without the job in the address, so the page
 * shows the new result. Without this script the page shows the state it loaded with, and a
 * person reloads it by hand; nothing else depends on it.
 */
( function () {
	'use strict';

	var cfg = window.estasoftND || { rest: '', nonce: '' };
	var data = ( window.estasoftNDScreens || {} )[ 'report-scan' ] || {};

	if ( ! data.job || ! cfg.rest || ! window.fetch ) {
		return;
	}

	var interval = Number( data.interval ) > 1000 ? Number( data.interval ) : 2000;
	var bar = document.querySelector( '.estasoft-nd-meter' );
	var words = document.getElementById( 'estasoft-nd-scan-words' );
	var timer = null;
	var failures = 0;

	// A network hiccup is tried again a little later each time, and given up after a while. A job that is gone (404) or a
	// session that ended (401, 403) will not come back by asking again.
	function again() {
		window.clearTimeout( timer );
		timer = window.setTimeout( poll, Math.min( 30000, interval * Math.pow( 2, Math.min( failures, 4 ) ) ) );
	}

	function failed( error ) {
		var status = error && error.status ? error.status : 0;

		if ( status === 404 || status === 410 ) {
			// The job is gone: the page it belongs to shows what it left. Without that address there is nothing to open.
			if ( data.finished ) {
				window.location.href = data.finished;
			}

			return;
		}

		failures += 1;

		if ( status === 401 || status === 403 || failures > 12 ) {
			return;
		}

		again();
	}

	function show( job ) {
		var total = Math.max( 1, Number( job.total ) || 1 );
		var done = Math.min( total, Number( job.done ) || 0 );

		if ( bar ) {
			bar.max = total;
			bar.value = done;
		}

		if ( words && job.state === 'running' && data.words ) {
			words.textContent = String( data.words ).replace( '{done}', done ).replace( '{total}', total );
		}
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
			if ( job.state === 'done' || job.state === 'failed' ) {
				window.location.href = data.finished || window.location.pathname + window.location.search;
				return;
			}

			failures = 0;
			show( job );
			again();
		} ).catch( failed );
	}

	again();
} )();
