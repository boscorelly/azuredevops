<?php

namespace GlpiPlugin\Azuredevops;

use CommonGLPI;

/**
 * Host of the page of a project: GLPI's standard page with tabs on the left, one per zone of settings (see ProjectTabs).
 * The project is the "id" of the page; id 0 is a new project (only its connection can be filled in at first).
 */
class ProjectPage extends CommonGLPI
{
    public $taborientation = 'vertical';

    public static $rightname = 'config';

    public static function getTypeName($nb = 0): string
    {
        return _n('Azure DevOps project', 'Azure DevOps projects', $nb, 'azuredevops');
    }

    public static function getIcon(): string
    {
        return 'ti ti-folder';
    }

    public function defineTabs($options = []): array
    {
        $tabs = [];
        $this->addStandardTab(ProjectTabs::class, $tabs, $options);

        return $tabs;
    }
}
