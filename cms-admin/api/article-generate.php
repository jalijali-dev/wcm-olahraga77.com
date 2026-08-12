<?php
declare(strict_types=1);

/**
 * AJAX endpoint for the "Generate Article" button on
 * cms-admin/pages/pages.php ("Generate Article with Agent SEO" helper
 * card). Takes the article title plus free-form editorial notes and asks
 * the configured `seo_agent` AI agent to draft excerpt + HTML content +
 * meta_title + meta_description.
 *
 * Request:  POST multipart/form-data — title, notes, csrf_token
 * Response: JSON — {success, excerpt, content, meta_title, meta_description, error}
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';

header('Content-Type: application/json; charset=utf-8');

/** @return never */
function cms_article_generate_respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

cms_ai_rate_limit(5, 60, [
    'success' => false, 'excerpt' => '', 'content' => '',
    'meta_title' => '', 'meta_description' => '', 'error' => '',
]);
session_write_close();
set_time_limit(70);

$title  = trim((string) ($_POST['title'] ?? ''));
$notes  = trim((string) ($_POST['notes'] ?? ''));
$pageId = (int) ($_POST['page_id'] ?? 0) ?: null;

if ($title === '' && $notes === '') {
    cms_article_generate_respond([
        'success' => false, 'excerpt' => '', 'content' => '',
        'meta_title' => '', 'meta_description' => '',
        'error' => 'Isi Title atau Catatan untuk Agent SEO dulu sebelum generate artikel.',
    ]);
}

$defaultSystemPrompt =
    'You are "Agent SEO", an editorial writing assistant for an Indonesian-language sports news ' .
    'website. Write in Bahasa Indonesia by default, unless the editorial notes explicitly ask for ' .
    'another language or tone. Given a title and editorial notes, write a complete, well-structured ' .
    'article body as clean HTML using only <p>, <h2>, <h3>, <ul>, <li>, <strong>, <em> tags — no ' .
    '<html>, <body>, <script>, or inline styles. Also produce a short 1-2 sentence excerpt, a ' .
    'meta_title (max 60 characters), and a meta_description (max 155 characters). ' .
    'If the editorial notes conflict with these defaults, the notes take priority. ' .
    'Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
    'this shape: {"excerpt": "...", "content": "...", "meta_title": "...", "meta_description": "..."}';

$agent = cms_ai_resolve_agent($pdo, 'seo_agent', $defaultSystemPrompt);
if (!$agent['ok']) {
    cms_ai_log('article-generate', 'article', false, '', 0, 0, 0, $agent['error']);
    cms_article_generate_respond([
        'success' => false, 'excerpt' => '', 'content' => '',
        'meta_title' => '', 'meta_description' => '', 'error' => $agent['error'],
    ]);
}

// Fase 3: fold in active style rules + past approved article_draft jobs,
// if any exist yet — see services/GrowthAgentPromptBuilder.php. No-op
// ('') until an admin curates style rules or approves a job.
try {
    require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
    cms_growth_agent_ensure_schema($pdo);
    $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('seo_agent', 'article_draft'));
    if ($growthContext !== '') {
        $agent['system_prompt'] = trim($agent['system_prompt'] . "\n\n" . $growthContext);
    }
} catch (Throwable $e) {
    // Ignore — generation proceeds on the agent's own system prompt.
}

$inputBrief = ['title' => $title, 'notes' => $notes];
$userPrompt = "Title: {$title}\n\nEditorial notes (these override the defaults above when they conflict):\n" . mb_substr($notes, 0, 3000);

$result = cms_ai_call_provider(
    $agent['provider'],
    $agent['api_key'],
    $agent['model'],
    $userPrompt,
    $agent['system_prompt'],
    max($agent['max_tokens'], 4000),
    $agent['temperature']
);

$parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
$retried = false;

// One retry with a sharper corrective instruction if the model replied
// but didn't give back valid JSON with a "content" key — a full article
// is much more likely to hit this than a short meta pair (long HTML body
// inside a JSON string is easy to mangle), so this matters more here than
// it did for seo-generate.php.
if ($result['success'] && ($parsed === null || !isset($parsed['content']))) {
    $retried = true;
    $correctivePrompt = $userPrompt .
        "\n\n---\nYour previous reply could not be parsed. Reply with ONLY a raw JSON object, " .
        'no markdown, no code fences, no commentary, in exactly this shape: ' .
        '{"excerpt": "...", "content": "...", "meta_title": "...", "meta_description": "..."}';

    $result = cms_ai_call_provider(
        $agent['provider'],
        $agent['api_key'],
        $agent['model'],
        $correctivePrompt,
        $agent['system_prompt'],
        max($agent['max_tokens'], 4000),
        $agent['temperature']
    );
    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
}

cms_ai_log(
    'article-generate',
    'article',
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
        $pdo, 'article_draft', 'seo_agent', $pageId, 'failed', $inputBrief, null,
        $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'],
        'AI request failed: ' . $result['error'] . ($retried ? ' (after 1 retry)' : '')
    );
    cms_article_generate_respond([
        'success' => false, 'excerpt' => '', 'content' => '',
        'meta_title' => '', 'meta_description' => '',
        'error' => 'AI request failed: ' . $result['error'],
    ]);
}

if ($parsed === null || !isset($parsed['content'])) {
    cms_growth_agent_log_job(
        $pdo, 'article_draft', 'seo_agent', $pageId, 'failed', $inputBrief, null,
        $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'],
        'AI response was not in the expected format' . ($retried ? ' (after 1 retry)' : '')
    );
    cms_article_generate_respond([
        'success' => false, 'excerpt' => '', 'content' => '',
        'meta_title' => '', 'meta_description' => '',
        'error' => 'AI response was not in the expected format. Please try again.',
    ]);
}

$output = [
    'excerpt' => trim((string) ($parsed['excerpt'] ?? '')),
    'content' => trim((string) ($parsed['content'] ?? '')),
    'meta_title' => mb_substr(trim((string) ($parsed['meta_title'] ?? '')), 0, 255),
    'meta_description' => mb_substr(trim((string) ($parsed['meta_description'] ?? '')), 0, 255),
];

cms_growth_agent_log_job(
    $pdo, 'article_draft', 'seo_agent', $pageId, 'succeeded', $inputBrief, $output,
    $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms']
);

cms_article_generate_respond([
    'success' => true,
    'excerpt' => $output['excerpt'],
    'content' => $output['content'],
    'meta_title' => $output['meta_title'],
    'meta_description' => $output['meta_description'],
    'error' => '',
]);
