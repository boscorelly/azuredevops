<?php
/**
 * End-to-end test on a real GLPI with the mock Azure DevOps (tests/mock_ado.py on :8098).
 * Usage: GLPI_ROOT=/path/to/glpi php tests/integration.php
 */
require __DIR__ . '/bootstrap.php';
// The assertions on messages are written in English: run_all.sh sets the instance language to en_GB for this script.

use GlpiPlugin\Azuredevops\AdoClient;
use GlpiPlugin\Azuredevops\AdoException;
use GlpiPlugin\Azuredevops\Link;
use GlpiPlugin\Azuredevops\Project;
use GlpiPlugin\Azuredevops\ProjectAccess;
use GlpiPlugin\Azuredevops\RequesterNotice;
use GlpiPlugin\Azuredevops\Settings;
use GlpiPlugin\Azuredevops\Sync;

global $DB, $PLUGIN_HOOKS;

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   - $name\n"; } else { $fail++; echo "  FAIL - $name" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}
function mock(string $path, array $body = []): array {
    $ctx = stream_context_create(['http' => ['method' => $path === '/_mock/dump' ? 'GET' : 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body), 'ignore_errors' => true]]);
    return json_decode((string) file_get_contents('http://127.0.0.1:8098' . $path, false, $ctx), true) ?? [];
}
function requests(string $needle = ''): array {
    return array_values(array_filter(mock('/_mock/dump')['requests'], fn($r) => $needle === '' || str_contains($r['method'] . ' ' . $r['path'], $needle)));
}
function item(int $id): array { return mock('/_mock/dump')['items'][(string) $id] ?? []; }
function login(): void {
    $_SESSION['glpiID'] = 2; $_SESSION['glpiname'] = 'glpi';
    $_SESSION['glpiactiveprofile'] = ['id' => 4, 'interface' => 'central', 'plugin_azuredevops_workitem' => 15, 'ticket' => 2147483647, 'config' => 3, 'profile' => 3];
    $_SESSION['glpiactive_entity'] = 0; $_SESSION['glpiactiveentities'] = [0]; $_SESSION['glpiactiveentities_string'] = "'0'";
    $_SESSION['glpi_currenttime'] = date('Y-m-d H:i:s');
    unset($_SESSION['glpicronuserrunning']);
}
function runCron(): void {
    global $DB;
    $DB->update('glpi_crontasks', ['lastrun' => null, 'state' => 1], ['name' => 'CheckWorkItems']);
    $saved = $_SESSION;
    unset($_SESSION['glpiID'], $_SESSION['glpiactiveprofile']); // a real cron run has no logged-in user
    CronTask::launch(CronTask::MODE_EXTERNAL, 1, 'CheckWorkItems');
    $_SESSION = $saved; unset($_SESSION['glpicronuserrunning']);
}
function newTicket(string $name, string $content = '<p>x</p>', int $requester = 2): Ticket {
    $t = new Ticket();
    $id = $t->add(['name' => $name, 'content' => $content, 'entities_id' => 0, '_users_id_requester' => $requester, 'itilcategories_id' => 0, 'priority' => 4, 'urgency' => 4, 'impact' => 3]);
    $t->getFromDB($id);
    return $t;
}
function followups(int $tid): array { global $DB; return array_values(iterator_to_array($DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid], 'ORDER' => 'id']))); }
function publicFollowups(int $tid): array { return array_values(array_filter(followups($tid), fn($f) => (int) $f['is_private'] === 0)); }
function privateFollowups(int $tid): array { return array_values(array_filter(followups($tid), fn($f) => (int) $f['is_private'] === 1)); }
function noticeIs(string $content, string $kind): bool { return html_entity_decode(strip_tags($content), ENT_QUOTES) === RequesterNotice::text($kind); }
function linkOf(int $id): array { global $DB; return $DB->request(['FROM' => Link::getTable(), 'WHERE' => ['id' => $id]])->current(); }
function status(int $tid): int { $t = new Ticket(); $t->getFromDB($tid); return (int) $t->fields['status']; }
function mkUser(string $name): int { global $DB; $DB->insert('glpi_users', ['name' => $name, 'realname' => ucfirst($name), 'firstname' => 'Tech', 'is_active' => 1, 'is_deleted' => 0, 'entities_id' => 0]); return (int) $DB->insertId(); }
function mkGroup(string $name): int { global $DB; $DB->insert('glpi_groups', ['name' => $name, 'completename' => $name, 'entities_id' => 0, 'is_recursive' => 1, 'level' => 1]); return (int) $DB->insertId(); }
function join_(int $u, int $g): void { global $DB; $DB->insert('glpi_groups_users', ['users_id' => $u, 'groups_id' => $g, 'is_dynamic' => 0, 'is_manager' => 0, 'is_userdelegate' => 0]); }
function names(array $projects): array { $n = array_column($projects, 'name'); sort($n); return $n; }
function proj(string $name, array $extra = []): array {
    return $extra + ['name' => $name, 'organization' => 'acme', 'ado_project' => 'Toto', 'token' => 'pat-toto', 'is_active' => 1, 'is_restricted' => 1,
        'workitem_type' => 'User Story', 'parent_types' => 'Epic,Feature', 'followup_only_types' => 'Epic,Feature', 'done_categories' => 'Completed', 'default_tags' => 'glpi'];
}

// ---------------------------------------------------------------- clean slate
mock('/_mock/reset');
foreach (['glpi_plugin_azuredevops_links', 'glpi_plugin_azuredevops_projects', 'glpi_plugin_azuredevops_projects_users', 'glpi_plugin_azuredevops_projects_groups'] as $t) { $DB->doQuery("DELETE FROM `$t`"); }
$DB->doQuery("DELETE FROM glpi_groups_users WHERE groups_id IN (SELECT id FROM glpi_groups WHERE name LIKE 'ado\\_%')");
$DB->doQuery("DELETE FROM glpi_groups WHERE name LIKE 'ado\\_%'");
$DB->doQuery("DELETE FROM glpi_users WHERE name LIKE 'ado\\_%'");
login();
Config::setConfigurationValues('core', ['use_notifications' => 1, 'notifications_mailing' => 1]);
Settings::save(['api_base_url' => 'http://127.0.0.1:8098/', 'close_followup_private' => '1'] + Settings::DEFAULTS);

