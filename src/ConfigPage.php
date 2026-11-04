<?php

namespace GlpiPlugin\Azuredevops;

use CommonGLPI;
use Session;

/**
 * Host of the configuration page: GLPI's standard page with tabs on the left, one tab per zone of settings
 * (see ConfigTabs), like the other administration pages of GLPI.
 */
class ConfigPage extends CommonGLPI
{
    public $taborientation = 'vertical';

    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return __('Azure DevOps', 'azuredevops');
    }

    public static function getIcon(): string
    {
        return Link::getIcon();
    }

    public static function getFormURL($full = true): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($full ? ($CFG_GLPI['root_doc'] ?? '') : '') . '/plugins/azuredevops/Config';
    }

    public function defineTabs($options = []): array
    {
        $tabs = [];
        $this->addStandardTab(ConfigTabs::class, $tabs, $options);

        return $tabs;
    }

    /**
     * Only people who may change the GLPI configuration see or use the page.
     */
    public static function canManage(): bool
    {
        return Session::haveRight('config', UPDATE);
    }
}
