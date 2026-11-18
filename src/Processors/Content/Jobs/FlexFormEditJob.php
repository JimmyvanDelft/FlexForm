<?php
/**
 * Created by  : Open CSP
 * Project     : FlexForm
 * Filename    : Jobs.php
 * Description :
 * Date        : 7-8-2025
 * Time        : 13:56
 */

namespace FlexForm\Processors\Content\Jobs;

use FlexForm\Processors\Content\ContentCore;
use Job;

class FlexFormEditJob extends Job {

	private const JOB_NAME = "FlexFormEdit";

	/**
	 * @inheritDoc
	 */
	public function __construct( string $dummy, array $params ) {
		parent::__construct( self::JOB_NAME, $params );
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$pageId = $this->params['pageId'];
		$pData = $this->params['edits'];
		$user = $this->params['user'];
		if ( !empty( $this->params['userId'] ) ) {
			$user = \MediaWiki\MediaWikiServices::getInstance()->getUserFactory()
				->newFromId( $this->params['userId'] )->getName();
		}
		try {
			$write = function () use ( $pageId, $pData, $user ) {
				ContentCore::runEditJob( $pageId, $pData, $this->params['summary'], $user );
			};
			if ( isset( $this->params['delivery'] ) && \FlexForm\Automation\Rules::options() ) {
				\FlexForm\Automation\Events::once( $this->params['delivery'], $write );
			} else {
				$write();
			}
			return true;
		} catch ( \Throwable $e ) {
			$this->setLastError( $e->getMessage() );
			FlexFormJobLogger::logError( 'Failed edit job for ' . $pageId . ': ' . $e->getMessage() );
			return false;
		}
	}
}
