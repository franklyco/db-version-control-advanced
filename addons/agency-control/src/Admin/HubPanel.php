<?php

namespace Dbvc\AgencyControl\Admin;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Enrollment\EnrollmentService;
use Dbvc\AgencyControl\Framework\FrameworkStatusService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\InvitationStore;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\ReviewStore;
use Dbvc\AgencyControl\Storage\Schema;
use Dbvc\ConnectedProtocol\RoleGate;

/**
 * Administrator surface for the hub inside DBVC's Add-ons tab: read-only
 * tables (environments, open invitations, framework status, review items)
 * and one operator action, creating an enrollment invitation. The token is
 * shown exactly once, in a notice on the next page load, and never stored
 * in clear. Nothing here applies content or changes hub records other than
 * the invitation row.
 */
final class HubPanel
{
    public const ACTION_INVITE = 'dbvc_agency_invite';
    public const FORM_ID = 'dbvc-agency-invite-form';
    private const TRANSIENT_PREFIX = 'dbvc_agency_invite_';
    private const ROW_LIMIT = 50;

    /**
     * @return void
     */
    public static function register()
    {
        add_action('admin_post_' . self::ACTION_INVITE, [self::class, 'handle_invite']);
        add_action('admin_notices', [self::class, 'render_invite_notice']);
        add_action('admin_footer', [self::class, 'render_invite_form_element']);
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('admin_post_' . self::ACTION_INVITE, [self::class, 'handle_invite']);
        remove_action('admin_notices', [self::class, 'render_invite_notice']);
        remove_action('admin_footer', [self::class, 'render_invite_form_element']);
    }

