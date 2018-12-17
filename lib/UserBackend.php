<?php
namespace OCA\ConcrexitAuth;

use OCP\User\Backend\ABackend;
use OCP\User\Backend\ICheckPasswordBackend;
use OCP\User\Backend\IGetDisplayNameBackend;
use OCP\User\Backend\ICountUsersBackend;
use OCA\ConcrexitAuth\ApiUtil;

/**
 * Class for external auth with https://thalia.nu
 *
 * @category Apps
 * @package  UserConcrexit
 * @author   Sébastiaan Versteeg <se_bastiaan@outlook.com>
 * @license  http://www.gnu.org/licenses/agpl AGPL
 * @link     http://github.com/thaliawww/user_concrexit
 */
class UserBackend extends ABackend implements ICheckPasswordBackend, IGetDisplayNameBackend, ICountUsersBackend {
	private $config;
	private $userManager;
	private $logger;
	private $db;
	private $appName;
	private $host;
	private $table;

	public function __construct($config, $userManager, $logger, $db, $appName) {
		$this->config = $config;
		$this->userManager = $userManager;
        $this->logger = $logger;
        $this->db = $db;
        $this->appName = $appName;
        $this->host = $config->getSystemValue('concrexit', array('host' => 'https://thalia.nu'))['host'];
        $this->table = 'users_concrexit';
	}

	public function init() {
        $this->userManager->registerBackend($this);
	}

	/**
	 * Delete a user
	 *
	 * @param string $uid The username of the user to delete
	 *
	 * @return bool
	 */
	public function deleteUser($uid) {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->table)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter(mb_strtolower($uid))));
		$result = $qb->execute();

		return $result ? true : false;
	}

	/**
	 * Check if the password is correct without logging in the user
	 *
	 * @param string $uid      The username
	 * @param string $password The password
	 *
	 * @return true/false
	 */
	public function checkPassword($uid, $password) {
		$this->logger->debug('Checking password ' . $uid, array('app' => $this->appName));

		if ($this->userExists($uid)) {
			$result = ApiUtil::doRequest(
				$this->host,
				'token-auth',
				array('Content-Type: application/json'),
				json_encode(array(
				    'username' => $uid,
				    'password' => $password
				))
			);

			if ($result['status'] === 200) {
				return $uid;
			}
		} else {
			$this->logger->debug('User does not exist: ' . $uid, array('app' => $this->appName));
		}

		return false;
	}

		/**
	 * Get display name of the user
	 *
	 * @param string $uid user ID of the user
	 *
	 * @return string display name
	 */
	public function getDisplayName($uid): string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('uid', 'displayname')
			->from($this->table)
			->where(
				$qb->expr()->eq(
					'uid', $qb->createNamedParameter($uid)
				)
			);
		$result = $qb->execute();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row !== false) {
			return $row['displayname'];
		} else {
			return false;
		}
	}
	/**
	 * Get a list of all display names and user ids.
	 *
	 * @return array with all displayNames (value) and the corresponding uids (key)
	 */
	public function getDisplayNames($search = '', $limit = null, $offset = null) {
		$qb = $this->db->getQueryBuilder();

		$qb->select('uid', 'displayname')
			->from($this->table)
			->where($qb->expr()->iLike('uid', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orWhere($qb->expr()->iLike('displayname', $qb->createPositionalParameter('%' . $this->db->escapeLikeParameter($search) . '%')))
			->orderBy($qb->func()->lower('displayname'), 'ASC')
			->orderBy('uid', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		$result = $qb->execute();
		$users = [];
		while ($row = $result->fetch()) {
			$users[(string)$row['uid']] = (string)$row['displayname'];
		}

		return $users;
	}
	/**
	* Get a list of all users
	*
	* @return array with all uids
	*/
	public function getUsers($search = '', $limit = null, $offset = null) {
		$users = $this->getDisplayNames($search, $limit, $offset);
		$userIds = array_map(function ($uid) {
			return (string)$uid;
		}, array_keys($users));
		sort($userIds, SORT_STRING | SORT_FLAG_CASE);
		return $userIds;
	}

	/**
	 * Check if a user exists
	 *
	 * @param string $uid the username
	 *
	 * @return boolean
	 */
	public function userExists($uid) {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($this->table)
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$result = $qb->execute();
		return $result->fetchColumn() > 0;
	}

	/**
	 * Determines if the backend can enlist users
	 *
	 * @return bool
	 */
	public function hasUserListings() {
		return false;
	}

	/**
	 * Backend name to be shown in user management
	 *
	 * @return string the name of the backend to be shown
	 */
	public function getBackendName() {
		return 'concrexit';
	}

	/**
	 * counts the users in the database
	 *
	 * @return int|bool
	 */
	public function countUsers() {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('uid'))->from($this->table);
		$result = $qb->execute();
		return $result->fetchColumn();
	}

	protected function storeUser($uid, $token) {
		if (!$this->userExists($uid)) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert($this->table)
				->values([
					'uid' => $qb->createNamedParameter($uid),
					'displayname' => $qb->createNamedParameter($uid),
					'token' => $qb->createNamedParameter($token),
				]);
			$qb->execute();
		}
	}

	/**
	 * Update the users from concrexit
	 */
	public function updateUsers() {
		$this->logger->debug('Updating users', array('app' => $this->appName));

		$settings = $this->config->getSystemValue('concrexit', array());
		$secret = isset($settings['secret']) ? $settings['secret'] : '';
		$quota = isset($settings['quota']) ? $settings['quota'] : '100MB';
		$result = ApiUtil::doRequest(
			$this->host,
			'activemembers/nextcloud/users/',
			array('Authorization: Secret ' . $secret)
		);

		if ($result['status'] == 200) {
			$users = json_decode($result['response']);
			$this->db->query('DELETE FROM ' . $this->db->getPrefix() . $this->table);
			foreach($users as $user) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert($this->table)
					->values([
						'uid' => $qb->createNamedParameter($user->username),
						'displayname' => $qb->createNamedParameter($user->first_name . ' ' . $user->last_name),
					]);
				$qb->execute();

				$email = $user->email;
				$userObj = $this->userManager->get($user->username);
				if (!is_null($userObj)) {
					$currentEmail = (string)$userObj->getEMailAddress();
					if ($currentEmail !== $email) {
						$userObj->setEMailAddress($email);
					}
					$userObj->setQuota($quota);
				}
			}
		} else {
			$this->logger->debug('Updating users failed, error from server', array('app' => $this->appName));
		}
	}
}
