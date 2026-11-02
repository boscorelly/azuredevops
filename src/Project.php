<?php

namespace GlpiPlugin\Azuredevops;

use GLPIKey;
use RuntimeException;
use Toolbox;

/**
 * A configured Azure DevOps project: where to create work items, with which access token, and the rules
 * that apply to it (type to create, allowed parents, which state categories mean "done").
 *
 * One row per project; every project has its own token, stored encrypted with GLPI's key and never sent
 * back to the browser. Who may use the project is handled by ProjectAccess.
 *
 * This class only provides the table name and static helpers: rows are read and written directly
 * (it deliberately does not extend CommonDBTM, whose method names would clash with the helpers).
 */
final class Project
{
    public const TABLE = 'glpi_plugin_azuredevops_projects';

    public static function getTable(): string
    {
        return self::TABLE;
    }

    /** State categories Azure DevOps knows (System categories of the process templates). */
    public const CAT_PROPOSED    = 'Proposed';
    public const CAT_IN_PROGRESS = 'InProgress';
    public const CAT_RESOLVED    = 'Resolved';
    public const CAT_COMPLETED   = 'Completed';
    public const CAT_REMOVED     = 'Removed';

    public static function getTypeName($nb = 0): string
    {
        return _n('Azure DevOps project', 'Azure DevOps projects', $nb, 'azuredevops');
    }

    // ------------------------------------------------------------------ reading