echo "== Settings\n";
ok('base URL normalised (no trailing slash)', Settings::baseUrl() === 'http://127.0.0.1:8098');
ok('organization normalised', Project::normalizeOrganization('https://dev.azure.com/acme/') === 'acme'
    && Project::normalizeOrganization('dev.azure.com/acme/Toto/_boards') === 'acme'
    && Project::normalizeOrganization('acme') === 'acme'
    && Project::normalizeOrganization('https://acme.visualstudio.com/') === 'acme');
ok('identity names', AdoClient::identityName(['displayName' => 'Jane Dev']) === 'Jane Dev' && AdoClient::identityName('Jane Dev <jane@x.y>') === 'Jane Dev');

echo "== Projects: one token each, encrypted\n";
$toto = Project::saveFromInput(proj('Toto', ['organization' => 'https://dev.azure.com/acme/']));
$tata = Project::saveFromInput(proj('Tata', ['ado_project' => 'Tata', 'token' => 'pat-tata', 'workitem_type' => 'Issue', 'parent_types' => 'Epic', 'followup_only_types' => 'Epic']));
$raw = $DB->request(['FROM' => Project::getTable(), 'WHERE' => ['id' => $toto]])->current();
ok('token stored encrypted', $raw['token'] !== '' && $raw['token'] !== 'pat-toto' && !str_contains($raw['token'], 'pat-toto'));
ok('token decrypted by the plugin', Project::token($raw) === 'pat-toto');
ok('organization stored as a name', $raw['organization'] === 'acme');
ok('every project has its own token', Project::token(Project::find($tata)) === 'pat-tata' && Project::token(Project::find($toto)) === 'pat-toto');
try { Project::saveFromInput(proj('NoToken', ['token' => ''])); ok('token required on creation', false); } catch (RuntimeException $e) { ok('token required on creation', true); }
try { Project::saveFromInput(proj('Toto')); ok('duplicate name refused', false); } catch (RuntimeException $e) { ok('duplicate name refused', true); }
Project::saveFromInput(proj('Toto', ['token' => '']), $toto);
ok('empty token on edit keeps the stored one', Project::token(Project::find($toto)) === 'pat-toto');
Project::saveFromInput(proj('Toto', ['token' => '', 'done_categories' => ['Resolved']]), $toto);
ok('"Completed" is always a done category', Project::csv(Project::find($toto)['done_categories']) === ['Completed', 'Resolved']);
Project::saveFromInput(proj('Toto', ['token' => '', 'done_categories' => 'Completed']), $toto);

echo "== Connection test, types and state categories\n";
$res = Project::testAndCache($toto);
ok('test connection', $res['project'] === 'Toto' && $res['user'] === 'Bot Toto', json_encode($res));
$types = Project::typeNames(Project::find($toto));
ok('types read (disabled ones left out)', $types === ['Epic', 'Feature', 'User Story', 'Task'], json_encode($types));
ok('state categories from the type list', Project::stateCategory(Project::find($toto), 'User Story', 'Closed') === 'Completed'
    && Project::stateCategory(Project::find($toto), 'User Story', 'Resolved') === 'Resolved'
    && Project::stateCategory(Project::find($toto), 'Epic', 'Removed') === 'Removed');
Project::testAndCache($tata);
ok('states read from the /states endpoint when the type list has none', Project::stateCategory(Project::find($tata), 'Issue', 'Done') === 'Completed'
    && Project::stateCategory(Project::find($tata), 'Issue', 'Doing') === 'InProgress' && count(requests('GET /acme/Tata/_apis/wit/workitemtypes/Issue/states')) >= 1);
ok('unknown type: usual names guessed', Project::stateCategory(Project::find($tata), 'Nope', 'Done') === 'Completed' && Project::stateCategory(Project::find($tata), 'Nope', 'Whatever') === null);

$c = new AdoClient('http://127.0.0.1:8098', 'acme', 'Toto', 'sup3r-secret-bad');
try { $c->testConnection(); ok('bad token', false); } catch (AdoException $e) { ok('bad token -> AUTH, token not leaked', $e->getKind() === AdoException::AUTH && !str_contains($e->getMessage(), 'sup3r-secret-bad') && !str_contains($e->getMessage(), base64_encode(':sup3r-secret-bad'))); }
mock('/_mock/token', ['mode' => 'html']);
try { $c->testConnection(); ok('sign-in page', false); } catch (AdoException $e) { ok('203 + sign-in page -> AUTH (not a JSON error)', $e->getKind() === AdoException::AUTH && str_contains($e->getMessage(), 'sign-in'), $e->getMessage()); }
mock('/_mock/token', ['mode' => '401']);
try { (new AdoClient('http://127.0.0.1:9', 'acme', 'Toto', 'x'))->testConnection(); ok('unreachable', false); } catch (AdoException $e) { ok('unreachable -> NETWORK', $e->getKind() === AdoException::NETWORK); }
try { (new AdoClient('http://127.0.0.1:8098', 'acme', 'Nope', 'pat-toto'))->testConnection(); ok('unknown project', false); } catch (AdoException $e) { ok('unknown project -> NOT_FOUND', $e->getKind() === AdoException::NOT_FOUND); }
try { new AdoClient('http://127.0.0.1:8098', 'acme', 'Toto', ''); ok('missing token', false); } catch (AdoException $e) { ok('missing token -> CONFIG', $e->getKind() === AdoException::CONFIG); }
ok('every request to the API carried the Basic credentials', count(array_filter(requests(), fn($x) => !$x['auth'])) === 0);

