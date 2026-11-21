<?php

namespace Ninja\Van\MY;

use Ninja\Van\MY\Worker\Webhook;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ninja Van checks in Tools → Site Health, and a section in its Info tab for support.
 * Every failed check names who can fix it. Nothing here shows a token, a secret or customer data.
 */
class Health
{
    const REST_NAMESPACE = 'ninja-van-my/v1';
    const REST_WEBHOOK_SUBSCRIPTIONS = '/health/webhook-subscriptions';

    function __construct() {
        add_action('http_api_debug', 'nv_my_health_record_api_call', 10, 5);
        add_filter('site_status_tests', [$this, 'register_tests']);
        add_filter('debug_information', [$this, 'debug_information']);
        add_action('rest_api_init', [$this, 'register_rest_route']);
    }

    public function register_tests($tests) {
        $direct = [
            'ninja_van_connection' => [__('Ninja Van connection', 'ninja-van-my'), 'test_connection'],
            'ninja_van_storage' => [__('Saved data survives between page loads', 'ninja-van-my'), 'test_storage'],
            'ninja_van_cron' => [__('Ninja Van token refresh schedule', 'ninja-van-my'), 'test_cron'],
            'ninja_van_api_errors' => [__('Recent Ninja Van API errors', 'ninja-van-my'), 'test_api_errors'],
            'ninja_van_webhooks' => [__('Ninja Van webhooks reach this site', 'ninja-van-my'), 'test_webhooks'],
        ];

        foreach ($direct as $id => $test) {
            $tests['direct'][$id] = [
                'label' => $test[0],
                'test' => [$this, $test[1]],
            ];
        }

        // Asks Ninja Van over the network, so it runs after the page loads
        $tests['async']['ninja_van_webhook_subscriptions'] = [
            'label' => __('Ninja Van webhook subscriptions', 'ninja-van-my'),
            'test' => rest_url(self::REST_NAMESPACE . self::REST_WEBHOOK_SUBSCRIPTIONS),
            'has_rest' => true,
            'async_direct_test' => [$this, 'test_webhook_subscriptions'],
        ];

        return $tests;
    }

    public function register_rest_route() {
        register_rest_route(self::REST_NAMESPACE, self::REST_WEBHOOK_SUBSCRIPTIONS, [
            'methods' => 'GET',
            'callback' => [$this, 'rest_webhook_subscriptions'],
            'permission_callback' => [$this, 'can_view_site_health'],
        ]);
    }

    public function can_view_site_health() {
        return current_user_can('view_site_health_checks');
    }

    public function rest_webhook_subscriptions() {
        return rest_ensure_response($this->test_webhook_subscriptions());
    }

    public function test_connection() {
        $test = 'ninja_van_connection';

        if (nv_my_get_config('auth_mode') != 'PLUGIN') {
            return $this->result($test, 'good', __('Ninja Van uses API key login', 'ninja-van-my'), [
                __('This store connects with a client ID and secret, so there is no Ninja Van login to check.', 'ninja-van-my'),
            ]);
        }

        $tokens = nv_my_get_tokens();
        $health = nv_my_health_get();
        $error = isset($health['refresh_error']) ? $health['refresh_error'] : null;
        $error_line = $error ? $this->token_error_line($error) : '';

        if (!nv_my_has_valid_refresh_token($tokens)) {
            $lines = [__('This store is not connected to Ninja Van, so orders cannot be sent.', 'ninja-van-my')];
            if ($error_line !== '') {
                $lines[] = $error_line;
            }

            return $this->result($test, 'critical', __('Ninja Van is not connected', 'ninja-van-my'), $lines,
                $error ? $this->owner_for($error['status'], true) : __('the store owner: connect in Ninja Van (MY) → Settings.', 'ninja-van-my'),
                $this->settings_link()
            );
        }

        if ($error && (int) $error['time'] > $this->last_connected($health)) {
            $has_access = nv_my_has_valid_access_token($tokens);
            $lines = [$error_line];
            $lines[] = $has_access
                ? sprintf(__('The current access token still works until %s.', 'ninja-van-my'), $this->time_text($tokens['access_expires']))
                : __('There is no working access token, so orders cannot be sent until this is fixed.', 'ninja-van-my');

            return $this->result($test, $has_access ? 'recommended' : 'critical', __('Ninja Van token refresh is failing', 'ninja-van-my'), $lines,
                $this->owner_for($error['status'], true),
                $this->settings_link()
            );
        }

        return $this->result($test, 'good', __('Ninja Van is connected', 'ninja-van-my'), [
            sprintf(__('The access token works until %s.', 'ninja-van-my'), $this->time_text($tokens['access_expires'])),
            sprintf(
                __('Last login: %1$s. Last token refresh: %2$s.', 'ninja-van-my'),
                $this->time_text(isset($health['login']['time']) ? $health['login']['time'] : 0),
                $this->time_text(isset($health['refresh']['time']) ? $health['refresh']['time'] : 0)
            ),
        ]);
    }

