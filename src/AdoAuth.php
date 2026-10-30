<?php

namespace GlpiPlugin\Azuredevops;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Toolbox;

/**
 * How a project authenticates to Azure DevOps.
 *
 * - PAT: a personal access token, sent as HTTP Basic (empty user name). It belongs to a person: when that account is
 *   disabled, or the token expires, the project stops working.
 * - Microsoft Entra application (service principal): the plugin signs in with the application's client credentials,
 *   gets an access token valid for about an hour, keeps it (encrypted, in the project) while it is valid and sends it
 *   as a Bearer token. No personal account is involved.
 */
final class AdoAuth
{
    public const MODE_PAT   = 'pat';
    public const MODE_ENTRA = 'entra';

    /** The Azure DevOps application in Microsoft Entra. */
    public const SCOPE = '499b84ac-1321-427f-aa17-267ca6975798/.default';

    public const DEFAULT_AUTHORITY = 'https://login.microsoftonline.com';

    /** A cached access token with less than this many seconds left is renewed. */
    private const REFRESH_MARGIN = 300;

    private ?string $access_token = null;
    private int $expires_at       = 0;
    private ?ClientInterface $http;

    /** @var callable|null function (string $access_token, int $expires_at): void */
    private $persist;

    private function __construct(
        private readonly string $mode,
        private readonly string $secret,
        private readonly string $tenant = '',
        private readonly string $client_id = '',
        private readonly string $authority = self::DEFAULT_AUTHORITY,
        ?callable $persist = null,
        ?ClientInterface $http = null,
    ) {
        $this->persist = $persist;
        $this->http    = $http;
    }

    public static function pat(string $token): self
    {
        return new self(self::MODE_PAT, $token);
    }

    /**
     * @param string|null   $cached_token   access token kept from a previous call (still used while it is valid)
     * @param callable|null $persist        called with (access token, expiry timestamp) each time a new one is obtained
     * @param ClientInterface|null $http    injectable for tests
     */
    public static function entra(
        string $tenant,
        string $client_id,
        string $client_secret,
        string $authority = self::DEFAULT_AUTHORITY,
        ?string $cached_token = null,
        int $cached_expires = 0,
        ?callable $persist = null,
        ?ClientInterface $http = null,
    ): self {
        $auth = new self(self::MODE_ENTRA, $client_secret, trim($tenant), trim($client_id), rtrim(trim($authority), '/') ?: self::DEFAULT_AUTHORITY, $persist, $http);
        if ($cached_token !== null && $cached_token !== '' && $cached_expires > 0) {
            $auth->access_token = $cached_token;
            $auth->expires_at   = $cached_expires;
        }

        return $auth;
    }

    public function isEntra(): bool
    {
        return $this->mode === self::MODE_ENTRA;
    }

    public function isConfigured(): bool
    {
        if ($this->mode === self::MODE_PAT) {
            return $this->secret !== '';
        }

        return $this->secret !== '' && $this->tenant !== '' && $this->client_id !== '' && preg_match('#^https?://#i', $this->authority) === 1;
    }

    /**
     * Value of the Authorization header. For an Entra application this may call Microsoft Entra to get a token.
     *
     * @throws AdoException
     */
    public function header(): string
    {
        if ($this->mode === self::MODE_PAT) {
            return 'Basic ' . base64_encode(':' . $this->secret);
        }

        if ($this->access_token === null || $this->expires_at - time() < self::REFRESH_MARGIN) {
            $this->fetchToken();
        }

        return 'Bearer ' . $this->access_token;
    }

    /**
     * Forget the access token (refused by Azure DevOps although it had not expired: revoked, or the application
     * was just added to the organization): the next header() asks for a new one.
     */
    public function invalidate(): void
    {
        $this->access_token = null;
        $this->expires_at   = 0;
    }

    /**
     * Everything that must never appear in a message.
     *
     * @return string[]
     */
    public function secrets(): array
    {
        $secrets = [];
        if ($this->secret !== '') {
            $secrets[] = base64_encode(':' . $this->secret);
            $secrets[] = $this->secret;
        }
        if ($this->access_token !== null && $this->access_token !== '') {
            $secrets[] = $this->access_token;
        }

        return $secrets;
    }

    /**
     * Sign in with the client credentials.
     *
     * @throws AdoException
     */
    private function fetchToken(): void
    {
        if (!$this->isConfigured()) {
            throw new AdoException(__('The tenant, the application (client) ID or the client secret is not configured.', 'azuredevops'), AdoException::CONFIG);
        }

        $http = $this->http ??= Toolbox::getGuzzleClient([
            'timeout'         => 20,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'headers'         => ['Accept' => 'application/json'],
        ]);

        try {
            $response = $http->request('POST', $this->authority . '/' . rawurlencode($this->tenant) . '/oauth2/v2.0/token', [
                'http_errors' => false,
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->client_id,
                    'client_secret' => $this->secret,
                    'scope'         => self::SCOPE,
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new AdoException(
                sprintf(__('Cannot reach Microsoft Entra: %s', 'azuredevops'), $this->scrub($e->getMessage())),
                AdoException::NETWORK
            );
        }

        $status = $response->getStatusCode();
        $data   = json_decode((string) $response->getBody(), true);
        if ($status === 200 && is_array($data) && !empty($data['access_token'])) {
            $this->access_token = (string) $data['access_token'];
            $this->expires_at   = time() + max(60, (int) ($data['expires_in'] ?? 3600));
            if ($this->persist !== null) {
                try {
                    ($this->persist)($this->access_token, $this->expires_at);
                } catch (\Throwable $e) {
                    // Only a cache: the token is still used for this request.
                    Toolbox::logInFile('azuredevops', 'Access token not kept: ' . $e->getMessage() . "\n");
                }
            }

            return;
        }

        // Entra describes the problem on several lines ("AADSTS7000215: ...\r\nTrace ID: ...").
        $detail = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? '') : '';
        $detail = trim(preg_split('/\r?\n/', $this->scrub($detail))[0] ?? '');
        $suffix = $detail !== '' ? ' - ' . mb_substr($detail, 0, 400) : '';
        $text   = sprintf(__('Microsoft Entra did not give an access token (HTTP %1$d)%2$s', 'azuredevops'), $status, $suffix);

        throw new AdoException($text, match (true) {
            $status === 429 => AdoException::RATE_LIMIT,
            $status >= 500  => AdoException::SERVER,
            $status === 400, $status === 401, $status === 403 => AdoException::AUTH,
            default         => AdoException::REQUEST,
        }, $status);
    }

    private function scrub(string $text): string
    {
        return $this->secret !== '' ? str_replace($this->secret, '***', $text) : $text;
    }
}
