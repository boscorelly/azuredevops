<?php

namespace GlpiPlugin\Azuredevops;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use ProfileRight;
use Session;

/**
 * The tabs of the configuration page, one per zone of settings. Each tab is a form of its own.
 */
class ConfigTabs extends CommonGLPI
{
    public const PROJECTS = 1;
    public const CREATION = 2;
    public const CLOSURE  = 3;
    public const ADVANCED = 4;
    public const RIGHTS   = 5;

    /** Value of the hidden "_tab" field of each form => tab number. */
    public const TAB_NAMES = [
        'projects' => self::PROJECTS,
        'creation' => self::CREATION,
        'closure'  => self::CLOSURE,
        'advanced' => self::ADVANCED,
        'rights'   => self::RIGHTS,
    ];

    public static function getTypeName($nb = 0): string
    {
        return __('Azure DevOps', 'azuredevops');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if (!($item instanceof ConfigPage) || !ConfigPage::canManage()) {
            return '';
        }

        return [
            self::PROJECTS => self::createTabEntry(__('Azure DevOps projects', 'azuredevops'), 0, null, 'ti ti-folders'),
            self::CREATION => self::createTabEntry(__('Work item creation', 'azuredevops'), 0, null, 'ti ti-file-plus'),
            self::CLOSURE  => self::createTabEntry(__('When a work item is done on Azure DevOps', 'azuredevops'), 0, null, 'ti ti-circle-check'),
            self::ADVANCED => self::createTabEntry(__('Advanced', 'azuredevops'), 0, null, 'ti ti-adjustments'),
            self::RIGHTS   => self::createTabEntry(__('Rights by profile', 'azuredevops'), 0, null, 'ti ti-shield-lock'),
        ];
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        // Checked again here: the tab content is also reachable directly through GLPI's ajax endpoint.
        if (!($item instanceof ConfigPage) || !ConfigPage::canManage()) {
            return false;
        }

        $template = match ((int) $tabnum) {
            self::PROJECTS => 'projects',
            self::CREATION => 'creation',
            self::CLOSURE  => 'closure',
            self::ADVANCED => 'advanced',
            self::RIGHTS   => 'rights',
            default        => null,
        };
        if ($template === null) {
            return false;
        }

        TemplateRenderer::getInstance()->display("@azuredevops/config/$template.html.twig", self::dataFor((int) $tabnum));

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function dataFor(int $tab): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $settings = Settings::all();
        $base     = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/azuredevops';
        $data     = [
            'settings'    => $settings,
            'form_url'    => $base . '/Config',
            'new_url'     => $base . '/Config/Project',
            'project_url' => $base . '/Config/Project',
            'rights_url'  => $base . '/Config/Rights',
        ];

        if ($tab === self::PROJECTS) {
            $data['projects'] = self::projects();
        }
        if ($tab === self::CREATION) {
            $data['default_notice_creation'] = RequesterNotice::defaultText(RequesterNotice::CREATION);
        }
        if ($tab === self::CLOSURE) {
            $data += [
                'solution_types'       => self::solutionTypes(),
                'users'                => self::users(),
                'close_mode'           => $settings['close_solve_ticket'] === '1' ? 'solve' : ($settings['notify_requester_close'] === '1' ? 'followup' : 'none'),
                'default_notice_close' => RequesterNotice::defaultText(RequesterNotice::CLOSURE),
            ];
        }
        if ($tab === self::RIGHTS) {
            $data += [
                'profiles'        => self::profiles(),
                'right_labels'    => (new Link())->getRights(),
                'can_edit_rights' => Session::haveRight('profile', UPDATE),
            ];
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function projects(): array
    {
        $projects = [];
        foreach (Project::all() as $project) {
            $projects[] = [
                'id'            => (int) $project['id'],
                'name'          => $project['name'],
                'target'        => $project['organization'] . ' / ' . $project['ado_project'],
                'type'          => Project::defaultType($project),
                'more_types'    => max(0, count(Project::offeredTypes($project)) - 1),
                'auth'          => $project['auth_mode'] ?? 'pat',
                'is_active'     => (bool) $project['is_active'],
                'is_restricted' => (bool) $project['is_restricted'],
                'users'         => count(ProjectAccess::users((int) $project['id'])),
                'groups'        => array_values(ProjectAccess::groups((int) $project['id'])),
                'last_error'    => $project['last_error'],
                'links'         => countElementsInTable(Link::getTable(), ['projects_id' => (int) $project['id']]),
            ];
        }

        return $projects;
    }

    /**
     * @return array<int, string>
     */
    private static function solutionTypes(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $types = [];
        foreach ($DB->request(['FROM' => 'glpi_solutiontypes', 'ORDER' => 'name']) as $row) {
            $types[(int) $row['id']] = $row['name'];
        }

        return $types;
    }

    /**
     * Active users (candidates for "author of the automatic followups and solutions").
     *
     * @return array<int, string>
     */
    private static function users(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $users = [];
        foreach ($DB->request(['FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'name', 'LIMIT' => 500]) as $row) {
            $users[(int) $row['id']] = ProjectAccess::userName((int) $row['id']);
        }

        return $users;
    }

    /**
     * Profiles of the central (technician) interface with their current plugin rights.
     *
     * @return array<int, array{id: int, name: string, value: int}>
     */
    public static function profiles(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $profiles = [];
        foreach ($DB->request(['FROM' => 'glpi_profiles', 'WHERE' => ['interface' => 'central'], 'ORDER' => 'name']) as $row) {
            $rights     = ProfileRight::getProfileRights((int) $row['id'], [Link::$rightname]);
            $profiles[] = ['id' => (int) $row['id'], 'name' => $row['name'], 'value' => (int) ($rights[Link::$rightname] ?? 0)];
        }

        return $profiles;
    }
}
