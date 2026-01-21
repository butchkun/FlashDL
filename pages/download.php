<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '' || strlen($id) > 200) { http_response_code(400); echo "Bad request"; exit; }

$pdo = db();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$viewer = current_user(); // peut rester public
$viewerId = $viewer ? (int)$viewer['id'] : null;

$stmt = $pdo->prepare("
  SELECT u.id, u.user_id, u.original_name, u.stored_relpath, u.bytes, u.deleted_at, u.password_hash
  FROM uploads u
  WHERE u.id=:id
  LIMIT 1
");
$stmt->execute([':id'=>$id]);
$up = $stmt->fetch();

if (!$up || $up['deleted_at']) {
    log_event('download_fail', $viewerId, $ip, "id=$id not_found_or_deleted");
    http_response_code(404); echo "Not found"; exit;
}

$rel = str_replace(['..','\\'], ['','/'], (string)$up['stored_relpath']);
$full = UPLOADS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

if (!is_file($full)) {
    log_event('download_fail', $viewerId, $ip, "id=$id missing_file rel=$rel");
    http_response_code(404); echo "Missing file"; exit;
}

$size = filesize($full);
$filename = basename((string)$up['original_name']) ?: basename($full);

// --- Password protection: if the upload row contains a password_hash, require it before streaming
$pwdHash = (string)($up['password_hash'] ?? '');
if ($pwdHash !== '') {
    $posted = (string)($_POST['password'] ?? '');
    if ($posted === '' || !password_verify($posted, $pwdHash)) {
        // Wrong/missing password: log and show form
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $posted !== '') {
            log_event('download_fail_pw', $viewerId, $ip, "id=$id wrong_password");
            $err = "Mot de passe incorrect.";
        } else {
            $err = '';
        }

        // Render a simple password prompt using site's header/footer for consistency
        require_once __DIR__ . '/../templates/header.php';
        ?>
        <div class="card" style="max-width:640px;margin:24px auto">
          <h2 style="margin-top:0">Téléchargement protégé</h2>
          <p class="muted">Ce fichier est protégé par mot de passe. Merci de le saisir pour démarrer le téléchargement.</p>
          <?php if (!empty($err)): ?><div class="error"><?=h($err)?></div><?php endif; ?>
          <form method="post" style="display:flex;gap:8px;align-items:center;margin-top:12px">
            <input type="password" name="password" placeholder="Mot de passe" style="flex:1;min-width:240px" autofocus required>
            <button class="btn" type="submit">Télécharger</button>
          </form>
          <p class="muted" style="margin-top:12px">URL : <code><?=h(BASE_URL . '/?p=download&id=' . rawurlencode($id))?></code></p>
        </div>
        <?php
        require_once __DIR__ . '/../templates/footer.php';
        exit;
    }
}

// --- hardening for big files
@set_time_limit(0);
@ignore_user_abort(true);
if (session_status() === PHP_SESSION_ACTIVE) {
    // évite de bloquer d'autres requêtes de l'utilisateur pendant le download
    session_write_close();
}

// Disable output buffering / compression
while (ob_get_level() > 0) { @ob_end_clean(); }
@ini_set('zlib.output_compression', '0');

// Range support
$start = 0;
$end = $size - 1;
$status = 200;

if (!empty($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] !== '') $start = (int)$m[1];
        if ($m[2] !== '') $end = (int)$m[2];

        if ($start > $end || $start >= $size) {
            header("Content-Range: bytes */$size");
            http_response_code(416);
            exit;
        }
        $end = min($end, $size - 1);
        $status = 206;
    }
}

$length = ($end - $start) + 1;

http_response_code($status);
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . str_replace('"','', $filename) . '"');
header('Accept-Ranges: bytes');
header('X-Content-Type-Options: nosniff');

if ($status === 206) {
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . (string)$length);

// Stats
$pdo->prepare("UPDATE uploads SET downloads_count = downloads_count+1, last_download_at=:t WHERE id=:id")
    ->execute([':t'=>date('c'), ':id'=>$id]);
log_event('download_ok', $viewerId, $ip, "id=$id bytes=$size range=$start-$end");

// Stream file
$fp = fopen($full, 'rb');
if (!$fp) { http_response_code(500); echo "Cannot open file"; exit; }

fseek($fp, $start);

$chunk = 1024 * 1024; // 1MB
$sent = 0;

while (!feof($fp) && $sent < $length) {
    $toRead = min($chunk, $length - $sent);
    $buf = fread($fp, $toRead);
    if ($buf === false) break;
    echo $buf;
    $sent += strlen($buf);

    // flush vers client
    if (function_exists('fastcgi_finish_request')) {
        // on ne l'appelle PAS ici, sinon on coupe trop tôt
    }
    flush();
}
fclose($fp);
exit;
