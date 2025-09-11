<?php
// bootstrap.php for SQLite demo
// This file sets up the Auth instance using SQLite storage.

declare(strict_types=1);

use AuthKit\Auth;
use AuthKit\Storage\PdoUserStorage;

require __DIR__ . '/../../vendor/autoload.php';

date_default_timezone_set('UTC');

// SQLite database file in this demo directory
$dbFile = __DIR__ . '/authkit.sqlite';
$firstRun = !file_exists($dbFile);

$pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

if ($firstRun) {
    // Initialize schema
    $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
    $pdo->exec($schemaSql);
}

// Setup storage and Auth
$storage = new PdoUserStorage($pdo);
$auth = new Auth($storage, null, 3600);

function redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}
