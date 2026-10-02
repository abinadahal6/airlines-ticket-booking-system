<?php
require_once __DIR__ . '/../includes/auth-guard.php';
require_admin();

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!verify_csrf()) {
		set_flash('error', 'Your session expired. Please try again.');
		redirect('admin/routes.php');
	}

	if (input_string($_POST, 'action') === 'delete') {
		$routeId = input_int($_POST, 'route_id');

		$stmt = $pdo->prepare('SELECT origin, destination FROM routes WHERE route_id = ?');
		$stmt->execute([$routeId]);
		$route = $stmt->fetch();

		if (!$route) {
			set_flash('error', 'That route no longer exists.');
			redirect('admin/routes.php');
		}

		$stmt = $pdo->prepare('SELECT COUNT(*) FROM flights WHERE route_id = ?');
		$stmt->execute([$routeId]);
		$hasFlights = (int) $stmt->fetchColumn() > 0;

		if (!$hasFlights) {
			// A flight added between the check and the delete makes the FK reject it; same outcome.
			try {
				$stmt = $pdo->prepare('DELETE FROM routes WHERE route_id = ?');
				$stmt->execute([$routeId]);
			} catch (PDOException $exception) {
				if ($exception->getCode() !== '23000') {
					throw $exception;
				}

				$hasFlights = true;
			}
		}

		if ($hasFlights) {
			set_flash('error', "This route has flights, so it can't be deleted.");
		} else {
			set_flash('success', 'Route ' . $route['origin'] . ' → ' . $route['destination'] . ' deleted.');
		}
	}

	redirect('admin/routes.php');
}

// has_flights only decides whether Delete is offered; a route in use can't be deleted.
$routes = $pdo->query('
	SELECT r.route_id, r.origin, r.destination,
		EXISTS (SELECT 1 FROM flights f WHERE f.route_id = r.route_id) AS has_flights
	FROM routes r
	ORDER BY r.origin, r.destination
')->fetchAll();

$flash = get_flash();
$pageTitle = 'Routes';
require_once __DIR__ . '/../includes/header.php';
?>
	<div class="admin-layout">
		<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>
		<main class="page admin-main">
			<div class="admin-header">
				<div>
					<h1 class="page-title page-title--tight">Routes</h1>
					<p class="page-subtitle">The city pairs <?= e(AIRLINE_NAME) ?> flies. A route with flights can't be deleted.</p>
				</div>
				<a class="btn-small btn-small--solid" href="<?= e(BASE_URL) ?>admin/route-form.php">Add Route</a>
			</div>

			<?php if ($flash): ?>
				<div class="alert alert-<?= e($flash['type']) ?> page-alert" role="status"><?= e($flash['message']) ?></div>
			<?php endif; ?>

			<?php if (!$routes): ?>
				<div class="panel panel-empty">
					<p>No routes yet. Add one to start scheduling flights.</p>
					<a class="btn-nav" href="<?= e(BASE_URL) ?>admin/route-form.php">Add Route</a>
				</div>
			<?php else: ?>
				<div class="table-scroll">
					<table class="data-table">
						<thead>
							<tr>
								<th>Origin</th>
								<th>Destination</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($routes as $route): ?>
								<tr>
									<td><?= e($route['origin']) ?></td>
									<td><?= e($route['destination']) ?></td>
									<td>
										<div class="table-actions">
											<a class="btn-small" href="<?= e(BASE_URL) ?>admin/route-form.php?id=<?= (int) $route['route_id'] ?>">Edit</a>
											<?php if ($route['has_flights']): ?>
												<button type="button" class="btn-small btn-small--danger" disabled
													title="This route has flights, so it can't be deleted.">Delete</button>
											<?php else: ?>
												<form class="inline-form" method="post" action="<?= e(BASE_URL) ?>admin/routes.php"
													data-confirm="Delete route <?= e($route['origin']) ?> → <?= e($route['destination']) ?>?">
													<?= csrf_field() ?>
													<input type="hidden" name="action" value="delete">
													<input type="hidden" name="route_id" value="<?= (int) $route['route_id'] ?>">
													<button type="submit" class="btn-small btn-small--danger">Delete</button>
												</form>
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
