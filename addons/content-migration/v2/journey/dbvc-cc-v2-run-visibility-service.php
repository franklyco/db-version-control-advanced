<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Run_Visibility_Service
{
    private const USER_META_KEY = 'dbvc_cc_v2_hidden_runs';

    /**
     * @var DBVC_CC_V2_Run_Visibility_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Run_Visibility_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return array<string, string>
     */
    public function get_hidden_run_map()
    {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return [];
        }

        $stored = get_user_meta($user_id, self::USER_META_KEY, true);
        if (! is_array($stored)) {
            return [];
        }

        $normalized = [];
        foreach ($stored as $run_id => $hidden_at) {
            $run_id = sanitize_text_field((string) $run_id);
            $hidden_at = sanitize_text_field((string) $hidden_at);
            if ($run_id === '') {
                continue;
            }

            $normalized[$run_id] = $hidden_at;
        }

        return $normalized;
    }

    /**
     * @param string $run_id
     * @return array<string, mixed>
     */
    public function get_visibility_payload($run_id)
    {
        $run_id = sanitize_text_field((string) $run_id);
        $hidden_runs = $this->get_hidden_run_map();
        $hidden_at = isset($hidden_runs[$run_id]) ? (string) $hidden_runs[$run_id] : '';

        return [
            'hidden' => $hidden_at !== '',
            'hiddenAt' => $hidden_at,
        ];
    }

    /**
     * @param string $run_id
     * @param bool   $hidden
     * @return array<string, mixed>
     */
    public function set_hidden($run_id, $hidden)
    {
        $run_id = sanitize_text_field((string) $run_id);
        $hidden_runs = $this->get_hidden_run_map();
        if ($run_id === '') {
            return [
                'hidden' => false,
                'hiddenAt' => '',
            ];
        }

        if ($hidden) {
            $hidden_runs[$run_id] = current_time('c');
        } else {
            unset($hidden_runs[$run_id]);
        }

        $user_id = get_current_user_id();
        if ($user_id > 0) {
            update_user_meta($user_id, self::USER_META_KEY, $hidden_runs);
        }

        return $this->get_visibility_payload($run_id);
    }
}
