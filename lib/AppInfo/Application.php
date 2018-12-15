<?php

namespace OCA\ConcrexitAuth\AppInfo;

use \OCP\AppFramework\App;
use \OCA\ConcrexitAuth\UserBackend;
use \OCA\ConcrexitAuth\GroupBackend;
use \OCA\ConcrexitAuth\Background\UpdateGroups;
use \OCA\ConcrexitAuth\Background\UpdateUsers;

class Application extends App {

    public function __construct() {
        parent::__construct('ConcrexitAuth', array());

        $container = $this->getContainer();
        $container->registerService('UserBackend', function($c) {
            return new UserBackend(
                $c->query('ServerContainer')->getConfig(),
                $c->query('ServerContainer')->getUserManager(),
                $c->query('ServerContainer')->getLogger(),
                $c->query('ServerContainer')->getDatabaseConnection(),
                $c->query('AppName')
            );
        });

        $container->registerService('GroupBackend', function($c) {
            return new GroupBackend(
                $c->query('ServerContainer')->getConfig(),
                $c->query('ServerContainer')->getGroupManager(),
                $c->query('ServerContainer')->getLogger(),
                $c->query('ServerContainer')->getDatabaseConnection(),
                $c->query('ServerContainer')->getJobList(),
                $c->query('AppName')
            );
        });

        $container->registerService('\OCA\ConcrexitAuth\Background\UpdateGroups', function($c) {
            return new UpdateGroups(
                $c->query('Logger'),
                $c->query('TimeFactory'),
                $c->query('AppName')
            );
        });

        $container->registerService('\OCA\ConcrexitAuth\Background\UpdateUsers', function($c) {
            return new UpdateUsers(
                $c->query('Logger'),
                $c->query('TimeFactory'),
                $c->query('AppName')
            );
        });
    }

    public function init() {
        $this->getContainer()->query('UserBackend')->init();
        $this->getContainer()->query('GroupBackend')->init();

        $logger = $this->getContainer()->query('ServerContainer')->getLogger();
        $jobList = $this->getContainer()->query('ServerContainer')->getJobList();
        $jobList->add(UpdateGroups::class);
        $jobList->add(UpdateUsers::class);
    }

}
