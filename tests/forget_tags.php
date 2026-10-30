<?php
// php tests/forget_tags.php <projects_id>  -> forgets the tags kept in GLPI's cache for that project (used by the HTTP tests)
require __DIR__ . '/bootstrap.php';
\GlpiPlugin\Azuredevops\Project::forgetTags((int) ($argv[1] ?? 0));
echo "forgotten\n";