echo "== Access by user and group\n";
$g1 = mkGroup('ado_group1'); $g2 = mkGroup('ado_group2'); $g3 = mkGroup('ado_group3');
$ua = mkUser('ado_a'); $ub = mkUser('ado_b'); $uc = mkUser('ado_c'); $ud = mkUser('ado_d'); $ue = mkUser('ado_e');
join_($ua, $g1); join_($ub, $g2); join_($uc, $g1); join_($uc, $g2); join_(2, $g1); join_(2, $g2);
ProjectAccess::set($toto, [], [$g1]);                 // group 1 -> Toto
ProjectAccess::set($tata, [$ue], [$g2]);              // group 2 + user e -> Tata
$open  = Project::saveFromInput(proj('Open', ['is_restricted' => 0]));
$ghost = Project::saveFromInput(proj('Ghost'));        // restricted, nobody listed
$off   = Project::saveFromInput(proj('Off', ['is_active' => 0, 'is_restricted' => 0]));
$for = fn(int $u) => names(ProjectAccess::projectsForUser($u));
ok('group 1 -> Toto (+ the open project)', $for($ua) === ['Open', 'Toto'], json_encode($for($ua)));
ok('group 2 -> Tata (+ open)', $for($ub) === ['Open', 'Tata'], json_encode($for($ub)));
ok('groups 1 and 2 -> both projects', $for($uc) === ['Open', 'Tata', 'Toto'], json_encode($for($uc)));
ok('no group -> only the open project', $for($ud) === ['Open'], json_encode($for($ud)));
ok('user listed directly -> Tata', $for($ue) === ['Open', 'Tata'], json_encode($for($ue)));
ok('restricted project without anybody: nobody, inactive project: nobody', !in_array('Ghost', $for($uc)) && !in_array('Off', $for($uc)));
ok('canUse() agrees', ProjectAccess::canUse(Project::find($toto), $ua) && !ProjectAccess::canUse(Project::find($toto), $ub) && !ProjectAccess::canUse(Project::find($ghost), 2) && !ProjectAccess::canUse(Project::find($off), 2));
ok('users and groups listed back by name', ProjectAccess::groups($tata) === [$g2 => 'ado_group2'] && array_keys(ProjectAccess::users($tata)) === [$ue]);
ProjectAccess::set($tata, [$ue, $ue, 0], [$g2, $g3]);
ok('set() de-duplicates and ignores empty ids', count(ProjectAccess::users($tata)) === 1 && count(ProjectAccess::groups($tata)) === 2);
ProjectAccess::set($tata, [$ue], [$g2]);

mock('/_mock/seed', ['id' => 500, 'project' => 'Toto', 'type' => 'Epic', 'title' => 'Epic Alpha']);
mock('/_mock/seed', ['id' => 501, 'project' => 'Toto', 'type' => 'Feature', 'title' => 'Feature Beta', 'parent' => 500]);
mock('/_mock/seed', ['id' => 502, 'project' => 'Toto', 'type' => 'Epic', 'title' => "Epic O'Neil"]);
mock('/_mock/seed', ['id' => 600, 'project' => 'Tata', 'type' => 'Epic', 'title' => 'Epic Gamma']);
mock('/_mock/seed', ['id' => 700, 'project' => 'Toto', 'type' => 'User Story', 'title' => 'Existing story']);
mock('/_mock/seed', ['id' => 701, 'project' => 'Toto', 'type' => 'Task', 'title' => 'A task']);
mock('/_mock/seed', ['id' => 702, 'project' => 'Toto', 'type' => 'User Story', 'title' => 'Done story', 'state' => 'Closed']);
mock('/_mock/seed', ['id' => 703, 'project' => 'Toto', 'type' => 'User Story', 'title' => 'Removed story', 'state' => 'Removed']);

$creates = fn() => count(requests('POST /acme/')) - count(requests('POST /acme/_apis/wit/workitemsbatch')) - count(requests('/wiql')) - count(requests('/attachments'));
$tx = newTicket('Acces refuse');
$before = $creates();
try { Link::createFromTicket($tx, $tata, $ua); ok('group-1 user cannot create in Tata', false); } catch (RuntimeException $e) { ok('group-1 user cannot create in Tata', true); }
try { Link::linkExisting($tx, $tata, 600, $ua); ok('nor link there', false); } catch (RuntimeException $e) { ok('nor link a work item there', true); }
try { Link::createFromTicket($tx, $ghost, 2); ok('nobody can use a restricted project without members', false); } catch (RuntimeException $e) { ok('nobody can use a restricted project without members', true); }
try { Link::createFromTicket($tx, $off, 2); ok('inactive project refused', false); } catch (RuntimeException $e) { ok('inactive project refused', true); }
ok('refused before anything reaches Azure DevOps', $creates() === $before && countElementsInTable(Link::getTable(), ['tickets_id' => $tx->getID()]) === 0);

echo "== Work item creation (under an Epic / Feature)\n";
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
@mkdir(GLPI_DOC_DIR . '/PNG/zz', 0777, true); @mkdir(GLPI_DOC_DIR . '/TXT/zz', 0777, true);
file_put_contents(GLPI_DOC_DIR . '/PNG/zz/shot.png', $png);
file_put_contents(GLPI_DOC_DIR . '/TXT/zz/notes.txt', "log line\n");
$t1 = newTicket('Imprimante "hors service" <étage 2>', '<p>Bonjour <strong>équipe</strong>,</p><ul><li>bac vide</li><li>voyant rouge</li></ul><p><img src="/front/document.send.php?docid=DOCID&amp;itemtype=Ticket" alt="photo"></p><p><a href="/front/computer.form.php?id=3">PC</a></p>');
$d1 = new Document(); $img_id = $d1->add(['name' => 'shot', 'filename' => 'shot.png', 'filepath' => 'PNG/zz/shot.png', 'mime' => 'image/png', 'entities_id' => 0]);
$d2 = new Document(); $txt_id = $d2->add(['name' => 'notes', 'filename' => 'notes.txt', 'filepath' => 'TXT/zz/notes.txt', 'mime' => 'text/plain', 'entities_id' => 0]);
$DB->update('glpi_documents', ['filepath' => 'PNG/zz/shot.png'], ['id' => $img_id]);
$DB->update('glpi_documents', ['filepath' => 'TXT/zz/notes.txt'], ['id' => $txt_id]);
foreach ([$img_id, $txt_id] as $did) { (new Document_Item())->add(['documents_id' => $did, 'itemtype' => 'Ticket', 'items_id' => $t1->getID(), 'entities_id' => 0]); }
$DB->update('glpi_tickets', ['content' => str_replace('DOCID', (string) $img_id, $t1->fields['content'])], ['id' => $t1->getID()]);
$t1->getFromDB($t1->getID());

