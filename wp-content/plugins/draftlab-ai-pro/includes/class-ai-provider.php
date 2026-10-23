<?php
/**
 * Unified AI Provider — works with any OpenAI-compatible API endpoint.
 *
 * 本类封装了与 AI 模型的通信逻辑。
 * 支持任何兼容 OpenAI API 格式的端点（DeepSeek、OpenAI、GitHub Models 等）。
 *
 * 核心流程：
 *   1. 接收 prompt 和参数 → 构造请求体
 *   2. 通过 wp_remote_post 发送 HTTP 请求
 *   3. 解析响应 → 提取 JSON 内容
 *   4. 处理异常情况（DeepSeek V4 Pro 的 reasoning_content、markdown 代码块剥离等）
 */

defined('ABSPATH') || exit;

class WooAI_Desc_AI_Provider {

    /** @var string AI API 密钥 */
    private string $api_key;

    /** @var string 模型名称（如 deepseek-chat, gpt-4o-mini） */
    private string $model;

    /** @var string API URL 默认值（DeepSeek 官方端点） */
    private string $api_url = 'https://api.deepseek.com/v1/chat/completions';

    /**
     * 构造函数。
     *
     * @param string $api_key AI API 密钥
     * @param string $model   模型名称
     */
    public function __construct(string $api_key, string $model) {
        $this->api_key = $api_key;
        $this->model   = $model;
    }

    /**
     * 覆盖 API URL（用于 test_connection 功能，用前端传的 url 覆盖默认值）。
     *
     * @param string $url 完整的 API 端点 URL
     */
    public function set_api_url(string $url): void {
        $this->api_url = rtrim($url, '/');
    }

    /**
     * 从设置中获取 API base URL。
     * 如果在设置页面配置了 api_url，优先使用；否则用 DeepSeek 默认端点。
     *
     * @return string
     */
    private function get_base_url(): string {
        if (class_exists('WooAI_Desc_Settings')) {
            return WooAI_Desc_Settings::get_api_url();
        }
        return $this->api_url;
    }

    /**
     * 核心方法：向 AI 模型发送请求并获取生成结果。
     *
     * 请求体构成：
     *   - system prompt：要求模型只输出 JSON（用 HTML 标签，不用 markdown）
     *   - user prompt：来自 ProductHandler 构造的产品描述指令
     *   - response_format: json_object（大多数模型支持强制 JSON 输出）
     *
     * @param string $prompt  送交给 AI 的完整指令文本
     * @param array  $options 可选参数（max_tokens, temperature, timeout）
     *
     * @return string 返回解析后的 JSON 字符串（不是数组，由前端自行 decode）
     * @throws Exception 请求网络错误 或 API 返回非 200 状态码
     */
    public function generate(string $prompt, array $options = []): string {
        $api_url = $this->api_url ?: $this->get_base_url();

        // ── 构造请求体 ──
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

        // ── 发送 HTTP 请求 ──
        $response = wp_remote_post($api_url, [
            'timeout' => $options['timeout'] ?? 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'body'    => json_encode($body),
        ]);

        // ── 检查网络层错误（DNS、超时、SSL 等） ──
        if (is_wp_error($response)) {
            $err = $response->get_error_message();
            WooAI_Desc_Logger::error('ai_provider', 'API request failed', [
                'api_url' => $api_url,
                'model'   => $this->model,
                'error'   => $err,
            ]);
            // 这里不转义：$err 来自 cURL/远程服务器，异常消息只是"数据"，
            // 终点是 REST JSON → React（JSX 的 {} 默认按 textContent 渲染，已自动转义）。
            // esc_html 是输出函数，只该用在 PHP 侧直接 echo 到 HTML 的地方；
            // 在这里转义反而会把 & ' < 变成 &amp; &#039; &lt; 字面量显示给用户。
            throw new Exception('AI API request failed: ' . $err);
        }

        // ── 检查 HTTP 状态码 ──
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
            throw new Exception('AI API error (' . $http_status . '): ' . $error_msg);
        }

        // ── 解析响应 ──
        $content = $this->parse_response($data, $raw_body);

        // 日志：解析结果为空但服务端有返回体（可能是格式变化）
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
     * 解析 AI API 响应 → 提取可用的 JSON 字符串。
     *
     * 处理三种特殊情况：
     *   1. DeepSeek V4 Pro：content 可能为空，真正的 JSON 藏在 reasoning_content 里
     *   2. Markdown 代码 fence：剥离 ```json ... ```
     *   3. JSON 被自然语言包裹：提取首尾花括号 {} 之间的内容
     *
     * @param array  $data     解码后的 API 响应数组
     * @param string $raw_body 原始响应字符串（仅用于日志）
     *
     * @return string 提取出的纯 JSON 字符串
     */
    private function parse_response(array $data, string $raw_body): string {
        // 标准路径：从 choices[0].message.content 中读取
        $content = $data['choices'][0]['message']['content'] ?? '';

        // ── 特殊处理：DeepSeek V4 Pro ──
        // 该模型可能把输出放在 reasoning_content（思维链字段）而非 content
        if (empty(trim($content)) && !empty($data['choices'][0]['message']['reasoning_content'])) {
            $reasoning = $data['choices'][0]['message']['reasoning_content'];

            // 优先提取包含 "title" 字段的 JSON 对象
            if (preg_match('/\{[^{}]*"title"\s*:.*\}/s', $reasoning, $m)) {
                $content = $m[0];
            } elseif (preg_match('/(\{.*\}|\[.*\])/s', $reasoning, $m)) {
                $content = $m[0];
            } else {
                $content = $reasoning;
            }
        }

        // ── 清理 markdown 代码 fence ──
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?```/s', $content, $m)) {
            $content = $m[1];
        }
        $content = trim($content);

        // ── 如果内容中嵌有 JSON 对象但开头不是 {}，则提取 JSON 部分 ──
        if ($content && $content[0] !== '{' && $content[0] !== '[') {
            if (preg_match('/(\{.*\}|\[.*\])/s', $content, $m)) {
                $content = $m[0];
            }
        }

        return $content;
    }
}
