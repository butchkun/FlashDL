<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
$u = current_user();
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?=h(APP_NAME)?></title>
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
  <div class="page-bg"></div>
  <div class="binary-overlay"></div>

  <header class="topbar">
    <div class="brand">
      <div class="brand-mark">FC</div>
      <div class="brand-name">Flash Copy Transfert</div>
    </div>

    <div class="top-actions">
      <?php if ($u): ?>
        <a class="btn secondary" href="/?p=upload">Uploader</a>
		<a class="btn secondary" href="/?p=my_uploads">Mes uploads</a>
        <?php if (($u['role'] ?? '') === 'admin'): ?>
          <a class="btn secondary" href="/?p=admin">Admin</a>
        <?php endif; ?>
        <a class="btn" href="/?p=logout">Déconnexion</a>
      <?php else: ?>
        <a class="btn secondary" href="/?p=login">Connexion</a>
        <a class="btn secondary" href="/?p=register">Créer un compte</a>
      <?php endif; ?>

      <!-- bouton style "Contactez-nous" (tu peux le pointer où tu veux) -->
      <a class="btn" href="mailto:maxime.beague@flash-copy.fr">Contactez-nous</a>
    </div>
  </header>

  <main class="wrap">