    public function test_storage() {
        $test = 'ninja_van_storage';

        if (get_option('pw_tm_suspend')) {
            return $this->result($test, 'recommended', __('Transients are suspended on this site', 'ninja-van-my'), [
                __('The Transients Manager plugin is set to suspend transients, so WordPress deletes this kind of saved data the moment it is written.', 'ninja-van-my'),
                __('The Ninja Van login is not stored this way, but other plugins on this site are affected.', 'ninja-van-my'),
            ], __('the store owner: click Unsuspend Transients in the admin bar (Transients Manager).', 'ninja-van-my'));
        }

        $health = nv_my_health_get();
        $probe_time = isset($health['probe']['time']) ? (int) $health['probe']['time'] : 0;

        if (!$probe_time || time() - $probe_time >= HOUR_IN_SECONDS) {
            return $this->result($test, 'good', __('Saved data was not checked recently', 'ninja-van-my'), [
                __('This check runs with each Ninja Van token refresh, and none ran in the last hour.', 'ninja-van-my'),
            ]);
        }

        if (get_transient(Ninja_Van_MY_HEALTH_PROBE) === false) {
            $lines = [sprintf(__('A test value saved at %s, meant to last an hour, was already gone.', 'ninja-van-my'), $this->time_text($probe_time))];

            if (wp_using_ext_object_cache()) {
                $lines[] = sprintf(__('This site uses a persistent object cache (%s), and it is not keeping data — for example, a Redis server that refuses writes.', 'ninja-van-my'), $this->object_cache_name());
                $owner = __('the hosting provider: fix the object cache, or turn it off.', 'ninja-van-my');
            } else {
                $lines[] = __('Something deleted it early, usually a plugin that removes transients.', 'ninja-van-my');
                $owner = __('the store owner: find the plugin that removes transients.', 'ninja-van-my');
            }
            $lines[] = __('The Ninja Van login is not stored this way, but other plugins on this site are affected.', 'ninja-van-my');

            return $this->result($test, 'recommended', __('This site loses saved data between page loads', 'ninja-van-my'), $lines, $owner);
        }

        return $this->result($test, 'good', __('Saved data survives between page loads', 'ninja-van-my'), [
            sprintf(__('A test value saved at %s was still there.', 'ninja-van-my'), $this->time_text($probe_time)),
        ]);
    }

    public function test_cron() {
        $test = 'ninja_van_cron';

        if (nv_my_get_config('auth_mode') != 'PLUGIN' || !nv_my_has_valid_refresh_token()) {
            return $this->result($test, 'good', __('No Ninja Van token refresh needed', 'ninja-van-my'), [
                __('The token refresh only runs while the store is connected with the Ninja Van login.', 'ninja-van-my'),
            ]);
        }

        $next = wp_next_scheduled('ninja_van_refresh_token');
        if (!$next) {
            return $this->result($test, 'critical', __('The Ninja Van token refresh is not scheduled', 'ninja-van-my'), [
                __('Without it, the login expires and the store is disconnected.', 'ninja-van-my'),
            ], __('the store owner: disconnect and connect again in Ninja Van (MY) → Settings, which schedules it.', 'ninja-van-my'), $this->settings_link());
        }

        $cron_mode = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)
            ? __('WP-Cron is disabled in wp-config.php, so a server cron job must run it.', 'ninja-van-my')
            : __('WP-Cron runs only when someone visits the site.', 'ninja-van-my');
        $host_owner = __('the hosting provider: set up a server cron job that runs WP-Cron every 5 minutes.', 'ninja-van-my');

