<?php
function e(?string $value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
	header('Location: ' . BASE_URL . $path);
	exit;
}



// Link to a CSS/JS file with its modification time appended, so browsers fetch it again whenever it changes
// instead of reusing an old cached copy.
function asset_url(string $path): string
{
	return BASE_URL . $path . '?v=' . filemtime(__DIR__ . '/../' . $path);
}

function dashboard_path_for_role(string $role): string
{
	return $role === 'admin' ? 'admin/flights.php' : 'index.php';
}

// Value of an <input type="datetime-local"> ("2026-10-05T07:00") as a MySQL DATETIME, or null if invalid.
function parse_datetime_local(string $value): ?string
{
	$parsed = DateTime::createFromFormat('Y-m-d\TH:i', $value);

	return $parsed !== false && $parsed->format('Y-m-d\TH:i') === $value ? $parsed->format('Y-m-d H:i:s') : null;
}

// MySQL DATETIME as the value an <input type="datetime-local"> expects.
function to_datetime_local(string $datetime): string
{
	return date('Y-m-d\TH:i', strtotime($datetime));
}

function seat_numbers(int $totalSeats): array
{
	$perRow = count(SEAT_LETTERS);
	$seats = [];

	for ($i = 0; $i < $totalSeats; $i++) {
		$seats[] = (intdiv($i, $perRow) + 1) . SEAT_LETTERS[$i % $perRow];
	}

	return $seats;
}

// The outermost letters of a row are window seats; the ones beside the aisle are aisle seats.
function seat_position(string $seatNo): string
{
	$letter = substr($seatNo, -1);

	return in_array($letter, [SEAT_LETTERS[0], SEAT_LETTERS[count(SEAT_LETTERS) - 1]], true) ? 'Window' : 'Aisle';
}

// Read one request value ($_GET/$_POST) as text. A non-scalar value (e.g. ?tab[]=x) counts as empty,
// so pages never cast an array to a string or use it as an array key. Passwords pass $trim = false.
function input_string(array $source, string $key, bool $trim = true): string
{
	$value = $source[$key] ?? '';

	if (!is_scalar($value)) {
		return '';
	}

	return $trim ? trim((string) $value) : (string) $value;
}

// Read one request value as an id/number; anything that isn't a whole number becomes 0.
function input_int(array $source, string $key): int
{
	$value = input_string($source, $key);

	return preg_match('/^[0-9]+$/', $value) === 1 ? (int) $value : 0;
}

function is_valid_date(string $date): bool
{
	$parsed = DateTime::createFromFormat('Y-m-d', $date);

	return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function format_money(float|string $amount): string
{
	return 'Rs. ' . number_format((float) $amount);
}

function format_duration(string $start, string $end): string
{
	$minutes = intdiv(strtotime($end) - strtotime($start), 60);
	$hours = intdiv($minutes, 60);

	return ($hours > 0 ? $hours . 'h ' : '') . ($minutes % 60) . 'm';
}

function format_datetime(int $timestamp): string
{
	return date('D, j M Y · g:i A', $timestamp);
}

function format_countdown(int $seconds): string
{
	return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
}

function generate_booking_ref(): string
{
	return strtoupper(bin2hex(random_bytes(4)));
}

function generate_transaction_ref(): string
{
	return 'NP-' . strtoupper(bin2hex(random_bytes(4)));
}

// Simulated Nepal Pay login: any 10-digit number starting with NEPAL_PAY_PHONE_PREFIX is accepted.
function is_nepal_pay_phone(string $phone): bool
{
	return preg_match('/^[0-9]{10}$/', $phone) === 1 && str_starts_with($phone, NEPAL_PAY_PHONE_PREFIX);
}

function mask_phone(string $phone): string
{
	return substr($phone, 0, 6) . '••' . substr($phone, -2);
}

// These SQL fragments hold no request data, only fixed conditions; they exist so every page applies the
// same seat rules. A booking (alias b) holds its seat while it is paid, or pending with its hold unexpired.
const ACTIVE_BOOKING_SQL = "(b.status = 'paid' OR (b.status = 'pending' AND b.hold_until > NOW()))";

const SEATS_LEFT_SQL = '(f.total_seats - (
	SELECT COUNT(*) FROM bookings b WHERE b.flight_id = f.flight_id AND ' . ACTIVE_BOOKING_SQL . '
))';

// "A customer can still book this flight" (alias f): scheduled, before the booking cutoff, a seat left.
// Its one placeholder takes bookable_flight_params().
const BOOKABLE_FLIGHT_SQL = "f.status = 'scheduled'
	AND NOW() < f.departure_time - INTERVAL ? MINUTE
	AND " . SEATS_LEFT_SQL . ' > 0';

function bookable_flight_params(): array
{
	return [BOOKING_CUTOFF_MINUTES];
}

