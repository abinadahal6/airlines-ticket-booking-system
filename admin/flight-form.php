<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_admin();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$flightId = input_int($_GET, 'id');
$isEdit = isset($_GET['id']);
$flight = null;
$isReadOnly = false;
$hasActiveBookings = false;
$minSeats = 4;
$seatsPerRow = count(SEAT_LETTERS);
$errors = [];

$flightNumber = '';
$routeId = input_int($_GET, 'route_id');
$departure = '';
$arrival = '';
$totalSeats = '';
$fare = '';

if ($isEdit) {
	$stmt = $pdo->prepare('
		SELECT f.flight_id, f.route_id, f.flight_number, f.departure_time, f.arrival_time,
			f.total_seats, f.fare, f.status, (f.departure_time <= NOW()) AS has_departed
		FROM flights f
		WHERE f.flight_id = ?
	');
	$stmt->execute([$flightId]);
	$flight = $stmt->fetch() ?: null;
}

if ($flight) {
	$isReadOnly = $flight['status'] !== 'scheduled' || $flight['has_departed'];

	// total_seats can't shrink below the row holding the highest booked seat, or that seat would vanish.
	$bookedMinimum = min_seats_for_bookings($pdo, $flightId);
	$hasActiveBookings = $bookedMinimum !== null;
	$minSeats = $bookedMinimum ?? $seatsPerRow;

	$flightNumber = $flight['flight_number'];
	$routeId = (int) $flight['route_id'];
	$departure = to_datetime_local($flight['departure_time']);
	$arrival = to_datetime_local($flight['arrival_time']);
	$totalSeats = (string) $flight['total_seats'];
	$fare = (string) (float) $flight['fare'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!$isEdit || $flight)) {
	if ($isReadOnly) {
		set_flash('error', 'Departed or cancelled flights can\'t be edited.');
		redirect('admin/flight-form.php?id=' . $flightId);
	}

	$flightNumber = strtoupper(input_string($_POST, 'flight_number'));
	$departure = input_string($_POST, 'departure_time');
	$arrival = input_string($_POST, 'arrival_time');
	$totalSeats = input_string($_POST, 'total_seats');
	$fare = input_string($_POST, 'fare');
	$postedRouteId = isset($_POST['route_id']) ? input_int($_POST, 'route_id') : null;

	// A locked route select is disabled, so the browser doesn't send it; keep the stored route.
	if ($hasActiveBookings) {
		if ($postedRouteId !== null && $postedRouteId !== $routeId) {
			$errors[] = 'This flight has active bookings, so its route can\'t be changed.';
		}
	} else {
		$routeId = (int) $postedRouteId;
	}

	if (!verify_csrf()) {
		$errors = ['Your session expired. Please try again.'];
	} else {
		if (!preg_match('/^' . AIRLINE_CODE . '-[0-9]{3,4}$/', $flightNumber)) {
			$errors[] = 'Flight number must look like ' . AIRLINE_CODE . '-101 (3 or 4 digits).';
		} else {
			$stmt = $pdo->prepare('SELECT 1 FROM flights WHERE flight_number = ? AND flight_id <> ?');
			$stmt->execute([$flightNumber, $flightId]);

			if ($stmt->fetchColumn()) {
				$errors[] = 'Flight number ' . $flightNumber . ' is already used by another flight.';
			}
		}

		$stmt = $pdo->prepare('SELECT 1 FROM routes WHERE route_id = ?');
		$stmt->execute([$routeId]);

		if (!$stmt->fetchColumn()) {
			$errors[] = 'Please choose a route.';
		}

		$departureSql = parse_datetime_local($departure);
		$arrivalSql = parse_datetime_local($arrival);

		if ($departureSql === null) {
			$errors[] = 'Please enter a valid departure date and time.';
		} elseif (strtotime($departureSql) <= time()) {
			$errors[] = 'Departure must be in the future.';
		}

		if ($arrivalSql === null) {
			$errors[] = 'Please enter a valid arrival date and time.';
		} elseif ($departureSql !== null) {
			$flightSeconds = strtotime($arrivalSql) - strtotime($departureSql);

			if ($flightSeconds <= 0) {
				$errors[] = 'Arrival must be after departure.';
			} elseif ($flightSeconds > 24 * 60 * 60) {
				$errors[] = 'A flight can\'t be longer than 24 hours.';
			}
		}

		$seatCount = filter_var($totalSeats, FILTER_VALIDATE_INT);

		if ($seatCount === false || $seatCount < 4 || $seatCount > 120 || $seatCount % $seatsPerRow !== 0) {
			$errors[] = 'Total seats must be a multiple of ' . $seatsPerRow . ' between 4 and 120.';
		} elseif ($seatCount < $minSeats) {
			$errors[] = 'Total seats can\'t be less than ' . $minSeats . ', because seats up to that row are booked.';
		}

		if (!preg_match('/^[0-9]+(\.[0-9]{1,2})?$/', $fare) || (float) $fare <= 0 || (float) $fare > 500000) {
			$errors[] = 'Fare must be a number above 0 and up to 500,000.';
		}
	}

	if (!$errors) {
		$pdo->beginTransaction();

		try {
			if ($isEdit) {
				// Same lock the customer booking transaction takes, so no seat can be booked while we re-check
				// the seat minimum and route lock against the bookings as they are right now.
				$stmt = $pdo->prepare('SELECT flight_id FROM flights WHERE flight_id = ? FOR UPDATE');
				$stmt->execute([$flightId]);
				$lockedMinimum = min_seats_for_bookings($pdo, $flightId);

				if ($lockedMinimum !== null && $seatCount < $lockedMinimum) {
					$errors[] = 'Total seats can\'t be less than ' . $lockedMinimum . ', because seats up to that row are booked.';
				} elseif ($lockedMinimum !== null && $routeId !== (int) $flight['route_id']) {
					$errors[] = 'This flight has active bookings, so its route can\'t be changed.';
				} else {
					$stmt = $pdo->prepare('
						UPDATE flights
						SET route_id = ?, flight_number = ?, departure_time = ?, arrival_time = ?, total_seats = ?, fare = ?
						WHERE flight_id = ?
					');
					$stmt->execute([$routeId, $flightNumber, $departureSql, $arrivalSql, $seatCount, $fare, $flightId]);
				}
			} else {
				$stmt = $pdo->prepare("
					INSERT INTO flights (route_id, flight_number, departure_time, arrival_time, total_seats, fare, status)
					VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
				");
				$stmt->execute([$routeId, $flightNumber, $departureSql, $arrivalSql, $seatCount, $fare]);
			}
		} catch (PDOException $exception) {
			$pdo->rollBack();

			if ($exception->getCode() !== '23000') {
				throw $exception;
			}

			$errors[] = 'Flight number ' . $flightNumber . ' is already used by another flight.';
		}

		if ($pdo->inTransaction()) {
			if ($errors) {
				$pdo->rollBack();
			} else {
				$pdo->commit();
				set_flash('success', 'Flight ' . $flightNumber . ($isEdit ? ' updated.' : ' added.'));
				redirect('admin/flights.php');
			}
		}
	}
}

if ($isEdit && !$flight) {
	http_response_code(404);
}

$routes = $pdo->query('
	SELECT route_id, origin, destination
	FROM routes
	ORDER BY origin, destination
')->fetchAll();

$flash = get_flash();
$pageTitle = $isEdit ? 'Edit Flight' : 'Add Flight';
$heading = $flight ? 'Edit Flight ' . $flight['flight_number'] : $pageTitle;
require_once __DIR__ . '/../includes/header.php';
?>
	<div class="admin-layout">
		<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
		<main class="page admin-main">
			<div class="admin-header">
				<div>
					<a class="back-link" href="<?= e(BASE_URL) ?>admin/flights.php">&larr; Back to flights</a>
					<h1 class="page-title page-title--tight"><?= e($heading) ?></h1>
					<p class="page-subtitle"><?= $isEdit ? 'Change this flight\'s schedule, seats or fare.' : 'Schedule a new ' . e(AIRLINE_NAME) . ' flight.' ?></p>
				</div>
			</div>

			<?php if ($flash): ?>
				<div class="alert alert-<?= e($flash['type']) ?> page-alert" role="status"><?= e($flash['message']) ?></div>
			<?php endif; ?>

			<?php if ($isEdit && !$flight): ?>
				<div class="panel panel-empty">
					<p>That flight doesn't exist.</p>
					<a class="btn-nav" href="<?= e(BASE_URL) ?>admin/flights.php">Back to flights</a>
				</div>
			<?php else: ?>
				<div class="panel form-card">
					<?php if ($isReadOnly): ?>
						<div class="alert alert-info" role="status">
							This flight has <?= $flight['status'] === 'cancelled' ? 'been cancelled' : 'already departed' ?>, so it can't be edited.
						</div>
					<?php elseif ($hasActiveBookings): ?>
						<div class="alert alert-info" role="status">
							This flight has active bookings, so its route is locked and seats can't go below <?= e((string) $minSeats) ?>.
						</div>
					<?php endif; ?>

					<?php if ($errors): ?>
						<div class="alert alert-error" role="alert">
							<ul class="alert-list">
								<?php foreach ($errors as $error): ?>
									<li><?= e($error) ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<form method="post" action="<?= e(BASE_URL) ?>admin/flight-form.php<?= $isEdit ? '?id=' . e((string) $flightId) : '' ?>">
						<?= csrf_field() ?>

						<div class="form-grid">
							<div class="form-group">
								<label class="form-label" for="flight_number">Flight number</label>
								<input class="form-input" type="text" id="flight_number" name="flight_number"
									value="<?= e($flightNumber) ?>" placeholder="<?= e(AIRLINE_CODE) ?>-101" maxlength="20" required
									<?= $isReadOnly ? 'disabled' : '' ?>>
							</div>

							<div class="form-group">
								<label class="form-label" for="route_id">Route</label>
								<select class="form-input" id="route_id" name="route_id" required
									<?= $isReadOnly || $hasActiveBookings ? 'disabled' : '' ?>>
									<option value="">Choose a route</option>
									<?php foreach ($routes as $route): ?>
										<option value="<?= e((string) $route['route_id']) ?>" <?= (int) $route['route_id'] === $routeId ? 'selected' : '' ?>>
											<?= e($route['origin']) ?> → <?= e($route['destination']) ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ($hasActiveBookings && !$isReadOnly): ?>
									<p class="form-hint">Locked because the flight has active bookings.</p>
								<?php endif; ?>
							</div>

							<div class="form-group">
								<label class="form-label" for="departure_time">Departure</label>
								<input class="form-input" type="datetime-local" id="departure_time" name="departure_time"
									value="<?= e($departure) ?>" required <?= $isReadOnly ? 'disabled' : '' ?>>
							</div>

							<div class="form-group">
								<label class="form-label" for="arrival_time">Arrival</label>
								<input class="form-input" type="datetime-local" id="arrival_time" name="arrival_time"
									value="<?= e($arrival) ?>" required <?= $isReadOnly ? 'disabled' : '' ?>>
							</div>

							<div class="form-group">
								<label class="form-label" for="total_seats">Total seats</label>
								<input class="form-input" type="number" id="total_seats" name="total_seats" value="<?= e($totalSeats) ?>"
									step="<?= e((string) $seatsPerRow) ?>" min="<?= e((string) $minSeats) ?>" max="120" required
									<?= $isReadOnly ? 'disabled' : '' ?>>
								<p class="form-hint">Multiple of 4 — seats are numbered 1A–1D, 2A–2D, …</p>
								<?php if ($hasActiveBookings && !$isReadOnly): ?>
									<p class="form-hint">At least <?= e((string) $minSeats) ?> — seats up to that row are booked.</p>
								<?php endif; ?>
							</div>

							<div class="form-group">
								<label class="form-label" for="fare">Fare (Rs.)</label>
								<input class="form-input" type="number" id="fare" name="fare" value="<?= e($fare) ?>"
									step="0.01" min="0.01" max="500000" required <?= $isReadOnly ? 'disabled' : '' ?>>
								<?php if ($isEdit): ?>
									<p class="form-hint">A fare change applies to new bookings only.</p>
								<?php endif; ?>
							</div>
						</div>

						<div class="form-actions">
							<?php if (!$isReadOnly): ?>
								<button class="btn-primary btn-inline" type="submit">Save</button>
							<?php endif; ?>
							<a class="btn-small" href="<?= e(BASE_URL) ?>admin/flights.php"><?= $isReadOnly ? 'Back to flights' : 'Cancel' ?></a>
						</div>
					</form>
				</div>
			<?php endif; ?>
		</main>
	</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
