<?php

namespace GlpiPlugin\Azuredevops;

use CommonDBTM;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Html;
use ITILFollowup;
use ProfileRight;
use RuntimeException;
use Session;
use Ticket;
use Toolbox;

/**
 * Link between a GLPI ticket and an Azure DevOps work item (User Story, Issue, PBI, Epic...).
 *
 * One row per work item linked to a ticket: a ticket can have several work items, and the same work item
 * (an Epic, typically) can be linked to several tickets. The row also carries what the automatic action
 * needs to follow the work item (state, last check, error counter) and a copy of its parent chain
 * (Epic > Feature > ...) for display.
 */
class Link extends CommonDBTM
{
    public static $rightname = 'plugin_azuredevops_workitem';

    public $dohistory = false;

    public const STATE_OPENED  = 'opened';
    public const STATE_CLOSED  = 'closed';   // reached a "done" state category
    public const STATE_MISSING = 'missing';  // deleted or no longer reachable with the token
    public const STATE_MOVED   = 'moved';    // moved to another Azure DevOps project: cannot be followed
    public const STATE_REMOVED = 'removed';  // "Removed" state category: dropped from the backlog

    // ---------------------------------------------------------------- rights

    /**
     * Store the plugin rights of a profile from the ticked rights (values of getRights()).
     * READ is added whenever something is ticked: the other rights are useless without it.
     *
     * @param array<int, int|string> $ticked
     */
    public static function saveProfileRights(int $profiles_id, array $ticked): void
    {
        $allowed = array_keys((new self())->getRights());
        $value   = 0;
        foreach ($ticked as $right) {
            if (in_array((int) $right, $allowed, true)) {
                $value |= (int) $right;
            }
        }
        if ($value > 0) {
            $value |= READ;
        }
        ProfileRight::updateProfileRights($profiles_id, [self::$rightname => $value]);
    }

    // Every check made by the plugin goes through these four methods (tab, controllers).
    // - The plugin is for technicians: profiles of the self-service interface never pass,
    //   even if a right was set for them in the database.
    // - READ is required for everything else: "create" or "unlink" without "read" gives nothing.
    // - On top of these rights, a technician only works with the projects he is allowed to use
    //   (see ProjectAccess).

    public static function canView(): bool
    {
        return Session::getCurrentInterface() === 'central' && parent::canView();
    }

    public static function canCreate(): bool
    {
        return self::canView() && parent::canCreate();
    }

    public static function canUpdate(): bool
    {
        return self::canView() && parent::canUpdate();
    }

    public static function canDelete(): bool
    {
        return self::canView() && parent::canDelete();
    }

    public static function getTypeName($nb = 0): string
    {
        return _n('Azure DevOps work item', 'Azure DevOps work items', $nb, 'azuredevops');
    }

    public static function getIcon(): string
    {
        return 'ti ti-brand-azure';
    }

    public function getRights($interface = 'central'): array
    {
        return [
            READ   => __('Read'),
            CREATE => __('Create or link a work item', 'azuredevops'),
            UPDATE => __('Refresh the status', 'azuredevops'),
            DELETE => __('Unlink', 'azuredevops'),
        ];
    }

    public static function getStateLabel(string $state): string
    {
        return match ($state) {
            self::STATE_OPENED  => __('Open', 'azuredevops'),
            self::STATE_CLOSED  => __('Done', 'azuredevops'),
            self::STATE_MISSING => __('Not found on Azure DevOps', 'azuredevops'),
            self::STATE_MOVED   => __('Moved to another project', 'azuredevops'),
            self::STATE_REMOVED => __('Removed from the backlog', 'azuredevops'),
            default             => $state,
        };
    }

    /**
     * May the user see and act on this link? (he must be allowed to use the project of the work item)
     *
     * @param array<string, mixed> $link
     */
    public static function canUseProjectOf(array $link, ?int $users_id = null): bool
    {
        $project = Project::find((int) $link['projects_id']);

        return $project !== null && ProjectAccess::canUse($project, $users_id ?? (int) Session::getLoginUserID());
    }

    // --------------------------------------------------------------------- tab

