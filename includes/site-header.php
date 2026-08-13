<?php
declare(strict_types=1);
/**
 * includes/site-header.php — shared header partial.
 * Expects (optional): $pageTitle, $metaDescription, $activeNavSlug.
 */
$pageTitle = $pageTitle ?? WPM_SITE_NAME;
$metaDescription = $metaDescription ?? WPM_SITE_TAGLINE;
$activeNavSlug = $activeNavSlug ?? '';
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= wpm_esc($pageTitle) ?></title>
<meta name="description" content="<?= wpm_esc($metaDescription) ?>">
<link rel="stylesheet" href="<?= wpm_esc(wpm_base_url('/assets/css/site.css')) ?>">
<link rel="icon" type="image/svg+xml" href="<?= wpm_esc(wpm_base_url('/assets/img/favicon.svg')) ?>">
<link rel="alternate icon" href="<?= wpm_esc(wpm_base_url('/assets/img/favicon.ico')) ?>">
</head>
<body>
<header class="wpm-header">
    <div class="wpm-header__top">
        <div class="wpm-container">
            <a href="<?= wpm_esc(wpm_base_url('/')) ?>" class="wpm-brand" aria-label="Olahraga77.com — Beranda">
                <img class="wpm-brand__logo" src="<?= wpm_esc(wpm_base_url('/assets/img/logo-mark.jpeg')) ?>" alt="Logo Olahraga77.com">
                <span class="wpm-brand__copy">
                    <span class="wpm-brand__name">Olahraga<span>77</span>.com</span>
                    <span class="wpm-brand__tagline"><?= wpm_esc(WPM_SITE_TAGLINE) ?></span>
                </span>
            </a>
            <form class="wpm-header__search" action="<?= wpm_esc(wpm_base_url('/cari.php')) ?>" method="get" role="search">
                <svg class="wpm-header__search-icon" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <input type="text" name="q" placeholder="Cari berita..." aria-label="Cari berita">
            </form>
        </div>
    </div>
    <nav class="wpm-header__nav" aria-label="Navigasi utama">
        <div class="wpm-container">
            <div class="wpm-nav">
                <a href="<?= wpm_esc(wpm_base_url('/')) ?>" class="<?= $activeNavSlug === 'home' ? 'is-active' : '' ?>">Beranda</a>
                <?php foreach (wpm_site_nav_categories() as $slug => $label): ?>
                <a href="<?= wpm_esc(wpm_category_url($slug)) ?>" class="<?= $activeNavSlug === $slug ? 'is-active' : '' ?>"><?= wpm_esc($label) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>
</header>
