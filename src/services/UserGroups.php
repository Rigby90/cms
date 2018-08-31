<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\errors\UserGroupNotFoundException;
use craft\errors\WrongEditionException;
use craft\events\ParseConfigEvent;
use craft\events\UserGroupEvent;
use craft\helpers\Db;
use craft\models\UserGroup;
use craft\records\UserGroup as UserGroupRecord;
use yii\base\Component;

/**
 * User Groups service.
 * An instance of the User Groups service is globally accessible in Craft via [[\craft\base\ApplicationTrait::getUserGroups()|`Craft::$app->userGroups`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class UserGroups extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event UserGroupEvent The event that is triggered before a user group is saved.
     */
    const EVENT_BEFORE_SAVE_USER_GROUP = 'beforeSaveUserGroup';

    /**
     * @event UserGroupEvent The event that is triggered after a user group is saved.
     */
    const EVENT_AFTER_SAVE_USER_GROUP = 'afterSaveUserGroup';

    /**
     * @event UserGroupEvent The event that is triggered before a user group is deleted.
     */
    const EVENT_BEFORE_DELETE_USER_GROUP = 'beforeDeleteUserGroup';

    /**
     * @event UserGroupEvent The event that is triggered after a user group is saved.
     */
    const EVENT_AFTER_DELETE_USER_GROUP = 'afterDeleteUserGroup';

    const CONFIG_USERPGROUPS_KEY = 'users.groups';

    // Public Methods
    // =========================================================================

    /**
     * Returns all user groups.
     *
     * @return UserGroup[]
     */
    public function getAllGroups(): array
    {
        $groups = UserGroupRecord::find()
            ->orderBy(['name' => SORT_ASC])
            ->all();

        foreach ($groups as $key => $value) {
            $groups[$key] = new UserGroup($value->toArray([
                'id',
                'name',
                'handle',
                'uid'
            ]));
        }

        return $groups;
    }

    /**
     * Returns the user groups that the current user is allowed to assign to another user.
     *
     * @param User|null $user The recipient of the user groups. If set, their current groups will be included as well.
     * @return UserGroup[]
     */
    public function getAssignableGroups(User $user = null): array
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        if (!$currentUser && !$user) {
            return [];
        }

        // If either user is an admin, all groups are fair game
        if (($currentUser !== null && $currentUser->admin) || ($user !== null && $user->admin)) {
            return $this->getAllGroups();
        }

        $assignableGroups = [];

        foreach ($this->getAllGroups() as $group) {
            if (
                ($currentUser !== null && (
                        $currentUser->isInGroup($group) ||
                        $currentUser->can('assignUserGroup:' . $group->uid)
                    )) ||
                ($user !== null && $user->isInGroup($group))
            ) {
                $assignableGroups[] = $group;
            }
        }

        return $assignableGroups;
    }

    /**
     * Gets a user group by its ID.
     *
     * @param int $groupId
     * @return UserGroup|null
     */
    public function getGroupById(int $groupId)
    {
        $result = $this->_createUserGroupsQuery()
            ->where(['id' => $groupId])
            ->one();

        return $result ? new UserGroup($result) : null;
    }

    /**
     * Gets a user group by its UID.
     *
     * @param string $uid
     * @return UserGroup|null
     */
    public function getGroupByUid(string $uid)
    {
        $result = $this->_createUserGroupsQuery()
            ->where(['uid' => $uid])
            ->one();

        return $result ? new UserGroup($result) : null;
    }

    /**
     * Gets a user group by its handle.
     *
     * @param string $groupHandle
     * @return UserGroup|null
     */
    public function getGroupByHandle(string $groupHandle)
    {
        $result = $this->_createUserGroupsQuery()
            ->where(['handle' => $groupHandle])
            ->one();

        return $result ? new UserGroup($result) : null;
    }

    /**
     * Gets user groups by a user ID.
     *
     * @param int $userId
     * @return UserGroup[]
     */
    public function getGroupsByUserId(int $userId): array
    {
        $groups = (new Query())
            ->select([
                'g.id',
                'g.name',
                'g.handle',
            ])
            ->from(['{{%usergroups}} g'])
            ->innerJoin('{{%usergroups_users}} gu', '[[gu.groupId]] = [[g.id]]')
            ->where(['gu.userId' => $userId])
            ->all();

        foreach ($groups as $key => $value) {
            $groups[$key] = new UserGroup($value);
        }

        return $groups;
    }

    /**
     * Saves a user group.
     *
     * @param UserGroup $group The user group to be saved
     * @param bool $runValidation Whether the user group should be validated
     * @return bool
     * @throws WrongEditionException if this is called from Craft Solo edition
     */
    public function saveGroup(UserGroup $group, bool $runValidation = true): bool
    {
        Craft::$app->requireEdition(Craft::Pro);

        $isNewGroup = !$group->id;

        // Fire a 'beforeSaveUserGroup' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_USER_GROUP)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_USER_GROUP, new UserGroupEvent([
                'userGroup' => $group,
                'isNew' => $isNewGroup,
            ]));
        }

        if ($runValidation && !$group->validate()) {
            Craft::info('User group not saved due to validation error.', __METHOD__);
            return false;
        }

        $projectConfig = Craft::$app->getProjectConfig();

        if ($isNewGroup) {
            $groupUid = StringHelper::UUID();
        } else {
            $groupUid = $group->uid;
            // Re-save the existing permissions, it's not our place to touch that.
        }

        $configPath = self::CONFIG_USERPGROUPS_KEY . '.' . $groupUid;

        // Save everything except permissions. Not ours to touch.
        $configData = [
            'name' => $group->name,
            'handle' => $group->handle
        ];

        $projectConfig->save($configPath, $configData);

        // Now that we have a group ID, save it on the model
        if ($isNewGroup) {
            $group->id = Db::idByUid('{{%usergroups}}', $groupUid);
        }

        // Fire an 'afterSaveUserGroup' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_USER_GROUP)) {
            $this->trigger(self::EVENT_AFTER_SAVE_USER_GROUP, new UserGroupEvent([
                'userGroup' => $group,
                'isNew' => $isNewGroup,
            ]));
        }

        return true;
    }

    /**
     * Handle any changed user groups.
     *
     * @param ParseConfigEvent $event
     */
    public function handleChangedUserGroup(ParseConfigEvent $event)
    {
        $path = $event->configPath;

        // Does it match a user group?
        if (preg_match('/' . self::CONFIG_USERPGROUPS_KEY . '\.(' . ProjectConfig::UID_PATTERN . ')$/i', $path, $matches)) {
            if (Craft::$app->getEdition() !== Craft::Pro) {
                Craft::$app->setEdition(Craft::Pro);
            }

            $uid = $matches[1];
            $data = $event->configData;

            $groupRecord = UserGroupRecord::findOne(['uid' => $uid]) ?? new UserGroupRecord();
            $groupRecord->name = $data['name'];
            $groupRecord->handle = $data['handle'];
            $groupRecord->uid = $uid;

            $groupRecord->save(false);

            // Prevent permission information from being saved. Allowing it would prevent the appropriate event from firing.
            $event->configData['permissions'] = $event->snapshotData['permissions'] ?? [];
        }
    }

    /**
     * Handle any deleted user groups.
     *
     * @param ParseConfigEvent $event
     */
    public function handleDeletedUserGroup(ParseConfigEvent $event)
    {
        $path = $event->configPath;

        // Does it match a user group?
        if (preg_match('/' . self::CONFIG_USERPGROUPS_KEY . '\.(' . ProjectConfig::UID_PATTERN . ')$/i', $path, $matches)) {
            $uid = $matches[1];

            Craft::$app->getDb()->createCommand()
                ->delete('{{%usergroups}}', ['uid' => $uid])
                ->execute();
        }
    }

    /**
     * Deletes a user group by its ID.
     *
     * @param int $groupId
     * @return bool
     * @throws WrongEditionException if this is called from Craft Solo edition
     */
    public function deleteGroupById(int $groupId): bool
    {
        Craft::$app->requireEdition(Craft::Pro);

        $group = $this->getGroupById($groupId);

        if (!$group) {
            return false;
        }

        // Fire a 'beforeDeleteUserGroup' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_USER_GROUP)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_USER_GROUP, new UserGroupEvent([
                'userGroup' => $group,
            ]));
        }

        Craft::$app->getProjectConfig()->save(self::CONFIG_USERPGROUPS_KEY . '.' . $group->uid, null);

        // Fire an 'afterDeleteUserGroup' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_USER_GROUP)) {
            $this->trigger(self::EVENT_AFTER_DELETE_USER_GROUP, new UserGroupEvent([
                'userGroup' => $group
            ]));
        }

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Gets a group's record.
     *
     * @param int|null $groupId
     * @return UserGroupRecord
     */
    private function _getGroupRecordById(int $groupId = null): UserGroupRecord
    {
        if ($groupId !== null) {
            $groupRecord = UserGroupRecord::findOne($groupId);

            if (!$groupRecord) {
                $this->_noGroupExists($groupId);
            }
        } else {
            $groupRecord = new UserGroupRecord();
        }

        return $groupRecord;
    }

    /**
     * Throws a "No group exists" exception.
     *
     * @param int $groupId
     * @throws UserGroupNotFoundException
     */
    private function _noGroupExists(int $groupId)
    {
        throw new UserGroupNotFoundException("No group exists with the ID '{$groupId}'");
    }

    /**
     * @return Query
     */
    private function _createUserGroupsQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'name',
                'handle',
                'uid'
            ])
            ->from(['{{%usergroups}}']);
    }
}
