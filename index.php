<?php
// PHP frontend -> Node API -> MySQL
const APP_VERSION = 'php-front-v1';

// ---- Settings: where the Node API is ----
// API_URL:  server address to call (the server IP, so no DNS is needed)
// API_HOST: the Node app's domain (sent as the Host header so cPanel routes to it)
$API_URL  = getenv('API_URL')  ?: 'http://216.10.251.130';
$API_HOST = getenv('API_HOST') ?: 'mysite3.masvrlogs.com';
// -----------------------------------------

function api($method, $path, $data = null) {
    global $API_URL, $API_HOST;
    if (!function_exists('curl_init')) {
        return [0, null, 'PHP curl extension is not enabled'];
    }
    $ch = curl_init(rtrim($API_URL, '/') . $path);
    $headers = ['Host: ' . $API_HOST, 'Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    if ($data !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) return [0, null, 'Cannot reach Node API: ' . $err];
    $json = json_decode($body, true);
    if ($json === null) return [$code, null, "Node API returned non-JSON (HTTP $code)"];
    if ($code >= 400) return [$code, $json, $json['error'] ?? "HTTP $code"];
    return [$code, $json, null];
}

$moods = ['happy' => '😀', 'neutral' => '😐', 'sad' => '😢', 'fire' => '🔥'];
$formError = null;

// Handle add / delete, then redirect so refresh doesn't resubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        [$code, , $err] = api('POST', '/api/entries', [
            'name'    => trim($_POST['name'] ?? ''),
            'message' => trim($_POST['message'] ?? ''),
            'mood'    => $_POST['mood'] ?? 'neutral',
        ]);
        if (!$err) { header('Location: ?saved=1'); exit; }
        $formError = $err;
    } elseif ($action === 'delete') {
        [, , $err] = api('DELETE', '/api/entries/' . (int)($_POST['id'] ?? 0));
        if (!$err) { header('Location: ?deleted=1'); exit; }
        $formError = $err;
    }
}
$flash = isset($_GET['saved']) ? 'Entry saved (via Node API).' : (isset($_GET['deleted']) ? 'Entry deleted (via Node API).' : null);

// Load data from Node
$q = trim($_GET['q'] ?? '');
$t0 = microtime(true);
[, $health, $healthErr] = api('GET', '/health');
$apiMs = round((microtime(true) - $t0) * 1000);
[, $stats, $statsErr]     = api('GET', '/api/stats');
[, $entries, $entriesErr] = api('GET', '/api/entries' . ($q !== '' ? '?q=' . urlencode($q) : ''));

$apiOk = !$healthErr && $health;
$dbOk  = $apiOk && ($health['db'] ?? '') === 'connected';
$entries = is_array($entries) ? $entries : [];
$stats   = is_array($stats) ? $stats : ['total' => 0, 'today' => 0, 'people' => 0, 'mysql' => '-'];

