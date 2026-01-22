<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
$u = require_login();
require __DIR__ . '/../templates/header.php';

$pdo = db();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check((string)($_POST['csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete_upload') {
        $uploadId = trim((string)($_POST['upload_id'] ?? ''));

        $stmt = $pdo->prepare("SELECT id, stored_relpath, deleted_at FROM uploads WHERE id=:id AND user_id=:uid LIMIT 1");
        $stmt->execute([':id'=>$uploadId, ':uid'=>(int)$u['id']]);
        $up = $stmt->fetch();

        if (!$up || $up['deleted_at']) {
            $err = "Lien introuvable ou déjà supprimé.";
        } else {
            $rel = str_replace(['..','\\'], ['','/'], (string)$up['stored_relpath']);
            $full = UPLOADS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

            if (is_file($full)) @unlink($full);

            // supprimer dossier parent si vide
            $parent = dirname($full);
            if (is_dir($parent)) {
                $files = array_values(array_diff(scandir($parent) ?: [], ['.','..']));
                if (count($files) === 0) @rmdir($parent);
            }

            $pdo->prepare("UPDATE uploads SET deleted_at=:t WHERE id=:id")->execute([':t'=>date('c'), ':id'=>$uploadId]);
            log_event('user_delete_upload', (int)$u['id'], $ip, "upload_id=$uploadId rel=$rel");
            $msg = "Upload supprimé (lien + fichier).";
        }
    }
    if ($action === 'set_password') {
        $uploadId = trim((string)($_POST['upload_id'] ?? ''));
        $pwd = trim((string)($_POST['new_password'] ?? ''));

        $stmt = $pdo->prepare("SELECT id, deleted_at FROM uploads WHERE id=:id AND user_id=:uid LIMIT 1");
        $stmt->execute([':id'=>$uploadId, ':uid'=>(int)$u['id']]);
        $up = $stmt->fetch();

        if (!$up || $up['deleted_at']) {
            $err = "Lien introuvable ou déjà supprimé.";
        } else {
            $hash = null;
            if ($pwd !== '') {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
            }
            $pdo->prepare("UPDATE uploads SET password_hash=:h WHERE id=:id")->execute([':h'=>$hash, ':id'=>$uploadId]);
            log_event('user_set_password', (int)$u['id'], $ip, "upload_id=$uploadId set=" . ($pwd!=='' ? '1':'0'));
            $msg = $pwd!=='' ? "Mot de passe mis à jour." : "Mot de passe retiré.";
        }
    }



    if ($action === 'purge_older') {
        $days = (int)($_POST['days'] ?? 30);
        if ($days < 1) $days = 1;
        $cutoff = date('c', time() - ($days * 86400));

        $sel = $pdo->prepare("SELECT id, stored_relpath, bytes FROM uploads WHERE user_id=:uid AND deleted_at IS NULL AND created_at < :cutoff");
        $sel->execute([':uid'=>(int)$u['id'], ':cutoff'=>$cutoff]);
        $toDel = $sel->fetchAll() ?: [];

        $upd = $pdo->prepare("UPDATE uploads SET deleted_at=:t WHERE id=:id AND user_id=:uid");
        $count = 0;
        $bytes = 0;
        foreach ($toDel as $up) {
            $bytes += (int)($up['bytes'] ?? 0);
            $rel = str_replace(['..','\\'], ['','/'], (string)$up['stored_relpath']);
            $full = UPLOADS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($full)) @unlink($full);

            $parent = dirname($full);
            if (is_dir($parent)) {
                $files = array_values(array_diff(scandir($parent) ?: [], ['.','..']));
                if (count($files) === 0) @rmdir($parent);
            }
            $upd->execute([':t'=>date('c'), ':id'=>(string)$up['id'], ':uid'=>(int)$u['id']]);
            $count++;
        }

        if ($count > 0) {
            $mb = round($bytes/1024/1024, 1);
            $msg = "Purge effectuée : $count lien(s) supprimé(s) (≈ {$mb} Mo).";
        } else {
            $msg = "Aucun lien à purger (plus de $days jours).";
        }
    }

}


