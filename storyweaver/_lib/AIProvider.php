<?php
/**
 * StoryWeaver — AI provider abstraction (§3.1).
 *
 * Normalizes text generation requests across OpenAI, xAI, Anthropic,
 * Ollama, and custom OpenAI-compatible endpoints. Uses curl for
 * HTTP with proper timeout and error handling.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/api_keys.php';

class AIProvider
{
    /** @var array The API key record from api_keys.json. */
    private array $key;

    /** @var array<string, float|int> */
    private array $generationOptions;

    /** @var int Request timeout in seconds. */
    private int $timeout = 75;

    /** @var callable|null */
    private $abortHandler = null;

    /**
     * Create an AIProvider for a specific key configuration.
     *
     * @param array $key               A key record from api_keys.json.
     * @param array $generationOptions User-configurable generation controls.
     */
    public function __construct(array $key, array $generationOptions = [])
    {
        $this->key = $key;
        $this->generationOptions = $this->normalizeGenerationOptions($generationOptions);
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
        if ($this->shouldAbort()) {
            throw $this->abortException();
        }

        $provider = $this->key['provider'] ?? 'openai';

        return match ($provider) {
            'anthropic' => $this->callAnthropic($system_prompt, $user_message),
            'gemini'    => $this->callGemini($system_prompt, $user_message),
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
        if ($this->shouldAbort()) {
            throw $this->abortException();
        }

        $provider = $this->key['provider'] ?? 'openai';

        return match ($provider) {
            'anthropic' => $this->streamAnthropic($system_prompt, $user_message, $onChunk),
            'gemini'    => $this->streamGemini($system_prompt, $user_message, $onChunk),
            default     => $this->streamOpenAICompatible($system_prompt, $user_message, $onChunk),
        };
    }

    /**
     * Register a callback that returns true when this request should abort.
     */
    public function setAbortHandler(?callable $abortHandler): void
    {
        $this->abortHandler = $abortHandler;
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

    /**
     * List available model IDs for the configured provider.
     *
     * @return array<int, string>
     */
    public function listModels(): array
    {
        $catalog = $this->listModelCatalog();
        return array_values(array_unique(array_merge($catalog['text'], $catalog['image'])));
    }

    /**
     * List discoverable text and image models for the configured provider.
     *
     * @return array{text: array<int, string>, image: array<int, string>}
     */
    public function listModelCatalog(): array
    {
        $provider = $this->key['provider'] ?? 'openai';

        $open_ai_compatible_models = $this->isOpenRouterHost() || $provider === 'xai'
            ? []
            : $this->listOpenAICompatibleModels();

        $catalog = match ($provider) {
            'xai'       => $this->listXAIModelCatalog(),
            'anthropic' => ['text' => $this->listAnthropicModels(), 'image' => []],
            'gemini'    => ['text' => $this->listGeminiModels(), 'image' => $this->listGeminiModels()],
            'ollama'    => ['text' => $this->listOllamaModels(), 'image' => $this->listOllamaModels()],
            default     => $this->isOpenRouterHost()
                ? [
                    'text' => $this->listOpenRouterModelsByOutput(['text']),
                    'image' => $this->listOpenRouterModelsByOutput(['image']),
                ]
                : ['text' => $open_ai_compatible_models, 'image' => $open_ai_compatible_models],
        };

        foreach (['text', 'image'] as $kind) {
            $catalog[$kind] = array_values(array_unique(array_filter(array_map('trim', $catalog[$kind] ?? []), function ($model) {
                return $model !== '';
            })));
            sort($catalog[$kind], SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $catalog;
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

        $body = $this->buildOpenAICompatibleBody($system, $user);
        $headers = $this->openAICompatibleHeaders();

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

        return trim(self::stripThinkingTags($text));
    }

    /**
     * Call the Gemini Generative Language API.
     */
    private function callGemini(string $system, string $user): string
    {
        $base_url = rtrim($this->key['base_url'] ?? '', '/');
        $model_path = $this->geminiModelPath((string) ($this->key['model_text'] ?? 'gemini-2.5-flash'));
        $url = $base_url . '/' . $model_path . ':generateContent?key=' . rawurlencode((string) ($this->key['api_key'] ?? ''));

        $generation_config = [
            'temperature'     => $this->generationFloat('temperature', 0.8),
            'topP'            => $this->generationFloat('top_p', 1.0),
            'maxOutputTokens' => $this->generationInt('max_tokens', 2048),
        ];

        $body = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $system],
                ],
            ],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $user],
                ],
            ]],
            'generationConfig' => $generation_config,
        ];

        try {
            $raw = $this->httpPost($url, $body, ['Content-Type: application/json']);
        } catch (RuntimeException $e) {
            throw new RuntimeException($this->normalizeGeminiTransportError($e->getMessage()), 0, $e);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from Gemini.');
        }

        if (isset($data['error'])) {
            throw new RuntimeException($this->formatGeminiError($data['error']));
        }

        $text = $this->extractGeminiText($data);
        if ($text === '') {
            throw new RuntimeException('No text content in Gemini response.');
        }

        return trim(self::stripThinkingTags($text));
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
            'max_tokens' => $this->generationInt('max_tokens', 2048),
            'system'     => $system,
            'messages'   => [
                ['role' => 'user', 'content' => $user],
            ],
        ];

        $body['temperature'] = $this->generationFloat('temperature', 0.8);
        $body['top_p'] = $this->generationFloat('top_p', 1.0);

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

        $body = $this->buildOpenAICompatibleBody($system, $user, true);
        $headers = $this->openAICompatibleHeaders();

        $accumulated = '';
        // Track state for stripping <think>…</think> blocks from the stream
        $think_state = ['in' => false];
        $this->httpPostStreaming($url, $body, $headers, function (string $line) use (&$accumulated, $onChunk, &$think_state) {
            // OpenAI SSE format: "data: {json}\n"
            if (!str_starts_with($line, 'data: ')) return;
            $json_str = substr($line, 6);
            if (trim($json_str) === '[DONE]') return;

            $data = json_decode($json_str, true);
            $delta = $data['choices'][0]['delta']['content'] ?? '';
            if ($delta === '') return;

            $accumulated .= $delta;

            // Filter out <think>…</think> tokens from the live stream
            $output = '';
            $text = $delta;
            while ($text !== '') {
                if (!$think_state['in']) {
                    $pos = strpos($text, '<think>');
                    if ($pos === false) {
                        $output .= $text;
                        break;
                    }
                    $output .= substr($text, 0, $pos);
                    $think_state['in'] = true;
                    $text = substr($text, $pos + 7);
                } else {
                    $pos = strpos($text, '</think>');
                    if ($pos === false) {
                        break; // still inside thinking block, discard rest of delta
                    }
                    $think_state['in'] = false;
                    $text = substr($text, $pos + 8);
                    // Skip optional newline right after closing tag
                    if ($text !== '' && $text[0] === "\n") {
                        $text = substr($text, 1);
                    }
                }
            }
            if ($output !== '') {
                $onChunk($output);
            }
        });

        if ($accumulated === '') {
            throw new RuntimeException('No content received from streaming response.');
        }
        // Strip any thinking tags that weren't already filtered during streaming
        // (e.g. tags split across chunk boundaries)
        return self::stripThinkingTags($accumulated);
    }

    /**
     * Stream Gemini text by chunking a completed response.
     */
    private function streamGemini(string $system, string $user, callable $onChunk): string
    {
        $text = $this->callGemini($system, $user);
        $words = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if (!is_array($words)) {
            $words = [$text];
        }

        $chunk = '';
        foreach ($words as $part) {
            if ($this->shouldAbort()) {
                throw $this->abortException();
            }
            if ($chunk !== '' && mb_strlen($chunk . $part) > 120) {
                $onChunk($chunk);
                $chunk = '';
            }
            $chunk .= $part;
        }

        if ($chunk !== '') {
            $onChunk($chunk);
        }

        return $text;
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
            'max_tokens' => $this->generationInt('max_tokens', 2048),
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
            'stream'     => true,
        ];

        $body['temperature'] = $this->generationFloat('temperature', 0.8);
        $body['top_p'] = $this->generationFloat('top_p', 1.0);

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
     * HTTP GET helper for model-listing endpoints.
     */
    private function httpGet(string $url, array $headers): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('HTTP request failed: ' . $error);
            }

            if ($httpCode === 401 || $httpCode === 403) {
                throw new RuntimeException('Authentication failed (HTTP ' . $httpCode . '). Check your API key.');
            }

            if ($httpCode >= 400) {
                throw new RuntimeException('HTTP ' . $httpCode . ': ' . mb_substr($response, 0, 300));
            }

            return $response;
        }

        $headerStr = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headerStr,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('HTTP request failed (stream). Check URL and network.');
        }

        $status = 0;
        $resp_headers = http_get_last_response_headers();
        if (is_array($resp_headers) && isset($resp_headers[0])) {
            preg_match('/\d{3}/', $resp_headers[0], $m);
            $status = (int) ($m[0] ?? 0);
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
        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ];

        if (defined('CURLOPT_NOPROGRESS')) {
            $options[CURLOPT_NOPROGRESS] = false;
        }
        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            $options[CURLOPT_XFERINFOFUNCTION] = function (...$args): int {
                return $this->shouldAbort() ? 1 : 0;
            };
        } elseif (defined('CURLOPT_PROGRESSFUNCTION')) {
            $options[CURLOPT_PROGRESSFUNCTION] = function (...$args): int {
                return $this->shouldAbort() ? 1 : 0;
            };
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            if ($this->shouldAbort()) {
                throw $this->abortException();
            }
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        if ($this->shouldAbort()) {
            throw $this->abortException();
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
        if ($this->shouldAbort()) {
            throw $this->abortException();
        }

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
            if ($this->shouldAbort()) {
                throw $this->abortException();
            }
            throw new RuntimeException('HTTP request failed (stream). Check URL and network.');
        }

        if ($this->shouldAbort()) {
            throw $this->abortException();
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
        $options = [
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
                if ($this->shouldAbort()) {
                    return 0;
                }

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
        ];

        if (defined('CURLOPT_NOPROGRESS')) {
            $options[CURLOPT_NOPROGRESS] = false;
        }
        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            $options[CURLOPT_XFERINFOFUNCTION] = function (...$args): int {
                return $this->shouldAbort() ? 1 : 0;
            };
        } elseif (defined('CURLOPT_PROGRESSFUNCTION')) {
            $options[CURLOPT_PROGRESSFUNCTION] = function (...$args): int {
                return $this->shouldAbort() ? 1 : 0;
            };
        }

        curl_setopt_array($ch, $options);

        curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            if ($this->shouldAbort()) {
                throw $this->abortException();
            }
            throw new RuntimeException('Streaming request failed: ' . $error);
        }

        if ($this->shouldAbort()) {
            throw $this->abortException();
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
     * Return true when generation should be cancelled.
     */
    private function shouldAbort(): bool
    {
        if (is_callable($this->abortHandler) && (bool) call_user_func($this->abortHandler)) {
            return true;
        }

        return sw_should_abort();
    }

    /**
     * Build the standard user-abort exception.
     */
    private function abortException(): RuntimeException
    {
        return new RuntimeException('Generation aborted by user.');
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
        if ($provider === 'gemini') {
            throw new RuntimeException('Gemini image generation is not supported by this StoryWeaver integration yet.');
        }

        // Image generation can be slow — use extended timeout
        $saved_timeout = $this->timeout;
        $this->timeout = max($this->timeout, 300);

        try {
            if ($provider === 'ollama') {
                return $this->generateImageOllama($model, $prompt);
            }
            if ($provider === 'xai') {
                return $this->generateImageXAI($model, $prompt);
            }
            if ($this->isOpenRouterHost()) {
                return $this->generateImageOpenRouter($model, $prompt);
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
            return $this->downloadRemoteBinary($img_url);
        }

        $err = $response['error']['message'] ?? mb_substr($raw, 0, 300);
        throw new RuntimeException('Image generation failed: ' . $err);
    }

    /**
     * Generate image via xAI's /images/generations endpoint.
     */
    private function generateImageXAI(string $model, string $prompt): string
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $url = $base_url . '/images/generations';
        $headers = $this->openAICompatibleHeaders();

        $body = [
            'model' => $model,
            'prompt' => mb_substr($prompt, 0, 32000),
            'n' => 1,
            'resolution' => '1k',
            'response_format' => 'b64_json',
        ];

        $raw = $this->httpPost($url, $body, $headers);
        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new RuntimeException('Invalid JSON response from xAI.');
        }

        $b64 = $response['data'][0]['b64_json'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $binary = base64_decode($b64);
            if ($binary === false) {
                throw new RuntimeException('Failed to decode xAI image data.');
            }
            return $binary;
        }

        $img_url = $response['data'][0]['url']
            ?? $response['data'][0]['image_url']
            ?? null;
        if (is_string($img_url) && trim($img_url) !== '') {
            return $this->decodeGeneratedImageReference($img_url);
        }

        $err = $response['error']['message'] ?? $response['error'] ?? mb_substr($raw, 0, 300);
        if (is_array($err)) {
            $err = json_encode($err);
        }
        throw new RuntimeException('xAI image generation failed: ' . $err);
    }

    /**
     * Generate an image via OpenRouter's chat-completions image API.
     */
    private function generateImageOpenRouter(string $model, string $prompt): string
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $url = $base_url . '/chat/completions';
        $headers = $this->openAICompatibleHeaders();
        $modalities = $this->openRouterImageModalities($model);

        $body = [
            'model' => $model,
            'messages' => [[
                'role' => 'user',
                'content' => mb_substr($prompt, 0, 32000),
            ]],
            'modalities' => $modalities,
            'stream' => false,
        ];

        $raw = $this->httpPost($url, $body, $headers);
        $response = json_decode($raw, true);
        if (!is_array($response)) {
            throw new RuntimeException('Invalid JSON response from provider.');
        }

        $image_url = $response['choices'][0]['message']['images'][0]['image_url']['url']
            ?? $response['choices'][0]['message']['images'][0]['imageUrl']['url']
            ?? null;
        if (!is_string($image_url) || trim($image_url) === '') {
            $err = $response['error']['message'] ?? $response['error'] ?? mb_substr($raw, 0, 300);
            if (is_array($err)) {
                $err = json_encode($err);
            }
            throw new RuntimeException('Image generation failed: ' . $err);
        }

        return $this->decodeGeneratedImageReference($image_url);
    }

    /* ------------------------------------------------------------------
     * Utilities
     * ----------------------------------------------------------------*/

    /**
     * Remove <think>…</think> blocks from text (used by DeepSeek-R1 etc.).
     * Also removes an optional trailing newline directly after the closing tag.
     *
     * @param string $text Input text.
     * @return string Text with thinking blocks stripped.
     */
    private static function stripThinkingTags(string $text): string
    {
        return trim(preg_replace('/<think>[\s\S]*?<\/think>\n?/i', '', $text));
    }

    /**
     * List models from an OpenAI-compatible /models endpoint.
     *
     * @return array<int, string>
     */
    private function listOpenAICompatibleModels(): array
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $url = $base_url . '/models';
        $headers = $this->openAICompatibleHeaders();

        $raw = $this->httpGet($url, $headers);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from provider.');
        }

        $models = [];
        foreach (($data['data'] ?? []) as $item) {
            $id = trim((string) ($item['id'] ?? $item['name'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        return $models;
    }

    /**
     * List xAI models and split Grok Imagine image models from text models.
     *
     * @return array{text: array<int, string>, image: array<int, string>}
     */
    private function listXAIModelCatalog(): array
    {
        $models = $this->listOpenAICompatibleModels();
        $text = [];
        $image = [];

        foreach ($models as $model) {
            $lower = strtolower($model);
            if (str_contains($lower, 'grok-imagine-image')) {
                $image[] = $model;
                continue;
            }
            if (str_contains($lower, 'grok-imagine-video')) {
                continue;
            }
            $text[] = $model;
        }

        if (empty($image)) {
            $image = ['grok-imagine-image', 'grok-imagine-image-quality'];
        }

        return ['text' => $text, 'image' => $image];
    }

    /**
     * List OpenRouter models filtered by output modality.
     *
     * @param array<int, string> $output_modalities
     * @return array<int, string>
     */
    private function listOpenRouterModelsByOutput(array $output_modalities): array
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $query = http_build_query([
            'output_modalities' => implode(',', array_values(array_filter(array_map('trim', $output_modalities)))),
        ]);
        $url = $base_url . '/models' . ($query !== '' ? '?' . $query : '');
        $headers = $this->openAICompatibleHeaders();

        $raw = $this->httpGet($url, $headers);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from provider.');
        }

        $models = [];
        foreach (($data['data'] ?? []) as $item) {
            $id = trim((string) ($item['id'] ?? $item['name'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        return $models;
    }

    /**
     * List models from Gemini's /models endpoint.
     *
     * @return array<int, string>
     */
    private function listGeminiModels(): array
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $url = $base_url . '/models?key=' . rawurlencode((string) ($this->key['api_key'] ?? ''));

        try {
            $raw = $this->httpGet($url, ['Content-Type: application/json']);
        } catch (RuntimeException $e) {
            throw new RuntimeException($this->normalizeGeminiTransportError($e->getMessage()), 0, $e);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from Gemini.');
        }

        if (isset($data['error'])) {
            throw new RuntimeException($this->formatGeminiError($data['error']));
        }

        $models = [];
        foreach (($data['models'] ?? []) as $item) {
            $methods = $item['supportedGenerationMethods'] ?? [];
            if (is_array($methods) && !in_array('generateContent', $methods, true)) {
                continue;
            }

            $id = trim((string) ($item['name'] ?? ''));
            if (str_starts_with($id, 'models/')) {
                $id = substr($id, 7);
            }
            if ($id !== '') {
                $models[] = $id;
            }
        }

        return $models;
    }

    /**
     * List models from Anthropic's /v1/models endpoint.
     *
     * @return array<int, string>
     */
    private function listAnthropicModels(): array
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $url = $base_url . '/v1/models';
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . ($this->key['api_key'] ?? ''),
            'anthropic-version: 2023-06-01',
        ];

        $raw = $this->httpGet($url, $headers);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from Anthropic.');
        }

        $models = [];
        foreach (($data['data'] ?? []) as $item) {
            $id = trim((string) ($item['id'] ?? $item['display_name'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        return $models;
    }

    /**
     * List models from Ollama's native /api/tags endpoint.
     *
     * @return array<int, string>
     */
    private function listOllamaModels(): array
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $base_url = preg_replace('#/v1$#', '', $base_url);
        $url = $base_url . '/api/tags';

        $raw = $this->httpGet($url, ['Content-Type: application/json']);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response from Ollama.');
        }

        $models = [];
        foreach (($data['models'] ?? []) as $item) {
            $id = trim((string) ($item['model'] ?? $item['name'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        return $models;
    }

    /**
     * Download a remote binary asset from a public URL without following redirects.
     */
    private function downloadRemoteBinary(string $url): string
    {
        $policy = api_key_url_policy($url);
        if (!$policy['ok'] || $policy['restricted']) {
            throw new RuntimeException('Refusing to download an image from a non-public URL.');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new RuntimeException('Failed to download generated image: ' . $error);
            }

            if ($httpCode >= 400) {
                throw new RuntimeException('Failed to download generated image (HTTP ' . $httpCode . ').');
            }

            return $response;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            throw new RuntimeException('Failed to download generated image.');
        }

        return $response;
    }

    /**
     * Decode a generated-image URL or data URI into binary image bytes.
     */
    private function decodeGeneratedImageReference(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            throw new RuntimeException('Generated image reference was empty.');
        }

        if (preg_match('#^data:image/[^;]+;base64,(.+)$#', $reference, $matches) === 1) {
            $binary = base64_decode($matches[1], true);
            if ($binary === false) {
                throw new RuntimeException('Failed to decode generated image data.');
            }
            return $binary;
        }

        return $this->downloadRemoteBinary($reference);
    }

    /**
     * Determine the correct OpenRouter image-generation modalities for a model.
     *
     * @return array<int, string>
     */
    private function openRouterImageModalities(string $model): array
    {
        $base_url = rtrim((string) ($this->key['base_url'] ?? ''), '/');
        $url = $base_url . '/models/' . rawurlencode($model) . '/endpoints';
        $headers = $this->openAICompatibleHeaders();

        try {
            $raw = $this->httpGet($url, $headers);
            $data = json_decode($raw, true);
            $modalities = $data['data']['architecture']['output_modalities'] ?? [];
            if (is_array($modalities) && in_array('image', $modalities, true)) {
                return in_array('text', $modalities, true) ? ['image', 'text'] : ['image'];
            }
        } catch (RuntimeException $e) {
            // Fall back to image-only output if model metadata is unavailable.
        }

        return ['image'];
    }

    /**
     * Return true when this key targets OpenRouter.
     */
    private function isOpenRouterHost(): bool
    {
        $host = strtolower((string) (parse_url((string) ($this->key['base_url'] ?? ''), PHP_URL_HOST) ?? ''));
        return $host !== '' && str_contains($host, 'openrouter.ai');
    }

    /**
     * Build a common OpenAI-compatible request body.
     */
    private function buildOpenAICompatibleBody(string $system, string $user, bool $stream = false): array
    {
        $body = [
            'model' => $this->key['model_text'] ?? 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ];

        if ($this->openAICompatibleSupportsCustomTemperature()) {
            $body['temperature'] = $this->generationFloat('temperature', 0.8);
            $body['top_p'] = $this->generationFloat('top_p', 1.0);
        }
        if ($this->openAICompatibleSupportsMaxTokens()) {
            $body['max_tokens'] = $this->generationInt('max_tokens', 2048);
        }
        if ($stream) {
            $body['stream'] = true;
        }
        if (($this->key['provider'] ?? '') === 'ollama') {
            $body['think'] = false;
        }

        return $body;
    }

    /**
     * Build common OpenAI-compatible headers.
     *
     * Adds the recommended OpenRouter attribution headers when applicable.
     *
     * @return array<int, string>
     */
    private function openAICompatibleHeaders(): array
    {
        $headers = ['Content-Type: application/json'];
        $api_key = (string) ($this->key['api_key'] ?? '');
        if ($api_key !== '') {
            $headers[] = 'Authorization: Bearer ' . $api_key;
        }

        $host = strtolower((string) (parse_url((string) ($this->key['base_url'] ?? ''), PHP_URL_HOST) ?? ''));
        if ($host !== '' && str_contains($host, 'openrouter.ai')) {
            $headers[] = 'HTTP-Referer: ' . request_origin() . base_url() . '/';
            $headers[] = 'X-Title: StoryWeaver';
        }

        return $headers;
    }

    /**
     * Some OpenAI-compatible model families reject custom temperature values.
     */
    private function openAICompatibleSupportsCustomTemperature(): bool
    {
        $model = strtolower((string) ($this->key['model_text'] ?? ''));
        return $model === '' || !str_contains($model, 'gpt-5');
    }

    /**
     * Some OpenAI-compatible model families reject custom token limits too.
     */
    private function openAICompatibleSupportsMaxTokens(): bool
    {
        $model = strtolower((string) ($this->key['model_text'] ?? ''));
        return $model === '' || !str_contains($model, 'gpt-5');
    }

    /**
     * Normalize user-provided generation controls.
     *
     * @param array<string, mixed> $options
     * @return array<string, float|int>
     */
    private function normalizeGenerationOptions(array $options): array
    {
        return [
            'temperature' => $this->clampFloat($options['temperature'] ?? 0.8, 0.0, 2.0, 0.8),
            'top_p'       => $this->clampFloat($options['top_p'] ?? 1.0, 0.0, 1.0, 1.0),
            'max_tokens'  => $this->clampInt($options['max_tokens'] ?? 2048, 128, 8192, 2048),
        ];
    }

    /**
     * Read a normalized float generation option.
     */
    private function generationFloat(string $key, float $default): float
    {
        $value = $this->generationOptions[$key] ?? $default;
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * Read a normalized integer generation option.
     */
    private function generationInt(string $key, int $default): int
    {
        $value = $this->generationOptions[$key] ?? $default;
        return is_numeric($value) ? (int) round((float) $value) : $default;
    }

    /**
     * Clamp a float option.
     */
    private function clampFloat(mixed $value, float $min, float $max, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $value = (float) $value;
        if ($value < $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return round($value, 2);
    }

    /**
     * Clamp an integer option.
     */
    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $value = (int) round((float) $value);
        if ($value < $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return $value;
    }

    /**
     * Normalize a Gemini model name for URL use.
     */
    private function geminiModelPath(string $model): string
    {
        $model = trim($model);
        if ($model === '') {
            $model = 'gemini-2.5-flash';
        }
        return str_starts_with($model, 'models/') ? $model : 'models/' . $model;
    }

    /**
     * Extract plain text from a Gemini response payload.
     */
    private function extractGeminiText(array $data): string
    {
        $text = '';
        foreach (($data['candidates'] ?? []) as $candidate) {
            foreach (($candidate['content']['parts'] ?? []) as $part) {
                $text .= (string) ($part['text'] ?? '');
            }
        }

        return $text;
    }

    /**
     * Convert Gemini API errors into clearer user-facing guidance.
     *
     * @param mixed $error
     */
    private function formatGeminiError($error): string
    {
        if (!is_array($error)) {
            return 'Gemini error: ' . trim((string) $error);
        }

        $code = (int) ($error['code'] ?? 0);
        $status = strtoupper(trim((string) ($error['status'] ?? '')));
        $message = trim((string) ($error['message'] ?? 'Unknown Gemini error'));
        $hint = '';

        if ($code === 429 || $status === 'RESOURCE_EXHAUSTED') {
            $hint = ' Free Google AI Studio keys are often quota-limited; try a supported Gemini text model, wait for quota reset, or use a paid key/project.';
        } elseif ($code === 403 || $status === 'PERMISSION_DENIED') {
            $hint = ' This key or project may not have access to that Gemini model or API in the current region.';
        } elseif ($code === 400 || $status === 'FAILED_PRECONDITION') {
            $hint = ' Check that the selected model supports generateContent for your AI Studio key and current API version.';
        }

        return 'Gemini error: ' . $message . $hint;
    }

    /**
     * Extract a structured Gemini error from a transport-layer exception message.
     */
    private function normalizeGeminiTransportError(string $message): string
    {
        if (preg_match('/(\{.*"error".*\})/s', $message, $matches)) {
            $payload = json_decode($matches[1], true);
            if (is_array($payload) && isset($payload['error'])) {
                return $this->formatGeminiError($payload['error']);
            }
        }

        return $message;
    }
}