$r = Link::createFromTicket($t1, $toto, 2, 501, 'urgent, GLPI');
$link1 = $r['link'];
$wi = item((int) $link1->fields['workitem_id']);
$f = $wi['fields'] ?? [];
ok('work item created in the right project and type', ($f['System.TeamProject'] ?? '') === 'Toto' && ($f['System.WorkItemType'] ?? '') === 'User Story', json_encode($f));
$post = requests('POST /acme/Toto/_apis/wit/workitems/$User Story');
ok('JSON Patch sent to the project endpoint, authenticated', count($post) === 1 && str_contains($post[0]['ctype'], 'application/json-patch+json') && $post[0]['auth'] === true && str_contains($post[0]['query'], 'api-version=7.1'), json_encode($post));
ok('title uses the template', ($f['System.Title'] ?? '') === '[GLPI #' . $t1->getID() . '] Imprimante "hors service" <étage 2>', $f['System.Title'] ?? '');
ok('tags merged without duplicates', ($f['System.Tags'] ?? '') === 'glpi; urgent', $f['System.Tags'] ?? '');
$desc = $f['System.Description'] ?? '';
ok('description is HTML with the ticket data', str_contains($desc, '<table>') && str_contains($desc, '/front/ticket.form.php?id=' . $t1->getID()) && str_contains($desc, '<strong>équipe</strong>') && str_contains($desc, '<li>bac vide</li>'), $desc);
ok('inline image points to the uploaded attachment', (bool) preg_match('#<img[^>]+src="http://127\.0\.0\.1:8098/acme/_apis/wit/attachments/[0-9a-f-]{36}\?fileName=shot\.png"#', $desc), $desc);
ok('relative GLPI links made absolute', str_contains($desc, 'href="http://localhost/front/computer.form.php?id=3"'), $desc);
$rels = $wi['relations'] ?? [];
$by = fn(string $rel) => array_values(array_filter($rels, fn($x) => $x['rel'] === $rel));
ok('parent link (Feature 501)', count($by('System.LinkTypes.Hierarchy-Reverse')) === 1 && str_ends_with($by('System.LinkTypes.Hierarchy-Reverse')[0]['url'], '/_apis/wit/workItems/501') && ($f['System.Parent'] ?? 0) === 501);
ok('hyperlink back to the ticket', count($by('Hyperlink')) === 1 && $by('Hyperlink')[0]['url'] === 'http://localhost/glpi/front/ticket.form.php?id=' . $t1->getID(), json_encode($by('Hyperlink')));
ok('both documents attached', count($by('AttachedFile')) === 2 && array_column(array_column($by('AttachedFile'), 'attributes'), 'comment') === ['shot.png', 'notes.txt'], json_encode($by('AttachedFile')));
$att = requests('POST /acme/Toto/_apis/wit/attachments');
ok('uploads are raw octet-stream, to the project', count($att) === 2 && str_contains($att[0]['ctype'], 'octet-stream'));
$row = linkOf($link1->getID());
ok('link stored', $row['state'] === 'opened' && (int) $row['tickets_id'] === $t1->getID() && (int) $row['projects_id'] === $toto && $row['workitem_type'] === 'User Story' && (int) $row['is_created'] === 1 && (int) $row['parent_id'] === 501 && $row['ado_state'] === 'New');
$chain = Link::chainOf($row);
ok('parent chain stored root first (Epic > Feature)', array_column($chain, 'id') === [500, 501] && array_column($chain, 'type') === ['Epic', 'Feature'], json_encode($chain));
ok('work item URL', $row['url'] === 'http://127.0.0.1:8098/acme/_workitems/edit/' . $row['workitem_id'], (string) $row['url']);
ok('private creation followup with the link', count($fu = followups($t1->getID())) === 1 && (int) $fu[0]['is_private'] === 1 && str_contains($fu[0]['content'], 'User Story #' . $row['workitem_id']));

echo "== Parent checks\n";
$n0 = $creates();
$bad = function (string $label, int $parent, string $needle = '') use ($t1, $toto, $n0, $creates): void {
    try { Link::createFromTicket(newTicket('parent ' . $label), $toto, 2, $parent); ok($label, false); }
    catch (RuntimeException $e) { ok($label, $needle === '' || str_contains($e->getMessage(), $needle), $e->getMessage()); }
};
$bad('parent of a type that is not allowed (User Story)', 700, 'cannot be used as a parent');
$bad('parent of another Azure DevOps project', 600, 'another Azure DevOps project');
$bad('unknown parent', 9999, 'not found');
ok('nothing created for refused parents', $creates() === $n0);
Project::saveFromInput(proj('Toto', ['token' => '', 'parent_types' => 'Epic,Feature,Task']), $toto);
try { Link::createFromTicket($tz = newTicket('refused by Azure DevOps'), $toto, 2, 701); ok('hierarchy rule of the process (rejected by Azure DevOps)', false); }
catch (AdoException $e) { ok('hierarchy rule of the process: Azure DevOps message shown, no link', $e->getKind() === AdoException::REQUEST && str_contains($e->getMessage(), 'TF26194') && countElementsInTable(Link::getTable(), ['tickets_id' => $tz->getID()]) === 0, $e->getMessage()); }
Project::saveFromInput(proj('Toto', ['token' => '']), $toto);

