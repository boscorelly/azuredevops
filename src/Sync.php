<?php

namespace GlpiPlugin\Azuredevops;

use CronTask;
use ITILFollowup;
use ITILSolution;
use Session;
use Throwable;
use Ticket;
use Toolbox;

/**
 * Automatic action "CheckWorkItems": polls Azure DevOps for the work items linked to tickets and, when
 * one reaches a "done" state, runs the configured actions on the ticket (followup, solution).
 *
 * Tickets are always modified through GLPI's own objects (ITILFollowup, ITILSolution), so history,
 * business rules, notifications and the other automatic actions (auto-close of solved tickets,
 * satisfaction surveys...) behave as for a manual action.
 *
 * Every project has its own token: a problem with one project (expired token...) is recorded for that
 * project and the others are still checked.
 */
final class Sync
{
    public const DEFAULT_BATCH_SIZE = 200;

    /** Consecutive "not found" answers before a work item is declared missing. */
    private const MAX_NOT_FOUND = 3;

    /** @var array<int, bool> projects whose types/states were re-read during this run */
    private static array $types_refreshed = [];

    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'CheckWorkItems' => [
                'description' => __('Check the Azure DevOps work items linked to tickets', 'azuredevops'),
                'parameter'   => __('Maximum number of work items checked per run', 'azuredevops'),
            ],
            default => [],
        };
    }

    /**
     * Entry point called by GLPI's cron.
     *
     * @return int >0 something was processed, 0 nothing to do, <0 partially processed
     */
    public static function cronCheckWorkItems(CronTask $task): int
    {
        $limit = (int) ($task->fields['param'] ?? 0);
        $stats = self::run($limit > 0 ? $limit : self::DEFAULT_BATCH_SIZE, $task);

        $task->addVolume($stats['checked']);
        $task->log(sprintf(
            'checked=%d closed=%d moved=%d removed=%d missing=%d errors=%d',
            $stats['checked'],
            $stats['closed'],
            $stats['moved'],
            $stats['removed'],
            $stats['missing'],
            $stats['errors']
        ));

        if ($stats['aborted']) {
            return -1;
        }

        return $stats['checked'] > 0 ? 1 : 0;
    }

    /**
     * @return array{checked: int, closed: int, moved: int, removed: int, missing: int, errors: int, aborted: bool}
     */
    public static function run(int $limit = self::DEFAULT_BATCH_SIZE, ?CronTask $task = null): array
    {
        return self::asSystem(static fn(): array => self::runBatch($limit, $task));
    }

    /**
     * @return array{checked: int, closed: int, moved: int, removed: int, missing: int, errors: int, aborted: bool}
     */
    private static function newStats(): array
    {
        return ['checked' => 0, 'closed' => 0, 'moved' => 0, 'removed' => 0, 'missing' => 0, 'errors' => 0, 'aborted' => false];
    }

    /**
     * @return array{checked: int, closed: int, moved: int, removed: int, missing: int, errors: int, aborted: bool}
     */
    private static function runBatch(int $limit, ?CronTask $task): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $stats = self::newStats();
        self::$types_refreshed = [];

        // Oldest checked first, never-checked first: every work item gets its turn even if
        // there are more open ones than the batch size.
        $rows = $DB->request([
            'FROM'  => Link::getTable(),
            'WHERE' => ['state' => Link::STATE_OPENED],
            'ORDER' => ['date_last_check ASC', 'id ASC'],
            'LIMIT' => max(1, $limit),
        ]);

        $by_project = [];
        foreach ($rows as $row) {
            $by_project[(int) $row['projects_id']][] = $row;
        }
        if ($by_project === []) {
            return $stats;
        }

        $projects = Project::all();

        foreach ($by_project as $projects_id => $links) {
            $project = $projects[$projects_id] ?? null;
            if ($project === null || empty($project['is_active'])) {
                continue; // deactivated project: its work items are not followed meanwhile
            }

            try {
                $client = Project::client($project);
                $items  = $client->getWorkItems(array_column($links, 'workitem_id'));
            } catch (AdoException $e) {
                if ($e->isGlobal()) {
                    // Token refused or expired, Azure DevOps unreachable...: this project is retried at the next run,
                    // the other projects (other tokens) are still checked.
                    self::recordProjectError($projects_id, $e->getMessage());
                    self::recordGlobalError($links, $e);
                    self::logError($task, sprintf('[%s] %s', $project['name'], $e->getMessage()));
                    $stats['errors'] += count($links);
                    if ($e->getKind() === AdoException::RATE_LIMIT) {
                        $stats['aborted'] = true;
                        break;
                    }
                    continue;
                }
                // Request rejected (project renamed...): treat each link as "not found".
                foreach ($links as $row) {
                    $stats['checked']++;
                    self::handleNotFound($row, $e->getMessage(), $stats);
                }
                continue;
            }
            self::recordProjectError($projects_id, null);

            foreach ($links as $row) {
                $stats['checked']++;
                $item = $items[(int) $row['workitem_id']] ?? null;

                try {
                    if ($item === null) {
                        // Not in the batch answer: ask for it individually to tell "deleted" from "hidden".
                        $item = $client->getWorkItem((int) $row['workitem_id']);
                    }
                    self::handleItem($row, $item, $project, $client, $stats);
                } catch (AdoException $e) {
                    if ($e->getKind() === AdoException::NOT_FOUND) {
                        self::handleNotFound($row, $e->getMessage(), $stats);
                        continue;
                    }
                    self::recordGlobalError([$row], $e);
                    self::logError($task, sprintf('[%s] %s', $project['name'], $e->getMessage()));
                    $stats['errors']++;
                    if ($e->getKind() === AdoException::RATE_LIMIT) {
                        $stats['aborted'] = true;
                        break 2;
                    }
                    if ($e->isGlobal()) {
                        continue 2; // next project
                    }
                } catch (Throwable $e) {
                    // A problem with one ticket must not block the others.
                    self::recordFailure($row, $e->getMessage());
                    self::logError($task, sprintf('Link #%d: %s', $row['id'], $e->getMessage()));
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Check a single link on demand (the "Refresh" button). The parent chain is rebuilt too.
     *
     * @return string Human readable result
     */
    public static function refresh(Link $link): string
    {
        $row     = $link->fields;
        $project = Project::find((int) $row['projects_id']);
        if ($project === null) {
            throw new \RuntimeException(__('This project is not available.', 'azuredevops'));
        }
        if ($row['state'] !== Link::STATE_OPENED) {
            return Link::getStateLabel($row['state']);
        }

        $client = Project::client($project);
        $stats  = self::newStats();
        $stats['checked'] = 1;

        // Same context as the automatic action, whoever clicked: the actions on the ticket must not
        // depend on the rights of the technician who pressed "Refresh".
        self::asSystem(static function () use ($client, $row, $project, &$stats): void {
            self::$types_refreshed = [];
            try {
                $item = $client->getWorkItem((int) $row['workitem_id']);
                self::handleItem($row, $item, $project, $client, $stats, true);
            } catch (AdoException $e) {
                if ($e->getKind() !== AdoException::NOT_FOUND) {
                    throw $e;
                }
                self::handleNotFound($row, $e->getMessage(), $stats);
            }
        });

        $link->getFromDB((int) $row['id']);

        return Link::getStateLabel($link->fields['state']);
    }

    /**
     * Run $fn like GLPI's automatic actions do: no logged-in user, full entity access. Followups and
     * solutions are then added with the author chosen in the settings, whatever the rights of the
     * person who triggered the check. The user's session is restored afterwards (flash messages kept).
     *
     * @template T
     * @param callable(): T $fn
     *
     * @return T
     */
    private static function asSystem(callable $fn): mixed
    {
        if (Session::isCron()) {
            return $fn();
        }

        $saved = $_SESSION;
        try {
            unset($_SESSION['glpiID'], $_SESSION['glpiactiveprofile']);
            $_SESSION['glpicronuserrunning'] = 'cron_CheckWorkItems';
            Session::loadEntity(0, true);
            $_SESSION['glpigroups'] = [];
            $_SESSION['glpiname']   = 'cron';

            return $fn();
        } finally {
            $messages = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
            $_SESSION = $saved;
            foreach ($messages as $level => $list) {
                foreach ((array) $list as $message) {
                    $_SESSION['MESSAGE_AFTER_REDIRECT'][$level][] = $message;
                }
            }
        }
    }

    // ---------------------------------------------------------------- handlers

    /**
     * @param array<string, mixed> $row     glpi_plugin_azuredevops_links row
     * @param array<string, mixed> $item    normalized work item
     * @param array<string, mixed> $project project row
     * @param array<string, int|bool> $stats
     */
    private static function handleItem(array $row, array $item, array $project, AdoClient $client, array &$stats, bool $rebuild_chain = false): void
    {
        $link = new Link();
        $link->getFromDB((int) $row['id']);

        $category = self::categoryOf($project, $item);

        $common = [
            'id'              => (int) $row['id'],
            'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'error_count'     => 0,
            'last_error'      => null,
            'ado_state'       => mb_substr($item['state'], 0, 128),
            'state_category'  => $category,
        ];
        // Kept in sync: displayed in the ticket tab.
        if ($item['title'] !== '') {
            $common['title'] = mb_substr($item['title'], 0, 255);
        }
        if ($item['type'] !== '') {
            $common['workitem_type'] = mb_substr($item['type'], 0, 128);
        }
        if ($item['project'] !== '') {
            $common['ado_project'] = mb_substr($item['project'], 0, 255);
        }
        if ($rebuild_chain || (int) $item['parent'] !== (int) $row['parent_id']) {
            $common['parent_id'] = (int) $item['parent'];
            try {
                $chain = $item['parent'] > 0 ? $client->parentChain((int) $item['parent']) : [];
                $common['parent_chain'] = $chain !== [] ? json_encode($chain, JSON_UNESCAPED_UNICODE) : null;
            } catch (AdoException) {
                unset($common['parent_id']); // retried at the next check
            }
        }

        // Moved to another Azure DevOps project: it was not done, and it can no longer be followed with this token.
        if ($item['project'] !== '' && strcasecmp($item['project'], (string) $project['ado_project']) !== 0) {
            $link->update($common + ['state' => Link::STATE_MOVED]);
            self::addFollowup((int) $row['tickets_id'], self::movedMessage($row, $item['project']), true, (int) $row['users_id']);
            $stats['moved']++;

            return;
        }

        if ($category === Project::CAT_REMOVED) {
            $link->update($common + ['state' => Link::STATE_REMOVED]);
            self::addFollowup((int) $row['tickets_id'], self::removedMessage($row), true, (int) $row['users_id']);
            $stats['removed']++;

            return;
        }

        if ($category !== null && in_array($category, Project::csv($project['done_categories']), true)) {
            self::processClosure($link, $item, $project, $client, $common);
            $stats['closed']++;

            return;
        }

        $link->update($common);
    }

    /**
     * The work item is done: run the configured actions on the ticket, atomically with the
     * state change, so a failure leaves the link "opened" and the whole thing is retried.
     *
     * Work items of a "followup only" type (Epics, Features...) only add a private followup: they never solve
     * the ticket. For the other ones, the ticket is solved (and the requester informed) only when no other
     * work item of the ticket is still open.
     *
     * @param array<string, mixed> $common fields to store with the new state
     */
    private static function processClosure(Link $link, array $item, array $project, AdoClient $client, array $common): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $row           = $link->fields;
        $settings      = Settings::all();
        $followup_only = Project::isFollowupOnlyType($project, $item['type'] ?: $row['workitem_type']);
        $info          = self::closureInfo($item, $client);
        $actor         = (int) $settings['actor_users_id'] ?: (int) $row['users_id'];
        $state_name    = $item['state'];

        $DB->beginTransaction();
        try {
            $link->update($common + [
                'state'       => Link::STATE_CLOSED,
                'date_closed' => $info['at'],
                'closed_by'   => mb_substr($info['by'], 0, 255),
            ]);

            $ticket = new Ticket();
            if ($ticket->getFromDB((int) $row['tickets_id']) && !$ticket->fields['is_deleted']) {
                $status = (int) $ticket->fields['status'];

                if ($status !== Ticket::CLOSED) {
                    if ($settings['close_followup'] === '1') {
                        // Details about an Epic or a Feature are for technicians only.
                        $private = $followup_only || $settings['close_followup_private'] === '1';
                        self::addFollowup(
                            $ticket->getID(),
                            self::closedMessage($row + ['title' => $item['title']], $state_name, $info['by'], $info['at']),
                            $private,
                            $actor,
                            true
                        );
                    }

                    if (!$followup_only && Link::countOpenActionable($ticket->getID()) === 0) {
                        $will_solve = $settings['close_solve_ticket'] === '1' && $status !== Ticket::SOLVED;

                        // The requester is told with a neutral public followup, unless the solution added just
                        // below already informs him (one message, not two notifications).
                        if ($settings['notify_requester_close'] === '1' && !$will_solve) {
                            self::addFollowup($ticket->getID(), RequesterNotice::html(RequesterNotice::CLOSURE), false, $actor, true);
                        }

                        if ($will_solve) {
                            self::solveTicket($ticket, $row, $info['by'], $info['at'], $settings, $actor);
                        }
                    }
                }
            }

            $DB->commit();
        } catch (Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }

    private static function handleNotFound(array $row, string $message, array &$stats): void
    {
        $link = new Link();
        $link->getFromDB((int) $row['id']);

        $count  = (int) $row['error_count'] + 1;
        $update = [
            'id'              => (int) $row['id'],
            'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            'error_count'     => $count,
            'last_error'      => mb_substr($message, 0, 1000),
        ];

        // A single 404 can be a permission glitch: only give up after several consecutive ones.
        if ($count >= self::MAX_NOT_FOUND) {
            $update['state'] = Link::STATE_MISSING;
            $link->update($update);
            self::addFollowup(
                (int) $row['tickets_id'],
                '<p>' . sprintf(
                    __('The Azure DevOps work item %s can no longer be found (deleted, or access removed). It is not followed anymore.', 'azuredevops'),
                    self::itemLink($row)
                ) . '</p>',
                true,
                (int) $row['users_id']
            );
            $stats['missing']++;

            return;
        }

        $link->update($update);
        $stats['errors']++;
    }

    // ----------------------------------------------------------- ticket actions

    /**
     * Add a followup to the ticket. Failures are logged and only rethrown when $strict.
     */
    public static function addFollowup(int $tickets_id, string $html, bool $private, int $users_id, bool $strict = false): void
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($tickets_id) || $ticket->fields['is_deleted'] || (int) $ticket->fields['status'] === Ticket::CLOSED) {
            return;
        }

        $id = (new ITILFollowup())->add([
            'itemtype'   => 'Ticket',
            'items_id'   => $tickets_id,
            'content'    => $html,
            'is_private' => $private ? 1 : 0,
            'users_id'   => $users_id,
        ]);

        if ($id === false) {
            $msg = sprintf('Could not add followup to ticket %d', $tickets_id);
            Toolbox::logInFile('azuredevops', $msg . "\n");
            if ($strict) {
                throw new \RuntimeException($msg);
            }
        }
    }

    private static function solveTicket(Ticket $ticket, array $row, string $closed_by, string $closed_at, array $settings, int $actor): void
    {
        $template = trim($settings['solution_template']);
        if ($template === '') {
            // The solution is read by the requester: neutral text. The Azure DevOps details (who, when)
            // go to the private followup (setting "Add a followup to the ticket").
            $content = RequesterNotice::html(RequesterNotice::CLOSURE);
        } else {
            // Placeholders are replaced with escaped values: the template itself is trusted HTML/text.
            $content = strtr($template, [
                '{workitem_url}' => htmlspecialchars((string) $row['url'], ENT_QUOTES, 'UTF-8'),
                '{workitem}'     => htmlspecialchars(Link::label($row), ENT_QUOTES, 'UTF-8'),
                '{closed_by}'    => htmlspecialchars($closed_by, ENT_QUOTES, 'UTF-8'),
                '{closed_at}'    => htmlspecialchars(\Html::convDateTime($closed_at), ENT_QUOTES, 'UTF-8'),
                '{ticket_id}'    => (string) $ticket->getID(),
            ]);
            if (!\Glpi\RichText\RichText::isRichTextHtmlContent($content)) {
                $content = '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
        }

        $input = [
            'itemtype' => 'Ticket',
            'items_id' => $ticket->getID(),
            'content'  => $content,
            'users_id' => $actor,
        ];
        if ((int) $settings['solutiontypes_id'] > 0) {
            $input['solutiontypes_id'] = (int) $settings['solutiontypes_id'];
        }

        if ((new ITILSolution())->add($input) === false) {
            throw new \RuntimeException(sprintf('Could not add the solution to ticket %d', $ticket->getID()));
        }
    }

    // ---------------------------------------------------------------- messages

    private static function itemLink(array $row): string
    {
        $label = htmlspecialchars(Link::label($row) . (!empty($row['title']) ? ' – ' . $row['title'] : ''), ENT_QUOTES, 'UTF-8');
        $url   = self::safeUrl((string) $row['url']);

        return $url !== '' ? '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>' : $label;
    }

    private static function closedMessage(array $row, string $state, string $closed_by, string $closed_at): string
    {
        $when = $closed_at !== '' ? \Html::convDateTime($closed_at) : '';

        $text = sprintf(__('The Azure DevOps work item %1$s is done (state: %2$s)', 'azuredevops'), self::itemLink($row), htmlspecialchars($state, ENT_QUOTES, 'UTF-8'));
        if ($closed_by !== '') {
            $text .= ' ' . sprintf(__('by %s', 'azuredevops'), htmlspecialchars($closed_by, ENT_QUOTES, 'UTF-8'));
        }
        if ($when !== '') {
            $text .= ' ' . sprintf(__('on %s', 'azuredevops'), htmlspecialchars($when, ENT_QUOTES, 'UTF-8'));
        }

        return '<p>' . $text . '.</p>';
    }

    private static function movedMessage(array $row, string $new_project): string
    {
        return '<p>' . sprintf(
            __('The Azure DevOps work item %1$s was moved to the project "%2$s". It is not followed anymore: the ticket is left unchanged.', 'azuredevops'),
            self::itemLink($row),
            htmlspecialchars($new_project, ENT_QUOTES, 'UTF-8')
        ) . '</p>';
    }

    private static function removedMessage(array $row): string
    {
        return '<p>' . sprintf(
            __('The Azure DevOps work item %s was removed from the backlog. It is not followed anymore: the ticket is left unchanged.', 'azuredevops'),
            self::itemLink($row)
        ) . '</p>';
    }

    // ----------------------------------------------------------------- helpers

    /**
     * Category of the current state. The states of a type are cached when the project is tested: if one is
     * unknown (state added since), the types are read again once per run.
     */
    private static function categoryOf(array $project, array $item): ?string
    {
        $category = Project::stateCategory($project, $item['type'], $item['state']);
        if ($category !== null) {
            return $category;
        }

        $id = (int) $project['id'];
        if (empty(self::$types_refreshed[$id])) {
            self::$types_refreshed[$id] = true;
            try {
                Project::testAndCache($id);
                $fresh = Project::find($id);
                if ($fresh !== null) {
                    return Project::stateCategory($fresh, $item['type'], $item['state']);
                }
            } catch (AdoException) {
                // The check itself goes on with what is known.
            }
        }

        return null;
    }

    /**
     * Who completed the work item and when. The "closed" fields do not exist in every process template:
     * fall back on the last state change, then on the last change.
     *
     * @return array{by: string, at: string}
     */
    private static function closureInfo(array $item, AdoClient $client): array
    {
        $fields = $item['fields'] ?? null;
        if (!is_array($fields)) {
            try {
                $fields = $client->getWorkItem((int) $item['id'])['fields'];
            } catch (AdoException) {
                $fields = [];
            }
        }

        $by = AdoClient::identityName($fields['Microsoft.VSTS.Common.ClosedBy'] ?? null);
        if ($by === '') {
            $by = AdoClient::identityName($fields['System.ChangedBy'] ?? null) ?: (string) $item['changed_by'];
        }
        $at = (string) ($fields['Microsoft.VSTS.Common.ClosedDate'] ?? $fields['Microsoft.VSTS.Common.StateChangeDate'] ?? $fields['System.ChangedDate'] ?? $item['changed_date'] ?? '');

        return ['by' => $by, 'at' => self::toLocalDateTime($at)];
    }

    /**
     * Azure DevOps dates are ISO 8601 (UTC); GLPI stores local server time.
     */
    private static function toLocalDateTime(string $iso): string
    {
        $time = $iso !== '' ? strtotime($iso) : false;

        return $time !== false ? date('Y-m-d H:i:s', $time) : date('Y-m-d H:i:s');
    }

    private static function safeUrl(string $url): string
    {
        return preg_match('#^https?://#i', $url) ? $url : '';
    }

    private static function recordProjectError(int $projects_id, ?string $message): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->update(Project::getTable(), [
            'last_error'      => $message !== null ? mb_substr($message, 0, 1000) : null,
            'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
        ], ['id' => $projects_id]);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function recordGlobalError(array $rows, AdoException $e): void
    {
        foreach ($rows as $row) {
            (new Link())->update([
                'id'              => (int) $row['id'],
                'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                'last_error'      => mb_substr($e->getMessage(), 0, 1000),
            ]);
        }
    }

    private static function recordFailure(array $row, string $message): void
    {
        (new Link())->update([
            'id'              => (int) $row['id'],
            'date_last_check' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
            // error_count is reserved to consecutive "not found" answers (see handleNotFound()).
            'last_error'      => mb_substr($message, 0, 1000),
        ]);
    }

    private static function logError(?CronTask $task, string $message): void
    {
        Toolbox::logInFile('azuredevops', $message . "\n");
        $task?->log($message);
    }
}
