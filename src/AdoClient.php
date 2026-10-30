<?php

namespace GlpiPlugin\Azuredevops;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Toolbox;

/**
 * Minimal Azure DevOps REST client (work item tracking, API 7.1).
 *
 * - uses GLPI's Guzzle factory, so the GLPI proxy settings are honoured;
 * - authenticates with a personal access token (HTTP Basic, empty user name) or as a Microsoft Entra application
 *   (Bearer access token), see AdoAuth;
 * - every failure is converted to a typed AdoException;
 * - never logs the token.
 */
class AdoClient
{
    public const API_VERSION = '7.1';

    private const USER_AGENT = 'GLPI-Azuredevops/' . PLUGIN_AZUREDEVOPS_VERSION;

    /** Fields requested when polling: they exist in every process template. */
    private const POLL_FIELDS = [
        'System.Id', 'System.WorkItemType', 'System.Title', 'System.State',
        'System.TeamProject', 'System.Parent', 'System.ChangedDate', 'System.ChangedBy',
    ];

    private ClientInterface $http;
    private AdoAuth $auth;
    private string $root;
    private string $project_url;

    /**
     * @param ClientInterface|null $http Injectable for tests
     */
    public function __construct(
        string $base_url,
        private readonly string $organization,
        private readonly string $project,
        AdoAuth|string $auth,
        ?ClientInterface $http = null,
    ) {
        // A string is a personal access token.
        $this->auth = is_string($auth) ? AdoAuth::pat($auth) : $auth;
        $base_url   = rtrim(trim($base_url), '/');
        if ($base_url === '' || $organization === '' || $project === '' || !$this->auth->isConfigured() || !preg_match('#^https?://#i', $base_url)) {
            throw new AdoException(
                __('The organization, the project or the credentials (access token, or Entra application) are not configured.', 'azuredevops'),
                AdoException::CONFIG
            );
        }
        $this->root        = $base_url . '/' . rawurlencode($organization) . '/';
        $this->project_url = $this->root . rawurlencode($project) . '/';
        $this->http        = $http ?? Toolbox::getGuzzleClient([
            'timeout'         => 25,
            'connect_timeout' => 5,
            'http_errors'     => false,
            'headers'         => [
                'User-Agent' => self::USER_AGENT,
                'Accept'     => 'application/json',
            ],
        ]);
    }

    // ---------------------------------------------------------------- generic

    /**
     * @param array<string, mixed> $options Guzzle options
     *
     * @return array{status: int, headers: array<string, string[]>, data: mixed}
     */
    protected function request(string $method, string $url, array $options = [], string $api_version = self::API_VERSION): array
    {
        $options['query']       = ($options['query'] ?? []) + ['api-version' => $api_version];
        $options['http_errors'] = false;

        $attempt = 0;
        do {
            $retry = false;
            // For an Entra application this may ask Microsoft Entra for a new access token.
            $options['headers']['Authorization'] = $this->auth->header();

            try {
                $response = $this->http->request($method, $url, $options);
            } catch (GuzzleException $e) {
                // Connection refused, DNS failure, TLS error, timeout...
                throw new AdoException(
                    sprintf(__('Cannot reach Azure DevOps: %s', 'azuredevops'), $this->scrub($e->getMessage())),
                    AdoException::NETWORK
                );
            }

            $status  = $response->getStatusCode();
            $body    = (string) $response->getBody();
            $ctype   = strtolower($response->getHeaderLine('Content-Type'));
            $is_html = str_contains($ctype, 'text/html') || preg_match('/^\s*<(!doctype|html)/i', $body) === 1;

            // An access token that is refused before its expiry (revoked, or the application was just added to the
            // organization): ask for a new one and try once more.
            $refused = $status === 401 || $status === 203 || ($status < 400 && $is_html);
            if ($refused && $attempt === 0 && $this->auth->isEntra()) {
                $attempt++;
                $retry = true;
                $this->auth->invalidate();
                if (($options['body'] ?? null) instanceof StreamInterface && $options['body']->isSeekable()) {
                    $options['body']->rewind(); // an upload must be sent again from its start
                }
            }
        } while ($retry);

        // A refused token does not always give a 401: Azure DevOps may answer 203 with its sign-in page.
        if ($status === 203 || ($status < 400 && $is_html)) {
            throw new AdoException(
                __('Azure DevOps did not accept the access token (sign-in page received). Check that the token is valid, not expired, and has access to this organization.', 'azuredevops') . $this->entraHint(),
                AdoException::AUTH,
                $status
            );
        }

        $data = ($body !== '' && !$is_html) ? json_decode($body, true) : null;

        if ($status >= 400) {
            throw $this->buildException($status, $data, $response->getHeaderLine('Retry-After'));
        }

        if ($body !== '' && $data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new AdoException(
                __('Unexpected (non-JSON) answer from Azure DevOps. Are the organization and the project correct?', 'azuredevops'),
                AdoException::REQUEST,
                $status
            );
        }

        return ['status' => $status, 'headers' => $response->getHeaders(), 'data' => $data];
    }

