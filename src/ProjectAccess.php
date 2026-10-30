<?php

namespace GlpiPlugin\Azuredevops;

use Group;
use User;

/**
 * Who may use which configured project.
 *
 * A project is either "open" (is_restricted = 0: every technician who has the plugin's "create" right can
 * use it) or restricted (default): only the users listed for the project, and the members of the groups
 * listed for the project, can use it. A restricted project without any user or group is usable by nobody.
 * Technicians who belong to several allowed groups simply get the union of the projects.
 */
final class ProjectAccess
{
    public const TABLE_USERS  = 'glpi_plugin_azuredevops_projects_users';
    public const TABLE_GROUPS = 'glpi_plugin_azuredevops_projects_groups';

    /**
     * Can this user work with the project (create / link work items, see their details)?
     *
     * @param array<string, mixed> $project glpi_plugin_azuredevops_projects row
     */
    public static function canUse(array $project, int $users_id): bool
    {
        if (empty($project['is_active'])) {
            return false;
        }
        if (empty($project['is_restricted'])) {
            return true;
        }

        return in_array((int) $project['id'], self::projectIdsForUser($users_id), true);
    }

    /**
     * Ids of the (restricted) projects explicitly granted to the user, directly or through a group.
     *
     * @return int[]
     */
    public static function projectIdsForUser(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        if ($users_id <= 0) {
            return [];
        }

        $ids = [];
        foreach ($DB->request(['SELECT' => ['projects_id'], 'FROM' => self::TABLE_USERS, 'WHERE' => ['users_id' => $users_id]]) as $row) {
            $ids[(int) $row['projects_id']] = true;
        }
        $groups = self::groupIdsOfUser($users_id);
        if ($groups !== []) {
            foreach ($DB->request(['SELECT' => ['projects_id'], 'FROM' => self::TABLE_GROUPS, 'WHERE' => ['groups_id' => $groups]]) as $row) {
                $ids[(int) $row['projects_id']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Active projects the user can use, indexed by id, ordered by name.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function projectsForUser(int $users_id): array
    {
        $granted  = self::projectIdsForUser($users_id);
        $projects = [];
        foreach (Project::all(true) as $project) {
            if (empty($project['is_restricted']) || in_array((int) $project['id'], $granted, true)) {
                $projects[(int) $project['id']] = $project;
            }
        }

        return $projects;
    }

    /**
     * Direct membership (GLPI groups are not expanded to their sub-groups: list every group that needs access).
     *
     * @return int[]
     */
    public static function groupIdsOfUser(int $users_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $users_id]]) as $row) {
            $ids[] = (int) $row['groups_id'];
        }

        return $ids;
    }

    /**
     * @return array<int, string> users_id => name
     */
    public static function users(int $projects_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $users = [];
        foreach ($DB->request(['SELECT' => ['users_id'], 'FROM' => self::TABLE_USERS, 'WHERE' => ['projects_id' => $projects_id]]) as $row) {
            $users[(int) $row['users_id']] = self::userName((int) $row['users_id']);
        }
        asort($users, SORT_NATURAL | SORT_FLAG_CASE);

        return $users;
    }

    /**
     * @return array<int, string> groups_id => name
     */
    public static function groups(int $projects_id): array
    {
        /** @var \DBmysql $DB */
        global $DB;

        $groups = [];
        foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => self::TABLE_GROUPS, 'WHERE' => ['projects_id' => $projects_id]]) as $row) {
            $groups[(int) $row['groups_id']] = self::groupName((int) $row['groups_id']);
        }
        asort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        return $groups;
    }

    /**
     * Replace the users and groups of a project.
     *
     * @param array<int|string> $users_ids
     * @param array<int|string> $groups_ids
     */
    public static function set(int $projects_id, array $users_ids, array $groups_ids): void
    {
        /** @var \DBmysql $DB */
        global $DB;

        $DB->delete(self::TABLE_USERS, ['projects_id' => $projects_id]);
        $DB->delete(self::TABLE_GROUPS, ['projects_id' => $projects_id]);

        foreach (array_unique(array_filter(array_map('intval', $users_ids))) as $id) {
            $DB->insert(self::TABLE_USERS, ['projects_id' => $projects_id, 'users_id' => $id]);
        }
        foreach (array_unique(array_filter(array_map('intval', $groups_ids))) as $id) {
            $DB->insert(self::TABLE_GROUPS, ['projects_id' => $projects_id, 'groups_id' => $id]);
        }
    }

    public static function clearProject(int $projects_id): void
    {
        self::set($projects_id, [], []);
    }

    /** item_purge hook */
    public static function cleanForUser(User $user): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->delete(self::TABLE_USERS, ['users_id' => $user->getID()]);
    }

    /** item_purge hook */
    public static function cleanForGroup(Group $group): void
    {
        /** @var \DBmysql $DB */
        global $DB;
        $DB->delete(self::TABLE_GROUPS, ['groups_id' => $group->getID()]);
    }

    public static function userName(int $users_id): string
    {
        $name = trim(html_entity_decode(strip_tags((string) getUserName($users_id)), ENT_QUOTES | ENT_HTML5));

        return $name !== '' ? $name : '#' . $users_id;
    }

    public static function groupName(int $groups_id): string
    {
        $group = new Group();
        if ($group->getFromDB($groups_id)) {
            return (string) ($group->fields['completename'] ?? $group->fields['name']);
        }

        return '#' . $groups_id;
    }
}
