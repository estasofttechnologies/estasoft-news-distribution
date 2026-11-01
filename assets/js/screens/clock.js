/* Settings, Clock and time zone: reads the browser's clock and asks the server to compare it. */
(function () {
	'use strict';

	var cfg = window.estasoftNDScreens && window.estasoftNDScreens.clock;
	var base = window.estasoftND;
	var checks = document.querySelector('[data-clock-region="checks"]');
	var clocks = document.querySelector('[data-clock-region="clocks"]');

	if (!cfg || !base || !checks || !clocks || !window.fetch) {
		return;
	}

	function address(zone) {
		var url = base.rest + cfg.route;
		var query = 'html=1&browser_time=' + Math.floor(Date.now() / 1000) + '&browser_zone=' + encodeURIComponent(zone);

		return url + (url.indexOf('?') === -1 ? '?' : '&') + query;
	}

	function run() {
		var zone = '';

		try {
			zone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
		} catch (e) {
			zone = '';
		}

		checks.setAttribute('aria-busy', 'true');
		clocks.setAttribute('aria-busy', 'true');

		window.fetch(address(zone), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': base.nonce }
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('status ' + response.status);
			}

			return response.json();
		}).then(function (data) {
			if (data && data.html) {
				checks.innerHTML = data.html.checks;
				clocks.innerHTML = data.html.clocks;
			}
		}).catch(function () {
			var note = document.createElement('p');

			note.className = 'estasoft-nd-muted estasoft-nd-small estasoft-nd-clock-more';
			note.textContent = cfg.text.failed;
			clocks.appendChild(note);
		}).then(function () {
			checks.removeAttribute('aria-busy');
			clocks.removeAttribute('aria-busy');
		});
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest ? event.target.closest('[data-clock-again]') : null;

		if (link) {
			event.preventDefault();
			run();
		}
	});

	run();
}());