    /**
     * @return bool
     */
    private static function on_dbvc_page()
    {
        if (! function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();

        return $screen instanceof \WP_Screen && strpos((string) $screen->id, 'dbvc-export') !== false;
    }

    /**
     * admin-post handler: create one invitation and redirect back with the token held for one display.
     *
     * @return void
     */
    public static function handle_invite()
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION_INVITE);
        wp_safe_redirect(self::process_invite_request(wp_unslash($_POST)));
        exit;
    }

    /**
     * Create the invitation for a verified administrator request and hold the
     * outcome (token or error) for exactly one display by that user.
     *
     * @param array<string, mixed> $post Unslashed request fields.
     * @return string Redirect URL.
     */
    public static function process_invite_request(array $post)
    {
        $redirect = admin_url('admin.php?page=dbvc-export#dbvc-addon-connected');
        $key = self::TRANSIENT_PREFIX . get_current_user_id();

        if (! class_exists('DBVC_Agency_Control_Addon') || \DBVC_Agency_Control_Addon::get_gate_state() !== RoleGate::READY) {
            set_transient($key, ['error' => __('The Agency Control hub is not ready (enable it and reload first).', 'dbvc')], 120);

            return $redirect;
        }

        $ttl = isset($post['dbvc_agency_invite_ttl']) ? (int) $post['dbvc_agency_invite_ttl'] : InvitationStore::DEFAULT_TTL_SECONDS;
        $result = (new EnrollmentService())->invite([
            'agency_id' => sanitize_text_field((string) ($post['dbvc_agency_invite_agency'] ?? 'studio')) ?: 'studio',
            'client_id' => sanitize_text_field((string) ($post['dbvc_agency_invite_client'] ?? '')),
            'environment_label' => sanitize_text_field((string) ($post['dbvc_agency_invite_label'] ?? '')),
            'environment_id' => sanitize_text_field((string) ($post['dbvc_agency_invite_environment'] ?? '')),
            'ttl_seconds' => $ttl > 0 ? $ttl : InvitationStore::DEFAULT_TTL_SECONDS,
        ]);
        if (is_wp_error($result)) {
            set_transient($key, ['error' => $result->get_error_message()], 120);
        } else {
            // Held for the next page load only; the hub keeps a hash of the token, never the token.
            set_transient($key, [
                'token' => (string) $result['token'],
                'invitation_id' => (int) $result['invitation_id'],
                'client_id' => (string) $result['client_id'],
                'environment_id' => (string) $result['environment_id'],
                'expires_at' => (string) $result['expires_at'],
                'hub_url' => (string) $result['hub_url'],
            ], 120);
        }

        return $redirect;
    }

    /**
     * @return void
     */
    public static function render_invite_notice()
    {
        if (! self::on_dbvc_page() || ! current_user_can('manage_options')) {
            return;
        }
        $key = self::TRANSIENT_PREFIX . get_current_user_id();
        $held = get_transient($key);
        if (! is_array($held)) {
            return;
        }
        delete_transient($key);
        if (isset($held['error'])) {
            printf('<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html((string) $held['error']));
            return;
        }
        $command = sprintf('wp dbvc connected enroll --hub=%s --token=%s', (string) $held['hub_url'], (string) $held['token']);
        echo '<div class="notice notice-success"><p><strong>' . esc_html__('Enrollment invitation created. The token is shown once; copy it now.', 'dbvc') . '</strong></p>';
        printf(
            '<p>%s <code>%s</code> · %s <code>%s</code>%s · %s %s</p>',
            esc_html__('Invitation', 'dbvc'),
            esc_html((string) $held['invitation_id']),
            esc_html__('client', 'dbvc'),
            esc_html((string) $held['client_id']),
            $held['environment_id'] !== '' ? ' · ' . esc_html__('environment', 'dbvc') . ' <code>' . esc_html((string) $held['environment_id']) . '</code>' : '',
            esc_html__('expires', 'dbvc'),
            esc_html((string) $held['expires_at'])
        );
        printf('<p><label for="dbvc-agency-invite-token">%s</label> <input type="text" id="dbvc-agency-invite-token" class="regular-text code" readonly value="%s" onfocus="this.select();" /></p>', esc_html__('Token', 'dbvc'), esc_attr((string) $held['token']));
        printf('<p>%s <code>%s</code></p></div>', esc_html__('On the client site:', 'dbvc'), esc_html($command));
    }

    /**
     * The invitation form element lives outside DBVC's settings form; the
     * panel's fields reference it through the HTML `form` attribute.
     *
     * @return void
     */
    public static function render_invite_form_element()
    {
        if (! self::on_dbvc_page() || ! current_user_can('manage_options')) {
            return;
        }
        printf('<form id="%s" method="post" action="%s"><input type="hidden" name="action" value="%s" />', esc_attr(self::FORM_ID), esc_url(admin_url('admin-post.php')), esc_attr(self::ACTION_INVITE));
        wp_nonce_field(self::ACTION_INVITE);
        echo '</form>';
    }

    /**
     * Panel body, rendered inside the Add-ons tab when the hub gate is ready.
     *
     * @return void
     */
    public static function render()
    {
        if (! class_exists('DBVC_Agency_Control_Addon') || \DBVC_Agency_Control_Addon::get_gate_state() !== RoleGate::READY || ! current_user_can('manage_options')) {
            return;
        }
        $freshness = ComparisonService::freshness_seconds();
        $environments = (new EnvironmentRegistry())->all(null, self::ROW_LIMIT);
        $invitations = array_values(array_filter((new InvitationStore())->all(self::ROW_LIMIT), static function ($row) {
            return empty($row['consumed_at']) && strtotime((string) $row['expires_at'] . ' UTC') > time();
        }));
        $status = (new FrameworkStatusService())->status();
        $reviews = new ReviewStore();
        $review_counts = $reviews->counts();
        $open_reviews = array_merge($reviews->all(null, self::ROW_LIMIT, ReviewStore::STATE_CLASSIFIED), $reviews->all(null, self::ROW_LIMIT, ReviewStore::STATE_OBSERVED));
        $open_reviews = array_slice($open_reviews, 0, self::ROW_LIMIT);
        $release_store = new ReleaseStore();
        $releases = $release_store->all(null, self::ROW_LIMIT);
        $release_counts = $release_store->counts();
        $preparation_store = new PreparationStore();
        $preparations = $preparation_store->all(null, null, self::ROW_LIMIT);
        $preparation_counts = $preparation_store->counts();
        ?>
        <div class="dbvc-agency-panel">
          <h4><?php esc_html_e('Environments', 'dbvc'); ?></h4>
          <?php if ($environments === []) : ?>
            <p class="description"><?php esc_html_e('No environment is enrolled yet.', 'dbvc'); ?></p>
          <?php else : ?>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Environment', 'dbvc'); ?></th><th><?php esc_html_e('Client', 'dbvc'); ?></th><th><?php esc_html_e('Label', 'dbvc'); ?></th><th><?php esc_html_e('Status', 'dbvc'); ?></th><th><?php esc_html_e('Epoch', 'dbvc'); ?></th><th><?php esc_html_e('Last contact', 'dbvc'); ?></th><th><?php esc_html_e('Fresh', 'dbvc'); ?></th><th><?php esc_html_e('Received', 'dbvc'); ?></th></tr></thead>
              <tbody>
              <?php foreach ($environments as $environment) : ?>
                <?php $fresh = $environment['status'] === EnvironmentRegistry::STATUS_ENABLED && ! empty($environment['last_contact_at']) && (time() - (int) strtotime((string) $environment['last_contact_at'] . ' UTC')) <= $freshness; ?>
                <tr>
                  <td><code><?php echo esc_html((string) $environment['environment_id']); ?></code></td>
                  <td><?php echo esc_html((string) $environment['client_id']); ?></td>
                  <td><?php echo esc_html((string) $environment['label']); ?></td>
                  <td><?php echo esc_html((string) $environment['status']); ?><?php echo ! empty($environment['hold_reason']) ? ' (' . esc_html((string) $environment['hold_reason']) . ')' : ''; ?></td>
                  <td><code><?php echo esc_html((string) $environment['current_epoch']); ?></code></td>
                  <td><?php echo esc_html((string) ($environment['last_contact_at'] ?: __('never', 'dbvc'))); ?></td>
                  <td><?php echo $fresh ? esc_html__('yes', 'dbvc') : esc_html__('no', 'dbvc'); ?></td>
                  <td><?php echo esc_html((string) (int) $environment['received_events']); ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h4><?php esc_html_e('Invite an environment', 'dbvc'); ?></h4>
          <p class="description"><?php esc_html_e('Creates a single-use, expiring invitation. The token appears once after this page reloads; the client runs the printed command to enroll. No site is contacted by the hub.', 'dbvc'); ?></p>
          <table class="form-table" role="presentation">
            <tr><th scope="row"><label for="dbvc-agency-invite-client"><?php esc_html_e('Client id', 'dbvc'); ?></label></th><td><input type="text" id="dbvc-agency-invite-client" name="dbvc_agency_invite_client" form="<?php echo esc_attr(self::FORM_ID); ?>" class="regular-text code" required pattern="[A-Za-z0-9._:-]{1,128}" /></td></tr>
            <tr><th scope="row"><label for="dbvc-agency-invite-label"><?php esc_html_e('Environment label', 'dbvc'); ?></label></th><td><input type="text" id="dbvc-agency-invite-label" name="dbvc_agency_invite_label" form="<?php echo esc_attr(self::FORM_ID); ?>" class="regular-text" /></td></tr>
            <tr><th scope="row"><label for="dbvc-agency-invite-environment"><?php esc_html_e('Fixed environment id (optional)', 'dbvc'); ?></label></th><td><input type="text" id="dbvc-agency-invite-environment" name="dbvc_agency_invite_environment" form="<?php echo esc_attr(self::FORM_ID); ?>" class="regular-text code" pattern="[A-Za-z0-9._:-]{1,128}" /></td></tr>
            <tr><th scope="row"><label for="dbvc-agency-invite-ttl"><?php esc_html_e('Valid for (seconds)', 'dbvc'); ?></label></th><td><input type="number" id="dbvc-agency-invite-ttl" name="dbvc_agency_invite_ttl" form="<?php echo esc_attr(self::FORM_ID); ?>" class="small-text" min="60" value="<?php echo esc_attr((string) InvitationStore::DEFAULT_TTL_SECONDS); ?>" /></td></tr>
            <tr><th scope="row"></th><td><input type="hidden" name="dbvc_agency_invite_agency" value="studio" form="<?php echo esc_attr(self::FORM_ID); ?>" /><button type="submit" class="button button-secondary" form="<?php echo esc_attr(self::FORM_ID); ?>"><?php esc_html_e('Create invitation', 'dbvc'); ?></button></td></tr>
          </table>

          <h4><?php esc_html_e('Open invitations', 'dbvc'); ?></h4>
          <?php if ($invitations === []) : ?>
            <p class="description"><?php esc_html_e('None.', 'dbvc'); ?></p>
          <?php else : ?>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Id', 'dbvc'); ?></th><th><?php esc_html_e('Client', 'dbvc'); ?></th><th><?php esc_html_e('Label', 'dbvc'); ?></th><th><?php esc_html_e('Environment', 'dbvc'); ?></th><th><?php esc_html_e('Expires', 'dbvc'); ?></th><th><?php esc_html_e('Created', 'dbvc'); ?></th></tr></thead>
              <tbody>
              <?php foreach ($invitations as $invitation) : ?>
                <tr><td><?php echo esc_html((string) $invitation['invitation_id']); ?></td><td><?php echo esc_html((string) $invitation['client_id']); ?></td><td><?php echo esc_html((string) $invitation['environment_label']); ?></td><td><code><?php echo esc_html((string) $invitation['environment_id']); ?></code></td><td><?php echo esc_html((string) $invitation['expires_at']); ?></td><td><?php echo esc_html((string) $invitation['created_at']); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h4><?php esc_html_e('Framework status', 'dbvc'); ?></h4>
          <p class="description"><?php echo esc_html(sprintf(__('Drift: %1$d clean, %2$d local drift, %3$d approved override, %4$d override changed, %5$d unknown · Version: %6$d current, %7$d behind, %8$d ahead, %9$d channel mismatch, %10$d unknown · %11$d override(s) need a rebase review. Reporting only; nothing is applied.', 'dbvc'), (int) $status['counts']['drift']['clean'], (int) $status['counts']['drift']['local_drift'], (int) $status['counts']['drift']['approved_override'], (int) $status['counts']['drift']['override_changed'], (int) $status['counts']['drift']['unknown'], (int) $status['counts']['version']['current'], (int) $status['counts']['version']['behind_version'], (int) $status['counts']['version']['ahead_version'], (int) $status['counts']['version']['channel_mismatch'], (int) $status['counts']['version']['unknown'], (int) $status['counts']['rebase_review'])); ?></p>
          <?php if ($status['rows'] === []) : ?>
            <p class="description"><?php esc_html_e('No enabled framework subscription.', 'dbvc'); ?></p>
          <?php else : ?>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Environment', 'dbvc'); ?></th><th><?php esc_html_e('Domain', 'dbvc'); ?></th><th><?php esc_html_e('Instance', 'dbvc'); ?></th><th><?php esc_html_e('Definition', 'dbvc'); ?></th><th><?php esc_html_e('Adopted', 'dbvc'); ?></th><th><?php esc_html_e('Desired', 'dbvc'); ?></th><th><?php esc_html_e('Drift', 'dbvc'); ?></th><th><?php esc_html_e('Version', 'dbvc'); ?></th><th><?php esc_html_e('Override', 'dbvc'); ?></th><th><?php esc_html_e('Reasons', 'dbvc'); ?></th></tr></thead>
              <tbody>
              <?php foreach (array_slice($status['rows'], 0, self::ROW_LIMIT) as $row) : ?>
                <tr><td><code><?php echo esc_html((string) $row['environment_id']); ?></code></td><td><?php echo esc_html((string) $row['domain']); ?></td><td><code><?php echo esc_html((string) $row['instance_uid']); ?></code></td><td><code><?php echo esc_html((string) $row['definition_uid']); ?></code> <small>(<?php echo esc_html((string) $row['channel']); ?>)</small></td><td><?php echo esc_html((string) $row['adopted_version']); ?></td><td><?php echo esc_html((string) $row['desired_version']); ?></td><td><?php echo esc_html((string) $row['drift']); ?></td><td><?php echo esc_html((string) $row['version']); ?></td><td><?php echo esc_html((string) $row['override_state']); ?></td><td><?php echo esc_html(implode(', ', (array) $row['reasons'])); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h4><?php esc_html_e('Framework review items', 'dbvc'); ?></h4>
          <p class="description"><?php echo esc_html(sprintf(__('%1$d total: %2$d observed, %3$d classified, %4$d resolved. Classify and resolve with `wp dbvc agency review-classify` / `review-resolve`.', 'dbvc'), (int) ($review_counts['total'] ?? 0), (int) ($review_counts[ReviewStore::STATE_OBSERVED] ?? 0), (int) ($review_counts[ReviewStore::STATE_CLASSIFIED] ?? 0), (int) ($review_counts[ReviewStore::STATE_RESOLVED] ?? 0))); ?></p>
          <?php if ($open_reviews !== []) : ?>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Id', 'dbvc'); ?></th><th><?php esc_html_e('Environment', 'dbvc'); ?></th><th><?php esc_html_e('Domain', 'dbvc'); ?></th><th><?php esc_html_e('Instance', 'dbvc'); ?></th><th><?php esc_html_e('Definition', 'dbvc'); ?></th><th><?php esc_html_e('Sequence', 'dbvc'); ?></th><th><?php esc_html_e('State', 'dbvc'); ?></th><th><?php esc_html_e('Drift', 'dbvc'); ?></th><th><?php esc_html_e('Version', 'dbvc'); ?></th><th><?php esc_html_e('Created', 'dbvc'); ?></th></tr></thead>
              <tbody>
              <?php foreach ($open_reviews as $item) : ?>
                <tr><td><?php echo esc_html((string) $item['review_item_id']); ?></td><td><code><?php echo esc_html((string) $item['environment_id']); ?></code></td><td><?php echo esc_html((string) $item['domain']); ?></td><td><code><?php echo esc_html((string) $item['instance_uid']); ?></code></td><td><code><?php echo esc_html((string) $item['definition_uid']); ?></code></td><td><?php echo esc_html((string) $item['event_sequence']); ?></td><td><?php echo esc_html((string) $item['state']); ?></td><td><?php echo esc_html((string) $item['drift']); ?></td><td><?php echo esc_html((string) $item['version_state']); ?></td><td><?php echo esc_html((string) $item['created_at']); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
          <h4><?php esc_html_e('Releases', 'dbvc'); ?></h4>
          <p class="description"><?php echo esc_html(sprintf(__('%1$d total: %2$d open (collecting payloads), %3$d sealed, %4$d withdrawn. Create with `wp dbvc agency release-create`; payloads arrive from the source connector and are verified by hash before a release seals.', 'dbvc'), (int) ($release_counts['total'] ?? 0), (int) ($release_counts[ReleaseStore::STATE_OPEN] ?? 0), (int) ($release_counts[ReleaseStore::STATE_SEALED] ?? 0), (int) ($release_counts[ReleaseStore::STATE_WITHDRAWN] ?? 0))); ?></p>
          <?php if ($releases !== []) : ?>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Release', 'dbvc'); ?></th><th><?php esc_html_e('Client', 'dbvc'); ?></th><th><?php esc_html_e('Source', 'dbvc'); ?></th><th><?php esc_html_e('State', 'dbvc'); ?></th><th><?php esc_html_e('Items', 'dbvc'); ?></th><th><?php esc_html_e('Digest', 'dbvc'); ?></th><th><?php esc_html_e('Note', 'dbvc'); ?></th><th><?php esc_html_e('Created', 'dbvc'); ?></th><th><?php esc_html_e('Sealed', 'dbvc'); ?></th></tr></thead>
              <tbody>
              <?php foreach ($releases as $release) : ?>
                <tr><td><code><?php echo esc_html((string) $release['release_uid']); ?></code></td><td><?php echo esc_html((string) $release['client_id']); ?></td><td><code><?php echo esc_html((string) $release['source_environment_id']); ?></code></td><td><?php echo esc_html((string) $release['state']); ?></td><td><?php echo esc_html((string) $release['item_count']); ?></td><td><code><?php echo esc_html(substr((string) $release['digest'], 0, 12)); ?></code></td><td><?php echo esc_html((string) $release['note']); ?></td><td><?php echo esc_html((string) $release['created_at']); ?></td><td><?php echo esc_html((string) ($release['sealed_at'] ?? '')); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>

          <h4><?php esc_html_e('Prepare receipts', 'dbvc'); ?></h4>
          <p class="description"><?php echo esc_html(sprintf(__('%1$d total: %2$d requested, %3$d received, %4$d cancelled. A receipt is a target\'s dry run (exact patch or explicit blocker per object); it expires and is never permission to write. Request with `wp dbvc agency prepare-request`; inspect with `preparations --operation`.', 'dbvc'), (int) ($preparation_counts['total'] ?? 0), (int) ($preparation_counts[PreparationStore::STATE_REQUESTED] ?? 0), (int) ($preparation_counts[PreparationStore::STATE_RECEIVED] ?? 0), (int) ($preparation_counts[PreparationStore::STATE_CANCELLED] ?? 0))); ?></p>
          <?php if ($preparations !== []) : ?>
            <table class="widefat striped">
              <thead><tr><th><?php esc_html_e('Operation', 'dbvc'); ?></th><th><?php esc_html_e('Release', 'dbvc'); ?></th><th><?php esc_html_e('Target', 'dbvc'); ?></th><th><?php esc_html_e('State', 'dbvc'); ?></th><th><?php esc_html_e('Outcome', 'dbvc'); ?></th><th><?php esc_html_e('Ready', 'dbvc'); ?></th><th><?php esc_html_e('No-op', 'dbvc'); ?></th><th><?php esc_html_e('Blocked', 'dbvc'); ?></th><th><?php esc_html_e('Requested', 'dbvc'); ?></th><th><?php esc_html_e('Received', 'dbvc'); ?></th><th><?php esc_html_e('Expires', 'dbvc'); ?></th></tr></thead>
              <tbody>
              <?php foreach ($preparations as $preparation) : ?>
                <tr><td><code><?php echo esc_html((string) $preparation['operation_id']); ?></code></td><td><code><?php echo esc_html((string) $preparation['release_uid']); ?></code></td><td><code><?php echo esc_html((string) $preparation['target_environment_id']); ?></code></td><td><?php echo esc_html((string) $preparation['state']); ?></td><td><?php echo esc_html((string) $preparation['outcome']); ?></td><td><?php echo esc_html((string) $preparation['items_ready']); ?></td><td><?php echo esc_html((string) $preparation['items_noop']); ?></td><td><?php echo esc_html((string) $preparation['items_blocked']); ?></td><td><?php echo esc_html((string) $preparation['requested_at']); ?></td><td><?php echo esc_html((string) ($preparation['received_at'] ?? '')); ?></td><td><?php echo esc_html((string) ($preparation['expires_at'] ?? '')); ?></td></tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
          <p><small class="description"><?php echo esc_html(sprintf(__('Hub URL %1$s · schema v%2$d · freshness window %3$d s. Full administration: `wp dbvc agency`.', 'dbvc'), home_url('/'), (int) get_option(Schema::OPTION_SCHEMA_VERSION, 0), $freshness)); ?></small></p>
        </div>
        <?php
    }
}