    private function buildException(int $status, mixed $data, string $retry_after): AdoException
    {
        $detail = is_array($data) ? (string) ($data['message'] ?? $data['Message'] ?? '') : '';
        $detail = $this->scrub($detail);
        $suffix = $detail !== '' ? ' - ' . mb_substr($detail, 0, 500) : '';

        return match (true) {
            $status === 401, $status === 403 => new AdoException(
                sprintf(__('Azure DevOps refused access (HTTP %d)%s', 'azuredevops'), $status, $suffix) . $this->entraHint(),
                AdoException::AUTH,
                $status
            ),
            $status === 404 => new AdoException(
                sprintf(__('Not found on Azure DevOps (HTTP 404)%s', 'azuredevops'), $suffix),
                AdoException::NOT_FOUND,
                $status
            ),
            $status === 429 => new AdoException(
                __('Azure DevOps rate limit reached (HTTP 429)', 'azuredevops'),
                AdoException::RATE_LIMIT,
                $status,
                (int) $retry_after
            ),
            $status >= 500 => new AdoException(
                sprintf(__('Azure DevOps server error (HTTP %d)%s', 'azuredevops'), $status, $suffix),
                AdoException::SERVER,
                $status
            ),
            default => new AdoException(
                sprintf(__('Azure DevOps rejected the request (HTTP %d)%s', 'azuredevops'), $status, $suffix) . $this->ruleHint($detail),
                AdoException::REQUEST,
                $status
            ),
        };
    }

    /**
     * Make sure the token never ends up in a message that is displayed or logged.
     */
    private function scrub(string $text): string
    {
        $secrets = $this->auth->secrets();

        return $secrets !== [] ? str_replace($secrets, '***', $text) : $text;
    }

    /**
     * A field required by the process (TF401320): tell where to give it a value.
     */
    private function ruleHint(string $detail): string
    {
        return str_contains($detail, 'TF401320')
            ? ' ' . __('The process requires a value for a field the plugin does not fill in: set it in the project, under "Default fields" (the required fields of the type are listed there after "Save and test the connection").', 'azuredevops')
            : '';
    }

    /**
     * What to check when an Entra application is refused: the usual cause is that it is not a user of the
     * organization yet (it is not enough to register it in Microsoft Entra).
     */
    private function entraHint(): string
    {
        return $this->auth->isEntra()
            ? ' ' . __('For an Entra application: it must be added as a user of the Azure DevOps organization (Organization settings > Users) and have access to this project.', 'azuredevops')
            : '';
    }

    // ------------------------------------------------------------- connection

    /**
     * @return array{project: string, state: string, user: string}
     */
    public function testConnection(): array
    {
        $project = (array) $this->request('GET', $this->root . '_apis/projects/' . rawurlencode($this->project))['data'];
        if (!isset($project['name'])) {
            throw new AdoException(__('Azure DevOps did not return the project.', 'azuredevops'), AdoException::REQUEST);
        }

        $user = '';
        try {
            // Informative only (who the token belongs to).
            $info = (array) $this->request('GET', $this->root . '_apis/connectionData', [], '7.1-preview')['data'];
            $user = (string) ($info['authenticatedUser']['providerDisplayName'] ?? '');
        } catch (AdoException) {
            $user = '';
        }

        return ['project' => (string) $project['name'], 'state' => (string) ($project['state'] ?? ''), 'user' => $user];
    }