echo "== Several projects, duplicates, followup modes\n";
try { Link::createFromTicket($t1, $toto, 2); ok('duplicate blocked', false); } catch (RuntimeException $e) { ok('second created work item in the same project blocked', true); }
$r2 = Link::createFromTicket($t1, $tata, 2, 600);
ok('other project allowed, under its own Epic', $r2['link']->fields['workitem_type'] === 'Issue' && item((int) $r2['link']->fields['workitem_id'])['fields']['System.TeamProject'] === 'Tata' && (int) $r2['link']->fields['parent_id'] === 600);
ok('Tata uses its own token', count(requests('POST /acme/Tata/_apis/wit/workitems/$Issue')) === 1);
$r3 = Link::createFromTicket($t1, $toto, 2, 0, '', true);
ok('"create another" allowed on request, without parent', (int) $r3['link']->fields['parent_id'] === 0 && $r3['link']->getID() !== $link1->getID());
$t8 = newTicket('Choix du suivi');
Link::createFromTicket($t8, $toto, 2, 0, '', true, 'public');
ok('explicit "public": public followup', count($f8 = followups($t8->getID())) === 1 && (int) $f8[0]['is_private'] === 0);
Link::createFromTicket($t8, $toto, 2, 0, '', true, 'none');
ok('explicit "none": no followup', count(followups($t8->getID())) === 1);
Settings::save(['creation_followup_private' => '0']);
ok('default mode follows the setting', Link::defaultFollowupMode() === 'public');
Settings::save(['creation_followup_private' => '1', 'creation_followup' => '0']);
ok('followup disabled -> none', Link::defaultFollowupMode() === 'none');
Settings::save(['creation_followup' => '1']);

echo "== Neutral message for the requester at creation\n";
Settings::save(['notify_requester' => '0', 'notify_requester_text' => '']);
$t13 = newTicket('Info demandeur');
Link::createFromTicket($t13, $toto, 2, 0, '', true, 'private', true);
$pub = publicFollowups($t13->getID());
ok('notify on: one neutral public followup, no Azure DevOps detail', count($pub) === 1 && noticeIs($pub[0]['content'], RequesterNotice::CREATION) && !str_contains($pub[0]['content'], 'http') && !str_contains($pub[0]['content'], 'Toto'), json_encode($pub));
ok('technical followup stays private', count(privateFollowups($t13->getID())) === 1);
$t14 = newTicket('Info demandeur off');
Settings::save(['notify_requester' => '1']);
Link::createFromTicket($t14, $toto, 2, 0, '', true, 'private', false);
ok('technician unticks the box: nothing public', publicFollowups($t14->getID()) === []);
Settings::save(['notify_requester' => '0']);

echo "== Link an existing work item\n";
$te1 = newTicket('Ticket epic 1'); $te2 = newTicket('Ticket epic 2');
$le1 = Link::linkExisting($te1, $toto, 500, 2)['link'];
ok('Epic linked, not created by the plugin', $le1->fields['workitem_type'] === 'Epic' && (int) $le1->fields['is_created'] === 0 && $le1->fields['state'] === 'opened');
$le2 = Link::linkExisting($te2, $toto, 500, 2)['link'];
ok('the same Epic can be linked to several tickets', countElementsInTable(Link::getTable(), ['workitem_id' => 500]) === 2);
ok('trace followup says "linked"', str_contains(privateFollowups($te1->getID())[0]['content'], 'Epic #500'));
try { Link::linkExisting($te1, $toto, 500, 2); ok('already linked', false); } catch (RuntimeException $e) { ok('already linked to this ticket: refused', true); }
try { Link::linkExisting($te1, $toto, 600, 2); ok('other project', false); } catch (RuntimeException $e) { ok('work item of another project refused', str_contains($e->getMessage(), 'another Azure DevOps project')); }
try { Link::linkExisting($te1, $toto, 9999, 2); ok('unknown', false); } catch (RuntimeException $e) { ok('unknown work item refused', str_contains($e->getMessage(), 'not found')); }
try { Link::linkExisting($te1, $toto, 703, 2); ok('removed', false); } catch (RuntimeException $e) { ok('work item in a Removed state refused', str_contains($e->getMessage(), 'Removed')); }
$td = newTicket('Deja fait');
$res = Link::linkExisting($td, $toto, 702, 2);
ok('already done: linked for reference (closed), warning shown', $res['link']->fields['state'] === 'closed' && $res['warnings'] !== []);
$nfu = count(followups($td->getID()));
runCron();
ok('already-done link is not followed: no action on the ticket', count(followups($td->getID())) === $nfu && status($td->getID()) !== Ticket::SOLVED);
$tl = newTicket('Lie a une story');
$ls = Link::linkExisting($tl, $toto, 700, 2)['link'];
ok('existing User Story linked (no parent chain)', $ls->fields['workitem_type'] === 'User Story' && Link::chainOf($ls->fields) === []);
$feat = Link::linkExisting(newTicket('Lie a la feature'), $toto, 501, 2)['link'];
ok('existing Feature linked with its Epic as chain', array_column(Link::chainOf($feat->fields), 'id') === [500], json_encode(Link::chainOf($feat->fields)));

echo "== Search (parents and existing items)\n";
$cl = Project::client(Project::find($toto));
ok('search by title', array_keys($cl->searchWorkItems(['Epic', 'Feature'], 'alpha')) === [500]);
ok('search by number', array_keys($cl->searchWorkItems(['Epic', 'Feature'], '501')) === [501]);
ok('search with an apostrophe (escaped)', array_keys($cl->searchWorkItems(['Epic'], "O'Neil")) === [502]);
ok('search limited to the given types', array_keys($cl->searchWorkItems(['Epic'], 'Beta')) === []);
ok('empty term: most recent first, only requested types', array_keys($cl->searchWorkItems(['Epic', 'Feature'], '')) === [502, 501, 500]);
ok('the Tata project does not see Toto items', array_keys(Project::client(Project::find($tata))->searchWorkItems(['Epic'], '')) === [600]);

echo "== Cron: nothing to do while work items are open\n";
runCron();
$row = linkOf($link1->getID());
ok('still opened, checked, state read', $row['state'] === 'opened' && $row['date_last_check'] !== null && $row['ado_state'] === 'New' && $row['state_category'] === 'Proposed');
$n_t1 = count(followups($t1->getID()));   // one trace followup per work item created for this ticket
ok('no extra followup', $n_t1 === 3);
mock('/_mock/seed', ['id' => 500, 'project' => 'Toto', 'type' => 'Epic', 'title' => 'Epic Alpha (renamed)']);
runCron();
ok('title kept in sync', str_contains((string) linkOf($le1->getID())['title'], 'renamed'));

