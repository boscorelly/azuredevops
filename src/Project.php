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
                $items[mb_strtolower($item)] = $item;
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
        [$default_fields, $bad_lines] = self::parseDefaultFields($str('default_fields'));
        if ($bad_lines !== []) {
            throw new RuntimeException(sprintf(
                __('Invalid line in the default fields (expected "Reference.Name = value", and not System.Title or System.Description): %s', 'azuredevops'),
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
            'parent_types'        => mb_substr($list($input['parent_types'] ?? ''), 0, 255),
            'followup_only_types' => mb_substr($list($input['followup_only_types'] ?? ''), 0, 255),
            'done_categories'     => implode(',', $done),
            'area_path'           => $str('area_path') !== '' ? mb_substr($str('area_path'), 0, 512) : null,
            'default_tags'        => mb_substr($str('default_tags'), 0, 255),
            'default_fields'      => $default_fields !== [] ? implode("\n", array_map(static fn($k, $v) => $k . ' = ' . $v, array_keys($default_fields), $default_fields)) : null,
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

    // ---------------------------------------------------------- default fields

    /**
     * Parse "Reference.Name = value" lines. Empty lines and lines starting with # are ignored.
     *
     * @return array{0: array<string, string>, 1: string[]} the fields (reference name => value) and the invalid lines
     */
    public static function parseDefaultFields(?string $text): array
    {
        $fields = [];
        $bad    = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // A reference name always has a dot: System.AreaPath, Microsoft.VSTS.Common.Priority, Custom.Team...
            if (!preg_match('/^([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+)\s*=\s*(.*)$/', $line, $m) || in_array(strtolower($m[1]), ['system.title', 'system.description'], true)) {
                $bad[] = $line;
                continue;
            }
            $fields[$m[1]] = trim($m[2]);
        }

        return [$fields, $bad];
    }

    /**
     * @return array<string, string> reference name => value (placeholders not yet replaced)
     */
    public static function defaultFields(array $project): array
    {
        return self::parseDefaultFields($project['default_fields'] ?? '')[0];
    }

    /**
     * Fields the process of the configured work item type requires, as read at the last test, with whether the
     * project gives them a value. The fields of the "System." family are filled in by Azure DevOps itself.
     *
     * @return array<int, array{ref: string, name: string, default: ?string, allowed: string[], covered: bool}>
     */
    public static function requiredFields(array $project): array
    {
        $type   = (string) ($project['workitem_type'] ?? '');
        $given  = array_change_key_case(self::defaultFields($project), CASE_LOWER);
        $fields = [];
        foreach (self::cache($project)['required'][$type] ?? [] as $field) {
            $fields[] = [
                'ref'      => $field['ref'],
                'name'     => $field['name'],
                'default'  => $field['default'] ?? null,
                'allowed'  => $field['allowed'] ?? [],
                'covered'  => isset($given[strtolower($field['ref'])]) || ($field['default'] ?? null) !== null,
            ];
        }

        return $fields;
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
        // The fields the process requires for the type to create (not fatal: only used to help the administrator).
        $cache['required'] = [];
        $type = (string) $project['workitem_type'];
        if ($type !== '' && isset($cache['types'][$type])) {
            try {
                foreach ($client->getTypeFields($type) as $field) {
                    if ($field['required'] && !str_starts_with(strtolower($field['ref']), 'system.')) {
                        $cache['required'][$type][] = ['ref' => $field['ref'], 'name' => $field['name'], 'default' => $field['default'], 'allowed' => array_slice($field['allowed'], 0, 200)];
                    }
                }
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
