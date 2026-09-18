<?php

namespace Dbvc\VisualEditor\MediaManager;

/**
 * R2-H Phase 1 (Slice 4) — schedule the recurring Media Index reconcile.
 *
 * Prefers Action Scheduler (robust chunking + retries) when it is available, and
 * falls back to stock WP-Cron otherwise, so the reconcile runs on any site. The
 * reconcile callback is registered here; the work lives in {@see MediaIndexReconciler}.
 *
 * Slice 4b: it also drives the first-run chunked build ({@see MediaIndexBuilder}) as a
 * self-continuing drain — an Action Scheduler async chain when available, else WP-Cron
 * single-event chaining — that advances one chunk per run and re-arms until the build
 * is complete, then stops.
 */
final class MediaIndexScheduler
{
    public const BUILD_HOOK = 'dbvc_visual_editor_media_index_build';

    private const GROUP = 'dbvc-visual-editor';
    private const INTERVAL = HOUR_IN_SECONDS;
    private const BUILD_DELAY = MINUTE_IN_SECONDS;

    /**
     * @var MediaIndexReconciler
     */
    private $reconciler;

    /**
     * @var MediaIndexBuilder|null
     */
    private $builder;

    public function __construct(MediaIndexReconciler $reconciler, ?MediaIndexBuilder $builder = null)
    {
        $this->reconciler = $reconciler;
        $this->builder = $builder;
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action(MediaIndexReconciler::RECONCILE_HOOK, [$this->reconciler, 'run']);
        if ($this->builder !== null) {
            add_action(self::BUILD_HOOK, [$this, 'runBuild']);
        }
        $this->ensureScheduled();
        $this->ensureBuildScheduled();
    }

    /**
     * Drain callback: advance one build chunk and re-arm until the build is complete.
     *
     * @return void
     */
    public function runBuild()
    {
        if ($this->builder === null) {
            return;
        }

        $this->builder->runChunk();

        if ($this->builder->needsBuild()) {
            $this->scheduleBuildContinuation();
        }
    }

    /**
     * Kick off (or resume) the build drain when the index still needs building.
     *
     * @return void
     */
    public function ensureBuildScheduled()
    {
        if ($this->builder === null || ! $this->builder->needsBuild()) {
            return;
        }

        $this->scheduleBuildContinuation();
    }

    /**
     * Schedule the recurring reconcile if it is not already scheduled.
     *
     * @return void
     */
    public function ensureScheduled()
    {
        if ($this->hasActionScheduler()) {
            if (! as_has_scheduled_action(MediaIndexReconciler::RECONCILE_HOOK, [], self::GROUP)) {
                as_schedule_recurring_action(
                    time() + self::INTERVAL,
                    self::INTERVAL,
                    MediaIndexReconciler::RECONCILE_HOOK,
                    [],
                    self::GROUP
                );
            }

            return;
        }

        if (! wp_next_scheduled(MediaIndexReconciler::RECONCILE_HOOK)) {
            wp_schedule_event(time() + self::INTERVAL, 'hourly', MediaIndexReconciler::RECONCILE_HOOK);
        }
    }

    /**
     * Arm the next build chunk: an Action Scheduler async action (immediate) when
     * available, else a WP-Cron single event a short delay out. Both are re-armed by
     * runBuild() until the build completes, so a build drains without a recurring job.
     *
     * @return void
     */
    private function scheduleBuildContinuation()
    {
        if ($this->hasActionScheduler() && function_exists('as_enqueue_async_action')) {
            if (! as_has_scheduled_action(self::BUILD_HOOK, [], self::GROUP)) {
                as_enqueue_async_action(self::BUILD_HOOK, [], self::GROUP);
            }

            return;
        }

        if (! wp_next_scheduled(self::BUILD_HOOK)) {
            wp_schedule_single_event(time() + self::BUILD_DELAY, self::BUILD_HOOK);
        }
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action(MediaIndexReconciler::RECONCILE_HOOK, [$this->reconciler, 'run']);
        remove_action(self::BUILD_HOOK, [$this, 'runBuild']);

        if ($this->hasActionScheduler() && function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(MediaIndexReconciler::RECONCILE_HOOK, [], self::GROUP);
            as_unschedule_all_actions(self::BUILD_HOOK, [], self::GROUP);
        }

        $timestamp = wp_next_scheduled(MediaIndexReconciler::RECONCILE_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, MediaIndexReconciler::RECONCILE_HOOK);
        }
        $build_timestamp = wp_next_scheduled(self::BUILD_HOOK);
        if ($build_timestamp) {
            wp_unschedule_event($build_timestamp, self::BUILD_HOOK);
        }
    }

    /**
     * @return bool
     */
    private function hasActionScheduler()
    {
        return function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action');
    }
}
