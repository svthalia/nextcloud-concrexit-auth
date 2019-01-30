<?php
namespace OCA\ConcrexitAuth;

use OCP\Group\Backend\ABackend;
use OCP\Group\Backend\ICountUsersBackend;
use OCP\Group\Backend\IGroupDetailsBackend;
use OCP\Group\Backend\IAddToGroupBackend;
use OCP\Group\Backend\IRemoveFromGroupBackend;
use OCA\ConcrexitAuth\ApiUtil;

class GroupBackend extends ABackend implements ICountUsersBackend, IGroupDetailsBackend, IAddToGroupBackend, IRemoveFromGroupBackend {
    private $config;
    private $groupManager;
    private $logger;
    private $db;
    private $appName;
    private $host;
    private $groupsTable;
    private $membershipTable;

    /**
     * Create new concrexit group backend
     */
    public function __construct($config, $groupManager, $logger, $db, $jobList, $appName) {
        $this->config = $config;
        $this->groupManager = $groupManager;
        $this->logger = $logger;
        $this->appName = $appName;
        $this->db = $db;
        $this->host = $config->getSystemValue('concrexit', array('host' => 'https://thalia.nu'))['host'];
        $this->groupsTable = 'groups_concrexit';
        $this->membershipTable = 'groups_memberships_concrexit';
    }

    public function init() {
        $this->groupManager->addBackend($this);
    }

