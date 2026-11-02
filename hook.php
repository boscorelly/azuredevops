<?php

/**
 * Install / uninstall hooks of the Azure DevOps plugin.
 *
 * @license AGPL-3.0-or-later
 */

use GlpiPlugin\Azuredevops\Link;
use GlpiPlugin\Azuredevops\Project;
use GlpiPlugin\Azuredevops\ProjectAccess;
use GlpiPlugin\Azuredevops\Settings;
use GlpiPlugin\Azuredevops\Sync;

/**
 * Install the plugin: tables, automatic action, rights, default settings.
 */
function plugin_azuredevops_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $migration = new Migration(PLUGIN_AZUREDEVOPS_VERSION);

    $charset  = DBConnection::getDefaultCharset();
    $collate  = DBConnection::getDefaultCollation();
    $key_sign = DBConnection::getDefaultPrimaryKeySignOption();

    // This function also runs on every plugin update (GLPI calls it again when the version
    // changes): everything below must be idempotent and must never overwrite admin choices.

    // ---- Configured Azure DevOps projects (one access token per project) ------
    $table = Project::getTable();
    if (!$DB->tableExists($table)) {
        $migration->displayMessage("Installing $table");
        $DB->doQuery(
            "CREATE TABLE `$table` (
                `id`                  INT {$key_sign} NOT NULL AUTO_INCREMENT,
                `name`                VARCHAR(255) NOT NULL,
                `organization`        VARCHAR(255) NOT NULL DEFAULT '',
                `ado_project`         VARCHAR(255) NOT NULL DEFAULT '',
                `token`               TEXT DEFAULT NULL,
                `is_active`           TINYINT NOT NULL DEFAULT '1',
                `is_restricted`       TINYINT NOT NULL DEFAULT '1',
                `workitem_type`       VARCHAR(128) NOT NULL DEFAULT '',
                `parent_types`        VARCHAR(255) NOT NULL DEFAULT 'Epic,Feature',
                `followup_only_types` VARCHAR(255) NOT NULL DEFAULT 'Epic,Feature',
                `done_categories`     VARCHAR(255) NOT NULL DEFAULT 'Completed',
                `area_path`           VARCHAR(512) DEFAULT NULL,
                `default_tags`        VARCHAR(255) NOT NULL DEFAULT 'glpi',
                `types_cache`         MEDIUMTEXT DEFAULT NULL,
                `last_error`          TEXT DEFAULT NULL,
                `date_last_check`     TIMESTAMP NULL DEFAULT NULL,
                `date_creation`       TIMESTAMP NULL DEFAULT NULL,
                `date_mod`            TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC"
        );
    }

    // ---- Who may use which project -------------------------------------------
    if (!$DB->tableExists(ProjectAccess::TABLE_USERS)) {
        $DB->doQuery(
            "CREATE TABLE `" . ProjectAccess::TABLE_USERS . "` (
                `id`          INT {$key_sign} NOT NULL AUTO_INCREMENT,
                `projects_id` INT {$key_sign} NOT NULL DEFAULT '0',
                `users_id`    INT {$key_sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`projects_id`, `users_id`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC"
        );
    }
    if (!$DB->tableExists(ProjectAccess::TABLE_GROUPS)) {
        $DB->doQuery(
            "CREATE TABLE `" . ProjectAccess::TABLE_GROUPS . "` (
                `id`          INT {$key_sign} NOT NULL AUTO_INCREMENT,
                `projects_id` INT {$key_sign} NOT NULL DEFAULT '0',
                `groups_id`   INT {$key_sign} NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`projects_id`, `groups_id`),
                KEY `groups_id` (`groups_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC"
        );
    }

    // ---- Links between tickets and work items ---------------------------------
    $table = Link::getTable();
    $first_install = !$DB->tableExists($table);
    if ($first_install) {
        $migration->displayMessage("Installing $table");
        $DB->doQuery(
            "CREATE TABLE `$table` (
                `id`              INT {$key_sign} NOT NULL AUTO_INCREMENT,
                `tickets_id`      INT {$key_sign} NOT NULL DEFAULT '0',
                `users_id`        INT {$key_sign} NOT NULL DEFAULT '0',
                `projects_id`     INT {$key_sign} NOT NULL DEFAULT '0',
                `workitem_id`     BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `workitem_type`   VARCHAR(128) DEFAULT NULL,
                `title`           VARCHAR(255) DEFAULT NULL,
                `url`             VARCHAR(1024) DEFAULT NULL,
                `ado_project`     VARCHAR(255) DEFAULT NULL,
                `ado_state`       VARCHAR(128) DEFAULT NULL,
                `state_category`  VARCHAR(32) DEFAULT NULL,
                `parent_id`       BIGINT UNSIGNED NOT NULL DEFAULT '0',
                `parent_chain`    TEXT DEFAULT NULL,
                `is_created`      TINYINT NOT NULL DEFAULT '1',
                `state`           VARCHAR(16) NOT NULL DEFAULT 'opened',
                `date_closed`     TIMESTAMP NULL DEFAULT NULL,
                `closed_by`       VARCHAR(255) DEFAULT NULL,
                `date_last_check` TIMESTAMP NULL DEFAULT NULL,
                `error_count`     INT {$key_sign} NOT NULL DEFAULT '0',
                `last_error`      TEXT DEFAULT NULL,
                `date_creation`   TIMESTAMP NULL DEFAULT NULL,
                `date_mod`        TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unicity` (`tickets_id`, `projects_id`, `workitem_id`),
                KEY `projects_id` (`projects_id`),
                KEY `workitem_id` (`workitem_id`),
                KEY `state_check` (`state`, `date_last_check`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC"
        );
    }

    // 1.0.1: Microsoft Entra application as an alternative to the personal access token.
    // The same code serves the first installation and the update from 1.0.0.
    $projects_table = Project::getTable();
    foreach ([
        'auth_mode'            => "VARCHAR(8) NOT NULL DEFAULT 'pat'",
        'tenant_id'            => 'VARCHAR(255) DEFAULT NULL',
        'client_id'            => 'VARCHAR(255) DEFAULT NULL',
        'client_secret'        => 'TEXT DEFAULT NULL',
        'access_token'         => 'TEXT DEFAULT NULL',
        'access_token_expires' => "INT {$key_sign} NOT NULL DEFAULT '0'",
        // 1.0.3: values for the fields the process requires (Priority...)
        'default_fields'       => 'TEXT DEFAULT NULL',
        // 1.0.5: other types of work item the technician can choose in the ticket
        'offered_types'        => "VARCHAR(512) NOT NULL DEFAULT ''",
        // 1.0.6: 0 = every type read from Azure DevOps is offered in the ticket, 1 = only the default one and offered_types
        'restrict_types'       => "TINYINT NOT NULL DEFAULT '0'",
        // 1.0.7: 1 = only tags that already exist in Azure DevOps are sent (accounts that cannot create tags)
        'restrict_tags'        => "TINYINT NOT NULL DEFAULT '0'",
        // 1.0.8: required fields (as detected in Azure DevOps) that are not asked in the ticket: "Type: Reference.Name" lines
        'hidden_fields'        => 'TEXT DEFAULT NULL',
    ] as $field => $definition) {
        if (!$DB->fieldExists($projects_table, $field)) {
            $DB->doQuery("ALTER TABLE `$projects_table` ADD COLUMN `$field` $definition");
        }
    }

    // Future schema changes go here, written as "if the column/table is missing, add it"
    // (use $DB->fieldExists() / $migration->addField()): GLPI gives no "previous version" to the
    // install function, so each step must detect by itself whether it is needed.

    // ---- Settings ---------------------------------------------------------------
    $existing = Config::getConfigurationValues(Settings::CONTEXT);
    $missing  = array_diff_key(Settings::DEFAULTS, $existing);
    if ($missing !== []) {
        Config::setConfigurationValues(Settings::CONTEXT, $missing);
    }

    // ---- Rights --------------------------------------------------------------
    // ProfileRight::addProfileRights() inserts blindly (it fails when rerun on an update):
    // only create the rows that are missing.
    $existing_profiles = array_column(
        iterator_to_array($DB->request(['SELECT' => ['profiles_id'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => Link::$rightname]])),
        'profiles_id'
    );
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => Profile::getTable()]) as $profile) {
        if (!in_array($profile['id'], $existing_profiles)) {
            $DB->insert('glpi_profilerights', ['profiles_id' => $profile['id'], 'name' => Link::$rightname]);
        }
    }
    // First installation only: profiles that can already update tickets get the plugin rights by
    // default. On an update the rights chosen by administrators in Administration > Profiles are kept.
    if ($first_install) {
        foreach ($DB->request([
            'SELECT' => ['profiles_id'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'ticket', 'rights' => ['&', UPDATE]],
        ]) as $row) {
            ProfileRight::updateProfileRights(
                (int) $row['profiles_id'],
                [Link::$rightname => READ | CREATE | UPDATE | DELETE]
            );
        }
    }

    // ---- Automatic action: check the work items every 5 minutes ---------------
    CronTask::register(
        Sync::class,
        'CheckWorkItems',
        5 * MINUTE_TIMESTAMP,
        [
            'comment' => 'Check the Azure DevOps work items linked to tickets and run the actions when they are done',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
            'param'   => Sync::DEFAULT_BATCH_SIZE,
        ]
    );

    $migration->executeMigration();

    return true;
}

/**
 * Uninstall the plugin and remove all its data (including the stored access tokens).
 */
function plugin_azuredevops_uninstall(): bool
{
    $migration = new Migration(PLUGIN_AZUREDEVOPS_VERSION);
    $migration->displayMessage('Uninstalling Azure DevOps');

    foreach ([Link::getTable(), ProjectAccess::TABLE_USERS, ProjectAccess::TABLE_GROUPS, Project::getTable()] as $table) {
        $migration->dropTable($table);
    }
    $migration->executeMigration();

    $config = new Config();
    $config->deleteByCriteria(['context' => Settings::CONTEXT]);

    ProfileRight::deleteProfileRights([Link::$rightname]);

    // CronTask::unregister('azuredevops') is called by GLPI after this function.

    return true;
}
