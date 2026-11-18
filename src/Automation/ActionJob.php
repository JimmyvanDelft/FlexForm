<?php

namespace FlexForm\Automation;

class ActionJob extends \Job {
	public function __construct( $title, array $params ) {
		parent::__construct( 'FlexFormAutomation', $params );
	}

	public function run() {
		$id = $this->params['event'];
		try {
			Events::run( $id );
			return true;
		} catch ( \Throwable $e ) {
			Events::db()->update( Events::TABLE, [
				'state' => 'failed', 'error' => substr( $e->getMessage(), 0, 2000 ), 'finished_at' => time()
			], [ 'event_id' => $id, 'state' => 'pending' ], __METHOD__ );
			$this->setLastError( $e->getMessage() );
			return false;
		}
	}
}
