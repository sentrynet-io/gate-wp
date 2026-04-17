<?php

declare(strict_types=1);

/**
 * Plugin Name: SentryNet Gate
 * Description: Bot protection middleware using the SentryNet API.
 * Version: 1.0.0
 * Author: SentryNet
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class SentryNetGate
{
    private \SentryNet\Settings $settings;
    private \SentryNet\ApiClient $apiClient;
    private \SentryNet\RequestProcessor $requestProcessor;

    public function __construct()
    {
        $this->settings = new \SentryNet\Settings();

        add_action('admin_menu', [$this->settings, 'register_menu']);
        add_action('admin_init', [$this->settings, 'register_settings']);

        // Handle custom routes (challenge page, verify) before WordPress routing.
        add_action('init', [$this, 'handle_custom_routes'], 1);

        // Main middleware runs at template_redirect so is_user_logged_in() is available.
        add_action('template_redirect', [$this, 'handle_request'], 1);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function handle_custom_routes(): void
    {
        $config = $this->settings->get();
        if (empty($config['enabled']) || empty($config['app_id']) || empty($config['api_key'])) {
            return;
        }

        $path = $this->get_request_path();

        if ($path === '/challenge-' . $config['app_id']) {
            $this->serve_challenge_page();
        }

        if ($path === '/sn-verify' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $cookie_name = $config['cookie_name'] ?? 'sentrynet_ftoken';
            $this->handle_verify($cookie_name);
        }
    }

    public function handle_request(): void
    {
        if ($this->should_skip()) {
            return;
        }

        $requestProcessor = $this->get_request_processor();
        if (empty($requestProcessor)) {
            return;
        }

        $response = $requestProcessor->process();
        if (empty($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Unable to process request, path=' . $this->get_request_path() . ", ip=" . $this->get_client_ip());
            }

            return;
        }

        switch ($response->action) {
            case \SentryNet\RequestAction::RENDER:
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Rendering with reason={$response->reason}");
                }

                $this->render($response->body);

                return;
            case \SentryNet\RequestAction::PASSTHROUGH:
                return;
            default:
                throw new \UnhandledMatchError();
        }
    }

    public function enqueue_scripts(): void
    {
        $config = $this->settings->get();
        if (empty($config['enabled']) || empty($config['app_id']) || empty($config['api_key'])) {
            return;
        }

        $src = 'https://sdk-cdn.sentrynet.io/sdk.js?' . http_build_query([
            'app_id' => $config['app_id'],
            'render'  => 'true',
        ]);

        wp_enqueue_script('sentrynet-sdk', $src, [], null, true);
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function get_request_path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    }

    private function get_api_client(): \SentryNet\ApiClient|null
    {
        $config = $this->settings->get();
        if (empty($config['enabled']) || empty($config['app_id']) || empty($config['api_key'])) {
            return null;
        }

        if (!empty($this->apiClient)) {
            return $this->apiClient;
        }

        return $this->apiClient = new \SentryNet\ApiClient(
            $config['app_id'],
            $config['api_key']
        );
    }

    private function get_request_processor(): \SentryNet\RequestProcessor|null
    {
        $config = $this->settings->get();
        if (empty($config['enabled']) || empty($config['app_id']) || empty($config['api_key'])) {
            return null;
        }

        if (!empty($this->requestProcessor)) {
            return $this->requestProcessor;
        }

        $cookieName = $config['cookie_name'] ?? 'sentrynet_ftoken';
        $token = $_COOKIE[$cookieName] ?? '';

        return $this->requestProcessor = new \SentryNet\RequestProcessor(
            $config['app_id'],
            $config['api_key'],
            $this->get_client_ip(),
            $this->get_request_path(),
            [], // STANTODO: Add support for whitelisted paths
            $token
        );
    }

    private function serve_challenge_page(): void
    {
        $port = (int) sanitize_text_field($_GET['challenge_port'] ?? '0');
        $challengeToken = sanitize_text_field($_GET['challenge_token'] ?? '');
        if ($port === 0 || empty($challengeToken)) {
            wp_redirect('/');
            exit;
        }

        $apiClient = $this->get_api_client();
        if (empty($apiClient)) {
            http_response_code(500);
            exit;
        }

        $html = $apiClient->getChallengePage(
            $port,
            $challengeToken,
            home_url('/sn-verify')
        );
        if (empty($html)) {
            http_response_code(500);
            exit;
        }

        $this->render($html);
    }

    private function handle_verify(string $cookie_name): void
    {
        $ip = $this->get_client_ip();
        if (empty($ip)) {
            http_response_code(422);
            exit;
        }

        $input          = json_decode(file_get_contents('php://input', false, null, 0, 8192), true) ?? [];
        $response       = sanitize_text_field($input['response'] ?? '');
        $port           = (int) ($input['challenge_port'] ?? 0);
        $challengeToken = sanitize_text_field($input['challenge_token'] ?? '');

        if (empty($response) || $port === 0 || empty($challengeToken)) {
            http_response_code(400);
            exit;
        }

        $apiClient = $this->get_api_client();
        if (empty($apiClient)) {
            http_response_code(500);
            exit;
        }

        $token = $apiClient->verifyChallenge(
            $ip,
            $response,
            $port,
            $challengeToken
        );
        if (!empty($token)) {
            setcookie(
                $cookie_name,
                $token,
                [
                    'expires'  => 0,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]
            );
            http_response_code(200);
        } else {
            http_response_code(422);
        }

        exit;
    }

    private function render(string $html): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function should_skip(): bool
    {
        if (wp_doing_cron()) {
            return true;
        }
        if (wp_doing_ajax()) {
            return true;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (is_admin()) {
            return true;
        }
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if (str_starts_with($path, '/wp-admin') || $path === '/wp-login.php') {
            return true;
        }

        return false;
    }

    private function get_client_ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

new SentryNetGate();
