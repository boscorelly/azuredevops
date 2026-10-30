<?php

namespace GlpiPlugin\Azuredevops;

/**
 * Neutral public messages for the requester of the ticket, who is often not a technician and never
 * sees the Azure DevOps tab: they say that the request was forwarded / completed, with no Azure DevOps detail
 * (no project, no developer name, no link). The technical details stay in private followups.
 *
 * Language: like the rest of the plugin, the language of the interface (the technician's when he creates
 * the issue, the instance's default language for the automatic action) if the plugin has a catalogue for
 * it, otherwise English. The administrator can replace the texts in the configuration.
 */
final class RequesterNotice
{
    public const CREATION = 'creation';
    public const CLOSURE  = 'closure';

    public static function defaultText(string $kind): string
    {
        return $kind === self::CREATION
            ? __('Your request has been taken into account by the technical team and forwarded to the people in charge of the development. You will be informed of the outcome.', 'azuredevops')
            : __('The technical work related to your request is complete.', 'azuredevops');
    }

    /**
     * Text chosen by the administrator, or the default one. Plain text.
     */
    public static function text(string $kind): string
    {
        $custom = trim(Settings::get($kind === self::CREATION ? 'notify_requester_text' : 'notify_requester_close_text'));

        return $custom !== '' ? $custom : self::defaultText($kind);
    }

    /**
     * Same text as HTML for a followup or a solution (the administrator's text is escaped).
     */
    public static function html(string $kind): string
    {
        return '<p>' . nl2br(htmlspecialchars(self::text($kind), ENT_QUOTES, 'UTF-8')) . '</p>';
    }
}
