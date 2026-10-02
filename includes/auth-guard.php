<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/functions.php';

// Customer pages: guests go to login, admins go back to their own start page (admin/flights.php).
function require_login(): void
{
	if (!isset($_SESSION['user_id'])) {
		redirect('auth/login.php');
	}

	if ($_SESSION['role'] !== 'customer') {
		redirect(dashboard_path_for_role($_SESSION['role']));
	}
}

function require_admin(): void
{
	if (!isset($_SESSION['user_id'])) {
		redirect('auth/login.php');
	}

	if ($_SESSION['role'] !== 'admin') {
		redirect(dashboard_path_for_role($_SESSION['role']));
	}
}
