<?php
declare(strict_types=1);

/**
 * AJAX endpoint for the "Generate SEO" button on cms-admin/pages/pages.php
 * ("Generate SEO with Agent SEO" helper card). Takes the current form's
 * title/slug/excerpt/content plus the shared "Catatan untuk Agent SEO"
 * notes box and asks the configured `seo_agent` AI agent for a meta_title
 * + meta_description pair. `notes` is read the same way Generate
 * Article/FAQ already do (see article-generate.php / faq-generate.php) —
 * previously this endpoint silently ignored it even though the field was
 * shared across all three buttons.
 *
 * Request:  POST multipart/form-data — type, title, slug, excerpt, content, notes, page_id, csrf_token
 * Response: JSON — {success, meta_title, meta_description, error}
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';

header('Content-Type: application/json; charset=utf-8');

/** @return never */
function cms_seo_generate_respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Rate limit must run while the session is still open.
cms_ai_rate_limit(8, 60, ['success' => false, 'meta_title' => '', 'meta_description' => '', 'error' => '']);
session_write_close();
set_time_limit(70);

$title   = trim((string) ($_POST['title'] ?? ''));
$slug    = trim((string) ($_POST['slug'] ?? ''));
$excerpt = trim((string) ($_POST['excerpt'] ?? ''));
$content = trim((string) ($_POST['content'] ?? ''));
$notes   = trim((string) ($_POST['notes'] ?? '')); // "Catatan untuk Agent SEO" box — shared with Generate Article/FAQ
$pageId  = (int) ($_POST['page_id'] ?? 0) ?: null; // set when editing an existing article; null for a brand-new one not saved yet

if ($title === '' && $content === '') {
    cms_seo_generate_respond([
        'success' => false, 'meta_title' => '', 'meta_description' => '',
        'error' => 'Isi Title atau Content dulu sebelum generate SEO.',
    ]);
}

$defaultSystemPrompt =
    'You are "Agent SEO", an SEO assistant for an Indonesian-language sports news website. ' .
    'Given a page/article title, slug, excerpt, content, and optional editorial notes, write a ' .
    'compelling meta_title (max 60 characters) and meta_description (max 155 characters) in the ' .
    'same language as the supplied content (default to Bahasa Indonesia if the input is empty or ' .
    'ambiguous). If the editorial notes conflict with these defaults, the notes take priority — ' .
    'e.g. a note like "focus on a specific product category" should shape the meta_title/meta_' .
    'description even though it is not a formatting instruction. ' .
    'Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in ' .
    'exactly this shape: {"meta_title": "...", "meta_description": "..."}';

$agent = cms_ai_resolve_agent($pdo, 'seo_agent', $defaultSystemPrompt);
if (!$agent['ok']) {
    cms_ai_log('seo-generate', 'page', false, '', 0, 0, 0, $agent['error']);
    cms_seo_generate_respond(['success' => false, 'meta_title' => '', 'meta_description' => '', 'error' => $agent['error']]);
}

// ── Fase 3: context-aware generate ──────────────────────────────────────
// Fold in the active style guide + a few past jobs a human approved
// without edits, if any exist yet. Returns '' (no-op) until an admin
// curates style_rules or approves a job on pages/growth-agent.php — see
// services/GrowthAgentPromptBuilder.php. Wrapped defensively so a context
// build failure never blocks generation.
try {
    require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
    cms_growth_agent_ensure_schema($pdo);
    $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('seo_agent', 'seo_meta'));
    if ($growthContext !== '') {
        $agent['system_prompt'] = trim($agent['system_prompt'] . "\n\n" . $growthContext);
    }
} catch (Throwable $e) {
    // Ignore — generation proceeds on the agent's own system prompt.
}

$inputBrief = ['title' => $title, 'slug' => $slug, 'excerpt' => $excerpt, 'notes' => $notes, 'content_len' => mb_strlen($content)];
$userPrompt = "Title: {$title}\nSlug: {$slug}\nExcerpt: {$excerpt}\n" .
    "Editorial notes (these override the defaults above when they conflict):\n" . mb_substr($notes, 0, 2000) .
    "\nContent:\n" . mb_substr($content, 0, 6000);

$result = cms_ai_call_provider(
    $agent['provider'],
    $agent['api_key'],
    $agent['model'],
    $userPrompt,
    $agent['system_prompt'],
    max($agent['max_tokens'], 300),
    $agent['temperature']
);

$parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
$retried = false;

// One retry with a sharper corrective instruction if the model replied but
// didn't give back valid {"meta_title","meta_description"} JSON — this is
// the exact failure the "AI response was not in the expected format" error
// used to surface immediately. Most transient format slips self-correct
// here instead of forcing the admin to click Generate again by hand.
if ($result['success'] && ($parsed === null || !isset($parsed['meta_title'], $parsed['meta_description']))) {
    $retried = true;
    $correctivePrompt = $userPrompt .
        "\n\n---\nYour previous reply could not be parsed. Reply with ONLY a raw JSON object, " .
        'no markdown, no code fences, no commentary, in exactly this shape: ' .
        '{"meta_title": "...", "meta_description": "..."}';

    $result = cms_ai_call_provider(
        $agent['provider'],
        $agent['api_key'],
        $agent['model'],
        $correctivePrompt,
        $agent['system_prompt'],
        max($agent['max_tokens'], 300),
        $agent['temperature']
    );
    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
}

cms_ai_log(
    'seo-generate',
    'page',
    $result['success'],
    $agent['model'],
    $result['http_status'],
    $result['latency_ms'],
    mb_strlen($userPrompt),
    $result['success'] ? '' : $result['error']
);

$usage = is_array($result['raw'] ?? null) ? ($result['raw']['usage'] ?? []) : [];
$tokensIn  = $agent['provider'] === 'openai' ? (int) ($usage['prompt_tokens'] ?? 0) : (int) ($usage['input_tokens'] ?? 0);
$tokensOut = $agent['provider'] === 'openai' ? (int) ($usage['completion_tokens'] ?? 0) : (int) ($usage['output_tokens'] ?? 0);

if (!$result['success']) {
    cms_growth_agent_log_job(
        $pdo, 'seo_meta', 'seo_agent', $pageId, 'failed', $inputBrief, null,
        $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'],
        'AI request failed: ' . $result['error'] . ($retried ? ' (after 1 retry)' : '')
    );
    cms_seo_generate_respond([
        'success' => false, 'meta_title' => '', 'meta_description' => '',
        'error' => 'AI request failed: ' . $result['error'],
    ]);
}

if ($parsed === null || !isset($parsed['meta_title'], $parsed['meta_description'])) {
    cms_growth_agent_log_job(
        $pdo, 'seo_meta', 'seo_agent', $pageId, 'failed', $inputBrief, null,
        $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'],
        'AI response was not in the expected format' . ($retried ? ' (after 1 retry)' : '')
    );
    cms_seo_generate_respond([
        'success' => false, 'meta_title' => '', 'meta_description' => '',
        'error' => 'AI response was not in the expected format. Please try again.',
    ]);
}

$output = [
    'meta_title' => mb_substr(trim((string) $parsed['meta_title']), 0, 255),
    'meta_description' => mb_substr(trim((string) $parsed['meta_description']), 0, 255),
];

cms_growth_agent_log_job(
    $pdo, 'seo_meta', 'seo_agent', $pageId, 'succeeded', $inputBrief, $output,
    $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms']
);

cms_seo_generate_respond([
    'success' => true,
    'meta_title' => $output['meta_title'],
    'meta_description' => $output['meta_description'],
    'error' => '',
]);
