# Azure DevOps – GLPI 11 plugin

Create an Azure DevOps work item (User Story, Product Backlog Item, Issue…) from a GLPI ticket, optionally **under an Epic or a Feature**, or link an existing one, then **check every 5 minutes** (GLPI automatic action) whether it is done. When it is, the plugin acts on the ticket **through GLPI's own objects** (followup, solution), so history, business rules, notifications and other automatic actions (auto-close of solved tickets, satisfaction surveys…) run as for a manual action.

Each Azure DevOps project is configured with **its own credentials** — a personal access token, or a **Microsoft Entra application** that is not tied to anybody's account — and each project can be restricted to **chosen groups and users**.

Tested on **GLPI 11.0.11** (PHP 8.3, MariaDB 10.11) against a mock of the Azure DevOps REST API (see *Tests*). License: AGPL-3.0-or-later.

## Install

1. Put the `azuredevops` folder in GLPI's `plugins/` directory, then install and activate it in *Setup > Plugins*.
2. Make sure GLPI's system cron runs (`* * * * * php /path/to/glpi/front/cron.php`); the automatic action `CheckWorkItems` is in CLI mode.
3. *Setup > Plugins > Azure DevOps*: add your projects (below), then adjust the behaviour on creation and on completion.

## Configure the projects

*Setup > Plugins > Azure DevOps* is GLPI's standard administration page: the tabs are on the left, one per zone of settings (**Azure DevOps projects**, **Work item creation**, **When a work item is done**, **Advanced**, **Rights by profile**), and each tab saves its own zone. *Add a project* (first tab) opens the page of the project, which has its tabs too: **Connection**, **Work items**, **Fields and tags**, **Who can use this project**. A new project starts with its connection; the other tabs appear once it is saved, and it is restricted (nobody can use it) until you choose users or groups. For each project:

