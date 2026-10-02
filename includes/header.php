<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/functions.php';

$documentTitle = isset($pageTitle) ? $pageTitle . ' | ' . SITE_NAME : SITE_NAME;
$navRole = $_SESSION['role'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?= e($documentTitle) ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap">
	<link rel="stylesheet" href="<?= e(asset_url('assets/css/style.css')) ?>">
</head>
<body>
<?php if (empty($hideNav)): ?>
	<header class="site-nav">
		<a class="site-nav-logo" href="<?= e(BASE_URL) ?>index.php"><span aria-hidden="true">✈</span> <?= e(SITE_NAME) ?></a>

		<nav class="site-nav-links" aria-label="Main">
			<?php if ($navRole === 'admin'): ?>
				<a href="<?= e(BASE_URL) ?>admin/flights.php">Flights</a>
				<a href="<?= e(BASE_URL) ?>admin/routes.php">Routes</a>
			<?php else: ?>
				<a href="<?= e(BASE_URL) ?>index.php">Home</a>
				<a href="<?= e(BASE_URL) ?>index.php">Flights</a>
				<?php if ($navRole === 'customer'): ?>
					<a href="<?= e(BASE_URL) ?>customer/my-bookings.php">My Bookings</a>
				<?php endif; ?>
				<a href="<?= e(BASE_URL) ?>index.php#contact">Contact</a>
			<?php endif; ?>
		</nav>

		<div class="site-nav-actions">
			<?php if ($navRole === null): ?>
				<a class="site-nav-login" href="<?= e(BASE_URL) ?>auth/login.php">Login</a>
				<a class="btn-nav" href="<?= e(BASE_URL) ?>auth/register.php">Sign Up</a>
			<?php else: ?>
				<span class="site-nav-user"><?= e($_SESSION['full_name'] ?? '') ?></span>
				<a class="btn-nav" href="<?= e(BASE_URL) ?>auth/logout.php">Logout</a>
			<?php endif; ?>
		</div>
	</header>
<?php endif; ?>
