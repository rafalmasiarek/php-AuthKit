<?php
// bootstrap.php for MySQL demo
// Configure your MySQL connection details here.

declare(strict_types=1);

use AuthKit\Auth;
use AuthKit\Storage\PdoUserStorage;

require __DIR__ . '/../../vendor/autoload.php';

date_default_timezone_set('UTC');

$dsn = 'mysql:host=127.0.0.1;dbname=authkit_demo;charset=utf8mb4';
$user = 'root';
$pass = 'secret';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Make sure you ran schema.sql manually before starting.

$storage = new PdoUserStorage($pdo);
$auth = new Auth($storage, null, 3600);

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}
