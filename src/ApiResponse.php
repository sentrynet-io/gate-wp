<?php

declare(strict_types=1);

namespace SentryNet;

class ApiResponse
{
    /** @var array<string, mixed> */
    public array $body;

    public function __construct(
        public int $status,
        string $body
    ) {
        if (!empty($body)) {
            try {
                $this->body = json_decode(
                    $body,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\JsonException $error) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Failed to decode JSON body, body={$body}, error=" . $error->getMessage());
                }

                $this->body = [];
            }
        }
    }
}
