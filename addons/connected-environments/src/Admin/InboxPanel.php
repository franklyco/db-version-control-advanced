<?php

namespace Dbvc\Connected\Admin;

use Dbvc\Connected\Storage\InboxStore;
use Dbvc\Connected\Storage\PreparationStore;
use Dbvc\ConnectedProtocol\RoleGate;

/**
 * Read-only administrator view of the connector's inbox: the observations
 * the hub delivered to this environment (stored before acknowledgement,
 * never applied). Rendered inside DBVC's Add-ons tab when the gate is ready.
 */
final class InboxPanel
{
    private const ROW_LIMIT = 50;

    /**
     * @return void
     */
    public static function render()
    {
        if (! class_exists('DBVC_Connected_Environments_Addon') || \DBVC_Connected_Environments_Addon::get_gate_state() !== RoleGate::READY || ! current_user_can('manage_options')) {
            return;
        }
        $store = new InboxStore();
        $counts = $store->counts();
        $rows = array_reverse($store->all(500));
        $rows = array_slice($rows, 0, self::ROW_LIMIT);
        ?>
        <h4><?php esc_html_e('Received observations (inbox)', 'dbvc'); ?></h4>
        <p class="description"><?php echo esc_html(sprintf(__('%1$d received from %2$d source(s), %3$d awaiting acknowledgement. Received observations are stored for review and never applied automatically; newest first, up to %4$d rows.', 'dbvc'), (int) $counts['total'], (int) $counts['sources'], (int) $counts['unacked'], self::ROW_LIMIT)); ?></p>
        <?php if ($rows === []) : ?>
          <p class="description"><?php esc_html_e('Nothing received yet.', 'dbvc'); ?></p>
        <?php else : ?>
          <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Delivery', 'dbvc'); ?></th><th><?php esc_html_e('Source', 'dbvc'); ?></th><th><?php esc_html_e('Sequence', 'dbvc'); ?></th><th><?php esc_html_e('Domain', 'dbvc'); ?></th><th><?php esc_html_e('Instance', 'dbvc'); ?></th><th><?php esc_html_e('Profile', 'dbvc'); ?></th><th><?php esc_html_e('Exists', 'dbvc'); ?></th><th><?php esc_html_e('Complete', 'dbvc'); ?></th><th><?php esc_html_e('Hash', 'dbvc'); ?></th><th><?php esc_html_e('Received', 'dbvc'); ?></th><th><?php esc_html_e('Acked', 'dbvc'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
              <?php $projection = is_array($row['body']) && isset($row['body']['projection']) && is_array($row['body']['projection']) ? $row['body']['projection'] : []; ?>
              <tr>
                <td><?php echo esc_html((string) $row['delivery_id']); ?></td>
                <td><code><?php echo esc_html((string) $row['source_environment_id']); ?></code></td>
                <td><?php echo esc_html((string) $row['source_sequence']); ?></td>
                <td><?php echo esc_html((string) $row['domain']); ?></td>
                <td><code><?php echo esc_html((string) $row['instance_uid']); ?></code></td>
                <td><?php echo esc_html((string) $row['profile']); ?></td>
                <td><?php echo array_key_exists('exists', $projection) ? ($projection['exists'] ? esc_html__('yes', 'dbvc') : esc_html__('no', 'dbvc')) : ''; ?></td>
                <td><?php echo array_key_exists('complete', $projection) ? ($projection['complete'] ? esc_html__('yes', 'dbvc') : esc_html__('no', 'dbvc')) : ''; ?></td>
                <td><code><?php echo esc_html(substr((string) ($projection['hash'] ?? ''), 0, 12)); ?></code></td>
                <td><?php echo esc_html((string) $row['received_at']); ?></td>
                <td><?php echo esc_html((string) ($row['acked_at'] ?: __('pending', 'dbvc'))); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <?php
        $preparations = new PreparationStore();
        $receipts = $preparations->all(self::ROW_LIMIT);
        $receipt_counts = $preparations->counts();
        ?>
        <h4><?php esc_html_e('Prepare receipts (dry runs)', 'dbvc'); ?></h4>
        <p class="description"><?php echo esc_html(sprintf(__('%1$d produced, %2$d not yet accepted by the hub. Each receipt describes what applying a release here would change (exact patch or explicit blocker per object); nothing was written and a receipt is never permission to apply.', 'dbvc'), (int) $receipt_counts['total'], (int) $receipt_counts['unreported'])); ?></p>
        <?php if ($receipts !== []) : ?>
          <table class="widefat striped">
            <thead><tr><th><?php esc_html_e('Operation', 'dbvc'); ?></th><th><?php esc_html_e('Release', 'dbvc'); ?></th><th><?php esc_html_e('Outcome', 'dbvc'); ?></th><th><?php esc_html_e('Ready', 'dbvc'); ?></th><th><?php esc_html_e('No-op', 'dbvc'); ?></th><th><?php esc_html_e('Blocked', 'dbvc'); ?></th><th><?php esc_html_e('Prepared', 'dbvc'); ?></th><th><?php esc_html_e('Expires', 'dbvc'); ?></th><th><?php esc_html_e('Reported', 'dbvc'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($receipts as $receipt) : ?>
              <tr><td><code><?php echo esc_html((string) $receipt['operation_id']); ?></code></td><td><code><?php echo esc_html((string) $receipt['release_uid']); ?></code></td><td><?php echo esc_html((string) $receipt['outcome']); ?></td><td><?php echo esc_html((string) $receipt['items_ready']); ?></td><td><?php echo esc_html((string) $receipt['items_noop']); ?></td><td><?php echo esc_html((string) $receipt['items_blocked']); ?></td><td><?php echo esc_html((string) $receipt['prepared_at']); ?></td><td><?php echo esc_html((string) $receipt['expires_at']); ?></td><td><?php echo esc_html((string) ($receipt['reported_at'] ?: ((string) $receipt['report_error'] !== '' ? $receipt['report_error'] : __('pending', 'dbvc')))); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <?php
    }
}
