<?php

namespace Dbvc\Connected;

use Dbvc\Connected\Admin\RestController as AdminRestController;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Capture\OptionSignalListener;
use Dbvc\Connected\Capture\PostSignalListener;
use Dbvc\Connected\Identity\InstanceIdentity;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\PreparationStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\Connected\Storage\InboxStore;
use Dbvc\Connected\Transport\InboxWorker;
use Dbvc\Connected\Transport\ReleaseWorker;
use Dbvc\Connected\Transport\OutboundWorker;
use Dbvc\Connected\Worker\ObservationWorker;

/**
 * Composition root for the enabled connector. Registers the schema upgrade,
 * save-side capture listeners and the background worker hook. It registers
 * no REST routes, no admin menus, no transport and no hub behaviour.
 */
final class Runtime
{
    /**
     * @var OptionSignalListener
     */
    private $listener;

    /**
     * @var PostSignalListener
     */
    private $post_listener;

    /**
     * @var ObservationWorker
     */
    private $worker;

    /**
     * @var OutboundWorker
     */
    private $outbound;

    /**
     * @var InboxWorker
     */
    private $inbox;

    /**
     * @var ReleaseWorker
     */
    private $release;

    /**
     * @var AdminRestController
     */
    private $admin_rest;

    /**
     * @var bool
     */
    private $registered = false;

    public function __construct()
    {
        $jobs = new JobStore();
        $capture = new DirtyCapture($jobs, DomainRegistry::domains());
        $this->listener = new OptionSignalListener($capture);
        $this->post_listener = new PostSignalListener($capture);
        $this->worker = new ObservationWorker($jobs, new ObjectStore(), new OutboxStore(), new StateStore(), new InstanceIdentity());
        $this->outbound = new OutboundWorker(new OutboxStore(), new StateStore());
        $this->inbox = new InboxWorker(new InboxStore(), new StateStore());
        $this->release = new ReleaseWorker(new ObjectStore(), new PreparationStore(), new StateStore());
        $this->admin_rest = new AdminRestController();
    }

    /**
     * @return void
     */
    public function register()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        add_action('plugins_loaded', [Schema::class, 'maybe_upgrade'], 15);
        if (did_action('plugins_loaded')) {
            Schema::maybe_upgrade();
        }

        // Capture and processing attach only to a ready schema; a held migration keeps the module inert.
        if (! Schema::is_ready()) {
            return;
        }

        $this->listener->register();
        $this->post_listener->register();
        add_action(DirtyCapture::CRON_HOOK, [$this->worker, 'run_from_cron']);
        add_action(DirtyCapture::DELIVERY_CRON_HOOK, [$this->outbound, 'run_from_cron']);
        add_action(DirtyCapture::INBOX_CRON_HOOK, [$this->inbox, 'run_from_cron']);
        // Release work (payload requests, prepare requests) rides the same outbound poll; no extra schedule.
        add_action(DirtyCapture::INBOX_CRON_HOOK, [$this->release, 'run_from_cron'], 20);
        // Administrator page routes (cookie + capability); public requests without a ready gate register nothing.
        add_action('rest_api_init', [$this->admin_rest, 'register_routes']);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        if (! $this->registered) {
            return;
        }
        $this->registered = false;

        remove_action('plugins_loaded', [Schema::class, 'maybe_upgrade'], 15);
        $this->listener->unregister();
        $this->post_listener->unregister();
        remove_action(DirtyCapture::CRON_HOOK, [$this->worker, 'run_from_cron']);
        remove_action(DirtyCapture::DELIVERY_CRON_HOOK, [$this->outbound, 'run_from_cron']);
        remove_action(DirtyCapture::INBOX_CRON_HOOK, [$this->inbox, 'run_from_cron']);
        remove_action(DirtyCapture::INBOX_CRON_HOOK, [$this->release, 'run_from_cron'], 20);
        remove_action('rest_api_init', [$this->admin_rest, 'register_routes']);
    }

    /**
     * @return ObservationWorker
     */
    public function worker()
    {
        return $this->worker;
    }

    /**
     * @return OutboundWorker
     */
    public function outbound()
    {
        return $this->outbound;
    }

    /**
     * @return InboxWorker
     */
    public function inbox()
    {
        return $this->inbox;
    }

    /**
     * @return ReleaseWorker
     */
    public function release()
    {
        return $this->release;
    }

    /**
     * @return array<string, mixed>
     */
    public function describe()
    {
        return [
            'state' => $this->registered ? 'registered' : 'unregistered',
            'registered' => $this->registered,
            'capabilities' => ['capture' => true, 'observe' => true, 'transport' => true, 'inbox' => true, 'release_payloads' => true, 'prepare' => true, 'apply' => \DBVC_Connected_Environments_Addon::is_apply_enabled()],
        ];
    }
}