    /**
     * is user in group?
     * @param string $uid uid of the user
     * @param string $gid gid of the group
     * @return bool
     *
     * Checks whether the user is member of a group or not.
     */
    public function inGroup($uid, $gid) {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->membershipTable)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
        $result = $qb->execute();
        return $result->fetchColumn() > 0;
    }

    /**
     * Get all groups a user belongs to
     * @param string $uid Name of the user
     * @return array an array of group names
     *
     * This function fetches all groups a user belongs to. It does not check
     * if the user exists at all.
     */
    public function getUserGroups($uid) {
        $qb = $this->db->getQueryBuilder();
        $qb->select('gid')
            ->from($this->membershipTable)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

        $result = $qb->execute()->fetchAll();
        $groups = array_column($result, 'gid');

        return $groups;
    }

    /**
     * get a list of all groups
     * @param string $search
     * @param int $limit
     * @param int $offset
     * @return array an array of group names
     *
     * Returns a list with all groups
     */
    public function getGroups($search = '', $limit = null, $offset = null) {
        $qb = $this->db->getQueryBuilder();

        $qb->selectDistinct('gid')
            ->from($this->groupsTable)
            ->where($qb->expr()->iLike('gid', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
            ->orderBy('gid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $result = $qb->execute()->fetchAll();
        $groups = array_column($result, 'gid');

        $this->logger->debug('Groups loaded ' . json_encode($groups), array('app' => $this->appName));

        return $groups;

    }

    /**
     * check if a group exists
     * @param string $gid
     * @return bool
     */
    public function groupExists($gid) {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->groupsTable)
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
        $result = $qb->execute();
        return $result->fetchColumn() > 0;
    }

    /**
     * get a list of all users in a group
     * @param string $gid
     * @param string $search
     * @param int $limit
     * @param int $offset
     * @return array an array of user ids
     */
    public function usersInGroup($gid, $search = '', $limit = null, $offset = null) {
        $qb = $this->db->getQueryBuilder();

        $qb->select('uid')
            ->from($this->membershipTable)
            ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($search !== '') {
            $qb->andWhere($qb->expr()->iLike('uid', $qb->createNamedParameter(
                '%' . $this->db->escapeLikeParameter($search) . '%'
            )));
        }

        $result = $qb->execute()->fetchAll();
        $users = array_column($result, 'uid');

        return $users;
    }

    /**
     * get the number of all users matching the search string in a group
     * @param string $gid
     * @param string $search
     * @return int
     */
    public function countUsersInGroup(string $gid, string $search = '') : int {
        $query = $this->db->getQueryBuilder();
        $query->select($query->func()->count('*', 'num_users'))
            ->from($this->membershipTable)
            ->where($query->expr()->eq('gid', $query->createNamedParameter($gid)));
        if ($search !== '') {
            $query->andWhere($query->expr()->like('uid', $query->createNamedParameter(
                '%' . $this->db->escapeLikeParameter($search) . '%'
            )));
        }
        $result = $query->execute();
        $count = $result->fetchColumn();
        $result->closeCursor();
        if ($count !== false) {
            $count = (int)$count;
        } else {
            $count = 0;
        }
        return $count;
    }

    /**
     * get the details of the group
     * @param string $gid
     * @return array
     */
    public function getGroupDetails(string $gid) : array {
        if (strpos($gid, 'concrexit_') === 0) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('name')
                ->from($this->groupsTable)
                ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
            $result = $qb->execute();
            $data = $result->fetchColumn();
            $result->closeCursor();

            if ($data !== false) {
                return ['displayName' => $data];
            }
        }
        return [];
    }

    /**
     * Add a user to a group
     * @param string $uid ID of the user to add to group
     * @param string $gid ID of the group in which add the user
     * @return bool
     */
    public function addToGroup(string $uid, string $gid): bool {
        if(!$this->inGroup($uid, $gid)) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert($this->membershipTable)
            ->values([
                'uid' => $qb->createNamedParameter($uid),
                'gid' => $qb->createNamedParameter($gid),
                'manual' => $qb->expr()->literal(true),
            ]);
            $qb->execute();
            return true;
        }
        return false;
    }

    /**
     * Removes a user from a group
     * @param string $uid ID of the user to remove from group
     * @param string $gid ID of the group from which remove the user
     * @return bool
     */
    public function removeFromGroup($uid, $gid) {
        if ($this->inGroup($uid, $gid)) {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($this->membershipTable)
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                ->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
                ->andWhere($qb->expr()->eq('manual', $qb->expr()->literal(true)));
            $qb->execute();
            return true;
        }
        return false;
    }

    private function storeGroup($group) {
        $gid = 'concrexit_' . ((string)$group->pk);
        if ($group->pk === -1) {
            $gid = 'admin';
        }

        if (!$this->groupExists($gid)) {
            $qb = $this->db->getQueryBuilder();
            $qb->insert($this->groupsTable)
                ->values([
                    'gid' => $qb->createNamedParameter($gid),
                    'name' => $qb->createNamedParameter($group->name),
                ]);
            $qb->execute();
            return $gid;
        } else {
            $qb = $this->db->getQueryBuilder();
            $qb->update($this->groupsTable)
                ->set('name', $qb->createNamedParameter($group->name))
                ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
            $qb->execute();
            return $gid;
        }
        return false;
    }

    /**
     * Update the groups from concrexit
     */
    public function updateGroups() {
        $this->logger->debug('Updating groups', array('app' => $this->appName));

        $secret = $this->config->getSystemValue('concrexit', array('secret' => ''))['secret'];
        $result = ApiUtil::doRequest(
            $this->host,
            'activemembers/nextcloud/groups/',
            array('Authorization: Secret ' . $secret)
        );

        if ($result['status'] == 200) {
            $groups = json_decode($result['response']);
            $deleteGroups = $this->getGroups();

            foreach($groups as $group) {
                $gid = $this->storeGroup($group);
                if ($gid !== false) {
                    $deleteGroups = array_values(array_diff($deleteGroups, [$gid]));
                    $members = array_unique($group->members);

                    $qb = $this->db->getQueryBuilder();
                    $qb->select('uid')
                        ->from($this->membershipTable)
                        ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)))
                        ->andWhere($qb->expr()->eq('manual', $qb->expr()->literal(0)));
                    $result = $qb->execute()->fetchAll();
                    $deleteMembers = array_column($result, 'uid');

                    foreach($members as $uid) {
                        $deleteMembers = array_values(array_diff($deleteMembers, [$uid]));
                        if (!$this->inGroup($uid, $gid)) {
                            $qb = $this->db->getQueryBuilder();
                            $qb->insert($this->membershipTable)
                                ->values([
                                    'uid' => $qb->createNamedParameter($uid),
                                    'gid' => $qb->createNamedParameter($gid),
                                ]);
                            $qb->execute();
                        }
                    }

                    foreach($deleteMembers as $uid) {
                        $this->logger->debug('Deleting user ' . $uid . ' from ' . $gid, array('app' => $this->appName));
                        $qb = $this->db->getQueryBuilder();
                        $qb->delete($this->membershipTable)
                            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
                            ->andWhere($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
                        $qb->execute();
                    }
                }
            }

            foreach($deleteGroups as $gid) {
                $this->logger->debug('Deleting group ' . $gid, array('app' => $this->appName));
                $qb = $this->db->getQueryBuilder();
                $qb->delete($this->groupsTable)
                    ->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
                $result = $qb->execute();
            }
        } else {
            $this->logger->error('Updating groups failed, error from server', array('app' => $this->appName));
        }
    }

}
