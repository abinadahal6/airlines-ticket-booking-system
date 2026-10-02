<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_login();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$tabs = ['upcoming' => 'Upcoming', 'past' => 'Past'];
$activeTab = input_string($_GET, 'tab');
$activeTab = array_key_exists($activeTab, $tabs) ? $activeTab : 'upcoming';

// Only paid tickets and holds still running; lapsed unpaid holds are ignored.
// has_departed / seconds_left come from SQL so they use the same clock as the hold rules.
$stmt = $pdo->prepare('
	SELECT b.booking_id, b.booking_reference, b.seat_no, b.fare, b.status,
		f.flight_number, f.departure_time, f.arrival_time, f.status AS flight_status,
		r.origin, r.destination, p.paid_at,
		(f.departure_time <= NOW()) AS has_departed,
		IFNULL(GREATEST(TIMESTAMPDIFF(SECOND, NOW(), b.hold_until), 0), 0) AS seconds_left
	FROM bookings b
	JOIN flights f ON f.flight_id = b.flight_id
	JOIN routes r ON r.route_id = f.route_id
	LEFT JOIN payments p ON p.booking_id = b.booking_id
	WHERE b.user_id = ? AND ' . ACTIVE_BOOKING_SQL . '
	ORDER BY f.departure_time
');
$stmt->execute([(int) $_SESSION['user_id']]);

$groups = ['upcoming' => [], 'past' => []];
$heldBookings = [];

foreach ($stmt->fetchAll() as $booking) {
	$groups[$booking['has_departed'] ? 'past' : 'upcoming'][] = $booking;

	if ($booking['status'] === 'pending') {
		$heldBookings[] = $booking;
	}
}

// Most recent first for the history tab; soonest first for upcoming.
$groups['past'] = array_reverse($groups['past']);

$pageTitle = 'My Bookings';
require_once __DIR__ . '/../includes/header.php';
?>
	<main class="page">
		<h1 class="page-title page-title--tight">My Bookings</h1>
		<p class="page-subtitle">Your tickets. Show the ticket number at check-in.</p>

		<?php foreach ($heldBookings as $held): ?>
			<div class="reminder-banner">
				<span class="reminder-icon" aria-hidden="true">!</span>
				<div class="reminder-content">
					<p class="reminder-title">Seat <?= e($held['seat_no']) ?> on <?= e($held['flight_number']) ?> is on hold for you</p>
					<p class="reminder-text">
						Complete payment within
						<strong data-seconds-left="<?= (int) $held['seconds_left'] ?>"><?= e(format_countdown((int) $held['seconds_left'])) ?></strong>
						or the seat is released.
					</p>
				</div>
				<a class="btn-small" href="payment.php?booking_id=<?= (int) $held['booking_id'] ?>">Complete payment</a>
			</div>
		<?php endforeach; ?>

		<nav class="tabs" aria-label="Booking groups">
			<?php foreach ($tabs as $key => $label): ?>
				<a class="tab<?= $key === $activeTab ? ' is-active' : '' ?>" href="my-bookings.php?tab=<?= e($key) ?>"
					<?= $key === $activeTab ? 'aria-current="page"' : '' ?>>
					<?= e($label) ?>
					<span class="tab-count"><?= count($groups[$key]) ?></span>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php if (!$groups[$activeTab]): ?>
			<div class="panel panel-empty">
				<p><?= $activeTab === 'upcoming' ? 'You have no upcoming bookings.' : 'No past trips yet.' ?></p>
				<a class="btn-nav" href="<?= e(BASE_URL) ?>index.php">Search flights</a>
			</div>
		<?php endif; ?>

		<div class="booking-list">
			<?php foreach ($groups[$activeTab] as $booking): ?>
				<?php [$statusClass, $statusLabel, $statusDetail] = booking_display_status($booking); ?>
				<article class="booking-card">
					<div class="booking-route">
						<span class="flight-card-number"><?= e($booking['flight_number']) ?></span>
						<div class="booking-times">
							<div>
								<span class="flight-card-time"><?= e(date('H:i', strtotime($booking['departure_time']))) ?></span>
								<span class="flight-card-muted"><?= e($booking['origin']) ?></span>
							</div>
							<span class="booking-arrow" aria-hidden="true">→</span>
							<div>
								<span class="flight-card-time"><?= e(date('H:i', strtotime($booking['arrival_time']))) ?></span>
								<span class="flight-card-muted"><?= e($booking['destination']) ?></span>
							</div>
						</div>
					</div>

					<dl class="booking-facts">
						<div><dt>Date</dt><dd><?= e(date('D, j M Y', strtotime($booking['departure_time']))) ?></dd></div>
						<div><dt>Seat</dt><dd><?= e($booking['seat_no']) ?></dd></div>
						<div><dt>Ticket No.</dt><dd class="booking-ref"><?= e($booking['booking_reference']) ?></dd></div>
					</dl>

					<div class="booking-status">
						<span class="status-pill status-pill--<?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
						<?php if ($statusDetail): ?>
							<span class="flight-card-muted"><?= e($statusDetail) ?></span>
						<?php endif; ?>
						<?php if ($booking['flight_status'] === 'cancelled'): ?>
							<span class="booking-warning">Flight cancelled by the airline — please contact our office.</span>
						<?php endif; ?>
					</div>

					<span class="booking-fare"><?= e(format_money($booking['fare'])) ?></span>

					<div class="booking-actions">
						<?php if ($booking['status'] === 'pending'): ?>
							<a class="btn-small btn-small--solid" href="payment.php?booking_id=<?= (int) $booking['booking_id'] ?>">Pay now</a>
						<?php else: ?>
							<a class="btn-small" href="booking-confirmation.php?booking_id=<?= (int) $booking['booking_id'] ?>">View ticket</a>
						<?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>

		<?php if ($activeTab === 'upcoming' && $groups['upcoming']): ?>
			<p class="booking-note">
				Tickets can't be cancelled online. For changes or cancellations, please visit a <?= e(AIRLINE_NAME) ?> office.
			</p>
		<?php endif; ?>
	</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
