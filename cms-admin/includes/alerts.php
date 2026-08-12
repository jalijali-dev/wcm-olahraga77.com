<?php
declare(strict_types=1);

$alerts = $alerts ?? [];
?>
<div class="admin-alerts" role="region" aria-label="Notices">
    <?php foreach ($alerts as $alert) : ?>
        <div class="admin-alert admin-alert--<?= cms_esc($alert['type'] ?? 'info') ?>" data-dismissible>
            <span class="admin-alert__text"><?php
                // 'raw' => true lets a page show a pre-built HTML message
                // (e.g. with a clickable <a> link) without it coming out
                // as escaped "&#039;"/"<a href=...>" text. Only ever set
                // this for strings the page itself constructed — never
                // for raw user input.
                echo !empty($alert['raw']) ? (string) ($alert['message'] ?? '') : cms_esc($alert['message'] ?? '');
            ?></span>
            <button type="button" class="admin-alert__dismiss" aria-label="Dismiss">&times;</button>
        </div>
    <?php endforeach; ?>
</div>
<div class="admin-content">
