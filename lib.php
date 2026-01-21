<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function ensure_dir(string $dir): void {
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossible de créer le dossier: $dir");
        }
    }
}

function random_id(int $bytes = 18): string {
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function safe_filename(string $name): string {
    $name = basename($name);
    $name = preg_replace('/[^\w\.\- ]+/u', '_', $name) ?? 'file';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') $name = 'file';
    return $name;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    ensure_dir(DATA_DIR);
    ensure_dir(UPLOADS_DIR);

    $path = DATA_DIR . DIRECTORY_SEPARATOR . 'app.sqlite';
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Schema (create tables/indexes) — migrations are handled below via PRAGMA table_info.
    $pdo->exec("
        PRAGMA journal_mode=WAL;
        PRAGMA foreign_keys=ON;

        CREATE TABLE IF NOT EXISTS users (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          username TEXT UNIQUE NOT NULL,
          pass_hash TEXT NOT NULL,
          role TEXT NOT NULL DEFAULT 'user', -- 'user' or 'admin'
          enabled INTEGER NOT NULL DEFAULT 0,
          created_at TEXT NOT NULL,
          last_login_at TEXT,
          last_login_ip TEXT
        );

        CREATE TABLE IF NOT EXISTS uploads (
          id TEXT PRIMARY KEY,
          user_id INTEGER NOT NULL,
          created_at TEXT NOT NULL,
          uploader_ip TEXT,
          original_name TEXT NOT NULL,
          stored_relpath TEXT NOT NULL,
          bytes INTEGER NOT NULL,
          downloads_count INTEGER NOT NULL DEFAULT 0,
          last_download_at TEXT,
          deleted_at TEXT,
          password_hash TEXT,
          FOREIGN KEY(user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS events (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          ts TEXT NOT NULL,
          event TEXT NOT NULL, -- login_ok, login_fail, register, upload_ok, upload_fail, download_ok, delete_upload, ...
          user_id INTEGER,
          ip TEXT,
          details TEXT,
          FOREIGN KEY(user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS upload_sessions (
          token TEXT PRIMARY KEY,
          user_id INTEGER NOT NULL,
          created_at TEXT NOT NULL,
          uploader_ip TEXT,
          original_name TEXT NOT NULL,
          safe_name TEXT NOT NULL,
          total_bytes INTEGER NOT NULL,
          chunk_size INTEGER NOT NULL,
          total_chunks INTEGER NOT NULL,
          received_chunks INTEGER NOT NULL DEFAULT 0,
          tmp_dir TEXT NOT NULL,
          finalized_at TEXT,
          aborted_at TEXT,
          FOREIGN KEY(user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS bundle_files (
          token TEXT NOT NULL,
          file_index INTEGER NOT NULL,
          rel_path TEXT NOT NULL,
          total_bytes INTEGER NOT NULL,
          chunk_size INTEGER NOT NULL,
          total_chunks INTEGER NOT NULL,
          PRIMARY KEY(token, file_index),
          FOREIGN KEY(token) REFERENCES upload_sessions(token)
        );

        CREATE INDEX IF NOT EXISTS idx_bundle_files_token ON bundle_files(token);
        CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);
        CREATE INDEX IF NOT EXISTS idx_uploads_user ON uploads(user_id);
        CREATE INDEX IF NOT EXISTS idx_uploads_created ON uploads(created_at);
        CREATE INDEX IF NOT EXISTS idx_upload_sessions_user ON upload_sessions(user_id);
    ");

    // Lightweight migrations (SQLite has no IF NOT EXISTS for ADD COLUMN in older versions)
    // Only run when columns are missing.
    $existingCols = [];
    foreach ($pdo->query("PRAGMA table_info('uploads')") as $row) {
        $existingCols[strtolower((string)$row['name'])] = true;
    }
    if (!isset($existingCols['password_hash'])) {
        $pdo->exec("ALTER TABLE uploads ADD COLUMN password_hash TEXT");
    }

    bootstrap_admin($pdo);
    return $pdo;
}

function bootstrap_admin(PDO $pdo): void {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :u LIMIT 1");
    $stmt->execute([':u' => ADMIN_USERNAME]);
    $exists = $stmt->fetchColumn();

    if (!$exists) {
        $hash = password_hash(ADMIN_INITIAL_PASSWORD, PASSWORD_DEFAULT);
        $ins = $pdo->prepare("INSERT INTO users(username, pass_hash, role, enabled, created_at) VALUES(:u,:h,'admin',1,:t)");
        $ins->execute([
            ':u' => ADMIN_USERNAME,
            ':h' => $hash,
            ':t' => date('c'),
        ]);
    }
}

function log_event(string $event, ?int $userId, ?string $ip, string $details = ''): void {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT INTO events(ts,event,user_id,ip,details) VALUES(:ts,:e,:uid,:ip,:d)");
    $stmt->execute([
        ':ts' => date('c'),
        ':e'  => $event,
        ':uid'=> $userId,
        ':ip' => $ip,
        ':d'  => $details,
    ]);
}

function current_user(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username, role, enabled FROM users WHERE id=:id LIMIT 1");
    $stmt->execute([':id' => (int)$uid]);
    $u = $stmt->fetch();
    if (!$u) return null;
    return $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) {
        header('Location: /?p=login');
        exit;
    }
    if ((int)$u['enabled'] !== 1) {
        // compte désactivé
        session_destroy();
        header('Location: /?p=login&disabled=1');
        exit;
    }
    return $u;
}

function require_admin(): array {
    $u = require_login();
    if (($u['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
    return $u;
}

function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = random_id(18);
    return (string)$_SESSION['csrf'];
}

function csrf_check(string $token): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $sess = (string)($_SESSION['csrf'] ?? '');
    if ($sess === '' || !hash_equals($sess, $token)) {
        http_response_code(400);
        die("CSRF invalide.");
    }
}

function username_valid(string $u): bool {
    // lettres/chiffres/._- (3..32)
    return (bool)preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $u);
}
