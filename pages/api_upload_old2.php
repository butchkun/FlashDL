<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

header('Cache-Control: no-store');

$u = require_login();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

set_exception_handler(function(Throwable $e) {
  // Avoid leaking sensitive info in production; still return JSON for the frontend
  json_out(['ok'=>false,'error'=>'exception','message'=>$e->getMessage()], 500);
});
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
if ($action === '') json_out(['ok'=>false,'error'=>'missing_action'], 400);

$pdo = db();

// CSRF check for POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check((string)($_POST['csrf'] ?? ''));
}

function tmp_root(): string {
  $dir = UPLOADS_DIR . DIRECTORY_SEPARATOR . '.tmp';
  ensure_dir($dir);
  return $dir;
}

function rrmdir(string $dir): void {
  // Safety: only delete inside uploads/.tmp
  $real = realpath($dir);
  $tmpBase = realpath(tmp_root());
  if ($real === false || $tmpBase === false) return;
  if (strpos($real, $tmpBase) !== 0) return;

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );
  foreach ($it as $file) {
    /** @var SplFileInfo $file */
    $path = $file->getRealPath();
    if ($path === false) continue;
    if ($file->isDir()) {
      @rmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($real);
}

function safe_rel(string $p): string {
  $p = str_replace(['..','\\'], ['','/'], $p);
  return trim($p, '/');
}

/**
 * INIT: start session
 * POST: action=init, original_name, total_bytes, chunk_size
 */
if ($action === 'init') {
  $original = trim((string)($_POST['original_name'] ?? ''));
  $totalBytes = (int)($_POST['total_bytes'] ?? 0);
  $chunkSize  = (int)($_POST['chunk_size'] ?? 0);

  if ($original === '' || $totalBytes <= 0 || $chunkSize <= 0) {
    json_out(['ok'=>false,'error'=>'bad_params'], 400);
  }

  // chunk size sanity (avoid people sending 2GB chunks)
  if ($chunkSize > 128 * 1024 * 1024) { // 128MB
    json_out(['ok'=>false,'error'=>'chunk_too_large'], 400);
  }

  $safeName = safe_filename($original);
  $totalChunks = (int)ceil($totalBytes / $chunkSize);
  if ($totalChunks <= 0 || $totalChunks > 500000) {
    json_out(['ok'=>false,'error'=>'invalid_total_chunks'], 400);
  }

  $token = random_id(18);
  $tmpDir = tmp_root() . DIRECTORY_SEPARATOR . $token;
  ensure_dir($tmpDir);

  $stmt = $pdo->prepare("
    INSERT INTO upload_sessions
      (token,user_id,created_at,uploader_ip,original_name,safe_name,total_bytes,chunk_size,total_chunks,tmp_dir)
    VALUES
      (:t,:uid,:c,:ip,:on,:sn,:tb,:cs,:tc,:td)
  ");
  $stmt->execute([
    ':t'=>$token,
    ':uid'=>(int)$u['id'],
    ':c'=>date('c'),
    ':ip'=>$ip,
    ':on'=>$original,
    ':sn'=>$safeName,
    ':tb'=>$totalBytes,
    ':cs'=>$chunkSize,
    ':tc'=>$totalChunks,
    ':td'=>safe_rel(str_replace(UPLOADS_DIR, '', $tmpDir)), // store relative-ish
  ]);
  log_event('upload_init', (int)$u['id'], $ip, "token=$token name=$original bytes=$totalBytes chunks=$totalChunks");
  json_out(['ok'=>true,'token'=>$token,'total_chunks'=>$totalChunks]);
}

/**
 * STATUS: returns which chunks exist
 * GET: action=status&token=...
 */
if ($action === 'status') {
  $token = trim((string)($_GET['token'] ?? ''));
  if ($token === '') json_out(['ok'=>false,'error'=>'missing_token'], 400);

  $stmt = $pdo->prepare("SELECT * FROM upload_sessions WHERE token=:t AND user_id=:uid LIMIT 1");
  $stmt->execute([':t'=>$token, ':uid'=>(int)$u['id']]);
  $s = $stmt->fetch();
  if (!$s || $s['aborted_at'] || $s['finalized_at']) {
    json_out(['ok'=>false,'error'=>'session_not_found'], 404);
  }

  $tmpDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . ltrim((string)$s['tmp_dir'], DIRECTORY_SEPARATOR);
  $totalChunks = (int)$s['total_chunks'];

  $present = [];
  for ($i=0; $i<$totalChunks; $i++) {
    $path = $tmpDir . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i);
    if (is_file($path)) $present[] = $i;
  }
  json_out(['ok'=>true,'present'=>$present,'total_chunks'=>$totalChunks]);
}

/**
 * CHUNK: receive one chunk
 * POST: action=chunk, token, index, chunk(file)
 */
if ($action === 'chunk') {
  $token = trim((string)($_POST['token'] ?? ''));
  $index = (int)($_POST['index'] ?? -1);

  if ($token === '' || $index < 0) json_out(['ok'=>false,'error'=>'bad_params'], 400);

  $stmt = $pdo->prepare("SELECT * FROM upload_sessions WHERE token=:t AND user_id=:uid LIMIT 1");
  $stmt->execute([':t'=>$token, ':uid'=>(int)$u['id']]);
  $s = $stmt->fetch();
  if (!$s || $s['aborted_at'] || $s['finalized_at']) json_out(['ok'=>false,'error'=>'session_not_found'], 404);

  $totalChunks = (int)$s['total_chunks'];
  if ($index >= $totalChunks) json_out(['ok'=>false,'error'=>'index_out_of_range'], 400);

  if (!isset($_FILES['chunk']) || (int)$_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    json_out(['ok'=>false,'error'=>'missing_chunk_file'], 400);
  }

  $tmpDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . ltrim((string)$s['tmp_dir'], DIRECTORY_SEPARATOR);
  ensure_dir($tmpDir);

  $dst = $tmpDir . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $index);

  // If already exists, treat as OK (resume)
  if (!is_file($dst)) {
    if (!move_uploaded_file((string)$_FILES['chunk']['tmp_name'], $dst)) {
      json_out(['ok'=>false,'error'=>'move_failed'], 500);
    }
    // update received_chunks approx
    $pdo->prepare("UPDATE upload_sessions SET received_chunks = received_chunks + 1 WHERE token=:t")
        ->execute([':t'=>$token]);
  }

  json_out(['ok'=>true,'index'=>$index]);
}

