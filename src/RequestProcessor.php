<?php

declare(strict_types=1);

namespace SentryNet;

enum RequestAction: string
{
    case RENDER = 'render';
    case PASSTHROUGH = 'passthrough';
};

enum RequestStatus: int
{
    case Success = 0;
    case ExpiredToken = 1;
    case InvalidToken = 2;
    case TokenNotOwnedByApp = 3;
    case TokenNotOwnedByClient = 4;
    case ReusedToken = 5;
    case ClientNotFound = 6;
    case SuspiciousIp = 7;
    case MissingToken = 8;
    case BlockedIp = 9;
    case RateLimited = 100;
};

class RequestProcessor
{
    private const CHALLENGE_PAGE_WRAPPER_CONTENTS = <<<CHALLENGE
<!DOCTYPE html>
<html>
  <head>
    <title>Please wait...</title>
    <style>
      iframe {
        width: 100vw;
        height: 100vh;
        border: 0;
      }
    </style>
  </head>
  <body>
    <iframe src="/challenge-{{ app_id }}?challenge_port={{ challenge_port }}&challenge_token={{ challenge_token }}"></iframe>
  </body>
</html>
CHALLENGE;
    private const FRONTEND_TOKEN_PAGE_CONTENTS = <<<FRONTEND
<!DOCTYPE html>
<html>
  <head>
    <title>Please wait...</title>
  </head>
  <body>
    <script type="application/javascript">
      window.reloadPage = function () {
        window.location.reload();
      }
    </script>
    <script src="https://sdk-cdn.sentrynet.io/sdk.js?app_id={{ app_id }}&render=true&callback=reloadPage"></script>
  </body>
</html>
FRONTEND;
    private const WHITELISTED_EXTENSIONS = "/\.(png|jpg|css|js|ico|js\.map)$/i";

    private ApiClient $apiClient;

    /**
     * @param array<string> $whitelistedPaths
     */
    public function __construct(
        private string $appId,
        private string $apiKey,
        private string $ip,
        private string $path,
        private array $whitelistedPaths,
        private string $frontendToken
    ) {
        $this->apiClient = new ApiClient(
            $this->appId,
            $this->apiKey
        );
    }

    public function process(): ProcessedResponse|null
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Processing request path=" . $this->path . ", ip=" . $this->ip);
        }

        if (in_array($this->path, $this->whitelistedPaths, true)) {
            return new ProcessedResponse(
                RequestAction::PASSTHROUGH,
                "{$this->path} is whitelisted",
                ''
            );
        }

        $allowanceResponse = $this->apiClient->checkAllowance($this->ip);
        if ($allowanceResponse === 403) {
            $this->createRequest(RequestStatus::RateLimited->value);

            return $this->renderChallengePage('Request is rate-limited');
        }

        if ($allowanceResponse === 423) {
            $this->createRequest(RequestStatus::BlockedIp->value);

            return $this->renderBlockedPage('Origin IP is blocked');
        }

        if (!$this->shouldValidateFrontendToken()) {
            $this->createRequest(RequestStatus::Success->value);

            return $this->buildSuccessfulResponse();
        }

        if (empty($this->frontendToken)) {
            $this->createRequest(RequestStatus::MissingToken->value);

            return $this->renderFrontendTokenPage();
        }

        $response = $this->apiClient->validateToken(
            $this->ip,
            $this->frontendToken
        );
        if (empty($response)) {
            return null;
        }

        if ($response->status === 422 || $response->status === 400) {
            $errorCode = empty($response->body['error_code'])
                ? -1
                : (int) $response->body['error_code'];

            $this->createRequest($errorCode);
            $encodedBody = json_encode($response->body);

            return $this->renderChallengePage("Frontend token is invalid, body={$encodedBody}, status={$response->status}");
        }

        $this->createRequest(RequestStatus::Success->value);

        return $this->buildSuccessfulResponse();
    }

    private function createRequest(int $validationStatus): void
    {
        $this->apiClient->createRequest(
            $this->ip,
            $this->path,
            $this->frontendToken,
            $validationStatus
        );
    }

    private function renderChallengePage(string $reason): ProcessedResponse|null
    {
        $response = $this->apiClient->getChallengeMeta($this->frontendToken);
        if (empty($response)) {
            return null;
        }

        $challengeToken = $response->body['token'] ?? '';
        $challengePort = empty($response->body['port'])
            ? -1
            : (int) $response->body['port'];

        if (empty($challengeToken) || $challengePort === -1) {
            return null;
        }

        return new ProcessedResponse(
            RequestAction::RENDER,
            $reason,
            $this->getChallengePageWrapperContents(
                $challengePort,
                $challengeToken
            )
        );
    }

    private function getChallengePageWrapperContents(int $challengePort, string $challengeToken): string
    {
        return str_replace(
            [
                '{{ app_id }}',
                '{{ challenge_port }}',
                '{{ challenge_token }}'
            ],
            [
                $this->appId,
                (string) $challengePort,
                $challengeToken
            ],
            self::CHALLENGE_PAGE_WRAPPER_CONTENTS
        );
    }

    private function renderBlockedPage(string $reason): ProcessedResponse|null
    {
        $blockedPageContents = $this->apiClient->getBlockedPageContents();
        if (empty($blockedPageContents)) {
            return null;
        }

        return new ProcessedResponse(
            RequestAction::RENDER,
            $reason,
            $blockedPageContents
        );
    }

    private function shouldValidateFrontendToken(): bool
    {
        return preg_match(self::WHITELISTED_EXTENSIONS, $this->path) !== 1;
    }

    private function renderFrontendTokenPage(): ProcessedResponse
    {
        return new ProcessedResponse(
            RequestAction::RENDER,
            "Frontend token is empty, rendering empty page",
            $this->getFrontendPageContents()
        );
    }

    private function getFrontendPageContents(): string
    {
        return str_replace(
            '{{ app_id }}',
            $this->appId,
            self::FRONTEND_TOKEN_PAGE_CONTENTS
        );
    }

    private function buildSuccessfulResponse(): ProcessedResponse
    {
        return new ProcessedResponse(
            RequestAction::PASSTHROUGH,
            "Request is not rate-limited, passing through",
            ""
        );
    }
}