    /**
     * @return array<int, array<string, mixed>> links of the ticket on projects the user may use
     */
    public static function visibleLinks(int $tickets_id, int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $projects = Project::all();
        $links    = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $tickets_id], 'ORDER' => 'id ASC']) as $row) {
            $project = $projects[(int) $row['projects_id']] ?? null;
            if ($project !== null && ProjectAccess::canUse($project, $users_id)) {
                $row['project_name'] = $project['name'];
                $links[] = $row;
            }
        }

        return $links;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if ($item instanceof Ticket && self::canView()) {
            $count = 0;
            if (!empty($_SESSION['glpishow_count_on_tabs'])) {
                $count = count(self::visibleLinks($item->getID(), (int) Session::getLoginUserID()));
            }

            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count, $item::class);
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof Ticket) {
            self::showForTicket($item);

            return true;
        }

        return false;
    }

    public static function showForTicket(Ticket $ticket): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        if (!self::canView() || !$ticket->canViewItem()) {
            return;
        }

        $users_id = (int) Session::getLoginUserID();
        $links    = [];
        $visible  = self::visibleLinks($ticket->getID(), $users_id);
        foreach ($visible as $row) {
            $row['state_label']     = self::getStateLabel($row['state']);
            $row['date_closed']     = $row['date_closed'] ? Html::convDateTime($row['date_closed']) : '';
            $row['date_last_check'] = $row['date_last_check'] ? Html::convDateTime($row['date_last_check']) : '';
            $row['chain']           = self::chainOf($row);
            $links[] = $row;
        }
        $hidden = countElementsInTable(self::getTable(), ['tickets_id' => $ticket->getID()]) - count($visible);

        $projects          = [];
        $fields_by_project = [];
        foreach (ProjectAccess::projectsForUser($users_id) as $project) {
            $fields_by_project[(int) $project['id']] = ItemBuilder::formFields($project, $ticket);
            $projects[] = [
                'id'            => (int) $project['id'],
                'name'          => $project['name'],
                'type'          => $project['workitem_type'],
                'has_parents'   => Project::csv($project['parent_types']) !== [],
                'parent_types'  => implode(', ', Project::csv($project['parent_types'])),
                'ready'         => $project['workitem_type'] !== '',
            ];
        }

        TemplateRenderer::getInstance()->display('@azuredevops/ticket_tab.html.twig', [
            'ticket_id'        => $ticket->getID(),
            'links'            => $links,
            'hidden_count'     => max(0, $hidden),
            'projects'         => $projects,
            'fields_by_project' => $fields_by_project,
            'any_project'      => Project::all(true) !== [],
            'can_create'       => $projects !== [] && self::canCreate(),
            'can_update'       => self::canUpdate(),
            'can_delete'       => self::canDelete(),
            'can_config'       => Session::haveRight('config', UPDATE),
            'default_followup' => self::defaultFollowupMode(),
            'default_notify'   => Settings::get('notify_requester') === '1',
            'notice_text'      => RequesterNotice::text(RequesterNotice::CREATION),
            'base'             => ($CFG_GLPI['root_doc'] ?? '') . '/plugins/azuredevops',
        ]);
    }

    /**
     * Parent chain stored in the link (root first): [{id, type, title, url}, ...]
     *
     * @param array<string, mixed> $row
     *
     * @return array<int, array{id: int, type: string, title: string, url: string}>
     */
    public static function chainOf(array $row): array
    {
        $chain = json_decode((string) ($row['parent_chain'] ?? ''), true);

        return is_array($chain) ? $chain : [];
    }

    // ---------------------------------------------------------------- creation

    /**
     * @return array<string, mixed> the project row, when the user may use it
     *
     * @throws RuntimeException
     */
    private static function usableProject(int $projects_id, int $users_id): array
    {
        $project = Project::find($projects_id);
        if ($project === null || empty($project['is_active'])) {
            throw new RuntimeException(__('This project is not available.', 'azuredevops'));
        }
        if (!ProjectAccess::canUse($project, $users_id)) {
            throw new RuntimeException(__('You are not allowed to use this project.', 'azuredevops'));
        }

        return $project;
    }

    /**
     * Create the work item for a ticket and store the link.
     *
     * @param int         $parent_id  Epic / Feature / ... to create the work item under (0 = none)
     * @param string|null $followup   'private', 'public' or 'none'; null = use the plugin settings
     * @param bool|null   $notify_requester add the neutral public message for the requester; null = use the plugin settings
     * @param array<string, mixed> $fields values chosen for the fields the process requires (reference name => value)
     *
     * @return array{link: Link, warnings: string[]}
     *
     * @throws AdoException|RuntimeException
     */
    public static function createFromTicket(
        Ticket $ticket,
        int $projects_id,
        int $users_id = 0,
        int $parent_id = 0,
        string $extra_tags = '',
        bool $allow_duplicate = false,
        ?string $followup = null,
        ?bool $notify_requester = null,
        array $fields = [],
    ): array {
        /** @var \DBmysql $DB */
        global $DB;

        $project = self::usableProject($projects_id, $users_id);
        if (trim((string) $project['workitem_type']) === '') {
            throw new RuntimeException(__('No work item type is configured for this project.', 'azuredevops'));
        }
        // Values chosen in the form for the fields the process requires: checked before anything is sent.
        $chosen_fields = ItemBuilder::validateFields($project, $fields);

        // Serialise concurrent creations for the same ticket (double click, two technicians).
        $lock = 'azuredevops_ticket_' . $ticket->getID();
        $got  = $DB->doQuery("SELECT GET_LOCK('" . $DB->escape($lock) . "', 10) AS acquired")->fetch_assoc();
        if (!$got || (int) $got['acquired'] !== 1) {
            throw new RuntimeException(__('Another creation is in progress for this ticket, try again in a moment.', 'azuredevops'));
        }

        try {
            if (
                !$allow_duplicate
                && countElementsInTable(self::getTable(), [
                    'tickets_id'  => $ticket->getID(),
                    'projects_id' => $projects_id,
                    'is_created'  => 1,
                    'state'       => self::STATE_OPENED,
                ]) > 0
            ) {
                throw new RuntimeException(__('This ticket already has an open work item created in this project.', 'azuredevops'));
            }

            $client = Project::client($project);

            // The parent is checked before anything is uploaded or created.
            if ($parent_id > 0) {
                $parent = $client->getWorkItems([$parent_id])[$parent_id] ?? null;
                if ($parent === null) {
                    throw new RuntimeException(sprintf(__('The parent work item #%d was not found.', 'azuredevops'), $parent_id));
                }
                if (strcasecmp($parent['project'], (string) $project['ado_project']) !== 0) {
                    throw new RuntimeException(sprintf(__('The parent work item #%d belongs to another Azure DevOps project.', 'azuredevops'), $parent_id));
                }
                if (!Project::inList($parent['type'], $project['parent_types'])) {
                    throw new RuntimeException(sprintf(__('A work item of type "%s" cannot be used as a parent in this project.', 'azuredevops'), $parent['type']));
                }
            }

            $payload = (new ItemBuilder($client))->build($ticket, $project, $parent_id, $extra_tags, $users_id, $chosen_fields);
            $item    = $client->createWorkItem($payload['type'], $payload['fields'], $payload['relations']);

            $chain = [];
            if ($parent_id > 0) {
                try {
                    $chain = $client->parentChain($parent_id);
                } catch (AdoException) {
                    $chain = []; // display only: refreshed by the next check
                }
            }

            $link = self::store($ticket->getID(), $users_id, $project, $item, $chain, true, $payload['fields']['System.Title']);
        } finally {
            $DB->doQuery("SELECT RELEASE_LOCK('" . $DB->escape($lock) . "')");
        }

        $mode = in_array($followup, ['private', 'public', 'none'], true) ? $followup : self::defaultFollowupMode();
        if ($mode !== 'none') {
            self::addTraceFollowup($ticket, $link, $users_id, $mode === 'private', __('Azure DevOps work item created: %s', 'azuredevops'));
        }

        if ($notify_requester ?? (Settings::get('notify_requester') === '1')) {
            // Neutral public followup: the requester learns that a technician forwarded his request.
            Sync::addFollowup($ticket->getID(), RequesterNotice::html(RequesterNotice::CREATION), false, $users_id);
        }

        return ['link' => $link, 'warnings' => $payload['warnings']];
    }

    /**
     * Link an existing work item (an Epic, a User Story already in the backlog...) to a ticket.
     *
     * @return array{link: Link, warnings: string[]}
     *
     * @throws AdoException|RuntimeException
     */
    public static function linkExisting(Ticket $ticket, int $projects_id, int $workitem_id, int $users_id = 0, ?string $followup = null): array
    {
        $project = self::usableProject($projects_id, $users_id);
        if ($workitem_id <= 0) {
            throw new RuntimeException(__('Select a work item.', 'azuredevops'));
        }
        if (countElementsInTable(self::getTable(), ['tickets_id' => $ticket->getID(), 'projects_id' => $projects_id, 'workitem_id' => $workitem_id]) > 0) {
            throw new RuntimeException(__('This work item is already linked to the ticket.', 'azuredevops'));
        }

        $client = Project::client($project);
        $item   = $client->getWorkItems([$workitem_id])[$workitem_id] ?? null;
        if ($item === null) {
            throw new RuntimeException(sprintf(__('The work item #%d was not found (or the token of the project cannot read it).', 'azuredevops'), $workitem_id));
        }
        if (strcasecmp($item['project'], (string) $project['ado_project']) !== 0) {
            throw new RuntimeException(sprintf(__('The work item #%d belongs to another Azure DevOps project.', 'azuredevops'), $workitem_id));
        }

        $warnings = [];
        $category = Project::stateCategory($project, $item['type'], $item['state']);
        $state    = self::STATE_OPENED;
        if ($category === Project::CAT_REMOVED) {
            throw new RuntimeException(__('This work item is in a "Removed" state.', 'azuredevops'));
        }
        if ($category !== null && in_array($category, Project::csv($project['done_categories']), true)) {
            // Already done: nothing to wait for, and no action on the ticket (it was not waiting for it).
            $state      = self::STATE_CLOSED;
            $warnings[] = __('This work item is already done: it is linked for reference only and will not be followed.', 'azuredevops');
        }

        $chain = [];
        if ($item['parent'] > 0) {
            try {
                $chain = $client->parentChain($item['parent']);
            } catch (AdoException) {
                $chain = [];
            }
        }

        $link = self::store($ticket->getID(), $users_id, $project, $item, $chain, false, $item['title'], $state, $category);

        $mode = in_array($followup, ['private', 'public', 'none'], true) ? $followup : self::defaultFollowupMode();
        if ($mode !== 'none') {
            self::addTraceFollowup($ticket, $link, $users_id, $mode === 'private', __('Azure DevOps work item linked: %s', 'azuredevops'));
        }

        return ['link' => $link, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $item    normalized work item
     * @param array<int, array<string, mixed>> $chain
     */
    private static function store(
        int $tickets_id,
        int $users_id,
        array $project,
        array $item,
        array $chain,
        bool $is_created,
        string $title,
        string $state = self::STATE_OPENED,
        ?string $category = null,
    ): self {
        $link = new self();
        $id   = false;
        try {
            $id = $link->add([
                'tickets_id'     => $tickets_id,
                'users_id'       => $users_id,
                'projects_id'    => (int) $project['id'],
                'workitem_id'    => $item['id'],
                'workitem_type'  => $item['type'],
                'title'          => mb_substr($title !== '' ? $title : $item['title'], 0, 255),
                'url'            => $item['url'],
                'ado_project'    => $item['project'] !== '' ? $item['project'] : $project['ado_project'],
                'ado_state'      => $item['state'],
                'state_category' => $category,
                'parent_id'      => $item['parent'],
                'parent_chain'   => $chain !== [] ? json_encode($chain, JSON_UNESCAPED_UNICODE) : null,
                'is_created'     => $is_created ? 1 : 0,
                'state'          => $state,
                'date_closed'    => $state === self::STATE_CLOSED ? date('Y-m-d H:i:s') : null,
            ]);
        } catch (\Throwable $e) {
            // GLPI 11 throws on SQL errors. Whatever happens, the user must learn where the work item is.
            Toolbox::logInFile('azuredevops', 'Link not saved: ' . $e->getMessage() . "\n");
        }
        if ($id === false) {
            Toolbox::logInFile('azuredevops', sprintf("Work item #%d on Azure DevOps but link not saved (ticket %d): %s\n", $item['id'], $tickets_id, $item['url']));
            throw new RuntimeException(sprintf(
                __('The work item exists on Azure DevOps (%s) but could not be linked to the ticket.', 'azuredevops'),
                $item['url']
            ));
        }

        return $link;
    }

    /**
     * Followup mode used when the user does not choose one: 'private', 'public' or 'none'.
     */
    public static function defaultFollowupMode(): string
    {
        $settings = Settings::all();
        if ($settings['creation_followup'] !== '1') {
            return 'none';
        }

        return $settings['creation_followup_private'] === '1' ? 'private' : 'public';
    }

    private static function addTraceFollowup(Ticket $ticket, self $link, int $users_id, bool $private, string $format): void
    {
        $url   = htmlspecialchars((string) $link->fields['url'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars(self::label($link->fields), ENT_QUOTES, 'UTF-8');

        try {
            (new ITILFollowup())->add([
                'itemtype'   => 'Ticket',
                'items_id'   => $ticket->getID(),
                'content'    => '<p>' . sprintf($format, '<a href="' . $url . '">' . $label . '</a>') . '</p>',
                'is_private' => $private ? 1 : 0,
                'users_id'   => $users_id,
            ]);
        } catch (\Throwable $e) {
            // Traceability only: never fail the creation because of it.
            Toolbox::logInFile('azuredevops', 'Trace followup failed: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * "Type #123 – Title" for messages.
     *
     * @param array<string, mixed> $row
     */
    public static function label(array $row): string
    {
        $type = (string) ($row['workitem_type'] ?? '');

        return trim(($type !== '' ? $type . ' ' : '') . '#' . $row['workitem_id']);
    }

    // ------------------------------------------------------------------- misc

    /**
     * Links of the ticket still waiting for something that should solve it (work items that are neither
     * done nor of a "followup only" type such as Epics).
     */
    public static function countOpenActionable(int $tickets_id): int
    {
        /** @var \DBmysql $DB */
        global $DB;

        $projects = Project::all();
        $count    = 0;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['tickets_id' => $tickets_id, 'state' => self::STATE_OPENED]]) as $row) {
            $project = $projects[(int) $row['projects_id']] ?? null;
            if ($project === null || !Project::isFollowupOnlyType($project, $row['workitem_type'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * item_purge hook: remove the links of a purged ticket.
     */
    public static function cleanForTicket(Ticket $ticket): void
    {
        (new self())->deleteByCriteria(['tickets_id' => $ticket->getID()], true);
    }
}
