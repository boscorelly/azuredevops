# Azure DevOps – GLPI 11 plugin

Create an Azure DevOps work item (User Story, Product Backlog Item, Issue…) from a GLPI ticket, optionally **under an Epic or a Feature**, or link an existing one, then **check every 5 minutes** (GLPI automatic action) whether it is done. When it is, the plugin acts on the ticket **through GLPI's own objects** (followup, solution), so history, business rules, notifications and other automatic actions (auto-close of solved tickets, satisfaction surveys…) run as for a manual action.

Each Azure DevOps project is configured with **its own access token**, and each project can be restricted to **chosen groups and users**.

Tested on **GLPI 11.0.11** (PHP 8.3, MariaDB 10.11) against a mock of the Azure DevOps REST API (see *Tests*). License: AGPL-3.0-or-later.

## Install

1. Put the `azuredevops` folder in GLPI's `plugins/` directory, then install and activate it in *Setup > Plugins*.
2. Make sure GLPI's system cron runs (`* * * * * php /path/to/glpi/front/cron.php`); the automatic action `CheckWorkItems` is in CLI mode.
3. *Setup > Plugins > Azure DevOps*: add your projects (below), then adjust the behaviour on creation and on completion.

## Configure the projects

*Setup > Plugins > Azure DevOps > Add a project*. For each project:

| Field | Meaning |
|---|---|
| Name | Shown to technicians when they choose where to create the work item |
| Organization / project | `my-organization` (or its URL) and the Azure DevOps project name |
| Access token (PAT) | A personal access token with the **Work Items (Read & write)** scope, whose owner can work on the project. Stored **encrypted** (GLPI's key), never shown again, used **only for this project** |
| Type to create | `User Story` (Agile), `Product Backlog Item` (Scrum), `Issue` (Basic)… chosen from the types read by *Save and test the connection* |
| Possible parents | Types under which a work item can be created and that can be linked (`Epic`, `Feature`…) |
| "Followup only" types | Types (Epic, Feature…) whose completion only adds a private followup and never solves the ticket |
| Done categories | `Completed` (always) and optionally `Resolved`. Categories come from the process template, so custom states and processes work without configuration |
| Area path, default tags | Optional |

Tokens expire: when a token is refused, the error is shown in the project list and on the linked tickets, the other projects keep being checked, and entering a new token clears it.

### Who can use which project

In each project, **Restrict to the users and groups below**:

- *checked* (default): only the members of the listed **groups** and the listed **users** can use the project. A technician in several allowed groups gets all their projects (group 1 → project A, group 2 → project B, group 3 → both A and B);
- *unchecked*: every technician who has the plugin's "create" right can use it;
- a restricted project with nobody listed can be used by **nobody**.

A technician only **sees** the links of the projects he may use (a note tells how many others exist), and every action — create, link, search, refresh, unlink — is checked server-side against that access, on top of the profile rights. Groups are not expanded to their sub-groups: list every group that needs access.

## Use

Ticket tab **Azure DevOps**:

- **Create a work item**: pick a project, optionally a parent (Epic / Feature, searched by title or number), tags, and the followup (private / public / none). The work item contains the ticket details, the description (HTML), the inline images and the attachments uploaded to Azure DevOps, and a hyperlink back to the ticket.
- **Link an existing work item**: pick a project and search by title or number. The same Epic can be linked to many tickets.
- The tab shows the parent chain (Epic › Feature), the type, the Azure DevOps state and the last check.

| Situation | Behaviour |
|---|---|
| Work item reaches a "done" category | Private followup (state, who, when). The ticket is solved / the requester informed (settings) **only when no other work item of the ticket is still open** |
| Epic / Feature (or any "followup only" type) done | Private followup only; the ticket is never solved |
| Work item moved to another Azure DevOps project | Ticket not solved; private informational followup; no longer followed |
| Work item in a "Removed" category | Same: not followed, ticket untouched |
| Work item not found (deleted, access removed) | Given up after 3 consecutive checks; private informational followup |
| Work item already done when linked | Linked for reference, no action on the ticket |
| Ticket already closed / in trash | Link marked done, ticket untouched |
| Token refused or expired, Azure DevOps unreachable | That project is retried at the next run (error shown); other projects are still checked |
| Rate limit (HTTP 429) | The run stops cleanly and resumes at the next run |
| Failure while acting on the ticket | Everything rolled back; the work item stays "open" and is retried, no duplicates |

## Informing the requester (non-technicians)

Requesters never see the Azure DevOps tab. By default they learn nothing. Two options add **neutral public followups**, with no Azure DevOps detail (no project, link or developer):

- when a work item is created (the technician can untick "Inform the requester" for a given one);
- when everything is done: nothing, a public followup, or **the ticket is solved** with this message as the solution.

Both texts can be changed. The technical details remain in the **private** followups.

## Languages

English (`en_GB`, `en_US`) and French (`fr_FR`). The plugin uses **the language of each user if it has a translation for it, otherwise English**. To add a language: copy `locales/azuredevops.pot` to `locales/<xx_XX>.po`, translate it, run `python3 tools/locales.py` (needs `msgfmt`), clear GLPI's cache.

## Rights

*Administration > Profiles > (profile) > Azure DevOps* tab or the **Rights by profile** table of the plugin's configuration page: read, create or link, refresh, unlink. Rights are cumulative: **read is required** for the others, and profiles of the self-service interface can never use the plugin. The configuration pages require GLPI's *Setup > Update* right. Forms use GLPI's CSRF protection. Profiles that can already update tickets get all four rights when the plugin is first installed.

## Update

Replace the folder, clear GLPI's cache (`php bin/console cache:clear`), then in *Setup > Plugins* click *Update* and *Activate*. Projects, tokens, access lists, links, settings and rights are preserved (`tests/upgrade_test.sh`).

## Known limits

- Ticket → work item only; comments are not synchronised. A work item reopened after being done is no longer followed.
- Attachments of followups are not copied (only the ticket's own and the images of its description). Pull requests are not shown.
- Detection delay is about 5 minutes (polling, no service hook).
- Azure DevOps Services (`dev.azure.com`). Azure DevOps Server (on-premises) uses another URL layout and is not supported.
- The tests below use a **mock** of the Azure DevOps API, not the real service: try it on a sandbox project first (see *Contributing* for what to check).

## Tests (`tests/`)

`mock_ado.py` (Azure DevOps API mock with two projects and two process shapes), `integration.php` (real GLPI, CLI, real cron: projects and encryption, access matrix, creation under a parent, linking, every automatic-action case), `http_test.py` (real web app: login, tab, configuration, access restrictions, CSRF, languages), `upgrade_test.sh`, `run_all.sh`.
