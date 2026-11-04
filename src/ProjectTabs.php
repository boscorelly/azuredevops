<?php

namespace GlpiPlugin\Azuredevops;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Html;

/**
 * The tabs of the page of a project, one per zone of settings. Each tab is a form of its own that only saves its zone
 * (Project::saveZone).
 */
class ProjectTabs extends CommonGLPI
{
    public const CONNECTION = 1;
    public const WORK_ITEMS = 2;
    public const FIELDS     = 3;
    public const ACCESS     = 4;

    /** Value of the hidden "_zone" field of each form => tab number. */
    public const ZONE_TABS = [
        'connection' => self::CONNECTION,
        'work_items' => self::WORK_ITEMS,
        'fields'     => self::FIELDS,
        'access'     => self::ACCESS,
    ];

    public static function getTypeName($nb = 0): string
    {
        return __('Azure DevOps', 'azuredevops');
    }

    /**
     * The project shown: the "id" of the page (GLPI passes it to the tabs the same way for every page).
     */
    private static function projectId(): int
    {
        return (int) ($_GET['id'] ?? 0);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if (!($item instanceof ProjectPage) || !ConfigPage::canManage()) {
            return '';
        }

        $tabs = [self::CONNECTION => self::createTabEntry(__('Connection', 'azuredevops'), 0, null, 'ti ti-plug-connected')];
        // The other zones need a saved project.
        if (self::projectId() > 0) {
            $tabs += [
                self::WORK_ITEMS => self::createTabEntry(__('Work items', 'azuredevops'), 0, null, 'ti ti-list-details'),
                self::FIELDS     => self::createTabEntry(__('Fields and tags', 'azuredevops'), 0, null, 'ti ti-forms'),
                self::ACCESS     => self::createTabEntry(__('Who can use this project', 'azuredevops'), 0, null, 'ti ti-users'),
            ];
        }

        return $tabs;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        // Checked again here: the tab content is also reachable directly through GLPI's ajax endpoint.
        if (!($item instanceof ProjectPage) || !ConfigPage::canManage()) {
            return false;
        }

        $id = self::projectId();
        $template = match ((int) $tabnum) {
            self::CONNECTION => 'connection',
            self::WORK_ITEMS => 'work_items',
            self::FIELDS     => 'fields',
            self::ACCESS     => 'access',
            default          => null,
        };
        if ($template === null || ((int) $tabnum !== self::CONNECTION && $id <= 0)) {
            return false;
        }
        $project = $id > 0 ? Project::find($id) : null;
        if ($id > 0 && $project === null) {
            return false;
        }

        TemplateRenderer::getInstance()->display("@azuredevops/project/$template.html.twig", self::dataFor($project));

        return true;
    }

    /**
     * @param array<string, mixed>|null $project stored row, null for a new project
     *
     * @return array<string, mixed>
     */
    private static function dataFor(?array $project): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $base = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/azuredevops';
        $row  = ($project ?? []) + Project::defaultInput() + ['id' => 0, 'name' => '', 'organization' => '', 'ado_project' => '', 'last_error' => null];
        $id   = (int) $row['id'];
        $has_token  = $project !== null && ($project['token'] ?? '') !== '';
        $has_secret = $project !== null && ($project['client_secret'] ?? '') !== '';
        // Secrets are never sent back to the browser.
        unset($row['token'], $row['client_secret'], $row['access_token'], $row['types_cache']);

        return [
            'project'             => $row,
            'is_new'              => $project === null,
            'has_token'           => $has_token,
            'has_secret'          => $has_secret,
            'required_by_type'    => $project !== null ? Project::requiredFieldsByType($project) : [],
            'offered_types'       => Project::csv($row['offered_types']),
            'default_tags'        => Project::csv($row['default_tags']),
            'tags_url'            => $id > 0 ? $base . '/Config/Project/' . $id . '/Tags' : '',
            'types'               => $project !== null ? Project::typeNames($project) : [],
            'parent_types'        => Project::csv($row['parent_types']),
            'followup_only_types' => Project::csv($row['followup_only_types']),
            'done_categories'     => Project::csv($row['done_categories']),
            'access_users'        => $id > 0 ? ProjectAccess::users($id) : [],
            'access_groups'       => $id > 0 ? ProjectAccess::groups($id) : [],
            'links'               => $id > 0 ? countElementsInTable(Link::getTable(), ['projects_id' => $id]) : 0,
            'form_url'            => $base . '/Config/Project',
            'delete_url'          => $id > 0 ? $base . '/Config/Project/' . $id . '/Delete' : '',
            'refresh_url'         => $id > 0 ? $base . '/Config/Project/' . $id . '/Refresh' : '',
            'types_read_at'       => $project !== null && !empty(Project::cache($project)['fetched_at']) ? Html::convDateTime((string) Project::cache($project)['fetched_at']) : '',
            'back_url'            => $base . '/Config',
            'search_users_url'    => $base . '/Config/Search/Users',
            'search_groups_url'   => $base . '/Config/Search/Groups',
        ];
    }
}
