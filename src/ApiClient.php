<?php

declare(strict_types=1);

namespace SentryNet;

class ApiClient
{
    private const API_BASE = 'https://api.sentrynet.io/v1';
    private const TIMEOUT = 1;
    private const DEFAULT_BLOCKED_PAGE_CONTENTS = <<<BLOCKED
<!DOCTYPE html>
<html>
  <head>
    <title>You are blocked</title>
  </head>
  <body>
    You are blocked
  </body>
</html>
BLOCKED;
    private const DEFAULT_CHALLENGE_PAGE_CONTENTS = <<<CHALLENGE
<!DOCTYPE html>
<html>
  <head>
    <title>Please wait...</title>
  </head>
  <body>
  </body>
</html>
CHALLENGE;
    private const INJECTED_SCRIPT = <<<SCRIPT
    <script src="https://s3.sentrynet.io/sdk/sentry-net-sdk.umd.cjs"></script>
    <script type="application/javascript">
      SentryNetSDK.renderChallenge({ appId: '{{ app_id }}', verifyUrl: '{{ verify_challenge_url }}', challengePort: {{ challenge_port }}, challengeToken: '{{ challenge_token }}' });
    </script>
SCRIPT;

    public function __construct(
        private string $appId,
        private string $apiKey,
    ) {
    }

    public function checkAllowance(string $ip): int
    {
        $response = $this->sendGetRequest('/allowance/' . urlencode($ip));
        if (empty($response)) {
            return 200; // if the request failed, we're returning a 200
        }

        return $response->status;
    }

    public function validateToken(string $ip, string $token): ApiResponse|null
    {
        return $this->sendPostRequest(
            '/tokens/validate',
            [
                'request' => [
                    'ip' => $ip,
                    'token' => $token,
                ]
            ]
        );
    }

    public function getChallengeMeta(string $token): ApiResponse|null
    {
        $response = $this->sendPostRequest(
            '/challenges/meta',
            [
                'token' => $token,
            ]
        );
        if (empty($response) || $response->status !== 200) {
            return null;
        }

        return $response;
    }

    public function getChallengePage(int $port, string $challengeToken, string $verifyUrl): string|null
    {
        $response = $this->sendGetRequest('/challenge_page');
        if (empty($response) || $response->status !== 200) {
            return null;
        }

        $html = $response->body['data']['attributes']['content'] ?? '';
        if (empty($html)) {
            $html = self::DEFAULT_CHALLENGE_PAGE_CONTENTS;
        }

        $script = str_replace(
            [
                '{{ verify_challenge_url }}',
                '{{ app_id }}',
                '{{ challenge_port }}',
                '{{ challenge_token }}'
            ],
            [
                $this->escapeJsSingleQuoted($verifyUrl),
                $this->escapeJsSingleQuoted($this->appId),
                (string) $port,
                $this->escapeJsSingleQuoted($challengeToken),
            ],
            self::INJECTED_SCRIPT
        );

        return str_replace(
            '</body>',
            $script . '</body>',
            $html
        );
    }

    public function getBlockedPageContents(): string|null
    {
        $response = $this->sendGetRequest('/blocked_page');
        if (empty($response) || $response->status !== 200) {
            return null;
        }

        $content = $response->body['data']['attributes']['content'] ?? '';
        if (empty($content)) {
            $content = self::DEFAULT_BLOCKED_PAGE_CONTENTS;
        }

        return $content;
    }

    public function verifyChallenge(string $ip, string $challengeResponse, int $challengePort, string $challengeToken): string|null
    {
        $response = $this->sendPostRequest(
            '/challenges/verify_response',
            [
                'ip' => $ip,
                'response' => $challengeResponse,
                'challenge_port' => $challengePort,
                'challenge_token' => $challengeToken,
            ]
        );
        if (empty($response) || $response->status !== 200) {
            return null;
        }

        return $response->body['token'] ?? null;
    }

    public function createRequest(string $ip, string $path, string $token, int $validationStatus): void
    {
        $this->sendPostRequest(
            '/requests',
            [
                'ip' => $ip,
                'path' => $path,
                'token' => $token,
                'status' => $validationStatus
            ]
        );
    }

    private function escapeJsSingleQuoted(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-App-Id'     => $this->appId,
            'X-Api-Key'    => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];
    }

    /** @param array<string, mixed> $options */
    private function sendGetRequest(string $path, array $options = []): ApiResponse|null
    {
        $response = wp_remote_get(
            self::API_BASE . $path,
            array_merge(
                [
                    'headers' => $this->headers(),
                    'timeout' => self::TIMEOUT,
                ],
                $options
            )
        );

        if (is_wp_error($response)) {
            return null;
        }

        return new ApiResponse(
            wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_body($response)
        );
    }

    /** @param array<string, mixed> $body */
    private function sendPostRequest(string $path, array $body): ApiResponse|null
    {
        $response = wp_remote_post(
            self::API_BASE . $path,
            [
                'body' => wp_json_encode($body),
                'headers' => $this->headers(),
                'timeout' => self::TIMEOUT,
            ],
        );

        if (is_wp_error($response)) {
            return null;
        }

        return new ApiResponse(
            wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_body($response)
        );
    }
}
