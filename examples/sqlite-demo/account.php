<?php
// account.php
// Protected page that shows user info.

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$user = $auth->getUser();
if (!$user) {
    redirect('index.php');
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Account</title></head>
<body>
<h1>My Account</h1>
<p>Email: <?= htmlspecialchars($user->get('email')) ?></p>
<p>User ID: <?= (int)$user->get('id') ?></p>
<p><a href="logout.php">Logout</a></p>
</body></html>
