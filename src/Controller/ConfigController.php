<?php

namespace GlpiPlugin\Azuredevops\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Azuredevops\AdoException;
use GlpiPlugin\Azuredevops\Link;
use GlpiPlugin\Azuredevops\Project;
use GlpiPlugin\Azuredevops\ProjectAccess;
use GlpiPlugin\Azuredevops\RequesterNotice;
use GlpiPlugin\Azuredevops\Settings;
use ProfileRight;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration pages (Setup > Plugins > Azure DevOps): global settings, the Azure DevOps projects (each with
 * its own token and the users / groups allowed to use it) and the rights per profile.
 * Everything here requires GLPI's Setup > Update right.
 */
final class ConfigController extends AbstractController
{
    // --------------------------------------------------------- global settings

    #[Route('/Config', name: 'azuredevops_config', methods: ['GET'])]
    public function show(): Response
    {
        $this->checkRight();
        $settings = Settings::all();

        $projects = [];
        foreach (Project::all() as $project) {
            $projects[] = [
                'id'           => (int) $project['id'],
                'name'         => $project['name'],
                'target'       => $project['organization'] . ' / ' . $project['ado_project'],
                'type'         => $project['workitem_type'],
                'auth'         => $project['auth_mode'] ?? 'pat',
                'is_active'    => (bool) $project['is_active'],
                'is_restricted' => (bool) $project['is_restricted'],
                'users'        => count(ProjectAccess::users((int) $project['id'])),
                'groups'       => array_values(ProjectAccess::groups((int) $project['id'])),
                'last_error'   => $project['last_error'],
                'links'        => countElementsInTable(Link::getTable(), ['projects_id' => (int) $project['id']]),
            ];
        }

        return $this->render('@azuredevops/config.html.twig', [
            'settings'  => $settings,
            'projects'  => $projects,
            'form_url'  => $this->url('/Config'),
            'new_url'   => $this->url('/Config/Project'),
            'project_url' => $this->url('/Config/Project'),
            'solution_types' => $this->solutionTypes(),
            'users'     => $this->users(),
            'close_mode' => $settings['close_solve_ticket'] === '1' ? 'solve' : ($settings['notify_requester_close'] === '1' ? 'followup' : 'none'),
            'default_notice_creation' => RequesterNotice::defaultText(RequesterNotice::CREATION),
            'default_notice_close' => RequesterNotice::defaultText(RequesterNotice::CLOSURE),
            'profiles'  => $this->profiles(),
            'right_labels' => (new Link())->getRights(),
            'can_edit_rights' => Session::haveRight('profile', UPDATE),
            'rights_url' => $this->url('/Config/Rights'),
        ]);
    }

    #[Route('/Config', name: 'azuredevops_config_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->checkRight();
        $input = $request->request->all();

        // Checkboxes are absent from the POST when unchecked.
        foreach (['include_people', 'upload_attachments', 'creation_followup', 'creation_followup_private', 'notify_requester', 'close_followup', 'close_followup_private'] as $flag) {
            $input[$flag] = !empty($input[$flag]) ? '1' : '0';
        }
        // One choice for the requester at closure; stored in the two settings.
        $mode = (string) ($input['requester_close_mode'] ?? 'none');
        $mode = in_array($mode, ['none', 'followup', 'solve'], true) ? $mode : 'none';
        $input['close_solve_ticket']     = $mode === 'solve' ? '1' : '0';
        $input['notify_requester_close'] = $mode === 'followup' ? '1' : '0';
        foreach (['max_attachment_mb', 'solutiontypes_id', 'actor_users_id'] as $number) {
            $input[$number] = (string) max(0, (int) ($input[$number] ?? 0));
        }
        if ((int) $input['max_attachment_mb'] < 1) {
            $input['max_attachment_mb'] = '10';
        }
        foreach (['api_base_url', 'entra_authority'] as $url_setting) {
            if (!empty($input[$url_setting]) && !preg_match('#^https?://#i', (string) $input[$url_setting])) {
                Session::addMessageAfterRedirect(__('The base URL must start with http:// or https://', 'azuredevops'), false, ERROR);

                return new RedirectResponse($this->url('/Config'));
            }
        }

        Settings::save($input);
        Session::addMessageAfterRedirect(__('Settings saved.', 'azuredevops'));

        return new RedirectResponse($this->url('/Config'));
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
        $central = array_column($this->profiles(), 'id');
        foreach (array_map('intval', $request->request->all('profiles')) as $profiles_id) {
            // Only the profiles that were displayed (central interface) can be changed.
            if (in_array($profiles_id, $central, true)) {
                Link::saveProfileRights($profiles_id, (array) ($ticked[$profiles_id] ?? []));
            }
        }
        Session::addMessageAfterRedirect(__('Rights updated.', 'azuredevops'));

