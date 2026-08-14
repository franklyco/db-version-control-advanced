<?php

/**
 * Read-only administrator capability landscape sourced from the agent manifest.
 *
 * @package DB_Version_Control
 */

if (! defined('WPINC')) {
	die;
}

/**
 * Determine whether the current user may inspect the capability landscape.
 *
 * @return bool
 */
function dbvc_can_view_capability_landscape()
{
	return current_user_can('manage_options');
}

/**
 * Load the curated agent capability manifest.
 *
 * @return array|WP_Error
 */
function dbvc_load_capability_landscape_manifest()
{
	$manifest_path = dirname(__DIR__) . '/docs/agents/manifest.json';
	if (! is_readable($manifest_path)) {
		return new WP_Error(
			'dbvc_capability_manifest_missing',
			esc_html__('The DBVC capability manifest is unavailable in this plugin package.', 'dbvc')
		);
	}

	$contents = file_get_contents($manifest_path);
	if (false === $contents) {
		return new WP_Error(
			'dbvc_capability_manifest_read_failed',
			esc_html__('The DBVC capability manifest could not be read.', 'dbvc')
		);
	}

	$manifest = json_decode($contents, true);
	if (! is_array($manifest) || ! isset($manifest['records']) || ! is_array($manifest['records'])) {
		return new WP_Error(
			'dbvc_capability_manifest_invalid',
			esc_html__('The DBVC capability manifest is invalid or incomplete.', 'dbvc')
		);
	}

	return $manifest;
}

/**
 * Extract sorted values from namespaced manifest tags.
 *
 * @param array  $tags   Manifest tags.
 * @param string $prefix Namespace prefix.
 * @return array
 */
function dbvc_capability_landscape_tag_values(array $tags, $prefix)
{
	$values = [];
	foreach ($tags as $tag) {
		if (is_string($tag) && 0 === strpos($tag, $prefix)) {
			$values[] = substr($tag, strlen($prefix));
		}
	}
	$values = array_values(array_unique(array_filter($values, 'strlen')));
	sort($values, SORT_NATURAL | SORT_FLAG_CASE);
	return $values;
}

/**
 * Return a human-readable manifest classification label.
 *
 * @param string $value Raw value.
 * @return string
 */
function dbvc_capability_landscape_label($value)
{
	$labels = [
		'active'                        => __('Active', 'dbvc'),
		'experimental'                  => __('Experimental', 'dbvc'),
		'planned'                       => __('Planned', 'dbvc'),
		'source_reference'              => __('Source reference', 'dbvc'),
		'deprecated'                    => __('Deprecated', 'dbvc'),
		'absent_current_checkout'       => __('Absent in this checkout', 'dbvc'),
		'unknown_requires_verification' => __('Needs verification', 'dbvc'),
		'cli_automation'                => __('CLI & automation', 'dbvc'),
		'import_export'                 => __('Import & export', 'dbvc'),
		'proposal_review'               => __('Proposal review', 'dbvc'),
		'media_resolver'                => __('Media & resolver', 'dbvc'),
		'identity_entities'             => __('Identity & entities', 'dbvc'),
		'snapshots_backups'             => __('Snapshots & backups', 'dbvc'),
		'entity_editor'                 => __('Entity Editor', 'dbvc'),
		'settings_configuration'        => __('Settings & configuration', 'dbvc'),
		'api_extensions'                => __('API & extensions', 'dbvc'),
		'addon_bricks'                  => __('Bricks add-on', 'dbvc'),
		'addon_content_migration'       => __('Content Migration add-on', 'dbvc'),
		'observability'                 => __('Observability', 'dbvc'),
		'internal_foundation'           => __('Internal foundation', 'dbvc'),
		'read_only'                     => __('Read only', 'dbvc'),
		'filesystem_write'              => __('Filesystem write', 'dbvc'),
		'wordpress_write'               => __('WordPress write', 'dbvc'),
		'remote_write'                  => __('Remote write', 'dbvc'),
		'destructive'                   => __('Destructive', 'dbvc'),
		'mixed'                         => __('Mixed', 'dbvc'),
		'unknown'                       => __('Unknown', 'dbvc'),
		'candidate'                     => __('Reviewed candidate', 'dbvc'),
		'covered_elsewhere'             => __('Covered elsewhere', 'dbvc'),
		'deferred'                      => __('Deferred', 'dbvc'),
		'not_recommended'               => __('Not recommended', 'dbvc'),
		'needs_review'                  => __('Needs review', 'dbvc'),
		'unreviewed'                    => __('Unreviewed', 'dbvc'),
		'high'                          => __('High', 'dbvc'),
		'medium'                        => __('Medium', 'dbvc'),
		'low'                           => __('Low', 'dbvc'),
		'none'                          => __('None', 'dbvc'),
		'small'                         => __('Small', 'dbvc'),
		'large'                         => __('Large', 'dbvc'),
		'cli'                           => __('WP-CLI', 'dbvc'),
		'rest'                          => __('REST API', 'dbvc'),
		'admin'                         => __('Admin UI', 'dbvc'),
		'php'                           => __('PHP', 'dbvc'),
		'hook'                          => __('Hook', 'dbvc'),
		'ajax'                          => __('AJAX', 'dbvc'),
		'admin_post'                    => __('Admin post', 'dbvc'),
		'cron'                          => __('Scheduled', 'dbvc'),
		'filesystem'                    => __('Filesystem', 'dbvc'),
		'database'                      => __('Database', 'dbvc'),
	];

	if (isset($labels[$value])) {
		return $labels[$value];
	}

	return ucwords(str_replace(['_', '-'], ' ', (string) $value));
}