/**
 * FINALIZE: assemble chunks -> final file, create download link (uploads row)
 * POST: action=finalize, token
 */
if ($action === 'finalize') {
  $token = trim((string)($_POST['token'] ?? ''));
  if ($token === '') json_out(['ok'=>false,'error'=>'missing_token'], 400);

  $stmt = $pdo->prepare("SELECT * FROM upload_sessions WHERE token=:t AND user_id=:uid LIMIT 1");
  $stmt->execute([':t'=>$token, ':uid'=>(int)$u['id']]);
  $s = $stmt->fetch();
  if (!$s || $s['aborted_at'] || $s['finalized_at']) json_out(['ok'=>false,'error'=>'session_not_found'], 404);

  $tmpDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . ltrim((string)$s['tmp_dir'], DIRECTORY_SEPARATOR);
  $totalChunks = (int)$s['total_chunks'];
  $chunkSize   = (int)$s['chunk_size'];
  $totalBytes  = (int)$s['total_bytes'];
  $original    = (string)$s['original_name'];
  $safeName    = (string)$s['safe_name'];

  // verify all chunks exist
  for ($i=0; $i<$totalChunks; $i++) {
    $p = $tmpDir . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i);
    if (!is_file($p)) {
      json_out(['ok'=>false,'error'=>'missing_chunk','missing_index'=>$i], 400);
    }
  }

  // Create final random folder
  $folder = date('Ymd_His') . '_' . random_id(10);
  $targetDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . $folder;
  ensure_dir($targetDir);

  // Resolve collisions
  $finalName = $safeName;
  $dst = $targetDir . DIRECTORY_SEPARATOR . $finalName;
  if (file_exists($dst)) {
    $ext = strtolower(pathinfo($finalName, PATHINFO_EXTENSION));
    $base = pathinfo($finalName, PATHINFO_FILENAME);
    $finalName = $base . '_' . random_id(6) . ($ext ? ".{$ext}" : '');
    $dst = $targetDir . DIRECTORY_SEPARATOR . $finalName;
  }

  // Assemble
  $out = fopen($dst, 'wb');
  if (!$out) json_out(['ok'=>false,'error'=>'cannot_open_output'], 500);

  $written = 0;
  for ($i=0; $i<$totalChunks; $i++) {
    $p = $tmpDir . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i);
    $in = fopen($p, 'rb');
    if (!$in) { fclose($out); json_out(['ok'=>false,'error'=>'cannot_open_chunk','index'=>$i], 500); }
    while (!feof($in)) {
      $buf = fread($in, 1024 * 1024); // 1MB buffer
      if ($buf === false) { fclose($in); fclose($out); json_out(['ok'=>false,'error'=>'read_failed','index'=>$i], 500); }
      $len = strlen($buf);
      if ($len > 0) {
        $w = fwrite($out, $buf);
        if ($w === false) { fclose($in); fclose($out); json_out(['ok'=>false,'error'=>'write_failed'], 500); }
        $written += $w;
      }
    }
    fclose($in);
  }
  fclose($out);

  // Validate size
  if ($written !== $totalBytes) {
    // allow slight mismatch? here we enforce exact
    json_out(['ok'=>false,'error'=>'size_mismatch','expected'=>$totalBytes,'got'=>$written], 500);
  }

  // Create download record
  $id = random_id(18);
  $rel = $folder . '/' . $finalName;  $pwd = trim((string)($_POST['password'] ?? ''));
  $pwdHash = ($pwd !== '') ? password_hash($pwd, PASSWORD_DEFAULT) : null;