        return new RedirectResponse($this->url('/Config'));
    }

    // ----------------------------------------------------------------- projects

    #[Route('/Config/Project', name: 'azuredevops_project_new', methods: ['GET'])]
    public function newProject(): Response
    {
        $this->checkRight();

        return $this->renderProject(null);
    }

    #[Route('/Config/Project/{id}', name: 'azuredevops_project_edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function editProject(int $id): Response
    {
        $this->checkRight();
        $project = Project::find($id);
        if ($project === null) {
            throw new NotFoundHttpException();
        }

        return $this->renderProject($project);
    }

    #[Route('/Config/Project', name: 'azuredevops_project_save', methods: ['POST'])]
    public function saveProject(Request $request): Response
    {
        $this->checkRight();
        $input = $request->request->all();
        $id    = (int) ($input['id'] ?? 0);

        try {
            $id = Project::saveFromInput($input, $id);

            // Users and groups allowed to use the project (they are listed in the same form).
            ProjectAccess::set($id, (array) ($input['users'] ?? []), (array) ($input['groups'] ?? []));
            Session::addMessageAfterRedirect(__('Project saved.', 'azuredevops'));

            if (isset($input['_test'])) {
                $this->testProject($id);
            }
        } catch (AdoException | \RuntimeException $e) {
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
            if ($id <= 0) {
                return new RedirectResponse($this->url('/Config/Project'));
            }
        }

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

        return new RedirectResponse($this->url('/Config'));
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
     * @param array<string, mixed>|null $project
     */
    private function renderProject(?array $project): Response
    {
        $defaults = [
            'id' => 0, 'name' => '', 'organization' => '', 'ado_project' => '', 'is_active' => 1, 'is_restricted' => 1,
            'workitem_type' => '', 'parent_types' => 'Epic,Feature', 'followup_only_types' => 'Epic,Feature',
            'done_categories' => 'Completed', 'area_path' => '', 'default_tags' => 'glpi', 'last_error' => null,
            'auth_mode' => 'pat', 'tenant_id' => '', 'client_id' => '',
        ];
        $row = ($project ?? []) + $defaults;
        $id  = (int) $row['id'];
        $has_token  = $project !== null && ($project['token'] ?? '') !== '';
        $has_secret = $project !== null && ($project['client_secret'] ?? '') !== '';
        // Secrets are never sent back to the browser.
        unset($row['token'], $row['client_secret'], $row['access_token'], $row['types_cache']);

        return $this->render('@azuredevops/project.html.twig', [
            'project'   => $row,
            'is_new'    => $project === null,
            'has_token' => $has_token,
            'has_secret' => $has_secret,
            'types'     => $project !== null ? Project::typeNames($project) : [],
            'parent_types'  => Project::csv($row['parent_types']),
            'followup_only_types' => Project::csv($row['followup_only_types']),
            'done_categories' => Project::csv($row['done_categories']),
            'access_users'  => $id > 0 ? ProjectAccess::users($id) : [],
            'access_groups' => $id > 0 ? ProjectAccess::groups($id) : [],
            'links'     => $id > 0 ? countElementsInTable(Link::getTable(), ['projects_id' => $id]) : 0,
            'form_url'  => $this->url('/Config/Project'),
            'delete_url' => $id > 0 ? $this->url('/Config/Project/' . $id . '/Delete') : '',
            'back_url'  => $this->url('/Config'),
            'search_users_url'  => $this->url('/Config/Search/Users'),
            'search_groups_url' => $this->url('/Config/Search/Groups'),
        ]);
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
     * Profiles of the central (technician) interface with their current plugin rights.
     *
     * @return array<int, array{id: int, name: string, value: int}>
     */
    private function profiles(): array
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

    private function checkRight(): void
    {
        if (!Session::haveRight('config', UPDATE)) {
            throw new AccessDeniedHttpException();
        }
    }

    private function url(string $path): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($CFG_GLPI['root_doc'] ?? '') . '/plugins/azuredevops' . $path;
    }

    /**
     * @return array<int, string>
     */
    private function solutionTypes(): array
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
    private function users(): array
    {
        /** @var \DBmysql $DB */
        global $DB;
        $users = [];
        foreach ($DB->request(['FROM' => 'glpi_users', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0], 'ORDER' => 'name', 'LIMIT' => 500]) as $row) {
            $users[(int) $row['id']] = ProjectAccess::userName((int) $row['id']);
        }

        return $users;
    }
}
