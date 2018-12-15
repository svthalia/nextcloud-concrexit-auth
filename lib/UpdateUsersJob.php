<?php
namespace OCA\ConcrexitAuth;

use OC\BackgroundJob\TimedJob;
use OCA\ConcrexitAuth\AppInfo\Application;

class UpdateUsersJob extends TimedJob {

	protected $logger;

	public function __construct() {
		$this->logger = \OC::$server->getLogger();
		$this->setInterval(60 * 5);
		$this->logger->debug('Init UpdateUsersJob', array('app' => 'ConcrexitAuth'));
	}

	public function run($argument) {
		$this->logger->debug('Running UpdateUsersJob', array('app' => 'ConcrexitAuth'));
		$app = new Application();
        $container = $app->getContainer();
        $container->query('UserBackend')->updateUsers();
	}
	
}