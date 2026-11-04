<?php

namespace GlpiPlugin\Azuredevops\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Azuredevops\AdoException;
use GlpiPlugin\Azuredevops\ConfigPage;
use GlpiPlugin\Azuredevops\ConfigTabs;
use GlpiPlugin\Azuredevops\Link;
use GlpiPlugin\Azuredevops\Project;
use GlpiPlugin\Azuredevops\ProjectAccess;
use GlpiPlugin\Azuredevops\ProjectPage;
use GlpiPlugin\Azuredevops\ProjectTabs;
use GlpiPlugin\Azuredevops\Settings;
use Html;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration pages (Setup > Plugins > Azure DevOps): GLPI's standard pages with the tabs on the left, one tab per
 * zone of settings (ConfigPage / ConfigTabs for the plugin, ProjectPage / ProjectTabs for each project). Every tab is a
 * form of its own and only saves the settings of its zone.
 * Everything here requires GLPI's Setup > Update right.
 */
final class ConfigController extends AbstractController
{
    /** Settings of each tab: what a form may change (anything else posted is ignored). */
    private const FIELDS = [
        'creation' => ['title_template', 'include_people', 'upload_attachments', 'max_attachment_mb', 'creation_followup',
            'creation_followup_private', 'notify_requester', 'notify_requester_text'],
        'closure'  => ['close_followup', 'close_followup_private', 'notify_requester_close_text', 'solutiontypes_id',
            'solution_template', 'actor_users_id'],
        'advanced' => ['api_base_url', 'entra_authority'],
    ];

    /** Check boxes (absent from the POST when unticked) and numbers of each tab. */
    private const FLAGS = [
        'creation' => ['include_people', 'upload_attachments', 'creation_followup', 'creation_followup_private', 'notify_requester'],
        'closure'  => ['close_followup', 'close_followup_private'],
    ];
    private const NUMBERS = [
        'creation' => ['max_attachment_mb'],
        'closure'  => ['solutiontypes_id', 'actor_users_id'],
    ];

    // ------------------------------------------------------------ the plugin

    #[Route('/Config', name: 'azuredevops_config', methods: ['GET'])]
    public function show(): Response
    {
        $this->checkRight();

        // Same rendering as GLPI's own pages with tabs.
        ob_start();
        Html::header(ConfigPage::getTypeName(), '', 'config', 'plugin');
        (new ConfigPage())->display(['id' => 0]);
        Html::footer();

        return new Response((string) ob_get_clean());
    }

    #[Route('/Config', name: 'azuredevops_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->checkRight();

        $tab = (string) $request->request->get('_tab', '');
        if (!isset(self::FIELDS[$tab])) {
            return $this->backTo('projects');
        }
        // Only the settings of this tab, whatever else was posted; and all of them valid, or nothing is saved.
        $posted = $request->request->all();
        $input  = [];
        foreach (self::FIELDS[$tab] as $name) {
            if (!array_key_exists($name, $posted)) {
                continue;
            }
            if (!is_scalar($posted[$name])) {
                return $this->invalid($tab);
            }
            $input[$name] = (string) $posted[$name];
        }
        foreach (self::NUMBERS[$tab] ?? [] as $number) {
            if (isset($input[$number]) && !preg_match('/^\d{0,9}$/', trim($input[$number]))) {
                return $this->invalid($tab);
            }
        }

        // Checkboxes are absent from the POST when unchecked.
        foreach (self::FLAGS[$tab] ?? [] as $flag) {
            $input[$flag] = !empty($input[$flag]) ? '1' : '0';
        }
        foreach (self::NUMBERS[$tab] ?? [] as $number) {
            $input[$number] = (string) max(0, (int) ($input[$number] ?? 0));
        }

        if ($tab === 'creation' && (int) $input['max_attachment_mb'] < 1) {
            $input['max_attachment_mb'] = '10';
        }

        if ($tab === 'closure') {
            // One choice for the requester at closure; stored in the two settings.
            $mode = $posted['requester_close_mode'] ?? null;
            if (!in_array($mode, ['none', 'followup', 'solve'], true)) {
                return $this->invalid($tab);
            }
            $input['close_solve_ticket']     = $mode === 'solve' ? '1' : '0';
            $input['notify_requester_close'] = $mode === 'followup' ? '1' : '0';
        }

