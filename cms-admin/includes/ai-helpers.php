<?php
declare(strict_types=1);

/**
 * AI endpoint helpers — rate limiting and usage logging.
 *
 * Rate limiter: session-based, no database, no schema.
 * Logger:       file-based (logs/ai.log), auto-creates directory, no DB.
 *
 * Call order in API endpoints:
 *   1. auth.php  (CSRF validated, session open)
 *   2. cms_ai_rate_limit()   ← session must still be writable here
 *   3. session_write_close()
 *   4. set_time_limit()
 *   5. AI call
 *   6. cms_ai_log()
 */

/**
 * Session-based AI request rate limiter.
 *
 * Must be called while the session is still open (before session_write_close).
 * Exits with HTTP 429 and a JSON error body if the limit is exceeded.
 *
 * @param int $max                    Maximum requests allowed within the window.
 * @param int $window                 Window size in seconds.
 * @param array<string, mixed>|null   Optional endpoint-specific JSON error shape.
 */
function cms_ai_rate_limit(int $max = 5, int $window = 60, ?array $errorPayload = null): void
{
    $countKey = 'ai_rl_count';
    $startKey = 'ai_rl_start';

    $now   = time();
    $start = (int) ($_SESSION[$startKey] ?? 0);
    $count = (int) ($_SESSION[$countKey] ?? 0);

    // Start a fresh window if the current one has expired.
    if ($now - $start > $window) {
        $_SESSION[$startKey] = $now;
        $_SESSION[$countKey] = 0;
        $count = 0;
    }

    if ($count >= $max) {
        $retryAfter = max(1, $window - ($now - $start));
        $message = 'Too many AI requests. Please wait ' . $retryAfter . ' second(s) and try again.';
        $payload = $errorPayload ?? [
            'success'          => false,
            'meta_title'       => '',
            'meta_description' => '',
            'error'            => '',
        ];
        $payload['error'] = $message;

        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: ' . $retryAfter);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION[$countKey] = $count + 1;
}

/**
 * Append one line to logs/ai.log (auto-creates the directory on first use).
 *
 * Returns true on successful write, false on failure.
 * Never throws — a logging failure must never break the AI endpoint response.
 *
 * Fields logged (pipe-separated):
 *   timestamp | endpoint | type | success|failure | model | http_status
 *   | latency_ms | prompt_char_length | error_summary (capped 200 chars)
 *
 * Never logged: API key, full prompt text, full response, session ID.
 *
 * @param string $endpoint     e.g. 'seo-generate', 'article-generate', 'faq-generate'
 * @param string $type         e.g. 'product', 'page', 'special_page', 'article', 'faq'
 * @param bool   $success      Whether the AI call succeeded.
 * @param string $model        Model identifier from config.
 * @param int    $httpStatus   Anthropic HTTP status code (0 if unavailable).
 * @param int    $latencyMs    Round-trip latency in milliseconds.
 * @param int    $promptLen    Character length of the prompt (not the content).
 * @param string $errorSummary Short error description, max 200 chars.
 */
function cms_ai_log(
    string $endpoint,
    string $type,
    bool   $success,
    string $model,
    int    $httpStatus,
    int    $latencyMs,
    int    $promptLen,
    string $errorSummary = ''
): bool {
    // Resolve project root from this file's known location:
    // __DIR__  = <root>/cms-admin/includes  (2 levels below project root)
    $projectRoot = CMS_PROJECT_ROOT;
    $logDir      = $projectRoot . '/logs';
    $logPath     = $logDir . '/ai.log';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $fields = [
        date('Y-m-d H:i:s'),
        $endpoint,
        $type,
        $success ? 'success' : 'failure',
        'model='       . $model,
        'http='        . $httpStatus,
        'latency='     . $latencyMs . 'ms',
        'prompt_len='  . $promptLen,
    ];

    if ($errorSummary !== '') {
        $fields[] = 'error=' . substr($errorSummary, 0, 200);
    }

    $written = file_put_contents(
        $logPath,
        implode(' | ', $fields) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    if ($written === false) {
        error_log('[cms_ai_log] Failed writing AI log: ' . $logPath);
        return false;
    }

    return true;
}

/**
 * ── AI Management: credential encryption ─────────────────────────────────
 *
 * API keys are stored encrypted (AES-256-CBC) rather than in plaintext.
 * This is at-rest obfuscation against casual DB dumps/backups, not a
 * substitute for proper secrets management — anyone with PHP code
 * execution on the server can still decrypt. Derives its key from
 * CMS_AI_ENC_SECRET, which MUST be defined in config/app.php (gitignored,
 * never committed) — there is deliberately no built-in default. A
 * silent fallback here would mean every deployment that forgets to set
 * this ships with the same, publicly-known key derivation.
 */
function cms_ai_enc_key(): string
{
    if (!defined('CMS_AI_ENC_SECRET')) {
        throw new RuntimeException('CMS_AI_ENC_SECRET is not defined — set it in cms-admin/config/app.php before storing or reading any encrypted credential.');
    }
    return hash('sha256', 'thecms-ai-credentials|' . CMS_AI_ENC_SECRET, true);
}

function cms_ai_encrypt(string $plaintext): string
{
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', cms_ai_enc_key(), OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('Failed to encrypt AI credential.');
    }
    return base64_encode($iv . $cipher);
}

function cms_ai_decrypt(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) <= 16) {
        return '';
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'aes-256-cbc', cms_ai_enc_key(), OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : $plain;
}

