<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? ($_ENV['APP_NAME'] ?? 'Tennis Pattern Alerts')) ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="index.php">Live Matches</a>
    <a href="rules.php">Rules</a>
    <a href="logs.php">Alert Logs</a>
  </div>
