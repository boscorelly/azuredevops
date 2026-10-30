<?php

/**
 * Azure DevOps – GLPI plugin
 *
 * Create an Azure DevOps work item (User Story, Issue, PBI...) from a GLPI ticket, optionally under an
 * Epic or a Feature, link existing work items, then periodically check (GLPI automatic action) whether
 * the work items are done and run the configured actions on the ticket.
 *
 * @license AGPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Azuredevops\Link;
use GlpiPlugin\Azuredevops\Profile as PluginProfile;
use GlpiPlugin\Azuredevops\ProjectAccess;

define('PLUGIN_AZUREDEVOPS_VERSION', '1.1.0');

// Minimal GLPI version, inclusive
define('PLUGIN_AZUREDEVOPS_MIN_GLPI', '11.0.0');
// Maximum GLPI version, exclusive
define('PLUGIN_AZUREDEVOPS_MAX_GLPI', '11.1.0');

/**
 * Plugin initialisation: register classes and hooks.
 */
function plugin_init_azuredevops(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['azuredevops'] = true;

    if (!Plugin::isPluginActive('azuredevops')) {
        return;
    }

    // "Azure DevOps" tab on tickets, and rights tab on profiles.
    Plugin::registerClass(Link::class, ['addtabon' => ['Ticket']]);
    Plugin::registerClass(PluginProfile::class, ['addtabon' => ['Profile']]);

    // Setup > Plugins > Azure DevOps > configuration (route /plugins/azuredevops/Config,
    // access is checked by the controller).
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['azuredevops'] = 'Config';

    // Language fallback: English when the plugin has no catalogue for the user's language.
    $PLUGIN_HOOKS[Hooks::POST_INIT]['azuredevops'] = 'plugin_azuredevops_post_init';

    // Clean-up when GLPI objects are purged.
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['azuredevops'] = [
        'Ticket' => [Link::class, 'cleanForTicket'],
        'User'   => [ProjectAccess::class, 'cleanForUser'],
        'Group'  => [ProjectAccess::class, 'cleanForGroup'],
    ];
}

/**
 * Plugin metadata and requirements.
 */
function plugin_version_azuredevops(): array
{
    return [
        'name'     => 'Azure DevOps',
        'version'  => PLUGIN_AZUREDEVOPS_VERSION,
        'author'   => 'Azure DevOps plugin contributors',
        'license'  => 'AGPL-3.0-or-later',
        'homepage' => 'https://github.com/boscorelly/azuredevops',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_AZUREDEVOPS_MIN_GLPI,
                'max' => PLUGIN_AZUREDEVOPS_MAX_GLPI,
                'dev' => false,
            ],
            'php' => [
                'min'  => '8.2',
                'exts' => [
                    'curl'     => ['required' => true],
                    'dom'      => ['required' => true],
                    'mbstring' => ['required' => true],
                    'json'     => ['required' => true],
                ],
            ],
        ],
    ];
}

/**
 * Language fallback.
 *
 * GLPI loads the plugin catalogue of the user's language, else the one of the instance's DEFAULT language,
 * and only then English: on a French instance, a user whose language the plugin does not translate would get
 * French. Here the rule is: the user's language if the plugin has it, otherwise English.
 */
function plugin_azuredevops_post_init(): void
{
    /**
     * @var array $CFG_GLPI
     * @var \Laminas\I18n\Translator\Translator $TRANSLATE
     */
    global $CFG_GLPI, $TRANSLATE;

    $lang = $_SESSION['glpilanguage'] ?? ($CFG_GLPI['language'] ?? 'en_GB');
    $dir  = Plugin::getPhpDir('azuredevops') . '/locales/';
    $file = $CFG_GLPI['languages'][$lang][1] ?? ($lang . '.mo');

    if (!is_file($dir . $file) && is_file($dir . 'en_GB.mo')) {
        $TRANSLATE->addTranslationFile('gettext', $dir . 'en_GB.mo', 'azuredevops', $lang);
    }
}
