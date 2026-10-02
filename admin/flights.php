<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_admin();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$views = ['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'];

$routes = $pdo->query('
	SELECT route_id, origin, destination
	FROM routes
	ORDER BY origin, destination
')->fetchAll();
$routeIds = array_map('intval', array_column($routes, 'route_id'));

$source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$view = input_string($source, 'view');
$view = array_key_exists($view, $views) ? $view : 'upcoming';
$routeId = input_int($source, 'route_id');

if (!in_array($routeId, $routeIds, true)) {
	$routeId = 0;
}

// Built only from the validated values above, never from raw request data.
$listPath = 'admin/flights.php?' . http_build_query($routeId > 0 ? ['view' => $view, 'route_id' => $routeId] : ['view' => $view]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = input_string($_POST, 'action');
	$flightId = input_int($_POST, 'flight_id');

	if (!verify_csrf()) {
		set_flash('error', 'Your session expired. Please try again.');
		redirect($listPath);
	}

	if ($action !== 'delete') {
		set_flash('error', 'Unknown action.');
		redirect($listPath);
	}

	$pdo->beginTransaction();

	try {
		// Lock the flight row: new holds lock it too, so the checks below can't race with a booking.
		$stmt = $pdo->prepare('
			SELECT f.flight_id, f.flight_number,
				' . PAID_SEATS_SQL . ' AS paid_seats,
				' . HELD_SEATS_SQL . ' AS held_seats
			FROM flights f
			WHERE f.flight_id = ?
			FOR UPDATE
		');
		$stmt->execute([$flightId]);
		$flight = $stmt->fetch();

		if (!$flight) {
			$pdo->rollBack();
			set_flash('error', 'That flight no longer exists.');
			redirect($listPath);
		}

		if ((int) $flight['paid_seats'] > 0 || (int) $flight['held_seats'] > 0) {
			$pdo->rollBack();
			set_flash('error', 'Flight ' . $flight['flight_number'] . ' has paid tickets or seats on hold, so it can\'t be deleted.');
			redirect($listPath);
		}

		// Only lapsed holds are left; they must go first because bookings reference the flight.
		$stmt = $pdo->prepare("
			DELETE FROM bookings
			WHERE flight_id = ?
				AND status = 'pending'
				AND (hold_until IS NULL OR hold_until <= NOW())
		");
		$stmt->execute([$flightId]);

		$stmt = $pdo->prepare('DELETE FROM flights WHERE flight_id = ?');
		$stmt->execute([$flightId]);

		$pdo->commit();
		set_flash('success', 'Flight ' . $flight['flight_number'] . ' deleted.');
		redirect($listPath);
	} catch (PDOException $e) {
		$pdo->rollBack();

		if ($e->getCode() === '23000') {
			set_flash('error', 'Flight ' . $flight['flight_number'] . ' still has bookings, so it can\'t be deleted.');
			redirect($listPath);
		}

		throw $e;
	}
}

$where = [];
$params = [];

if ($view === 'upcoming') {
	$where[] = 'f.departure_time > NOW()';
} elseif ($view === 'past') {
	$where[] = 'f.departure_time <= NOW()';
}

if ($routeId > 0) {
	$where[] = 'f.route_id = ?';
	$params[] = $routeId;
}

// $where holds fixed SQL fragments only; request values go through $params.
$stmt = $pdo->prepare('
	SELECT f.flight_id, f.flight_number, f.departure_time, f.arrival_time, f.total_seats, f.fare, f.status,
		r.origin, r.destination,
		(f.departure_time <= NOW()) AS has_departed,
		' . PAID_SEATS_SQL . ' AS paid_seats,
		' . HELD_SEATS_SQL . ' AS held_seats
	FROM flights f
	JOIN routes r ON r.route_id = f.route_id
	' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
	ORDER BY f.departure_time ' . ($view === 'past' ? 'DESC' : 'ASC') . ', f.flight_number
');
$stmt->execute($params);
$flights = $stmt->fetchAll();

$flash = get_flash();
$pageTitle = 'Flights';
require_once __DIR__ . '/../includes/header.php';
?>
	<div class="admin-layout">
		<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
		<main class="page admin-main">
			<div class="admin-header">
				<div>
					<h1 class="page-title page-title--tight">Flights</h1>
					<p class="page-subtitle"><?= e(AIRLINE_NAME) ?> flights, seats sold and seats on hold.</p>
				</div>
				<a class="btn-small btn-small--solid" href="<?= e(BASE_URL) ?>admin/flight-form.php">Add Flight</a>
			</div>

			<?php if ($flash): ?>
				<div class="alert alert-<?= e($flash['type']) ?> page-alert" role="status"><?= e($flash['message']) ?></div>
			<?php endif; ?>

			<form class="filter-bar" method="get" action="<?= e(BASE_URL) ?>admin/flights.php">
				<div class="form-group">
					<label class="form-label" for="view">View</label>
					<select class="form-input" id="view" name="view">
						<?php foreach ($views as $key => $label): ?>
							<option value="<?= e($key) ?>"<?= $key === $view ? ' selected' : '' ?>><?= e($label) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-group">
					<label class="form-label" for="route_id">Route</label>
					<select class="form-input" id="route_id" name="route_id">
						<option value="0">All routes</option>
						<?php foreach ($routes as $route): ?>
							<option value="<?= (int) $route['route_id'] ?>"<?= (int) $route['route_id'] === $routeId ? ' selected' : '' ?>>
								<?= e($route['origin']) ?> → <?= e($route['destination']) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<button class="btn-small" type="submit">Filter</button>
			</form>

			<?php if (!$flights): ?>
				<div class="panel panel-empty">
					<p>No flights match this view.</p>
					<a class="btn-nav" href="<?= e(BASE_URL) ?>admin/flight-form.php<?= $routeId > 0 ? '?route_id=' . $routeId : '' ?>">Add a flight</a>
				</div>
			<?php else: ?>
				<div class="table-scroll">
					<table class="data-table">
						<thead>
							<tr>
								<th>Flight</th>
								<th>Route</th>
								<th>Departure</th>
								<th>Arrival</th>
								<th class="is-number">Fare</th>
								<th class="is-number">Seats</th>
								<th>Status</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($flights as $flight): ?>
								<?php
								[$statusModifier, $statusLabel] = flight_display_status($flight);
								$paidSeats = (int) $flight['paid_seats'];
								$heldSeats = (int) $flight['held_seats'];
								$canDelete = $paidSeats === 0 && $heldSeats === 0;
								$departure = strtotime($flight['departure_time']);
								?>
								<tr>
									<td class="is-nowrap"><strong><?= e($flight['flight_number']) ?></strong></td>
									<td class="is-nowrap"><?= e($flight['origin']) ?> → <?= e($flight['destination']) ?></td>
									<td class="is-nowrap">
										<?= e(date('D, j M Y', $departure)) ?>
										<span class="cell-muted"><?= e(date('g:i A', $departure)) ?></span>
									</td>
									<td class="is-nowrap"><?= e(date('g:i A', strtotime($flight['arrival_time']))) ?></td>
									<td class="is-number"><?= e(format_money($flight['fare'])) ?></td>
									<td class="is-number">
										<?= $paidSeats ?> / <?= (int) $flight['total_seats'] ?>
										<?php if ($heldSeats > 0): ?>
											<span class="cell-muted"><?= $heldSeats ?> on hold</span>
										<?php endif; ?>
									</td>
									<td><span class="status-pill status-pill--<?= e($statusModifier) ?>"><?= e($statusLabel) ?></span></td>
									<td>
										<div class="table-actions">
											<a class="btn-small" href="<?= e(BASE_URL) ?>admin/flight-passengers.php?flight_id=<?= (int) $flight['flight_id'] ?>">Passengers</a>
											<a class="btn-small" href="<?= e(BASE_URL) ?>admin/flight-form.php?id=<?= (int) $flight['flight_id'] ?>">Edit</a>
											<?php if ($canDelete): ?>
												<form class="inline-form" method="post" action="<?= e(BASE_URL) ?>admin/flights.php"
													data-confirm="Delete flight <?= e($flight['flight_number']) ?> permanently?">
													<?= csrf_field() ?>
													<input type="hidden" name="action" value="delete">
													<input type="hidden" name="flight_id" value="<?= (int) $flight['flight_id'] ?>">
													<input type="hidden" name="view" value="<?= e($view) ?>">
													<input type="hidden" name="route_id" value="<?= $routeId ?>">
													<button class="btn-small btn-small--danger" type="submit">Delete</button>
												</form>
											<?php else: ?>
												<button class="btn-small btn-small--danger" type="button" disabled
													title="Flights with paid tickets or seats on hold can't be deleted.">Delete</button>
											<?php endif; ?>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</main>
	</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