    /**
     * Work item types of the project with the category of each of their states.
     *
     * @return array<int, array{name: string, states: array<string, string>}>
     */
    public function getWorkItemTypes(): array
    {
        $list  = (array) ($this->request('GET', $this->project_url . '_apis/wit/workitemtypes')['data']['value'] ?? []);
        $types = [];
        foreach ($list as $type) {
            if (!is_array($type) || empty($type['name']) || !empty($type['isDisabled'])) {
                continue;
            }
            $states = self::statesFrom($type['states'] ?? null);
            if ($states === []) {
                $states = $this->getTypeStates((string) $type['name']);
            }
            $types[] = ['name' => (string) $type['name'], 'states' => $states];
        }

        return $types;
    }

    /**
     * Fields of a work item type with their rules: which ones the process requires, their default and the values
     * they are limited to (the "Priority" of a custom process, for instance).
     *
     * @return array<int, array{ref: string, name: string, required: bool, default: ?string, allowed: string[]}>
     */
    public function getTypeFields(string $type): array
    {
        $res    = $this->request('GET', $this->project_url . '_apis/wit/workitemtypes/' . rawurlencode($type) . '/fields', ['query' => ['$expand' => 'allowedValues']]);
        $fields = [];
        foreach ((array) ($res['data']['value'] ?? []) as $field) {
            if (!is_array($field) || empty($field['referenceName'])) {
                continue;
            }
            $default = $field['defaultValue'] ?? null;
            $fields[] = [
                'ref'      => (string) $field['referenceName'],
                'name'     => (string) ($field['name'] ?? $field['referenceName']),
                'required' => !empty($field['alwaysRequired']),
                'default'  => is_scalar($default) && (string) $default !== '' ? (string) $default : null,
                'allowed'  => array_values(array_map('strval', array_filter((array) ($field['allowedValues'] ?? []), 'is_scalar'))),
            ];
        }

        return $fields;
    }