echo "== Cron: work item done -> private followup, ticket untouched\n";
$wid1 = (int) $link1->fields['workitem_id'];
mock('/_mock/state', ['id' => $wid1, 'state' => 'Closed', 'by' => 'Jane Dev']);
$before = status($t1->getID());
runCron();
$row = linkOf($link1->getID());
ok('link closed with who and when (Closed fields of the process)', $row['state'] === 'closed' && $row['closed_by'] === 'Jane Dev' && $row['date_closed'] !== null && $row['state_category'] === 'Completed', json_encode($row));
$fu = followups($t1->getID()); $last = end($fu);
ok('private followup with state and author', count($fu) === $n_t1 + 1 && (int) $last['is_private'] === 1 && str_contains($last['content'], 'Jane Dev') && str_contains($last['content'], 'User Story #' . $wid1) && str_contains($last['content'], 'Closed'), json_encode($last['content'] ?? ''));
ok('ticket status unchanged (solve option off)', status($t1->getID()) === $before);
runCron();
ok('idempotent: no duplicate followup', count(followups($t1->getID())) === $n_t1 + 1);

echo "== Cron: the ticket is solved when ALL its work items are done\n";
Settings::save(['close_solve_ticket' => '1', 'notify_requester_close' => '0', 'solution_template' => '']);
$tm = newTicket('Deux projets');
$la = Link::createFromTicket($tm, $toto, 2, 0, '', true, 'none')['link'];
$lb = Link::createFromTicket($tm, $tata, 2, 0, '', true, 'none')['link'];
$le = Link::linkExisting($tm, $toto, 502, 2, 'none')['link'];   // an Epic: never blocks, never solves
mock('/_mock/state', ['id' => (int) $la->fields['workitem_id'], 'state' => 'Closed', 'by' => 'Ann']);
runCron();
ok('first work item done: ticket NOT solved (the other one is open)', linkOf($la->getID())['state'] === 'closed' && status($tm->getID()) !== Ticket::SOLVED);
$q_before = countElementsInTable('glpi_queuednotifications');
mock('/_mock/state', ['id' => (int) $lb->fields['workitem_id'], 'state' => 'Done', 'by' => 'Bob Tata']);
runCron();
$rowb = linkOf($lb->getID());
ok('last work item done: ticket solved', $rowb['state'] === 'closed' && status($tm->getID()) === Ticket::SOLVED, 'status ' . status($tm->getID()));
ok('process without "closed" fields: falls back on the last change', $rowb['closed_by'] === 'Bob Tata' && $rowb['date_closed'] !== null, json_encode($rowb['closed_by']));
$sol = $DB->request(['FROM' => 'glpi_itilsolutions', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tm->getID()]])->current();
ok('the solution is the neutral message (no project, no developer)', $sol && noticeIs($sol['content'], RequesterNotice::CLOSURE) && !str_contains($sol['content'], 'Bob') && !str_contains($sol['content'], 'Toto'), $sol['content'] ?? 'none');
ok('GLPI notifications queued (automatic actions chain works)', countElementsInTable('glpi_queuednotifications') > $q_before);
ok('the open Epic did not prevent it, and is still followed', linkOf($le->getID())['state'] === 'opened');
Settings::save(['close_solve_ticket' => '0']);

echo "== Cron: Epics only add a private followup\n";
Settings::save(['close_solve_ticket' => '1', 'notify_requester_close' => '1', 'close_followup' => '1', 'close_followup_private' => '0']);
mock('/_mock/state', ['id' => 500, 'state' => 'Closed', 'by' => 'Pat Product']);
runCron();
foreach ([$te1, $te2] as $tt) {
    $priv = privateFollowups($tt->getID());
    ok('Epic done on ticket ' . $tt->getID() . ': private followup (even if the setting says public), ticket untouched, nothing for the requester',
        count($priv) === 2 && str_contains(end($priv)['content'], 'Epic #500') && publicFollowups($tt->getID()) === [] && status($tt->getID()) !== Ticket::SOLVED);
}
ok('both Epic links closed', linkOf($le1->getID())['state'] === 'closed' && linkOf($le2->getID())['state'] === 'closed');
Settings::save(['close_solve_ticket' => '0', 'notify_requester_close' => '0', 'close_followup_private' => '1']);

echo "== Cron: neutral message for the requester only once everything is done\n";
Settings::save(['notify_requester_close' => '1', 'close_solve_ticket' => '0']);
$tn = newTicket('Info fermeture');
$l1 = Link::createFromTicket($tn, $toto, 2, 0, '', true, 'none', false)['link'];
$l2 = Link::createFromTicket($tn, $tata, 2, 0, '', true, 'none', false)['link'];
mock('/_mock/state', ['id' => (int) $l1->fields['workitem_id'], 'state' => 'Closed', 'by' => 'Jane Dev']);
runCron();
ok('first done: nothing public yet', publicFollowups($tn->getID()) === []);
mock('/_mock/state', ['id' => (int) $l2->fields['workitem_id'], 'state' => 'Done', 'by' => 'Joe']);
runCron();
$pub = publicFollowups($tn->getID());
ok('all done: one neutral public followup', count($pub) === 1 && noticeIs($pub[0]['content'], RequesterNotice::CLOSURE) && !str_contains($pub[0]['content'], 'Jane'));
ok('details (state, who) stay in the private followups', count($pr = privateFollowups($tn->getID())) === 2 && str_contains($pr[0]['content'], 'Jane Dev'));
Settings::save(['notify_requester_close' => '0']);

