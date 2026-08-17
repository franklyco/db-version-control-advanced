<?php

use Dbvc\ConfigurationPortability\Registry;

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Configuration_Portability_CLI_Inspector')) {
	/**
	 * Prepare bounded configuration portability schema metadata for WP-CLI.
	 */
	final class DBVC_Configuration_Portability_CLI_Inspector {
		private const FORMATS = ['table', 'json', 'csv', 'yaml', 'count'];

		/**
		 * Return one compact row per registered portability domain.
		 *
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		public static function domains(array $assoc_args) {
			$guard = self::guard_arguments($assoc_args, ['domain', 'format']);
			if (is_wp_error($guard)) {
				return $guard;
			}

			$domain_filter = sanitize_key((string) ($assoc_args['domain'] ?? ''));
			$rows = [];
			foreach (Registry::get_providers() as $provider) {
				if ($domain_filter !== '' && $provider->get_key() !== $domain_filter) {
					continue;
				}

				$rows[] = self::domain_row($provider);
			}

			if ($domain_filter !== '' && $rows === []) {
				return new WP_Error('dbvc_config_cli_domain_missing', 'Configuration portability domain not found: ' . $domain_filter);
			}

			return $rows;
		}

		/**
		 * Return aggregate portability registry metadata.
		 *
		 * @param array $assoc_args Named command arguments.
		 * @return array|WP_Error
		 */
		public static function status(array $assoc_args) {
			$guard = self::guard_arguments($assoc_args, ['format']);
			if (is_wp_error($guard)) {
				return $guard;
			}

			$rows = [];
			foreach (Registry::get_providers() as $provider) {
				$rows[] = self::domain_row($provider);
			}

			$totals = [
				'groups' => 0,
				'fields' => 0,
				'portable' => 0,
				'exclude' => 0,
				'redact' => 0,
				'prompt' => 0,
				'replace' => 0,
				'keep_existing' => 0,
				'advanced' => 0,
				'sensitive' => 0,
			];
			foreach ($rows as $row) {
				foreach (array_keys($totals) as $key) {
					$totals[$key] += (int) $row[$key];
				}
			}

			return array_merge(
				[
					'feature' => 'dbvc_configuration_portability',
					'feature_version' => '0.1.0',
					'domains' => count($rows),
				],
				$totals,
				[
					'current_values_read' => 'no',
					'writer_services_invoked' => 'no',
				]
			);
		}

		/**
		 * @param Dbvc\ConfigurationPortability\DomainProviderInterface $provider Domain provider.
		 * @return array
		 */
		private static function domain_row($provider): array {
			$fields = $provider->get_fields();
			$row = [
				'key' => (string) $provider->get_key(),
				'label' => (string) $provider->get_label(),
				'version' => (int) $provider->get_version(),
				'groups' => count($provider->get_groups()),
				'fields' => count($fields),
				'portable' => 0,
				'exclude' => 0,
				'redact' => 0,
				'prompt' => 0,
				'replace' => 0,
				'keep_existing' => 0,
				'advanced' => 0,
				'sensitive' => 0,
			];

			foreach ($fields as $field) {
				if (! is_array($field)) {
					continue;
				}
				$policy = sanitize_key((string) ($field['environment_policy'] ?? 'portable'));
				if (array_key_exists($policy, $row)) {
					++$row[$policy];
				}
				if (! empty($field['sensitive'])) {
					++$row['sensitive'];
				}
			}

			return $row;
		}

		/**
		 * Reject unknown arguments before provider metadata is inspected.
		 *
		 * @param array $assoc_args Named command arguments.
		 * @param array $allowed    Allowed argument names.
		 * @return true|WP_Error
		 */
		private static function guard_arguments(array $assoc_args, array $allowed) {
			foreach (array_keys($assoc_args) as $argument) {
				if (! in_array((string) $argument, $allowed, true)) {
					return new WP_Error('dbvc_config_cli_read_only', 'The configuration portability metadata CLI is read-only and rejects --' . $argument . '.');
				}
			}

			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			if (! in_array($format, self::FORMATS, true)) {
				return new WP_Error('dbvc_config_cli_format_invalid', 'Format must be table, json, csv, yaml, or count.');
			}

			return true;
		}
	}
}

if (defined('WP_CLI') && WP_CLI && ! class_exists('DBVC_WP_CLI_Configuration_Portability')) {
	/**
	 * Inspect DBVC configuration portability provider metadata without reading values or invoking writers.
	 */
	class DBVC_WP_CLI_Configuration_Portability extends WP_CLI_Command {
		/**
		 * List registered configuration portability domains and schema counts.
		 *
		 * ## OPTIONS
		 *
		 * [--domain=<key>]
		 * : Return one exact registered domain.
		 *
		 * [--format=<format>]
		 * : Output format: table, json, csv, yaml, or count. Default: table.
		 *
		 * ## EXAMPLES
		 *
		 *     wp dbvc config domains
		 *     wp dbvc config domains --domain=visual_editor --format=json
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 */
		public function domains($args, $assoc_args) {
			$rows = DBVC_Configuration_Portability_CLI_Inspector::domains((array) $assoc_args);
			if (is_wp_error($rows)) {
				WP_CLI::error($rows->get_error_message());
			}

			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			\WP_CLI\Utils\format_items(
				$format,
				$rows,
				['key', 'label', 'version', 'groups', 'fields', 'portable', 'exclude', 'redact', 'prompt', 'replace', 'keep_existing', 'advanced', 'sensitive']
			);
		}

		/**
		 * Summarize registered configuration portability schema metadata.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format: table, json, csv, yaml, or count. Default: table.
		 *
		 * ## EXAMPLES
		 *
		 *     wp dbvc config status
		 *     wp dbvc config status --format=json
		 *
		 * @param array $args Positional arguments.
		 * @param array $assoc_args Named arguments.
		 */
		public function status($args, $assoc_args) {
			$status = DBVC_Configuration_Portability_CLI_Inspector::status((array) $assoc_args);
			if (is_wp_error($status)) {
				WP_CLI::error($status->get_error_message());
			}

			$format = sanitize_key((string) ($assoc_args['format'] ?? 'table'));
			\WP_CLI\Utils\format_items($format, [$status], array_keys($status));
		}
	}

	WP_CLI::add_command('dbvc config', 'DBVC_WP_CLI_Configuration_Portability');
}
