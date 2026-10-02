<?php
// Admin side menu. The form and passenger pages belong to their list page's section.
$adminSection = [
	'routes.php' => 'routes',
	'route-form.php' => 'routes',
	'flights.php' => 'flights',
	'flight-form.php' => 'flights',
	'flight-passengers.php' => 'flights',
][basename($_SERVER['SCRIPT_NAME'])] ?? '';
?>
<aside class="admin-sidebar" aria-label="Admin menu">
	<nav class="admin-nav">
		<a class="<?= $adminSection === 'flights' ? 'is-active' : '' ?>" href="<?= e(BASE_URL) ?>admin/flights.php">Flights</a>
		<a class="<?= $adminSection === 'routes' ? 'is-active' : '' ?>" href="<?= e(BASE_URL) ?>admin/routes.php">Routes</a>
	</nav>
</aside>
