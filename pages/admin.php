<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
$admin = require_admin();
require __DIR__ . '/../templates/header.php';

$pdo = db();
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check((string)($_POST['csrf'] ?? ''));

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'toggle_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        $enabled = (int)($_POST['enabled'] ?? 0);

        // Empêcher de se désactiver soi-même par erreur
        if ($uid === (int)$admin['id']) {
            $err = "Tu ne peux pas désactiver le compte admin courant.";
        } else {
            $pdo->prepare("UPDATE users SET enabled=:e WHERE id=:id")->execute([':e'=>$enabled, ':id'=>$uid]);
            log_event('admin_toggle_user', (int)$admin['id'], $ip, "uid=$uid enabled=$enabled");
            $msg = "Compte mis à jour.";
        }
    }

    if ($action === 'delete_upload') {
        $uploadId = (string)($_POST['upload_id'] ?? '');
        $uploadId = trim($uploadId);

        $stmt = $pdo->prepare("SELECT id, stored_relpath, deleted_at FROM uploads WHERE id=:id LIMIT 1");
        $stmt->execute([':id'=>$uploadId]);
        $up = $stmt->fetch();

        if (!$up || $up['deleted_at']) {
            $err = "Lien introuvable ou déjà supprimé.";
        } else {
            $rel = str_replace(['..','\\'], ['','/'], (string)$up['stored_relpath']);
            $full = UPLOADS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

            // supprime fichier
            if (is_file($full)) @unlink($full);

            // supprime dossier parent si vide
            $parent = dirname($full);
            if (is_dir($parent)) {
                $files = array_values(array_diff(scandir($parent) ?: [], ['.','..']));
                if (count($files) === 0) @rmdir($parent);
            }

            $pdo->prepare("UPDATE uploads SET deleted_at=:t WHERE id=:id")->execute([':t'=>date('c'), ':id'=>$uploadId]);
            log_event('delete_upload', (int)$admin['id'], $ip, "upload_id=$uploadId rel=$rel");
            $msg = "Lien + fichier supprimés.";
        }
    }
    if ($action === 'set_password') {
        $uploadId = trim((string)($_POST['upload_id'] ?? ''));
        $pwd = trim((string)($_POST['new_password'] ?? ''));

        $stmt = $pdo->prepare("SELECT id, deleted_at FROM uploads WHERE id=:id LIMIT 1");
        $stmt->execute([':id'=>$uploadId]);
        $up = $stmt->fetch();

        if (!$up || $up['deleted_at']) {
            $err = "Lien introuvable ou déjà supprimé.";
        } else {
            $hash = null;
            if ($pwd !== '') {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
            }
            $pdo->prepare("UPDATE uploads SET password_hash=:h WHERE id=:id")->execute([':h'=>$hash, ':id'=>$uploadId]);
            log_event('admin_set_password', (int)$admin['id'], $ip, "upload_id=$uploadId set=" . ($pwd!=='' ? '1':'0'));
            $msg = $pwd!=='' ? "Mot de passe mis à jour." : "Mot de passe retiré.";
        }
    }

    if ($action === 'purge_older') {
        $days = (int)($_POST['days'] ?? 0);
        if ($days <= 0 || $days > 3650) {
            $err = "Nombre de jours invalide.";
        } else {
            $cutoff = (new DateTimeImmutable('now'))->modify("-{$days} days")->format('c');

            $stmt = $pdo->prepare("
              SELECT id, stored_relpath, bytes
              FROM uploads
              WHERE deleted_at IS NULL AND created_at < :cutoff
              ORDER BY created_at ASC
              LIMIT 5000
            ");
            $stmt->execute([':cutoff'=>$cutoff]);
            $toDel = $stmt->fetchAll();

            $count = 0;
            $bytes = 0;

            foreach ($toDel as $up) {
                $uploadId = (string)$up['id'];
                $bytes += (int)($up['bytes'] ?? 0);

                $rel = str_replace(['..','\\'], ['','/'], (string)$up['stored_relpath']);
                $full = UPLOADS_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

                if (is_file($full)) @unlink($full);

                $parent = dirname($full);
                if (is_dir($parent)) {
                    $files = array_values(array_diff(scandir($parent) ?: [], ['.','..']));
                    if (count($files) === 0) @rmdir($parent);
                }

                $pdo->prepare("UPDATE uploads SET deleted_at=:t WHERE id=:id")->execute([':t'=>date('c'), ':id'=>$uploadId]);
                $count++;
            }

            log_event('admin_purge_older', (int)$admin['id'], $ip, "days=$days count=$count bytes=$bytes cutoff=$cutoff");
            $mb = round($bytes/1024/1024, 1);
            $msg = "Purge terminée : $count lien(s) supprimé(s), ~{$mb} Mo libérés.";
        }
    }


}


