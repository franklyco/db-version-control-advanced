<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Attribute_Scrubber_Service
{
    /**
     * @param array<string, string> $attributes
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    public static function scrub_attributes(array $attributes, array $policy)
    {
        $scrubbed_attributes = [];
        $actions = [];
        $totals = [
            'kept' => 0,
            'dropped' => 0,
            'hashed' => 0,
            'tokenized' => 0,
        ];
        $by_attribute = [];

        foreach ($attributes as $raw_name => $raw_value) {
            $attribute_name = strtolower(trim((string) $raw_name));
            if ($attribute_name === '') {
                continue;
            }

            $attribute_value = (string) $raw_value;
            $category = self::resolve_attribute_category($attribute_name);
            $action = self::resolve_action($attribute_name, $category, $policy);

            if (! empty($policy['enabled'])) {
                $actions[$attribute_name] = $action;
            } else {
                $actions[$attribute_name] = DBVC_CC_Contracts::SCRUB_ACTION_KEEP;
                $action = DBVC_CC_Contracts::SCRUB_ACTION_KEEP;
            }

            if (! isset($by_attribute[$category])) {
                $by_attribute[$category] = [
                    'kept' => 0,
                    'dropped' => 0,
                    'hashed' => 0,
                    'tokenized' => 0,
                ];
            }

            if ($action === DBVC_CC_Contracts::SCRUB_ACTION_DROP) {
                $totals['dropped']++;
                $by_attribute[$category]['dropped']++;
                continue;
            }

            if ($action === DBVC_CC_Contracts::SCRUB_ACTION_HASH) {
                $scrubbed_attributes[$attribute_name] = self::hash_value($attribute_value);
                $totals['hashed']++;
                $by_attribute[$category]['hashed']++;
                continue;
            }

            if ($action === DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE) {
                $scrubbed_attributes[$attribute_name] = self::tokenize_value($attribute_value);
                $totals['tokenized']++;
                $by_attribute[$category]['tokenized']++;
                continue;
            }

            $scrubbed_attributes[$attribute_name] = $attribute_value;
            $totals['kept']++;
            $by_attribute[$category]['kept']++;
        }

        return [
            'attributes' => $scrubbed_attributes,
            'actions' => $actions,
            'totals' => $totals,
            'by_attribute' => $by_attribute,
        ];
    }

    /**
     * @param string $attribute_name
     * @return string
     */
    private static function resolve_attribute_category($attribute_name)
    {
        if ($attribute_name === 'class') {
            return 'class';
        }

        if ($attribute_name === 'id') {
            return 'id';
        }

        if ($attribute_name === 'style') {
            return 'style';
        }

        if (strpos($attribute_name, 'data-') === 0) {
            return 'data';
        }

        if (strpos($attribute_name, 'aria-') === 0) {
            return 'aria';
        }

        if (strpos($attribute_name, 'on') === 0) {
            return 'event';
        }

        return 'other';
    }

    /**
     * @param string $attribute_name
     * @param string $category
     * @param array<string, mixed> $policy
     * @return string
     */
    private static function resolve_action($attribute_name, $category, array $policy)
    {
        if ($category === 'event') {
            return DBVC_CC_Contracts::SCRUB_ACTION_DROP;
        }

        $allowlist = isset($policy['allowlist']) && is_array($policy['allowlist']) ? $policy['allowlist'] : [];
        if (self::matches_patterns($attribute_name, $allowlist)) {
            return DBVC_CC_Contracts::SCRUB_ACTION_KEEP;
        }

        $denylist = isset($policy['denylist']) && is_array($policy['denylist']) ? $policy['denylist'] : [];
        if (self::matches_patterns($attribute_name, $denylist)) {
            return DBVC_CC_Contracts::SCRUB_ACTION_DROP;
        }

        $actions = isset($policy['actions']) && is_array($policy['actions']) ? $policy['actions'] : [];
        if (isset($actions[$category]) && is_string($actions[$category])) {
            return $actions[$category];
        }

        if (isset($actions['other']) && is_string($actions['other'])) {
            return $actions['other'];
        }

        return DBVC_CC_Contracts::SCRUB_ACTION_KEEP;
    }

    /**
     * @param string $value
     * @return string
     */
    private static function hash_value($value)
    {
        if ($value === '') {
            return '';
        }

        return 'sha256:' . substr(hash('sha256', $value), 0, 32);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function tokenize_value($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $tokens = preg_split('/\s+/', $value);
        if (! is_array($tokens)) {
            return '';
        }

        $tokenized = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $tokenized[] = 'tok_' . substr(hash('sha256', $token), 0, 10);
        }

        return implode(' ', $tokenized);
    }

    /**
     * @param string $attribute_name
     * @param array<int, string> $patterns
     * @return bool
     */
    private static function matches_patterns($attribute_name, array $patterns)
    {
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern === '') {
                continue;
            }

            if ($pattern === $attribute_name) {
                return true;
            }

            if (substr($pattern, -1) === '*' && strpos($attribute_name, rtrim($pattern, '*')) === 0) {
                return true;
            }
        }

        return false;
    }
}
