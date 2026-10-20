<?php

/**
 * Loopback transport for connector ⇄ hub tests inside one WordPress install.
 * Intercepts `wp_remote_request`, authenticates the Basic header through
 * WordPress's own application-password path, and dispatches the request to
 * the in-process REST server. No real network is used and every request is
 * still authenticated by WordPress itself.
 */
trait ConnectedLoopbackTrait
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private $http_log = [];

    /**
     * @var WP_Error|null
     */
    private $simulated_transport_error = null;

    /**
     * @var callable|null Optional predicate deciding whether a given request should fail (receives url, args).
     */
    private $transport_failure_predicate = null;

    /**
     * @param mixed                $preempt
     * @param array<string, mixed> $args
     * @param string               $url
     * @return array<string, mixed>|WP_Error
     */
    public function loopback($preempt, $args, $url)
    {
        unset($preempt);
        $this->http_log[] = ['url' => $url, 'args' => $args];
        if ($this->simulated_transport_error instanceof WP_Error) {
            $predicate = $this->transport_failure_predicate;
            if ($predicate === null || $predicate($url, $args)) {
                return $this->simulated_transport_error;
            }
        }
        $this->assertTrue($args['sslverify']);
        $this->assertSame(\Dbvc\ConnectedProtocol\Protocol::REQUEST_TIMEOUT_SECONDS, $args['timeout']);

        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;
        if (! empty($args['headers']['Authorization'])) {
            list($username, $password) = explode(':', base64_decode(substr((string) $args['headers']['Authorization'], 6)), 2);
            $user = wp_authenticate_application_password(null, $username, $password);
            if ($user instanceof WP_User) {
                wp_set_current_user($user->ID);
            }
        }

        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $query);
        $route = (string) ($query['rest_route'] ?? '');
        unset($query['rest_route']);
        $request = new WP_REST_Request(strtoupper((string) ($args['method'] ?? 'POST')), $route);
        foreach ($args['headers'] as $name => $value) {
            if ($name !== 'Authorization') {
                $request->set_header($name, $value);
            }
        }
        if ($query !== []) {
            $request->set_query_params($query);
        }
        if (isset($args['body'])) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body((string) $args['body']);
        }
        $response = rest_do_request($request);
        $data = rest_get_server()->response_to_data($response, false);

        wp_set_current_user(0);
        $GLOBALS['wp_rest_application_password_uuid'] = null;

        return [
            'headers' => [],
            'body' => wp_json_encode($data),
            'response' => ['code' => $response->get_status(), 'message' => ''],
            'cookies' => [],
            'filename' => null,
        ];
    }
}