/**
 * ── AI Management: provider call helpers ─────────────────────────────────
 *
 * Minimal cURL wrappers for Anthropic Messages API and OpenAI Chat
 * Completions API. Returns a normalized shape:
 *   ['success' => bool, 'text' => string, 'http_status' => int,
 *    'latency_ms' => int, 'error' => string, 'raw' => array|null]
 */
function cms_ai_call_anthropic(
    string $apiKey,
    string $model,
    string $userPrompt,
    string $systemPrompt = '',
    int $maxTokens = 512,
    float $temperature = 0.7
): array {
    $start = (int) round(microtime(true) * 1000);

    $body = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
        'messages' => [
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];
    if ($systemPrompt !== '') {
        $body['system'] = $systemPrompt;
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $latency = (int) round(microtime(true) * 1000) - $start;

    if ($response === false) {
        return ['success' => false, 'text' => '', 'http_status' => 0, 'latency_ms' => $latency, 'error' => $curlError ?: 'cURL request failed.', 'raw' => null];
    }

    $decoded = json_decode($response, true);
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $errMsg = is_array($decoded) ? ($decoded['error']['message'] ?? $response) : $response;
        return ['success' => false, 'text' => '', 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => (string) $errMsg, 'raw' => is_array($decoded) ? $decoded : null];
    }

    $text = '';
    if (is_array($decoded) && !empty($decoded['content']) && is_array($decoded['content'])) {
        foreach ($decoded['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
    }

    return ['success' => true, 'text' => $text, 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => '', 'raw' => is_array($decoded) ? $decoded : null];
}

function cms_ai_call_openai(
    string $apiKey,
    string $model,
    string $userPrompt,
    string $systemPrompt = '',
    int $maxTokens = 512,
    float $temperature = 0.7
): array {
    $start = (int) round(microtime(true) * 1000);

    $messages = [];
    if ($systemPrompt !== '') {
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    $messages[] = ['role' => 'user', 'content' => $userPrompt];

    $body = [
        'model' => $model,
        'messages' => $messages,
        'max_tokens' => $maxTokens,
        'temperature' => $temperature,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $latency = (int) round(microtime(true) * 1000) - $start;

    if ($response === false) {
        return ['success' => false, 'text' => '', 'http_status' => 0, 'latency_ms' => $latency, 'error' => $curlError ?: 'cURL request failed.', 'raw' => null];
    }

    $decoded = json_decode($response, true);
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $errMsg = is_array($decoded) ? ($decoded['error']['message'] ?? $response) : $response;
        return ['success' => false, 'text' => '', 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => (string) $errMsg, 'raw' => is_array($decoded) ? $decoded : null];
    }

    $text = (string) ($decoded['choices'][0]['message']['content'] ?? '');

    return ['success' => true, 'text' => $text, 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => '', 'raw' => is_array($decoded) ? $decoded : null];
}

/**
 * OpenAI Images API (GROWTH_AGENT_V2_PROPOSAL.md § 6, Fase F, 8 Aug 2026) —
 * the first image-generation call anywhere in this codebase. Separate
 * endpoint from cms_ai_call_openai() (chat/completions) — images/generations
 * has a different request/response shape entirely, so this is a dedicated
 * function rather than a branch inside the text one.
 *
 * gpt-image-1 (and the -mini variant) only ever returns base64-encoded
 * image bytes (b64_json) — there is no url response_format option for this
 * model family, unlike the older DALL-E API. Caller is responsible for
 * decoding and persisting the bytes (see
 * cms_growth_agent_save_generated_image() in growth-agent-service.php) —
 * this function only talks to the API.
 *
 * @return array{success:bool,b64_data:string,http_status:int,latency_ms:int,error:string}
 */
function cms_ai_call_openai_image(
    string $apiKey,
    string $model,
    string $prompt,
    string $quality = 'medium',
    string $size = '1536x1024'
): array {
    $start = (int) round(microtime(true) * 1000);

    $body = [
        'model' => $model,
        'prompt' => $prompt,
        'quality' => $quality,
        'size' => $size,
        'n' => 1,
    ];

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        // Image generation is far slower than a chat completion — same
        // "this endpoint genuinely needs longer" reasoning as PageSpeed
        // Insights elsewhere in this codebase (gsc-api.php).
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $latency = (int) round(microtime(true) * 1000) - $start;

    if ($response === false) {
        return ['success' => false, 'b64_data' => '', 'http_status' => 0, 'latency_ms' => $latency, 'error' => $curlError ?: 'cURL request failed.'];
    }

    $decoded = json_decode($response, true);
    if ($httpStatus < 200 || $httpStatus >= 300) {
        $errMsg = is_array($decoded) ? ($decoded['error']['message'] ?? $response) : $response;
        return ['success' => false, 'b64_data' => '', 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => (string) $errMsg];
    }

    $b64 = (string) ($decoded['data'][0]['b64_json'] ?? '');
    if ($b64 === '') {
        return ['success' => false, 'b64_data' => '', 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => 'Response did not include image data.'];
    }

    return ['success' => true, 'b64_data' => $b64, 'http_status' => $httpStatus, 'latency_ms' => $latency, 'error' => ''];
}

/**
 * Dispatch to the right provider call based on a provider string.
 */
function cms_ai_call_provider(
    string $provider,
    string $apiKey,
    string $model,
    string $userPrompt,
    string $systemPrompt = '',
    int $maxTokens = 512,
    float $temperature = 0.7
): array {
    return $provider === 'openai'
        ? cms_ai_call_openai($apiKey, $model, $userPrompt, $systemPrompt, $maxTokens, $temperature)
        : cms_ai_call_anthropic($apiKey, $model, $userPrompt, $systemPrompt, $maxTokens, $temperature);
}

/**
 * ── AI content-generation endpoints: agent resolution ────────────────────
 *
 * Resolves everything an api/*-generate.php endpoint needs to make a call:
 * the configured model + provider for an `ai_agent_settings.agent_key` row,
 * an active decrypted API key for that provider, and a system prompt
 * (agent's own `system_prompt` column, or — if left blank — the optional
 * Prompt Control merged layers, or finally the caller's own hardcoded
 * default so generation never hard-fails just because nothing has been
 * configured in the admin UI yet).
 *
 * @return array{
 *   ok: bool, error: string, provider: string, api_key: string,
 *   model: string, temperature: float, max_tokens: int, system_prompt: string
 * }
 */
function cms_ai_resolve_agent(PDO $pdo, string $agentKey, string $fallbackSystemPrompt = ''): array
{
    $fail = static fn (string $msg): array => [
        'ok' => false, 'error' => $msg, 'provider' => '', 'api_key' => '',
        'model' => '', 'temperature' => 0.7, 'max_tokens' => 512, 'system_prompt' => '',
    ];

    $agentStmt = $pdo->prepare(
        'SELECT model_id, temperature, max_tokens, system_prompt
         FROM ai_agent_settings WHERE agent_key = :agent_key AND is_active = 1 LIMIT 1'
    );
    $agentStmt->execute(['agent_key' => $agentKey]);
    $agent = $agentStmt->fetch();

    if (!$agent || empty($agent['model_id'])) {
        return $fail('AI agent "' . $agentKey . '" belum dikonfigurasi. Buka AI Agent Settings di admin panel dan pilih model untuk agent ini.');
    }

    $modelStmt = $pdo->prepare(
        'SELECT provider, model_key FROM ai_models WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $modelStmt->execute(['id' => (int) $agent['model_id']]);
    $model = $modelStmt->fetch();

    if (!$model) {
        return $fail('Model AI untuk agent ini tidak aktif atau sudah dihapus. Cek di AI Agent Settings.');
    }

    $credStmt = $pdo->prepare(
        'SELECT api_key_enc FROM ai_credentials WHERE provider = :provider AND is_active = 1 ORDER BY id ASC LIMIT 1'
    );
    $credStmt->execute(['provider' => $model['provider']]);
    $cred = $credStmt->fetch();

    if (!$cred) {
        return $fail('Belum ada API key aktif untuk provider ' . $model['provider'] . '. Tambahkan di AI Credentials.');
    }

    $apiKey = cms_ai_decrypt((string) $cred['api_key_enc']);
    if ($apiKey === '') {
        return $fail('Gagal membaca API key untuk provider ' . $model['provider'] . '.');
    }

    $systemPrompt = trim((string) ($agent['system_prompt'] ?? ''));

    // Optional Prompt Control layering (services/PromptLoader.php). Skipped
    // silently — never a hard error — if the service class isn't reachable,
    // so content generation always still works off the agent's own
    // system_prompt column or the caller's hardcoded default.
    if ($systemPrompt === '') {
        $promptLoaderPath = dirname(__DIR__, 2) . '/services/PromptLoader.php';
        if (is_file($promptLoaderPath)) {
            try {
                require_once $promptLoaderPath;
                if (class_exists('PromptLoader')) {
                    $loader = new PromptLoader($pdo);
                    $merged = trim($loader->buildMergedPrompt($agentKey));
                    if ($merged !== '') {
                        $systemPrompt = $merged;
                    }
                }
            } catch (Throwable $e) {
                // Ignore — fall through to the caller's own default prompt.
            }
        }
    }

    if ($systemPrompt === '') {
        $systemPrompt = $fallbackSystemPrompt;
    }

    return [
        'ok' => true,
        'error' => '',
        'provider' => (string) $model['provider'],
        'api_key' => $apiKey,
        'model' => (string) $model['model_key'],
        'temperature' => (float) $agent['temperature'],
        'max_tokens' => (int) $agent['max_tokens'],
        'system_prompt' => $systemPrompt,
    ];
}

/**
 * Best-effort JSON parsing of an AI text response. Handles the common
 * cases where a model wraps its answer in a ```json ... ``` fence, or adds
 * a short sentence of prose before/after the JSON object despite being
 * told not to. Returns null if nothing decodable is found.
 *
 * @return array<string, mixed>|null
 */
function cms_ai_extract_json(string $text): ?array
{
    $text = trim($text);
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text) ?? $text;
        $text = preg_replace('/```\s*$/', '', trim($text)) ?? $text;
        $text = trim($text);
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // Fallback: slice out the outermost {...} or [...] and try again, in
    // case the model added stray prose around the JSON.
    $start = null;
    foreach (['{', '['] as $opener) {
        $pos = strpos($text, $opener);
        if ($pos !== false && ($start === null || $pos < $start)) {
            $start = $pos;
        }
    }
    if ($start === null) {
        return null;
    }
    $closer = $text[$start] === '{' ? '}' : ']';
    $end = strrpos($text, $closer);
    if ($end === false || $end <= $start) {
        return null;
    }

    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

    return is_array($decoded) ? $decoded : null;
}
