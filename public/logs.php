<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

$matches = FeedAdapter::getLiveMatches();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(getenv('APP_NAME') ?: 'Tennis Pattern Alerts') ?></title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="index.php">Live Matches</a>
    <a href="rules.php">Rules</a>
    <a href="logs.php">Alert Logs</a>
  </div>

  <div class="card">
    <h1><?= htmlspecialchars(getenv('APP_NAME') ?: 'Tennis Pattern Alerts') ?></h1>
    <p class="small">Live match dashboard</p>
  </div>

  <?php foreach ($matches as $m): ?>
    <div class="card">
      <div>
        <strong><?= htmlspecialchars($m['player1'] ?? '') ?></strong>
        vs
        <strong><?= htmlspecialchars($m['player2'] ?? '') ?></strong>
      </div>

      <div class="small" style="margin-top:8px;">
        Level: <?= htmlspecialchars($m['level'] ?? '-') ?> |
        Surface: <?= htmlspecialchars($m['surface'] ?? '-') ?> |
        Score: <?= htmlspecialchars($m['score_text'] ?? '-') ?>
      </div>

      <div style="margin-top:10px;">
        <span class="badge">Server: <?= htmlspecialchars($m['server'] ?? '-') ?></span>
      </div>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
