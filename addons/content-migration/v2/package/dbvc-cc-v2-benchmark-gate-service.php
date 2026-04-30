<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Benchmark_Gate_Service
{
    /**
     * @var DBVC_CC_V2_Benchmark_Gate_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Benchmark_Gate_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return array<string, int>
     */
    public function get_policy()
    {
        return [
            'blocked_quality_score_max' => 69,
            'warning_quality_score_max' => 84,
            'blocked_ambiguous_reviewed_count' => 3,
            'warning_ambiguous_reviewed_count' => 1,
            'blocked_manual_override_count' => 5,
            'warning_manual_override_count' => 1,
            'blocked_rerun_count' => 3,
            'warning_rerun_count' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $signals
     * @return array<string, mixed>
     */
    public function evaluate_page(array $signals)
    {
        $policy = $this->get_policy();
        $quality_score = isset($signals['quality_score']) ? (int) $signals['quality_score'] : 0;
        $ambiguous_reviewed_count = isset($signals['ambiguous_reviewed_count']) ? (int) $signals['ambiguous_reviewed_count'] : 0;
        $manual_override_count = isset($signals['manual_override_count']) ? (int) $signals['manual_override_count'] : 0;
        $rerun_count = isset($signals['rerun_count']) ? (int) $signals['rerun_count'] : 0;

        $blocking_reason_codes = [];
        $warning_reason_codes = [];

        if ($quality_score <= $policy['blocked_quality_score_max']) {
            $blocking_reason_codes[] = 'quality_score_floor';
        } elseif ($quality_score <= $policy['warning_quality_score_max']) {
            $warning_reason_codes[] = 'quality_score_floor';
        }

        if ($ambiguous_reviewed_count >= $policy['blocked_ambiguous_reviewed_count']) {
            $blocking_reason_codes[] = 'ambiguous_reviewed_threshold';
        } elseif ($ambiguous_reviewed_count >= $policy['warning_ambiguous_reviewed_count']) {
            $warning_reason_codes[] = 'ambiguous_reviewed_threshold';
        }

        if ($manual_override_count >= $policy['blocked_manual_override_count']) {
            $blocking_reason_codes[] = 'manual_override_threshold';
        } elseif ($manual_override_count >= $policy['warning_manual_override_count']) {
            $warning_reason_codes[] = 'manual_override_threshold';
        }

        if ($rerun_count >= $policy['blocked_rerun_count']) {
            $blocking_reason_codes[] = 'rerun_threshold';
        } elseif ($rerun_count >= $policy['warning_rerun_count']) {
            $warning_reason_codes[] = 'rerun_threshold';
        }

        $status = DBVC_CC_V2_Contracts::READINESS_STATUS_READY;
        if (! empty($blocking_reason_codes)) {
            $status = DBVC_CC_V2_Contracts::READINESS_STATUS_BLOCKED;
        } elseif (! empty($warning_reason_codes)) {
            $status = DBVC_CC_V2_Contracts::READINESS_STATUS_NEEDS_REVIEW;
        }

        return [
            'status' => $status,
            'high_risk' => $status !== DBVC_CC_V2_Contracts::READINESS_STATUS_READY,
            'blocking_reason_codes' => array_values(array_unique(array_map('sanitize_key', $blocking_reason_codes))),
            'warning_reason_codes' => array_values(array_unique(array_map('sanitize_key', $warning_reason_codes))),
            'signals' => [
                'quality_score' => $quality_score,
                'ambiguous_reviewed_count' => $ambiguous_reviewed_count,
                'manual_override_count' => $manual_override_count,
                'rerun_count' => $rerun_count,
            ],
            'policy' => $policy,
        ];
    }
}
