<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

/**
 * Global admin search — AJAX endpoint for the navbar search box.
 * Searches across Pages & Articles and Contact Messages, and returns a
 * small grouped result set as JSON.
 *
 * Testimonials intentionally excluded (13 Jul 2026) — its sidebar menu
 * was removed as not relevant to a news site, so it shouldn't resurface
 * via search either. The testimonials.php page and its data are untouched
 * (still directly reachable by URL) in case it's wanted again later.
 *
 * GET /cms-admin/actions/search.php?q=term
 */

header('Content-Type: application/json');

$q = trim((string) ($_GET['q'] ?? ''));

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'query' => $q, 'results' => []]);
    exit;
}

$like = '%' . $q . '%';
$results = [];

/**
 * Run one search query and append its rows (already shaped as result
 * items) to $results. Wrapped per-table so one missing/broken table
 * never breaks the whole search.
 */
$runSearch = static function (PDO $pdo, string $sql, array $params, callable $mapRow) use (&$results): void {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = $mapRow($row);
        }
    } catch (PDOException $e) {
        // Silently skip this category — a schema hiccup in one table
        // shouldn't take down search for everything else.
    }
};

// ── Pages & Articles ────────────────────────────────────────────────────
$runSearch(
    $pdo,
    'SELECT page_id, title, slug FROM pages WHERE title LIKE :q1 OR slug LIKE :q2 ORDER BY title LIMIT 5',
    ['q1' => $like, 'q2' => $like],
    static fn (array $row): array => [
        'type'     => 'Pages & Articles',
        'title'    => (string) $row['title'],
        'subtitle' => '/' . $row['slug'],
        'url'      => 'pages.php?edit=' . (int) $row['page_id'],
    ]
);

// ── Contact Messages ─────────────────────────────────────────────────────
$runSearch(
    $pdo,
    'SELECT id, full_name, email, message FROM contact_messages
     WHERE full_name LIKE :q1 OR email LIKE :q2 OR message LIKE :q3
     ORDER BY created_at DESC LIMIT 5',
    ['q1' => $like, 'q2' => $like, 'q3' => $like],
    static fn (array $row): array => [
        'type'     => 'Contact Messages',
        'title'    => (string) $row['full_name'],
        'subtitle' => (string) $row['email'],
        'url'      => 'contact-messages.php?view=' . (int) $row['id'],
    ]
);

echo json_encode(['ok' => true, 'query' => $q, 'results' => $results], JSON_UNESCAPED_SLASHES);
