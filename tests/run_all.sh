#!/bin/sh
# Full test run on a GLPI 11 test instance (MariaDB, GLPI web server on :8080) with the Azure DevOps mock.
#   1. start the mock:      python3 tests/mock_ado.py 8098 &
#   2. start the web app:   (cd $GLPI_ROOT && php -S 127.0.0.1:8080 -t public public/index.php) &
#   3. deploy the plugin into $GLPI_ROOT/plugins/azuredevops, install and activate it
#   4. sh tests/run_all.sh
set -e
D=$(cd "$(dirname "$0")" && pwd)
export GLPI_ROOT="${GLPI_ROOT:-/opt/glpi}"
lang() { mysql -uroot glpi -e "update glpi_configs set value='$1' where context='core' and name='language'"; }
lang en_GB   # messages are asserted in English by integration.php
php "$D/integration.php" | grep -v '^  ok' | tail -12
lang fr_FR   # the web test also checks the French and the English-fallback catalogues
python3 "$D/http_test.py" "$(cat /tmp/ado_last_ticket_id)" | grep -v '^  ok' | tail -14
python3 "$D/ui_fields_test.py" "$(cat /tmp/ado_last_ticket_id)" | grep -v '^  ok' | tail -6
(cd "$GLPI_ROOT" && sh "$D/upgrade_test.sh" | grep -v '^  ok')
