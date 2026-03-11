<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $consecutive = max(1, (int)($_POST['consecutive'] ?? 2));

    $config = json_encode([
        'consecutive' => $consecutive
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        UPDATE rules
        SET enabled = :enabled,
            config_json = :config
        WHERE id = :id
    ");

    $stmt->execute([
        ':enabled' => $enabled,
        ':config' => $config,
        ':id' => $id
    ]);

    header('Location: rules.php');
    exit;
}

$rules = $pdo->query("SELECT * FROM rules ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Rules</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
  <div class="nav">
    <a href="index.php">Live Matches</a>
    <a href="rules.php">Rules</a>
    <a href="logs.php">Alert Logs</a>
  </div>

  <?php foreach ($rules as $rule):
    $cfg = json_decode((string)$rule['config_json'], true) ?: [];
  ?>
    <div class="card">
      <form method="post">
        <input type="hidden" name="id" value="<?= (int)$rule['id'] ?>">

        <h2><?= htmlspecialchars($rule['name']) ?></h2>

        <label>
          <input type="checkbox" name="enabled" <?= (int)$rule['enabled'] === 1 ? 'checked' : '' ?> style="width:auto;">
          Enabled
        </label>

        <div style="margin-top:12px;">
          <label>Consecutive games</label>
          <input type="number" name="consecutive" value="<?= (int)($cfg['consecutive'] ?? 2) ?>">
        </div>

        <div style="margin-top:12px;">
          <button type="submit">Save rule</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
