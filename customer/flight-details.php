<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_login();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$flightId = input_int($_GET, 'flight_id');
$userId = (int) $_SESSION['user_id'];

// seats_left / is_bookable are computed in SQL so they use the same clock as the hold rules.
$flightSql = "
	SELECT f.flight_id, f.flight_number, f.departure_time, f.arrival_time, f.total_seats, f.fare,
		r.origin, r.destination, " . SEATS_LEFT_SQL . " AS seats_left,
		(" . BOOKABLE_FLIGHT_SQL . ") AS is_bookable
	FROM flights f
	JOIN routes r ON r.route_id = f.route_id
	WHERE f.flight_id = ?
";
$stmt = $pdo->prepare($flightSql);
$stmt->execute([...bookable_flight_params(), $flightId]);
$flight = $stmt->fetch();

$isBookable = $flight && (bool) $flight['is_bookable'];
$error = '';

if ($isBookable && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$seatNo = strtoupper(input_string($_POST, 'seat_no'));

	if (!verify_csrf()) {
		$error = 'Your session expired. Please try again.';
	} elseif (!in_array($seatNo, seat_numbers((int) $flight['total_seats']), true)) {
		$error = 'Please choose a seat.';
	} else {
		$pdo->beginTransaction();

		try {
			// Lock the flight row first: every hold for this flight goes through this lock, so the checks
			// below can't race with another customer taking the same seat or the last seat.
			$stmt = $pdo->prepare('SELECT flight_id FROM flights WHERE flight_id = ? FOR UPDATE');
			$stmt->execute([$flightId]);

			// Picking a seat again replaces this customer's own earlier hold on the flight.
			$stmt = $pdo->prepare("DELETE FROM bookings WHERE flight_id = ? AND user_id = ? AND status = 'pending'");
			$stmt->execute([$flightId, $userId]);

			$stmt = $pdo->prepare($flightSql);
			$stmt->execute([...bookable_flight_params(), $flightId]);
			$isStillBookable = (bool) $stmt->fetch()['is_bookable'];

			$stmt = $pdo->prepare("SELECT 1 FROM bookings WHERE flight_id = ? AND user_id = ? AND status = 'paid'");
			$stmt->execute([$flightId, $userId]);
			$hasTicket = (bool) $stmt->fetchColumn();

			$stmt = $pdo->prepare('
				SELECT 1 FROM bookings b
				WHERE b.flight_id = ? AND b.seat_no = ? AND ' . ACTIVE_BOOKING_SQL
			);
			$stmt->execute([$flightId, $seatNo]);
			$isSeatTaken = (bool) $stmt->fetchColumn();

			if (!$isStillBookable) {
				$error = 'Sorry, this flight is now full or closed for booking.';
			} elseif ($hasTicket) {
				$error = 'You already have a ticket on this flight.';
			} elseif ($isSeatTaken) {
				$error = 'Seat ' . $seatNo . ' was just taken. Please pick another seat.';
			}

			if ($error) {
				$pdo->rollBack();
			} else {
				$stmt = $pdo->prepare("
					INSERT INTO bookings (user_id, flight_id, seat_no, fare, status, hold_until, booking_reference)
					SELECT ?, flight_id, ?, fare, 'pending', NOW() + INTERVAL ? MINUTE, ?
					FROM flights
					WHERE flight_id = ?
				");
				$stmt->execute([$userId, $seatNo, HOLD_MINUTES, generate_booking_ref(), $flightId]);
				$bookingId = (int) $pdo->lastInsertId();
				$pdo->commit();

				unset($_SESSION['nepal_pay']);
				redirect('customer/payment.php?booking_id=' . $bookingId);
			}
		} catch (PDOException $e) {
			$pdo->rollBack();
			throw $e;
		}
	}
}

$selectedSeat = '';

if ($isBookable) {
	$seats = seat_numbers((int) $flight['total_seats']);

	// Taken = every active booking except this customer's own hold, which shows as their selected seat.
	$stmt = $pdo->prepare('
		SELECT b.seat_no FROM bookings b
		WHERE b.flight_id = ?
			AND ' . ACTIVE_BOOKING_SQL . "
			AND NOT (b.user_id = ? AND b.status = 'pending')
	");
	$stmt->execute([$flightId, $userId]);
	$bookedSeats = $stmt->fetchAll(PDO::FETCH_COLUMN);

	$stmt = $pdo->prepare('
		SELECT b.booking_id, b.seat_no, b.status,
			IFNULL(GREATEST(TIMESTAMPDIFF(SECOND, NOW(), b.hold_until), 0), 0) AS seconds_left
		FROM bookings b
		WHERE b.flight_id = ? AND b.user_id = ? AND ' . ACTIVE_BOOKING_SQL . "
		ORDER BY b.status = 'paid' DESC
		LIMIT 1
	");
	$stmt->execute([$flightId, $userId]);
	$myBooking = $stmt->fetch();

	if ($myBooking && $myBooking['status'] === 'pending') {
		$selectedSeat = $myBooking['seat_no'];
	}
} elseif (!$flight) {
	http_response_code(404);
}

$pageTitle = 'Flight Details';
require_once __DIR__ . '/../includes/header.php';
?>
	<main class="page">
		<h1 class="page-title">Flight Details</h1>

		<?php if (!$isBookable): ?>
			<div class="panel panel-empty">
				<p>This flight is no longer available for booking.</p>
				<a class="btn-nav" href="<?= e(BASE_URL) ?>index.php">Search flights</a>
			</div>
		<?php else: ?>
			<section class="flight-summary">
				<div class="flight-summary-item">
					<span class="flight-summary-airline"><?= e(AIRLINE_NAME) ?></span>
					<span class="flight-card-muted"><?= e($flight['flight_number']) ?></span>
				</div>
				<div class="flight-summary-item">
					<span class="flight-summary-time"><?= e(date('H:i', strtotime($flight['departure_time']))) ?></span>
					<span class="flight-card-muted"><?= e($flight['origin']) ?></span>
				</div>
				<span class="flight-summary-duration">
					→ <?= e(format_duration($flight['departure_time'], $flight['arrival_time'])) ?> →
				</span>
				<div class="flight-summary-item">
					<span class="flight-summary-time"><?= e(date('H:i', strtotime($flight['arrival_time']))) ?></span>
					<span class="flight-card-muted"><?= e($flight['destination']) ?></span>
				</div>
				<div class="flight-summary-item flight-summary-date">
					<span class="flight-summary-strong"><?= e(date('D, j M Y', strtotime($flight['departure_time']))) ?></span>
					<span class="flight-card-muted">
						<?= (int) $flight['seats_left'] ?> of <?= (int) $flight['total_seats'] ?> seats available
					</span>
				</div>
				<div class="flight-summary-item">
					<span class="flight-summary-fare"><?= e(format_money($flight['fare'])) ?></span>
					<span class="flight-card-muted">per seat</span>
				</div>
			</section>

			<form class="booking-layout" method="post" action="flight-details.php?flight_id=<?= (int) $flight['flight_id'] ?>">
				<?= csrf_field() ?>

				<section class="panel seat-panel" aria-labelledby="seat-heading">
					<h2 class="panel-title" id="seat-heading">Select Your Seat</h2>
					<p class="panel-subtitle">
						Pick any available seat. Seats are numbered by row (front to back) and letter
						(<?= e(SEAT_LETTERS[0]) ?>–<?= e(SEAT_LETTERS[count(SEAT_LETTERS) - 1]) ?>).
					</p>

					<?php if ($error): ?>
						<div class="alert alert-error" role="alert"><?= e($error) ?></div>
					<?php endif; ?>

					<?php if ($myBooking && $myBooking['status'] === 'paid'): ?>
						<div class="alert alert-info">
							You already have a ticket (seat <?= e($myBooking['seat_no']) ?>) on this flight.
							<a href="<?= e(BASE_URL) ?>customer/booking-confirmation.php?booking_id=<?= (int) $myBooking['booking_id'] ?>">View ticket</a>
						</div>
					<?php elseif ($myBooking): ?>
						<div class="alert alert-info">
							Seat <?= e($myBooking['seat_no']) ?> is on hold for you
							(<span data-seconds-left="<?= (int) $myBooking['seconds_left'] ?>"><?= e(format_countdown((int) $myBooking['seconds_left'])) ?></span> left).
							<a href="<?= e(BASE_URL) ?>customer/payment.php?booking_id=<?= (int) $myBooking['booking_id'] ?>">Continue to payment</a>
							or pick a different seat below.
						</div>
					<?php endif; ?>

					<div class="seat-map-scroll">
						<div class="plane">
							<div class="plane-body">
								<?php /* Outline only: the cabin is a bordered box; nose, wings and tail fins are small SVGs around it. */ ?>
								<svg class="plane-nose" width="156" height="254" viewBox="0 0 156 254" aria-hidden="true">
									<path d="M156 1 C84 1, 40 67, 1 127 C40 187, 84 253, 156 253"/>
								</svg>
								<svg class="plane-wing plane-wing--top" width="180" height="90" viewBox="0 0 180 90" aria-hidden="true">
									<path d="M0 90 L120 0 L170 0 L180 90"/>
								</svg>
								<svg class="plane-wing plane-wing--bottom" width="180" height="90" viewBox="0 0 180 90" aria-hidden="true">
									<path d="M0 90 L120 0 L170 0 L180 90"/>
								</svg>
								<svg class="plane-tail plane-tail--top" width="85" height="46" viewBox="0 0 85 46" aria-hidden="true">
									<path d="M0 46 L55 0 L85 0 L70 46"/>
								</svg>
								<svg class="plane-tail plane-tail--bottom" width="85" height="46" viewBox="0 0 85 46" aria-hidden="true">
									<path d="M0 46 L55 0 L85 0 L70 46"/>
								</svg>
								<span class="plane-front" aria-hidden="true">FRONT</span>

								<div class="seat-grid" id="seat-map" role="radiogroup" aria-labelledby="seat-heading">
									<?php foreach (SEAT_LETTERS as $letter): ?>
										<span class="seat-letter" aria-hidden="true"><?= e($letter) ?></span>
									<?php endforeach; ?>

									<?php foreach ($seats as $seatNo): ?>
										<?php $isBooked = in_array($seatNo, $bookedSeats, true); ?>
										<label class="seat">
											<input type="radio" name="seat_no" value="<?= e($seatNo) ?>"
												data-row="<?= (int) $seatNo ?>" data-position="<?= e(seat_position($seatNo)) ?>"
												<?= $isBooked ? 'disabled' : '' ?> <?= $seatNo === $selectedSeat ? 'checked' : '' ?>
												aria-label="Seat <?= e($seatNo) ?><?= $isBooked ? ', booked' : '' ?>">
											<span><?= e($seatNo) ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>

					<div class="seat-legend" aria-hidden="true">
						<span class="seat-legend-item"><i class="seat-swatch"></i>Available</span>
						<span class="seat-legend-item"><i class="seat-swatch seat-swatch--booked"></i>Booked</span>
						<span class="seat-legend-item"><i class="seat-swatch seat-swatch--selected"></i>Your seat</span>
					</div>
				</section>

				<aside class="panel selection-panel" aria-labelledby="selection-heading">
					<h2 class="panel-title" id="selection-heading">Your Selection</h2>

					<div class="selected-seat">
						<span class="selected-seat-tile<?= $selectedSeat ? ' is-set' : '' ?>" id="selected-seat-tile">
							<?= $selectedSeat ? e($selectedSeat) : '–' ?>
						</span>
						<div>
							<p class="selected-seat-title" id="selected-seat-title">
								<?= $selectedSeat ? 'Seat ' . e($selectedSeat) : 'No seat selected' ?>
							</p>
							<p class="flight-card-muted" id="selected-seat-meta">
								<?= $selectedSeat
									? 'Row ' . (int) $selectedSeat . ' · ' . e(seat_position($selectedSeat)) . ' seat'
									: 'Pick a seat on the map' ?>
							</p>
						</div>
					</div>

					<dl class="detail-list">
						<div><dt>Passenger</dt><dd><?= e($_SESSION['full_name'] ?? '') ?></dd></div>
						<div><dt>Flight</dt><dd><?= e($flight['flight_number']) ?> · <?= e(AIRLINE_NAME) ?></dd></div>
						<div><dt>Date</dt><dd><?= e(date('D, j M Y', strtotime($flight['departure_time']))) ?></dd></div>
						<div><dt>Aircraft</dt><dd><?= e(AIRCRAFT_TYPE) ?></dd></div>
						<div><dt>Baggage</dt><dd><?= e(BAGGAGE_ALLOWANCE) ?></dd></div>
					</dl>

					<div class="total-row">
						<span>Total fare</span>
						<strong><?= e(format_money($flight['fare'])) ?></strong>
					</div>

					<button class="btn-primary btn-continue" type="submit" id="continue-button" <?= $selectedSeat ? '' : 'disabled' ?>>
						Proceed to Payment
					</button>
					<p class="flight-card-muted">
						Your seat is held for <?= HOLD_MINUTES ?> minutes while you pay with <?= e(NEPAL_PAY_NAME) ?>.
					</p>
				</aside>
			</form>
		<?php endif; ?>
	</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
