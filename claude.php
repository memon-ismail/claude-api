<?php

define('ORG_ID',  'YOUR_ORG_ID_HERE');
define('COOKIE',  'YOUR_COOKIE_HERE');

define('MODEL',   'claude-sonnet-4-6');
define('BASE_URL', 'https://claude.ai');

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit();
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('#^/claude\.php#', '', $path);
$path = rtrim($path, '/');

if ($path === '/v1/models' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    routeModels();
} elseif ($path === '/v1/chat/completions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    routeChatCompletions();
} else {
    jsonError(404, 'not_found', 'Route not found');
}


// ─────────────────────────────────────────────────────────────────────────────

function routeModels(): void
{
    echo json_encode([
        "object" => "list",
        "data" => [
            makeModelObject("claude-sonnet-4-6"),
            makeModelObject("claude-opus-4-6"),
            makeModelObject("claude-haiku-4-5"),
        ],
    ]);
}

function makeModelObject(string $id): array
{
    return [
        "id" => $id,
        "object" => "model",
        "created" => 1700000000,
        "owned_by" => "anthropic",
    ];
}
function claudeCollect(string $convId, string $prompt): string
{
    $url     = BASE_URL . "/api/organizations/" . ORG_ID .
               "/chat_conversations/" . $convId . "/completion";
    $payload = buildClaudePayload($prompt);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array_merge(claudeHeaders(), [
            "accept: text/event-stream",
            "content-type: application/json",
            "referer: " . BASE_URL . "/chat/" . $convId,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);

    $rawBody = curl_exec($ch);
    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400) {
        jsonError(502, "upstream_error", "Completion request failed: HTTP $code");
    }

    $collected = "";
    foreach (explode("\n", $rawBody) as $line) {
        $line = rtrim($line, "\r");
        if (!strpos($line, "data: ") === 0) {
            continue;  // ← this silently skips "event: ..." lines
        }
        $dataLine = substr($line, 6);
        if ($dataLine === '' || $dataLine === '[DONE]') {
            continue;
        }
        $evt = json_decode($dataLine, true);
        if (!is_array($evt)) {
            continue;
        }
        if (
            ($evt["type"] ?? "") === "content_block_delta" &&
            ($evt["delta"]["type"] ?? "") === "text_delta"
        ) {
            $collected .= $evt["delta"]["text"] ?? "";
        }
    }

    return $collected;
}

function routeChatCompletions(): void
{
    $body = json_decode(file_get_contents("php://input"), true);
    if (!$body || !isset($body["messages"])) {
        jsonError(400, "invalid_request_error", "Missing messages field");
    }

    $stream  = !empty($body["stream"]);
    $prompt  = buildPrompt($body["messages"]);
    $convId  = claudeCreateConversation();
    $model   = $body["model"] ?? MODEL;

    if ($stream) {
        // Set SSE headers here, not inside the helper
        header("Content-Type: text/event-stream");
        header("Cache-Control: no-cache");
        header("X-Accel-Buffering: no");
        ob_implicit_flush(true);
        @ob_end_flush();
        claudeStream($convId, $prompt, $model);   // streams + exits
    } else {
        $text = claudeCollect($convId, $prompt);  // returns plain string
        header("Content-Type: application/json");
        echo json_encode(buildCompletionResponse($text, $model));
    }
}

// ─────────────────────────────────────────────────────────────────────────────

function buildPrompt(array $messages): string
{
    $system = "";
    $turns = [];

    foreach ($messages as $m) {
        $role = $m["role"] ?? "user";
        $content = $m["content"] ?? "";

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (($part["type"] ?? "") === "text") {
                    $parts[] = $part["text"];
                }
            }
            $content = implode("\n", $parts);
        }

        if ($role === "system") {
            $system = $content;
        } else {
            $turns[] = ["role" => $role, "content" => $content];
        }
    }

    if (empty($turns)) {
        return $system;
    }

    if (count($turns) === 1 && $turns[0]["role"] === "user" && $system === "") {
        return $turns[0]["content"];
    }

    $prompt = "";
    if ($system !== "") {
        $prompt .= "[System: {$system}]\n\n";
    }

    foreach ($turns as $t) {
        $label = $t["role"] === "assistant" ? "Assistant" : "Human";
        $prompt .= "{$label}: {$t["content"]}\n\n";
    }

    return rtrim($prompt);
}

