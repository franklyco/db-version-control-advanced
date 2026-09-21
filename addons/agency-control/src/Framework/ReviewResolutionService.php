<?php

namespace Dbvc\AgencyControl\Framework;

use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\ReviewStore;

/**
 * Resolves framework review items through the framework status report
 * instead of a second classifier. Each open item is stamped with the drift
 * and version state its instance currently falls under; an item whose
 * observation has been superseded by a newer projection, or whose instance
 * reads clean/current or approved-and-current, is closed automatically.
 * Everything else stays `classified` until an operator resolves it with a
 * note. No content, adoption, definition or override is touched here.
 */
final class ReviewResolutionService
{
    public const RESOLUTION_CLEAN = 'clean';
    public const RESOLUTION_APPROVED_OVERRIDE = 'approved_override';
    public const RESOLUTION_SUPERSEDED = 'superseded';
    public const RESOLUTION_OPERATOR = 'operator';

    /**
     * @var ReviewStore
     */
    private $reviews;

    /**
     * @var FrameworkStatusService
     */
    private $status;

    /**
     * @var ProjectionStore
     */
    private $projections;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct()
    {
        $this->reviews = new ReviewStore();
        $this->status = new FrameworkStatusService();
        $this->projections = new ProjectionStore();
        $this->environments = new EnvironmentRegistry();
    }

    /**
     * @param string|null $environment_id
     * @param string|null $definition_uid
     * @return array<string, mixed>
     */
    public function classify($environment_id = null, $definition_uid = null)
    {
        $summary = ['examined' => 0, 'classified' => 0, 'resolved' => 0, 'unmatched' => 0, 'resolutions' => [], 'items' => []];
        $rows = [];
        foreach ($this->status->status($environment_id, $definition_uid)['rows'] as $row) {
            $rows[$row['environment_id'] . '|' . $row['domain'] . '|' . $row['instance_uid'] . '|' . $row['definition_uid']] = $row;
        }
        $environments = [];

        // Collect before stamping so an item moved to `classified` in this run is not examined twice.
        $items = [];
        foreach ([ReviewStore::STATE_OBSERVED, ReviewStore::STATE_CLASSIFIED] as $state) {
            foreach ($this->reviews->all($definition_uid, 5000, $state, $environment_id) as $item) {
                $items[$item['review_item_id']] = $item;
            }
        }
        ksort($items);
        foreach ($items as $item) {
            $summary['examined']++;
            $key = $item['environment_id'] . '|' . $item['domain'] . '|' . $item['instance_uid'] . '|' . $item['definition_uid'];
            if (! isset($rows[$key])) {
                // No enabled framework subscription reports this instance any more; leave the raw item untouched.
                $summary['unmatched']++;
                continue;
            }
            $row = $rows[$key];
            $resolution = null;
            if ($this->is_superseded($item, $row, $environments)) {
                $resolution = self::RESOLUTION_SUPERSEDED;
            } elseif ($row['drift'] === 'clean' && $row['version'] === 'current') {
                $resolution = self::RESOLUTION_CLEAN;
            } elseif ($row['drift'] === 'approved_override' && $row['version'] === 'current' && $row['override_state'] === OverrideStore::STATE_APPROVED) {
                $resolution = self::RESOLUTION_APPROVED_OVERRIDE;
            }
            $this->reviews->classify($item['review_item_id'], $row['drift'], $row['version'], $resolution, $resolution === null ? '' : 'auto:' . $resolution, 0);
            if ($resolution === null) {
                $summary['classified']++;
            } else {
                $summary['resolved']++;
                $summary['resolutions'][$resolution] = ($summary['resolutions'][$resolution] ?? 0) + 1;
            }
            $summary['items'][] = ['review_item_id' => $item['review_item_id'], 'environment_id' => $item['environment_id'], 'instance_uid' => $item['instance_uid'], 'definition_uid' => $item['definition_uid'], 'drift' => $row['drift'], 'version' => $row['version'], 'state' => $resolution === null ? ReviewStore::STATE_CLASSIFIED : ReviewStore::STATE_RESOLVED, 'resolution' => (string) $resolution];
        }

        return $summary;
    }

    /**
     * @param int    $review_item_id
     * @param string $note
     * @return array<string, mixed>|\WP_Error
     */
    public function resolve($review_item_id, $note = '')
    {
        $item = $this->reviews->get((int) $review_item_id);
        if ($item === null) {
            return new \WP_Error('dbvc_agency_review_not_found', 'Review item not found.', ['status' => 404]);
        }
        if ($item['state'] === ReviewStore::STATE_RESOLVED) {
            return ['review_item_id' => $item['review_item_id'], 'state' => $item['state'], 'resolution' => $item['resolution'], 'changed' => false];
        }
        $note = trim((string) $note);
        if ($note === '') {
            return new \WP_Error('dbvc_agency_note_required', 'An operator resolution records why the item needs no further action.', ['status' => 400]);
        }
        $this->reviews->classify($item['review_item_id'], (string) $item['drift'], (string) $item['version_state'], self::RESOLUTION_OPERATOR, $note, get_current_user_id());

        return ['review_item_id' => $item['review_item_id'], 'previous_state' => $item['state'], 'state' => ReviewStore::STATE_RESOLVED, 'resolution' => self::RESOLUTION_OPERATOR, 'changed' => true];
    }

    /**
     * A newer projection of the same instance exists (or the epoch moved on), so this item's observation is history.
     *
     * @param array<string, mixed>                     $item
     * @param array<string, mixed>                     $row
     * @param array<string, array<string, mixed>|null> $environments
     * @return bool
     */
    private function is_superseded(array $item, array $row, array &$environments)
    {
        if ($item['event_sequence'] <= 0 || $item['installation_epoch'] === '') {
            return false;
        }
        if (! array_key_exists($item['environment_id'], $environments)) {
            $environments[$item['environment_id']] = $this->environments->find($item['environment_id']);
        }
        $environment = $environments[$item['environment_id']];
        if ($environment === null) {
            return false;
        }
        if ($environment['current_epoch'] !== $item['installation_epoch']) {
            return true;
        }

        return $row['observed_sequence'] > $item['event_sequence'];
    }
}
