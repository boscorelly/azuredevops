# Changelog

Release checklist: bump `PLUGIN_AZUREDEVOPS_VERSION` in `setup.php`, add the version (compatibility and `download_url`) to `azuredevops.xml`, update this file, tag and publish a release with the `glpi-azuredevops-<version>.tar.bz2` archive.

## 1.0.6
- **Every type of the project is now offered in the ticket by default** (User Story, Bug, Task, Epic...), as read from Azure DevOps at the connection test, the default type first. In 1.0.5 only the default type was offered unless other types were picked in the project: that was not what was asked for.
- Restricting the list is now an explicit option of the project: *Only offer some types in the ticket*, then the types to offer (the default type is always offered).
- **The required fields of a type are read when it is first chosen in a ticket** (a message says they are being read), and kept: a type that was not read at the connection test, or added later in Azure DevOps, works without going back to the configuration. An error of Azure DevOps is shown instead of the fields.
- Update from 1.0.5: one column is added to the projects table. Projects that had other types picked in 1.0.5 now offer every type; tick *Only offer some types in the ticket* to keep a short list.

## 1.0.5
- **The type of work item is chosen in the ticket** (User Story, Bug...), next to the project: a *Type* list in the creation form, filled with the types the project offers. If none is chosen, the project's **default type** is created.
- Per project, *Default type of work item* and **Other types the technician can choose in the ticket** (picked among the types read from Azure DevOps; empty = only the default type, as before).
- **Each type has its own required fields**: the connection test reads them for every offered type, the project page lists them per type, and the form of the ticket shows those of the chosen type (a Bug is not asked what a User Story is).
- *Default fields* can target a type: `Bug: Microsoft.VSTS.Common.Severity = 2 - High` (lines without a type apply to every type).
- A ticket can have an open User Story **and** an open Bug in the same project; a second one of the same type is blocked. Searching an existing work item to link also finds the offered types.
- What the form sends is checked on the server: the type must be offered by the project.
- Update from 1.0.4: one column is added to the projects table; existing projects keep creating their configured type only.

## 1.0.4
- **The creation form offers the fields the process requires** (Priority...), next to the project and the parent: a list of the values the process allows (read from Azure DevOps at the connection test), or a text box when there is no list. The value is preselected from the project's *Default fields* if any, else from the process's own default, else, for the Priority, from the priority of the ticket. The technician can change it for each work item.
- What is sent is checked on the server: only the fields the project lists as required can be set from the ticket, with one of the allowed values; an empty choice falls back on the project's default.
- The project's *Default fields* remain the way to give a value to a field without asking the technician.

## 1.0.3
- **Fields required by the process** (`TF401320: Rule Error for field Priority...`): a custom process can make fields such as Priority mandatory, with a list of allowed values, and the work item could not be created. New per-project setting **Default fields** (`Reference.Name = value`, one per line) with the placeholders `{priority}` (the ticket's priority on the Azure DevOps scale, 1 = most urgent to 4 = lowest), `{id}` and `{title}`.
- *Save and test the connection* now **reads the required fields of the type to create** (with their allowed values) and lists them in the project, showing which ones still need a value, so that all of them can be filled in at once (Azure DevOps reports only the first error).
- When Azure DevOps refuses a creation because of such a rule, the message says where to set the value.
- Update from 1.0.2: one column is added to the projects table.

## 1.0.2
- **The message of Azure DevOps is no longer lost on server errors (HTTP 5xx)**: it is shown in GLPI and recorded on the link, like for the other errors. Failures of the creation, linking and search are also written to `files/_log/azuredevops.log`.
- The parent / work item search lists show the Azure DevOps error instead of an empty list.
- Help on the **Stakeholder** access level (it can only use tags that already exist, which can make the creation fail while the default tag `glpi` does not exist) and a clearer step-by-step for the Entra application, in the form and in the README: where to click in Microsoft Entra and in Azure DevOps.

## 1.0.1
- **Microsoft Entra application as an alternative to the personal access token**, chosen per project. A PAT belongs to a person and stops working when that account is disabled; an Entra application (service principal) is not tied to anybody. The plugin signs in with the client credentials (tenant ID, application ID, client secret), keeps the access token (encrypted) while it is valid, renews it before it expires, and retries once with a new one if Azure DevOps refuses it early.
- The client secret is stored encrypted and never sent back to the browser; both modes can be filled in, the selected one is used.
- Clear errors: the reason given by Entra (wrong or expired secret) on one line, and a hint when the application was not added to the organization.
- New advanced setting: Microsoft Entra sign-in address (sovereign clouds).
- Update from 1.0.0: six columns are added to the projects table; existing projects stay in personal-token mode, nothing else changes.
- The PAT help now lists the **Project and Team (Read)** scope next to **Work Items (Read & write)**.

## 1.0.0
- First release: create an Azure DevOps work item from a ticket (title template, HTML description, inline images and attachments, hyperlink back to the ticket, tags, area path), optionally under an Epic / Feature; link existing work items; check them every 5 minutes and act on the ticket (followup / solution) through GLPI's own objects.
- **One access token per project**, stored encrypted (GLPI's key) and never sent back to the browser; per-project work item type, possible parents, "followup only" types, done categories.
- **Access by group and by user for each project** (or open to every technician); links and actions limited, server-side, to the projects the technician may use.
- A ticket is solved (and the requester informed) only when **all** its work items are done; Epics and Features only add a private followup. The same Epic can be linked to many tickets.
- State categories (Proposed, InProgress, Resolved, Completed, Removed) are read from the project's process template: custom states and processes work without configuration.
- A problem with one project (expired token...) is recorded for it and does not stop the others.
- English (`en_GB`, `en_US`) and French (`fr_FR`); the user's language if translated, otherwise English.