| Field | Meaning |
|---|---|
| Name | Shown to technicians when they choose where to create the work item |
| Organization / project | `my-organization` (or its URL) and the Azure DevOps project name |
| Authentication | **Personal access token (PAT)** or **Microsoft Entra application** (see below) |
| Access token (PAT) | A personal access token with the **Work Items (Read & write)** and **Project and Team (Read)** scopes, whose owner can work on the project. Stored **encrypted** (GLPI's key), never shown again, used **only for this project** |
| Default type | `User Story` (Agile), `Product Backlog Item` (Scrum), `Issue` (Basic)… chosen from the types read by *Save and test the connection*: created when the technician does not choose another one |
| Types offered | By default **every type of the project** read from Azure DevOps (User Story, Bug, Task…) can be chosen in the ticket, the default type first. *Only offer some types in the ticket* restricts the list to the default type and the ones you pick |
| Possible parents | Types under which a work item can be created and that can be linked (`Epic`, `Feature`…) |
| "Followup only" types | Types (Epic, Feature…) whose completion only adds a private followup and never solves the ticket |
| Done categories | `Completed` (always) and optionally `Resolved`. Categories come from the process template, so custom states and processes work without configuration |
| Default fields | Values for the fields your process makes mandatory (Priority...), see below |
| Area path | Optional |
| Default tags | Added to every work item; the list offers the tags that exist in the project in Azure DevOps. *Only send tags that already exist* is for an account that cannot create tags (Stakeholder) |

Credentials expire or get revoked: when they are refused, the error is shown in the project list and on the linked tickets, the other projects keep being checked, and entering new ones clears it.

### Fields required by your process (Priority…)

A custom process can make fields mandatory, often with a list of allowed values. Azure DevOps then refuses the creation: `TF401320: Rule Error for field Priority ... Required ... LimitedToValues`. It reports one field at a time ("One additional error occurred"), so the plugin reads them all for you:

1. In the project, choose the **type of work item to create** and use *Save and test the connection*.
2. The project page now lists **the fields required by the process for that type**, with their **reference name** (for example `Microsoft.VSTS.Common.Priority`), their allowed values, and whether they still *need a value*.
3. In **Default fields**, give each one a value, one per line:

```
Microsoft.VSTS.Common.Priority = {priority}
Custom.Team = Support
Custom.Reference = GLPI-{id}: {title}
```

**In the ticket tab**, the creation form then shows these fields (for example *Priority*) with the allowed values in a list, **preselected** from the default fields, from the process's own default or, for the Priority, from the ticket's priority: the technician can change them for each work item. Default fields are for values that should not be asked.

**A field that is not really required.** Azure DevOps sometimes reports as required a field that its own form does not ask (hidden from the layout, or filled in by the process). Untick **Ask in the ticket** for it in the project: it is no longer asked in the ticket, and its default value from the project, if any, still applies. The *Default in the process* column helps to decide: a field that the process fills in by itself is rarely worth asking.

**Keeping the types up to date.** The list of the types is read again from Azure DevOps by itself when a ticket is opened if it is more than an hour old, and the **Refresh the types and fields from Azure DevOps** button of the project page does it at once.

Placeholders: `{priority}` is the ticket's priority on the Azure DevOps scale (GLPI 5-6 → 1, 4 → 2, 3 → 3, 1-2 → 4; use a fixed value if your process has other values), `{id}` the ticket number, `{title}` its title, `{entity}` its GLPI entity, `{category}` its category and `{requester}` its first requester (for instance `Custom.Client = {entity}` to fill a customer field). Use one of the **allowed values** exactly as listed. Fields with a default in the process get it from Azure DevOps and need nothing.

### Authenticate without a personal account (Microsoft Entra application)

A personal access token belongs to a person: if that account is disabled, or the token expires, the project stops working. With an **Entra application** (a *service principal*), the plugin signs in with the application's own credentials and no personal account is involved. It takes three steps, **in three different places**:

**A. In Microsoft Entra** (`https://entra.microsoft.com`, or the Azure portal), with an account allowed to register applications:

1. *Applications > App registrations > New registration*. Give it a recognisable name, for example `GLPI Azure DevOps`.
2. On the application's *Overview* page, copy the **Application (client) ID** and the **Directory (tenant) ID**.
3. *Certificates & secrets > New client secret*. Copy the **Value** column (not the *Secret ID*) right away: it is never shown again.

**B. In Azure DevOps** (`https://dev.azure.com/<your-organization>`), with a member of the *Project Collection Administrators* group (the organization owners are):

1. Click the **Organization settings** gear, bottom left.
2. **Users > Add users**.
3. Search the application **by the name given in step A1**, then set:
   - **Access level**: `Basic` (see *Access level* below);
   - **Add to projects**: the Azure DevOps project of the plugin's project;
   - **Azure DevOps Groups**: leave `Project Contributors` (this is what lets the application create and edit work items).
4. Check: the application appears in *Organization settings > Users*, then in *Project settings > Permissions > Contributors > Members*.

Registering the application in Entra is **not enough**: without step B, Azure DevOps refuses it (HTTP 401) although Entra gave a token. The organization must also be connected to the **same Entra tenant** (an organization tied to a personal Microsoft account cannot host an application): see *Organization settings > Microsoft Entra*.

**C. In the plugin**: *Setup > Plugins > Azure DevOps*, add or edit the project, choose *Microsoft Entra application*, enter the tenant ID, the application ID and the secret value, then *Save and test the connection*.

The plugin gets an access token from Entra (valid about an hour), keeps it encrypted in the project while it is valid, renews it before it expires, and asks for a new one once if Azure DevOps refuses it earlier (revoked). The client secret has an expiry date too, but it belongs to the application, not to a person: renew it before it ends. Both modes can be filled in; the one selected is used, so you can switch without typing the other credentials again. Sovereign clouds use another sign-in address: *Advanced > Microsoft Entra sign-in address*.

### Access level of the account (Basic or Stakeholder)

The account behind the token or the application has an Azure DevOps **access level**. **Basic** is the safe choice. **Stakeholder** (free) is limited, and Microsoft documents that a Stakeholder can view, add and edit work items but **can only assign tags that already exist** (it cannot create new ones). Since the plugin adds the project's default tags (`glpi` unless you change it), a Stakeholder account can fail to create a work item while the tag does not exist yet. With a Stakeholder account, leave the default tags empty, or create the tag first from an account that can. If something fails, the message of Azure DevOps is shown in GLPI and written to `files/_log/azuredevops.log`.

### Who can use which project

In each project, **Restrict to the users and groups below**:

- *checked* (default): only the members of the listed **groups** and the listed **users** can use the project. A technician in several allowed groups gets all their projects (group 1 → project A, group 2 → project B, group 3 → both A and B);
- *unchecked*: every technician who has the plugin's "create" right can use it;
- a restricted project with nobody listed can be used by **nobody**.

A technician only **sees** the links of the projects he may use (a note tells how many others exist), and every action — create, link, search, refresh, unlink — is checked server-side against that access, on top of the profile rights. Groups are not expanded to their sub-groups: list every group that needs access.

## Use

Ticket tab **Azure DevOps**:

- **Create a work item**: pick a project, the **type** of work item (User Story, Bug… among those the project offers; the default type if you leave it), optionally a parent (Epic / Feature, searched by title or number), tags, and the followup (private / public / none). The work item contains the ticket details, the description (HTML), the inline images and the attachments uploaded to Azure DevOps, and a hyperlink back to the ticket.
- **Link an existing work item**: pick a project and search by title or number. The same Epic can be linked to many tickets.
- **A solved or closed ticket is left as it is**: its links stay visible, but no work item can be created, linked, refreshed or unlinked (the forms and buttons are hidden and the server refuses them). Reopen the ticket to act again.
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
| Credentials refused or expired (token, client secret), Azure DevOps or Entra unreachable | That project is retried at the next run (error shown); other projects are still checked |
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

*Administration > Profiles > (profile) > Azure DevOps* tab or the **Rights by profile** table of the plugin's configuration page: read, create or link, refresh, unlink. Rights are cumulative: **read is required** for the others, and profiles of the self-service (simplified) interface can never use the plugin. The **Rights by profile** tab lists every profile: those that use the simplified interface — the default of a profile created in GLPI — are shown greyed, without rights; to give one of them rights, set its interface to the standard one in *Administration > Profiles*. The configuration pages require GLPI's *Setup > Update* right. Forms use GLPI's CSRF protection. Profiles that can already update tickets get all four rights when the plugin is first installed.

## Update

Replace the folder, clear GLPI's cache (`php bin/console cache:clear`), then in *Setup > Plugins* click *Update* and *Activate*. Projects, credentials, access lists, links, settings and rights are preserved, and existing projects stay in personal-token mode (`tests/upgrade_test.sh`).

## Known limits

- Ticket → work item only; comments are not synchronised. A work item reopened after being done is no longer followed.
- Attachments of followups are not copied (only the ticket's own and the images of its description). Pull requests are not shown.
- Detection delay is about 5 minutes (polling, no service hook).
- Azure DevOps Services (`dev.azure.com`). Azure DevOps Server (on-premises) uses another URL layout and is not supported.
- Entra applications sign in with a **client secret** (certificates and federated credentials are not supported).
- The tests below use a **mock** of the Azure DevOps API and of the Microsoft Entra sign-in, not the real services: try it on a sandbox project first (see *Contributing* for what to check).

## Tests (`tests/`)

`mock_ado.py` (Azure DevOps API and Entra sign-in mock, with two projects and two process shapes), `integration.php` (real GLPI, CLI, real cron: projects and encryption, access matrix, creation under a parent, linking, every automatic-action case, the Entra sign-in), `http_test.py` (real web app: login, tab, configuration, access restrictions, CSRF, languages), `ui_fields_test.py` (the creation form script, in jsdom), `upgrade_test.sh`, `run_all.sh`.
