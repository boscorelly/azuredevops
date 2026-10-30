# Contributing

Bug reports and pull requests are welcome.

## Before opening an issue
Give the GLPI version, the plugin version, the process template of the Azure DevOps project (Agile, Scrum, Basic, CMMI or a custom one), and the relevant lines of `files/_log/azuredevops.log` and `files/_log/php-errors.log`. Remove tokens and personal data first.

## Pull requests
- Target GLPI 11.0.x. Keep the code PSR-12 style, with PHP 8.2+ features allowed.
- User-visible strings go through `__('…', 'azuredevops')`; add the French translation in `locales/fr_FR.po` and run `python3 tools/locales.py` (it compiles the `.mo` files with `msgfmt`).
- Add or update tests in `tests/` (`run_all.sh` needs a GLPI 11 test instance, MariaDB, GLPI served on :8080 and the Azure DevOps API mock `tests/mock_ado.py`).
- Installation code in `hook.php` runs again on every update: keep it idempotent and never overwrite administrators' settings or rights.
- Do not rename `Project` into a `CommonDBTM` subclass: its helper names clash with `CommonDBTM` methods.

## What the mock cannot prove
The tests run against `tests/mock_ado.py`, written from the documented behaviour of the API, not against the real service. When trying the plugin on a sandbox project, check in particular:
- the states of a work item type are read, with their category (the list of types, or the `/states` endpoint of the type, depending on the API version);
- the batch read (`workitemsbatch` with `errorPolicy=Omit`) leaves out deleted or unreadable work items;
- a refused or expired token is reported as an authentication error (Azure DevOps may answer `203` with its sign-in page rather than `401`);
- who completed a work item and when (the "closed by/date" fields do not exist in every process; the plugin falls back on the last change);
- parent creation (`System.LinkTypes.Hierarchy-Reverse`) and attachment upload.

## Releasing (maintainers)
Bump `PLUGIN_AZUREDEVOPS_VERSION` in `setup.php`, add the version (compatibility and `download_url`) to `azuredevops.xml`, update `CHANGELOG.md`, then publish a release whose tag equals the version. The release workflow builds and attaches `glpi-azuredevops-<version>.tar.bz2` and `.zip`.

## License
By contributing you agree that your contribution is licensed under AGPL-3.0-or-later.