// Admin seat counts for a flight (alias f): paid tickets, and holds still running.
const PAID_SEATS_SQL = "(SELECT COUNT(*) FROM bookings b WHERE b.flight_id = f.flight_id AND b.status = 'paid')";

const HELD_SEATS_SQL = "(SELECT COUNT(*) FROM bookings b
	WHERE b.flight_id = f.flight_id AND b.status = 'pending' AND b.hold_until > NOW())";

// Lowest total_seats a flight can be given without dropping a seat that is booked (paid or held): the row of its
// highest active seat, rounded up to a full row. Returns null when the flight has no active bookings.
function min_seats_for_bookings(PDO $pdo, int $flightId): ?int
{
	$stmt = $pdo->prepare('SELECT b.seat_no FROM bookings b WHERE b.flight_id = ? AND ' . ACTIVE_BOOKING_SQL);
	$stmt->execute([$flightId]);
	$bookedSeats = $stmt->fetchAll(PDO::FETCH_COLUMN);

	if (!$bookedSeats) {
		return null;
	}

	$perRow = count(SEAT_LETTERS);
	$highestRow = max(array_map('intval', $bookedSeats));

	return max($perRow, $highestRow * $perRow);
}

// How a flight's status is shown to the admin. Expects status and has_departed (from SQL NOW()).
// Returns [css modifier, label]. A scheduled flight whose departure time has passed shows as Departed.
function flight_display_status(array $flight): array
{
	if ($flight['status'] === 'cancelled') {
		return ['cancelled', 'Cancelled'];
	}

	if ($flight['status'] === 'departed' || $flight['has_departed']) {
		return ['departed', 'Departed'];
	}

	return ['scheduled', 'Scheduled'];
}

function find_bookable_flights(PDO $pdo, string $origin, string $destination, string $date): array
{
	$stmt = $pdo->prepare("
		SELECT f.flight_id, f.flight_number, f.departure_time, f.arrival_time,
			f.fare, r.origin, r.destination, " . SEATS_LEFT_SQL . " AS seats_left
		FROM flights f
		JOIN routes r ON r.route_id = f.route_id
		WHERE r.origin = ? AND r.destination = ?
			AND DATE(f.departure_time) = ?
			AND " . BOOKABLE_FLIGHT_SQL . "
		ORDER BY f.departure_time
	");
	$stmt->execute([$origin, $destination, $date, ...bookable_flight_params()]);

	return $stmt->fetchAll();
}

// One booking of this customer (null if it isn't theirs), with its flight, payment and hold time left.
// seconds_left is computed in SQL so it uses the same clock as the hold rules.
function find_customer_booking(PDO $pdo, int $bookingId, int $userId): ?array
{
	$stmt = $pdo->prepare("
		SELECT b.booking_id, b.booking_reference, b.flight_id, b.seat_no, b.fare, b.status,
			f.flight_number, f.departure_time, f.arrival_time, r.origin, r.destination, u.full_name,
			p.payment_method, p.payer_phone, p.transaction_ref, p.paid_at,
			IFNULL(GREATEST(TIMESTAMPDIFF(SECOND, NOW(), b.hold_until), 0), 0) AS seconds_left
		FROM bookings b
		JOIN flights f ON f.flight_id = b.flight_id
		JOIN routes r ON r.route_id = f.route_id
		JOIN users u ON u.user_id = b.user_id
		LEFT JOIN payments p ON p.booking_id = b.booking_id
		WHERE b.booking_id = ? AND b.user_id = ?
	");
	$stmt->execute([$bookingId, $userId]);

	return $stmt->fetch() ?: null;
}

function is_hold_active(array $booking): bool
{
	return $booking['status'] === 'pending' && (int) $booking['seconds_left'] > 0;
}

// What a customer sees for a booking in My Bookings. Expects status, has_departed (from SQL NOW()),
// departure_time, paid_at and seconds_left. Returns [css modifier, label, detail line].
function booking_display_status(array $booking): array
{
	if ($booking['status'] === 'pending') {
		return ['pending', 'Awaiting payment', 'Seat held · ' . format_countdown((int) $booking['seconds_left']) . ' left'];
	}

	if ($booking['has_departed']) {
		return ['completed', 'Completed', 'Flown ' . date('j M Y', strtotime($booking['departure_time']))];
	}

	return ['paid', 'Paid', $booking['paid_at'] ? 'Paid on ' . date('j M Y', strtotime($booking['paid_at'])) : ''];
}

function csrf_token(): string
{
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}

	return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
	return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): bool
{
	return isset($_SESSION['csrf_token'], $_POST['csrf_token'])
		&& $_POST['csrf_token'] === $_SESSION['csrf_token'];
}

function set_flash(string $type, string $message): void
{
	$_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
	$flash = $_SESSION['flash'] ?? null;
	unset($_SESSION['flash']);

	return $flash;
}