/**
 * Normalize manifest records for display and filtering.
 *
 * @param array $manifest Manifest payload.
 * @return array
 */
function dbvc_prepare_capability_landscape_records(array $manifest)
{
	$prepared = [];
	foreach ((array) ($manifest['records'] ?? []) as $record) {
		if (! is_array($record) || empty($record['id']) || empty($record['title'])) {
			continue;
		}

		$tags          = array_values(array_filter((array) ($record['tags'] ?? []), 'is_string'));
		$surface_types = dbvc_capability_landscape_tag_values($tags, 'surface:');
		$surfaces      = [];
		foreach ((array) ($record['surfaces'] ?? []) as $surface) {
			if (! is_array($surface)) {
				continue;
			}
			$type = sanitize_key((string) ($surface['type'] ?? ''));
			if ('' !== $type) {
				$surface_types[] = $type;
			}
			$surfaces[] = [
				'type'       => $type,
				'identifier' => (string) ($surface['identifier'] ?? ''),
			];
		}
		$surface_types = array_values(array_unique(array_filter($surface_types, 'strlen')));
		sort($surface_types, SORT_NATURAL | SORT_FLAG_CASE);

		$status       = sanitize_key((string) ($record['status'] ?? 'unknown_requires_verification'));
		$category     = sanitize_key((string) ($record['primary_category'] ?? 'internal_foundation'));
		$safety       = sanitize_key((string) ($record['safety']['classification'] ?? 'unknown'));
		$opportunity  = is_array($record['opportunity'] ?? null) ? $record['opportunity'] : [];
		$disposition  = sanitize_key((string) ($opportunity['disposition'] ?? 'unreviewed'));
		$priority     = sanitize_key((string) ($opportunity['priority'] ?? 'none'));
		$effort       = sanitize_key((string) ($opportunity['effort'] ?? 'unknown'));
		$recommended  = sanitize_key((string) ($opportunity['recommended_surface'] ?? 'none'));
		$is_active    = 'active' === $status;
		$has_cli      = in_array('cli', $surface_types, true);
		$has_rest     = in_array('rest', $surface_types, true);
		$agent_uses   = [];
		if (! $is_active) {
			$agent_uses[] = 'non-current';
		}
		if ($has_cli) {
			$agent_uses[] = 'cli-ready';
		}
		if ($is_active && 'read_only' === $safety) {
			$agent_uses[] = 'safe-inspection';
		}
		if ('candidate' === $disposition && 'cli' === $recommended) {
			$agent_uses[] = 'cli-candidate';
		}
		if ('unreviewed' === $disposition && $is_active && $has_rest && ! $has_cli) {
			$agent_uses[] = 'parity-review';
		}
		if ('covered_elsewhere' === $disposition) {
			$agent_uses[] = 'parity-covered';
		}
		if (in_array($disposition, ['deferred', 'not_recommended'], true)) {
			$agent_uses[] = 'parity-deferred';
		}
		if ('read_only' !== $safety && 'unknown' !== $safety) {
			$agent_uses[] = 'write-gated';
		}

		$search_parts = [
			$record['id'],
			$record['title'],
			$record['summary'] ?? '',
			$record['addon_or_owner'] ?? '',
			$status,
			$category,
			$safety,
			$disposition,
			$priority,
			$effort,
			$recommended,
			$opportunity['rationale'] ?? '',
			$opportunity['candidate_scope'] ?? '',
			$opportunity['next_action'] ?? '',
		];
		$search_parts = array_merge(
			$search_parts,
			(array) ($record['aliases'] ?? []),
			$tags,
			(array) ($record['known_gaps'] ?? []),
			(array) ($record['storage_touched'] ?? []),
			(array) ($opportunity['excluded_operations'] ?? [])
		);
		foreach ($surfaces as $surface) {
			$search_parts[] = $surface['identifier'];
		}

		$prepared[] = [
			'record'         => $record,
			'status'         => $status,
			'category'       => $category,
			'safety'         => $safety,
			'surfaces'       => $surfaces,
			'surface_types'  => $surface_types,
			'operations'     => dbvc_capability_landscape_tag_values($tags, 'operation:'),
			'objects'        => dbvc_capability_landscape_tag_values($tags, 'object:'),
			'workflows'      => dbvc_capability_landscape_tag_values($tags, 'workflow:'),
			'scopes'         => dbvc_capability_landscape_tag_values($tags, 'scope:'),
			'agent_uses'     => array_values(array_unique($agent_uses)),
			'opportunity'    => [
				'disposition'        => $disposition,
				'priority'           => $priority,
				'effort'             => $effort,
				'recommended_surface' => $recommended,
				'rationale'          => (string) ($opportunity['rationale'] ?? ''),
				'candidate_scope'     => (string) ($opportunity['candidate_scope'] ?? ''),
				'excluded_operations' => array_values(array_filter(array_map('strval', (array) ($opportunity['excluded_operations'] ?? [])))),
				'next_action'        => (string) ($opportunity['next_action'] ?? ''),
			],
			'search'         => strtolower(implode(' ', array_filter(array_map('strval', $search_parts)))),
		];
	}

	usort($prepared, static function ($left, $right) {
		$category_compare = strcasecmp($left['category'], $right['category']);
		if (0 !== $category_compare) {
			return $category_compare;
		}
		$status_order = [
			'active'                        => 0,
			'experimental'                  => 1,
			'unknown_requires_verification' => 2,
			'planned'                       => 3,
			'source_reference'              => 4,
			'absent_current_checkout'       => 5,
			'deprecated'                    => 6,
		];
		$left_status  = isset($status_order[$left['status']]) ? $status_order[$left['status']] : 99;
		$right_status = isset($status_order[$right['status']]) ? $status_order[$right['status']] : 99;
		if ($left_status !== $right_status) {
			return $left_status <=> $right_status;
		}
		return strcasecmp($left['record']['title'], $right['record']['title']);
	});

	return $prepared;
}

