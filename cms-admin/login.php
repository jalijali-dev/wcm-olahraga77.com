<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

cms_session_start();

if (!empty($_SESSION['cms_admin_id'])) {
    header('Location: dashboard.php', true, 302);
    exit;
}

$loginError = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $loginError = 'Email dan password wajib diisi.';
    } else {
        require_once __DIR__ . '/config/database.php';

        $stmt = $pdo->prepare(
            'SELECT admin_id, name, email, password_hash, role, is_active
             FROM admins
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $admin = $stmt->fetch();

        $valid = $admin
            && (int) ($admin['is_active'] ?? 0) === 1
            && password_verify($password, (string) ($admin['password_hash'] ?? ''));

        if ($valid) {
            session_regenerate_id(true);
            $_SESSION['cms_admin_id']    = (int)    $admin['admin_id'];
            $_SESSION['cms_admin_name']  = (string) $admin['name'];
            $_SESSION['cms_admin_email'] = (string) $admin['email'];
            $_SESSION['cms_admin_role']  = (string) $admin['role'];

            header('Location: dashboard.php', true, 302);
            exit;
        }

        $loginError = 'Email atau password tidak valid.';
    }
}

$fav     = cms_esc(cms_favicon_url());
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Login · <?= cms_esc(CMS_ADMIN_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" href="<?= $fav ?>" type="image/png">
    <style>
    /*
     * WPM Login — Deep Purple identity
     *
     * Standalone stylesheet: does NOT load admin.css, does NOT read the
     * user's saved admin theme. This page always uses the fixed Deep Purple
     * palette defined here. After login, the dashboard reads the user's
     * saved theme preference from localStorage as usual.
     *
     * Namespace: lp- (login-page) — isolated from all admin panel classes.
     */

    *, *::before, *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    :root {
        /* ── Palette ── */
        --lp-bg-deep:          #080012;
        --lp-purple:           #a855f7;
        --lp-violet:           #7c3aed;
        --lp-text:             #f0e6ff;
        --lp-muted:            rgba(240, 230, 255, 0.52);
        --lp-faint:            rgba(240, 230, 255, 0.18);

        /* ── Card ── */
        --lp-card-bg:          rgba(255, 255, 255, 0.038);
        --lp-card-border:      rgba(168, 85, 247, 0.22);
        --lp-card-shadow:
            0 0 0 1px rgba(168, 85, 247, 0.08),
            0 24px 64px rgba(124, 58, 237, 0.28),
            0 4px 16px rgba(0, 0, 0, 0.45);

        /* ── Inputs ── */
        --lp-input-bg:         rgba(255, 255, 255, 0.055);
        --lp-input-border:     rgba(168, 85, 247, 0.28);
        --lp-input-focus-bdr:  #a855f7;
        --lp-input-focus-ring: rgba(168, 85, 247, 0.30);

        /* ── Button ── */
        --lp-btn-shadow:       0 4px 20px rgba(124, 58, 237, 0.44);
        --lp-btn-shadow-hover: 0 8px 30px rgba(124, 58, 237, 0.58);

        /* ── Links ── */
        --lp-link:             #c084fc;
        --lp-link-hover:       #e9d5ff;

        /* ── Error / notice ── */
        --lp-error-bg:         rgba(239, 68, 68, 0.10);
        --lp-error-border:     rgba(239, 68, 68, 0.32);
        --lp-error-text:       #fca5a5;
        --lp-notice-bg:        rgba(168, 85, 247, 0.09);
        --lp-notice-border:    rgba(168, 85, 247, 0.28);
        --lp-notice-text:      rgba(240, 230, 255, 0.68);

        /* ── Typography ── */
        --lp-font: 'Poppins', system-ui, -apple-system, sans-serif;

        /* ── Shape ── */
        --lp-r-card:  24px;
        --lp-r-input: 12px;
        --lp-r-btn:   12px;
        --lp-r-logo:  18px;
    }

    html, body { height: 100%; }

    body {
        font-family: var(--lp-font);
        background: var(--lp-bg-deep);
        color: var(--lp-text);
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 32px 20px;
        overflow-x: hidden;
    }

    /* ── Animated background ──────────────────────────────────── */

    .lp-bg {
        position: fixed;
        inset: 0;
        z-index: 0;
        background:
            radial-gradient(ellipse 85% 55% at 18% 8%,  rgba(124, 58, 237, 0.22) 0%, transparent 60%),
            radial-gradient(ellipse 65% 50% at 82% 92%, rgba(168, 85, 247, 0.17) 0%, transparent 55%),
            linear-gradient(160deg, #0e0020 0%, #080012 48%, #100018 100%);
        overflow: hidden;
    }

    .lp-glow {
        position: absolute;
        border-radius: 999px;
        filter: blur(80px);
        animation: lpPulse var(--dur, 8s) ease-in-out infinite;
        animation-delay: var(--delay, 0s);
        pointer-events: none;
        will-change: opacity, transform;
    }

    .lp-glow--a {
        --dur: 9s;
        --delay: 0s;
        width: 520px; height: 520px;
        top: -130px; left: -150px;
        background: radial-gradient(circle, rgba(124, 58, 237, 0.30) 0%, transparent 68%);
    }
    .lp-glow--b {
        --dur: 11s;
        --delay: -5s;
        width: 440px; height: 440px;
        bottom: -110px; right: -110px;
        background: radial-gradient(circle, rgba(168, 85, 247, 0.24) 0%, transparent 68%);
    }
    .lp-glow--c {
        --dur: 13s;
        --delay: -2.5s;
        width: 300px; height: 300px;
        top: 45%; left: 58%;
        background: radial-gradient(circle, rgba(109, 40, 217, 0.16) 0%, transparent 68%);
    }

    @keyframes lpPulse {
        0%, 100% { opacity: 0.72; transform: scale(1);    }
        50%       { opacity: 1.00; transform: scale(1.13); }
    }

    /* ── Shell ────────────────────────────────────────────────── */

    .lp-shell {
        position: relative;
        z-index: 1;
        width: min(460px, 100%);
    }

    /* ── Card ─────────────────────────────────────────────────── */

    .lp-card {
        background: var(--lp-card-bg);
        border: 1px solid var(--lp-card-border);
        border-radius: var(--lp-r-card);
        box-shadow: var(--lp-card-shadow);
        backdrop-filter: blur(32px) saturate(1.5);
        -webkit-backdrop-filter: blur(32px) saturate(1.5);
        padding: 42px 36px 36px;
    }

    /* ── Brand ────────────────────────────────────────────────── */

    .lp-brand {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        margin-bottom: 28px;
    }

    .lp-brand__logo-wrap {
        position: relative;
        margin-bottom: 20px;
        width: 136px;
        height: 136px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .lp-brand__logo-wrap::before {
        /* Soft purple halo behind the logo */
        content: '';
        position: absolute;
        inset: -14px;
        border-radius: calc(var(--lp-r-logo) + 14px);
        background: radial-gradient(circle, rgba(168, 85, 247, 0.22) 0%, transparent 70%);
        animation: lpPulse 6s ease-in-out infinite;
        pointer-events: none;
    }

    .lp-brand__logo {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 136px;
        height: 136px;
        border-radius: var(--lp-r-logo);
        border: 1px solid rgba(168, 85, 247, 0.38);
        box-shadow:
            0 0 0 5px rgba(168, 85, 247, 0.10),
            0 10px 28px rgba(0, 0, 0, 0.45);
        position: relative;
        z-index: 1;
        background: linear-gradient(135deg, #7c3aed 0%, #a855f7 55%, #c084fc 100%);
        user-select: none;
    }

    .lp-brand__logo img {
        width: 82%;
        height: 82%;
        object-fit: contain;
    }

    .lp-brand__name {
        font-size: 1.85rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        line-height: 1.1;
        margin-bottom: 6px;
        /* Gradient text shimmer */
        background: linear-gradient(135deg, #f0e6ff 20%, #c084fc 55%, #f0e6ff 90%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .lp-brand__tagline {
        font-size: 11.5px;
        font-weight: 600;
        letter-spacing: 0.10em;
        text-transform: uppercase;
        color: var(--lp-muted);
    }

    /* ── Divider ──────────────────────────────────────────────── */

    .lp-divider {
        height: 1px;
        background: linear-gradient(90deg, transparent 0%, rgba(168, 85, 247, 0.28) 50%, transparent 100%);
        margin-bottom: 22px;
    }

    /* ── Notice / error banner ────────────────────────────────── */

    .lp-notice {
        margin: 0 0 20px;
        padding: 11px 14px;
        border-radius: 11px;
        font-size: 13px;
        line-height: 1.5;
    }

    .lp-notice--info {
        background: var(--lp-notice-bg);
        border: 1px dashed var(--lp-notice-border);
        color: var(--lp-notice-text);
    }

    .lp-notice--error {
        background: var(--lp-error-bg);
        border: 1px solid var(--lp-error-border);
        color: var(--lp-error-text);
        font-weight: 500;
    }

    /* ── Form ─────────────────────────────────────────────────── */

    .lp-form {
        display: grid;
        gap: 16px;
    }

    .lp-field {
        display: grid;
        gap: 7px;
    }

    .lp-field label {
        font-size: 12.5px;
        font-weight: 600;
        color: var(--lp-muted);
        letter-spacing: 0.03em;
    }

    .lp-field input[type="email"],
    .lp-field input[type="password"] {
        width: 100%;
        padding: 12px 14px;
        background: var(--lp-input-bg);
        border: 1px solid var(--lp-input-border);
        border-radius: var(--lp-r-input);
        color: var(--lp-text);
        font-size: 14px;
        font-family: var(--lp-font);
        font-weight: 400;
        outline: none;
        caret-color: var(--lp-purple);
        transition:
            border-color 0.18s ease,
            box-shadow   0.18s ease,
            background   0.18s ease;
    }

    .lp-field input::placeholder {
        color: var(--lp-faint);
    }

    .lp-field input:focus {
        border-color: var(--lp-input-focus-bdr);
        box-shadow: 0 0 0 3px var(--lp-input-focus-ring);
        background: rgba(255, 255, 255, 0.08);
    }

    /* ── Checkbox row ─────────────────────────────────────────── */

    .lp-checkbox {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 500;
        color: var(--lp-muted);
        user-select: none;
    }

    .lp-checkbox input[type="checkbox"] {
        width: 16px;
        height: 16px;
        accent-color: var(--lp-purple);
        cursor: pointer;
        flex-shrink: 0;
    }

    /* ── Submit button ────────────────────────────────────────── */

    .lp-btn {
        width: 100%;
        padding: 13px 18px;
        margin-top: 4px;
        border: none;
        border-radius: var(--lp-r-btn);
        background: linear-gradient(135deg, #7c3aed 0%, #a855f7 100%);
        color: #fff;
        font-family: var(--lp-font);
        font-size: 15px;
        font-weight: 600;
        letter-spacing: 0.02em;
        cursor: pointer;
        box-shadow: var(--lp-btn-shadow);
        transition:
            opacity     0.18s ease,
            transform   0.18s ease,
            box-shadow  0.18s ease;
    }

    .lp-btn:hover {
        opacity: 0.91;
        transform: translateY(-1px);
        box-shadow: var(--lp-btn-shadow-hover);
    }

    .lp-btn:active {
        transform: translateY(0);
        opacity: 1;
        box-shadow: var(--lp-btn-shadow);
    }

    .lp-btn:focus-visible {
        outline: 2px solid var(--lp-purple);
        outline-offset: 3px;
    }

    /* ── Back link ────────────────────────────────────────────── */

    .lp-back {
        margin-top: 20px;
        text-align: center;
        font-size: 13px;
    }

    .lp-back a {
        color: var(--lp-link);
        text-decoration: none;
        font-weight: 500;
        transition: color 0.18s ease;
    }

    .lp-back a:hover   { color: var(--lp-link-hover); }

    .lp-back a:focus-visible {
        outline: 2px solid var(--lp-purple);
        outline-offset: 2px;
        border-radius: 3px;
    }

    /* ── Responsive ───────────────────────────────────────────── */

    @media (max-width: 480px) {
        .lp-card {
            padding: 30px 20px 26px;
            border-radius: 18px;
        }
        .lp-brand__name { font-size: 1.55rem; }
        .lp-brand__logo-wrap { width: 100px; height: 100px; }
        .lp-brand__logo { width: 100px; height: 100px; }
    }

    /* ── Reduced motion ───────────────────────────────────────── */

    @media (prefers-reduced-motion: reduce) {
        .lp-glow,
        .lp-brand__logo-wrap::before {
            animation: none;
        }
        .lp-btn,
        .lp-field input {
            transition: none;
        }
    }
    </style>
</head>
<body class="lp-body">

<!-- Animated deep-purple background — always on, theme-independent -->
<div class="lp-bg" aria-hidden="true">
    <div class="lp-glow lp-glow--a"></div>
    <div class="lp-glow lp-glow--b"></div>
    <div class="lp-glow lp-glow--c"></div>
</div>

<main class="lp-shell">
    <div class="lp-card" role="region" aria-labelledby="lp-heading">

        <!-- ── Brand ── -->
        <div class="lp-brand">
            <div class="lp-brand__logo-wrap">
                <div class="lp-brand__logo">
                    <img src="<?= cms_esc(cms_asset_url('img/logo-white.png')) ?>" alt="<?= cms_esc(CMS_ADMIN_NAME) ?> logo">
                </div>
            </div>
            <h1 class="lp-brand__name" id="lp-heading"><?= cms_esc(CMS_ADMIN_NAME) ?></h1>
            <p class="lp-brand__tagline">Admin Panel - Olahraga77.com</p>
        </div>

        <div class="lp-divider" aria-hidden="true"></div>

        <!-- ── Notice / error ── -->
        <?php if ($loginError !== '') : ?>
            <p class="lp-notice lp-notice--error" role="alert">
                <?= cms_esc($loginError) ?>
            </p>
        <?php endif; ?>

        <!-- ── Login form ── -->
        <form class="lp-form" method="post" action="login.php" autocomplete="on" novalidate>
            <div class="lp-field">
                <label for="lp-email">Email address</label>
                <input id="lp-email"
                       name="email"
                       type="email"
                       placeholder="admin@example.com"
                       value="<?= cms_esc($email) ?>"
                       required
                       autocomplete="email"
                       spellcheck="false"
                       inputmode="email">
            </div>

            <div class="lp-field">
                <label for="lp-password">Password</label>
                <input id="lp-password"
                       name="password"
                       type="password"
                       placeholder="••••••••"
                       required
                       autocomplete="current-password">
            </div>

            <div class="lp-field">
                <label class="lp-checkbox">
                    <input type="checkbox" name="remember" value="1">
                    <span>Remember me</span>
                </label>
            </div>

            <button type="submit" class="lp-btn">Sign in to <?= cms_esc(CMS_ADMIN_NAME) ?></button>
        </form>

        <p class="lp-back">
            <a href="<?= cms_esc(cms_public_site_url()) ?>">← Back to site</a>
        </p>

    </div>
</main>

</body>
</html>