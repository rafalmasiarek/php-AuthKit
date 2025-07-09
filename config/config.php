<?php

return [
    'db' => new PDO('mysql:host=localhost;dbname=authkit', 'root', ''),
    'session_ttl' => 3600,
    'csrf_token_name' => '_csrf_token',
];
