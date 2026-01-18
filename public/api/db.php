<?php

$pdo = new PDO(
    "pgsql:host=127.0.0.1;dbname=walk_routes",
    "postgres",
    "qwerty12345@",
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);