// Global stats (active uploads)
$global = $pdo->query("
  SELECT
    COALESCE(SUM(bytes),0) AS total_bytes,
    COUNT(*) AS total_uploads,
    COALESCE(SUM(downloads_count),0) AS total_downloads
  FROM uploads
  WHERE deleted_at IS NULL
")->fetch() ?: ['total_bytes'=>0,'total_uploads'=>0,'total_downloads'=>0];

// Users list + stats
$users = $pdo->query("
  SELECT
    u.id, u.username, u.role, u.enabled, u.created_at, u.last_login_at, u.last_login_ip,
    COALESCE(SUM(CASE WHEN up.deleted_at IS NULL THEN up.bytes ELSE 0 END),0) AS total_bytes,
    COALESCE(SUM(CASE WHEN up.deleted_at IS NULL THEN 1 ELSE 0 END),0) AS total_uploads,
    COALESCE(SUM(CASE WHEN up.deleted_at IS NULL THEN up.downloads_count ELSE 0 END),0) AS total_downloads
  FROM users u
  LEFT JOIN uploads up ON up.user_id = u.id
  GROUP BY u.id
  ORDER BY u.role DESC, u.username ASC
")->fetchAll();

// Links list (non deleted)
$links = $pdo->query("
  SELECT
    up.id, up.created_at, up.original_name, up.bytes, up.downloads_count, up.last_download_at, up.password_hash,
    u.username AS owner
  FROM uploads up
  JOIN users u ON u.id = up.user_id
  WHERE up.deleted_at IS NULL
  ORDER BY up.created_at DESC
  LIMIT 500
")->fetchAll();

?>
<div class="card">
  <h2 style="margin:0 0 10px">Admin</h2>
  <?php
    $gMb = round(((int)$global['total_bytes'])/1024/1024, 1);
    $gUp = (int)$global['total_uploads'];
    $gDl = (int)$global['total_downloads'];
  ?>
  <div class="muted" style="margin:8px 0 14px">
    <strong><?=h((string)$gUp)?></strong> lien(s) actifs •
    <strong><?=h((string)$gMb)?></strong> Mo utilisés •
    <strong><?=h((string)$gDl)?></strong> téléchargement(s)
  </div>

  <div class="card" style="margin:12px 0">
    <h3 style="margin:0 0 8px">Purge automatique</h3>
    <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap" onsubmit="return confirm('Supprimer tous les liens (et fichiers) plus vieux que ce délai ?');">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="action" value="purge_older">
      <label class="muted">Supprimer tout ce qui a plus de</label>
      <input type="number" name="days" min="1" max="3650" value="30" style="width:90px">
      <span class="muted">jours</span>
      <button class="danger" type="submit">Purger</button>
    </form>
  </div>

  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>
  <?php if ($msg): ?><div class="ok"><?=h($msg)?></div><?php endif; ?>

  <h3 style="margin:16px 0 6px">Comptes & activation</h3>
  <p class="muted">Les nouveaux comptes sont créés en “désactivé”. Active/désactive ici.</p>

  <table>
    <thead>
      <tr>
        <th>Compte</th><th>Rôle</th><th>État</th><th>Créé</th><th>Dernière connexion</th><th>Stats</th><th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?=h($u['username'])?></td>
        <td><?=h($u['role'])?></td>
        <td><?=((int)$u['enabled']===1)?'Actif':'Inactif'?></td>
        <td class="muted"><?=h((string)$u['created_at'])?></td>
        <td class="muted"><?=h((string)($u['last_login_at'] ?? '—'))?><br><?=h((string)($u['last_login_ip'] ?? ''))?></td>
        <td>
          Uploads: <strong><?=h((string)$u['total_uploads'])?></strong><br>
          Downloads: <strong><?=h((string)$u['total_downloads'])?></strong><br>
          Volume: <strong><?=h((string)round(((int)$u['total_bytes'])/1024/1024, 1))?> Mo</strong>
        </td>
        <td>
          <?php if ($u['role'] !== 'admin'): ?>
            <form method="post" style="display:flex;gap:8px;align-items:center">
              <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
              <input type="hidden" name="action" value="toggle_user">
              <input type="hidden" name="uid" value="<?=h((string)$u['id'])?>">
              <?php if ((int)$u['enabled']===1): ?>
                <input type="hidden" name="enabled" value="0">
                <button class="secondary" type="submit">Désactiver</button>
              <?php else: ?>
                <input type="hidden" name="enabled" value="1">
                <button type="submit">Activer</button>
              <?php endif; ?>
            </form>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h3 style="margin:0 0 6px">Liens existants</h3>
  <p class="muted">Liste (max 500). Le bouton supprime le lien + le fichier local (et le dossier s’il devient vide).</p>

  <table>
    <thead>
      <tr>
        <th>Lien</th><th>Propriétaire</th><th>Créé</th><th>Âge</th><th>Taille</th><th>Downloads</th><th>Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($links as $l): 
      $created = strtotime((string)$l['created_at']) ?: time();
      $ageSec = max(0, time() - $created);
      $ageH = floor($ageSec/3600);
      $ageD = floor($ageSec/86400);
      $ageTxt = $ageD > 0 ? ($ageD.' j') : ($ageH.' h');
      $url = BASE_URL . '/?p=download&id=' . rawurlencode((string)$l['id']);
    ?>
      <tr>
        <td>
          <a href="<?=h($url)?>" target="_blank"><?=h((string)$l['original_name'])?></a><br>
          <span class="muted"><code><?=h($url)?></code></span>
        </td>
        <td><?=h((string)$l['owner'])?></td>
        <td class="muted"><?=h((string)$l['created_at'])?></td>
        <td><?=h($ageTxt)?></td>
        <td><?=h((string)round(((int)$l['bytes'])/1024/1024, 1))?> Mo</td>
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
