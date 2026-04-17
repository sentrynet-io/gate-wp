<?php

declare(strict_types=1);

namespace SentryNet;

class ProcessedResponse
{
    public function __construct(
        public RequestAction $action,
        public string $reason,
        public string $body
    ) {
    }
}
