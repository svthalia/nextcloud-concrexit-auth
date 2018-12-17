<?php
namespace OCA\ConcrexitAuth;

use OCP\Group\Backend\ABackend;
use OCA\ConcrexitAuth\ApiUtil;

class GroupBackend extends ABackend {
	private $config;
	private $groupManager;
	private $logger;
	private $db;
	private $appName;
	private $host;
	private $table;

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
        $this->table = 'groups_concrexit';
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
			->from($this->table)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($gid)));
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
			->from($this->table)
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
	public function getGroups($search = '', $limit = -1, $offset = 0) {
		$qb = $this->db->getQueryBuilder();

		$qb->selectDistinct('gid')
			->from($this->table)
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
			->from($this->table)
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
	public function usersInGroup($gid, $search = '', $limit = -1, $offset = 0) {
		$qb = $this->db->getQueryBuilder();

		$qb->select('uid')
			->from($this->table)
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
			$this->db->query('DELETE FROM ' . $this->db->getPrefix() . $this->table);
			foreach($groups as $group) {
				$gid = $group->name;

				$members = array_unique($group->members);

				foreach($members as $uid) {
					$qb = $this->db->getQueryBuilder();
					$qb->insert($this->table)
						->values([
							'uid' => $qb->createNamedParameter($uid),
							'gid' => $qb->createNamedParameter($gid),
						]);
					$qb->execute();
				}
			}
		} else {
			$this->logger->debug('Updating groups failed, error from server', array('app' => $this->appName));
		}
	}

}
