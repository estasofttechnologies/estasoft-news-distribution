/* Settings, Environment: copies the support information to the clipboard. */
(function () {
	'use strict';

	var cfg = (window.estasoftNDScreens && window.estasoftNDScreens.environment) || { text: {} };
	var button = document.querySelector('[data-copy-target]');

	if (!button) {
		return;
	}

	var box = document.getElementById(button.getAttribute('data-copy-target'));
	var status = document.querySelector('[data-copy-status]');
	var message = status ? status.querySelector('[data-copy-message]') : null;

	if (!box) {
		return;
	}

	button.hidden = false;

	function show(text, failed) {
		if (!status || !message) {
			return;
		}

		message.textContent = text;
		status.hidden = false;
		status.classList.toggle('estasoft-nd-status--danger', !!failed);
		status.classList.toggle('estasoft-nd-status--success', !failed);
	}

	function fallback() {
		box.focus();
		box.select();

		try {
			return document.execCommand('copy');
		} catch (e) {
			return false;
		}
	}

	button.addEventListener('click', function () {
		var text = box.value;

		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function () {
				show(cfg.text.copied || 'Copied', false);
			}, function () {
				var ok = fallback();

				show(ok ? (cfg.text.copied || 'Copied') : (cfg.text.failed || 'Could not copy'), !ok);
			});
			return;
		}

		var ok = fallback();
		show(ok ? (cfg.text.copied || 'Copied') : (cfg.text.failed || 'Could not copy'), !ok);
	});
}());