echo "== Cron: moved / removed / missing / hidden\n";
$t3 = newTicket('Deplace'); $l3 = Link::createFromTicket($t3, $toto, 2, 0, '', true, 'none')['link'];
mock('/_mock/move', ['id' => (int) $l3->fields['workitem_id'], 'project' => 'Tata']);
runCron();
ok('moved to another project: state moved, ticket not solved, technicians informed', linkOf($l3->getID())['state'] === 'moved' && status($t3->getID()) !== Ticket::SOLVED && str_contains(privateFollowups($t3->getID())[0]['content'], 'Tata'));
$t4 = newTicket('Retire'); $l4 = Link::createFromTicket($t4, $toto, 2, 0, '', true, 'none')['link'];
mock('/_mock/state', ['id' => (int) $l4->fields['workitem_id'], 'state' => 'Removed']);
Settings::save(['close_solve_ticket' => '1']);
runCron();
ok('Removed category: state removed, ticket NOT solved, followup', linkOf($l4->getID())['state'] === 'removed' && status($t4->getID()) !== Ticket::SOLVED && count(privateFollowups($t4->getID())) === 1);
Settings::save(['close_solve_ticket' => '0']);
$t5 = newTicket('Supprime'); $l5 = Link::createFromTicket($t5, $toto, 2, 0, '', true, 'none')['link'];
mock('/_mock/delete', ['id' => (int) $l5->fields['workitem_id']]);
runCron(); $s1 = linkOf($l5->getID()); runCron(); runCron(); $s3 = linkOf($l5->getID());
ok('deleted: still opened after 1 miss (counter)', $s1['state'] === 'opened' && (int) $s1['error_count'] === 1);
ok('deleted: missing after 3 misses + technicians informed', $s3['state'] === 'missing' && count(privateFollowups($t5->getID())) === 1);
$t6 = newTicket('Masque'); $l6 = Link::createFromTicket($t6, $toto, 2, 0, '', true, 'none')['link'];
mock('/_mock/hide', ['id' => (int) $l6->fields['workitem_id']]);
runCron();
ok('unreadable once: counted', (int) linkOf($l6->getID())['error_count'] === 1);
mock('/_mock/hide', ['id' => (int) $l6->fields['workitem_id'], 'hidden' => false]);
runCron();
ok('readable again: counter reset, still followed', linkOf($l6->getID())['state'] === 'opened' && (int) linkOf($l6->getID())['error_count'] === 0 && linkOf($l6->getID())['last_error'] === null);
$t7 = newTicket('Clos entre-temps'); $l7 = Link::createFromTicket($t7, $toto, 2, 0, '', true, 'none')['link'];
$DB->update('glpi_tickets', ['status' => Ticket::CLOSED], ['id' => $t7->getID()]);
mock('/_mock/state', ['id' => (int) $l7->fields['workitem_id'], 'state' => 'Closed']);
runCron();
ok('closed ticket: link closed, ticket untouched', linkOf($l7->getID())['state'] === 'closed' && followups($t7->getID()) === [] && status($t7->getID()) === Ticket::CLOSED);

echo "== Cron: a state added to the process after the last test\n";
$t10 = newTicket('Etat inconnu'); $l10 = Link::createFromTicket($t10, $tata, 2, 0, '', true, 'none')['link'];
$DB->update(Project::getTable(), ['types_cache' => json_encode(['types' => []])], ['id' => $tata]);
mock('/_mock/state', ['id' => (int) $l10->fields['workitem_id'], 'state' => 'Doing']);
runCron();
ok('unknown state: types read again, category known, still followed', linkOf($l10->getID())['state_category'] === 'InProgress' && linkOf($l10->getID())['state'] === 'opened' && Project::typeNames(Project::find($tata)) !== []);

echo "== Cron: one project's problem does not stop the others\n";
$ti = newTicket('Isolation');
$lt = Link::createFromTicket($ti, $toto, 2, 0, '', true, 'none')['link'];
$lu = Link::createFromTicket($ti, $tata, 2, 0, '', true, 'none')['link'];
mock('/_mock/state', ['id' => (int) $lt->fields['workitem_id'], 'state' => 'Closed']);
mock('/_mock/state', ['id' => (int) $lu->fields['workitem_id'], 'state' => 'Done']);
Project::saveFromInput(proj('Toto', ['token' => 'expired-token']), $toto);
runCron();
ok('expired token on Toto: its link stays opened with the error', linkOf($lt->getID())['state'] === 'opened' && str_contains((string) linkOf($lt->getID())['last_error'], '401'), (string) linkOf($lt->getID())['last_error']);
ok('error recorded on the project (shown in the configuration)', str_contains((string) Project::find($toto)['last_error'], '401'));
ok('the other project (other token) is still checked', linkOf($lu->getID())['state'] === 'closed');
mock('/_mock/token', ['mode' => 'html']);
runCron();
ok('sign-in page instead of the API: clear error', str_contains((string) linkOf($lt->getID())['last_error'], 'sign-in'), (string) linkOf($lt->getID())['last_error']);
mock('/_mock/token', ['mode' => '401']);
Project::saveFromInput(proj('Toto', ['token' => 'pat-toto']), $toto);
ok('new token: the previous error is cleared', Project::find($toto)['last_error'] === null);
runCron();
ok('new token: pending closure processed', linkOf($lt->getID())['state'] === 'closed' && linkOf($lt->getID())['last_error'] === null);

echo "== Cron: rate limit / server errors\n";
$tr = newTicket('Limite'); $lr = Link::createFromTicket($tr, $toto, 2, 0, '', true, 'none')['link'];
mock('/_mock/state', ['id' => (int) $lr->fields['workitem_id'], 'state' => 'Closed']);
mock('/_mock/fail', ['status' => 429, 'retry_after' => 30]);
$stats = Sync::run(200);
ok('429: run aborted, nothing lost', $stats['aborted'] === true && linkOf($lr->getID())['state'] === 'opened' && str_contains((string) linkOf($lr->getID())['last_error'], '429'));
mock('/_mock/fail', ['status' => 500]);
$stats = Sync::run(200);
ok('500: error recorded, stays opened', linkOf($lr->getID())['state'] === 'opened' && str_contains((string) linkOf($lr->getID())['last_error'], '500') && $stats['errors'] >= 1);
mock('/_mock/fail', ['status' => 0]);
runCron();
ok('back to normal: pending closure processed', linkOf($lr->getID())['state'] === 'closed' && linkOf($lr->getID())['last_error'] === null);

