<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Attribute_Scrub_Policy_Service
{
    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function get_policy(array $options)
    {
        $actions = [
            'class' => self::sanitize_action(isset($options['scrub_attr_action_class']) ? (string) $options['scrub_attr_action_class'] : DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE, DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE),
            'id' => self::sanitize_action(isset($options['scrub_attr_action_id']) ? (string) $options['scrub_attr_action_id'] : DBVC_CC_Contracts::SCRUB_ACTION_HASH, DBVC_CC_Contracts::SCRUB_ACTION_HASH),
            'data' => self::sanitize_action(isset($options['scrub_attr_action_data']) ? (string) $options['scrub_attr_action_data'] : DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE, DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE),
            'style' => self::sanitize_action(isset($options['scrub_attr_action_style']) ? (string) $options['scrub_attr_action_style'] : DBVC_CC_Contracts::SCRUB_ACTION_DROP, DBVC_CC_Contracts::SCRUB_ACTION_DROP),
            'aria' => self::sanitize_action(isset($options['scrub_attr_action_aria']) ? (string) $options['scrub_attr_action_aria'] : DBVC_CC_Contracts::SCRUB_ACTION_KEEP, DBVC_CC_Contracts::SCRUB_ACTION_KEEP),
            'other' => DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
        ];

        $profile = isset($options['scrub_profile_mode']) ? sanitize_key((string) $options['scrub_profile_mode']) : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT;
        $allowed_profiles = [
            DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            DBVC_CC_Contracts::SCRUB_PROFILE_CUSTOM,
            DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED,
        ];

        if (! in_array($profile, $allowed_profiles, true)) {
            $profile = DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT;
        }

        $policy = [
            'enabled' => ! empty($options['scrub_policy_enabled']),
            'profile' => $profile,
            'actions' => $actions,
            'allowlist' => self::parse_attribute_list(isset($options['scrub_custom_allowlist']) ? (string) $options['scrub_custom_allowlist'] : ''),
            'denylist' => self::parse_attribute_list(isset($options['scrub_custom_denylist']) ? (string) $options['scrub_custom_denylist'] : ''),
        ];

        $hash_source = wp_json_encode($policy);
        $policy['policy_hash'] = is_string($hash_source) ? hash('sha256', $hash_source) : hash('sha256', 'dbvc_cc_default_policy');

        return $policy;
    }

    /**
     * @return array<int, string>
     */
    public static function get_allowed_actions()
    {
        return [
            DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
            DBVC_CC_Contracts::SCRUB_ACTION_DROP,
            DBVC_CC_Contracts::SCRUB_ACTION_HASH,
            DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
        ];
    }

    /**
     * @param string $action
     * @param string $fallback
     * @return string
     */
    private static function sanitize_action($action, $fallback)
    {
        $action = sanitize_key((string) $action);
        if (in_array($action, self::get_allowed_actions(), true)) {
            return $action;
        }

        return $fallback;
    }

    /**
     * @param string $raw
     * @return array<int, string>
     */
    private static function parse_attribute_list($raw)
    {
        if ($raw === '') {
            return [];
        }

        $parts = preg_split('/[\s,]+/', strtolower($raw));
        if (! is_array($parts)) {
            return [];
        }

        $parsed = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $part = preg_replace('/[^a-z0-9_\-:*]/', '', $part);
            if (! is_string($part) || $part === '') {
                continue;
            }
            $parsed[$part] = $part;
        }

        return array_values($parsed);
    }
}
