<?php declare(strict_types=1); ?>
<footer class="wpm-footer">
    <div class="wpm-container">
        <span class="wpm-brand__name">Olahraga<span style="color:#3fc177">77</span>.com</span>
        <div class="wpm-footer__links">
            <a href="<?= wpm_esc(wpm_base_url('/')) ?>">Beranda</a>
            <?php foreach (wpm_site_nav_categories() as $slug => $label): ?>
            <a href="<?= wpm_esc(wpm_category_url($slug)) ?>"><?= wpm_esc($label) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="wpm-footer__copy">&copy; <?= date('Y') ?> Olahraga77.com. Seluruh hak cipta dilindungi.</div>
    </div>
</footer>
</body>
</html>
