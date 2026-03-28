<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

session_start();

$pdo = db();

// CSRF token generate
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF шалгалт
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }

    $id = (int)($_POST['id'] ?? 0);
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    $consecutive = max(1, (int)($_POST['consecutive'] ?? 2));

    $config = json_encode([
        'consecutive' => $consecutive,
    ], JSON_UNESCAPED_UNICODE);

    try {
        $stmt = $pdo->prepare("
            UPDATE rules
            SET enabled = :enabled,
                config_json = :config
            WHERE id = :id
        ");
        $stmt->execute([
            ':enabled' => $enabled,
            ':config' => $config,
            ':id' => $id,
        ]);
    } catch (PDOException $e) {
        error_log('rules.php: update failed: ' . $e->getMessage());
    }

    header('Location: rules.php');
    exit;
}

try {
    $rules = $pdo->query("SELECT * FROM rules ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('rules.php: ' . $e->getMessage());
    $rules = [];
}

$pageTitle = 'Rules — ' . ($_ENV['APP_NAME'] ?? 'Tennis Pattern Alerts');
include dirname(__DIR__) . '/src/templates/header.php';
?>

  <?php foreach ($rules as $rule):
    $cfg = json_decode((string)$rule['config_json'], true) ?: [];
  ?>
    <div class="card">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
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

<?php include dirname(__DIR__) . '/src/templates/footer.php'; ?>
