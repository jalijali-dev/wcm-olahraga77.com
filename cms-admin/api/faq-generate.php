<?php
declare(strict_types=1);

/**
 * AJAX endpoint for the "Generate FAQ" button on cms-admin/pages/pages.php
 * ("Generate FAQ with Agent SEO" helper card). Takes the current
 * title/content/excerpt plus optional dev notes and asks the configured
 * `seo_agent` AI agent for 5 Bahasa Indonesia Q&A pairs.
 *
 * Request:  POST multipart/form-data — title, content, excerpt, dev_notes, csrf_token
 * Response: JSON — {success, faq: [{question, answer}, ...], error}
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/growth-agent-service.php';

header('Content-Type: application/json; charset=utf-8');

/** @return never */
function cms_faq_generate_respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Pull the FAQ list out of whatever cms_ai_extract_json() decoded.
 * Accepts the requested {"faq": [...]} shape, but ALSO accepts a bare
 * [...] array — smaller/faster models (this agent is often configured
 * with a Haiku-class model) frequently drop the "faq" wrapper key even
 * when told not to, especially once the instruction also says "exactly
 * 5 items", which reads to the model like "just give me the array".
 * Rejecting that outright was the actual cause of this endpoint's
 * "AI response was not in the expected format" failures — being
 * tolerant of both shapes here fixes it without depending on the model
 * complying with the wrapper on every single call.
 *
 * @return array<int, mixed>|null
 */
function cms_faq_extract_items(mixed $parsed): ?array
{
    if (!is_array($parsed)) {
        return null;
    }
    if (isset($parsed['faq']) && is_array($parsed['faq'])) {
        return $parsed['faq'];
    }
    // A bare list looks like [0 => [...], 1 => [...], ...] — sequential
    // integer keys starting at 0. An associative array (e.g. a single
    // {"question":...,"answer":...} object, or some other unrelated
    // shape) is NOT treated as the list.
    if ($parsed !== [] && array_keys($parsed) === range(0, count($parsed) - 1)) {
        return $parsed;
    }
    return null;
}

cms_ai_rate_limit(8, 60, ['success' => false, 'faq' => [], 'error' => '']);
session_write_close();
set_time_limit(70);

$title    = trim((string) ($_POST['title'] ?? ''));
$content  = trim((string) ($_POST['content'] ?? ''));
$excerpt  = trim((string) ($_POST['excerpt'] ?? ''));
$devNotes = trim((string) ($_POST['dev_notes'] ?? ''));
$pageId   = (int) ($_POST['page_id'] ?? 0) ?: null;

if ($title === '' && $content === '') {
    cms_faq_generate_respond([
        'success' => false, 'faq' => [],
        'error' => 'Isi Title atau Content dulu sebelum generate FAQ.',
    ]);
}

$defaultSystemPrompt =
    'You are "Agent SEO" for an Indonesian-language sports news website. Given an article\'s title, ' .
    'excerpt, content, and optional editorial notes, generate exactly 5 frequently-asked-questions ' .
    'with clear, concise answers relevant to the article, written in Bahasa Indonesia. ' .
    'Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
    'this shape: {"faq": [{"question": "...", "answer": "..."}, ...]} with exactly 5 items.';

$agent = cms_ai_resolve_agent($pdo, 'seo_agent', $defaultSystemPrompt);
if (!$agent['ok']) {
    cms_ai_log('faq-generate', 'faq', false, '', 0, 0, 0, $agent['error']);
    cms_faq_generate_respond(['success' => false, 'faq' => [], 'error' => $agent['error']]);
}

// Fase 3: fold in active style rules + past approved faq jobs, if any
// exist yet — see services/GrowthAgentPromptBuilder.php. No-op ('')
// until an admin curates style rules or approves a job.
try {
    require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
    cms_growth_agent_ensure_schema($pdo);
    $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('seo_agent', 'faq'));
    if ($growthContext !== '') {
        $agent['system_prompt'] = trim($agent['system_prompt'] . "\n\n" . $growthContext);
    }
} catch (Throwable $e) {
    // Ignore — generation proceeds on the agent's own system prompt.
}

$inputBrief = ['title' => $title, 'excerpt' => $excerpt, 'dev_notes' => $devNotes, 'content_len' => mb_strlen($content)];
$userPrompt = "Title: {$title}\nExcerpt: {$excerpt}\nEditorial notes: {$devNotes}\nContent:\n" . mb_substr($content, 0, 6000);

$result = cms_ai_call_provider(
    $agent['provider'],
    $agent['api_key'],
    $agent['model'],
    $userPrompt,
    $agent['system_prompt'],
    max($agent['max_tokens'], 900),
    $agent['temperature']
);

$parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
$faqRaw = cms_faq_extract_items($parsed);
$retried = false;

if ($result['success'] && $faqRaw === null) {
    $retried = true;
    $correctivePrompt = $userPrompt .
        "\n\n---\nYour previous reply could not be parsed. Reply with ONLY a raw JSON object, " .
        'no markdown, no code fences, no commentary, in exactly this shape: ' .
        '{"faq": [{"question": "...", "answer": "..."}, ...]} with exactly 5 items.';

    $result = cms_ai_call_provider(
        $agent['provider'],
        $agent['api_key'],
        $agent['model'],
        $correctivePrompt,
        $agent['system_prompt'],
        max($agent['max_tokens'], 900),
        $agent['temperature']
    );
    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
    $faqRaw = cms_faq_extract_items($parsed);
}

cms_ai_log(
    'faq-generate',
    'faq',
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
        $pdo, 'faq', 'seo_agent', $pageId, 'failed', $inputBrief, null,
        $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'],
        'AI request failed: ' . $result['error'] . ($retried ? ' (after 1 retry)' : '')
    );
    cms_faq_generate_respond(['success' => false, 'faq' => [], 'error' => 'AI request failed: ' . $result['error']]);
}

if ($faqRaw === null) {
    cms_growth_agent_log_job(
        $pdo, 'faq', 'seo_agent', $pageId, 'failed', $inputBrief, null,
        $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'],
        'AI response was not in the expected format' . ($retried ? ' (after 1 retry)' : '')
    );
    cms_faq_generate_respond([
        'success' => false, 'faq' => [],
        'error' => 'AI response was not in the expected format. Please try again.',
    ]);
}

$faq = [];
foreach ($faqRaw as $item) {
    if (!is_array($item)) {
        continue;
    }
    $question = trim((string) ($item['question'] ?? ''));
    $answer   = trim((string) ($item['answer'] ?? ''));
    if ($question === '' || $answer === '') {
        continue;
    }
    $faq[] = ['question' => $question, 'answer' => $answer];
    if (count($faq) >= 5) {
        break;
    }
}

if ($faq === []) {
    cms_growth_agent_log_job(
        $pdo, 'faq', 'seo_agent', $pageId, 'failed', $inputBrief, null,
        $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'],
        'AI returned no usable FAQ items' . ($retried ? ' (after 1 retry)' : '')
    );
    cms_faq_generate_respond([
        'success' => false, 'faq' => [],
        'error' => 'AI did not return any usable FAQ items. Please try again.',
    ]);
}

cms_growth_agent_log_job(
    $pdo, 'faq', 'seo_agent', $pageId, 'succeeded', $inputBrief, ['faq' => $faq],
    $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms']
);

cms_faq_generate_respond(['success' => true, 'faq' => $faq, 'error' => '']);
