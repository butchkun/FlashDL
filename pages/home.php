<?php
declare(strict_types=1);
require __DIR__ . '/../templates/header.php';
$u = current_user();
?>

<section class="hero">
  <div class="hero-left">
    <div class="logo-big">
      <span>flash</span>
      <span>copy</span>
    </div>

    <div class="services">
      Numérisation<br>
      Micrographie<br>
      Saisie de données
    </div>

    <div class="desc">
      Plateforme de dépôt temporaire pour vos fichiers.
    </div>

    <div class="cta">
      <?php if (!$u): ?>
        <a class="btn" href="/?p=login">Découvrir</a>
      <?php else: ?>
        <a class="btn" href="/?p=upload">Aller à l’upload</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="hero-sep"></div>

  <div class="hero-visual">
    <img src="/assets/img/robot_demat_fibre.png" alt="Robot de dématérialisation patrimoniale">
</div>

</section>

<?php require __DIR__ . '/../templates/footer.php'; ?>
