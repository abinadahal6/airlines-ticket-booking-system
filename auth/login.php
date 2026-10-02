<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['user_id'])) {
	redirect(dashboard_path_for_role($_SESSION['role']));
}

$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$email = input_string($_POST, 'email');
	$password = input_string($_POST, 'password', false);

	if (!verify_csrf()) {
		$error = 'Your session expired. Please try again.';
	} elseif ($email === '' || $password === '') {
		$error = 'Please enter your email and password.';
	} else {
		$stmt = $pdo->prepare('SELECT user_id, full_name, password, role, status FROM users WHERE email = ?');
		$stmt->execute([$email]);
		$user = $stmt->fetch();

		if (!$user || !password_verify($password, $user['password'])) {
			$error = 'Invalid email or password.';
		} elseif ($user['status'] !== 'active') {
			$error = 'Your account has been deactivated. Please contact the agency.';
		} else {
			session_regenerate_id(true);
			$_SESSION['user_id'] = (int) $user['user_id'];
			$_SESSION['role'] = $user['role'];
			$_SESSION['full_name'] = $user['full_name'];

			redirect(dashboard_path_for_role($user['role']));
		}
	}
}

$flash = get_flash();
$pageTitle = 'Login';
$hideNav = true;
require_once __DIR__ . '/../includes/header.php';
?>
	<div class="auth-layout">
		<aside class="auth-brand">
			<div class="auth-brand-inner">
				<span class="auth-brand-icon" aria-hidden="true">✈️</span>
				<p class="auth-brand-name">PK AIRLINE<br>TICKET SYSTEM</p>
			</div>
		</aside>

		<main class="auth-panel">
			<div class="auth-card">
				<h1 class="auth-title">Login</h1>
				<p class="auth-subtitle">Welcome back! Please login to continue.</p>

				<?php if ($flash): ?>
					<div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
				<?php endif; ?>

				<?php if ($error !== ''): ?>
					<div class="alert alert-error" role="alert"><?= e($error) ?></div>
				<?php endif; ?>

				<form method="post" action="login.php">
					<?= csrf_field() ?>

					<div class="form-group">
						<label class="form-label" for="email">Email</label>
						<input class="form-input" type="email" id="email" name="email" value="<?= e($email) ?>"
							placeholder="you@example.com" autocomplete="email" maxlength="100" required autofocus>
					</div>

					<div class="form-group">
						<label class="form-label" for="password">Password</label>
						<input class="form-input" type="password" id="password" name="password"
							placeholder="Enter your password" autocomplete="current-password" required>
					</div>

					<button class="btn-primary" type="submit">Login</button>
				</form>

				<p class="auth-switch">Don't have an account? <a href="register.php">Create one</a></p>
			</div>
		</main>
	</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
