<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_admin();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$isEdit = isset($_GET['id']);
$routeId = input_int($_GET, 'id');
$route = null;
$flightCount = 0;
$origin = '';
$destination = '';
$errors = [];

if ($isEdit) {
	$stmt = $pdo->prepare('
		SELECT r.route_id, r.origin, r.destination,
			(SELECT COUNT(*) FROM flights f WHERE f.route_id = r.route_id) AS flight_count
		FROM routes r
		WHERE r.route_id = ?
	');
	$stmt->execute([$routeId]);
	$route = $stmt->fetch() ?: null;

	if ($route) {
		$flightCount = (int) $route['flight_count'];
		$origin = $route['origin'];
		$destination = $route['destination'];
	}
}

$isNotFound = $isEdit && !$route;
$isLocked = $flightCount > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isNotFound) {
	if (!$isLocked) {
		$origin = input_string($_POST, 'origin');
		$destination = input_string($_POST, 'destination');
	}

	if (!verify_csrf()) {
		$errors[] = 'Your session expired. Please try again.';
	} elseif ($isLocked) {
		// A route in use can't be renamed: its flights and bookings would silently move to a different city pair.
		$errors[] = 'This route has flights, so it can\'t be changed.';
	} else {
		foreach (['Origin' => $origin, 'Destination' => $destination] as $label => $city) {
			if ($city === '') {
				$errors[] = 'Please enter the ' . strtolower($label) . ' city.';
			} elseif (mb_strlen($city) < 2 || mb_strlen($city) > 100) {
				$errors[] = $label . ' must be 2–100 characters long.';
			} elseif (!preg_match("/^[A-Za-z .'-]+$/", $city)) {
				$errors[] = $label . ' can only contain letters, spaces, dots, hyphens and apostrophes.';
			}
		}

		if (!$errors && strcasecmp($origin, $destination) === 0) {
			$errors[] = 'Origin and destination must be different cities.';
		}

		$duplicateMessage = 'A route from ' . $origin . ' to ' . $destination . ' already exists.';

		if (!$errors) {
			$stmt = $pdo->prepare('
				SELECT 1
				FROM routes
				WHERE LOWER(origin) = LOWER(?)
					AND LOWER(destination) = LOWER(?)
					AND route_id <> ?
			');
			$stmt->execute([$origin, $destination, $routeId]);

			if ($stmt->fetchColumn()) {
				$errors[] = $duplicateMessage;
			}
		}

		if (!$errors) {
			try {
				if ($isEdit) {
					$stmt = $pdo->prepare('
						UPDATE routes
						SET origin = ?, destination = ?
						WHERE route_id = ?
					');
					$stmt->execute([$origin, $destination, $routeId]);
				} else {
					$stmt = $pdo->prepare('INSERT INTO routes (origin, destination) VALUES (?, ?)');
					$stmt->execute([$origin, $destination]);
				}
			} catch (PDOException $exception) {
				// SQLSTATE 23000: another admin saved the same pair between our check and this write.
				if ($exception->getCode() !== '23000') {
					throw $exception;
				}

				$errors[] = $duplicateMessage;
			}
		}

		if (!$errors) {
			set_flash('success', 'Route ' . $origin . ' → ' . $destination . ($isEdit ? ' updated.' : ' added.'));
			redirect('admin/routes.php');
		}
	}
}

if ($isNotFound) {
	http_response_code(404);
}

$flash = get_flash();
$pageTitle = $isEdit ? 'Edit Route' : 'Add Route';
require_once __DIR__ . '/../includes/header.php';
?>
	<div class="admin-layout">
		<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
		<main class="page admin-main">
			<a class="back-link" href="<?= e(BASE_URL) ?>admin/routes.php">← Back to routes</a>
			<div class="admin-header">
				<div>
					<h1 class="page-title page-title--tight"><?= e($pageTitle) ?></h1>
					<p class="page-subtitle">
						<?= $isEdit ? 'Change the cities of this route.' : 'Add a new origin → destination pair that flights can use.' ?>
					</p>
				</div>
			</div>

			<?php if ($flash): ?>
				<div class="alert alert-<?= e($flash['type']) ?> page-alert" role="status"><?= e($flash['message']) ?></div>
			<?php endif; ?>

			<?php if ($isNotFound): ?>
				<div class="panel panel-empty">
					<p>This route doesn't exist. It may have been deleted.</p>
					<a class="btn-nav" href="<?= e(BASE_URL) ?>admin/routes.php">Back to routes</a>
				</div>
			<?php else: ?>
				<div class="panel form-card">
					<?php if ($errors): ?>
						<div class="alert alert-error" role="alert">
							<ul class="alert-list">
								<?php foreach ($errors as $error): ?>
									<li><?= e($error) ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<?php if ($isLocked): ?>
						<div class="alert alert-info" role="status">
							This route is used by <?= e((string) $flightCount) ?> <?= $flightCount === 1 ? 'flight' : 'flights' ?>, so it can't be changed.
						</div>
					<?php endif; ?>

					<form method="post" action="<?= e(BASE_URL) ?>admin/route-form.php<?= $isEdit ? '?id=' . e((string) $routeId) : '' ?>">
						<?= csrf_field() ?>

						<div class="form-grid">
							<div class="form-group">
								<label class="form-label" for="origin">Origin</label>
								<input class="form-input" type="text" id="origin" name="origin" value="<?= e($origin) ?>"
									placeholder="e.g. Kathmandu" maxlength="100" required<?= $isLocked ? ' disabled' : ' autofocus' ?>>
							</div>

							<div class="form-group">
								<label class="form-label" for="destination">Destination</label>
								<input class="form-input" type="text" id="destination" name="destination" value="<?= e($destination) ?>"
									placeholder="e.g. Pokhara" maxlength="100" required<?= $isLocked ? ' disabled' : '' ?>>
							</div>
						</div>

						<div class="form-actions">
							<?php if (!$isLocked): ?>
								<button class="btn-primary btn-inline" type="submit">Save</button>
							<?php endif; ?>
							<a class="btn-small" href="<?= e(BASE_URL) ?>admin/routes.php"><?= $isLocked ? 'Back to routes' : 'Cancel' ?></a>
						</div>
					</form>
				</div>
			<?php endif; ?>
		</main>
	</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
