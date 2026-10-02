<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_login();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$bookingId = input_int($_GET, 'booking_id');
$userId = (int) $_SESSION['user_id'];
$booking = find_customer_booking($pdo, $bookingId, $userId);

if ($booking && $booking['status'] === 'paid') {
	redirect('customer/booking-confirmation.php?booking_id=' . $bookingId);
}

$isHoldActive = $booking && is_hold_active($booking);
$login = $_SESSION['nepal_pay'] ?? null;
$isLoggedIn = $login && $login['booking_id'] === $bookingId;

// This step is only reachable after a successful Nepal Pay login for this same booking.
if ($isHoldActive && !$isLoggedIn) {
	redirect('customer/payment.php?booking_id=' . $bookingId);
}

$error = '';

if ($isHoldActive && $_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!verify_csrf()) {
		$error = 'Your session expired. Please try again.';
	} else {
		$pdo->beginTransaction();

		try {
			// The hold check is part of the UPDATE, so a hold that lapsed a moment ago can't be paid.
			$stmt = $pdo->prepare("
				UPDATE bookings
				SET status = 'paid', hold_until = NULL
				WHERE booking_id = ? AND user_id = ? AND status = 'pending' AND hold_until > NOW()
			");
			$stmt->execute([$bookingId, $userId]);

			if ($stmt->rowCount() === 0) {
				$pdo->rollBack();
				$isHoldActive = false;
			} else {
				// amount comes from the booking row, never from the form.
				$stmt = $pdo->prepare('
					INSERT INTO payments (booking_id, amount, payment_method, payer_phone, transaction_ref)
					SELECT booking_id, fare, ?, ?, ?
					FROM bookings
					WHERE booking_id = ?
				');
				$stmt->execute([NEPAL_PAY_NAME, $login['phone'], generate_transaction_ref(), $bookingId]);
				$pdo->commit();

				unset($_SESSION['nepal_pay']);
				redirect('customer/booking-confirmation.php?booking_id=' . $bookingId);
			}
		} catch (PDOException $e) {
			$pdo->rollBack();
			throw $e;
		}
	}
}

if (!$booking) {
	http_response_code(404);
}

$pageTitle = 'Confirm Payment';
require_once __DIR__ . '/../includes/header.php';
?>
	<main class="page">
		<h1 class="page-title">Payment</h1>

		<?php if (!$booking): ?>
			<div class="panel panel-empty">
				<p>We couldn't find this booking.</p>
				<a class="btn-nav" href="<?= e(BASE_URL) ?>index.php">Search flights</a>
			</div>
		<?php elseif (!$isHoldActive): ?>
			<div class="panel hold-expired">
				<h2 class="panel-title">Seat hold expired</h2>
				<p>
					Your <?= HOLD_MINUTES ?>-minute hold on seat <?= e($booking['seat_no']) ?> ran out before payment,
					so the seat was released and nothing was charged. You can choose a seat again.
				</p>
				<a class="btn-action" href="<?= e(BASE_URL) ?>customer/flight-details.php?flight_id=<?= (int) $booking['flight_id'] ?>">
					Choose seat again
				</a>
			</div>
		<?php else: ?>
			<section class="nepal-pay-card nepal-pay-card--narrow" aria-labelledby="confirm-heading">
				<header class="nepal-pay-header">
					<span class="nepal-pay-logo"><?= e(NEPAL_PAY_NAME) ?></span>
					<span class="nepal-pay-secure">Logged in as <?= e(mask_phone($login['phone'])) ?></span>
				</header>

				<div class="nepal-pay-body">
					<h2 class="nepal-pay-title" id="confirm-heading">Confirm payment</h2>
					<p class="nepal-pay-amount"><?= e(format_money($booking['fare'])) ?></p>

					<?php if ($error): ?>
						<div class="alert alert-error" role="alert"><?= e($error) ?></div>
					<?php endif; ?>

					<dl class="detail-list">
						<div><dt>Pay to</dt><dd><?= e(AIRLINE_NAME) ?></dd></div>
						<div><dt>From account</dt><dd><?= e(NEPAL_PAY_NAME) ?> · <?= e(mask_phone($login['phone'])) ?></dd></div>
						<div><dt>For</dt><dd>Ticket <?= e($booking['booking_reference']) ?></dd></div>
						<div><dt>Flight</dt><dd><?= e($booking['flight_number']) ?> · <?= e($booking['origin']) ?> → <?= e($booking['destination']) ?></dd></div>
						<div><dt>Seat</dt><dd><?= e($booking['seat_no']) ?></dd></div>
					</dl>

					<p class="hold-timer">
						Seat held for
						<strong data-seconds-left="<?= (int) $booking['seconds_left'] ?>"><?= e(format_countdown((int) $booking['seconds_left'])) ?></strong>
					</p>

					<form method="post" action="payment-confirm.php?booking_id=<?= (int) $bookingId ?>">
						<?= csrf_field() ?>
						<button class="btn-primary btn-nepal-pay" type="submit">Confirm Payment</button>
					</form>
					<a class="nepal-pay-cancel" href="<?= e(BASE_URL) ?>customer/payment.php?booking_id=<?= (int) $bookingId ?>">Cancel</a>
				</div>
			</section>
		<?php endif; ?>
	</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