        $health = nv_my_health_get();
        $last = isset($health['cron']['time']) ? (int) $health['cron']['time'] : 0;

        if (!$last) {
            return $this->result($test, 'recommended', __('The Ninja Van token refresh has not run yet', 'ninja-van-my'), [
                sprintf(__('It is due at %s and should run every 5 minutes.', 'ninja-van-my'), $this->time_text($next)),
                $cron_mode,
            ], $host_owner);
        }

        if (time() - $last > Ninja_Van_MY_HEALTH_CRON_OVERDUE) {
            return $this->result($test, 'recommended', __('The Ninja Van token refresh is overdue', 'ninja-van-my'), [
                sprintf(__('It last ran at %1$s (%2$s ago), and should run every 5 minutes.', 'ninja-van-my'), $this->time_text($last), human_time_diff($last, time())),
                $cron_mode,
            ], $host_owner);
        }

        return $this->result($test, 'good', __('The Ninja Van token refresh runs on time', 'ninja-van-my'), [
            sprintf(__('Last run: %1$s, from %2$s. Next: %3$s.', 'ninja-van-my'), $this->time_text($last), $health['cron']['source'], $this->time_text($next)),
        ]);
    }

    public function test_api_errors() {
        $test = 'ninja_van_api_errors';
        $errors = $this->recent(nv_my_health_get(), 'api_errors');

        if (!$errors) {
            return $this->result($test, 'good', __('No recent Ninja Van API errors', 'ninja-van-my'), [
                __('No request to Ninja Van failed in the last 24 hours.', 'ninja-van-my'),
            ]);
        }

        $lines = [sprintf(__('Requests to Ninja Van that failed in the last 24 hours: %d. Latest first:', 'ninja-van-my'), count($errors))];
        foreach (array_slice($errors, 0, 5) as $call) {
            $lines[] = $this->api_call_line($call);
        }

        return $this->result($test, 'recommended', __('Some Ninja Van requests failed recently', 'ninja-van-my'), $lines,
            $this->owner_for($errors[0]['status'], $this->is_token_path($errors[0]['path']))
        );
    }

    public function test_webhooks() {
        $test = 'ninja_van_webhooks';

        if (get_option('permalink_structure') == '') {
            return $this->result($test, 'critical', __('Ninja Van webhooks cannot reach this site', 'ninja-van-my'), [
                __('Plain permalinks are on, so the webhook address does not work and orders never get Ninja Van status updates.', 'ninja-van-my'),
            ], __('the store owner: choose any other setting in Settings → Permalinks.', 'ninja-van-my'));
        }

        $health = nv_my_health_get();
        $received_line = $this->webhook_received_line($health);
        $rejections = $this->recent($health, 'webhook_rejections');

        if ($rejections) {
            $owner = $rejections[0]['reason'] === 'Wrong security code'
                ? __('the store owner: click Sync Webhook in Ninja Van (MY) → Settings, so Ninja Van uses this site\'s current webhook address.', 'ninja-van-my')
                : __('Ninja Van: their webhook arrived without the expected data.', 'ninja-van-my');

            return $this->result($test, 'recommended', __('Some Ninja Van webhooks were rejected', 'ninja-van-my'), [
                sprintf(__('Webhooks rejected in the last 24 hours: %1$d. Latest: %2$s, at %3$s.', 'ninja-van-my'), count($rejections), $rejections[0]['reason'], $this->time_text($rejections[0]['time'])),
                $received_line,
            ], $owner, $this->settings_link());
        }

        return $this->result($test, 'good', __('Ninja Van webhooks reach this site', 'ninja-van-my'), [$received_line]);
    }

    public function test_webhook_subscriptions() {
        $test = 'ninja_van_webhook_subscriptions';

        if (nv_my_get_config('auth_mode') != 'PLUGIN' || !nv_my_has_valid_refresh_token()) {
            return $this->result($test, 'good', __('Ninja Van webhook subscriptions were not checked', 'ninja-van-my'), [
                __('They are checked only while the store is connected with the Ninja Van login.', 'ninja-van-my'),
            ]);
        }

        $client = new Webhook();
        $response = $client->get_webhooks();

        if (empty($response['status'])) {
            return $this->result($test, 'recommended', __('Could not check Ninja Van webhook subscriptions', 'ninja-van-my'), [
                sprintf(__('Ninja Van answered: %s', 'ninja-van-my'), $response['message']),
            ], __('Ninja Van, if this keeps failing.', 'ninja-van-my'));
        }

        $host = (string) wp_parse_url(site_url(), PHP_URL_HOST);
        $security = 'security=' . nv_my_get_webhook_security();
        $count = 0;

        foreach ((array) $response['data'] as $webhook) {
            if (isset($webhook->uri) && strpos($webhook->uri, $host) !== false && strpos($webhook->uri, $security) !== false) {
                $count++;
            }
        }

        if (!$count) {
            return $this->result($test, 'recommended', __('No Ninja Van webhooks point at this site', 'ninja-van-my'), [
                __('Ninja Van will not send order status updates to this site.', 'ninja-van-my'),
            ], __('the store owner: click Sync Webhook in Ninja Van (MY) → Settings.', 'ninja-van-my'), $this->settings_link());
        }

        return $this->result($test, 'good', __('Ninja Van webhooks point at this site', 'ninja-van-my'), [
            sprintf(__('Ninja Van webhook subscriptions pointing at this site: %d.', 'ninja-van-my'), $count),
        ]);
    }

    /**
     * The "Ninja Van (MY)" section of Site Health → Info, which "Copy site info" gives support
     */
    public function debug_information($info) {
        $tokens = nv_my_get_tokens();
        $health = nv_my_health_get();
        $none = __('none', 'ninja-van-my');

        $error = isset($health['refresh_error']) ? $this->token_error_line($health['refresh_error']) : $none;
        $probe_time = isset($health['probe']['time']) ? (int) $health['probe']['time'] : 0;
        $rejections = $this->recent($health, 'webhook_rejections');
        $next_run = wp_next_scheduled('ninja_van_refresh_token');

        $values = [
            'version' => [__('Plugin version', 'ninja-van-my'), Ninja_Van_MY_VERSION],
            'woocommerce' => [__('WooCommerce version', 'ninja-van-my'), defined('WC_VERSION') ? WC_VERSION : __('not active', 'ninja-van-my')],
            'login' => [__('Login type', 'ninja-van-my'), nv_my_get_config('auth_mode') == 'PLUGIN' ? __('Ninja Van login (Plugin API)', 'ninja-van-my') : __('API key', 'ninja-van-my')],
            'environment' => [__('Environment', 'ninja-van-my'), nv_my_get_config('type') == 1 ? __('production', 'ninja-van-my') : __('sandbox', 'ninja-van-my')],
            'connected' => [__('Connected', 'ninja-van-my'), nv_my_has_valid_refresh_token($tokens) ? __('yes', 'ninja-van-my') : __('no', 'ninja-van-my')],
            'access_expires' => [__('Access token valid until', 'ninja-van-my'), $this->time_text($tokens['access_expires'])],
            'refresh_expires' => [__('Refresh token valid until', 'ninja-van-my'), $this->time_text($tokens['refresh_expires'])],
            'last_login' => [__('Last login', 'ninja-van-my'), $this->token_event_line($health, 'login')],
            'last_refresh' => [__('Last token refresh', 'ninja-van-my'), $this->token_event_line($health, 'refresh')],
            'last_refresh_error' => [__('Last token error', 'ninja-van-my'), $error],
            'refresh_schedule' => [__('Token refresh schedule', 'ninja-van-my'), sprintf(
                __('next run %1$s; WP-Cron disabled in wp-config.php: %2$s', 'ninja-van-my'),
                $next_run ? $this->time_text($next_run) : __('not scheduled', 'ninja-van-my'),
                (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? __('yes', 'ninja-van-my') : __('no', 'ninja-van-my')
            )],
            'last_refresh_run' => [__('Last token refresh run', 'ninja-van-my'), isset($health['cron']['time'])
                ? sprintf(__('%1$s, from %2$s', 'ninja-van-my'), $this->time_text($health['cron']['time']), $health['cron']['source'])
                : $none],
            'object_cache' => [__('Persistent object cache', 'ninja-van-my'), wp_using_ext_object_cache() ? $this->object_cache_name() : $none],
            'transients_suspended' => [__('Transients suspended (Transients Manager)', 'ninja-van-my'), get_option('pw_tm_suspend') ? __('yes', 'ninja-van-my') : __('no', 'ninja-van-my')],
            'storage_probe' => [__('Saved data test', 'ninja-van-my'), $this->storage_probe_line($probe_time)],
            'permalinks' => [__('Permalinks', 'ninja-van-my'), get_option('permalink_structure') ? get_option('permalink_structure') : __('plain', 'ninja-van-my')],
            'last_webhook' => [__('Last webhook', 'ninja-van-my'), $this->webhook_received_line($health)],
            'webhook_rejections' => [__('Webhooks rejected in the last 24 hours', 'ninja-van-my'), $rejections
                ? sprintf(__('%1$d; latest: %2$s at %3$s', 'ninja-van-my'), count($rejections), $rejections[0]['reason'], $this->time_text($rejections[0]['time']))
                : '0'],
            'api_errors' => [__('Recent API errors', 'ninja-van-my'), $this->numbered(array_map([$this, 'api_call_line'], $this->recent($health, 'api_errors')), $none)],
            'recent_events' => [__('Recent events', 'ninja-van-my'), $this->numbered(array_map([$this, 'event_line'], nv_my_health_get_log()), $none)],
        ];

        $fields = [];
        foreach ($values as $key => $value) {
            $fields[$key] = [
                'label' => $value[0],
                'value' => $value[1],
            ];
        }

        $info['ninja-van-my'] = [
            'label' => __('Ninja Van (MY)', 'ninja-van-my'),
            'description' => __('For Ninja Van support. It contains no tokens, secrets or customer details.', 'ninja-van-my'),
            'fields' => $fields,
        ];

        return $info;
    }

    private function result($test, $status, $label, $lines, $owner = '', $actions = '') {
        $description = '';
        foreach ($lines as $line) {
            $description .= '<p>' . esc_html($line) . '</p>';
        }

        if ($owner !== '') {
            $description .= '<p><strong>' . esc_html__('Who can fix this:', 'ninja-van-my') . '</strong> ' . esc_html($owner) . '</p>';
        }

        return [
            'label' => $label,
            'status' => $status,
            'badge' => [
                'label' => __('Ninja Van', 'ninja-van-my'),
                'color' => 'blue',
            ],
            'description' => $description,
            'actions' => $actions,
            'test' => $test,
        ];
    }

    /**
     * Who acts on a failed Ninja Van request, by its HTTP status
     */
    private function owner_for($status, $is_token_request) {
        $status = (int) $status;

        if ($status === 0) {
            return __('the hosting provider: this server could not reach Ninja Van.', 'ninja-van-my');
        }

        if ($status >= 500) {
            return __('Ninja Van: their service returned an error. Contact Ninja Van support if it continues.', 'ninja-van-my');
        }

        if ($is_token_request || $status === 401 || $status === 403) {
            return __('the store owner: log in to Ninja Van again in Ninja Van (MY) → Settings.', 'ninja-van-my');
        }

        return __('the store owner: check the order details Ninja Van rejected.', 'ninja-van-my');
    }

    private function last_connected($health) {
        return max(
            isset($health['login']['time']) ? (int) $health['login']['time'] : 0,
            isset($health['refresh']['time']) ? (int) $health['refresh']['time'] : 0
        );
    }

    /**
     * Entries of a health list from the last 24 hours, newest first
     */
    private function recent($health, $key) {
        $recent = [];
        $list = (isset($health[$key]) && is_array($health[$key])) ? $health[$key] : [];

        foreach ($list as $entry) {
            if (isset($entry['time']) && time() - (int) $entry['time'] < DAY_IN_SECONDS) {
                $recent[] = $entry;
            }
        }

        return $recent;
    }

    private function is_token_path($path) {
        return substr((string) $path, -strlen('/oauth/token')) === '/oauth/token';
    }

    private function time_text($timestamp) {
        return $timestamp ? gmdate('Y-m-d H:i:s', (int) $timestamp) . ' UTC' : __('never', 'ninja-van-my');
    }

    private function token_error_line($error) {
        return sprintf(
            __('The last %1$s failed at %2$s with HTTP %3$d: %4$s', 'ninja-van-my'),
            !empty($error['login']) ? __('login', 'ninja-van-my') : __('token refresh', 'ninja-van-my'),
            $this->time_text($error['time']),
            $error['status'],
            $error['message'] !== '' ? $error['message'] : __('no message', 'ninja-van-my')
        );
    }

    private function token_event_line($health, $key) {
        if (!isset($health[$key]['time'])) {
            return __('none', 'ninja-van-my');
        }

        return sprintf(
            __('%1$s; tokens valid for %2$d hours; new refresh token returned: %3$s', 'ninja-van-my'),
            $this->time_text($health[$key]['time']),
            round($health[$key]['expires_in'] / HOUR_IN_SECONDS),
            !empty($health[$key]['new_refresh_token']) ? __('yes', 'ninja-van-my') : __('no', 'ninja-van-my')
        );
    }

    private function webhook_received_line($health) {
        if (!isset($health['webhook_received']['time'])) {
            return __('No webhook received yet.', 'ninja-van-my');
        }

        $received = $health['webhook_received'];

        return sprintf(
            __('Last webhook received at %1$s: %2$s for order #%3$d — %4$s', 'ninja-van-my'),
            $this->time_text($received['time']),
            $received['status'],
            $received['order_id'],
            $received['outcome']
        );
    }

    public function api_call_line($call) {
        return sprintf(
            '%1$s — %2$s %3$s → HTTP %4$d%5$s',
            $this->time_text($call['time']),
            $call['method'],
            $call['path'],
            $call['status'],
            $call['error'] !== '' ? ': ' . $call['error'] : ''
        );
    }

    public function event_line($event) {
        return sprintf('%1$s — %2$s (%3$s): %4$s', $this->time_text($event['time']), $event['type'], $event['source'], $event['summary']);
    }

    /**
     * Site Health shows an array value as "key: value" lines, so number the entries
     */
    private function numbered($lines, $empty) {
        if (!$lines) {
            return $empty;
        }

        $numbered = [];
        foreach (array_values($lines) as $index => $line) {
            $numbered[$index + 1] = $line;
        }

        return $numbered;
    }

    /**
     * The probe lives one hour on purpose, so an older probe being gone says nothing about the site
     */
    private function storage_probe_line($probe_time) {
        if (!$probe_time) {
            return __('not run yet', 'ninja-van-my');
        }

        if (time() - $probe_time >= HOUR_IN_SECONDS) {
            return sprintf(__('last run %s; no result, because no token refresh ran in the last hour', 'ninja-van-my'), $this->time_text($probe_time));
        }

        return sprintf(
            __('saved %1$s; still there: %2$s', 'ninja-van-my'),
            $this->time_text($probe_time),
            get_transient(Ninja_Van_MY_HEALTH_PROBE) === false ? __('no — this site loses saved data', 'ninja-van-my') : __('yes', 'ninja-van-my')
        );
    }

    private function object_cache_name() {
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        if (!file_exists($dropin)) {
            return __('unknown', 'ninja-van-my');
        }

        $data = get_file_data($dropin, ['name' => 'Plugin Name']);

        return !empty($data['name']) ? $data['name'] : 'object-cache.php';
    }

    private function settings_link() {
        return '<p><a href="' . esc_url(admin_url('admin.php?page=ninja-van-my')) . '">' . esc_html__('Open Ninja Van (MY) settings', 'ninja-van-my') . '</a></p>';
    }
}

?>