/**
 * Summarize current and automation-oriented capability counts.
 *
 * @param array $records Prepared records.
 * @return array
 */
function dbvc_capability_landscape_stats(array $records)
{
	$active             = 0;
	$read_only          = 0;
	$non_active         = 0;
	$cli_candidates     = 0;
	$opportunity_reviews = 0;
	$cli_commands       = [];
	$rest_registrations = [];

	foreach ($records as $item) {
		$is_active = 'active' === $item['status'];
		if ($is_active) {
			++$active;
		} else {
			++$non_active;
		}
		if ($is_active && 'read_only' === $item['safety']) {
			++$read_only;
		}
		if (in_array('cli-candidate', $item['agent_uses'], true)) {
			++$cli_candidates;
		}
		if (in_array($item['opportunity']['disposition'], ['candidate', 'covered_elsewhere', 'deferred', 'not_recommended', 'needs_review'], true)) {
			++$opportunity_reviews;
		}
		if (! $is_active) {
			continue;
		}
		foreach ((array) ($item['record']['surfaces'] ?? []) as $surface) {
			foreach ((array) ($surface['discovery_ids'] ?? []) as $discovery_id) {
				if (0 === strpos($discovery_id, 'cli.command.')) {
					$cli_commands[$discovery_id] = true;
				}
				if (0 === strpos($discovery_id, 'rest.')) {
					$rest_registrations[$discovery_id] = true;
				}
			}
		}
	}

	return [
		'total'              => count($records),
		'active'             => $active,
		'cli_commands'       => count($cli_commands),
		'rest_registrations' => count($rest_registrations),
		'read_only'          => $read_only,
		'cli_candidates'     => $cli_candidates,
		'opportunity_reviews' => $opportunity_reviews,
		'non_active'         => $non_active,
	];
}

