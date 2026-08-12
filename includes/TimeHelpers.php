<?php
declare(strict_types=1);

/**
 * includes/TimeHelpers.php
 *
 * Missing entirely until 12 Agu 2026 — cms-admin/pages/pages.php
 * (require_once dirname(__DIR__) . '/../includes/TimeHelpers.php') expects
 * this file at the project root, but when cms-admin/ was cloned from
 * sagagoal.com into this project, only the cms-admin/ folder itself was
 * copied (see /CLAUDE.md "Konteks & tujuan") — this file, which lives
 * outside cms-admin/, never came along. Dormant bug: only triggered when
 * saving a published article with no explicit published_at (the
 * auto-fill-on-publish path in pages.php), which is why it went unnoticed
 * until an admin actually hit that exact flow in production.
 */

if (!function_exists('wpm_now_wib')) {
    /**
     * Current time in WIB (Asia/Jakarta, UTC+7) — matches wpm_format_date()/
     * wpm_time_ago() on the public frontend (includes/site-bootstrap.php),
     * which render dates assuming WIB, not this server's UTC default. Used
     * to auto-fill `published_at` when an admin publishes an article
     * without picking a date manually.
     */
    function wpm_now_wib(): DateTime
    {
        return new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    }
}
