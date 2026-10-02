document.addEventListener('DOMContentLoaded', function () {
	var password = document.getElementById('password');
	var confirmPassword = document.getElementById('confirm_password');

	if (password && confirmPassword) {
		var checkMatch = function () {
			confirmPassword.setCustomValidity(
				confirmPassword.value !== password.value ? 'Passwords do not match.' : ''
			);
		};

		password.addEventListener('input', checkMatch);
		confirmPassword.addEventListener('input', checkMatch);
	}

	var fromSelect = document.getElementById('from');
	var toSelect = document.getElementById('to');

	if (fromSelect && toSelect) {
		var filterDestinations = function () {
			var origin = fromSelect.value;

			Array.prototype.forEach.call(toSelect.options, function (option) {
				if (option.dataset.origin === undefined) {
					return;
				}

				var isServed = option.dataset.origin === origin;
				option.hidden = !isServed;
				option.disabled = !isServed;
			});

			var selected = toSelect.options[toSelect.selectedIndex];

			if (selected && selected.disabled) {
				toSelect.selectedIndex = 0;
			}

			toSelect.disabled = origin === '';
		};

		fromSelect.addEventListener('change', filterDestinations);
		filterDestinations();
	}

	var seatMap = document.getElementById('seat-map');

	if (seatMap) {
		seatMap.addEventListener('change', function (event) {
			var seat = event.target;

			if (seat.name !== 'seat_no') {
				return;
			}

			var tile = document.getElementById('selected-seat-tile');
			tile.textContent = seat.value;
			tile.classList.add('is-set');
			document.getElementById('selected-seat-title').textContent = 'Seat ' + seat.value;
			document.getElementById('selected-seat-meta').textContent =
				'Row ' + seat.dataset.row + ' · ' + seat.dataset.position + ' seat';
			document.getElementById('continue-button').disabled = false;
		});
	}

	// Destructive admin actions: <form data-confirm="Question?"> asks before submitting.
	document.querySelectorAll('form[data-confirm]').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			if (!window.confirm(form.dataset.confirm)) {
				event.preventDefault();
			}
		});
	});

	document.querySelectorAll('[data-print]').forEach(function (button) {
		button.addEventListener('click', function () {
			window.print();
		});
	});

	// Seat-hold countdowns. Display only: the server decides whether a hold is still valid.
	var timers = document.querySelectorAll('[data-seconds-left]');

	if (timers.length) {
		var startedAt = Date.now();

		var tick = function () {
			var elapsed = Math.floor((Date.now() - startedAt) / 1000);

			timers.forEach(function (timer) {
				var left = Math.max(parseInt(timer.dataset.secondsLeft, 10) - elapsed, 0);
				var seconds = left % 60;
				timer.textContent = left > 0 ? Math.floor(left / 60) + ':' + (seconds < 10 ? '0' : '') + seconds : 'expired';
			});
		};

		tick();
		setInterval(tick, 1000);
	}
});