/**
 * Render a manifest tag chip.
 *
 * @param string $value Value.
 * @param string $class Optional modifier.
 * @return void
 */
function dbvc_capability_landscape_chip($value, $class = '')
{
	printf(
		'<span class="dbvc-capability-chip %1$s">%2$s</span>',
		esc_attr($class),
		esc_html(dbvc_capability_landscape_label($value))
	);
}

/**
 * Render the administrator-only capability landscape panel.
 *
 * @return void
 */
function dbvc_render_capability_landscape_panel()
{
	if (! dbvc_can_view_capability_landscape()) {
		wp_die(esc_html__('You do not have sufficient permissions to view the DBVC capability landscape.', 'dbvc'));
	}

	$manifest = dbvc_load_capability_landscape_manifest();
	if (is_wp_error($manifest)) {
		printf('<div class="notice notice-error inline"><p>%s</p></div>', esc_html($manifest->get_error_message()));
		return;
	}

	$records    = dbvc_prepare_capability_landscape_records($manifest);
	$stats      = dbvc_capability_landscape_stats($records);
	$categories = [];
	$statuses   = [];
	$surfaces   = [];
	foreach ($records as $item) {
		$categories[$item['category']] = dbvc_capability_landscape_label($item['category']);
		$statuses[$item['status']]     = dbvc_capability_landscape_label($item['status']);
		foreach ($item['surface_types'] as $surface) {
			$surfaces[$surface] = dbvc_capability_landscape_label($surface);
		}
	}
	asort($categories, SORT_NATURAL | SORT_FLAG_CASE);
	asort($statuses, SORT_NATURAL | SORT_FLAG_CASE);
	asort($surfaces, SORT_NATURAL | SORT_FLAG_CASE);
	?>
	<div class="dbvc-capability-landscape" id="dbvc-capability-landscape">
		<div class="dbvc-capability-landscape__intro">
			<h3><?php esc_html_e('Capability Landscape', 'dbvc'); ?></h3>
			<p><?php esc_html_e('Review the repository-curated DBVC tool, command, API, and add-on landscape. Active records describe this plugin checkout; planned, source-reference, experimental, and absent records remain visible so gaps are not mistaken for callable functionality.', 'dbvc'); ?></p>
			<p class="description"><?php esc_html_e('This screen is read-only. Reviewed opportunities include explicit priority, effort, and recommended-interface judgments; unreviewed REST-only records remain prompts rather than automatic CLI recommendations.', 'dbvc'); ?></p>
		</div>

		<div class="dbvc-capability-stats" aria-label="<?php esc_attr_e('Capability summary', 'dbvc'); ?>">
			<div><strong><?php echo esc_html($stats['active']); ?></strong><span><?php esc_html_e('Active capabilities', 'dbvc'); ?></span></div>
			<div><strong><?php echo esc_html($stats['cli_commands']); ?></strong><span><?php esc_html_e('CLI commands', 'dbvc'); ?></span></div>
			<div><strong><?php echo esc_html($stats['rest_registrations']); ?></strong><span><?php esc_html_e('Active REST registrations', 'dbvc'); ?></span></div>
			<div><strong><?php echo esc_html($stats['read_only']); ?></strong><span><?php esc_html_e('Read-only starting points', 'dbvc'); ?></span></div>
			<div><strong><?php echo esc_html($stats['cli_candidates']); ?></strong><span><?php esc_html_e('Potential CLI parity', 'dbvc'); ?></span></div>
			<div><strong><?php echo esc_html($stats['opportunity_reviews']); ?></strong><span><?php esc_html_e('Reviewed opportunities', 'dbvc'); ?></span></div>
		</div>

		<div class="dbvc-capability-filters" aria-label="<?php esc_attr_e('Capability filters', 'dbvc'); ?>">
			<label>
				<span><?php esc_html_e('Search', 'dbvc'); ?></span>
				<input type="search" id="dbvc-capability-search" placeholder="<?php esc_attr_e('Command, tool, tag, owner, gap…', 'dbvc'); ?>" />
			</label>
			<label>
				<span><?php esc_html_e('Category', 'dbvc'); ?></span>
				<select id="dbvc-capability-category"><option value=""><?php esc_html_e('All categories', 'dbvc'); ?></option><?php foreach ($categories as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
			</label>
			<label>
				<span><?php esc_html_e('Status', 'dbvc'); ?></span>
				<select id="dbvc-capability-status"><option value=""><?php esc_html_e('All statuses', 'dbvc'); ?></option><?php foreach ($statuses as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
			</label>
			<label>
				<span><?php esc_html_e('Interface', 'dbvc'); ?></span>
				<select id="dbvc-capability-surface"><option value=""><?php esc_html_e('All interfaces', 'dbvc'); ?></option><?php foreach ($surfaces as $value => $label) : ?><option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
			</label>
			<label>
				<span><?php esc_html_e('Opportunity', 'dbvc'); ?></span>
				<select id="dbvc-capability-opportunity">
					<option value=""><?php esc_html_e('All opportunity states', 'dbvc'); ?></option>
					<option value="candidate"><?php esc_html_e('Reviewed candidate', 'dbvc'); ?></option>
					<option value="needs_review"><?php esc_html_e('Needs review', 'dbvc'); ?></option>
					<option value="covered_elsewhere"><?php esc_html_e('Covered elsewhere', 'dbvc'); ?></option>
					<option value="deferred"><?php esc_html_e('Deferred', 'dbvc'); ?></option>
					<option value="not_recommended"><?php esc_html_e('Not recommended', 'dbvc'); ?></option>
					<option value="unreviewed"><?php esc_html_e('Unreviewed', 'dbvc'); ?></option>
				</select>
			</label>
			<label>
				<span><?php esc_html_e('Agent leverage', 'dbvc'); ?></span>
				<select id="dbvc-capability-agent-use">
					<option value=""><?php esc_html_e('All leverage types', 'dbvc'); ?></option>
					<option value="cli-ready"><?php esc_html_e('CLI ready', 'dbvc'); ?></option>
					<option value="safe-inspection"><?php esc_html_e('Read-only starting point', 'dbvc'); ?></option>
					<option value="cli-candidate"><?php esc_html_e('Potential CLI parity', 'dbvc'); ?></option>
					<option value="parity-review"><?php esc_html_e('Unreviewed REST parity', 'dbvc'); ?></option>
					<option value="parity-covered"><?php esc_html_e('Parity covered elsewhere', 'dbvc'); ?></option>
					<option value="write-gated"><?php esc_html_e('Write-gated', 'dbvc'); ?></option>
					<option value="non-current"><?php esc_html_e('Non-current or gap', 'dbvc'); ?></option>
				</select>
			</label>
			<button type="button" class="button" id="dbvc-capability-reset"><?php esc_html_e('Reset filters', 'dbvc'); ?></button>
		</div>

		<p class="dbvc-capability-results" id="dbvc-capability-results" aria-live="polite">
			<?php printf(esc_html__('Showing %1$d of %2$d capability records.', 'dbvc'), (int) $stats['total'], (int) $stats['total']); ?>
		</p>

		<div class="dbvc-capability-table-wrap">
			<table class="widefat striped dbvc-capability-table">
				<caption class="screen-reader-text"><?php esc_html_e('DBVC capability, command, interface, safety, and gap inventory', 'dbvc'); ?></caption>
				<thead><tr>
					<th scope="col"><?php esc_html_e('Capability', 'dbvc'); ?></th>
					<th scope="col"><?php esc_html_e('Classification', 'dbvc'); ?></th>
					<th scope="col"><?php esc_html_e('Interfaces', 'dbvc'); ?></th>
					<th scope="col"><?php esc_html_e('Operations & workflows', 'dbvc'); ?></th>
					<th scope="col"><?php esc_html_e('Safety & data', 'dbvc'); ?></th>
					<th scope="col"><?php esc_html_e('Agent leverage & gaps', 'dbvc'); ?></th>
				</tr></thead>
				<tbody>
				<?php
				$current_category = null;
				foreach ($records as $item) :
					$record = $item['record'];
					if ($current_category !== $item['category']) :
						$current_category = $item['category'];
						?>
						<tr class="dbvc-capability-group" data-dbvc-capability-group="<?php echo esc_attr($current_category); ?>"><th colspan="6" scope="rowgroup"><?php echo esc_html(dbvc_capability_landscape_label($current_category)); ?></th></tr>
					<?php endif; ?>
					<tr
						data-dbvc-capability-row
						data-search="<?php echo esc_attr($item['search']); ?>"
						data-category="<?php echo esc_attr($item['category']); ?>"
						data-status="<?php echo esc_attr($item['status']); ?>"
						data-surfaces="<?php echo esc_attr(' ' . implode(' ', $item['surface_types']) . ' '); ?>"
						data-opportunity="<?php echo esc_attr($item['opportunity']['disposition']); ?>"
						data-agent-uses="<?php echo esc_attr(' ' . implode(' ', $item['agent_uses']) . ' '); ?>">
						<td class="dbvc-capability-primary">
							<strong><?php echo esc_html($record['title']); ?></strong>
							<code><?php echo esc_html($record['id']); ?></code>
							<p><?php echo esc_html($record['summary']); ?></p>
							<small><?php echo esc_html($record['addon_or_owner'] ?? 'DBVC'); ?></small>
						</td>
						<td>
							<?php dbvc_capability_landscape_chip($item['status'], 'is-status is-' . sanitize_html_class($item['status'])); ?>
							<?php dbvc_capability_landscape_chip($item['category'], 'is-category'); ?>
							<?php foreach ($item['scopes'] as $scope) : dbvc_capability_landscape_chip($scope, 'is-scope'); endforeach; ?>
						</td>
						<td>
							<?php if (empty($item['surfaces'])) : ?><span class="description"><?php esc_html_e('No callable interface', 'dbvc'); ?></span><?php endif; ?>
							<?php foreach ($item['surfaces'] as $surface) : ?>
								<div class="dbvc-capability-interface"><?php dbvc_capability_landscape_chip($surface['type'], 'is-surface'); ?><?php if ('' !== $surface['identifier']) : ?><code><?php echo esc_html($surface['identifier']); ?></code><?php endif; ?></div>
							<?php endforeach; ?>
						</td>
						<td>
							<?php foreach ($item['operations'] as $value) : dbvc_capability_landscape_chip($value, 'is-operation'); endforeach; ?>
							<?php foreach ($item['objects'] as $value) : dbvc_capability_landscape_chip($value, 'is-object'); endforeach; ?>
							<?php foreach ($item['workflows'] as $value) : dbvc_capability_landscape_chip($value, 'is-workflow'); endforeach; ?>
						</td>
						<td>
							<?php dbvc_capability_landscape_chip($item['safety'], 'is-safety is-' . sanitize_html_class($item['safety'])); ?>
							<?php if (! empty($record['storage_touched'])) : ?><details><summary><?php esc_html_e('Data/storage touched', 'dbvc'); ?></summary><ul><?php foreach ((array) $record['storage_touched'] as $storage) : ?><li><?php echo esc_html($storage); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
							<?php if (! empty($record['safety']['warnings'][0])) : ?><p class="description"><?php echo esc_html($record['safety']['warnings'][0]); ?></p><?php endif; ?>
						</td>
						<td>
							<?php foreach ($item['agent_uses'] as $agent_use) : ?>
								<?php
								$agent_labels = [
									'cli-ready'       => __('CLI ready', 'dbvc'),
									'safe-inspection' => __('Read-only starting point', 'dbvc'),
									'cli-candidate'    => __('Potential CLI parity', 'dbvc'),
									'parity-review'    => __('Unreviewed REST parity', 'dbvc'),
									'parity-covered'   => __('Parity covered elsewhere', 'dbvc'),
									'parity-deferred'  => __('Parity deferred', 'dbvc'),
									'write-gated'      => __('Write-gated', 'dbvc'),
									'non-current'      => __('Not currently callable', 'dbvc'),
								];
								?>
								<span class="dbvc-capability-chip is-agent-use is-<?php echo esc_attr(sanitize_html_class($agent_use)); ?>"><?php echo esc_html($agent_labels[$agent_use] ?? $agent_use); ?></span>
							<?php endforeach; ?>
							<?php if ('unreviewed' !== $item['opportunity']['disposition']) : ?>
								<div class="dbvc-capability-opportunity">
									<?php dbvc_capability_landscape_chip($item['opportunity']['disposition'], 'is-opportunity is-' . sanitize_html_class($item['opportunity']['disposition'])); ?>
									<span class="dbvc-capability-chip is-priority is-<?php echo esc_attr(sanitize_html_class($item['opportunity']['priority'])); ?>"><?php echo esc_html(sprintf(__('Priority: %s', 'dbvc'), dbvc_capability_landscape_label($item['opportunity']['priority']))); ?></span>
									<span class="dbvc-capability-chip is-effort"><?php echo esc_html(sprintf(__('Effort: %s', 'dbvc'), dbvc_capability_landscape_label($item['opportunity']['effort']))); ?></span>
									<?php if ('none' !== $item['opportunity']['recommended_surface']) : ?><code><?php echo esc_html(sprintf(__('Recommended: %s', 'dbvc'), dbvc_capability_landscape_label($item['opportunity']['recommended_surface']))); ?></code><?php endif; ?>
									<p class="description"><?php echo esc_html($item['opportunity']['rationale']); ?></p>
									<?php if ('' !== $item['opportunity']['candidate_scope']) : ?><p><strong><?php esc_html_e('Candidate boundary:', 'dbvc'); ?></strong> <?php echo esc_html($item['opportunity']['candidate_scope']); ?></p><?php endif; ?>
									<?php if (! empty($item['opportunity']['excluded_operations'])) : ?><details><summary><?php esc_html_e('Explicitly excluded', 'dbvc'); ?></summary><ul><?php foreach ($item['opportunity']['excluded_operations'] as $excluded_operation) : ?><li><?php echo esc_html($excluded_operation); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
									<?php if ('' !== $item['opportunity']['next_action']) : ?><p><strong><?php esc_html_e('Next:', 'dbvc'); ?></strong> <?php echo esc_html($item['opportunity']['next_action']); ?></p><?php endif; ?>
								</div>
							<?php elseif (in_array('parity-review', $item['agent_uses'], true)) : ?><p class="description"><?php esc_html_e('Active REST behavior without a mapped CLI surface; no task-specific parity judgment has been recorded yet.', 'dbvc'); ?></p><?php endif; ?>
							<?php if (! empty($record['known_gaps'])) : ?><details><summary><?php esc_html_e('Known gaps', 'dbvc'); ?></summary><ul><?php foreach ((array) $record['known_gaps'] as $gap) : ?><li><?php echo esc_html($gap); ?></li><?php endforeach; ?></ul></details><?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr id="dbvc-capability-empty" hidden><td colspan="6"><?php esc_html_e('No capability records match these filters.', 'dbvc'); ?></td></tr>
				</tbody>
			</table>
		</div>
	</div>

	<style>
		.dbvc-capability-landscape { max-width:100%; }
		.dbvc-capability-landscape__intro { margin-bottom:1rem; }
		.dbvc-capability-landscape__intro h3 { margin-top:0; }
		.dbvc-capability-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:.75rem; margin:1rem 0 1.25rem; }
		.dbvc-capability-stats > div { border:1px solid #dcdcde; border-radius:6px; background:#f6f7f7; padding:.8rem; }
		.dbvc-capability-stats strong { display:block; color:#1d2327; font-size:1.5rem; line-height:1.1; }
		.dbvc-capability-stats span { display:block; margin-top:.3rem; color:#50575e; }
		.dbvc-capability-filters { display:grid; grid-template-columns:minmax(220px,2fr) repeat(4,minmax(145px,1fr)) auto; gap:.75rem; align-items:end; border:1px solid #dcdcde; border-radius:6px; padding:1rem; background:#fff; }
		.dbvc-capability-filters label span { display:block; margin-bottom:.3rem; font-weight:600; }
		.dbvc-capability-filters input,.dbvc-capability-filters select { width:100%; min-height:34px; }
		.dbvc-capability-results { margin:.9rem 0; font-weight:600; }
		.dbvc-capability-table-wrap { overflow-x:auto; border:1px solid #dcdcde; }
		.dbvc-capability-table { min-width:1450px; border:0; }
		.dbvc-capability-table th,.dbvc-capability-table td { vertical-align:top; }
		.dbvc-capability-table thead th { position:sticky; top:32px; z-index:2; background:#f0f0f1; }
		.dbvc-capability-group th { background:#dcdcde; color:#1d2327; font-size:13px; letter-spacing:.02em; text-transform:uppercase; }
		.dbvc-capability-primary { width:260px; }
		.dbvc-capability-primary strong,.dbvc-capability-primary code,.dbvc-capability-primary small { display:block; }
		.dbvc-capability-primary code { margin:.3rem 0; word-break:break-word; }
		.dbvc-capability-primary p { margin:.45rem 0; }
		.dbvc-capability-interface { margin-bottom:.45rem; }
		.dbvc-capability-interface code { display:block; margin-top:.2rem; white-space:normal; word-break:break-word; }
		.dbvc-capability-chip { display:inline-block; margin:0 .25rem .3rem 0; border:1px solid #c3c4c7; border-radius:999px; padding:.14rem .48rem; background:#f6f7f7; color:#2c3338; font-size:11px; line-height:1.45; }
		.dbvc-capability-chip.is-status,.dbvc-capability-chip.is-safety,.dbvc-capability-chip.is-agent-use { font-weight:600; }
		.dbvc-capability-chip.is-active,.dbvc-capability-chip.is-read_only,.dbvc-capability-chip.is-safe-inspection,.dbvc-capability-chip.is-cli-ready { border-color:#68de7c; background:#edfaef; color:#116329; }
		.dbvc-capability-chip.is-experimental,.dbvc-capability-chip.is-mixed,.dbvc-capability-chip.is-cli-candidate,.dbvc-capability-chip.is-parity-review,.dbvc-capability-chip.is-needs_review,.dbvc-capability-chip.is-medium { border-color:#dba617; background:#fcf9e8; color:#664d03; }
		.dbvc-capability-chip.is-wordpress_write,.dbvc-capability-chip.is-filesystem_write,.dbvc-capability-chip.is-remote_write,.dbvc-capability-chip.is-destructive,.dbvc-capability-chip.is-write-gated { border-color:#d63638; background:#fcf0f1; color:#8a2424; }
		.dbvc-capability-chip.is-candidate,.dbvc-capability-chip.is-high { border-color:#2271b1; background:#eaf3fb; color:#0a4b78; }
		.dbvc-capability-opportunity { margin-top:.55rem; padding-top:.55rem; border-top:1px solid #dcdcde; }
		.dbvc-capability-chip.is-planned,.dbvc-capability-chip.is-source_reference,.dbvc-capability-chip.is-absent_current_checkout,.dbvc-capability-chip.is-non-current { border-color:#8c8f94; background:#f0f0f1; color:#50575e; }
		.dbvc-capability-table details { margin-top:.45rem; }
		.dbvc-capability-table details ul { margin:.35rem 0 0 1.1rem; }
		@media (max-width:1200px) { .dbvc-capability-filters { grid-template-columns:repeat(2,minmax(180px,1fr)); } }
		@media (max-width:782px) { .dbvc-capability-filters { grid-template-columns:1fr; } .dbvc-capability-table thead th { top:46px; } }
	</style>

	<script>
	(function() {
		const root = document.getElementById('dbvc-capability-landscape');
		if (!root) return;
		const search = root.querySelector('#dbvc-capability-search');
		const category = root.querySelector('#dbvc-capability-category');
		const status = root.querySelector('#dbvc-capability-status');
		const surface = root.querySelector('#dbvc-capability-surface');
		const opportunity = root.querySelector('#dbvc-capability-opportunity');
		const agentUse = root.querySelector('#dbvc-capability-agent-use');
		const reset = root.querySelector('#dbvc-capability-reset');
		const result = root.querySelector('#dbvc-capability-results');
		const empty = root.querySelector('#dbvc-capability-empty');
		const rows = Array.from(root.querySelectorAll('[data-dbvc-capability-row]'));
		const groups = Array.from(root.querySelectorAll('[data-dbvc-capability-group]'));

		function applyFilters() {
			const needle = (search.value || '').trim().toLowerCase();
			let visible = 0;
			rows.forEach(function(row) {
				const matches = (!needle || row.dataset.search.indexOf(needle) !== -1)
					&& (!category.value || row.dataset.category === category.value)
					&& (!status.value || row.dataset.status === status.value)
					&& (!surface.value || row.dataset.surfaces.indexOf(' ' + surface.value + ' ') !== -1)
					&& (!opportunity.value || row.dataset.opportunity === opportunity.value)
					&& (!agentUse.value || row.dataset.agentUses.indexOf(' ' + agentUse.value + ' ') !== -1);
				row.hidden = !matches;
				if (matches) visible += 1;
			});

			groups.forEach(function(group) {
				const groupCategory = group.dataset.dbvcCapabilityGroup;
				group.hidden = !rows.some(function(row) {
					return !row.hidden && row.dataset.category === groupCategory;
				});
			});

			empty.hidden = visible !== 0;
			result.textContent = <?php echo wp_json_encode(__('Showing %1$d of %2$d capability records.', 'dbvc')); ?>
				.replace('%1$d', visible)
				.replace('%2$d', rows.length);
		}

		[search, category, status, surface, opportunity, agentUse].forEach(function(control) {
			control.addEventListener(control === search ? 'input' : 'change', applyFilters);
		});
		reset.addEventListener('click', function() {
			search.value = '';
			category.value = '';
			status.value = '';
			surface.value = '';
			opportunity.value = '';
			agentUse.value = '';
			applyFilters();
			search.focus();
		});
	})();
	</script>
	<?php
}
