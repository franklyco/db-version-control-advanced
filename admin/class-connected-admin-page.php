<?php

/**
 * Dedicated "Connected Environments" admin page: menu entry, app assets and
 * the role-neutral admin REST routes (overview + settings). Exists only while
 * the connector or the hub gate is ready, so disabled modules add no menu,
 * assets or routes. Role-specific routes are registered by each add-on's
 * runtime (`dbvc/v1/connected/*`, `dbvc/v1/agency/*`).
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
	die;
}

if (! class_exists('DBVC_Connected_Admin_Page')) {
	final class DBVC_Connected_Admin_Page {
		public const MENU_SLUG = 'dbvc-connected';
		public const CAPABILITY = 'manage_options';
		public const REST_NAMESPACE = 'dbvc/v1';
		public const SCRIPT_HANDLE = 'dbvc-connected-app';

		/**
		 * @return void
		 */
		public static function init() {
			add_action('admin_menu', [self::class, 'register_menu'], 20);
			add_action('rest_api_init', [self::class, 'register_routes']);
		}

		/**
		 * True when at least one gate is ready: the page's reason to exist.
		 *
		 * @return bool
		 */
		public static function is_available() {
			return self::connector_ready() || self::hub_ready();
		}

		/**
		 * @return bool
		 */
		public static function connector_ready() {
			return class_exists('DBVC_Connected_Environments_Addon') && DBVC_Connected_Environments_Addon::get_gate_state() === \Dbvc\ConnectedProtocol\RoleGate::READY;
		}

		/**
		 * @return bool
		 */
		public static function hub_ready() {
			return class_exists('DBVC_Agency_Control_Addon') && DBVC_Agency_Control_Addon::get_gate_state() === \Dbvc\ConnectedProtocol\RoleGate::READY;
		}

		/**
		 * @return void
		 */
		public static function register_menu() {
			if (! self::is_available()) {
				return;
			}
			$hook = add_submenu_page(
				'dbvc-export',
				esc_html__('Connected Environments', 'dbvc'),
				esc_html__('Connected Environments', 'dbvc'),
				self::CAPABILITY,
				self::MENU_SLUG,
				[self::class, 'render']
			);
			if ($hook) {
				add_action('load-' . $hook, [self::class, 'enqueue']);
			}
		}

		/**
		 * @return void
		 */
		public static function enqueue() {
			add_action('admin_enqueue_scripts', static function () {
				$asset_file = DBVC_PLUGIN_PATH . 'build/connected-app.asset.php';
				$asset = file_exists($asset_file) ? include $asset_file : ['dependencies' => [], 'version' => DBVC_PLUGIN_VERSION];
				$dependencies = array_values(array_unique(array_merge((array) ($asset['dependencies'] ?? []), ['wp-element', 'wp-i18n', 'wp-api-fetch'])));
				wp_enqueue_script(self::SCRIPT_HANDLE, DBVC_PLUGIN_URL . 'build/connected-app.js', $dependencies, (string) ($asset['version'] ?? DBVC_PLUGIN_VERSION), true);
				if (file_exists(DBVC_PLUGIN_PATH . 'build/style-connected-app.css')) {
					wp_enqueue_style(self::SCRIPT_HANDLE, DBVC_PLUGIN_URL . 'build/style-connected-app.css', [], (string) ($asset['version'] ?? DBVC_PLUGIN_VERSION));
				}
				wp_localize_script(self::SCRIPT_HANDLE, 'DBVC_CONNECTED_APP', self::bootstrap_config());
			});
		}

		/**
		 * Configuration the app reads once; everything else comes from the admin REST routes.
		 *
		 * @return array<string, mixed>
		 */
		public static function bootstrap_config() {
			return [
				'root' => esc_url_raw(rest_url()),
				'namespace' => self::REST_NAMESPACE,
				'nonce' => wp_create_nonce('wp_rest'),
				'roles' => ['connector' => self::connector_ready(), 'hub' => self::hub_ready()],
				'gates' => [
					'connector' => class_exists('DBVC_Connected_Environments_Addon') ? DBVC_Connected_Environments_Addon::get_gate_state() : 'unavailable',
					'hub' => class_exists('DBVC_Agency_Control_Addon') ? DBVC_Agency_Control_Addon::get_gate_state() : 'unavailable',
				],
				'pluginVersion' => DBVC_PLUGIN_VERSION,
				'addonsUrl' => admin_url('admin.php?page=dbvc-export#dbvc-addon-connected'),
			];
		}

		/**
		 * @return void
		 */
		public static function render() {
			if (! current_user_can(self::CAPABILITY)) {
				wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'));
			}
			// wp-header-end keeps core/plugin admin notices above the app header instead of inside it.
			echo '<div class="wrap dbvc-connected-wrap"><hr class="wp-header-end">';
			echo '<div id="dbvc-connected-app" class="dbvc-ce" data-dbvc-connected-app>';
			echo '<noscript><p>' . esc_html__('The Connected Environments page needs JavaScript. Use `wp dbvc connected` / `wp dbvc agency` instead.', 'dbvc') . '</p></noscript>';
			echo '</div></div>';
		}

		/**
		 * Role-neutral routes; role-specific ones live with the add-ons.
		 *
		 * @return void
		 */
		public static function register_routes() {
			if (! self::is_available()) {
				return;
			}
			register_rest_route(self::REST_NAMESPACE, '/connected-admin/overview', [
				'methods' => WP_REST_Server::READABLE,
				'callback' => [self::class, 'overview'],
				'permission_callback' => [self::class, 'permission'],
			]);
			register_rest_route(self::REST_NAMESPACE, '/connected-admin/settings', [
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => [self::class, 'save_settings'],
				'permission_callback' => [self::class, 'permission'],
			]);
		}

		/**
		 * @return bool|WP_Error
		 */
		public static function permission() {
			if (! current_user_can(self::CAPABILITY)) {
				return new WP_Error('dbvc_connected_admin_forbidden', __('Administrator capability required.', 'dbvc'), ['status' => rest_authorization_required_code()]);
			}

			return true;
		}

		/**
		 * Gates, roles and both status reports in one round trip.
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response
		 */
		public static function overview(WP_REST_Request $request) {
			unset($request);
			$connector = self::connector_ready() ? DBVC_Connected_CLI_Inspector::status() : null;
			$hub = self::hub_ready() ? DBVC_Agency_CLI_Inspector::status() : null;

			return self::respond([
				'roles' => ['connector' => self::connector_ready(), 'hub' => self::hub_ready()],
				'gates' => self::bootstrap_config()['gates'],
				'connector' => is_wp_error($connector) ? null : $connector,
				'hub' => is_wp_error($hub) ? null : $hub,
				'settings' => [
					'connector' => class_exists('DBVC_Connected_Environments_Addon') ? DBVC_Connected_Environments_Addon::get_all_settings() : [],
					'hub' => class_exists('DBVC_Agency_Control_Addon') ? DBVC_Agency_Control_Addon::get_all_settings() : [],
				],
				'generated_at' => gmdate('c'),
			]);
		}

		/**
		 * Same semantics as the Add-ons tab save: each add-on validates its own keys.
		 *
		 * @param WP_REST_Request $request
		 * @return WP_REST_Response|WP_Error
		 */
		public static function save_settings(WP_REST_Request $request) {
			$body = $request->get_json_params();
			if (! is_array($body)) {
				return new WP_Error('dbvc_connected_admin_invalid_body', __('JSON body required.', 'dbvc'), ['status' => 400]);
			}
			$results = [];
			$errors = [];
			if (array_key_exists('connector', $body) && class_exists('DBVC_Connected_Environments_Addon')) {
				$values = is_array($body['connector']) ? $body['connector'] : [];
				$request_data = [];
				foreach ([DBVC_Connected_Environments_Addon::OPTION_ENABLED => 'enabled', DBVC_Connected_Environments_Addon::OPTION_APPLY_ENABLED => 'apply_enabled'] as $option => $key) {
					if (! empty($values[$key])) {
						$request_data[$option] = '1';
					}
				}
				$result = DBVC_Connected_Environments_Addon::save_settings($request_data);
				$results['connector'] = $result['values'];
				$errors = array_merge($errors, (array) ($result['errors'] ?? []));
			}
			if (array_key_exists('hub', $body) && class_exists('DBVC_Agency_Control_Addon')) {
				$values = is_array($body['hub']) ? $body['hub'] : [];
				$result = DBVC_Agency_Control_Addon::save_settings(! empty($values['enabled']) ? [DBVC_Agency_Control_Addon::OPTION_ENABLED => '1'] : []);
				$results['hub'] = $result['values'];
				$errors = array_merge($errors, (array) ($result['errors'] ?? []));
			}

			return self::respond([
				'saved' => $results,
				'errors' => array_values(array_map('strval', $errors)),
				'roles' => ['connector' => self::connector_ready(), 'hub' => self::hub_ready()],
				'gates' => self::bootstrap_config()['gates'],
				'page_available' => self::is_available(),
			]);
		}

		/**
		 * @param array<string, mixed>|WP_Error $result
		 * @return WP_REST_Response|WP_Error
		 */
		public static function respond($result) {
			if (is_wp_error($result)) {
				$data = (array) $result->get_error_data();
				if (! isset($data['status'])) {
					$result->add_data(['status' => 400]);
				}

				return $result;
			}
			$response = rest_ensure_response($result);
			$response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');

			return $response;
		}
	}
}
