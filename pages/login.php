<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

$err = '';
$info = '';

if (isset($_GET['disabled'])) {
    $info = "Compte désactivé ou non encore activé par un admin.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check((string)($_POST['csrf'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, username, pass_hash, enabled, role FROM users WHERE username=:u LIMIT 1");
    $stmt->execute([':u' => $username]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, (string)$u['pass_hash'])) {
        log_event('login_fail', $u ? (int)$u['id'] : null, $ip, 'bad_credentials');
        $err = "Identifiants invalides.";
    } elseif ((int)$u['enabled'] !== 1) {
        log_event('login_fail', (int)$u['id'], $ip, 'disabled');
        $err = "Compte non activé / désactivé.";
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['csrf'] = random_id(18);

        $upd = $pdo->prepare("UPDATE users SET last_login_at=:t, last_login_ip=:ip WHERE id=:id");
        $upd->execute([':t'=>date('c'), ':ip'=>$ip, ':id'=>(int)$u['id']]);

        log_event('login_ok', (int)$u['id'], $ip, '');
        header('Location: /?p=home');
        exit;
    }
}
require __DIR__ . '/../templates/header.php';
?>
<div class="card">
  <h2 style="margin:0 0 10px">Connexion</h2>
  <?php if ($info): ?><div class="ok"><?=h($info)?></div><?php endif; ?>
  <?php if ($err): ?><div class="error"><?=h($err)?></div><?php endif; ?>

  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <div class="row">
      <div>
        <label>Utilisateur</label>
        <input type="text" name="username" required>
      </div>
      <div>
        <label>Mot de passe</label>
        <input type="password" name="password" required>
      </div>
    </div>
    <div style="margin-top:12px">
      <button type="submit">Se connecter</button>
    </div>
  </form>

  <p class="muted" style="margin-top:10px">
    Pas de compte ? <a href="/?p=register">Créer un compte</a> (validation admin).
  </p>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