$pdo->prepare("
    INSERT INTO uploads(id,user_id,created_at,uploader_ip,original_name,stored_relpath,bytes,password_hash)
    VALUES(:id,:uid,:t,:ip,:on,:rp,:b,:ph)
  ")->execute([
    ':id'=>$id,
    ':uid'=>(int)$u['id'],
    ':t'=>date('c'),
    ':ip'=>$ip,
    ':on'=>$original,
    ':rp'=>$rel,
    ':b'=>$totalBytes,
    ':ph'=>$pwdHash
  ]);

    // Mark session finalized
  $pdo->prepare("UPDATE upload_sessions SET finalized_at=:t WHERE token=:tkn")
      ->execute([':t'=>date('c'), ':tkn'=>$token]);



  // Cleanup tmp
  for ($i=0; $i<$totalChunks; $i++) {
    @unlink($tmpDir . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i));
  }
  @rmdir($tmpDir);

  $url = BASE_URL . '/?p=download&id=' . rawurlencode($id);
  log_event('upload_ok', (int)$u['id'], $ip, "id=$id token=$token name=$original bytes=$totalBytes");
  json_out(['ok'=>true,'id'=>$id,'url'=>$url,'bytes'=>$totalBytes,'name'=>$original]);
}

/**
 * ABORT
 * POST: action=abort, token
 */
