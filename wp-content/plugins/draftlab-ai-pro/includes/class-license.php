<?php
/**
 * License helper for DraftLab AI Pro.
 *
 * 本类管理插件的授权状态（付费/试用/过期）。
 *
 * 注意：即使本插件是"Pro版"安装包，也需要有效的 Freemius 授权才能使用 AI 生成功能。
 * 这防止了盗版分发：有人拿到插件 zip 直接装上是不能用的。
 *
 * 缓存策略：
 * 使用自定义 WordPress option（wooai_desc_license_data）作为可靠缓存层，
 * 绕过 Freemius SDK 的缓存问题（SDK 的 sync_install() 只更新 _site 不更新 _license）。
 *
 * 流程：
 *   - 激活授权 → set_license_active() → 写入 option
 *   - 检查授权 → get_license_info() → 24h 内读 option，超出调用 Freemius SDK
 *   - 停用插件 → clear_cache() → 删除 option
 */

defined('ABSPATH') || exit;

class WooAI_Desc_License {

    /**
     * 自定义 option 的键名（存储在 wp_options 表）。
     * 存储内容：{ is_paid, plan, expires, time }
     */
    private const OPTION_KEY = 'wooai_desc_license_data';

    /**
     * 检查当前是否已付费（外部使用）。
     * 控制 AI 生成 API 的门控——不是功能门控。
     * 注意：这是 Pro 插件，所有功能代码都在，但生成接口需要 license 才允许调用。
     *
     * @return bool
     */
    public static function is_pro(): bool {
        $info = self::get_license_info();
        return !empty($info['is_paid']);
    }

    /**
     * 获取完整的授权信息（供前端渲染用）。
     *
     * 读取优先级（降序）：
     *   1. 自定义 option（24小时内写入的，视为新鲜）
     *   2. Freemius SDK（直接查 SDK 内部状态）
     *   3. 兜底返回 free（无 Freemius 时的开发环境默认值）
     *
     * 为什么优先读自定义 option？
     * Freemius SDK 的缓存机制不可靠——is_paying() 依赖 _license 缓存，
     * 而 sync_install() 不更新这个缓存。自定义 option 是我们自己维护的，
     * 每次激活/检查时写入，读取更快更可靠。
     *
     * @return array{plan: string, is_pro: bool, is_paid: bool, expires: int|null}
     */
    public static function get_license_info(): array {
        // ── 1. 先读自定义 option（如果 24h 内写入的） ──
        $cached = get_option(self::OPTION_KEY);
        if (is_array($cached) && !empty($cached['is_paid']) && !empty($cached['plan'])) {
            $age = isset($cached['time']) ? (time() - (int)$cached['time']) : DAY_IN_SECONDS + 1;
            if ($age < DAY_IN_SECONDS) {
                return [
                    'plan'    => $cached['plan'],
                    'is_pro'  => true,
                    'is_paid' => true,
                    'expires' => $cached['expires'] ?? null,
                ];
            }
        }

        // ── 2. 兜底：从 Freemius SDK 实时检查 ──
        if (function_exists('wooai_desc_fs')) {
            $fs = wooai_desc_fs();
            $is_paid = $fs->is_paying() || $fs->is_trial();
            $plan = $fs->is_paying()
                ? $fs->get_plan_name()
                : ($fs->is_trial() ? 'trial' : 'expired');

            // 过期时间，默认 null（null = 终身有效，不设到期日）。
            // 为什么这里不读 SDK 缓存的 license 对象（$fs->_license）？
            //   1. Freemius::$_license 是 private（freemius/includes/class-freemius.php:240），
            //      SDK 也没有 __get / __isset，外部 isset($fs->_license) 恒为 false 且不报错；
            //   2. 也就是说以前那段 isset 兜底是死代码，从未执行过。
            //   3. 必须保持 $expires 为空，才能命中下面的 /licenses.json 实时查询分支，
            //      那是目前唯一可信的 expiration 来源。
            $expires = null;

            // 如果 SDK 没缓存 license，但用户确实是付费状态，尝试 API 查询
            if (empty($expires) && $is_paid) {
                try {
                    $site = $fs->get_site();
                    $api = FS_Api::instance(
                        $fs->get_slug(),
                        'install',
                        $site->id,
                        $site->public_key,
                        false,
                        $site->secret_key
                    );
                    $licenses = $api->call('/licenses.json', 'get');
                    if (isset($licenses->licenses) && is_array($licenses->licenses) && count($licenses->licenses) > 0) {
                        $lic = $licenses->licenses[0];
                        if (!empty($lic->expiration)) {
                            $expires = is_numeric($lic->expiration)
                                ? (int) $lic->expiration
                                : strtotime($lic->expiration);
                        }
                    }
                } catch (Exception $e) {
                    // 静默失败——返回 null 过期时间
                }
            }

            $info = [
                'plan'    => $plan,
                'is_pro'  => $is_paid,
                'is_paid' => $is_paid,
                'expires' => $expires,
            ];

            // 如果是付费状态，同步更新自定义 option
            if ($is_paid) {
                $plan_name = is_string($plan) ? $plan : 'pro';
                update_option(self::OPTION_KEY, [
                    'is_paid' => true,
                    'plan'    => $plan_name,
                    'expires' => $expires,
                    'time'    => time(),
                ]);
            }

            return $info;
        }

        // ── 3. 没有 Freemius -> 开发环境兜底 ──
        return ['plan' => 'free', 'is_pro' => false, 'is_paid' => false];
    }

    /**
     * 写入授权状态到自定义 option（授权激活成功后调用）。
     * 在 class-rest-api.php 的 /license/activate 端点中被调用。
     *
     * @param string   $plan_name 计划名称（通常为 'pro'）
     * @param int|null $expires   过期时间戳（null 表示终身）
     */
    public static function set_license_active(string $plan_name = 'pro', $expires = null): void {
        update_option(self::OPTION_KEY, [
            'is_paid' => true,
            'plan'    => $plan_name,
            'expires' => $expires,
            'time'    => time(),
        ]);
    }

    /**
     * 清除自定义 option（插件停用时调用）。
     * 确保下次激活时重新通过 Freemius SDK 验证。
     */
    public static function clear_cache(): void {
        delete_option(self::OPTION_KEY);
    }
}