    /**
     * @return array<int, array<string, mixed>> rows indexed by id, ordered by name
     */
    public static function all(bool $active_only = false): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $where = $active_only ? ['is_active' => 1] : [];
        $rows  = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'name ASC']) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($id <= 0) {
            return null;
        }
        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();

        return $row ?: null;
    }

    /**
     * @return string[]
     */
    public static function csv(?string $value): array
    {
        $items = [];
        foreach (explode(',', (string) $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[mb_strtolower($item)] ??= $item;   // the first spelling wins
            }
        }

        return array_values($items);
    }

    public static function inList(string $needle, ?string $csv): bool
    {
        foreach (self::csv($csv) as $item) {
            if (strcasecmp($item, $needle) === 0) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------- token

    /** The personal access token (PAT mode). */
    public static function token(array $project): string
    {
        return self::decrypt((string) ($project['token'] ?? ''));
    }

    /**
     * How the project signs in: its personal access token, or its Microsoft Entra application (the access token
     * obtained from Entra is kept, encrypted, in the project while it is valid).
     */
    public static function auth(array $project): AdoAuth
    {
        if (($project['auth_mode'] ?? AdoAuth::MODE_PAT) !== AdoAuth::MODE_ENTRA) {
            return AdoAuth::pat(self::token($project));
        }

        $id      = (int) $project['id'];
        $expires = (int) ($project['access_token_expires'] ?? 0);
        $cached  = $expires > time() ? self::decrypt((string) ($project['access_token'] ?? '')) : '';

        return AdoAuth::entra(
            (string) ($project['tenant_id'] ?? ''),
            (string) ($project['client_id'] ?? ''),
            self::decrypt((string) ($project['client_secret'] ?? '')),
            Settings::entraAuthority(),
            $cached,
            $cached !== '' ? $expires : 0,
            static function (string $token, int $expires_at) use ($id): void {
                /** @var \DBmysql $DB */
                global $DB;
                $DB->update(self::getTable(), [
                    'access_token'         => (new GLPIKey())->encrypt($token),
                    'access_token_expires' => $expires_at,
                ], ['id' => $id]);
            }
        );
    }

    public static function client(array $project): AdoClient
    {
        return new AdoClient(
            Settings::baseUrl(),
            (string) $project['organization'],
            (string) $project['ado_project'],
            self::auth($project)
        );
    }

    private static function decrypt(string $stored): string
    {
        // decrypt() returns an empty string when the value cannot be decrypted
        // (e.g. glpicrypt.key changed): the administrator then has to enter the secret again.
        return $stored !== '' ? (new GLPIKey())->decrypt($stored) : '';
    }

    // ------------------------------------------------------------------- saving

    /**
     * Create (id = 0) or update a project from the configuration form. An empty token keeps the stored one.
     *
     * @param array<string, mixed> $input
     *
     * @return int id of the project
     *
     * @throws RuntimeException with a message that can be shown to the administrator
     */
    public static function saveFromInput(array $input, int $id = 0): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $current = $id > 0 ? self::find($id) : null;
        if ($id > 0 && $current === null) {
            throw new RuntimeException(__('Project not found.', 'azuredevops'));
        }

        $str = static fn(string $k): string => is_scalar($input[$k] ?? null) ? trim((string) $input[$k]) : '';

        $name = $str('name');
        if ($name === '') {
            throw new RuntimeException(__('The project needs a name.', 'azuredevops'));
        }
        $duplicate = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['name' => $name, 'id' => ['<>', $id]]])->current();
        if ($duplicate) {
            throw new RuntimeException(__('Another project already has this name.', 'azuredevops'));
        }

        $organization = self::normalizeOrganization($str('organization'));
        $ado_project  = $str('ado_project');
        if ($organization === '' || $ado_project === '') {
            throw new RuntimeException(__('The organization and the Azure DevOps project name are required.', 'azuredevops'));
        }

        $done = array_values(array_intersect(
            self::csv(is_array($input['done_categories'] ?? null) ? implode(',', $input['done_categories']) : $str('done_categories')),
            [self::CAT_COMPLETED, self::CAT_RESOLVED]
        ));
        if (!in_array(self::CAT_COMPLETED, $done, true)) {
            array_unshift($done, self::CAT_COMPLETED);
        }

        $list = static function (mixed $value): string {
            $items = is_array($value) ? $value : explode(',', (string) $value);

            return implode(',', self::csv(implode(',', array_map('strval', $items))));
        };

        // Values for the fields the process requires ("Reference.Name = value", one per line)
        [$default_entries, $bad_lines] = self::parseDefaultFields($str('default_fields'));
        if ($bad_lines !== []) {
            throw new RuntimeException(sprintf(
                __('Invalid line in the default fields (expected "Reference.Name = value" or "Type: Reference.Name = value", and not System.Title or System.Description): %s', 'azuredevops'),
                mb_substr($bad_lines[0], 0, 120)
            ));
        }

        $values = [
            'name'                => mb_substr($name, 0, 255),
            'organization'        => mb_substr($organization, 0, 255),
            'ado_project'         => mb_substr($ado_project, 0, 255),
            'is_active'           => !empty($input['is_active']) ? 1 : 0,
            'is_restricted'       => !empty($input['is_restricted']) ? 1 : 0,
            'workitem_type'       => mb_substr($str('workitem_type'), 0, 128),
            // 1 = only the default type and the ones below are offered in the ticket; 0 = every type of the project
            'restrict_types'      => !empty($input['restrict_types']) ? 1 : 0,
            // Types the technician can choose in the ticket when restricted (the default type is always offered)
            'offered_types'       => mb_substr(implode(',', array_filter(
                self::csv(implode(',', array_map('strval', (array) ($input['offered_types'] ?? explode(',', $str('offered_types')))))),
                static fn(string $t): bool => strcasecmp($t, $str('workitem_type')) !== 0
            )), 0, 512),
            'parent_types'        => mb_substr($list($input['parent_types'] ?? ''), 0, 255),
            'followup_only_types' => mb_substr($list($input['followup_only_types'] ?? ''), 0, 255),
            'done_categories'     => implode(',', $done),
            'area_path'           => $str('area_path') !== '' ? mb_substr($str('area_path'), 0, 512) : null,
            'default_tags'        => mb_substr(is_array($input['default_tags'] ?? null) ? implode(',', array_map('strval', $input['default_tags'])) : $str('default_tags'), 0, 255),
            // 1 = only the tags that already exist in Azure DevOps are sent (an account that cannot create tags)
            'restrict_tags'       => !empty($input['restrict_tags']) ? 1 : 0,
            'default_fields'      => $default_entries !== [] ? self::formatDefaultFields($default_entries) : null,
            'hidden_fields'       => self::mergeHiddenFields($current, $input),
            'date_mod'            => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ];

        // Credentials: a personal access token, or a Microsoft Entra application. Both can be stored; the mode
        // chosen decides which one is used. Secrets are encrypted; an empty field keeps the stored value.
        $mode = $str('auth_mode') === AdoAuth::MODE_ENTRA ? AdoAuth::MODE_ENTRA : AdoAuth::MODE_PAT;
        $values['auth_mode'] = $mode;
        $values['tenant_id'] = $str('tenant_id') !== '' ? mb_substr($str('tenant_id'), 0, 255) : null;
        $values['client_id'] = $str('client_id') !== '' ? mb_substr($str('client_id'), 0, 255) : null;

        $token  = $str('token');
        $secret = $str('client_secret');
        if ($token !== '') {
            $values['token'] = (new GLPIKey())->encrypt($token);
        }
        if ($secret !== '') {
            $values['client_secret'] = (new GLPIKey())->encrypt($secret);
        }
        // New credentials: the previous error (expired token...) no longer applies.
        if ($token !== '' || $secret !== '') {
            $values['last_error'] = null;
        }
        // The access token obtained from Entra belongs to the application credentials: forget it when they change.
        if ($current !== null && ($secret !== '' || (string) $values['tenant_id'] !== (string) $current['tenant_id'] || (string) $values['client_id'] !== (string) $current['client_id'])) {
            $values['access_token']         = null;
            $values['access_token_expires'] = 0;
        }

        $has_token  = $token !== '' || ($current !== null && (string) $current['token'] !== '');
        $has_secret = $secret !== '' || ($current !== null && (string) $current['client_secret'] !== '');
        if ($mode === AdoAuth::MODE_PAT && !$has_token) {
            throw new RuntimeException(__('The access token is required.', 'azuredevops'));
        }
        if ($mode === AdoAuth::MODE_ENTRA && ($values['tenant_id'] === null || $values['client_id'] === null || !$has_secret)) {
            throw new RuntimeException(__('The tenant ID, the application (client) ID and the client secret are required.', 'azuredevops'));
        }

        // The cached types belong to the organization/project: forget them when those change.
        if ($current !== null && ($current['organization'] !== $values['organization'] || $current['ado_project'] !== $values['ado_project'])) {
            $values['types_cache'] = null;
        }

        if ($current === null) {
            $values['date_creation'] = $values['date_mod'];
            $DB->insert(self::getTable(), $values);

            return (int) $DB->insertId();
        }

        $DB->update(self::getTable(), $values, ['id' => $id]);
        self::forgetTags($id);

        return $id;
    }

    /**
     * "https://dev.azure.com/acme/", "dev.azure.com/acme" and "acme" all give "acme".
     */
    public static function normalizeOrganization(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        $value = preg_replace('#^[^/]*dev\.azure\.com/#i', '', $value) ?? $value;
        // Legacy URL: acme.visualstudio.com
        if (preg_match('#^([^/.]+)\.visualstudio\.com#i', $value, $m)) {
            $value = $m[1];
        }
        $parts = explode('/', trim($value, '/'));

        return trim($parts[0] ?? '');
    }

    /**
     * Delete a project. Refused while tickets are linked to it: deactivate it instead.
     *
     * @throws RuntimeException
     */
    public static function remove(int $id): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        if (self::find($id) === null) {
            return;
        }
        if (countElementsInTable(Link::getTable(), ['projects_id' => $id]) > 0) {
            throw new RuntimeException(__('Tickets are linked to this project: it cannot be deleted. Deactivate it instead.', 'azuredevops'));
        }
        ProjectAccess::clearProject($id);
        $DB->delete(self::getTable(), ['id' => $id]);
    }

    // ------------------------------------------------------------------ tags

    private const TAGS_TTL = 300;

    /**
     * The tags that exist in the project in Azure DevOps. Kept five minutes (the lists of the forms ask for them often).
     *
     * @return string[]
     *
     * @throws AdoException
     */
    public static function tags(array $project): array
    {
        /** @var \Psr\SimpleCache\CacheInterface|null $GLPI_CACHE */
        global $GLPI_CACHE;

        $key = 'azuredevops_tags_' . (int) $project['id'];
        try {
            $cached = $GLPI_CACHE?->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // no cache: ask Azure DevOps
        }

        $tags = self::client($project)->getTags();
        try {
            $GLPI_CACHE?->set($key, $tags, self::TAGS_TTL);
        } catch (\Throwable) {
            // only a cache
        }

        return $tags;
    }

    public static function forgetTags(int $id): void
    {
        /** @var \Psr\SimpleCache\CacheInterface|null $GLPI_CACHE */
        global $GLPI_CACHE;

        try {
            $GLPI_CACHE?->delete('azuredevops_tags_' . $id);
        } catch (\Throwable) {
            // only a cache
        }
    }

    /**
     * Tags matching what the technician typed, for the select boxes.
     *
     * @return array<int, array{id: string, text: string}>
     *
     * @throws AdoException
     */
    public static function tagResults(array $project, string $term, int $limit = 50): array
    {
        $term    = mb_strtolower(trim($term));
        $results = [];
        foreach (self::tags($project) as $tag) {
            if ($term === '' || str_contains(mb_strtolower($tag), $term)) {
                $results[] = ['id' => $tag, 'text' => $tag];
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }

    // ------------------------------------------------------ types to create

    /**
     * The type created when the technician does not choose one: the project's default type, else (restricted list) the
     * first type of the list.
     */
    public static function defaultType(array $project): string
    {
        $type = trim((string) ($project['workitem_type'] ?? ''));
        if ($type !== '' || empty($project['restrict_types'])) {
            return $type;
        }

        return self::csv($project['offered_types'] ?? '')[0] ?? '';
    }

    /**
     * The types a technician can choose in the ticket, the default one first. By default, every type read from Azure
     * DevOps (at the last connection test); when the project restricts them, the default type and the chosen ones.
     *
     * @return string[]
     */
    public static function offeredTypes(array $project): array
    {
        $types   = [];
        $default = self::defaultType($project);
        if ($default !== '') {
            $types[strtolower($default)] = $default;
        }
        $others = !empty($project['restrict_types'])
            ? self::csv($project['offered_types'] ?? '')
            : array_map('strval', array_keys(self::cache($project)['types']));
        foreach ($others as $type) {
            $types[strtolower($type)] ??= $type;
        }

        return array_values($types);
    }

    /**
     * The type to create: the one chosen in the ticket, which must be offered by the project, or the default one.
     *
     * @throws RuntimeException
     */
    public static function resolveType(array $project, ?string $type): string
    {
        $type = trim((string) $type);
        if ($type === '') {
            $type = self::defaultType($project);
            if ($type === '') {
                throw new RuntimeException(__('No work item type is configured for this project.', 'azuredevops'));
            }

            return $type;
        }
        foreach (self::offeredTypes($project) as $offered) {
            if (strcasecmp($offered, $type) === 0) {
                return $offered;
            }
        }

        throw new RuntimeException(sprintf(__('This type of work item cannot be created from a ticket in this project: %s', 'azuredevops'), $type));
    }

    // ---------------------------------------------------------- default fields

    /**
     * Parse the default fields: "Reference.Name = value" (every type) or "Type: Reference.Name = value" (one type),
     * one per line. Empty lines and lines starting with # are ignored.
     *
     * @return array{0: array<int, array{type: ?string, ref: string, value: string}>, 1: string[]} the entries and the invalid lines
     */
    public static function parseDefaultFields(?string $text): array
    {
        $entries = [];
        $bad     = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // A reference name always has a dot: System.AreaPath, Microsoft.VSTS.Common.Priority, Custom.Team...
            if (!preg_match('/^(?:([^=:]+?)\s*:\s+)?([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+)\s*=\s*(.*)$/', $line, $m) || in_array(strtolower($m[2]), ['system.title', 'system.description'], true)) {
                $bad[] = $line;
                continue;
            }
            $type = trim($m[1] ?? '');
            $entries[] = ['type' => $type !== '' ? $type : null, 'ref' => $m[2], 'value' => trim($m[3])];
        }

        return [$entries, $bad];
    }

    /**
     * The lines as stored: normalised, one per line, the last value of a field winning.
     *
     * @param array<int, array{type: ?string, ref: string, value: string}> $entries
     */
    public static function formatDefaultFields(array $entries): string
    {
        $last = [];
        foreach ($entries as $entry) {
            $last[strtolower(($entry['type'] ?? '') . '|' . $entry['ref'])] = $entry;
        }

        return implode("\n", array_map(
            static fn(array $e): string => ($e['type'] !== null ? $e['type'] . ': ' : '') . $e['ref'] . ' = ' . $e['value'],
            array_values($last)
        ));
    }

    /**
     * Default values for a type: the lines that apply to every type, completed (and overridden) by those of that type.
     *
     * @return array<string, string> reference name => value (placeholders not yet replaced)
     */
    public static function defaultFields(array $project, ?string $type = null): array
    {
        $type   = $type ?? self::defaultType($project);
        $values = [];
        foreach ([true, false] as $global) {   // every type first, then the specific ones win
            foreach (self::parseDefaultFields($project['default_fields'] ?? '')[0] as $entry) {
                if ($global ? $entry['type'] === null : ($entry['type'] !== null && strcasecmp($entry['type'], $type) === 0)) {
                    $values[strtolower($entry['ref'])] = [$entry['ref'], $entry['value']];
                }
            }
        }

        return array_column($values, 1, 0);
    }

    /**
     * The required fields the administrator chose not to ask in the ticket ("Type: Reference.Name" lines).
     *
     * @return array<string, true> keys: lower case "type|reference"
     */
    public static function hiddenFields(array $project): array
    {
        $hidden = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($project['hidden_fields'] ?? '')) ?: [] as $line) {
            if (preg_match('/^(.+?)\s*:\s+(\S+)$/', trim($line), $m)) {
                $hidden[strtolower($m[1] . '|' . $m[2])] = true;
            }
        }

        return $hidden;
    }

    /**
     * Apply what the project form sent: it lists the fields it showed and ticks those to ask in the ticket. The others
     * become hidden. What the form did not show (a type not read yet...) is kept as it was.
     *
     * @param array<string, mixed> $input
     */
    private static function mergeHiddenFields(?array $current, array $input): ?string
    {
        $existing = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($current['hidden_fields'] ?? '')) ?: [] as $line) {
            if (preg_match('/^(.+?)\s*:\s+(\S+)$/', trim($line), $m)) {
                $existing[strtolower($m[1] . '|' . $m[2])] = [$m[1], $m[2]];
            }
        }

        $listed = is_array($input['listed_fields'] ?? null) ? $input['listed_fields'] : [];
        $asked  = is_array($input['ask_fields'] ?? null) ? $input['ask_fields'] : [];
        foreach ($listed as $type => $refs) {
            foreach ((array) $refs as $ref) {
                $key = strtolower($type . '|' . $ref);
                if (in_array($ref, (array) ($asked[$type] ?? []), true)) {
                    unset($existing[$key]);
                } else {
                    $existing[$key] = [(string) $type, (string) $ref];
                }
            }
        }

        $lines = array_map(static fn(array $e): string => $e[0] . ': ' . $e[1], array_values($existing));

        return $lines !== [] ? implode("\n", $lines) : null;
    }

    /**
     * Fields the process requires for a work item type (the default one unless told), as read at the last test, with
     * whether the project gives them a value. The fields of the "System." family are filled in by Azure DevOps itself.
     *
     * @return array<int, array{ref: string, name: string, default: ?string, allowed: string[], covered: bool, hidden: bool}>
     */
    public static function requiredFields(array $project, ?string $type = null): array
    {
        $type   = $type ?? self::defaultType($project);
        $given  = array_change_key_case(self::defaultFields($project, $type), CASE_LOWER);
        $hidden = self::hiddenFields($project);
        $fields = [];
        foreach (self::cache($project)['required'][$type] ?? [] as $field) {
            $fields[] = [
                'ref'      => $field['ref'],
                'name'     => $field['name'],
                'default'  => $field['default'] ?? null,
                'allowed'  => $field['allowed'] ?? [],
                'covered'  => isset($given[strtolower($field['ref'])]) || ($field['default'] ?? null) !== null,
                // not asked in the ticket (the administrator found it is not a real requirement)
                'hidden'   => isset($hidden[strtolower($type . '|' . $field['ref'])]),
            ];
        }

        return $fields;
    }

    /**
     * Keep what the form needs of the fields of a type: the required ones, outside the System family.
     *
     * @param array<int, array{ref: string, name: string, required: bool, default: ?string, allowed: string[]}> $fields
     *
     * @return array<int, array{ref: string, name: string, default: ?string, allowed: string[]}>
     */
    private static function requiredFromFields(array $fields): array
    {
        $required = [];
        foreach ($fields as $field) {
            if ($field['required'] && !str_starts_with(strtolower($field['ref']), 'system.')) {
                $required[] = ['ref' => $field['ref'], 'name' => $field['name'], 'default' => $field['default'], 'allowed' => array_slice($field['allowed'], 0, 200)];
            }
        }

        return $required;
    }

    /**
     * Were the required fields of this type read from Azure DevOps (even if it has none)?
     */
    public static function requiredFieldsRead(array $project, string $type): bool
    {
        return array_key_exists($type, self::cache($project)['required'] ?? []);
    }

    /** The list of the types is read again, when a ticket is opened, if it is older than this (seconds). */
    public const TYPES_MAX_AGE = 3600;

    /** The required fields of the types are read again (when a type is next chosen) if they are older than this. */
    private const REQUIRED_MAX_AGE = 86400;

    /**
     * Keep the list of the types up to date without anybody pressing a button: when it is older than $max_age, read it
     * again (one request). A type added or removed in Azure DevOps shows up in the tickets within the hour. The required
     * fields already read are kept (they are read again, per type, once a day); a failure leaves the cache as it is and
     * tries again in five minutes.
     *
     * @param array<string, mixed> $project
     *
     * @return array<string, mixed> the project, with its refreshed cache
     */
    public static function refreshTypesIfStale(array $project, int $max_age = self::TYPES_MAX_AGE): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $cache   = self::cache($project);
        $fetched = strtotime((string) ($cache['fetched_at'] ?? '')) ?: 0;
        if (time() - $fetched < $max_age || empty($project['is_active'])) {
            return $project;
        }

        $id = (int) $project['id'];
        try {
            $types = self::client($project)->getWorkItemTypes();
        } catch (AdoException $e) {
            Toolbox::logInFile('azuredevops', sprintf("Types not refreshed (project %d): %s\n", $id, $e->getMessage()));
            $cache['fetched_at'] = date('Y-m-d H:i:s', time() - $max_age + 300);   // again in five minutes
            $DB->update(self::getTable(), ['types_cache' => json_encode($cache, JSON_UNESCAPED_UNICODE)], ['id' => $id]);

            return self::find($id) ?? $project;
        }

        $fresh = [];
        foreach ($types as $type) {
            $fresh[$type['name']] = ['states' => $type['states'], 'hierarchy' => $type['hierarchy'] ?? ''];
        }
        // What was read about a type that still exists is kept, unless it is a day old.
        $required = [];
        if (time() - (strtotime((string) ($cache['required_at'] ?? '')) ?: 0) < self::REQUIRED_MAX_AGE) {
            foreach ($cache['required'] ?? [] as $name => $fields) {
                foreach (array_keys($fresh) as $known) {
                    if (strcasecmp((string) $known, (string) $name) === 0) {
                        $required[$name] = $fields;
                    }
                }
            }
        }
        $cache['types']      = $fresh;
        $cache['required']   = $required;
        $cache['fetched_at'] = date('Y-m-d H:i:s');
        if ($required === []) {
            unset($cache['required_at']);
        }
        $DB->update(self::getTable(), ['types_cache' => json_encode($cache, JSON_UNESCAPED_UNICODE), 'last_error' => null], ['id' => $id]);

        return self::find($id) ?? $project;
    }

    /**
     * Read the required fields of a type that was not read yet (a type chosen in a ticket for the first time), and keep
     * them with the others.
     *
     * @throws AdoException
     */
    public static function ensureRequiredFields(int $id, string $type): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $project = self::find($id);
        if ($project === null || self::requiredFieldsRead($project, $type)) {
            return;
        }

        $required = self::requiredFromFields(self::client($project)->getTypeFields($type));

        // Read again just before writing: another request may have added other types meanwhile.
        $project = self::find($id);
        if ($project === null) {
            return;
        }
        $cache = self::cache($project);
        $cache['required'][$type] = $required;
        $DB->update(self::getTable(), ['types_cache' => json_encode($cache, JSON_UNESCAPED_UNICODE)], ['id' => $id]);
    }

    /**
     * Required fields of every type offered by the project that was read (at the last test, or when first chosen).
     *
     * @return array<string, array<int, array{ref: string, name: string, default: ?string, allowed: string[], covered: bool}>>
     */
    public static function requiredFieldsByType(array $project): array
    {
        $all = [];
        foreach (self::offeredTypes($project) as $type) {
            if (self::requiredFieldsRead($project, $type)) {
                $all[$type] = self::requiredFields($project, $type);
            }
        }

        return $all;
    }

    // ------------------------------------------------------- types and states

    /**
     * Test the token and read the work item types and their states from Azure DevOps; the result is cached
     * so that the form can offer the real types and the automatic action can tell "done" from "in progress".
     *
     * @return array{project: string, user: string, types: string[]}
     *
     * @throws AdoException
     */
    public static function testAndCache(int $id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $project = self::find($id);
        if ($project === null) {
            throw new RuntimeException(__('Project not found.', 'azuredevops'));
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        try {
            $client = self::client($project);
            $info   = $client->testConnection();
            $types  = $client->getWorkItemTypes();
        } catch (AdoException $e) {
            $DB->update(self::getTable(), ['last_error' => mb_substr($e->getMessage(), 0, 1000), 'date_last_check' => $now], ['id' => $id]);
            throw $e;
        }

        $cache = ['fetched_at' => $now, 'types' => []];
        foreach ($types as $type) {
            $cache['types'][$type['name']] = ['states' => $type['states'], 'hierarchy' => $type['hierarchy'] ?? ''];
        }
        // The fields the process requires for each type that can be created (not fatal: only used to help the administrator).
        $cache['required']    = [];
        $cache['required_at'] = $now;
        foreach (array_slice(self::offeredTypes($project), 0, 12) as $wanted) {
            $type = null;
            foreach (array_keys($cache['types']) as $known) {
                if (strcasecmp((string) $known, $wanted) === 0) {
                    $type = (string) $known;
                }
            }
            if ($type === null) {
                continue;
            }
            try {
                $cache['required'][$wanted] = self::requiredFromFields($client->getTypeFields($type));
            } catch (AdoException $e) {
                Toolbox::logInFile('azuredevops', sprintf("Fields of the type %s not read (project %d): %s\n", $type, $id, $e->getMessage()));
            }
        }

        $DB->update(self::getTable(), [
            'types_cache'     => json_encode($cache, JSON_UNESCAPED_UNICODE),
            'last_error'      => null,
            'date_last_check' => $now,
        ], ['id' => $id]);

        return ['project' => $info['project'], 'user' => $info['user'], 'types' => array_keys($cache['types'])];
    }

    /**
     * @return array{fetched_at?: string, types: array<string, array{states: array<string, string>}>}
     */
    public static function cache(array $project): array
    {
        $data = json_decode((string) ($project['types_cache'] ?? ''), true);

        return is_array($data) && is_array($data['types'] ?? null) ? $data : ['types' => []];
    }

    /**
     * @return string[] type names known from the last test
     */
    public static function typeNames(array $project): array
    {
        return array_keys(self::cache($project)['types']);
    }

    /**
     * Category (Proposed, InProgress, Resolved, Completed, Removed) of a state of a work item type.
     * Unknown states are guessed from the usual names of the standard process templates.
     */
    public static function stateCategory(array $project, string $type, string $state): ?string
    {
        foreach (self::cache($project)['types'] as $name => $def) {
            if (strcasecmp($name, $type) !== 0) {
                continue;
            }
            foreach ($def['states'] as $state_name => $category) {
                if (strcasecmp((string) $state_name, $state) === 0) {
                    return $category !== '' ? $category : null;
                }
            }
        }

        return match (mb_strtolower($state)) {
            'removed'                 => self::CAT_REMOVED,
            'done', 'closed', 'completed' => self::CAT_COMPLETED,
            'resolved'                => self::CAT_RESOLVED,
            default                   => null,
        };
    }

    public static function isFollowupOnlyType(array $project, ?string $type): bool
    {
        return $type !== null && $type !== '' && self::inList($type, $project['followup_only_types'] ?? '');
    }
}
