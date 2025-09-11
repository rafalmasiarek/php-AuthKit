<?php
// index.php
// Simple entry page with registration and login forms.

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

/** @var AuthKit\Auth $auth */
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'register') {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $res = $auth->register($email, $pass, []);
        if ($res instanceof AuthKit\User) {
            $notice = "Registered successfully.";
        } else {
            $error = is_string($res) ? $res : "Registration failed.";
        }
    } elseif ($action === 'login') {
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $token = $auth->login($email, $pass);
        if ($token) {
            redirect('account.php');
        } else {
            $error = "Invalid credentials.";
        }
    }
}
$user = $auth->getUser();
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>AuthKit Demo</title></head>
<body>
<h1>AuthKit Demo</h1>
<?php if ($notice) echo "<p style='color:green'>$notice</p>"; ?>
<?php if ($error) echo "<p style='color:red'>$error</p>"; ?>

<?php if ($user): ?>
<p>You are logged in as <?= htmlspecialchars($user->get('email')) ?>. <a href="logout.php">Logout</a></p>
<?php else: ?>
<form method="post">
<input type="hidden" name="action" value="register">
Email: <input type="email" name="email"><br>
Password: <input type="password" name="password"><br>
<button type="submit">Register</button>
</form>
<form method="post">
<input type="hidden" name="action" value="login">
Email: <input type="email" name="email"><br>
Password: <input type="password" name="password"><br>
<button type="submit">Login</button>
</form>
<?php endif; ?>
</body></html>
