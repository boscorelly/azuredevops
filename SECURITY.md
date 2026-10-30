# Security policy

## Supported versions
Only the latest release is supported.

## Reporting a vulnerability
Please **do not open a public issue** for a security problem. Use the private vulnerability reporting of the repository host (for GitHub: the **Security** tab > **Report a vulnerability**), or contact the maintainers privately.

Include the plugin and GLPI versions, and the steps to reproduce. Never post an Azure DevOps personal access token or a GLPI session in an issue, a log or a screenshot. If you did, revoke the token immediately (Azure DevOps > User settings > Personal access tokens).

## Scope notes
- Each project's access token is stored encrypted with GLPI's key (`glpicrypt.key`: back it up with the database) and is never sent back to the browser or written to the logs.
- Give each token the least privilege: the **Work Items (Read & write)** scope only, with a short expiry.
- Rights are checked server-side on every action, and every action is also restricted to the projects the technician may use; forms use GLPI's CSRF protection.
