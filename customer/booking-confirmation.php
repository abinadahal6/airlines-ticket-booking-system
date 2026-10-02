<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_login();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$bookingId = input_int($_GET, 'booking_id');
$booking = find_customer_booking($pdo, $bookingId, (int) $_SESSION['user_id']);

// An unpaid booking isn't a ticket yet: while its hold lasts, send the customer back to pay.
if ($booking && is_hold_active($booking)) {
	redirect('customer/payment.php?booking_id=' . $bookingId);
}

$isPaid = $booking && $booking['status'] === 'paid';

if (!$isPaid) {
	http_response_code(404);
}

$pageTitle = $isPaid ? 'Booking Confirmed' : 'Booking not found';
require_once __DIR__ . '/../includes/header.php';
?>
	<main class="page">
		<h1 class="page-title"><?= e($pageTitle) ?></h1>

		<?php if (!$isPaid): ?>
			<div class="panel panel-empty">
				<p>We couldn't find this booking. If a seat hold ran out before payment, the seat was released.</p>
				<a class="btn-nav" href="<?= e(BASE_URL) ?>index.php">Search flights</a>
			</div>
		<?php else: ?>
			<section class="panel confirm-card">
				<div class="confirm-head">
					<span class="status-icon status-icon--paid" aria-hidden="true">✓</span>
					<h2 class="confirm-title">Payment successful!</h2>
					<p class="confirm-subtitle">Your ticket is booked. Show your ticket number at check-in.</p>
				</div>

				<div class="reference-box">
					<div>
						<p class="reference-label">Booking Reference / Ticket No.</p>
						<p class="reference-value"><?= e($booking['booking_reference']) ?></p>
					</div>
					<span class="status-pill status-pill--paid">Paid</span>
				</div>

				<dl class="confirm-details">
					<div>
						<dt>Flight</dt>
						<dd><?= e($booking['flight_number']) ?> · <?= e($booking['origin']) ?> → <?= e($booking['destination']) ?></dd>
					</div>
					<div>
						<dt>Departure</dt>
						<dd><?= e(format_datetime(strtotime($booking['departure_time']))) ?></dd>
					</div>
					<div>
						<dt>Seat</dt>
						<dd><?= e($booking['seat_no']) ?> · <?= e(seat_position($booking['seat_no'])) ?></dd>
					</div>
					<div>
						<dt>Passenger</dt>
						<dd><?= e($booking['full_name']) ?></dd>
					</div>
					<div class="is-amount">
						<dt>Amount paid</dt>
						<dd><?= e(format_money($booking['fare'])) ?></dd>
					</div>
					<div>
						<dt>Paid with</dt>
						<dd><?= e($booking['payment_method']) ?> · <?= e(mask_phone($booking['payer_phone'])) ?></dd>
					</div>
				</dl>

				<div class="receipt-row">
					<span><?= e(NEPAL_PAY_NAME) ?> transaction <strong><?= e($booking['transaction_ref']) ?></strong></span>
					<span><?= e(format_datetime(strtotime($booking['paid_at']))) ?></span>
				</div>

				<div class="confirm-actions">
					<a class="btn-action" href="<?= e(BASE_URL) ?>customer/my-bookings.php">Go to My Bookings</a>
					<a class="btn-action btn-action--outline" href="<?= e(BASE_URL) ?>index.php">Book Another Flight</a>
				</div>
			</section>
		<?php endif; ?>
	</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
