<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_admin();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$flightId = input_int($_GET, 'flight_id');

$stmt = $pdo->prepare("
	SELECT f.flight_id, f.flight_number, f.departure_time, f.status,
		r.origin, r.destination,
		(f.departure_time <= NOW()) AS has_departed
	FROM flights f
	JOIN routes r ON r.route_id = f.route_id
	WHERE f.flight_id = ?
");
$stmt->execute([$flightId]);
$flight = $stmt->fetch() ?: null;

$passengers = [];
$holds = [];

if ($flight) {
	$stmt = $pdo->prepare("
		SELECT b.seat_no, b.booking_reference, u.full_name, u.email, u.phone,
			p.amount, p.payer_phone, p.transaction_ref, p.paid_at
		FROM bookings b
		JOIN users u ON u.user_id = b.user_id
		LEFT JOIN payments p ON p.booking_id = b.booking_id
		WHERE b.flight_id = ? AND b.status = 'paid'
		ORDER BY CAST(b.seat_no AS UNSIGNED), RIGHT(b.seat_no, 1)
	");
	$stmt->execute([$flightId]);
	$passengers = $stmt->fetchAll();

	$stmt = $pdo->prepare("
		SELECT b.seat_no, u.full_name, u.email,
			GREATEST(TIMESTAMPDIFF(SECOND, NOW(), b.hold_until), 0) AS seconds_left
		FROM bookings b
		JOIN users u ON u.user_id = b.user_id
		WHERE b.flight_id = ? AND b.status = 'pending' AND b.hold_until > NOW()
		ORDER BY CAST(b.seat_no AS UNSIGNED), RIGHT(b.seat_no, 1)
	");
	$stmt->execute([$flightId]);
	$holds = $stmt->fetchAll();
} else {
	http_response_code(404);
}

$pageTitle = $flight ? 'Passengers — ' . $flight['flight_number'] : 'Flight not found';
require_once __DIR__ . '/../includes/header.php';
?>
	<div class="admin-layout">
		<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
		<main class="page admin-main">
			<?php if (!$flight): ?>
				<h1 class="page-title">Flight not found</h1>
				<div class="panel panel-empty">
					<p>We couldn't find this flight. It may have been deleted.</p>
					<a class="btn-nav" href="<?= e(BASE_URL) ?>admin/flights.php">Back to flights</a>
				</div>
			<?php else: ?>
				<?php [$statusModifier, $statusLabel] = flight_display_status($flight); ?>
				<a class="back-link no-print" href="<?= e(BASE_URL) ?>admin/flights.php">← Back to flights</a>
				<div class="admin-header">
					<div>
						<h1 class="page-title page-title--tight">Passengers — <?= e($flight['flight_number']) ?></h1>
						<p class="page-subtitle">
							<?= e($flight['origin']) ?> → <?= e($flight['destination']) ?>
							· <?= e(format_datetime(strtotime($flight['departure_time']))) ?>
							<span class="status-pill status-pill--<?= e($statusModifier) ?>"><?= e($statusLabel) ?></span>
						</p>
					</div>
					<div class="table-actions no-print">
						<a class="btn-small" href="<?= e(BASE_URL) ?>admin/flight-form.php?id=<?= (int) $flight['flight_id'] ?>">Edit flight</a>
						<button type="button" class="btn-small no-print" data-print>Print</button>
					</div>
				</div>

				<h2 class="section-title">Passenger list</h2>
				<?php if (!$passengers): ?>
					<div class="panel panel-empty">
						<p>Nobody has paid for a seat on this flight yet.</p>
					</div>
				<?php else: ?>
					<div class="table-scroll">
						<table class="data-table">
							<thead>
								<tr>
									<th>Seat</th>
									<th>Ticket No.</th>
									<th>Passenger</th>
									<th>Phone</th>
									<th class="is-number">Amount</th>
									<th>Nepal Pay</th>
									<th>Paid at</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($passengers as $passenger): ?>
									<tr>
										<td><strong><?= e($passenger['seat_no']) ?></strong></td>
										<td class="is-nowrap"><?= e($passenger['booking_reference']) ?></td>
										<td>
											<?= e($passenger['full_name']) ?>
											<span class="cell-muted"><?= e($passenger['email']) ?></span>
										</td>
										<td class="is-nowrap"><?= e($passenger['phone'] ?: '—') ?></td>
										<td class="is-number"><?= $passenger['amount'] !== null ? e(format_money($passenger['amount'])) : '—' ?></td>
										<td class="is-nowrap">
											<?= e($passenger['payer_phone'] ?? '—') ?>
											<span class="cell-muted"><?= e($passenger['transaction_ref']) ?></span>
										</td>
										<td class="is-nowrap"><?= $passenger['paid_at'] ? e(format_datetime(strtotime($passenger['paid_at']))) : '—' ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>

				<h2 class="section-title">Seats on hold</h2>
				<?php if (!$holds): ?>
					<p class="cell-muted">No seats are on hold right now.</p>
				<?php else: ?>
					<div class="table-scroll">
						<table class="data-table">
							<thead>
								<tr>
									<th>Seat</th>
									<th>Customer</th>
									<th class="is-number">Time left</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($holds as $hold): ?>
									<tr>
										<td><strong><?= e($hold['seat_no']) ?></strong></td>
										<td>
											<?= e($hold['full_name']) ?>
											<span class="cell-muted"><?= e($hold['email']) ?></span>
										</td>
										<td class="is-number">
											<span data-seconds-left="<?= (int) $hold['seconds_left'] ?>"><?= e(format_countdown((int) $hold['seconds_left'])) ?></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</main>
	</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
