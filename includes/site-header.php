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
<!--
  Mobile menu toggle — pure CSS "checkbox hack", no JS. The checkbox is
  visually hidden; the hamburger <label> below toggles it; CSS shows/hides
  #wpm-mobile-menu based on :checked. Keeps this site's "no JS" pattern
  intact while still getting a real slide-down mobile nav instead of the
  horizontal-scroll nav that used to cut off "RAGAM" on small screens
  (found 21 Agu 2026, operator screenshot on iPhone width).
-->
<input type="checkbox" id="wpm-mobile-toggle" class="wpm-mobile-toggle-input" aria-hidden="true">

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
            <label for="wpm-mobile-toggle" class="wpm-hamburger" aria-label="Buka menu">
                <span class="wpm-hamburger__box">
                    <span class="wpm-hamburger__line"></span>
                    <span class="wpm-hamburger__line"></span>
                    <span class="wpm-hamburger__line"></span>
                </span>
            </label>
        </div>
    </div>
    <nav class="wpm-header__nav" aria-label="Navigasi utama">
        <div class="wpm-container">
            <div class="wpm-nav">
                <a href="<?= wpm_esc(wpm_base_url('/')) ?>" class="<?= $activeNavSlug === 'home' ? 'is-active' : '' ?>">Beranda</a>
                <?php foreach (wpm_site_nav_categories() as $navSlug => $navLabel): ?>
                <a href="<?= wpm_esc(wpm_category_url($navSlug)) ?>" class="<?= $activeNavSlug === $navSlug ? 'is-active' : '' ?>"><?= wpm_esc($navLabel) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>
</header>

<label for="wpm-mobile-toggle" class="wpm-mobile-menu__backdrop" aria-hidden="true"></label>
<nav id="wpm-mobile-menu" class="wpm-mobile-menu" aria-label="Navigasi mobile">
    <div class="wpm-mobile-menu__head">
        <span class="wpm-brand__name">Olahraga<span>77</span>.com</span>
        <label for="wpm-mobile-toggle" class="wpm-mobile-menu__close" aria-label="Tutup menu">&times;</label>
    </div>
    <form class="wpm-mobile-menu__search" action="<?= wpm_esc(wpm_base_url('/cari.php')) ?>" method="get" role="search">
        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><path d="m21 21-4.35-4.35m1.35-5.65a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <input type="text" name="q" placeholder="Cari berita..." aria-label="Cari berita">
    </form>
    <a href="<?= wpm_esc(wpm_base_url('/')) ?>" class="wpm-mobile-menu__link <?= $activeNavSlug === 'home' ? 'is-active' : '' ?>">Beranda</a>
    <?php foreach (wpm_site_nav_categories() as $navSlug => $navLabel): ?>
    <a href="<?= wpm_esc(wpm_category_url($navSlug)) ?>" class="wpm-mobile-menu__link <?= $activeNavSlug === $navSlug ? 'is-active' : '' ?>"><?= wpm_esc($navLabel) ?></a>
    <?php endforeach; ?>
</nav>
