<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
require __DIR__ . '/../templates/header.php';

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check((string)($_POST['csrf'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!username_valid($username)) {
        $err = "Username invalide (3-32, lettres/chiffres/._-).";
    } elseif (strlen($password) < 8) {
        $err = "Mot de passe trop court (min 8).";
    } else {
        $pdo = db();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $ins = $pdo->prepare("INSERT INTO users(username, pass_hash, role, enabled, created_at) VALUES(:u,:h,'user',0,:t)");
            $ins->execute([':u'=>$username, ':h'=>$hash, ':t'=>date('c')]);

            $uid = (int)$pdo->lastInsertId();
            log_event('register', $uid, $ip, 'pending_activation');
            $ok = "Compte créé. Il doit être activé par un admin avant connexion.";
        } catch (Throwable $e) {
            $err = "Impossible de créer le compte (username déjà pris ?).";
        }
    }
}
?>
<div class="card">
  <h2 style="margin:0 0 10px">Créer un compte</h2>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>
  <?php if ($ok): ?><div class="ok"><?=h($ok)?></div><?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="row">
      <div>
        <label>Nom d’utilisateur</label>
        <input type="text" name="username" required placeholder="ex: maxime">
        <div class="muted">3–32 caractères : lettres, chiffres, point, underscore, tiret</div>
      </div>
      <div>
        <label>Mot de passe</label>
        <input type="password" name="password" required>
        <div class="muted">Min 8 caractères (tu peux renforcer)</div>
      </div>
    </div>
    <div style="margin-top:12px">
      <button type="submit">Créer</button>
    </div>
  </form>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
