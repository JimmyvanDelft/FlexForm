<?php

use FlexForm\Automation\ActionJob;
use FlexForm\Automation\Events;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class RequeueAutomations extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'FlexForm' );
		$this->addDescription( 'Requeue old pending automation events or one failed event.' );
		$this->addOption( 'failed', 'Retry this failed event after resolving its error.', false, true );
	}

	public function execute() {
		$db = Events::db();
		$id = $this->getOption( 'failed' );
		if ( $id ) {
			$db->update( Events::TABLE, [ 'state' => 'pending', 'error' => null, 'finished_at' => null ],
				[ 'event_id' => $id, 'state' => 'failed' ], __METHOD__ );
			$ids = [ $id ];
		} else {
			$ids = $db->selectFieldValues( Events::TABLE, 'event_id',
				[ 'state' => 'pending', 'queued_at < ' . ( time() - 300 ) ], __METHOD__,
				[ 'ORDER BY' => 'queued_at', 'LIMIT' => 1000 ] );
		}
		foreach ( array_chunk( $ids, 100 ) as $batch ) {
			MediaWikiServices::getInstance()->getJobQueueGroup()->push(
				array_map( static fn ( $key ) => new ActionJob( null, [ 'event' => $key ] ), $batch ) );
		}
		$this->output( count( $ids ) . " jobs offered.\n" );
	}
}

$maintClass = RequeueAutomations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
