(function () {
	'use strict';

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.mad4b-chatgpt-copy');
		if (!button) return;
		var targetId = button.getAttribute('data-copy-target') || '';
		var target = targetId ? document.getElementById(targetId) : null;
		if (!target) return;
		var value = target.value || target.textContent || '';
		if (!value) return;

		var done = function () {
			var old = button.textContent;
			button.textContent = 'Copied';
			window.setTimeout(function () { button.textContent = old; }, 1200);
		};

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(value).then(done).catch(function () {
				target.focus();
				target.select();
			});
			return;
		}
		target.focus();
		target.select();
	});
}());
