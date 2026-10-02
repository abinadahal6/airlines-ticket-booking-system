<?php
require_once __DIR__ . '/includes/auth-guard.php';
require_login();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$routes = $pdo->query("
	SELECT DISTINCT origin, destination
	FROM routes
	ORDER BY origin, destination
")->fetchAll();
$origins = array_unique(array_column($routes, 'origin'));

$today = date('Y-m-d');

$from = input_string($_GET, 'from');
$to = input_string($_GET, 'to');
$departure = input_string($_GET, 'departure') ?: $today;

$isSearch = isset($_GET['from'], $_GET['to'], $_GET['departure']);
$errors = [];
$flights = [];

if ($isSearch) {
	if (!in_array(['origin' => $from, 'destination' => $to], $routes, true)) {
		$errors[] = 'Please choose a valid route.';
	}

	if (!is_valid_date($departure) || $departure < $today) {
		$errors[] = 'Please choose a departure date from today onwards.';
	}

	if (!$errors) {
		$flights = find_bookable_flights($pdo, $from, $to, $departure);
	}
}

$pageTitle = 'Search Flights';
require_once __DIR__ . '/includes/header.php';
?>
	<main class="landing">
		<section class="hero">
			<div class="hero-text">
				<h1 class="hero-title">Book Your Next<br>Journey with Us</h1>
				<p class="hero-subtitle">Search the best flights and book tickets with ease.</p>
			</div>
			<div class="hero-art" aria-hidden="true">✈️</div>
		</section>

		<section class="search-card" aria-label="Search flights">
			<form method="get" action="<?= e(BASE_URL) ?>index.php#results">
				<div class="search-fields">
					<div class="search-field search-field--wide">
						<label class="form-label" for="from">From</label>
						<select class="form-input" id="from" name="from" required>
							<option value="" disabled <?= $from === '' ? 'selected' : '' ?>>Select city</option>
							<?php foreach ($origins as $origin): ?>
								<option value="<?= e($origin) ?>" <?= $origin === $from ? 'selected' : '' ?>><?= e($origin) ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="search-field search-field--wide">
						<label class="form-label" for="to">To</label>
						<?php /* One option per route; script.js shows only the ones matching the chosen origin. */ ?>
						<select class="form-input" id="to" name="to" required>
							<option value="" disabled <?= $to === '' ? 'selected' : '' ?>>Select city</option>
							<?php foreach ($routes as $route): ?>
								<option value="<?= e($route['destination']) ?>" data-origin="<?= e($route['origin']) ?>"
									<?= $route['origin'] === $from && $route['destination'] === $to ? 'selected' : '' ?>>
									<?= e($route['destination']) ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="search-field">
						<label class="form-label" for="departure">Departure</label>
						<input class="form-input" type="date" id="departure" name="departure"
							value="<?= e($departure) ?>" min="<?= e($today) ?>" required>
					</div>
				</div>

				<button class="btn-primary btn-search" type="submit">Search Flights</button>
			</form>
		</section>

		<?php if ($isSearch): ?>
			<section class="results" id="results" aria-label="Search results">
				<?php if ($errors): ?>
					<div class="alert alert-error" role="alert">
						<ul class="alert-list">
							<?php foreach ($errors as $error): ?>
								<li><?= e($error) ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if (!$errors): ?>
					<h2 class="results-title"><?= e($from) ?> → <?= e($to) ?></h2>
					<p class="results-meta">
						<?= e(date('D, j M Y', strtotime($departure))) ?> ·
						<?= count($flights) ?> flight<?= count($flights) === 1 ? '' : 's' ?> found
					</p>

					<?php if (!$flights): ?>
						<p class="results-empty">No flights available on this date. Try another date.</p>
					<?php endif; ?>

					<?php foreach ($flights as $flight): ?>
						<article class="flight-card">
							<div class="flight-card-airline">
								<span class="flight-card-number"><?= e($flight['flight_number']) ?></span>
								<span class="flight-card-muted"><?= e(AIRLINE_NAME) ?></span>
							</div>

							<div class="flight-card-times">
								<div>
									<span class="flight-card-time"><?= e(date('H:i', strtotime($flight['departure_time']))) ?></span>
									<span class="flight-card-muted"><?= e($flight['origin']) ?></span>
								</div>
								<span class="flight-card-duration">
									<?= e(format_duration($flight['departure_time'], $flight['arrival_time'])) ?>
								</span>
								<div>
									<span class="flight-card-time"><?= e(date('H:i', strtotime($flight['arrival_time']))) ?></span>
									<span class="flight-card-muted"><?= e($flight['destination']) ?></span>
								</div>
							</div>

							<span class="flight-card-muted"><?= (int) $flight['seats_left'] ?> seats left</span>
							<span class="flight-card-fare"><?= e(format_money($flight['fare'])) ?></span>

							<a class="btn-nav flight-card-select"
								href="<?= e(BASE_URL) ?>customer/flight-details.php?flight_id=<?= (int) $flight['flight_id'] ?>">
								Select
							</a>
						</article>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>
		<?php endif; ?>
	</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
