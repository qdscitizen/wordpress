<?php
/**
 * License helper for DraftLab AI Pro.
 *
 * This IS the Pro plugin — but the license must be active (paid/trial)
 * for AI generation to work. Freemius SDK handles subscription expiry.
 * On localhost, sandbox mode allows testing with a sandbox license key.
 *
 * Uses a custom WordPress option (wooai_desc_license_data) as a reliable
 * cache layer to avoid Freemius SDK caching issues.
 */

defined('ABSPATH') || exit;

class WooAI_Desc_License {

    private const OPTION_KEY = 'wooai_desc_license_data';

    /**
     * Is the Freemius license active (paid or trial)?
     * Controls AI generation API gate — not feature gating.
     */
    public static function is_pro(): bool {
        $info = self::get_license_info();
        return !empty($info['is_paid']);
    }

    /**
     * Get license info for frontend display.
     * Priority: custom option (fresh) > Freemius SDK > default free.
     * Custom option is considered fresh for 24 hours, then re-checked via Freemius SDK.
     */
    public static function get_license_info(): array {
        // 1. Try custom option first if fresh (< 24h old)
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

        // 2. Fallback to Freemius SDK
        if (function_exists('wooai_desc_fs')) {
            $fs = wooai_desc_fs();
            $is_paid = $fs->is_paying() || $fs->is_trial();
            $plan = $fs->is_paying()
                ? $fs->get_plan_name()
                : ($fs->is_trial() ? 'trial' : 'expired');

            // Get expiration timestamp from Freemius license
            $expires = null;
            if (isset($fs->_license) && is_object($fs->_license)) {
                // expiration is Unix timestamp or null (for lifetime)
                $expires = $fs->_license->expiration ?? null;
            }

            // Fallback: if paying but SDK has no license cached, try API
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
                    // Silently fail — use null expiration
                }
            }

            $info = [
                'plan'    => $plan,
                'is_pro'  => $is_paid,
                'is_paid' => $is_paid,
                'expires' => $expires,
            ];

            // If paid, also update the custom option for future requests
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

        // 3. No Freemius — dev fallback
        return ['plan' => 'free', 'is_pro' => false, 'is_paid' => false];
    }

    /**
     * Save license data to custom option (called after successful activation).
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
     * Clear custom license option (called on deactivation or when license expires).
     */
    public static function clear_cache(): void {
        delete_option(self::OPTION_KEY);
    }
}