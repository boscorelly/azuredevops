<?php

namespace GlpiPlugin\Azuredevops;

use CommonITILActor;
use DOMDocument;
use DOMElement;
use Document;
use Dropdown;
use Glpi\RichText\RichText;
use Html;
use Ticket;

/**
 * Builds the content of the work item from a GLPI ticket: title, HTML description (ticket data, the
 * description of the ticket with its inline images), tags, and the relations (parent, link back to the
 * ticket, attachments uploaded to Azure DevOps).
 *
 * Azure DevOps descriptions are HTML like GLPI's, so no format conversion is needed.
 */
final class ItemBuilder
{
    private const MAX_ATTACHMENTS       = 20;
    private const MAX_DESCRIPTION_CHARS = 900000; // the limit of an HTML field is 1,048,576 characters

    public function __construct(private readonly AdoClient $client) {}

    /**
     * @param array<string, mixed> $project glpi_plugin_azuredevops_projects row
     *
     * @return array{type: string, fields: array<string, mixed>, relations: array<int, array<string, mixed>>, warnings: string[]}
     */
    public function build(Ticket $ticket, array $project, int $parent_id, string $extra_tags, int $creator_id): array
    {
        $settings = Settings::all();
        $warnings = [];

        // 1. Attachments first: the inline images of the description need their Azure DevOps URL.
        $uploaded = [];
        if ($settings['upload_attachments'] === '1') {
            $uploaded = $this->uploadDocuments($ticket, (int) $settings['max_attachment_mb'], $warnings);
        }

        // 2. Description
        $body = $this->bodyHtml((string) $ticket->fields['content'], $uploaded);
        $html = $this->header($ticket, $creator_id, $settings['include_people'] === '1') . '<hr>' . $body;
        if (mb_strlen($html) > self::MAX_DESCRIPTION_CHARS) {
            // Cutting HTML in the middle would break it: keep the text only.
            $text = mb_substr(trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5)), 0, self::MAX_DESCRIPTION_CHARS - 20000);
            $html = $this->header($ticket, $creator_id, $settings['include_people'] === '1') . '<hr><p>' . nl2br(self::esc($text))
                . '</p><p><em>' . self::esc(__('(description truncated)', 'azuredevops')) . '</em></p>';
            $warnings[] = __('The ticket description was too long and has been truncated.', 'azuredevops');
        }

        // 3. Fields
        $fields = [
            'System.Title'       => $this->title($ticket, $settings['title_template']),
            'System.Description' => $html,
        ];
        $tags = $this->tags((string) ($project['default_tags'] ?? ''), $extra_tags);
        if ($tags !== '') {
            $fields['System.Tags'] = $tags;
        }
        if (!empty($project['area_path'])) {
            $fields['System.AreaPath'] = (string) $project['area_path'];
        }
        // Fields required by the process (Priority...): fixed values, or placeholders filled from the ticket.
        foreach (Project::defaultFields($project) as $reference => $value) {
            $fields[$reference] = strtr($value, [
                '{id}'       => (string) $ticket->getID(),
                '{title}'    => (string) $ticket->fields['name'],
                '{priority}' => (string) self::azurePriority((int) $ticket->fields['priority']),
            ]);
        }

        // 4. Relations: parent, link back to the ticket, attachments
        $relations = [];
        if ($parent_id > 0) {
            $relations[] = [
                'rel'        => 'System.LinkTypes.Hierarchy-Reverse',
                'url'        => $this->client->apiUrl($parent_id),
                'attributes' => ['comment' => sprintf('GLPI ticket #%d', $ticket->getID())],
            ];
        }
        $ticket_url = self::ticketUrl($ticket->getID());
        if (preg_match('#^https?://#i', $ticket_url)) {
            $relations[] = [
                'rel'        => 'Hyperlink',
                'url'        => $ticket_url,
                'attributes' => ['comment' => sprintf('GLPI ticket #%d', $ticket->getID())],
            ];
        }
        foreach ($uploaded as $doc) {
            $relations[] = ['rel' => 'AttachedFile', 'url' => $doc['url'], 'attributes' => ['comment' => $doc['name']]];
        }

        return ['type' => (string) $project['workitem_type'], 'fields' => $fields, 'relations' => $relations, 'warnings' => $warnings];
    }

    // ------------------------------------------------------------------ pieces

    /**
     * Priority of the ticket (GLPI: 1 very low ... 5 very high, 6 major) as an Azure DevOps priority
     * (1 = most urgent ... 4 = lowest), the scale of the standard processes.
     */
    public static function azurePriority(int $glpi_priority): int
    {
        return match (true) {
            $glpi_priority >= 5 => 1,
            $glpi_priority === 4 => 2,
            $glpi_priority === 3 => 3,
            default => 4,
        };
    }

    private function title(Ticket $ticket, string $template): string
    {
        $template = $template !== '' ? $template : Settings::DEFAULTS['title_template'];
        $title    = strtr($template, [
            '{id}'    => (string) $ticket->getID(),
            '{title}' => (string) $ticket->fields['name'],
        ]);
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return mb_substr($title, 0, 255);
    }

    private function tags(string $default, string $extra): string
    {
        $tags = [];
        foreach (explode(',', str_replace(';', ',', $default . ',' . $extra)) as $tag) {
            $tag = trim($tag);
            if ($tag !== '' && !isset($tags[mb_strtolower($tag)])) {
                $tags[mb_strtolower($tag)] = $tag; // first spelling wins
            }
        }

        return implode('; ', $tags);
    }

    /**
     * HTML of the ticket description: safe HTML, images pointing to the uploaded attachments, absolute links.
     *
     * @param array<int, array{url: string, name: string}> $uploaded indexed by GLPI document id
     */
    private function bodyHtml(string $content, array $uploaded): string
    {
        if ($content === '') {
            return '<p><em>' . self::esc(__('(no description)', 'azuredevops')) . '</em></p>';
        }
        if (!RichText::isRichTextHtmlContent($content)) {
            return '<p>' . nl2br(self::esc($content)) . '</p>';
        }

        $html = RichText::getSafeHtml($content);
        $dom  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="adowrap">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $origin = self::origin();
        foreach (iterator_to_array($dom->getElementsByTagName('img')) as $img) {
            /** @var DOMElement $img */
            $src = html_entity_decode($img->getAttribute('src'));
            if (preg_match('/docid=(\d+)/', $src, $m) && isset($uploaded[(int) $m[1]])) {
                $img->setAttribute('src', $uploaded[(int) $m[1]]['url']);
            } elseif (str_starts_with($src, '/') && $origin !== '') {
                $img->setAttribute('src', $origin . $src);
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('a')) as $a) {
            /** @var DOMElement $a */
            $href = $a->getAttribute('href');
            if (str_starts_with($href, '/') && !str_starts_with($href, '//') && $origin !== '') {
                $a->setAttribute('href', $origin . $href);
            }
        }

        $wrap = $dom->getElementById('adowrap') ?? $dom->documentElement;
        $out  = '';
        foreach ($wrap->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out) !== '' ? $out : '<p><em>' . self::esc(__('(no description)', 'azuredevops')) . '</em></p>';
    }

    private function header(Ticket $ticket, int $creator_id, bool $include_people): string
    {
        $id  = $ticket->getID();
        $url = self::ticketUrl($id);

        $link    = '<a href="' . self::esc($url) . '">#' . $id . '</a>';
        $creator = $creator_id > 0 ? ProjectAccess::userName($creator_id) : '';
        $when    = Html::convDateTime(date('Y-m-d H:i:s'));
        $intro   = $creator !== ''
            ? sprintf(__('Created from GLPI ticket %1$s by %2$s on %3$s', 'azuredevops'), $link, self::esc($creator), self::esc($when))
            : sprintf(__('Created from GLPI ticket %1$s on %2$s', 'azuredevops'), $link, self::esc($when));

        $rows = [
            [__('Ticket', 'azuredevops'), $link, true],
            [__('Opened on', 'azuredevops'), Html::convDateTime($ticket->fields['date']), false],
            [__('Status', 'azuredevops'), Ticket::getStatus((int) $ticket->fields['status']), false],
            [__('Priority', 'azuredevops'), Ticket::getPriorityName((int) $ticket->fields['priority']), false],
            [__('Urgency', 'azuredevops'), Ticket::getUrgencyName((int) $ticket->fields['urgency']), false],
            [__('Impact', 'azuredevops'), Ticket::getImpactName((int) $ticket->fields['impact']), false],
        ];

        $category = $this->dropdownName('glpi_itilcategories', (int) $ticket->fields['itilcategories_id']);
        if ($category !== '') {
            $rows[] = [__('Category', 'azuredevops'), $category, false];
        }
        $entity = $this->dropdownName('glpi_entities', (int) $ticket->fields['entities_id']);
        if ($entity !== '') {
            $rows[] = [__('Entity', 'azuredevops'), $entity, false];
        }

        if ($include_people) {
            $requesters = $this->actorNames($ticket, CommonITILActor::REQUESTER);
            if ($requesters !== '') {
                $rows[] = [__('Requester', 'azuredevops'), $requesters, false];
            }
            $assignees = $this->actorNames($ticket, CommonITILActor::ASSIGN);
            if ($assignees !== '') {
                $rows[] = [__('Assigned to', 'azuredevops'), $assignees, false];
            }
        }

        $table = '<table><tbody>';
        foreach ($rows as [$label, $value, $is_html]) {
            $table .= '<tr><td><b>' . self::esc((string) $label) . '</b></td><td>' . ($is_html ? $value : self::esc((string) $value)) . '</td></tr>';
        }
        $table .= '</tbody></table>';

        return '<blockquote>' . $intro . '</blockquote>' . $table;
    }

    private function actorNames(Ticket $ticket, int $type): string
    {
        $names = [];
        foreach ($ticket->getUsers($type) as $actor) {
            if ((int) $actor['users_id'] > 0) {
                $names[] = ProjectAccess::userName((int) $actor['users_id']);
            } elseif (!empty($actor['alternative_email'])) {
                $names[] = $actor['alternative_email'];
            }
        }
        foreach ($ticket->getGroups($type) as $group) {
            $name = $this->dropdownName('glpi_groups', (int) $group['groups_id']);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', array_filter($names));
    }

    private function dropdownName(string $table, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        $name = html_entity_decode(strip_tags((string) Dropdown::getDropdownName($table, $id)), ENT_QUOTES | ENT_HTML5);

        return trim(str_replace("\u{a0}", ' ', $name));
    }

    // ------------------------------------------------------------- attachments

    /**
     * Upload the documents linked to the ticket to Azure DevOps.
     *
     * @param string[] $warnings
     *
     * @return array<int, array{url: string, name: string}> indexed by GLPI document id
     */
    private function uploadDocuments(Ticket $ticket, int $max_mb, array &$warnings): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $uploaded = [];
        $max_size = max(1, $max_mb) * 1024 * 1024;
        $count    = 0;

        $iterator = $DB->request([
            'SELECT'     => ['glpi_documents.id'],
            'FROM'       => 'glpi_documents_items',
            'INNER JOIN' => ['glpi_documents' => ['FKEY' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id']]],
            'WHERE'      => ['glpi_documents_items.itemtype' => 'Ticket', 'glpi_documents_items.items_id' => $ticket->getID()],
            'GROUPBY'    => ['glpi_documents.id'],
            'ORDER'      => ['glpi_documents.id ASC'],
        ]);

        foreach ($iterator as $row) {
            $doc = new Document();
            if (!$doc->getFromDB((int) $row['id']) || empty($doc->fields['filepath'])) {
                continue; // link-only document (URL) or unreadable
            }
            $name = (string) ($doc->fields['filename'] ?: basename((string) $doc->fields['filepath']));
            $path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];

            if ($count >= self::MAX_ATTACHMENTS) {
                $warnings[] = sprintf(__('Attachment not copied (limit of %d files): %s', 'azuredevops'), self::MAX_ATTACHMENTS, $name);
                continue;
            }
            if (!is_readable($path)) {
                $warnings[] = sprintf(__('Attachment not found on disk: %s', 'azuredevops'), $name);
                continue;
            }
            if (filesize($path) > $max_size) {
                $warnings[] = sprintf(__('Attachment not copied (larger than %d MB): %s', 'azuredevops'), $max_mb, $name);
                continue;
            }

            try {
                $res = $this->client->uploadAttachment($path, $name);
                $uploaded[(int) $doc->getID()] = ['url' => $res['url'], 'name' => $name];
                $count++;
            } catch (AdoException $e) {
                // A failed upload must not prevent the creation, but the user is told.
                if ($e->isGlobal() && $e->getKind() !== AdoException::SERVER) {
                    throw $e;
                }
                $warnings[] = sprintf(__('Attachment not copied (%1$s): %2$s', 'azuredevops'), $e->getMessage(), $name);
            }
        }

        return $uploaded;
    }

    // -------------------------------------------------------------------- urls

    public static function baseUrl(): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
    }

    /** Scheme + host (+ port) of GLPI, to make the relative links of the ticket absolute. */
    private static function origin(): string
    {
        $parts = parse_url(self::baseUrl());
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public static function ticketUrl(int $id): string
    {
        // url_base already contains the GLPI root path, so the path is built without root_doc.
        return self::baseUrl() . '/front/ticket.form.php?id=' . $id;
    }

    private static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
