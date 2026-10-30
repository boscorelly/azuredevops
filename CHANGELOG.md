# Changelog

Release checklist: bump `PLUGIN_AZUREDEVOPS_VERSION` in `setup.php`, add the version (compatibility and `download_url`) to `azuredevops.xml`, update this file, tag and publish a release with the `glpi-azuredevops-<version>.tar.bz2` archive.

## 1.0.0
- First release: create an Azure DevOps work item from a ticket (title template, HTML description, inline images and attachments, hyperlink back to the ticket, tags, area path), optionally under an Epic / Feature; link existing work items; check them every 5 minutes and act on the ticket (followup / solution) through GLPI's own objects.
- **One access token per project**, stored encrypted (GLPI's key) and never sent back to the browser; per-project work item type, possible parents, "followup only" types, done categories.
- **Access by group and by user for each project** (or open to every technician); links and actions limited, server-side, to the projects the technician may use.
- A ticket is solved (and the requester informed) only when **all** its work items are done; Epics and Features only add a private followup. The same Epic can be linked to many tickets.
- State categories (Proposed, InProgress, Resolved, Completed, Removed) are read from the project's process template: custom states and processes work without configuration.
- A problem with one project (expired token...) is recorded for it and does not stop the others.
- English (`en_GB`, `en_US`) and French (`fr_FR`); the user's language if translated, otherwise English.
