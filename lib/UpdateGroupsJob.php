<?php
namespace OCA\ConcrexitAuth;

use OCP\BackgroundJob\Job;
use OCA\ConcrexitAuth\AppInfo\Application;

class UpdateGroupsJob extends Job {

	protected $logger;

	/**
	 * @param ILogger $logger
	 * @param ITimeFactory $timeFactory
	 */
	public function __construct(ILogger $logger, ITimeFactory $timeFactory) {
		parent::__construct($timeFactory);
		$this->logger = $logger;
	}

	public function run($argument) {
		$this->logger->debug('Running UpdateGroupsJob', array('app' => 'ConcrexitAuth'));
		$app = new Application();
        $container = $app->getContainer();
        $container->query('GroupBackend')->updateGroups();
	}
	
}