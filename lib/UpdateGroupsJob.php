<?php
namespace OCA\ConcrexitAuth;

use OC\BackgroundJob\TimedJob;
use \OCA\ConcrexitAuth\AppInfo\Application;

class UpdateGroupsJob extends TimedJob {

	protected $logger;

	public function __construct() {
		$this->logger = \OC::$server->getLogger();
		$this->setInterval(60);
		$this->logger->debug('Init UpdateGroupsJob', array('app' => 'ConcrexitAuth'));
	}

	public function run($argument) {
		$this->logger->debug('Running UpdateGroupsJob', array('app' => 'ConcrexitAuth'));
		$app = new Application();
        $container = $app->getContainer();
        $container->query('GroupBackend')->updateGroups();
	}
	
}