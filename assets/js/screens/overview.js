/* Overview: a changed language or "hide this card" choice is sent at once, so the Apply and Save buttons are only for people without a script. */
(function () {
	'use strict';

	var fields = document.querySelectorAll('#estasoft-nd-app [data-auto-submit]');

	function send(form) {
		if (typeof form.requestSubmit === 'function') {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}

	Array.prototype.forEach.call(fields, function (field) {
		field.addEventListener('change', function () {
			if (field.form) {
				send(field.form);
			}
		});
	});
}());
