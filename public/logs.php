<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$pdo = db();

try {
    $alerts = $pdo->query("SELECT * FROM alerts ORDER BY created_at DESC LIMIT 100")
        ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('logs.php: ' . $e->getMessage());
    $alerts = [];
}

$pageTitle = 'Alert Logs — ' . ($_ENV['APP_NAME'] ?? 'Tennis Pattern Alerts');
include dirname(__DIR__) . '/src/templates/header.php';
?>

  <div class="card">
    <h1>Alert Logs</h1>
    <p class="small">Recent pattern alerts sent via Telegram</p>
  </div>

  <?php if (empty($alerts)): ?>
    <div class="card">
      <p class="small">No alerts yet.</p>
    </div>
  <?php else: ?>
    <div class="card" style="overflow-x:auto;">
      <table class="table">
        <thead>
          <tr>
            <th>Time</th>
            <th>Match ID</th>
            <th>Player</th>
            <th>Rule</th>
            <th>Score</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($alerts as $alert): ?>
            <tr>
              <td class="small"><?= htmlspecialchars($alert['created_at'] ?? '') ?></td>
              <td class="small"><?= htmlspecialchars($alert['match_id'] ?? '') ?></td>
              <td><?= htmlspecialchars($alert['player_name'] ?? '') ?></td>
              <td><span class="badge"><?= htmlspecialchars($alert['rule_key'] ?? '') ?></span></td>
              <td><?= htmlspecialchars($alert['score_text'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<?php include dirname(__DIR__) . '/src/templates/footer.php'; ?>
