<?php

namespace Dbvc\VisualEditor\Performance;

final class PerformanceProfiler
{
    /**
     * @var bool
     */
    private $enabled = false;

    /**
     * @var float
     */
    private $started_at = 0.0;

    /**
     * @var string
     */
    private $request_id = '';

    /**
     * @var array<string, int>
     */
    private $counters = [];

    /**
     * @var array<string, array<string, float|int>>
     */
    private $timings = [];

    /**
     * @var array<string, mixed>
     */
    private $values = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private $events = [];

    /**
     * @var int
     */
    private $max_events = 50;

    /**
     * @var bool
     */
    private $flushed = false;

    public function __construct()
    {
        $this->started_at = microtime(true);
        $this->enabled = (bool) apply_filters('dbvc_visual_editor_performance_profile_enabled', false);
        $this->request_id = $this->buildRequestId();
        $this->max_events = $this->resolveMaxEvents();
    }

    /**
     * @return bool
     */
    public function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * @return float
     */
    public function startTimer()
    {
        return $this->enabled ? microtime(true) : 0.0;
    }

    /**
     * @param string               $name
     * @param int                  $amount
     * @param array<string, mixed> $tags
     * @return void
     */
    public function increment($name, $amount = 1, array $tags = [])
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->buildMetricKey($name, $tags);
        if ($key === '') {
            return;
        }

