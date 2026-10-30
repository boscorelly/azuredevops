# Security policy

## Supported versions
Only the latest release is supported.

## Reporting a vulnerability
Please **do not open a public issue** for a security problem. Use the private vulnerability reporting of the repository host (for GitHub: the **Security** tab > **Report a vulnerability**), or contact the maintainers privately.

Include the plugin and GLPI versions, and the steps to reproduce. Never post an Azure DevOps personal access token, an Entra client secret or a GLPI session in an issue, a log or a screenshot. If you did, revoke the token immediately (Azure DevOps > User settings > Personal access tokens).

## Scope notes
- Each project's credentials (access token, or Entra client secret and the access token obtained from Entra) are stored encrypted with GLPI's key (`glpicrypt.key`: back it up with the database) and are never sent back to the browser or written to the logs.
- Give each token the least privilege (**Work Items (Read & write)**, **Project and Team (Read)**), with a short expiry. Prefer an Entra application to a personal token: it survives the departure of a person; give it access to the one project it serves, and renew its client secret before it expires.
- Rights are checked server-side on every action, and every action is also restricted to the projects the technician may use; forms use GLPI's CSRF protection.
