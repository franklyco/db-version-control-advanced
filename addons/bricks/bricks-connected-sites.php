<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Connected_Sites
{
    public const OPTION_CONNECTED_SITES = 'dbvc_bricks_connected_sites';
    public const OPTION_SITE_ALIASES = 'dbvc_bricks_site_aliases';
    public const OPTION_SITE_SEQUENCE = 'dbvc_bricks_site_sequence';
    public const CONFLICT_DUPLICATE_BASE_URL = 'duplicate_base_url';
    public const RECENT_PULL_WINDOW_SECONDS = 900;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_sites()
    {
        $sites = get_option(self::OPTION_CONNECTED_SITES, []);
        return is_array($sites) ? $sites : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function get_aliases()
    {
        $raw = get_option(self::OPTION_SITE_ALIASES, []);
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $alias_uid => $row) {
            $alias_uid = sanitize_key((string) $alias_uid);
            if ($alias_uid === '') {
                continue;
            }
            if (is_string($row)) {
                $canonical = sanitize_key($row);
                if ($canonical === '' || $canonical === $alias_uid) {
                    continue;
                }
                $out[$alias_uid] = [
                    'alias_site_uid' => $alias_uid,
                    'canonical_site_uid' => $canonical,
                    'reason' => '',
                    'created_at' => '',
                    'updated_at' => '',
                    'auto_generated' => 0,
                ];
                continue;
            }
            if (! is_array($row)) {
                continue;
            }
            $canonical = sanitize_key((string) ($row['canonical_site_uid'] ?? ''));
            if ($canonical === '' || $canonical === $alias_uid) {
                continue;
            }
            $out[$alias_uid] = [
                'alias_site_uid' => $alias_uid,
                'canonical_site_uid' => $canonical,
                'reason' => sanitize_text_field((string) ($row['reason'] ?? '')),
                'created_at' => sanitize_text_field((string) ($row['created_at'] ?? '')),
                'updated_at' => sanitize_text_field((string) ($row['updated_at'] ?? '')),
                'auto_generated' => ! empty($row['auto_generated']) ? 1 : 0,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $aliases
     * @return void
     */
    private static function set_aliases(array $aliases)
    {
        update_option(self::OPTION_SITE_ALIASES, $aliases);
    }

    /**
     * @param string $site_uid
     * @return array<string, mixed>
     */
    public static function resolve_site_identity($site_uid)
    {
        $incoming = sanitize_key((string) $site_uid);
        if ($incoming === '') {
            return [
                'incoming_site_uid' => '',
                'resolved_site_uid' => '',
                'was_alias' => 0,
                'resolution_chain' => [],
                'resolution_error' => 'site_uid_required',
            ];
        }
        $aliases = self::get_aliases();
        $seen = [];
        $chain = [$incoming];
        $resolved = $incoming;
        $max_hops = 10;
        for ($i = 0; $i < $max_hops; $i++) {
            if (isset($seen[$resolved])) {
                return [
                    'incoming_site_uid' => $incoming,
                    'resolved_site_uid' => $resolved,
                    'was_alias' => $incoming !== $resolved ? 1 : 0,
                    'resolution_chain' => $chain,
                    'resolution_error' => 'alias_cycle_detected',
                ];
            }
            $seen[$resolved] = 1;
            if (! isset($aliases[$resolved]) || ! is_array($aliases[$resolved])) {
                break;
            }
            $next = sanitize_key((string) ($aliases[$resolved]['canonical_site_uid'] ?? ''));
            if ($next === '' || $next === $resolved) {
                break;
            }
            $resolved = $next;
            $chain[] = $resolved;
        }
        return [
            'incoming_site_uid' => $incoming,
            'resolved_site_uid' => $resolved,
            'was_alias' => $incoming !== $resolved ? 1 : 0,
            'resolution_chain' => $chain,
            'resolution_error' => '',
        ];
    }

    /**
     * @param string $alias_site_uid
     * @param string $canonical_site_uid
     * @param string $reason
     * @param bool $auto_generated
     * @return array<string, mixed>|\WP_Error
     */
    public static function set_known_alias($alias_site_uid, $canonical_site_uid, $reason = '', $auto_generated = false)
    {
        $alias_site_uid = sanitize_key((string) $alias_site_uid);
        $canonical_site_uid = sanitize_key((string) $canonical_site_uid);
        if ($alias_site_uid === '' || $canonical_site_uid === '' || $alias_site_uid === $canonical_site_uid) {
            return new \WP_Error('dbvc_bricks_alias_invalid', 'alias_site_uid and canonical_site_uid must be different non-empty values.', ['status' => 400]);
        }
        $sites = self::get_sites();
        if (! isset($sites[$canonical_site_uid]) || ! is_array($sites[$canonical_site_uid])) {
            return new \WP_Error('dbvc_bricks_alias_canonical_missing', 'Canonical site does not exist.', ['status' => 404]);
        }

        $aliases = self::get_aliases();
        foreach ($aliases as $existing_alias_uid => $row) {
            if (! is_array($row)) {
                continue;
            }
            $existing_canonical = sanitize_key((string) ($row['canonical_site_uid'] ?? ''));
            if ($existing_alias_uid === $alias_site_uid && $existing_canonical !== '' && $existing_canonical !== $canonical_site_uid) {
                return new \WP_Error('dbvc_bricks_alias_already_mapped', 'Alias is already mapped to another canonical UID.', ['status' => 409]);
            }
        }
        $probe = $canonical_site_uid;
        $max_hops = 10;
        for ($i = 0; $i < $max_hops; $i++) {
            if ($probe === $alias_site_uid) {
                return new \WP_Error('dbvc_bricks_alias_cycle_detected', 'Alias mapping would create a cycle.', ['status' => 400]);
            }
            if (! isset($aliases[$probe]) || ! is_array($aliases[$probe])) {
                break;
            }
            $probe = sanitize_key((string) ($aliases[$probe]['canonical_site_uid'] ?? ''));
            if ($probe === '') {
                break;
            }
        }

        $existing = isset($aliases[$alias_site_uid]) && is_array($aliases[$alias_site_uid]) ? $aliases[$alias_site_uid] : [];
        $aliases[$alias_site_uid] = [
            'alias_site_uid' => $alias_site_uid,
            'canonical_site_uid' => $canonical_site_uid,
            'reason' => sanitize_text_field((string) $reason),
            'created_at' => sanitize_text_field((string) ($existing['created_at'] ?? gmdate('c'))),
            'updated_at' => gmdate('c'),
            'auto_generated' => $auto_generated ? 1 : 0,
        ];
        self::set_aliases($aliases);
        return $aliases[$alias_site_uid];
    }

    /**
     * @param string $canonical_site_uid
     * @return array<int, string>
     */
    public static function get_aliases_for_canonical($canonical_site_uid)
    {
        $canonical_site_uid = sanitize_key((string) $canonical_site_uid);
        if ($canonical_site_uid === '') {
            return [];
        }
        $aliases = self::get_aliases();
        $out = [];
        foreach ($aliases as $alias_uid => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (sanitize_key((string) ($row['canonical_site_uid'] ?? '')) !== $canonical_site_uid) {
                continue;
            }
            $out[] = sanitize_key((string) $alias_uid);
        }
        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }

    /**
     * @param string $base_url
     * @return string
     */
    public static function normalize_base_url($base_url)
    {
        $base_url = trim((string) $base_url);
        if ($base_url === '') {
            return '';
        }
        $parts = wp_parse_url($base_url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = isset($parts['path']) ? (string) $parts['path'] : '';
        $path = trim($path);
        $path = trim($path, '/');
        $path = $path !== '' ? '/' . $path : '';
        return $scheme . '://' . $host . $path;
    }

    /**
     * @param array<string, array<string, mixed>>|null $sites
     * @return array<string, array<int, string>>
     */
    public static function get_duplicate_base_url_groups($sites = null)
    {
        if (! is_array($sites)) {
            $sites = self::get_sites();
        }
        $groups = [];
        foreach ($sites as $site) {
            if (! is_array($site)) {
                continue;
            }
            $site_uid = sanitize_key((string) ($site['site_uid'] ?? ''));
            if ($site_uid === '') {
                continue;
            }
            $normalized = self::normalize_base_url((string) ($site['base_url'] ?? ''));
            if ($normalized === '') {
                continue;
            }
            if (! isset($groups[$normalized])) {
                $groups[$normalized] = [];
            }
            if (! in_array($site_uid, $groups[$normalized], true)) {
                $groups[$normalized][] = $site_uid;
            }
        }
        foreach ($groups as $base => $uids) {
            if (count($uids) <= 1) {
                unset($groups[$base]);
                continue;
            }
            sort($uids, SORT_STRING);
            $groups[$base] = array_values($uids);
        }
        return $groups;
    }

    /**
     * @param array<int, string> $group_uids
     * @param array<string, array<string, mixed>> $sites
     * @return string
     */
    private static function pick_canonical_uid(array $group_uids, array $sites)
    {
        $canonical = '';
        $canonical_ts = 0;
        foreach ($group_uids as $uid) {
            $site = isset($sites[$uid]) && is_array($sites[$uid]) ? $sites[$uid] : [];
            $status = sanitize_key((string) ($site['status'] ?? 'online'));
            $allow = ! empty($site['allow_receive_packages']);
            $disabled = $status === 'disabled' || ! $allow;
            if ($disabled) {
                continue;
            }
            $updated_at = (string) ($site['updated_at'] ?? $site['last_seen_at'] ?? '');
            $ts = $updated_at !== '' ? (int) strtotime($updated_at) : 0;
            if ($canonical === '' || $ts > $canonical_ts || ($ts === $canonical_ts && strcmp((string) $uid, (string) $canonical) < 0)) {
                $canonical = (string) $uid;
                $canonical_ts = $ts;
            }
        }
        if ($canonical !== '') {
            return $canonical;
        }
        sort($group_uids, SORT_STRING);
        return isset($group_uids[0]) ? (string) $group_uids[0] : '';
    }

    /**
     * @param array<string, array<string, mixed>>|null $sites
     * @return array<string, array<string, mixed>>
     */
    public static function build_conflict_map($sites = null)
    {
        if (! is_array($sites)) {
            $sites = self::get_sites();
        }
        $conflicts = [];
        $groups = self::get_duplicate_base_url_groups($sites);
        foreach ($groups as $normalized_base_url => $uids) {
            $canonical_uid = self::pick_canonical_uid($uids, $sites);
            foreach ($uids as $uid) {
                $is_canonical = $uid === $canonical_uid;
                $conflicts[$uid] = [
                    'conflict_state' => self::CONFLICT_DUPLICATE_BASE_URL,
                    'normalized_base_url' => $normalized_base_url,
                    'group_site_uids' => $uids,
                    'canonical_site_uid' => $canonical_uid,
                    'is_canonical' => $is_canonical ? 1 : 0,
                    'is_targetable' => $is_canonical ? 1 : 0,
                    'error_code' => $is_canonical ? '' : 'site_uid_conflict_duplicate_base_url',
                ];
            }
        }
        return $conflicts;
    }

    /**
     * @param array<string, array<string, mixed>>|null $sites
     * @return array<int, array<string, mixed>>
     */
    public static function get_assisted_merge_candidates($sites = null)
    {
        if (! is_array($sites)) {
            $sites = self::get_sites();
        }
        $out = [];
        $groups = self::get_duplicate_base_url_groups($sites);
        foreach ($groups as $normalized_base_url => $uids) {
            $canonical_uid = self::pick_canonical_uid($uids, $sites);
            if ($canonical_uid === '' || ! isset($sites[$canonical_uid]) || ! is_array($sites[$canonical_uid])) {
                continue;
            }
            $canonical_site = $sites[$canonical_uid];
            $canonical_instance = sanitize_text_field((string) ($canonical_site['local_instance_uuid'] ?? ''));
            $canonical_snapshot = sanitize_text_field((string) ($canonical_site['site_title_host_snapshot'] ?? ''));
            foreach ($uids as $alias_uid) {
                if ($alias_uid === $canonical_uid || ! isset($sites[$alias_uid]) || ! is_array($sites[$alias_uid])) {
                    continue;
                }
                $alias_site = $sites[$alias_uid];
                $alias_instance = sanitize_text_field((string) ($alias_site['local_instance_uuid'] ?? ''));
                $alias_snapshot = sanitize_text_field((string) ($alias_site['site_title_host_snapshot'] ?? ''));
                $match_reasons = [];
                if ($canonical_instance !== '' && $alias_instance !== '' && hash_equals($canonical_instance, $alias_instance)) {
                    $match_reasons[] = 'local_instance_uuid_match';
                }
                if ($canonical_snapshot !== '' && $alias_snapshot !== '' && hash_equals($canonical_snapshot, $alias_snapshot)) {
                    $match_reasons[] = 'site_title_host_snapshot_match';
                }
                if (empty($match_reasons)) {
                    continue;
                }
                $token_seed = $canonical_uid . '|' . $alias_uid . '|' . $normalized_base_url . '|' . implode(',', $match_reasons);
                $out[] = [
                    'canonical_site_uid' => $canonical_uid,
                    'alias_site_uid' => $alias_uid,
                    'normalized_base_url' => $normalized_base_url,
                    'match_reasons' => $match_reasons,
                    'match_token' => substr(hash('sha256', $token_seed), 0, 16),
                ];
            }
        }
        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $sites
     * @param array<string, array<int, string>> $groups
     * @return array<string, array<string, mixed>>
     */
    private static function build_active_pull_map(array $sites, array $groups)
    {
        $out = [];
        $now = time();
        foreach ($groups as $uids) {
            $active_uid = '';
            $active_ts = 0;
            foreach ($uids as $uid) {
                if (! isset($sites[$uid]) || ! is_array($sites[$uid])) {
                    continue;
                }
                $last_pull_at = sanitize_text_field((string) ($sites[$uid]['last_pull_at'] ?? ''));
                if ($last_pull_at === '') {
                    continue;
                }
                $pull_ts = strtotime($last_pull_at);
                if ($pull_ts === false || $pull_ts < ($now - self::RECENT_PULL_WINDOW_SECONDS)) {
                    continue;
                }
                if ($active_uid === '' || $pull_ts > $active_ts) {
                    $active_uid = sanitize_key((string) $uid);
                    $active_ts = (int) $pull_ts;
                }
            }
            if ($active_uid === '') {
                continue;
            }
            $active_at = gmdate('c', $active_ts);
            foreach ($uids as $uid) {
                $uid = sanitize_key((string) $uid);
                if ($uid === '') {
                    continue;
                }
                $out[$uid] = [
                    'active_pull_site_uid' => $active_uid,
                    'active_pull_at' => $active_at,
                    'active_pull_mismatch' => $uid !== $active_uid ? 1 : 0,
                ];
            }
        }
        return $out;
    }

    /**
     * @param string $site_uid
     * @return array<string, mixed>
     */
    public static function get_enqueue_guard_status($site_uid)
    {
        $resolution = self::resolve_site_identity($site_uid);
        $incoming_site_uid = sanitize_key((string) ($resolution['incoming_site_uid'] ?? ''));
        $resolved_site_uid = sanitize_key((string) ($resolution['resolved_site_uid'] ?? ''));
        if ($resolved_site_uid === '') {
            return ['ok' => false, 'error_code' => 'site_uid_required'];
        }
        $site = self::get_site($resolved_site_uid);
        if (! is_array($site)) {
            return ['ok' => false, 'error_code' => 'site_uid_not_found'];
        }
        $status = sanitize_key((string) ($site['status'] ?? 'online'));
        if ($status === 'disabled') {
            return ['ok' => false, 'error_code' => 'site_disabled'];
        }
        if (empty($site['allow_receive_packages'])) {
            return ['ok' => false, 'error_code' => 'site_allow_receive_disabled'];
        }
        $onboarding_state = strtoupper(sanitize_text_field((string) ($site['onboarding_state'] ?? '')));
        if ($onboarding_state === 'PENDING_INTRO') {
            return ['ok' => false, 'error_code' => 'site_onboarding_pending_intro'];
        }
        if ($onboarding_state === 'REJECTED') {
            return ['ok' => false, 'error_code' => 'site_onboarding_rejected'];
        }
        if ($onboarding_state === 'DISABLED') {
            return ['ok' => false, 'error_code' => 'site_onboarding_disabled'];
        }

        $conflicts = self::build_conflict_map();
        if (isset($conflicts[$resolved_site_uid]) && is_array($conflicts[$resolved_site_uid])) {
            $conflict = $conflicts[$resolved_site_uid];
            if (empty($conflict['is_targetable'])) {
                return [
                    'ok' => false,
                    'error_code' => sanitize_key((string) ($conflict['error_code'] ?? 'site_uid_conflict_duplicate_base_url')),
                    'canonical_site_uid' => sanitize_key((string) ($conflict['canonical_site_uid'] ?? '')),
                    'normalized_base_url' => sanitize_text_field((string) ($conflict['normalized_base_url'] ?? '')),
                ];
            }
        }

        return [
            'ok' => true,
            'incoming_site_uid' => $incoming_site_uid,
            'resolved_site_uid' => $resolved_site_uid,
            'was_alias' => ! empty($resolution['was_alias']) ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $site
     * @param array<string, array<string, mixed>> $conflicts
     * @return array<string, mixed>
     */
    private static function decorate_site_with_conflicts(array $site, array $conflicts, array $assisted_by_alias = [], array $active_pull_by_site = [])
    {
        $site_uid = sanitize_key((string) ($site['site_uid'] ?? ''));
        $site['normalized_base_url'] = self::normalize_base_url((string) ($site['base_url'] ?? ''));
        $site['conflict_state'] = '';
        $site['conflict_group_uids'] = [];
        $site['canonical_site_uid'] = '';
        $site['is_conflict_canonical'] = 0;
        $site['is_targetable'] = 1;
        $site['targeting_error_code'] = '';
        $site['known_aliases'] = self::get_aliases_for_canonical($site_uid);
        $resolution = self::resolve_site_identity($site_uid);
        $site['canonical_site_uid'] = sanitize_key((string) ($resolution['resolved_site_uid'] ?? $site_uid));
        $site['assisted_merge_eligible'] = 0;
        $site['assisted_merge_match_reasons'] = [];
        $site['assisted_merge_match_token'] = '';
        $site['active_pull_site_uid'] = '';
        $site['active_pull_at'] = '';
        $site['active_pull_mismatch'] = 0;
        $site['routing_notice'] = '';

        if ($site_uid !== '' && isset($conflicts[$site_uid]) && is_array($conflicts[$site_uid])) {
            $conflict = $conflicts[$site_uid];
            $site['conflict_state'] = (string) ($conflict['conflict_state'] ?? '');
            $site['conflict_group_uids'] = isset($conflict['group_site_uids']) && is_array($conflict['group_site_uids'])
                ? array_values(array_map('sanitize_key', $conflict['group_site_uids']))
                : [];
            $site['canonical_site_uid'] = sanitize_key((string) ($conflict['canonical_site_uid'] ?? ''));
            $site['is_conflict_canonical'] = ! empty($conflict['is_canonical']) ? 1 : 0;
            if (empty($conflict['is_targetable'])) {
                $site['is_targetable'] = 0;
                $site['targeting_error_code'] = sanitize_key((string) ($conflict['error_code'] ?? 'site_uid_conflict_duplicate_base_url'));
            }
        }
        if ($site_uid !== '' && isset($assisted_by_alias[$site_uid]) && is_array($assisted_by_alias[$site_uid])) {
            $candidate = $assisted_by_alias[$site_uid];
            $site['assisted_merge_eligible'] = 1;
            $site['assisted_merge_match_reasons'] = isset($candidate['match_reasons']) && is_array($candidate['match_reasons']) ? array_values($candidate['match_reasons']) : [];
            $site['assisted_merge_match_token'] = sanitize_text_field((string) ($candidate['match_token'] ?? ''));
            $site['canonical_site_uid'] = sanitize_key((string) ($candidate['canonical_site_uid'] ?? $site['canonical_site_uid']));
        }
        if ($site_uid !== '' && isset($active_pull_by_site[$site_uid]) && is_array($active_pull_by_site[$site_uid])) {
            $pull = $active_pull_by_site[$site_uid];
            $active_uid = sanitize_key((string) ($pull['active_pull_site_uid'] ?? ''));
            $active_at = sanitize_text_field((string) ($pull['active_pull_at'] ?? ''));
            $site['active_pull_site_uid'] = $active_uid;
            $site['active_pull_at'] = $active_at;
            $site['active_pull_mismatch'] = (! empty($pull['active_pull_mismatch'])) ? 1 : 0;
            if (! empty($pull['active_pull_mismatch']) && $active_uid !== '' && $active_uid !== $site_uid) {
                $site['routing_notice'] = 'Recent pull activity is coming from UID "' . $active_uid . '". Merge/deactivate alias or map known alias before targeting this row.';
            }
        }
        return $site;
    }

    /**
     * @param string $site_uid
     * @return array<string, mixed>|null
     */
    public static function get_site($site_uid)
    {
        $sites = self::get_sites();
        $site_uid = sanitize_key((string) $site_uid);
        if ($site_uid === '' || ! isset($sites[$site_uid]) || ! is_array($sites[$site_uid])) {
            return null;
        }
        return $sites[$site_uid];
    }

    /**
     * @param string $incoming_site_uid
     * @param string $resolved_site_uid
     * @return void
     */
    public static function record_pull_activity($incoming_site_uid, $resolved_site_uid = '')
    {
        $incoming_site_uid = sanitize_key((string) $incoming_site_uid);
        $resolved_site_uid = sanitize_key((string) $resolved_site_uid);
        if ($incoming_site_uid === '' && $resolved_site_uid === '') {
            return;
        }
        if ($resolved_site_uid === '') {
            $resolved_site_uid = $incoming_site_uid;
        }
        $now = gmdate('c');
        if ($resolved_site_uid !== '') {
            $resolved_site = self::get_site($resolved_site_uid);
            if (is_array($resolved_site)) {
                self::upsert_site([
                    'site_uid' => $resolved_site_uid,
                    'last_pull_at' => $now,
                    'last_pull_incoming_uid' => $incoming_site_uid,
                    'last_seen_at' => $now,
                ]);
            }
        }
        if ($incoming_site_uid !== '' && $incoming_site_uid !== $resolved_site_uid) {
            $incoming_site = self::get_site($incoming_site_uid);
            if (is_array($incoming_site)) {
                self::upsert_site([
                    'site_uid' => $incoming_site_uid,
                    'last_pull_at' => $now,
                    'last_pull_incoming_uid' => $incoming_site_uid,
                    'last_seen_at' => $now,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function upsert_site(array $payload)
    {
        $site_uid = sanitize_key((string) ($payload['site_uid'] ?? ''));
        if ($site_uid === '') {
            return [];
        }

        $existing = self::get_site($site_uid);
        $allow_receive_packages = array_key_exists('allow_receive_packages', $payload)
            ? (! empty($payload['allow_receive_packages']) ? 1 : 0)
            : (! empty($existing['allow_receive_packages']) ? 1 : 0);
        $site = [
            'site_uid' => $site_uid,
            'site_label' => sanitize_text_field((string) ($payload['site_label'] ?? ($existing['site_label'] ?? $site_uid))),
            'base_url' => esc_url_raw((string) ($payload['base_url'] ?? ($existing['base_url'] ?? ''))),
            'status' => sanitize_key((string) ($payload['status'] ?? ($existing['status'] ?? 'online'))),
            'auth_mode' => sanitize_key((string) ($payload['auth_mode'] ?? ($existing['auth_mode'] ?? 'wp_app_password'))),
            'allow_receive_packages' => $allow_receive_packages,
            'onboarding_state' => strtoupper(sanitize_text_field((string) ($payload['onboarding_state'] ?? ($existing['onboarding_state'] ?? '')))),
            'onboarding_updated_at' => sanitize_text_field((string) ($payload['onboarding_updated_at'] ?? ($existing['onboarding_updated_at'] ?? ''))),
            'last_pull_at' => sanitize_text_field((string) ($payload['last_pull_at'] ?? ($existing['last_pull_at'] ?? ''))),
            'last_pull_incoming_uid' => sanitize_key((string) ($payload['last_pull_incoming_uid'] ?? ($existing['last_pull_incoming_uid'] ?? ''))),
            'last_seen_at' => sanitize_text_field((string) ($payload['last_seen_at'] ?? gmdate('c'))),
            'updated_at' => gmdate('c'),
        ];

        if ($site['site_label'] === '') {
            $site['site_label'] = $site_uid;
        }
        if (! in_array($site['status'], ['online', 'offline', 'disabled'], true)) {
            $site['status'] = 'online';
        }
        if (! in_array($site['auth_mode'], ['wp_app_password', 'api_key', 'hmac'], true)) {
            $site['auth_mode'] = 'wp_app_password';
        }
        if (! in_array($site['onboarding_state'], ['', 'PENDING_INTRO', 'VERIFIED', 'REJECTED', 'DISABLED'], true)) {
            $site['onboarding_state'] = '';
        }
        if ($site['base_url'] === '' && isset($existing['base_url'])) {
            $site['base_url'] = (string) $existing['base_url'];
        }
        if (! isset($existing['created_at'])) {
            $site['created_at'] = gmdate('c');
        } else {
            $site['created_at'] = (string) $existing['created_at'];
        }
        if (! isset($existing['first_seen_at']) || (string) $existing['first_seen_at'] === '') {
            $site['first_seen_at'] = sanitize_text_field((string) ($payload['first_seen_at'] ?? gmdate('c')));
        } else {
            $site['first_seen_at'] = sanitize_text_field((string) ($payload['first_seen_at'] ?? $existing['first_seen_at']));
        }
        if (isset($existing['site_sequence_id'])) {
            $site['site_sequence_id'] = max(1, (int) $existing['site_sequence_id']);
        } else {
            $next_seq = max(0, (int) get_option(self::OPTION_SITE_SEQUENCE, 0)) + 1;
            $site['site_sequence_id'] = $next_seq;
            update_option(self::OPTION_SITE_SEQUENCE, $next_seq);
        }
        $site['local_instance_uuid'] = sanitize_text_field((string) ($payload['local_instance_uuid'] ?? ($existing['local_instance_uuid'] ?? '')));
        $site['site_title_host_snapshot'] = sanitize_text_field((string) ($payload['site_title_host_snapshot'] ?? ($existing['site_title_host_snapshot'] ?? '')));
        if ($site['site_title_host_snapshot'] === '' && $site['site_label'] !== '') {
            $host = wp_parse_url((string) ($site['base_url'] ?? ''), PHP_URL_HOST);
            $site['site_title_host_snapshot'] = sanitize_text_field($site['site_label'] . ($host ? (' | ' . $host) : ''));
        }

        $sites = self::get_sites();
        $sites[$site_uid] = $site;
        update_option(self::OPTION_CONNECTED_SITES, $sites);

        return $site;
    }

    /**
     * @param string $site_uid
     * @return bool
     */
    public static function is_allowed_site($site_uid)
    {
        $site = self::get_site($site_uid);
        if (! is_array($site)) {
            return false;
        }
        if (($site['status'] ?? 'online') === 'disabled') {
            return false;
        }
        return ! empty($site['allow_receive_packages']);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public static function rest_list(\WP_REST_Request $request)
    {
        $mode = 'registry_table';
        if (class_exists('DBVC_Bricks_Addon')) {
            $mode = DBVC_Bricks_Addon::get_enum_setting('dbvc_bricks_connected_sites_mode', ['packages_backfill', 'registry_table'], 'registry_table');
        }
        self::sync_from_registry($mode);
        $status_filter = sanitize_key((string) $request->get_param('status'));
        $sites = self::get_sites();
        $duplicate_groups = self::get_duplicate_base_url_groups($sites);
        $conflicts = self::build_conflict_map($sites);
        $assisted_candidates = self::get_assisted_merge_candidates($sites);
        $active_pull_by_site = self::build_active_pull_map($sites, $duplicate_groups);
        $assisted_by_alias = [];
        foreach ($assisted_candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $alias_uid = sanitize_key((string) ($candidate['alias_site_uid'] ?? ''));
            if ($alias_uid === '' || isset($assisted_by_alias[$alias_uid])) {
                continue;
            }
            $assisted_by_alias[$alias_uid] = $candidate;
        }
        $items = array_values(array_map(static function ($site) use ($conflicts, $assisted_by_alias, $active_pull_by_site) {
            if (! is_array($site)) {
                return [];
            }
            return self::decorate_site_with_conflicts($site, $conflicts, $assisted_by_alias, $active_pull_by_site);
        }, $sites));
        if ($status_filter !== '') {
            $items = array_values(array_filter($items, static function ($site) use ($status_filter) {
                return isset($site['status']) && $site['status'] === $status_filter;
            }));
        }
        return rest_ensure_response([
            'items' => $items,
            'registry_mode' => $mode,
            'conflicts' => $conflicts,
            'duplicate_groups' => $duplicate_groups,
            'aliases' => self::get_aliases(),
            'assisted_merge_candidates' => $assisted_candidates,
            'active_pull_by_site' => $active_pull_by_site,
        ]);
    }

    /**
     * @param string $mode
     * @return void
     */
    private static function sync_from_registry($mode)
    {
        if ($mode === 'packages_backfill') {
            self::sync_from_packages_sources();
            self::sync_from_onboarding_registry();
            return;
        }

        self::sync_from_onboarding_registry();
        if (empty(self::get_sites())) {
            self::sync_from_packages_sources();
        }
    }

    /**
     * Backfill connected-site registry from known package source metadata.
     *
     * @return void
     */
    public static function sync_from_packages_sources()
    {
        if (! class_exists('DBVC_Bricks_Packages') || ! method_exists('DBVC_Bricks_Packages', 'get_packages')) {
            return;
        }
        $packages = DBVC_Bricks_Packages::get_packages();
        if (! is_array($packages) || empty($packages)) {
            return;
        }
        foreach ($packages as $package) {
            if (! is_array($package)) {
                continue;
            }
            $source_site = isset($package['source_site']) && is_array($package['source_site']) ? $package['source_site'] : [];
            $site_uid = sanitize_key((string) ($source_site['site_uid'] ?? ''));
            if ($site_uid === '') {
                continue;
            }
            $existing = self::get_site($site_uid);
            self::upsert_site([
                'site_uid' => $site_uid,
                'site_label' => (string) ($existing['site_label'] ?? $site_uid),
                'base_url' => esc_url_raw((string) ($source_site['base_url'] ?? ($existing['base_url'] ?? ''))),
                'status' => (string) ($existing['status'] ?? 'online'),
                'auth_mode' => (string) ($existing['auth_mode'] ?? 'wp_app_password'),
                'allow_receive_packages' => ! empty($existing['allow_receive_packages']) ? 1 : 0,
                'onboarding_state' => (string) ($existing['onboarding_state'] ?? ''),
                'onboarding_updated_at' => (string) ($existing['onboarding_updated_at'] ?? ''),
                'last_seen_at' => sanitize_text_field((string) ($package['updated_at'] ?? gmdate('c'))),
            ]);
        }
    }

    /**
     * Sync connected-site records from onboarding registry option.
     *
     * @return void
     */
    public static function sync_from_onboarding_registry()
    {
        if (! class_exists('DBVC_Bricks_Onboarding') || ! method_exists('DBVC_Bricks_Onboarding', 'get_clients')) {
            return;
        }
        $clients = DBVC_Bricks_Onboarding::get_clients();
        if (! is_array($clients) || empty($clients)) {
            return;
        }

        foreach ($clients as $client) {
            if (! is_array($client)) {
                continue;
            }
            $site_uid = sanitize_key((string) ($client['site_uid'] ?? ''));
            if ($site_uid === '') {
                continue;
            }

            $existing = self::get_site($site_uid);
            $onboarding_state = strtoupper(sanitize_text_field((string) ($client['onboarding_state'] ?? 'PENDING_INTRO')));
            $status = (string) ($existing['status'] ?? 'online');
            if (in_array($onboarding_state, ['REJECTED', 'DISABLED'], true)) {
                $status = 'disabled';
            }
            $allow_receive = ! empty($existing['allow_receive_packages']) ? 1 : 0;
            if ($onboarding_state === 'VERIFIED') {
                $allow_receive = 1;
            }
            if (in_array($onboarding_state, ['REJECTED', 'DISABLED'], true)) {
                $allow_receive = 0;
            }

            $auth_profile = isset($client['auth_profile']) && is_array($client['auth_profile']) ? $client['auth_profile'] : [];
            self::upsert_site([
                'site_uid' => $site_uid,
                'site_label' => sanitize_text_field((string) ($client['site_label'] ?? ($existing['site_label'] ?? $site_uid))),
                'base_url' => esc_url_raw((string) ($client['base_url'] ?? ($existing['base_url'] ?? ''))),
                'status' => $status,
                'auth_mode' => sanitize_key((string) ($auth_profile['method'] ?? ($existing['auth_mode'] ?? 'wp_app_password'))),
                'allow_receive_packages' => $allow_receive,
                'onboarding_state' => $onboarding_state,
                'onboarding_updated_at' => sanitize_text_field((string) ($client['last_handshake_at'] ?? ($client['last_intro_at'] ?? ''))),
                'last_seen_at' => sanitize_text_field((string) ($client['last_seen_at'] ?? gmdate('c'))),
                'local_instance_uuid' => sanitize_text_field((string) ($client['local_instance_uuid'] ?? ($existing['local_instance_uuid'] ?? ''))),
                'first_seen_at' => sanitize_text_field((string) ($client['first_seen_at'] ?? ($existing['first_seen_at'] ?? ''))),
                'site_title_host_snapshot' => sanitize_text_field((string) ($client['site_title_host_snapshot'] ?? ($existing['site_title_host_snapshot'] ?? ''))),
            ]);
        }
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_upsert(\WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (! is_array($payload)) {
            $payload = [];
        }
        $site = self::upsert_site($payload);
        if (empty($site)) {
            return new \WP_Error('dbvc_bricks_site_uid_required', 'site_uid is required.', ['status' => 400]);
        }
        return rest_ensure_response([
            'ok' => true,
            'site' => $site,
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_reset_linkage(\WP_REST_Request $request)
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error('dbvc_bricks_reset_linkage_role_invalid', 'Reset linkage is mothership-only.', ['status' => 400]);
        }
        $site_uid = sanitize_key((string) $request->get_param('site_uid'));
        $confirm = ! empty($request->get_param('confirm_reset'));
        if ($site_uid === '' || ! $confirm) {
            return new \WP_Error('dbvc_bricks_reset_linkage_invalid', 'site_uid and confirm_reset=true are required.', ['status' => 400]);
        }

        $site = self::get_site($site_uid);
        if (! is_array($site)) {
            return new \WP_Error('dbvc_bricks_reset_linkage_not_found', 'Connected site not found.', ['status' => 404]);
        }

        if (class_exists('DBVC_Bricks_Onboarding') && method_exists('DBVC_Bricks_Onboarding', 'reset_client_linkage')) {
            DBVC_Bricks_Onboarding::reset_client_linkage($site_uid);
        } elseif (class_exists('DBVC_Bricks_Onboarding')) {
            $clients = get_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, []);
            if (is_array($clients) && isset($clients[$site_uid]) && is_array($clients[$site_uid])) {
                $clients[$site_uid]['onboarding_state'] = 'pending_intro';
                $clients[$site_uid]['approved_at'] = '';
                $clients[$site_uid]['rejected_at'] = '';
                $clients[$site_uid]['last_handshake_at'] = '';
                $clients[$site_uid]['handshake_token_hash'] = '';
                $clients[$site_uid]['command_secret'] = '';
                $clients[$site_uid]['updated_at'] = gmdate('c');
                update_option(DBVC_Bricks_Onboarding::OPTION_CLIENTS, $clients);
            }
        }

        $updated_site = self::upsert_site([
            'site_uid' => $site_uid,
            'status' => 'online',
            'allow_receive_packages' => 0,
            'onboarding_state' => 'PENDING_INTRO',
            'onboarding_updated_at' => gmdate('c'),
        ]);

        return rest_ensure_response([
            'ok' => true,
            'site_uid' => $site_uid,
            'site' => $updated_site,
            'action' => 'reset_linkage',
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_merge_alias(\WP_REST_Request $request)
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error('dbvc_bricks_merge_alias_role_invalid', 'Merge/deactivate alias is mothership-only.', ['status' => 400]);
        }
        $canonical_uid = sanitize_key((string) $request->get_param('canonical_site_uid'));
        $alias_uid = sanitize_key((string) $request->get_param('alias_site_uid'));
        $confirm = ! empty($request->get_param('confirm_merge'));
        if ($canonical_uid === '' || $alias_uid === '' || ! $confirm || $canonical_uid === $alias_uid) {
            return new \WP_Error('dbvc_bricks_merge_alias_invalid', 'canonical_site_uid, alias_site_uid, and confirm_merge=true are required.', ['status' => 400]);
        }

        $canonical_site = self::get_site($canonical_uid);
        $alias_site = self::get_site($alias_uid);
        if (! is_array($canonical_site) || ! is_array($alias_site)) {
            return new \WP_Error('dbvc_bricks_merge_alias_not_found', 'Canonical or alias site was not found.', ['status' => 404]);
        }

        $canonical_url = self::normalize_base_url((string) ($canonical_site['base_url'] ?? ''));
        $alias_url = self::normalize_base_url((string) ($alias_site['base_url'] ?? ''));
        if ($canonical_url === '' || $canonical_url !== $alias_url) {
            return new \WP_Error('dbvc_bricks_merge_alias_url_mismatch', 'Canonical and alias must share the same normalized base URL.', ['status' => 400]);
        }

        $merged = self::merge_alias_pair($canonical_uid, $alias_uid, 'merge_deactivate_alias', true);
        if (is_wp_error($merged)) {
            return $merged;
        }

        return rest_ensure_response([
            'ok' => true,
            'canonical_site_uid' => $canonical_uid,
            'alias_site_uid' => $alias_uid,
            'alias_site' => $merged,
            'action' => 'merge_deactivate_alias',
        ]);
    }

    /**
     * @param string $canonical_uid
     * @param string $alias_uid
     * @param string $reason
     * @param bool $auto_generated
     * @return array<string, mixed>|\WP_Error
     */
    private static function merge_alias_pair($canonical_uid, $alias_uid, $reason = 'merge_alias', $auto_generated = false)
    {
        $canonical_uid = sanitize_key((string) $canonical_uid);
        $alias_uid = sanitize_key((string) $alias_uid);
        if ($canonical_uid === '' || $alias_uid === '' || $canonical_uid === $alias_uid) {
            return new \WP_Error('dbvc_bricks_merge_alias_invalid', 'canonical_site_uid and alias_site_uid must be valid and different.', ['status' => 400]);
        }
        self::upsert_site([
            'site_uid' => $canonical_uid,
            'status' => 'online',
            'allow_receive_packages' => 1,
        ]);
        $alias_updated = self::upsert_site([
            'site_uid' => $alias_uid,
            'status' => 'disabled',
            'allow_receive_packages' => 0,
            'onboarding_state' => 'DISABLED',
            'onboarding_updated_at' => gmdate('c'),
        ]);
        if (class_exists('DBVC_Bricks_Onboarding') && method_exists('DBVC_Bricks_Onboarding', 'disable_alias_client_record')) {
            DBVC_Bricks_Onboarding::disable_alias_client_record($alias_uid, $canonical_uid);
        }
        $alias_map = self::set_known_alias($alias_uid, $canonical_uid, $reason, $auto_generated);
        if (is_wp_error($alias_map)) {
            return $alias_map;
        }
        return $alias_updated;
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_assisted_merge_candidates(\WP_REST_Request $request)
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error('dbvc_bricks_assisted_merge_role_invalid', 'Assisted merge candidates are mothership-only.', ['status' => 400]);
        }
        return rest_ensure_response([
            'ok' => true,
            'items' => self::get_assisted_merge_candidates(),
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_assisted_merge(\WP_REST_Request $request)
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error('dbvc_bricks_assisted_merge_role_invalid', 'Assisted merge is mothership-only.', ['status' => 400]);
        }
        $canonical_uid = sanitize_key((string) $request->get_param('canonical_site_uid'));
        $alias_uid = sanitize_key((string) $request->get_param('alias_site_uid'));
        $match_token = sanitize_text_field((string) $request->get_param('match_token'));
        $confirm = ! empty($request->get_param('confirm_assisted_merge'));
        if ($canonical_uid === '' || $alias_uid === '' || $match_token === '' || ! $confirm) {
            return new \WP_Error('dbvc_bricks_assisted_merge_invalid', 'canonical_site_uid, alias_site_uid, match_token, and confirm_assisted_merge=true are required.', ['status' => 400]);
        }
        $candidate = null;
        foreach (self::get_assisted_merge_candidates() as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (sanitize_key((string) ($row['canonical_site_uid'] ?? '')) !== $canonical_uid) {
                continue;
            }
            if (sanitize_key((string) ($row['alias_site_uid'] ?? '')) !== $alias_uid) {
                continue;
            }
            $candidate = $row;
            break;
        }
        if (! is_array($candidate)) {
            return new \WP_Error('dbvc_bricks_assisted_merge_not_eligible', 'Alias/canonical pair is not currently eligible for deterministic assisted merge.', ['status' => 409]);
        }
        $candidate_token = sanitize_text_field((string) ($candidate['match_token'] ?? ''));
        if ($candidate_token === '' || ! hash_equals($candidate_token, $match_token)) {
            return new \WP_Error('dbvc_bricks_assisted_merge_token_invalid', 'Assisted merge token mismatch.', ['status' => 409]);
        }
        $reason = 'assisted_merge:' . implode(',', isset($candidate['match_reasons']) && is_array($candidate['match_reasons']) ? $candidate['match_reasons'] : []);
        $merged = self::merge_alias_pair($canonical_uid, $alias_uid, $reason, true);
        if (is_wp_error($merged)) {
            return $merged;
        }
        return rest_ensure_response([
            'ok' => true,
            'action' => 'assisted_merge_deactivate_alias',
            'canonical_site_uid' => $canonical_uid,
            'alias_site_uid' => $alias_uid,
            'alias_site' => $merged,
            'match_reasons' => isset($candidate['match_reasons']) && is_array($candidate['match_reasons']) ? array_values($candidate['match_reasons']) : [],
        ]);
    }

    /**
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public static function rest_set_known_alias(\WP_REST_Request $request)
    {
        if (class_exists('DBVC_Bricks_Addon') && DBVC_Bricks_Addon::get_role_mode() !== 'mothership') {
            return new \WP_Error('dbvc_bricks_known_alias_role_invalid', 'Known alias mapping is mothership-only.', ['status' => 400]);
        }
        $canonical_uid = sanitize_key((string) $request->get_param('canonical_site_uid'));
        $alias_uid = sanitize_key((string) $request->get_param('alias_site_uid'));
        $reason = sanitize_text_field((string) $request->get_param('reason'));
        $confirm = ! empty($request->get_param('confirm_alias'));
        if ($canonical_uid === '' || $alias_uid === '' || ! $confirm) {
            return new \WP_Error('dbvc_bricks_known_alias_invalid', 'canonical_site_uid, alias_site_uid, and confirm_alias=true are required.', ['status' => 400]);
        }
        $mapped = self::set_known_alias($alias_uid, $canonical_uid, $reason, false);
        if (is_wp_error($mapped)) {
            return $mapped;
        }
        return rest_ensure_response([
            'ok' => true,
            'mapping' => $mapped,
        ]);
    }
}
