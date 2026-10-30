<?php

namespace GlpiPlugin\Azuredevops;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Profile as CoreProfile;
use ProfileRight;
use Session;

/**
 * "Azure DevOps" tab on profiles: lets administrators choose who can use the plugin.
 */
class Profile extends CommonGLPI
{
    public static $rightname = 'profile';

    public static function getTypeName($nb = 0): string
    {
        return Link::getTypeName($nb);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string|array
    {
        if ($item instanceof CoreProfile && $item->getField('interface') === 'central' && Session::haveRight('profile', READ)) {
            // Same Azure DevOps icon as the ticket tab (the class has no getIcon() of its own).
            return self::createTabEntry(Link::getTypeName(Session::getPluralNumber()), 0, null, Link::getIcon());
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if ($item instanceof CoreProfile) {
            self::showForProfile($item);

            return true;
        }

        return false;
    }

    public static function showForProfile(CoreProfile $profile): void
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $current = (int) (ProfileRight::getProfileRights($profile->getID(), [Link::$rightname])[Link::$rightname] ?? 0);
        $rights  = [];
        foreach ((new Link())->getRights() as $value => $label) {
            $rights[] = ['value' => $value, 'label' => $label, 'checked' => (bool) ($current & $value)];
        }

        TemplateRenderer::getInstance()->display('@azuredevops/profile.html.twig', [
            'profile_id' => $profile->getID(),
            'rights'     => $rights,
            'can_edit'   => Session::haveRight('profile', UPDATE),
            'base'       => ($CFG_GLPI['root_doc'] ?? '') . '/plugins/azuredevops',
        ]);
    }
}