        $this->counters[$key] = isset($this->counters[$key])
            ? (int) $this->counters[$key] + (int) $amount
            : (int) $amount;
    }

    /**
     * @param string               $name
     * @param mixed                $value
     * @param array<string, mixed> $tags
     * @return void
     */
    public function recordValue($name, $value, array $tags = [])
    {
        if (! $this->enabled) {
            return;
        }

        $key = $this->buildMetricKey($name, $tags);
        if ($key === '') {
            return;
        }

        if (is_numeric($value)) {
            $this->values[$key] = strpos((string) $value, '.') === false ? (int) $value : (float) $value;
            return;
        }

        if (is_bool($value)) {
            $this->values[$key] = $value;
            return;
        }

        $this->values[$key] = sanitize_text_field((string) $value);
    }

    /**
     * @param string               $name
     * @param float                $started_at
     * @param array<string, mixed> $tags
     * @return void
     */
    public function recordDuration($name, $started_at, array $tags = [])
    {
        if (! $this->enabled || ! is_numeric($started_at) || (float) $started_at <= 0) {
            return;
        }

        $key = $this->buildMetricKey($name, $tags);
        if ($key === '') {
            return;
        }

        $duration_ms = round((microtime(true) - (float) $started_at) * 1000, 3);
        if (! isset($this->timings[$key])) {
            $this->timings[$key] = [
                'calls' => 0,
                'totalMs' => 0.0,
                'maxMs' => 0.0,
            ];
        }

        $this->timings[$key]['calls'] = (int) $this->timings[$key]['calls'] + 1;
        $this->timings[$key]['totalMs'] = round((float) $this->timings[$key]['totalMs'] + $duration_ms, 3);
        $this->timings[$key]['maxMs'] = max((float) $this->timings[$key]['maxMs'], $duration_ms);
    }

    /**
     * @param string               $name
     * @param callable             $callback
     * @param array<string, mixed> $tags
     * @return mixed
     */
    public function measure($name, callable $callback, array $tags = [])
    {
        if (! $this->enabled) {
            return $callback();
        }

        $started_at = $this->startTimer();
        try {
            return $callback();
        } finally {
            $this->recordDuration($name, $started_at, $tags);
        }
    }

    /**
     * @param string               $name
     * @param array<string, mixed> $context
     * @return void
     */
    public function addEvent($name, array $context = [])
    {
        if (! $this->enabled || $this->max_events <= 0 || count($this->events) >= $this->max_events) {
            return;
        }

        $event_name = $this->normalizeToken($name);
        if ($event_name === '') {
            return;
        }

        $event = [
            'name' => $event_name,
            'elapsedMs' => round((microtime(true) - $this->started_at) * 1000, 3),
            'context' => $this->sanitizeContext($context),
        ];

        $this->events[] = $event;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummary()
    {
        if (! $this->enabled) {
            return [];
        }

        $timings = [];
        foreach ($this->timings as $key => $timing) {
            $calls = isset($timing['calls']) ? max(1, (int) $timing['calls']) : 1;
            $total_ms = isset($timing['totalMs']) ? (float) $timing['totalMs'] : 0.0;
            $timings[$key] = [
                'calls' => $calls,
                'totalMs' => round($total_ms, 3),
                'maxMs' => isset($timing['maxMs']) ? round((float) $timing['maxMs'], 3) : 0.0,
                'avgMs' => round($total_ms / $calls, 3),
            ];
        }

        ksort($this->counters);
        ksort($timings);
        ksort($this->values);

        return [
            'requestId' => $this->request_id,
            'method' => $this->resolveRequestMethod(),
            'path' => $this->resolveRequestPath(),
            'elapsedMs' => round((microtime(true) - $this->started_at) * 1000, 3),
            'counters' => $this->counters,
            'timings' => $timings,
            'values' => $this->values,
            'events' => $this->events,
        ];
    }

    /**
     * @return void
     */
    public function flush()
    {
        if (! $this->enabled || $this->flushed) {
            return;
        }

        $this->flushed = true;
        $summary = $this->buildSummary();
        if (empty($summary)) {
            return;
        }

        do_action('dbvc_visual_editor_performance_profile_summary', $summary, $this);

        if ((bool) apply_filters('dbvc_visual_editor_performance_profile_log', true, $summary)) {
            error_log('[DBVC VE Profile] ' . wp_json_encode($summary));
        }
    }

    /**
     * @return string
     */
    private function buildRequestId()
    {
        $seed = (string) $this->started_at;
        if (function_exists('wp_generate_password')) {
            $seed .= '|' . wp_generate_password(12, false, false);
        } else {
            $seed .= '|' . uniqid('', true);
        }

        return substr(hash('sha256', $seed), 0, 12);
    }

    /**
     * @return int
     */
    private function resolveMaxEvents()
    {
        $max_events = (int) apply_filters('dbvc_visual_editor_performance_profile_max_events', 50);

        if ($max_events < 0) {
            return 0;
        }

        return min($max_events, 200);
    }

    /**
     * @param string               $name
     * @param array<string, mixed> $tags
     * @return string
     */
    private function buildMetricKey($name, array $tags)
    {
        $metric = $this->normalizeToken($name);
        if ($metric === '') {
            return '';
        }

        if (empty($tags)) {
            return $metric;
        }

        ksort($tags);
        $parts = [];
        foreach ($tags as $key => $value) {
            $tag_key = $this->normalizeToken((string) $key);
            $tag_value = $this->normalizeToken((string) $value);
            if ($tag_key === '' || $tag_value === '') {
                continue;
            }

            $parts[] = $tag_key . ':' . $tag_value;
        }

        return empty($parts) ? $metric : $metric . '{' . implode(',', $parts) . '}';
    }

    /**
     * @param string $value
     * @return string
     */
    private function normalizeToken($value)
    {
        $value = strtolower((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9_.:-]+/', '_', $value);
        $value = is_string($value) ? trim($value, '_.:-') : '';

        return substr($value, 0, 80);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context)
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $context_key = $this->normalizeToken((string) $key);
            if ($context_key === '') {
                continue;
            }

            if (is_bool($value)) {
                $sanitized[$context_key] = $value;
            } elseif (is_numeric($value)) {
                $sanitized[$context_key] = strpos((string) $value, '.') === false ? (int) $value : (float) $value;
            } elseif (is_scalar($value)) {
                $sanitized[$context_key] = sanitize_text_field(substr((string) $value, 0, 120));
            }
        }

        return $sanitized;
    }

    /**
     * @return string
     */
    private function resolveRequestMethod()
    {
        return isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_key((string) $_SERVER['REQUEST_METHOD'])
            : '';
    }

    /**
     * @return string
     */
    private function resolveRequestPath()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = strtok($request_uri, '?');
        $path = is_string($path) ? $path : '';

        return sanitize_text_field($path);
    }
}
