<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['user_id'])) {
	redirect(dashboard_path_for_role($_SESSION['role']));
}

$fullName = '';
$email = '';
$phone = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$fullName = input_string($_POST, 'full_name');
	$email = input_string($_POST, 'email');
	$phone = input_string($_POST, 'phone');
	$password = input_string($_POST, 'password', false);
	$confirmPassword = input_string($_POST, 'confirm_password', false);

	if (!verify_csrf()) {
		$errors[] = 'Your session expired. Please try again.';
	} else {
		if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
			$errors[] = 'Please enter your full name (2–100 characters).';
		} elseif (!preg_match("/^[\\p{L}\\p{M} .'-]+$/u", $fullName)) {
			$errors[] = 'Full name can only contain letters, spaces, dots, hyphens and apostrophes.';
		}

		if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
			$errors[] = 'Please enter a valid email address.';
		}

		if ($phone !== '' && !preg_match('/^\+?[0-9 -]{7,20}$/', $phone)) {
			$errors[] = 'Please enter a valid phone number, or leave it blank.';
		}

		// bcrypt only uses the first 72 bytes, so a longer password would be silently cut short.
		if (strlen($password) < 8) {
			$errors[] = 'Password must be at least 8 characters.';
		} elseif (strlen($password) > 72) {
			$errors[] = 'Password must be at most 72 characters.';
		} elseif ($password !== $confirmPassword) {
			$errors[] = 'Passwords do not match.';
		}
	}

	if (!$errors) {
		$stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
		$stmt->execute([$email]);

		if ($stmt->fetchColumn()) {
			$errors[] = 'An account with this email already exists.';
		}
	}

	if (!$errors) {
		$stmt = $pdo->prepare("
			INSERT INTO users (full_name, email, password, phone, role, status)
			VALUES (?, ?, ?, ?, 'customer', 'active')
		");
		$stmt->execute([
			$fullName,
			$email,
			password_hash($password, PASSWORD_DEFAULT),
			$phone === '' ? null : $phone,
		]);

		set_flash('success', 'Account created. You can now log in.');
		redirect('auth/login.php');
	}
}

$pageTitle = 'Create Account';
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

		<main class="auth-panel auth-panel--compact">
			<div class="auth-card">
				<h1 class="auth-title">Create Account</h1>
				<p class="auth-subtitle">Sign up to search and book your flights.</p>

				<?php if ($errors): ?>
					<div class="alert alert-error" role="alert">
						<ul class="alert-list">
							<?php foreach ($errors as $error): ?>
								<li><?= e($error) ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<form method="post" action="register.php">
					<?= csrf_field() ?>

					<div class="form-group">
						<label class="form-label" for="full_name">Full Name</label>
						<input class="form-input" type="text" id="full_name" name="full_name" value="<?= e($fullName) ?>"
							placeholder="Your full name" autocomplete="name" maxlength="100" required autofocus>
					</div>

					<div class="form-row">
						<div class="form-group">
							<label class="form-label" for="email">Email</label>
							<input class="form-input" type="email" id="email" name="email" value="<?= e($email) ?>"
								placeholder="you@example.com" autocomplete="email" maxlength="100" required>
						</div>

						<div class="form-group">
							<label class="form-label" for="phone">Phone <span class="form-optional">(optional)</span></label>
							<input class="form-input" type="tel" id="phone" name="phone" value="<?= e($phone) ?>"
								placeholder="98XXXXXXXX" autocomplete="tel" maxlength="20">
						</div>
					</div>

					<div class="form-row">
						<div class="form-group">
							<label class="form-label" for="password">Password</label>
							<input class="form-input" type="password" id="password" name="password"
								placeholder="At least 8 characters" autocomplete="new-password" minlength="8" maxlength="72" required>
						</div>

						<div class="form-group">
							<label class="form-label" for="confirm_password">Confirm Password</label>
							<input class="form-input" type="password" id="confirm_password" name="confirm_password"
								placeholder="Re-enter your password" autocomplete="new-password" maxlength="72" required>
						</div>
					</div>
					<p class="form-hint form-row-hint">There is no password reset, so keep your password somewhere safe.</p>

					<button class="btn-primary" type="submit">Create Account</button>
				</form>

				<p class="auth-switch">Already have an account? <a href="login.php">Login</a></p>
			</div>
		</main>
	</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
