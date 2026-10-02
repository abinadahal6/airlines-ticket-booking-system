<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_login();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$bookingId = input_int($_GET, 'booking_id');
$booking = find_customer_booking($pdo, $bookingId, (int) $_SESSION['user_id']);

if ($booking && $booking['status'] === 'paid') {
	redirect('customer/booking-confirmation.php?booking_id=' . $bookingId);
}

$isHoldActive = $booking && is_hold_active($booking);
$phone = '';
$error = '';

// Simulated Nepal Pay login: the phone number decides success (see is_nepal_pay_phone());
// the password only has to be filled in and is never stored.
if ($isHoldActive && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$phone = preg_replace('/[\s-]/', '', input_string($_POST, 'phone'));
	$password = input_string($_POST, 'password', false);

	if (!verify_csrf()) {
		$error = 'Your session expired. Please try again.';
	} elseif ($phone === '' || $password === '') {
		$error = 'Enter your ' . NEPAL_PAY_NAME . ' phone number and password.';
	} elseif (!is_nepal_pay_phone($phone)) {
		$error = 'Credentials did not match. Check your phone number and password and try again.';
	} else {
		$_SESSION['nepal_pay'] = ['booking_id' => $bookingId, 'phone' => $phone];
		redirect('customer/payment-confirm.php?booking_id=' . $bookingId);
	}
}

if (!$booking) {
	http_response_code(404);
}

$pageTitle = 'Payment';
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
					so the seat was released. You can choose a seat again.
				</p>
				<a class="btn-action" href="<?= e(BASE_URL) ?>customer/flight-details.php?flight_id=<?= (int) $booking['flight_id'] ?>">
					Choose seat again
				</a>
			</div>
		<?php else: ?>
			<div class="payment-layout">
				<section class="nepal-pay-card" aria-labelledby="nepal-pay-heading">
					<header class="nepal-pay-header">
						<span class="nepal-pay-logo"><?= e(NEPAL_PAY_NAME) ?></span>
						<span class="nepal-pay-secure">Secure payment</span>
					</header>

					<div class="nepal-pay-body">
						<h2 class="nepal-pay-title" id="nepal-pay-heading">Log in to pay <?= e(format_money($booking['fare'])) ?></h2>
						<p class="nepal-pay-subtitle">to <?= e(AIRLINE_NAME) ?> for ticket <?= e($booking['booking_reference']) ?></p>

						<?php if ($error): ?>
							<div class="alert alert-error" role="alert"><?= e($error) ?></div>
						<?php endif; ?>

						<form method="post" action="payment.php?booking_id=<?= (int) $bookingId ?>" autocomplete="off">
							<?= csrf_field() ?>

							<div class="form-group">
								<label class="form-label" for="phone"><?= e(NEPAL_PAY_NAME) ?> phone number</label>
								<input class="form-input" type="tel" id="phone" name="phone" value="<?= e($phone) ?>"
									placeholder="98XXXXXXXX" inputmode="numeric" maxlength="10" required autofocus>
							</div>

							<div class="form-group">
								<label class="form-label" for="password">Password</label>
								<input class="form-input" type="password" id="password" name="password"
									placeholder="Your <?= e(NEPAL_PAY_NAME) ?> password" required>
							</div>

							<button class="btn-primary btn-nepal-pay" type="submit">Log in &amp; Continue</button>
						</form>
					</div>
				</section>

				<aside class="panel order-summary" aria-labelledby="summary-heading">
					<h2 class="panel-title" id="summary-heading">Booking Summary</h2>

					<p class="hold-timer">
						Seat held for
						<strong data-seconds-left="<?= (int) $booking['seconds_left'] ?>"><?= e(format_countdown((int) $booking['seconds_left'])) ?></strong>
					</p>

					<dl class="detail-list">
						<div><dt>Booking Reference / Ticket No.</dt><dd><?= e($booking['booking_reference']) ?></dd></div>
						<div><dt>Flight</dt><dd><?= e($booking['flight_number']) ?> · <?= e($booking['origin']) ?> → <?= e($booking['destination']) ?></dd></div>
						<div><dt>Departure</dt><dd><?= e(format_datetime(strtotime($booking['departure_time']))) ?></dd></div>
						<div><dt>Seat</dt><dd><?= e($booking['seat_no']) ?> · <?= e(seat_position($booking['seat_no'])) ?></dd></div>
						<div><dt>Passenger</dt><dd><?= e($booking['full_name']) ?></dd></div>
					</dl>

					<div class="total-row">
						<span>Amount to pay</span>
						<strong><?= e(format_money($booking['fare'])) ?></strong>
					</div>

					<a class="btn-small" href="<?= e(BASE_URL) ?>customer/flight-details.php?flight_id=<?= (int) $booking['flight_id'] ?>">Change seat</a>
				</aside>
			</div>
		<?php endif; ?>
	</main>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
