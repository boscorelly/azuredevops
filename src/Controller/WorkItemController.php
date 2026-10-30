<?php

namespace GlpiPlugin\Azuredevops\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Azuredevops\AdoException;
use GlpiPlugin\Azuredevops\Link;
use GlpiPlugin\Azuredevops\Project;
use GlpiPlugin\Azuredevops\ProjectAccess;
use GlpiPlugin\Azuredevops\Sync;
use Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Ticket;
use Toolbox;

/**
 * Actions of the "Azure DevOps" tab of a ticket. Besides the plugin's rights, every action checks that
 * the technician is allowed to use the project concerned (ProjectAccess).
 */
final class WorkItemController extends AbstractController
{
    #[Route('/Ticket/{tickets_id}/WorkItem', name: 'azuredevops_create', requirements: ['tickets_id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, int $tickets_id): Response
    {
        $ticket = $this->getReadableTicket($tickets_id);
        if (!Link::canCreate()) {
            throw new AccessDeniedHttpException();
        }

        try {
            $res = Link::createFromTicket(
                $ticket,
                (int) $request->request->get('project_id', 0),
                (int) Session::getLoginUserID(),
                (int) $request->request->get('parent_id', 0),
                (string) $request->request->get('tags', ''),
                $request->request->getBoolean('allow_duplicate'),
                (string) $request->request->get('followup', ''),
                $request->request->has('notify_requester') ? $request->request->getBoolean('notify_requester') : null,
                // Fields the process requires (Priority...), chosen in the form
                array_filter((array) $request->request->all('fields'), 'is_scalar'),
                // The type chosen in the ticket (empty = the project's default type)
                (string) $request->request->get('work_item_type', '')
            );
            $this->flashCreated(__('Azure DevOps work item created: %s', 'azuredevops'), $res['link']);
            $this->flashWarnings($res['warnings']);
        } catch (AdoException | \RuntimeException $e) {
            Toolbox::logInFile('azuredevops', sprintf("Creation failed (ticket %d, project %d): %s\n", $tickets_id, (int) $request->request->get('project_id', 0), $e->getMessage()));
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }

        return $this->backToTicket($tickets_id);
    }

    #[Route('/Ticket/{tickets_id}/WorkItem/Link', name: 'azuredevops_link', requirements: ['tickets_id' => '\d+'], methods: ['POST'])]
    public function linkExisting(Request $request, int $tickets_id): Response
    {
        $ticket = $this->getReadableTicket($tickets_id);
        if (!Link::canCreate()) {
            throw new AccessDeniedHttpException();
        }

        try {
            $res = Link::linkExisting(
                $ticket,
                (int) $request->request->get('project_id', 0),
                (int) $request->request->get('workitem_id', 0),
                (int) Session::getLoginUserID(),
                (string) $request->request->get('followup', '')
            );
            $this->flashCreated(__('Azure DevOps work item linked: %s', 'azuredevops'), $res['link']);
            $this->flashWarnings($res['warnings']);
        } catch (AdoException | \RuntimeException $e) {
            Toolbox::logInFile('azuredevops', sprintf("Link failed (ticket %d, project %d): %s\n", $tickets_id, (int) $request->request->get('project_id', 0), $e->getMessage()));
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }

        return $this->backToTicket($tickets_id);
    }

    #[Route('/Link/{id}/Refresh', name: 'azuredevops_refresh', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refresh(int $id): Response
    {
        $link = $this->getLink($id);
        if (!Link::canUpdate()) {
            throw new AccessDeniedHttpException();
        }
        try {
            Session::addMessageAfterRedirect(
                htmlspecialchars(sprintf(__('Status: %s', 'azuredevops'), Sync::refresh($link))),
                false,
                INFO
            );
        } catch (AdoException | \RuntimeException $e) {
            // Azure DevOps error, or the actions on the ticket failed (rolled back, retried by the automatic action).
            Session::addMessageAfterRedirect(htmlspecialchars($e->getMessage()), false, ERROR);
        }

        return $this->backToTicket((int) $link->fields['tickets_id']);
    }

    #[Route('/Link/{id}/Unlink', name: 'azuredevops_unlink', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function unlink(int $id): Response
    {
        $link = $this->getLink($id);
        if (!Link::canDelete()) {
            throw new AccessDeniedHttpException();
        }
        $tickets_id = (int) $link->fields['tickets_id'];
        $link->delete(['id' => $id], true);
        Session::addMessageAfterRedirect(__('The work item is no longer followed. It was not changed on Azure DevOps.', 'azuredevops'));

        return $this->backToTicket($tickets_id);
    }

    /**
     * Work item search for the select boxes of the ticket tab.
     *
     * scope=parents: the types allowed as parents in the project (Epic, Feature...);
     * scope=items:   any of those plus the type created by the plugin (to link an existing work item).
     */
    #[Route('/Projects/{id}/Search', name: 'azuredevops_search', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function search(Request $request, int $id): Response
    {
        if (!Link::canCreate()) {
            throw new AccessDeniedHttpException();
        }
        $project = Project::find($id);
        if ($project === null || !ProjectAccess::canUse($project, (int) Session::getLoginUserID())) {
            throw new AccessDeniedHttpException();
        }

        $types = Project::csv($project['parent_types']);
        if ($request->query->get('scope') === 'items') {
            $types = Project::csv(implode(',', array_merge($types, Project::offeredTypes($project))));
        }

        try {
            $items = Project::client($project)->searchWorkItems($types, (string) $request->query->get('term', ''), 20);
        } catch (AdoException $e) {
            Toolbox::logInFile('azuredevops', sprintf("Search failed (project %d): %s\n", $id, $e->getMessage()));

            return new JsonResponse(['results' => [], 'error' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $results = [];
        foreach ($items as $item) {
            $results[] = ['id' => $item['id'], 'text' => sprintf('%s #%d – %s (%s)', $item['type'], $item['id'], $item['title'], $item['state'])];
        }

        return new JsonResponse(['results' => $results, 'pagination' => ['more' => false]]);
    }

    // ------------------------------------------------------------------ helpers

    private function getReadableTicket(int $id): Ticket
    {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        if (!$ticket->canViewItem()) {
            throw new AccessDeniedHttpException();
        }

        return $ticket;
    }

    /**
     * A link is only reachable by someone who can see its ticket and may use its project.
     */
    private function getLink(int $id): Link
    {
        $link = new Link();
        if (!$link->getFromDB($id)) {
            throw new NotFoundHttpException();
        }
        $this->getReadableTicket((int) $link->fields['tickets_id']);
        if (!Link::canUseProjectOf($link->fields)) {
            throw new AccessDeniedHttpException();
        }

        return $link;
    }

    private function flashCreated(string $format, Link $link): void
    {
        $url   = htmlspecialchars((string) $link->fields['url'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars(Link::label($link->fields), ENT_QUOTES, 'UTF-8');
        Session::addMessageAfterRedirect(
            sprintf($format, '<a href="' . $url . '" target="_blank" rel="noopener">' . $label . '</a>'),
            false,
            INFO
        );
    }

    /**
     * @param string[] $warnings
     */
    private function flashWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            Session::addMessageAfterRedirect(htmlspecialchars($warning), false, WARNING);
        }
    }

    private function backToTicket(int $tickets_id): Response
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return new RedirectResponse(
            ($CFG_GLPI['root_doc'] ?? '') . '/front/ticket.form.php?id=' . $tickets_id
            . '&forcetab=' . rawurlencode(Link::class . '$1')
        );
    }
}
