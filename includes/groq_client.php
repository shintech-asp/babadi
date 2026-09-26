<?php
// includes/groq_client.php — thin, failure-tolerant transport for Groq's
// OpenAI-compatible chat completions API. Never throws: every failure mode
// (no key, timeout, HTTP error, malformed body) returns null so callers in
// includes/dss_helper.php can fall back to the rule-based parser / template
// explanations without the seeker ever seeing a 500.

/**
 * @param array $messages OpenAI-style [{role, content}, ...]
 * @param array $opts json (bool: request JSON-mode response), max_tokens, temperature
 * @return array|null Decoded assistant message content (already json_decode'd when $opts['json'] is true), or null on any failure.
 */
function groqChat(array $messages, array $opts = []) {
    if (!defined('GROQ_ENABLED') || !GROQ_ENABLED || GROQ_API_KEY === '') {
        return null;
    }

    $payload = [
        'model'       => GROQ_MODEL,
        'messages'    => $messages,
        'temperature' => $opts['temperature'] ?? 0.2,
        'max_tokens'  => $opts['max_tokens'] ?? 400,
    ];
    if (!empty($opts['json'])) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $attempt = function () use ($payload) {
        $ch = curl_init(GROQ_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GROQ_API_KEY,
            ],
            CURLOPT_TIMEOUT        => GROQ_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => GROQ_CONNECT_TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        return [$httpCode, $body, $curlErrno];
    };

    [$httpCode, $body, $curlErrno] = $attempt();

    // One retry, only for rate-limit/server errors — never for a timeout,
    // the user has already waited long enough.
    if ($curlErrno === 0 && in_array($httpCode, [429, 500, 502, 503], true)) {
        usleep(400000);
        [$httpCode, $body, $curlErrno] = $attempt();
    }

    if ($curlErrno !== 0) {
        error_log("[groq] cURL error ($curlErrno) calling Groq API");
        return null;
    }
    if ($httpCode !== 200) {
        error_log("[groq] HTTP $httpCode from Groq API: " . substr((string)$body, 0, 300));
        return null;
    }

    $decoded = json_decode((string)$body, true);
    $content = $decoded['choices'][0]['message']['content'] ?? null;
    $finishReason = $decoded['choices'][0]['finish_reason'] ?? null;
    if ($content === null || $finishReason !== 'stop') {
        error_log('[groq] Unexpected response shape or finish_reason=' . ($finishReason ?? 'null'));
        return null;
    }

    if (!empty($opts['json'])) {
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            error_log('[groq] Response was not valid JSON despite json mode: ' . substr($content, 0, 300));
            return null;
        }
        return $parsed;
    }

    return ['text' => $content];
}