echo "== Cron: inactive project is not followed\n";
$tj = newTicket('Inactif'); $lj = Link::createFromTicket($tj, $toto, 2, 0, '', true, 'none')['link'];
mock('/_mock/state', ['id' => (int) $lj->fields['workitem_id'], 'state' => 'Closed']);
Project::saveFromInput(proj('Toto', ['token' => '', 'is_active' => 0]), $toto);
runCron();
ok('deactivated: not checked', linkOf($lj->getID())['state'] === 'opened');
Project::saveFromInput(proj('Toto', ['token' => '']), $toto);
runCron();
ok('reactivated: processed', linkOf($lj->getID())['state'] === 'closed');

echo "== Cron: failure while running the actions -> rolled back, retried\n";
$tf = newTicket('Echec des actions'); $lf = Link::createFromTicket($tf, $toto, 2, 0, '', true, 'none')['link'];
mock('/_mock/state', ['id' => (int) $lf->fields['workitem_id'], 'state' => 'Closed']);
$nf = count(followups($tf->getID()));
$PLUGIN_HOOKS['pre_item_add']['azuredevops'] = ['ITILFollowup' => static function ($item) { $item->input = false; }];
runCron();
unset($PLUGIN_HOOKS['pre_item_add']);
$row = linkOf($lf->getID());
ok('failed actions: link NOT marked closed (rolled back)', $row['state'] === 'opened' && (int) $row['error_count'] === 0, $row['state']);
ok('failed actions: error recorded, ticket untouched', str_contains((string) $row['last_error'], 'followup') && count(followups($tf->getID())) === $nf, (string) $row['last_error']);
runCron();
ok('retried at the next run once the problem is gone', linkOf($lf->getID())['state'] === 'closed' && count(followups($tf->getID())) === $nf + 1);

echo "== Refresh button: the actions do not depend on the technician who clicks\n";
Settings::save(['close_solve_ticket' => '1', 'close_followup' => '1', 'close_followup_private' => '1']);
$po = (int) $DB->request(['FROM' => 'glpi_users', 'WHERE' => ['name' => 'post-only']])->current()['id'];
$t18 = newTicket('Refresh solve', '<p>x</p>', $po);
$l18 = Link::createFromTicket($t18, $toto, 2, 0, '', true, 'none', false)['link'];
mock('/_mock/state', ['id' => (int) $l18->fields['workitem_id'], 'state' => 'Closed', 'by' => 'Zoe Dev']);
login();
$label = Sync::refresh($l18);
ok('refresh from a user session: link closed and ticket solved', linkOf($l18->getID())['state'] === 'closed' && status($t18->getID()) === Ticket::SOLVED, $label . ' / status ' . status($t18->getID()));
ok('refresh: the user session is restored', ($_SESSION['glpiID'] ?? 0) === 2 && !isset($_SESSION['glpicronuserrunning']));
$l19 = Link::createFromTicket($t19 = newTicket('Refresh chain'), $toto, 2, 0, '', true, 'none', false)['link'];
mock('/_mock/parent', ['id' => (int) $l19->fields['workitem_id'], 'parent' => 501]);
Sync::refresh($l19);
ok('refresh rebuilds the parent chain', array_column(Link::chainOf(linkOf($l19->getID())), 'id') === [500, 501] && (int) linkOf($l19->getID())['parent_id'] === 501);
Settings::save(['close_solve_ticket' => '0']);

echo "== Links visible only through the allowed projects\n";
$tv = newTicket('Visibilite');
$vt = Link::createFromTicket($tv, $toto, 2, 0, '', true, 'none')['link'];
$vu = Link::createFromTicket($tv, $tata, 2, 0, '', true, 'none')['link'];
ok('group 1 sees only the Toto link', array_column(Link::visibleLinks($tv->getID(), $ua), 'id') === [$vt->getID()]);
ok('group 2 sees only the Tata link', array_column(Link::visibleLinks($tv->getID(), $ub), 'id') === [$vu->getID()]);
ok('both groups see both', count(Link::visibleLinks($tv->getID(), $uc)) === 2);
ok('nobody-in-a-group sees none', Link::visibleLinks($tv->getID(), $ud) === []);
ok('refresh / unlink are reserved to the allowed projects', Link::canUseProjectOf($vt->fields, $ua) && !Link::canUseProjectOf($vt->fields, $ub) && Link::canUseProjectOf($vu->fields, $ub));

echo "== Rights\n";
ok('self-service interface is refused whatever the right', (function () { $s = $_SESSION; $_SESSION['glpiactiveprofile']['interface'] = 'helpdesk'; $r = !Link::canView() && !Link::canCreate(); $_SESSION = $s; return $r; })());
ok('technician with the right passes', Link::canView() && Link::canCreate() && Link::canUpdate() && Link::canDelete());
Link::saveProfileRights(4, [CREATE]);
ok('ticking a right also grants read', (int) $DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => 4, 'name' => Link::$rightname]])->current()['rights'] === (READ | CREATE));
Link::saveProfileRights(4, [READ, CREATE, UPDATE, DELETE]);

echo "== Clean-up: purge, projects, groups\n";
$tid = $tv->getID();
$tv->delete(['id' => $tid], true);
ok('links removed when the ticket is purged', countElementsInTable(Link::getTable(), ['tickets_id' => $tid]) === 0);
$gtmp = mkGroup('ado_tmp'); $utmp = mkUser('ado_tmp');
ProjectAccess::set($off, [$utmp], [$gtmp]);
(new Group())->delete(['id' => $gtmp], true); (new User())->delete(['id' => $utmp], true);
ok('access rows removed when a group / a user is purged', ProjectAccess::groups($off) === [] && ProjectAccess::users($off) === []);
try { Project::remove($toto); ok('project with links', false); } catch (RuntimeException $e) { ok('a project with linked tickets cannot be deleted', Project::find($toto) !== null); }
Project::remove($ghost);
ok('an unused project can be deleted (with its access rows)', Project::find($ghost) === null);
ok('log written by the cron', $DB->request(['FROM' => 'glpi_crontasklogs', 'ORDER' => 'id DESC', 'LIMIT' => 1])->current() !== null);

file_put_contents('/tmp/ado_last_ticket_id', (string) $t1->getID());
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