function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP + Node + MySQL</title>
<style>
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, Arial, sans-serif; background: #0f172a; color: #e2e8f0; padding: 30px 16px; }
  .wrap { max-width: 640px; margin: 0 auto; }
  .card { background: #1e293b; border-radius: 14px; padding: 22px; margin-bottom: 18px; box-shadow: 0 10px 30px rgba(0,0,0,.35); }
  h1 { font-size: 20px; margin: 0 0 16px; }
  h2 { font-size: 15px; margin: 0 0 14px; color: #94a3b8; font-weight: 600; }
  .chain { display: flex; align-items: stretch; gap: 8px; }
  .node { flex: 1; background: #0f172a; border-radius: 10px; padding: 12px; text-align: center; border: 2px solid #22c55e; }
  .node.bad { border-color: #ef4444; }
  .node b { display: block; font-size: 15px; }
  .node span { display: block; font-size: 11px; color: #94a3b8; margin-top: 4px; word-break: break-word; }
  .arrow { align-self: center; color: #64748b; font-size: 18px; }
  .err { color: #fca5a5; font-size: 13px; margin-top: 10px; word-break: break-word; }
  .meta { font-size: 12px; color: #64748b; margin-top: 10px; }
  .flash { background: #164e63; color: #a5f3fc; padding: 10px 14px; border-radius: 8px; margin-bottom: 18px; font-size: 14px; }
  .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 14px; }
  .stat { background: #0f172a; border-radius: 10px; padding: 12px; text-align: center; }
  .stat b { display: block; font-size: 22px; color: #38bdf8; }
  .stat span { font-size: 12px; color: #94a3b8; }
  input, textarea, select { width: 100%; padding: 10px 12px; border-radius: 8px; border: 1px solid #334155; background: #0f172a; color: #e2e8f0; font: inherit; margin-bottom: 10px; }
  textarea { resize: vertical; min-height: 70px; }
  .row { display: flex; gap: 10px; }
  .row > * { margin-bottom: 0; }
  button { background: #38bdf8; color: #082f49; border: 0; padding: 10px 18px; border-radius: 8px; font-weight: 700; cursor: pointer; white-space: nowrap; }
  button:disabled { opacity: .5; cursor: default; }
  .del { background: transparent; color: #f87171; padding: 4px 8px; font-size: 12px; border: 1px solid #7f1d1d; }
  .entry { border-top: 1px solid #334155; padding: 12px 0; display: flex; gap: 12px; align-items: flex-start; }
  .entry:first-child { border-top: 0; padding-top: 0; }
  .mood { font-size: 22px; line-height: 1; }
  .body { flex: 1; min-width: 0; }
  .body b { color: #38bdf8; }
  .body small { color: #64748b; margin-left: 6px; }
  .body p { margin: 4px 0 0; white-space: pre-wrap; word-break: break-word; }
  .empty { color: #64748b; font-size: 14px; }
  a { color: #38bdf8; }
</style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1>PHP + Node + MySQL</h1>
    <div class="chain">
      <div class="node">
        <b>PHP</b><span><?= e(PHP_VERSION) ?></span>
      </div>
      <div class="arrow">→</div>
      <div class="node <?= $apiOk ? '' : 'bad' ?>">
        <b>Node API</b><span><?= $apiOk ? e($health['node'] . ' · ' . $health['version']) : 'unreachable' ?></span>
      </div>
      <div class="arrow">→</div>
      <div class="node <?= $dbOk ? '' : 'bad' ?>">
        <b>MySQL</b><span><?= $dbOk ? e($stats['mysql'] ?? 'connected') : 'error' ?></span>
      </div>
    </div>
    <?php if ($healthErr): ?><div class="err"><?= e($healthErr) ?></div><?php endif; ?>
    <?php if ($apiOk && !$dbOk): ?><div class="err"><?= e($health['dbError'] ?? 'DB error') ?></div><?php endif; ?>
    <div class="stats">
      <div class="stat"><b><?= (int)$stats['total'] ?></b><span>Total entries</span></div>
      <div class="stat"><b><?= (int)$stats['today'] ?></b><span>Added today</span></div>
      <div class="stat"><b><?= (int)$stats['people'] ?></b><span>People</span></div>
    </div>
    <div class="meta">
      <?= e(APP_VERSION) ?> · API: <?= e($API_URL) ?> (Host: <?= e($API_HOST) ?>) · <?= $apiMs ?> ms
      <?php if ($apiOk): ?> · Node up <?= (int)$health['uptime'] ?>s<?php endif; ?>
    </div>
  </div>

  <?php if ($flash): ?><div class="flash"><?= e($flash) ?></div><?php endif; ?>

  <div class="card">
    <h2>Add entry</h2>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <div class="row" style="margin-bottom:10px">
        <input name="name" placeholder="Name" maxlength="100" required>
        <select name="mood" style="max-width:130px">
          <?php foreach ($moods as $k => $icon): ?>
            <option value="<?= $k ?>" <?= $k === 'neutral' ? 'selected' : '' ?>><?= $icon ?> <?= ucfirst($k) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <textarea name="message" placeholder="Message" maxlength="500" required></textarea>
      <button type="submit" <?= $dbOk ? '' : 'disabled' ?>>Save</button>
      <?php if ($formError): ?><span class="err"><?= e($formError) ?></span><?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2>Saved entries</h2>
    <form method="get" class="row" style="margin-bottom:16px">
      <input name="q" value="<?= e($q) ?>" placeholder="Search name or message">
      <button type="submit">Search</button>
    </form>
    <?php if ($q !== ''): ?>
      <div class="meta" style="margin:-6px 0 14px"><?= count($entries) ?> result(s) for "<?= e($q) ?>" · <a href="?">clear</a></div>
    <?php endif; ?>
    <?php if ($entriesErr): ?><div class="err"><?= e($entriesErr) ?></div><?php endif; ?>
    <?php if (!$entries && !$entriesErr): ?>
      <div class="empty"><?= $q !== '' ? 'No matches.' : 'No entries yet. Add one above.' ?></div>
    <?php endif; ?>
    <?php foreach ($entries as $row): ?>
      <div class="entry">
        <div class="mood"><?= $moods[$row['mood'] ?? 'neutral'] ?? '😐' ?></div>
        <div class="body">
          <b><?= e($row['name']) ?></b><small>#<?= (int)$row['id'] ?> · <?= e(date('Y-m-d H:i', strtotime($row['created_at']))) ?></small>
          <p><?= e($row['message']) ?></p>
        </div>
        <form method="post" onsubmit="return confirm('Delete this entry?')">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
          <button class="del" type="submit">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>

</div>
</body>
</html>
