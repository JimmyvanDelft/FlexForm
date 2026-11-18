<?php

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class RunAutomationTests extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Exercise FlexForm automations in a fresh disposable wiki.' );
		$this->addOption( 'disposable-wiki', 'Confirm this is an empty disposable wiki.', true );
	}
	public function execute() {
		require __DIR__ . '/automationIntegration.php';
	}
}

$maintClass = RunAutomationTests::class;
require_once RUN_MAINTENANCE_IF_MAIN;
