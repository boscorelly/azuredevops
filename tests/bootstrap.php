<?php
// Boots GLPI in CLI mode for integration tests: php tests/xxx.php (GLPI_ROOT env var)
$root = getenv('GLPI_ROOT') ?: '/opt/glpi-env/glpi';
chdir($root);
require $root . '/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel(null);
$kernel->boot();
