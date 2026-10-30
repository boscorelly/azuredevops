#!/bin/sh
# Simulates an update from an older installed version and checks that nothing is lost (projects, tokens, access lists,
# links, rights chosen by the administrators). Needs the plugin installed with some data (run integration.php first).
GLPI_ROOT="${GLPI_ROOT:-/opt/glpi}"
cd "$GLPI_ROOT" || exit 2
Q="mysql -uroot glpi -N -e"
fail=0
check() { if [ "$2" = "$3" ]; then echo "  ok   - $1"; else echo "  FAIL - $1 [got '$2', expected '$3']"; fail=1; fi; }

projects=$($Q "select count(*) from glpi_plugin_azuredevops_projects")
links=$($Q "select count(*) from glpi_plugin_azuredevops_links")
access=$($Q "select (select count(*) from glpi_plugin_azuredevops_projects_users)+(select count(*) from glpi_plugin_azuredevops_projects_groups)")
tokens=$($Q "select md5(group_concat(token order by id)) from glpi_plugin_azuredevops_projects")
echo "before: $projects projects, $links links, $access access rows"

# 1.0.0 had none of the Entra columns
$Q "alter table glpi_plugin_azuredevops_projects drop column auth_mode, drop column tenant_id, drop column client_id, drop column client_secret, drop column access_token, drop column access_token_expires, drop column default_fields, drop column offered_types, drop column restrict_types"
$Q "update glpi_profilerights set rights=1 where name='plugin_azuredevops_workitem' and profiles_id=4; delete from glpi_configs where context='plugin:azuredevops' and name='creation_followup_private'; update glpi_plugins set version='1.0.0' where directory='azuredevops';"
rm -rf files/_cache/*
php bin/console plugin:install azuredevops --username=glpi --allow-superuser -n >/dev/null 2>&1   # first call only marks it "to update"
php bin/console plugin:install azuredevops --username=glpi --allow-superuser -n >/dev/null 2>&1
php bin/console plugin:activate azuredevops --allow-superuser -n >/dev/null 2>&1

check "plugin active (state 1) and version current" "$($Q "select concat(state,'/',version) from glpi_plugins where directory='azuredevops'")" "1/$(sed -n "s/.*define('PLUGIN_AZUREDEVOPS_VERSION', '\(.*\)');.*/\1/p" plugins/azuredevops/setup.php)"
check "rights chosen by the administrators are kept" "$($Q "select rights from glpi_profilerights where name='plugin_azuredevops_workitem' and profiles_id=4")" "1"
check "missing setting added back" "$($Q "select count(*) from glpi_configs where context='plugin:azuredevops' and name='creation_followup_private'")" "1"
check "one automatic action" "$($Q "select count(*) from glpi_crontasks where itemtype like '%Azuredevops%'")" "1"
check "the Entra and default-fields columns are added back" "$($Q "select count(*) from information_schema.columns where table_schema=database() and table_name='glpi_plugin_azuredevops_projects' and column_name in ('auth_mode','tenant_id','client_id','client_secret','access_token','access_token_expires','default_fields','offered_types','restrict_types')")" "9"
check "existing projects are in personal-token mode" "$($Q "select count(*) from glpi_plugin_azuredevops_projects where auth_mode='pat'")" "$projects"
check "projects kept" "$($Q "select count(*) from glpi_plugin_azuredevops_projects")" "$projects"
check "tokens untouched" "$($Q "select md5(group_concat(token order by id)) from glpi_plugin_azuredevops_projects")" "$tokens"
check "access lists kept" "$($Q "select (select count(*) from glpi_plugin_azuredevops_projects_users)+(select count(*) from glpi_plugin_azuredevops_projects_groups)")" "$access"
check "links kept" "$($Q "select count(*) from glpi_plugin_azuredevops_links")" "$links"
$Q "update glpi_profilerights set rights=15 where name='plugin_azuredevops_workitem' and profiles_id=4"
exit $fail
