<?php
/**
 * StoryWeaver — AI provider abstraction (§3.1).
 *
 * Normalizes text generation requests across OpenAI, Anthropic,
 * Ollama, and custom OpenAI-compatible endpoints. Uses curl for
 * HTTP with proper timeout and error handling.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_keys.php';

class AIProvider
{
    /** @var array The API key record from api_keys.json. */
    private array $key;

    /** @var int Request timeout in seconds. */
    private int $timeout = 60;

    /**
     * Create an AIProvider for a specific key configuration.
     *
     * @param array $key A key record from api_keys.json.
     */
    public function __construct(array $key)
    {
        $this->key = $key;
    }

    /**
     * Generate text via the AI provider's chat completion endpoint.
     *
     * @param string $system_prompt The system prompt.
     * @param string $user_message  The user message.
     * @return string Raw text content from the AI response.
     * @throws RuntimeException On HTTP or parsing errors.
     */
    public function generateText(string $system_prompt, string $user_message): string
    {
        $provider = $this->key['provider'] ?? 'openai';

        return match ($provider) {
            'anthropic' => $this->callAnthropic($system_prompt, $user_message),
            default     => $this->callOpenAICompatible($system_prompt, $user_message),
        };
    }

    /**
     * Generate text with streaming — calls $onChunk for each token as it arrives.
     *
     * @param string   $system_prompt The system prompt.
     * @param string   $user_message  The user message.
     * @param callable $onChunk       Callback: function(string $text_chunk): void
     * @return string The complete accumulated text.
     * @throws RuntimeException On HTTP or parsing errors.
     */
    public function generateTextStream(string $system_prompt, string $user_message, callable $onChunk): string
    {
        $provider = $this->key['provider'] ?? 'openai';

        return match ($provider) {
            'anthropic' => $this->streamAnthropic($system_prompt, $user_message, $onChunk),
            default     => $this->streamOpenAICompatible($system_prompt, $user_message, $onChunk),
        };
    }

    /**
     * Test the connection with a minimal prompt.
     *
     * @return array ['ok' => bool, 'message' => string, 'preview' => string]
     */
    public function testConnection(): array
    {
        try {
            $response = $this->generateText(
                'You are a test assistant. Respond with exactly: {"test": "ok"}',
                'Say hello.'
            );
            return [
                'ok'      => true,
                'message' => 'Connection successful.',
                'preview' => mb_substr($response, 0, 200),
            ];
        } catch (RuntimeException $e) {
            return [
                'ok'      => false,
                'message' => $e->getMessage(),
                'preview' => '',
            ];
        }
    }

    /* ------------------------------------------------------------------
     * Provider-specific request methods
     * ----------------------------------------------------------------*/

    /**
     * Call an OpenAI-compatible endpoint (OpenAI, Ollama, custom).
     *
     * @param string $system System prompt.
     * @param string $user   User message.
     * @return string The assistant's reply text.
     * @throws RuntimeException On failure.
     */
    private function callOpenAICompatible(string $system, string $user): string
    {
        $base_url = rtrim($this->key['base_url'] ?? '', '/');
        $url = $base_url . '/chat/completions';

        $body = [
            'model'    => $this->key['model_text'] ?? 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.8,
        ];

        $headers = [
            'Content-Type: application/json',
        ];

        $api_key = $this->key['api_key'] ?? '';
        if ($api_key !== '') {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }

        $raw = $this->httpPost($url, $body, $headers);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from provider.');
        }

        if (isset($data['error'])) {
            $msg = $data['error']['message'] ?? $data['error'] ?? 'Unknown error';
            if (is_array($msg)) $msg = json_encode($msg);
            throw new RuntimeException('Provider error: ' . $msg);
        }

        $text = $data['choices'][0]['message']['content'] ?? null;
        if ($text === null) {
            throw new RuntimeException('No content in provider response.');
        }

        return trim($text);
    }

    /**
     * Call the Anthropic Messages API.
     *
     * @param string $system System prompt.
     * @param string $user   User message.
     * @return string The assistant's reply text.
     * @throws RuntimeException On failure.
     */
    private function callAnthropic(string $system, string $user): string
    {
        $base_url = rtrim($this->key['base_url'] ?? '', '/');
        $url = $base_url . '/v1/messages';

        $body = [
            'model'      => $this->key['model_text'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => 2048,
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . ($this->key['api_key'] ?? ''),
            'anthropic-version: 2023-06-01',
        ];

        $raw = $this->httpPost($url, $body, $headers);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from Anthropic.');
        }

        if (isset($data['error'])) {
            $msg = $data['error']['message'] ?? 'Unknown Anthropic error';
            throw new RuntimeException('Anthropic error: ' . $msg);
        }

        // Anthropic returns content as array of blocks
        $blocks = $data['content'] ?? [];
        $text = '';
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        if ($text === '') {
            throw new RuntimeException('No text content in Anthropic response.');
        }

        return trim($text);
    }

    /* ------------------------------------------------------------------
     * Streaming provider methods
     * ----------------------------------------------------------------*/

    /**
     * Stream from an OpenAI-compatible endpoint.
     *
     * @param string   $system  System prompt.
     * @param string   $user    User message.
     * @param callable $onChunk Token callback.
     * @return string Complete accumulated text.
     * @throws RuntimeException On failure.
     */
    private function streamOpenAICompatible(string $system, string $user, callable $onChunk): string
    {
        $base_url = rtrim($this->key['base_url'] ?? '', '/');
        $url = $base_url . '/chat/completions';

        $body = [
            'model'       => $this->key['model_text'] ?? 'gpt-4o',
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => 0.8,
            'stream'      => true,
        ];

        $headers = ['Content-Type: application/json'];
        $api_key = $this->key['api_key'] ?? '';
        if ($api_key !== '') {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }

        $accumulated = '';
        $this->httpPostStreaming($url, $body, $headers, function (string $line) use (&$accumulated, $onChunk) {
            // OpenAI SSE format: "data: {json}\n"
            if (!str_starts_with($line, 'data: ')) return;
            $json_str = substr($line, 6);
            if (trim($json_str) === '[DONE]') return;

            $data = json_decode($json_str, true);
            $delta = $data['choices'][0]['delta']['content'] ?? '';
            if ($delta !== '') {
                $accumulated .= $delta;
                $onChunk($delta);
            }
        });

        if ($accumulated === '') {
            throw new RuntimeException('No content received from streaming response.');
        }
        return $accumulated;
    }

    /**
     * Stream from the Anthropic Messages API.
     *
     * @param string   $system  System prompt.
     * @param string   $user    User message.
     * @param callable $onChunk Token callback.
     * @return string Complete accumulated text.
     * @throws RuntimeException On failure.
     */
    private function streamAnthropic(string $system, string $user, callable $onChunk): string
    {
        $base_url = rtrim($this->key['base_url'] ?? '', '/');
        $url = $base_url . '/v1/messages';

        $body = [
            'model'      => $this->key['model_text'] ?? 'claude-sonnet-4-20250514',
            'max_tokens' => 2048,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
            'stream'     => true,
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . ($this->key['api_key'] ?? ''),
            'anthropic-version: 2023-06-01',
        ];

        $accumulated = '';
        $this->httpPostStreaming($url, $body, $headers, function (string $line) use (&$accumulated, $onChunk) {
            // Anthropic SSE: "event: content_block_delta\ndata: {json}"
            if (!str_starts_with($line, 'data: ')) return;
            $data = json_decode(substr($line, 6), true);
            if (!is_array($data)) return;

            $delta = $data['delta']['text'] ?? '';
            if ($delta !== '') {
                $accumulated .= $delta;
                $onChunk($delta);
            }
        });

        if ($accumulated === '') {
            throw new RuntimeException('No content received from Anthropic streaming response.');
        }
        return $accumulated;
    }

    /* ------------------------------------------------------------------
     * HTTP transport
     * ----------------------------------------------------------------*/

    /**
     * POST JSON to a URL and return the raw response body.
     *
     * Uses curl if available, falls back to file_get_contents with stream context.
     *
     * @param string $url     Endpoint URL.
     * @param array  $body    Request body (will be JSON-encoded).
     * @param array  $headers HTTP headers.
     * @return string Raw response body.
     * @throws RuntimeException On HTTP errors.
     */
    private function httpPost(string $url, array $body, array $headers): string
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        if (function_exists('curl_init')) {
            return $this->httpPostCurl($url, $json, $headers);
        }
        return $this->httpPostStream($url, $json, $headers);
    }

    /**
     * HTTP POST via curl.
     *
     * @param string $url     Endpoint URL.
     * @param string $json    JSON-encoded request body.
     * @param array  $headers HTTP headers.
     * @return string Raw response body.
     * @throws RuntimeException On failure.
     */
    private function httpPostCurl(string $url, string $json, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new RuntimeException('Authentication failed (HTTP ' . $httpCode . '). Check your API key.');
        }

        if ($httpCode >= 400) {
            // Try to extract error message from response
            $data = json_decode($response, true);
            $msg = $data['error']['message'] ?? $data['error'] ?? $response;
            if (is_array($msg)) $msg = json_encode($msg);
            throw new RuntimeException('HTTP ' . $httpCode . ': ' . mb_substr($msg, 0, 300));
        }

        return $response;
    }

    /**
     * HTTP POST via file_get_contents stream context (fallback).
     *
     * @param string $url     Endpoint URL.
     * @param string $json    JSON-encoded request body.
     * @param array  $headers HTTP headers.
     * @return string Raw response body.
     * @throws RuntimeException On failure.
     */
    private function httpPostStream(string $url, string $json, array $headers): string
    {
        $headerStr = implode("\r\n", $headers);

        $opts = [
            'http' => [
                'method'  => 'POST',
                'header'  => $headerStr,
                'content' => $json,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('HTTP request failed (stream). Check URL and network.');
        }

        // Check HTTP status from response headers
        $status = 0;
        $resp_headers = http_get_last_response_headers();
        if (is_array($resp_headers) && isset($resp_headers[0])) {
            preg_match('/\d{3}/', $resp_headers[0], $m);
            $status = (int)($m[0] ?? 0);
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Authentication failed (HTTP ' . $status . '). Check your API key.');
        }

        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status . ': ' . mb_substr($response, 0, 300));
        }

        return $response;
    }

    /**
     * HTTP POST with streaming via curl — reads response line-by-line.
     *
     * @param string   $url      Endpoint URL.
     * @param array    $body     Request body (will be JSON-encoded).
     * @param array    $headers  HTTP headers.
     * @param callable $onLine   Called for each SSE line: function(string $line): void
     * @return void
     * @throws RuntimeException On failure.
     */
    private function httpPostStreaming(string $url, array $body, array $headers, callable $onLine): void
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $buffer = '';
        $httpCode = 0;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$httpCode) {
                if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $m)) {
                    $httpCode = (int)$m[1];
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$buffer, &$httpCode, $onLine) {
                // On error status, accumulate for error message
                if ($httpCode >= 400) {
                    $buffer .= $chunk;
                    return strlen($chunk);
                }

                $buffer .= $chunk;
                // Process complete lines
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = rtrim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '') {
                        $onLine($line);
                    }
                }
                return strlen($chunk);
            },
        ]);

        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            throw new RuntimeException('Streaming request failed: ' . $error);
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new RuntimeException('Authentication failed (HTTP ' . $httpCode . '). Check your API key.');
        }

        if ($httpCode >= 400) {
            throw new RuntimeException('HTTP ' . $httpCode . ': ' . mb_substr($buffer, 0, 300));
        }

        // Process any remaining buffer
        $remaining = trim($buffer);
        if ($remaining !== '') {
            $onLine($remaining);
        }
    }

    /**
     * Generate an image using the provider's image model.
     *
     * Currently supports OpenAI-compatible /images/generations endpoint
     * (DALL-E, etc.). Returns the image binary data or throws on failure.
     *
     * @param string $prompt  The image generation prompt.
     * @param string $size    Image size (e.g., "1024x1024"). Default "1024x1024".
     * @return string         Raw image binary data.
     * @throws RuntimeException if no image model or generation fails.
     */
    public function generateImage(string $prompt, string $size = '1024x1024'): string
    {
        $model = $this->key['model_image'] ?? '';
        if ($model === '') {
            throw new RuntimeException('No image model configured for this key.');
        }

        $provider = $this->key['provider'];
        if ($provider === 'anthropic') {
            throw new RuntimeException('Anthropic does not support image generation.');
        }

        // Image generation can be slow — use extended timeout
        $saved_timeout = $this->timeout;
        $this->timeout = max($this->timeout, 300);

        try {
            if ($provider === 'ollama') {
                return $this->generateImageOllama($model, $prompt);
            }
            return $this->generateImageOpenAI($model, $prompt, $size);
        } finally {
            $this->timeout = $saved_timeout;
        }
    }

    /**
     * Generate image via Ollama's native /api/generate endpoint.
     *
     * Ollama image models (e.g. x/flux2-klein) use the native API,
     * not the OpenAI-compatible /v1/images/generations endpoint.
     * Limited to 400×400 to keep generation fast on local hardware.
     */
    private function generateImageOllama(string $model, string $prompt): string
    {
        // Ollama native API is at the base host, not under /v1
        $base_url = rtrim($this->key['base_url'] ?? '', '/');
        // Strip /v1 suffix if present (base_url is typically stored as .../v1)
        $base_url = preg_replace('#/v1$#', '', $base_url);
        $url = $base_url . '/api/generate';

        $body = [
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
            'width'  => 400,
            'height' => 400,
        ];

        $headers = ['Content-Type: application/json'];

        $raw = $this->httpPost($url, $body, $headers);
        $response = json_decode($raw, true);

        // Ollama returns base64 image data in "image" or "response" field
        $b64 = $response['image'] ?? $response['response'] ?? null;

        if ($b64 === null || $b64 === '') {
            $err = $response['error'] ?? mb_substr($raw, 0, 300);
            throw new RuntimeException('Ollama image generation failed: ' . $err);
        }

        $binary = base64_decode($b64);
        if ($binary === false) {
            throw new RuntimeException('Failed to decode Ollama image data.');
        }

        return $binary;
    }

    /**
     * Generate image via OpenAI /images/generations endpoint.
     *
     * Handles both GPT Image models (gpt-image-1) and DALL-E models.
     */
    private function generateImageOpenAI(string $model, string $prompt, string $size): string
    {
        $base_url = rtrim($this->key['base_url'], '/');
        $url = $base_url . '/images/generations';

        $headers = ['Content-Type: application/json'];
        $api_key = $this->key['api_key'] ?? '';
        if ($api_key !== '') {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }

        // GPT Image models (gpt-image-1, etc.) use different params than DALL-E
        if (str_starts_with($model, 'gpt-image')) {
            $body = [
                'model'         => $model,
                'prompt'        => mb_substr($prompt, 0, 32000),
                'n'             => 1,
                'size'          => $size,
                'quality'       => 'medium',
                'output_format' => 'png',
            ];
        } else {
            $body = [
                'model'           => $model,
                'prompt'          => mb_substr($prompt, 0, 4000),
                'n'               => 1,
                'size'            => $size,
                'response_format' => 'b64_json',
            ];
        }

        $raw = $this->httpPost($url, $body, $headers);
        $response = json_decode($raw, true);

        $b64 = $response['data'][0]['b64_json'] ?? null;
        if ($b64 !== null) {
            $binary = base64_decode($b64);
            if ($binary === false) {
                throw new RuntimeException('Failed to decode image data.');
            }
            return $binary;
        }

        // Some models return a URL instead
        $img_url = $response['data'][0]['url'] ?? null;
        if ($img_url !== null) {
            $binary = @file_get_contents($img_url);
            if ($binary === false) {
                throw new RuntimeException('Failed to download generated image.');
            }
            return $binary;
        }

        $err = $response['error']['message'] ?? mb_substr($raw, 0, 300);
        throw new RuntimeException('Image generation failed: ' . $err);
    }
}
