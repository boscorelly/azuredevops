<?php

namespace GlpiPlugin\Azuredevops;

use Config;

/**
 * Global plugin settings, stored in glpi_configs (context "plugin:azuredevops").
 *
 * No secret here: the access tokens belong to the configured projects (see Project) and are encrypted there.
 */
final class Settings
{
    public const CONTEXT = 'plugin:azuredevops';

    public const DEFAULT_BASE_URL = 'https://dev.azure.com';

    public const DEFAULTS = [
        // Advanced: Azure DevOps Services is https://dev.azure.com (also used to point the tests to a mock)
        'api_base_url'           => self::DEFAULT_BASE_URL,
        // Work item creation
        'title_template'         => '[GLPI #{id}] {title}',
        'include_people'         => '1',
        'upload_attachments'     => '1',
        'max_attachment_mb'      => '10',
        'creation_followup'      => '1',
        'creation_followup_private' => '1',
        'notify_requester'       => '0',
        'notify_requester_text'  => '',
        // When the work item is done
        'close_followup'         => '1',
        'close_followup_private' => '1',
        'notify_requester_close' => '0',
        'notify_requester_close_text' => '',
        'close_solve_ticket'     => '0',
        'solutiontypes_id'       => '0',
        'solution_template'      => '',
        'actor_users_id'         => '0',
    ];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return Config::getConfigurationValues(self::CONTEXT) + self::DEFAULTS;
    }

    public static function get(string $name): string
    {
        return (string) (self::all()[$name] ?? '');
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function save(array $input): void
    {
        $values = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            if (!array_key_exists($name, $input)) {
                continue;
            }
            $value = is_scalar($input[$name]) ? trim((string) $input[$name]) : '';
            if ($name === 'api_base_url') {
                $value = rtrim($value, '/');
                if ($value === '') {
                    $value = self::DEFAULT_BASE_URL;
                }
            }
            $values[$name] = $value;
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    public static function baseUrl(): string
    {
        return rtrim(self::get('api_base_url') ?: self::DEFAULT_BASE_URL, '/');
    }
}
