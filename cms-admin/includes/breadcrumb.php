<?php
declare(strict_types=1);

$breadcrumbs = $breadcrumbs ?? [['label' => 'Dashboard', 'href' => '']];
?>
<nav class="admin-breadcrumb" aria-label="Breadcrumb">
    <ol class="admin-breadcrumb__list">
        <?php foreach ($breadcrumbs as $index => $crumb) :
            $isLast = $index === count($breadcrumbs) - 1;
            $href = $crumb['href'] ?? '';
            ?>
            <li class="admin-breadcrumb__item">
                <?php if ($isLast || $href === '') : ?>
                    <span class="admin-breadcrumb__current"<?= $isLast ? ' aria-current="page"' : '' ?>><?= cms_esc($crumb['label']) ?></span>
                <?php else : ?>
                    <a href="<?= cms_esc($href) ?>"><?= cms_esc($crumb['label']) ?></a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
