<?php

namespace GlpiPlugin\Azuredevops;

use RuntimeException;

/**
 * Error raised by the Azure DevOps client. The "kind" lets callers decide what to do
 * (retry later, skip a project, report a configuration problem...).
 */
final class AdoException extends RuntimeException
{
    public const NETWORK    = 'network';     // cannot reach Azure DevOps (DNS, TLS, timeout...)
    public const AUTH       = 'auth';        // 401 / 403, or the sign-in page instead of the API (invalid or expired token)
    public const NOT_FOUND  = 'not_found';   // 404
    public const RATE_LIMIT = 'rate_limit';  // 429
    public const SERVER     = 'server';      // 5xx
    public const REQUEST    = 'request';     // other 4xx (validation, rules of the process...)
    public const CONFIG     = 'config';      // project not configured

    public function __construct(
        string $message,
        private readonly string $kind,
        private readonly int $http_status = 0,
        private readonly int $retry_after = 0,
    ) {
        parent::__construct($message, $http_status);
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getHttpStatus(): int
    {
        return $this->http_status;
    }

    /** Seconds asked by the server before the next attempt (429), 0 when unknown. */
    public function getRetryAfter(): int
    {
        return $this->retry_after;
    }

    /**
     * Errors that affect every request made with the same credentials: the rest of the project is skipped
     * until the next run instead of hammering the server.
     */
    public function isGlobal(): bool
    {
        return in_array($this->kind, [self::NETWORK, self::AUTH, self::RATE_LIMIT, self::SERVER, self::CONFIG], true);
    }
}
