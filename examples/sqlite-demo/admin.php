<?php
// admin.php
// Admin page to force logout users by email.

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email) {
        if (method_exists($auth, 'forceLogoutEmail')) {
            $count = $auth->forceLogoutEmail($email, 'Admin panel');
            $message = "Invalidated $count session(s) for $email.";
        } else {
            $message = "forceLogoutEmail() not available in this Auth version.";
        }
    }
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Admin</title></head>
<body>
<h1>Admin: Force Logout by Email</h1>
<?php if ($message) echo "<p>$message</p>"; ?>
<form method="post">
Email: <input type="email" name="email">
<button type="submit">Force Logout</button>
</form>
<p><a href="index.php">Back</a></p>
</body></html>
