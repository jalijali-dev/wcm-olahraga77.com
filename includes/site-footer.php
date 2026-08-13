<?php declare(strict_types=1); ?>
<footer class="wpm-footer">
    <div class="wpm-container">
        <div class="wpm-footer__grid">
            <div class="wpm-footer__brand-col">
                <a href="<?= wpm_esc(wpm_base_url('/')) ?>" class="wpm-brand wpm-brand--footer" aria-label="Olahraga77.com — Beranda">
                    <img class="wpm-brand__logo" src="<?= wpm_esc(wpm_base_url('/assets/img/logo-mark.jpeg')) ?>" alt="Logo Olahraga77.com">
                    <span class="wpm-brand__copy">
                        <span class="wpm-brand__name">Olahraga<span>77</span>.com</span>
                        <span class="wpm-brand__tagline"><?= wpm_esc(WPM_SITE_TAGLINE) ?></span>
                    </span>
                </a>
                <p class="wpm-footer__about">Portal sepak bola Indonesia dengan update Timnas, Liga 1, transfer, dan kabar pertandingan terbaru.</p>
            </div>

            <div class="wpm-footer__column">
                <h2>Navigasi</h2>
                <div class="wpm-footer__links wpm-footer__links--stacked">
                    <a href="<?= wpm_esc(wpm_base_url('/')) ?>">Beranda</a>
                    <?php foreach (wpm_site_nav_categories() as $slug => $label): ?>
                    <a href="<?= wpm_esc(wpm_category_url($slug)) ?>"><?= wpm_esc($label) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="wpm-footer__column">
                <h2>Olahraga77</h2>
                <p class="wpm-footer__note">Berita disajikan dengan tampilan cepat, ringan, dan nyaman dibaca dari desktop maupun perangkat mobile.</p>
                <a class="wpm-footer__search-link" href="<?= wpm_esc(wpm_base_url('/cari.php')) ?>">Cari berita <span aria-hidden="true">→</span></a>
            </div>
        </div>

        <div class="wpm-footer__bottom">
            <div class="wpm-footer__copy">&copy; <?= date('Y') ?> Olahraga77.com. Seluruh hak cipta dilindungi.</div>
            <div class="wpm-footer__signature"><span></span> Update sepak bola Indonesia</div>
        </div>
    </div>
</footer>
</body>
</html>
