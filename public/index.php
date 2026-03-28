<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$feed = new App\FeedAdapter($_ENV['TENNIS_API_KEY'] ?? '');
$matches = $feed->getLiveMatches();

$pageTitle = 'Live Matches — ' . ($_ENV['APP_NAME'] ?? 'Tennis Pattern Alerts');
include dirname(__DIR__) . '/src/templates/header.php';
?>

  <div class="card">
    <h1><?= htmlspecialchars($_ENV['APP_NAME'] ?? 'Tennis Pattern Alerts') ?></h1>
    <p class="small">Live match dashboard — <?= count($matches) ?> match(es) live</p>
  </div>

  <?php if (empty($matches)): ?>
    <div class="card">
      <p class="small">No live matches at the moment.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($matches as $m): ?>
    <div class="card">
      <div>
        <strong><?= htmlspecialchars($m['player1'] ?? '') ?></strong>
        vs
        <strong><?= htmlspecialchars($m['player2'] ?? '') ?></strong>
      </div>

      <div class="small" style="margin-top:8px;">
        <?= htmlspecialchars($m['tournament'] ?? '-') ?> |
        <?= htmlspecialchars($m['round'] ?? '-') ?> |
        <?= htmlspecialchars($m['level'] ?? '-') ?>
      </div>

      <div style="margin-top:8px;">
        Score: <strong><?= htmlspecialchars($m['score_text'] ?? '-') ?></strong>
        — <?= htmlspecialchars($m['status'] ?? '-') ?>
      </div>

      <div style="margin-top:10px;">
        <?php if (!empty($m['server'])): ?>
          <span class="badge">Serving: <?= htmlspecialchars($m['server']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

<?php include dirname(__DIR__) . '/src/templates/footer.php'; ?>
