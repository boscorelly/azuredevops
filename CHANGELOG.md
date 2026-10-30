# Changelog

Release checklist: bump `PLUGIN_AZUREDEVOPS_VERSION` in `setup.php`, add the version (compatibility and `download_url`) to `azuredevops.xml`, update this file, tag and publish a release with the `glpi-azuredevops-<version>.tar.bz2` archive.

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