if ($action === 'abort') {
  $token = trim((string)($_POST['token'] ?? ''));
  if ($token === '') json_out(['ok'=>false,'error'=>'missing_token'], 400);

  $stmt = $pdo->prepare("SELECT * FROM upload_sessions WHERE token=:t AND user_id=:uid LIMIT 1");
  $stmt->execute([':t'=>$token, ':uid'=>(int)$u['id']]);
  $s = $stmt->fetch();
  if (!$s) json_out(['ok'=>false,'error'=>'session_not_found'], 404);

  $tmpDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . ltrim((string)$s['tmp_dir'], DIRECTORY_SEPARATOR);
  // best-effort cleanup (supports bundle sessions too)
  if (is_dir($tmpDir)) {
    rrmdir($tmpDir);
  }

  $pdo->prepare("UPDATE upload_sessions SET aborted_at=:at WHERE token=:tk")
      ->execute([':at'=>date('c'), ':tk'=>$token]);

  log_event('upload_abort', (int)$u['id'], $ip, "token=$token");
  json_out(['ok'=>true]);
}
if ($action === 'init_bundle') {
  $manifestJson = (string)($_POST['manifest'] ?? '');
  if ($manifestJson === '') json_out(['ok'=>false,'error'=>'missing_manifest'], 400);

  $manifest = json_decode($manifestJson, true);
  if (!is_array($manifest) || empty($manifest['files'])) json_out(['ok'=>false,'error'=>'bad_manifest'], 400);

  $chunkSize = (int)($_POST['chunk_size'] ?? 0);
  if ($chunkSize <= 0 || $chunkSize > 128*1024*1024) json_out(['ok'=>false,'error'=>'bad_chunk_size'], 400);

  $token = random_id(18);
  $tmpDir = tmp_root() . DIRECTORY_SEPARATOR . $token;
  ensure_dir($tmpDir);

  // total bundle bytes
  $totalBytes = 0;
  foreach ($manifest['files'] as $f) {
    $tb = (int)($f['size'] ?? 0);
    if ($tb < 0) $tb = 0;
    $totalBytes += $tb;
  }

  $pdo->prepare("
    INSERT INTO upload_sessions(token,user_id,created_at,uploader_ip,original_name,safe_name,total_bytes,chunk_size,total_chunks,tmp_dir)
    VALUES(:t,:uid,:c,:ip,:on,:sn,:tb,:cs,0,:td)
  ")->execute([
    ':t'=>$token, ':uid'=>(int)$u['id'], ':c'=>date('c'), ':ip'=>$ip,
    ':on'=>'BUNDLE', ':sn'=>'bundle', ':tb'=>$totalBytes, ':cs'=>$chunkSize,
    ':td'=>safe_rel(str_replace(UPLOADS_DIR, '', $tmpDir)),
  ]);

  // store file list
  $i = 0;
  foreach ($manifest['files'] as $f) {
    $rel = (string)($f['path'] ?? ("file_$i"));
    $rel = str_replace(['..','\\'], ['','/'], $rel);
    $rel = ltrim($rel, '/');

    $tb = (int)($f['size'] ?? 0);
    $tc = (int)ceil(max(1,$tb) / $chunkSize);

    $pdo->prepare("
      INSERT INTO bundle_files(token,file_index,rel_path,total_bytes,chunk_size,total_chunks)
      VALUES(:t,:i,:p,:b,:cs,:tc)
    ")->execute([
      ':t'=>$token, ':i'=>$i, ':p'=>$rel, ':b'=>$tb, ':cs'=>$chunkSize, ':tc'=>$tc
    ]);
    $i++;
  }

  log_event('upload_init_bundle', (int)$u['id'], $ip, "token=$token files=".$i." bytes=$totalBytes");
  json_out(['ok'=>true,'token'=>$token,'files_count'=>$i]);
}
if ($action === 'chunk_bundle') {
  $token = trim((string)($_POST['token'] ?? ''));
  $fileIndex = (int)($_POST['file_index'] ?? -1);
  $index = (int)($_POST['index'] ?? -1);

  if ($token==='' || $fileIndex<0 || $index<0) json_out(['ok'=>false,'error'=>'bad_params'], 400);

  $s = $pdo->prepare("SELECT * FROM upload_sessions WHERE token=:t AND user_id=:uid LIMIT 1");
  $s->execute([':t'=>$token, ':uid'=>(int)$u['id']]);
  $sess = $s->fetch();
  if (!$sess || $sess['aborted_at'] || $sess['finalized_at']) json_out(['ok'=>false,'error'=>'session_not_found'], 404);

  $bf = $pdo->prepare("SELECT * FROM bundle_files WHERE token=:t AND file_index=:i LIMIT 1");
  $bf->execute([':t'=>$token, ':i'=>$fileIndex]);
  $meta = $bf->fetch();
  if (!$meta) json_out(['ok'=>false,'error'=>'file_not_found'], 404);

  if ($index >= (int)$meta['total_chunks']) json_out(['ok'=>false,'error'=>'index_out_of_range'], 400);

  if (!isset($_FILES['chunk']) || (int)$_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
    json_out(['ok'=>false,'error'=>'missing_chunk_file'], 400);
  }

  $tmpDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . ltrim((string)$sess['tmp_dir'], DIRECTORY_SEPARATOR);
  $fileDir = $tmpDir . DIRECTORY_SEPARATOR . sprintf('file_%06d', $fileIndex);
  ensure_dir($fileDir);

  $dst = $fileDir . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $index);
  if (!is_file($dst)) {
    if (!move_uploaded_file((string)$_FILES['chunk']['tmp_name'], $dst)) {
      json_out(['ok'=>false,'error'=>'move_failed'], 500);
    }
  }

  json_out(['ok'=>true,'file_index'=>$fileIndex,'index'=>$index]);
}
if ($action === 'finalize_bundle') {
  $token = trim((string)($_POST['token'] ?? ''));
  if ($token==='') json_out(['ok'=>false,'error'=>'missing_token'], 400);

  $s = $pdo->prepare("SELECT * FROM upload_sessions WHERE token=:t AND user_id=:uid LIMIT 1");
  $s->execute([':t'=>$token, ':uid'=>(int)$u['id']]);
  $sess = $s->fetch();
  if (!$sess || $sess['aborted_at'] || $sess['finalized_at']) json_out(['ok'=>false,'error'=>'session_not_found'], 404);

  $tmpDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . ltrim((string)$sess['tmp_dir'], DIRECTORY_SEPARATOR);

  $files = $pdo->prepare("SELECT * FROM bundle_files WHERE token=:t ORDER BY file_index ASC");
  $files->execute([':t'=>$token]);
  $list = $files->fetchAll();
  if (!$list) json_out(['ok'=>false,'error'=>'empty_bundle'], 400);

  // Create final folder + zip path
  $folder = date('Ymd_His') . '_' . random_id(10);
  $targetDir = UPLOADS_DIR . DIRECTORY_SEPARATOR . $folder;
  ensure_dir($targetDir);

  $bundleName = trim((string)($_POST['bundle_name'] ?? ''));
  $zipBase = ($bundleName !== '') ? safe_filename($bundleName) : ('flashcopy_' . date('Ymd_His'));
  if ($zipBase === '' || $zipBase === '.' || $zipBase === '..') $zipBase = ('flashcopy_' . date('Ymd_His'));
  if (!preg_match('/\.zip$/i', $zipBase)) $zipBase .= '.zip';
  $zipName = $zipBase;
  $zipPath = $targetDir . DIRECTORY_SEPARATOR . $zipName;

  $zip = new ZipArchive();
  if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    json_out(['ok'=>false,'error'=>'zip_open_failed'], 500);
  }

  // For speed on huge bundles: store (no compression)
  // $zip->setCompressionName($name, ZipArchive::CM_STORE);

  foreach ($list as $f) {
    $fi = (int)$f['file_index'];
    $relPath = str_replace(['..','\\'], ['','/'], (string)$f['rel_path']);
    $relPath = ltrim($relPath, '/');

    $totalChunks = (int)$f['total_chunks'];
    $fileDir = $tmpDir . DIRECTORY_SEPARATOR . sprintf('file_%06d', $fi);

    // Assemble to a temp file
    $assembled = $tmpDir . DIRECTORY_SEPARATOR . sprintf('assembled_%06d.bin', $fi);
    $out = fopen($assembled, 'wb');
    if (!$out) { $zip->close(); json_out(['ok'=>false,'error'=>'assemble_open_failed'], 500); }

    for ($i=0; $i<$totalChunks; $i++) {
      $p = $fileDir . DIRECTORY_SEPARATOR . sprintf('chunk_%06d.part', $i);
      if (!is_file($p)) { fclose($out); $zip->close(); json_out(['ok'=>false,'error'=>'missing_chunk','file_index'=>$fi,'missing'=>$i], 400); }
      $in = fopen($p, 'rb');
      if (!$in) { fclose($out); $zip->close(); json_out(['ok'=>false,'error'=>'chunk_open_failed'], 500); }
      while (!feof($in)) {
        $buf = fread($in, 1024*1024);
        if ($buf === false) { fclose($in); fclose($out); $zip->close(); json_out(['ok'=>false,'error'=>'read_failed'], 500); }
        if ($buf !== '') fwrite($out, $buf);
      }
      fclose($in);
    }
    fclose($out);

    // Add to zip with path
    $zip->addFile($assembled, $relPath);
    // Optional: no compression for big files
    // $zip->setCompressionName($relPath, ZipArchive::CM_STORE);
  }

  $zip->close();

  // Mark finalized, cleanup (best-effort)
  $pdo->prepare("UPDATE upload_sessions SET finalized_at=:t WHERE token=:tkn")
      ->execute([':t'=>date('c'), ':tkn'=>$token]);

  // Cleanup tmp (chunks + assembled) now that the ZIP is built
  rrmdir($tmpDir);

  $zipBytes = filesize($zipPath) ?: 0;

  $pwd = trim((string)($_POST['password'] ?? ''));
  $pwdHash = ($pwd !== '') ? password_hash($pwd, PASSWORD_DEFAULT) : null;

  $id = random_id(18);
  $rel = $folder . '/' . $zipName;

  $pdo->prepare("
    INSERT INTO uploads(id,user_id,created_at,uploader_ip,original_name,stored_relpath,bytes,password_hash)
    VALUES(:id,:uid,:t,:ip,:on,:rp,:b,:ph)
  ")->execute([
    ':id'=>$id, ':uid'=>(int)$u['id'], ':t'=>date('c'), ':ip'=>$ip,
    ':on'=>$zipName, ':rp'=>$rel, ':b'=>$zipBytes, ':ph'=>$pwdHash
  ]);

  $url = BASE_URL . '/?p=download&id=' . rawurlencode($id);
  log_event('upload_ok_bundle', (int)$u['id'], $ip, "id=$id token=$token zip_bytes=$zipBytes");
  json_out(['ok'=>true,'id'=>$id,'url'=>$url,'bytes'=>$zipBytes,'name'=>$zipName]);
}




json_out(['ok'=>false,'error'=>'unknown_action'], 400);