        if ($tab === 'advanced') {
            foreach (['api_base_url', 'entra_authority'] as $url_setting) {
                if (!empty($input[$url_setting]) && !preg_match('#^https?://#i', $input[$url_setting])) {
                    Session::addMessageAfterRedirect(__('The base URL must start with http:// or https://', 'azuredevops'), false, ERROR);

                    return $this->backTo($tab);
                }
            }
        }

        Settings::save($input);
        Session::addMessageAfterRedirect(__('Settings saved.', 'azuredevops'));

        return $this->backTo($tab);
    }

    /**
     * Rights per profile, edited from the configuration page (same data as the "Azure DevOps"
     * tab of each profile). Needs both the "config" and the "profile" update rights.
     */
    #[Route('/Config/Rights', name: 'azuredevops_config_rights', methods: ['POST'])]
    public function saveRights(Request $request): Response
    {
        $this->checkRight();
        if (!Session::haveRight('profile', UPDATE)) {
            throw new AccessDeniedHttpException();
        }

        $ticked  = $request->request->all('rights');   // [profiles_id => [right, ...]]
        $central = array_column(ConfigTabs::profiles(), 'id');
        foreach (array_map('intval', $request->request->all('profiles')) as $profiles_id) {
            // Only the profiles that were displayed (central interface) can be changed.
            if (in_array($profiles_id, $central, true)) {
                Link::saveProfileRights($profiles_id, (array) ($ticked[$profiles_id] ?? []));
            }
        }
        Session::addMessageAfterRedirect(__('Rights updated.', 'azuredevops'));

        return $this->backTo('rights');
    }

    // ----------------------------------------------------------------- projects

    #[Route('/Config/Project', name: 'azuredevops_project_new', methods: ['GET'])]
    public function newProject(): Response
    {
        $this->checkRight();

        return $this->showProject(0);
    }

    #[Route('/Config/Project/{id}', name: 'azuredevops_project_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editProject(int $id): Response
    {
        $this->checkRight();
        if (Project::find($id) === null) {
            throw new NotFoundHttpException();
        }

        return $this->showProject($id);
    }

    /**
     * The page of a project: GLPI's standard page with one tab per zone of settings.
     */
    private function showProject(int $id): Response
    {
        $project = $id > 0 ? Project::find($id) : null;
        // The tabs read the project from the "id" of the request, as for any page of GLPI.
        $_GET['id'] = $id;

        ob_start();
        Html::header($project !== null ? (string) $project['name'] : __('New Azure DevOps project', 'azuredevops'), '', 'config', 'plugin');
        (new ProjectPage())->display(['id' => $id, 'show_nav_header' => false]);
        Html::footer();

        return new Response((string) ob_get_clean());
    }

    /**
     * Save the settings of one zone (one tab) of a project. A new project is created by the connection zone.
     */
    #[Route('/Config/Project', name: 'azuredevops_project_save', methods: ['POST'])]
    public function saveProject(Request $request): Response
    {
        $this->checkRight();
        $input = $request->request->all();
        $id    = (int) ($input['id'] ?? 0);
        $zone  = (string) ($input['_zone'] ?? '');

        try {
            $id = Project::saveZone($id, $zone, $input);
            Session::addMessageAfterRedirect(__('Project saved.', 'azuredevops'));

            if ($zone === 'connection' && isset($input['_test'])) {
                $this->testProject($id);
            }
        } catch (AdoException | \RuntimeException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
            if ($id <= 0) {
                return new RedirectResponse($this->url('/Config/Project'));
            }
        }

        return $this->backToProject($id, $zone);
    }

    /**
     * Read the types of the project, and the fields they require, again from Azure DevOps, without saving the form.
     */
    #[Route('/Config/Project/{id}/Refresh', name: 'azuredevops_project_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refreshProject(int $id): Response
    {
        $this->checkRight();
        if (Project::find($id) === null) {
            throw new NotFoundHttpException();
        }
        $this->testProject($id);

        return new RedirectResponse($this->url('/Config/Project/' . $id));
    }

    #[Route('/Config/Project/{id}/Delete', name: 'azuredevops_project_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deleteProject(int $id): Response
    {
        $this->checkRight();
        try {
            Project::remove($id);
            Session::addMessageAfterRedirect(__('Project deleted.', 'azuredevops'));
        } catch (\RuntimeException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);

            return new RedirectResponse($this->url('/Config/Project/' . $id));
        }

        return $this->backTo('projects');
    }

    private function testProject(int $id): void
    {
        try {
            $res = Project::testAndCache($id);
            Session::addMessageAfterRedirect(
                htmlspecialchars(sprintf(
                    __('Connection successful: project "%1$s"%2$s. %3$d work item types found.', 'azuredevops'),
                    $res['project'],
                    $res['user'] !== '' ? sprintf(__(', authenticated as %s', 'azuredevops'), $res['user']) : '',
                    count($res['types'])
                )),
                false,
                INFO
            );
        } catch (AdoException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }
    }

    /**
     * Existing tags of the project, to choose the default tags of the project.
     */
    #[Route('/Config/Project/{id}/Tags', name: 'azuredevops_project_tags', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function projectTags(Request $request, int $id): Response
    {
        $this->checkRight();
        $project = Project::find($id);
        if ($project === null) {
            throw new NotFoundHttpException();
        }

        try {
            $results = Project::tagResults($project, (string) $request->query->get('term', ''));
        } catch (AdoException $e) {
            return new JsonResponse(['results' => [], 'error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['results' => $results, 'pagination' => ['more' => false]]);
    }

    // ------------------------------------------------------------ access search

    #[Route('/Config/Search/Users', name: 'azuredevops_search_users', methods: ['GET'])]
    public function searchUsers(Request $request): Response
    {
        $this->checkRight();
        /** @var \DBmysql $DB */
        global $DB;

        $term  = trim((string) $request->query->get('term', ''));
        $page  = max(1, (int) $request->query->get('page', 1));
        $per   = 20;
        $where = ['is_active' => 1, 'is_deleted' => 0];
        if ($term !== '') {
            $like    = '%' . $term . '%';
            $where[] = ['OR' => ['name' => ['LIKE', $like], 'realname' => ['LIKE', $like], 'firstname' => ['LIKE', $like]]];
        }

        $results = [];
        foreach ($DB->request(['FROM' => 'glpi_users', 'WHERE' => $where, 'ORDER' => ['realname ASC', 'name ASC'], 'LIMIT' => $per + 1, 'START' => ($page - 1) * $per]) as $row) {
            $results[] = ['id' => (int) $row['id'], 'text' => ProjectAccess::userName((int) $row['id'])];
        }
        $more = count($results) > $per;

        return new JsonResponse(['results' => array_slice($results, 0, $per), 'pagination' => ['more' => $more]]);
    }

    #[Route('/Config/Search/Groups', name: 'azuredevops_search_groups', methods: ['GET'])]
    public function searchGroups(Request $request): Response
    {
        $this->checkRight();
        /** @var \DBmysql $DB */
        global $DB;

        $term  = trim((string) $request->query->get('term', ''));
        $page  = max(1, (int) $request->query->get('page', 1));
        $per   = 20;
        $where = [];
        if ($term !== '') {
            $where['completename'] = ['LIKE', '%' . $term . '%'];
        }

        $results = [];
        foreach ($DB->request(['FROM' => 'glpi_groups', 'WHERE' => $where, 'ORDER' => 'completename ASC', 'LIMIT' => $per + 1, 'START' => ($page - 1) * $per]) as $row) {
            $results[] = ['id' => (int) $row['id'], 'text' => (string) $row['completename']];
        }
        $more = count($results) > $per;

        return new JsonResponse(['results' => array_slice($results, 0, $per), 'pagination' => ['more' => $more]]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Refuse the whole form: a partly applied invalid form would silently overwrite good settings.
     */
    private function invalid(string $tab): Response
    {
        Session::addMessageAfterRedirect(__('Invalid form data. Nothing was saved.', 'azuredevops'), false, ERROR);

        return $this->backTo($tab);
    }

    private function checkRight(): void
    {
        if (!ConfigPage::canManage()) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * Back to the configuration page, on the tab that was just saved: GLPI reopens the tab remembered in the session.
     */
    private function backTo(string $tab): Response
    {
        $number = ConfigTabs::TAB_NAMES[$tab] ?? ConfigTabs::PROJECTS;
        Session::setActiveTab(ConfigPage::class, ConfigTabs::class . '$' . $number);

        return new RedirectResponse($this->url('/Config'));
    }

    /**
     * Back to the page of the project, on the tab of the zone that was just saved.
     */
    private function backToProject(int $id, string $zone): Response
    {
        $number = ProjectTabs::ZONE_TABS[$zone] ?? ProjectTabs::CONNECTION;
        Session::setActiveTab(ProjectPage::class, ProjectTabs::class . '$' . $number);

        return new RedirectResponse($this->url('/Config/Project/' . $id));
    }

    private function url(string $path): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/azuredevops' . $path;
    }
}
