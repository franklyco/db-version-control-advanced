<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Import_Plan_Bridge_Service
{
    /**
     * @var DBVC_CC_V2_Import_Plan_Bridge_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Import_Plan_Bridge_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $run_id
     * @param string $package_id
     * @return array<string, mixed>|WP_Error
     */
    public function get_dry_run_surface($run_id, $package_id = '')
    {
        $payload = DBVC_CC_V2_Import_Collection_Service::get_instance()->build_dry_run_surface($run_id, $package_id);
        if (is_wp_error($payload)) {
            return $payload;
        }

        if (! empty($payload['packageId'])) {
            DBVC_CC_V2_Package_Observability_Service::get_instance()->record_dry_run_snapshot(
                $run_id,
                (string) $payload['packageId'],
                $payload
            );
        }

        return $payload;
    }
}