// ─────────────────────────────────────────────────────────────────────────────

function claudeHeaders(): array
{
    static $deviceId = null;
    if ($deviceId === null) {
        $deviceId = sprintf(
            "%04x%04x-%04x-%04x-%04x-%04x%04x%04x",
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    return [
        "authority: claude.ai",
        "accept-language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "anthropic-client-platform: web_claude_ai",
        "anthropic-client-version: 1.0.0",
        "origin: " . BASE_URL,
        "sec-fetch-dest: empty",
        "sec-fetch-mode: cors",
        "sec-fetch-site: same-origin",
        "user-agent: Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 Chrome/137.0.0.0 Mobile",
        "cookie: " . COOKIE,
        "anthropic-device-id: " . $deviceId,
    ];
}

function claudeCreateConversation(): string
{
    $url = BASE_URL . "/api/organizations/" . ORG_ID . "/chat_conversations";
    $payload = json_encode(["name" => "", "model" => MODEL]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array_merge(claudeHeaders(), [
            "accept: application/json",
            "content-type: application/json",
        ]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        jsonError(
            502,
            "upstream_error",
            "Failed to create conversation: HTTP " . $code
        );
    }

    $data = json_decode($res, true);
    if (empty($data["uuid"])) {
        jsonError(502, "upstream_error", "No conversation UUID returned");
    }

    return $data["uuid"];
}

function claudeUploadFile(string $convId, string $filePath): string
{
    if (!file_exists($filePath)) {
        jsonError(400, "invalid_request_error", "File not found: " . $filePath);
    }

    $mime = "application/octet-stream";
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $map = [
        "jpg" => "image/jpeg",
        "jpeg" => "image/jpeg",
        "png" => "image/png",
        "webp" => "image/webp",
        "pdf" => "application/pdf",
        "txt" => "text/plain",
    ];
    if (isset($map[$ext])) {
        $mime = $map[$ext];
    }

    $url =
        BASE_URL .
        "/api/organizations/" .
        ORG_ID .
        "/conversations/" .
        $convId .
        "/wiggle/upload-file";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            "file" => new CURLFile($filePath, $mime, basename($filePath)),
        ],
        CURLOPT_HTTPHEADER => array_merge(claudeHeaders(), ["accept: */*"]),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        jsonError(502, "upstream_error", "File upload failed: HTTP " . $code);
    }

    $data = json_decode($res, true);
    $id = $data["file_uuid"] ?? ($data["uuid"] ?? ($data["id"] ?? null));
    if (!$id) {
        jsonError(502, "upstream_error", "No file ID returned from upload");
    }

    return $id;
}

// ── Shared payload builder ────────────────────────────────────────────────────
function buildClaudePayload(string $prompt): string
{
    return json_encode([
        "prompt"               => $prompt,
        "timezone"             => "Asia/Jakarta",
        "locale"               => "id-ID",
        "model"                => MODEL,
        "personalized_styles"  => [[
            "type"       => "default",
            "key"        => "Default",
            "name"       => "Normal",
            "nameKey"    => "normal_style_name",
            "prompt"     => "Normal\n",
            "summary"    => "Default responses from Claude",
            "summaryKey" => "normal_style_summary",
            "isDefault"  => true,
        ]],
        "tools"                => [
            ["type" => "web_search_v0", "name" => "web_search"],
            ["type" => "artifacts_v0",  "name" => "artifacts"],
            ["type" => "repl_v0",       "name" => "repl"],
        ],
        "turn_message_uuids"   => [
            "human_message_uuid"     => randomUUID(),
            "assistant_message_uuid" => randomUUID(),
        ],
        "attachments"          => [],
        "files"                => [],
        "sync_sources"         => [],
        "rendering_mode"       => "messages",
    ]);
}
// ── STREAMING: reads upstream SSE, re-emits as OpenAI chunks, then exits ──────
function claudeStream(string $convId, string $prompt, string $model): void
{
    $url           = BASE_URL . "/api/organizations/" . ORG_ID .
                     "/chat_conversations/" . $convId . "/completion";
    $payload       = buildClaudePayload($prompt);
    $completionId  = "chatcmpl-" . randomUUID();
    $created       = time();

    // Send the OpenAI "role" chunk first
    echo "data: " . json_encode([
        "id"      => $completionId,
        "object"  => "chat.completion.chunk",
        "created" => $created,
        "model"   => $model,
        "choices" => [[
            "index"         => 0,
            "delta"         => ["role" => "assistant", "content" => ""],
            "logprobs"      => null,
            "finish_reason" => null,
        ]],
    ]) . "\n\n";
    flush();

    $buffer = "";

    $sseHandler = function (string $data) use (
        &$buffer, $completionId, $created, $model
    ): int {
        $buffer .= $data;

        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $event  = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $dataLine = null;
            foreach (explode("\n", $event) as $line) {
                $line = rtrim($line, "\r");
                if (strpos($line, "data: ") === 0) {
                    $dataLine = substr($line, 6);
                }
            }

            if ($dataLine === null || $dataLine === '' || $dataLine === '[DONE]') {
                continue;
            }

            $evt = json_decode($dataLine, true);
            if (!is_array($evt)) {
                continue;
            }

            if (
                ($evt["type"] ?? "") === "content_block_delta" &&
                ($evt["delta"]["type"] ?? "") === "text_delta"
            ) {
                $text = $evt["delta"]["text"] ?? "";
                echo "data: " . json_encode([
                    "id"      => $completionId,
                    "object"  => "chat.completion.chunk",
                    "created" => $created,
                    "model"   => $model,
                    "choices" => [[
                        "index"         => 0,
                        "delta"         => ["content" => $text],
                        "logprobs"      => null,
                        "finish_reason" => null,
                    ]],
                ]) . "\n\n";
                flush();
            }
        }

        return strlen($data);
    };

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => array_merge(claudeHeaders(), [
            "accept: text/event-stream",
            "content-type: application/json",
            "referer: " . BASE_URL . "/chat/" . $convId,
        ]),
        CURLOPT_WRITEFUNCTION  => $sseHandler,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code >= 400) {
        // Can't send a proper JSON error after streaming started; send a stop chunk
        echo "data: " . json_encode([
            "id"      => $completionId,
            "object"  => "chat.completion.chunk",
            "created" => $created,
            "model"   => $model,
            "choices" => [[
                "index"         => 0,
                "delta"         => ["content" => "\n\n[upstream error HTTP $code]"],
                "logprobs"      => null,
                "finish_reason" => "stop",
            ]],
        ]) . "\n\n";
        echo "data: [DONE]\n\n";
        flush();
        exit();
    }

    // Final stop chunk
    echo "data: " . json_encode([
        "id"      => $completionId,
        "object"  => "chat.completion.chunk",
        "created" => $created,
        "model"   => $model,
        "choices" => [[
            "index"         => 0,
            "delta"         => [],
            "logprobs"      => null,
            "finish_reason" => "stop",
        ]],
    ]) . "\n\n";
    echo "data: [DONE]\n\n";
    flush();
    exit();
}
// ─────────────────────────────────────────────────────────────────────────────

function buildCompletionResponse(string $text, string $model): array
{
    $promptTokens = 0;
    $completionTokens = (int) ceil(strlen($text) / 4);

    return [
        "id" => "chatcmpl-" . randomUUID(),
        "object" => "chat.completion",
        "created" => time(),
        "model" => $model,
        "choices" => [
            [
                "index" => 0,
                "message" => ["role" => "assistant", "content" => $text],
                "finish_reason" => "stop",
            ],
        ],
        "usage" => [
            "prompt_tokens" => $promptTokens,
            "completion_tokens" => $completionTokens,
            "total_tokens" => $promptTokens + $completionTokens,
        ],
    ];
}

function randomUUID(): string
{
    return sprintf(
        "%04x%04x-%04x-%04x-%04x-%04x%04x%04x",
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0xffff)
    );
}

function jsonError(int $status, string $type, string $message): void
{
    http_response_code($status);
    echo json_encode(["error" => ["type" => $type, "message" => $message]]);
    exit();
}