$statsStmt = $pdo->prepare("
  SELECT
    COALESCE(SUM(bytes),0) AS total_bytes,
    COUNT(*) AS total_uploads,
    COALESCE(SUM(downloads_count),0) AS total_downloads
  FROM uploads
  WHERE user_id=:uid AND deleted_at IS NULL
");
$statsStmt->execute([':uid'=>(int)$u['id']]);
$stats = $statsStmt->fetch() ?: ['total_bytes'=>0,'total_uploads'=>0,'total_downloads'=>0];

$rows = $pdo->prepare("
  SELECT id, created_at, original_name, bytes, downloads_count, last_download_at, password_hash
  FROM uploads
  WHERE user_id=:uid AND deleted_at IS NULL
  ORDER BY created_at DESC
  LIMIT 500
");
$rows->execute([':uid'=>(int)$u['id']]);
$uploads = $rows->fetchAll();
?>

<div class="card">
  <h2 style="margin:0 0 10px">Mes uploads</h2>
  <p class="muted">Tu peux retrouver tes liens, voir leur âge, et supprimer ceux qui ne servent plus.</p>
  <?php
    $totMb = round(((int)$stats['total_bytes'])/1024/1024, 1);
    $totUp = (int)$stats['total_uploads'];
    $totDl = (int)$stats['total_downloads'];
  ?>
  <div class="muted" style="margin:8px 0 14px">
    <strong><?=h((string)$totUp)?></strong> lien(s) actifs •
    <strong><?=h((string)$totMb)?></strong> Mo utilisés •
    <strong><?=h((string)$totDl)?></strong> téléchargement(s)
  </div>

  <form method="post" style="margin:10px 0 18px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="action" value="purge_older">
    <div>
      <label class="muted">Purger les liens de plus de (jours)</label><br>
      <input type="number" name="days" value="30" min="1" style="width:90px">
    </div>
    <button class="danger" type="submit" onclick="return confirm('Supprimer tous vos liens de plus de X jours ?');">Purger</button>
  </form>



  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>
  <?php if ($msg): ?><div class="ok"><?=h($msg)?></div><?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>Fichier</th>
        <th>Créé</th>
        <th>Âge</th>
        <th>Taille</th>
        <th>Downloads</th>
        <th>Mot de passe</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($uploads as $l):
      $created = strtotime((string)$l['created_at']) ?: time();
      $ageSec = max(0, time() - $created);
      $ageD = (int)floor($ageSec / 86400);
      $ageH = (int)floor($ageSec / 3600);
      $ageTxt = $ageD > 0 ? ($ageD.' j') : ($ageH.' h');
      $url = BASE_URL . '/?p=download&id=' . rawurlencode((string)$l['id']);
      $mb = round(((int)$l['bytes'])/1024/1024, 1);
    ?>
      <tr>
        <td>
          <a href="<?=h($url)?>" target="_blank"><?=h((string)$l['original_name'])?></a><br>
          <span class="muted"><code><?=h($url)?></code></span>
        </td>
        <td class="muted"><?=h((string)$l['created_at'])?></td>
        <td><?=h($ageTxt)?></td>
        <td><?=h((string)$mb)?> Mo</td>
        <td>
          <strong><?=h((string)$l['downloads_count'])?></strong><br>
          <span class="muted">Dernier: <?=h((string)($l['last_download_at'] ?? '—'))?></span>
        </td>
        <td>
          <?php $hasPwd = !empty($l['password_hash']); ?>
          <div class="muted" style="margin-bottom:6px"><?= $hasPwd ? 'Protégé' : '—' ?></div>
          <form method="post" style="display:flex; gap:6px; align-items:center; flex-wrap:wrap">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="set_password">
            <input type="hidden" name="upload_id" value="<?=h((string)$l['id'])?>">
            <input type="password" name="new_password" placeholder="<?= $hasPwd ? 'Nouveau mot de passe' : 'Définir un mot de passe' ?>" autocomplete="new-password" style="max-width:180px">
            <button type="submit"><?= $hasPwd ? 'Modifier' : 'Définir' ?></button>
            <?php if ($hasPwd): ?>
              <button type="submit" name="new_password" value="" class="danger" onclick="return confirm('Retirer le mot de passe ?');">Retirer</button>
            <?php endif; ?>
          </form>
        </td>
        <td>
          <form method="post" onsubmit="return confirm('Supprimer ce lien ET le fichier local ?');">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="delete_upload">
            <input type="hidden" name="upload_id" value="<?=h((string)$l['id'])?>">
            <button class="danger" type="submit">Supprimer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