    /**
     * @return array<string, string> state name => category
     */
    private function getTypeStates(string $type): array
    {
        // The list of the states of a type is a preview API on some versions: try both.
        foreach ([self::API_VERSION, '7.1-preview.1'] as $version) {
            try {
                $res = $this->request('GET', $this->project_url . '_apis/wit/workitemtypes/' . rawurlencode($type) . '/states', [], $version);

                return self::statesFrom($res['data']['value'] ?? null);
            } catch (AdoException $e) {
                if ($e->isGlobal() && $e->getKind() !== AdoException::SERVER) {
                    throw $e;
                }
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private static function statesFrom(mixed $states): array
    {
        $map = [];
        foreach ((array) $states as $state) {
            if (is_array($state) && !empty($state['name'])) {
                $map[(string) $state['name']] = (string) ($state['category'] ?? $state['stateCategory'] ?? '');
            }
        }

        return $map;
    }

    // -------------------------------------------------------------- work items

    /**
     * Create a work item.
     *
     * @param array<string, mixed>             $fields    reference name => value (System.Title...)
     * @param array<int, array<string, mixed>> $relations each {rel, url, attributes?}
     *
     * @return array<string, mixed> normalized work item
     */
    public function createWorkItem(string $type, array $fields, array $relations = []): array
    {
        $ops = [];
        foreach ($fields as $name => $value) {
            $ops[] = ['op' => 'add', 'path' => '/fields/' . $name, 'value' => $value];
        }
        foreach ($relations as $relation) {
            $ops[] = ['op' => 'add', 'path' => '/relations/-', 'value' => $relation];
        }

        $res = $this->request('POST', $this->project_url . '_apis/wit/workitems/$' . rawurlencode($type), [
            'body'    => json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'headers' => ['Content-Type' => 'application/json-patch+json'],
        ]);

        $item = self::normalize((array) $res['data']);
        if ($item['id'] <= 0) {
            throw new AdoException(__('Azure DevOps did not return the created work item.', 'azuredevops'), AdoException::REQUEST);
        }
        $item['url'] = $this->webUrl($item['id']);

        return $item;
    }

    /**
     * Upload a file; it must then be attached to a work item with a relation of type AttachedFile.
     *
     * @return array{id: string, url: string}
     */
    public function uploadAttachment(string $path, string $filename): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new AdoException(sprintf(__('Cannot read file %s', 'azuredevops'), $filename), AdoException::REQUEST);
        }

        $res = $this->request('POST', $this->project_url . '_apis/wit/attachments', [
            'query'   => ['fileName' => $filename],
            'body'    => Utils::streamFor($handle),
            'headers' => ['Content-Type' => 'application/octet-stream'],
        ]);

        $data = (array) $res['data'];
        if (empty($data['url'])) {
            throw new AdoException(sprintf(__('Azure DevOps did not accept file %s', 'azuredevops'), $filename), AdoException::REQUEST);
        }

        return ['id' => (string) ($data['id'] ?? ''), 'url' => (string) $data['url']];
    }

    /**
     * The tags that exist in the project (shared by its work items), sorted.
     *
     * @return string[]
     *
     * @throws AdoException
     */
    public function getTags(): array
    {
        // Depending on the version of the API, the list of the tags is stable or a preview: try both.
        $last = null;
        foreach ([self::API_VERSION, '7.1-preview.1'] as $version) {
            try {
                $res   = $this->request('GET', $this->project_url . '_apis/wit/tags', [], $version);
                $names = [];
                foreach ((array) ($res['data']['value'] ?? []) as $tag) {
                    if (is_array($tag) && !empty($tag['name'])) {
                        $names[] = (string) $tag['name'];
                    }
                }
                natcasesort($names);

                return array_values($names);
            } catch (AdoException $e) {
                if ($e->isGlobal() && $e->getKind() !== AdoException::SERVER) {
                    throw $e;
                }
                $last = $e;
            }
        }

        throw $last ?? new AdoException(__('Azure DevOps did not return the tags.', 'azuredevops'), AdoException::REQUEST);
    }

    /**
     * Read several work items in as few requests as possible. Work items that do not exist, or that the
     * token cannot read, are absent from the result.
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>> normalized work items indexed by id
     */
    public function getWorkItems(array $ids): array
    {
        $found = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $ids)))), 200) as $chunk) {
            $res = $this->request('POST', $this->root . '_apis/wit/workitemsbatch', [
                'json' => ['ids' => $chunk, 'fields' => self::POLL_FIELDS, 'errorPolicy' => 'Omit'],
            ]);
            foreach ((array) ($res['data']['value'] ?? []) as $wi) {
                if (is_array($wi) && isset($wi['id'])) {
                    $item = self::normalize($wi);
                    $item['url'] = $this->webUrl($item['id']);
                    $found[$item['id']] = $item;
                }
            }
        }

        return $found;
    }

    /**
     * One work item with all its fields (404 when it does not exist or cannot be read).
     *
     * @return array<string, mixed> normalized work item, plus "fields" (all the raw fields)
     */
    public function getWorkItem(int $id): array
    {
        $res  = $this->request('GET', $this->root . '_apis/wit/workitems/' . $id);
        $data = (array) $res['data'];
        if (!isset($data['id'])) {
            throw new AdoException(__('Azure DevOps did not return the work item.', 'azuredevops'), AdoException::NOT_FOUND, 404);
        }
        $item = self::normalize($data);
        $item['url']    = $this->webUrl($item['id']);
        $item['fields'] = (array) ($data['fields'] ?? []);

        return $item;
    }

    /**
     * Search work items of the project by title or number.
     *
     * @param string[] $types work item types to search
     *
     * @return array<int, array<string, mixed>> normalized work items, most recently changed first
     */
    public function searchWorkItems(array $types, string $term, int $limit = 20): array
    {
        $types = array_values(array_filter(array_map('trim', $types), static fn($t) => $t !== ''));
        if ($types === []) {
            return [];
        }

        $esc   = static fn(string $v): string => str_replace("'", "''", $v);
        $where = ["[System.TeamProject] = '" . $esc($this->project) . "'"];
        $where[] = '[System.WorkItemType] IN (' . implode(',', array_map(static fn($t) => "'" . $esc($t) . "'", $types)) . ')';

        $term = trim($term);
        if ($term !== '') {
            $cond = "[System.Title] CONTAINS '" . $esc($term) . "'";
            if (ctype_digit(ltrim($term, '#'))) {
                $cond .= ' OR [System.Id] = ' . (int) ltrim($term, '#');
            }
            $where[] = '(' . $cond . ')';
        }
        $wiql = 'SELECT [System.Id] FROM WorkItems WHERE ' . implode(' AND ', $where) . ' ORDER BY [System.ChangedDate] DESC';

        $res = $this->request('POST', $this->project_url . '_apis/wit/wiql', [
            'query' => ['$top' => max(1, $limit)],
            'json'  => ['query' => $wiql],
        ]);
        $ids = [];
        foreach ((array) ($res['data']['workItems'] ?? []) as $row) {
            if (isset($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids === []) {
            return [];
        }

        $items   = $this->getWorkItems($ids);
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($items[$id])) {
                $ordered[$id] = $items[$id];
            }
        }

        return $ordered;
    }

    /**
     * Ancestors of a work item, from the root (Epic...) down to its direct parent.
     *
     * @return array<int, array{id: int, type: string, title: string, url: string}>
     */
    public function parentChain(int $parent_id, int $max_depth = 5): array
    {
        $chain = [];
        $seen  = [];
        $id    = $parent_id;
        while ($id > 0 && count($chain) < $max_depth && !isset($seen[$id])) {
            $seen[$id] = true;
            $item = $this->getWorkItems([$id])[$id] ?? null;
            if ($item === null) {
                break;
            }
            array_unshift($chain, ['id' => $item['id'], 'type' => $item['type'], 'title' => $item['title'], 'url' => $item['url']]);
            $id = (int) $item['parent'];
        }

        return $chain;
    }

    public function webUrl(int $id): string
    {
        return $this->root . '_workitems/edit/' . $id;
    }

    /**
     * URL used in relations (parent link...).
     */
    public function apiUrl(int $id): string
    {
        return $this->root . '_apis/wit/workItems/' . $id;
    }

    // -------------------------------------------------------------- normalizing

    /**
     * @param array<string, mixed> $wi raw work item
     *
     * @return array{id: int, type: string, title: string, state: string, project: string, parent: int, changed_date: string, changed_by: string, url: string}
     */
    public static function normalize(array $wi): array
    {
        $f = (array) ($wi['fields'] ?? []);

        return [
            'id'           => (int) ($wi['id'] ?? $f['System.Id'] ?? 0),
            'type'         => (string) ($f['System.WorkItemType'] ?? ''),
            'title'        => (string) ($f['System.Title'] ?? ''),
            'state'        => (string) ($f['System.State'] ?? ''),
            'project'      => (string) ($f['System.TeamProject'] ?? ''),
            'parent'       => (int) ($f['System.Parent'] ?? 0),
            'changed_date' => (string) ($f['System.ChangedDate'] ?? ''),
            'changed_by'   => self::identityName($f['System.ChangedBy'] ?? null),
            'url'          => '',
        ];
    }

    /**
     * Identity fields are objects ({displayName, uniqueName...}) in API 7.x and "Name <mail>" strings in old ones.
     */
    public static function identityName(mixed $identity): string
    {
        if (is_array($identity)) {
            return trim((string) ($identity['displayName'] ?? $identity['uniqueName'] ?? ''));
        }
        $text = trim((string) $identity);

        return trim(preg_replace('/\s*<[^>]*>\s*$/', '', $text) ?? $text);
    }
}
