<?php
/**
 * Unified AI Provider — works with any OpenAI-compatible API endpoint.
 * Supports DeepSeek, OpenAI, and custom endpoints via configurable URL.
 */

defined('ABSPATH') || exit;

class WooAI_Desc_AI_Provider {

    private string $api_key;
    private string $model;
    private string $api_url = 'https://api.deepseek.com/v1/chat/completions';

    public function __construct(string $api_key, string $model) {
        $this->api_key = $api_key;
        $this->model   = $model;
    }

    /**
     * Override the API URL (used by test_connection with request param).
     */
    public function set_api_url(string $url): void {
        $this->api_url = rtrim($url, '/');
    }

    /**
     * Get the API base URL from settings.
     */
    private function get_base_url(): string {
        if (class_exists('WooAI_Desc_Settings')) {
            return WooAI_Desc_Settings::get_api_url();
        }
        return $this->api_url;
    }

    /**
     * Generate content from the AI provider.
     */
    public function generate(string $prompt, array $options = []): string {
        $api_url = $this->api_url ?: $this->get_base_url();

        $body = [
            'model'       => $this->model,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => 'You output JSON only. Inside JSON values, use HTML tags (<strong>, <p>, <br>). No markdown. No explanation. Follow the language instruction in the user prompt EXACTLY.',
                ],
                [
                    'role'    => 'user',
                    'content' => $prompt,
                ],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens'  => $options['max_tokens'] ?? 2048,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        $response = wp_remote_post($api_url, [
            'timeout' => $options['timeout'] ?? 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body'    => json_encode($body),
        ]);

        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            WooAI_Desc_Logger::error('ai_provider', 'API request failed', [
                'api_url' => $api_url,
                'model'   => $this->model,
                'error'   => $err,
            ]);
            throw new Exception('AI API request failed: ' . esc_html($err));
        }

        $http_status = wp_remote_retrieve_response_code($response);
        $raw_body    = wp_remote_retrieve_body($response);
        $data        = json_decode($raw_body, true);

        if ($http_status !== 200) {
            $error_msg = $data['error']['message'] ?? "HTTP {$http_status}";
            WooAI_Desc_Logger::error('ai_provider', "{$error_msg} (HTTP {$http_status})", [
                'api_url'     => $api_url,
                'model'       => $this->model,
                'http_status' => $http_status,
                'error'       => $error_msg,
                'response'    => substr($raw_body, 0, 1000),
            ]);
            throw new Exception('AI API error (' . esc_html($http_status) . '): ' . esc_html($error_msg));
        }

        $content = $this->parse_response($data, $raw_body);

        // Log if empty result
        if (empty($content) && !empty($raw_body)) {
            WooAI_Desc_Logger::warning('ai_provider', 'Empty parse result', [
                'api_url'  => $api_url,
                'model'    => $this->model,
                'response' => substr($raw_body, 0, 1000),
            ]);
        }

        return $content;
    }

    /**
     * Parse the API response into usable content.
     */
    private function parse_response(array $data, string $raw_body): string {
        $content = $data['choices'][0]['message']['content'] ?? '';

        // DeepSeek V4 Pro: content may be empty, check reasoning_content
        if (empty(trim($content)) && !empty($data['choices'][0]['message']['reasoning_content'])) {
            $reasoning = $data['choices'][0]['message']['reasoning_content'];
            if (preg_match('/\{[^{}]*"title"\s*:.*\}/s', $reasoning, $m)) {
                $content = $m[0];
            } elseif (preg_match('/(\{.*\}|\[.*\])/s', $reasoning, $m)) {
                $content = $m[0];
            } else {
                $content = $reasoning;
            }
        }

        // Strip markdown code fences
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) {
            $content = $m[1];
        }
        $content = trim($content);

        // Extract JSON block if wrapped in text
        if ($content && $content[0] !== '{' && $content[0] !== '[') {
            if (preg_match('/(\{.*\}|\[.*\])/s', $content, $m)) {
                $content = $m[0];
            }
        }

        return $content;
    }
}
