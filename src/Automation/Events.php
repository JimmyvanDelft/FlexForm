<?php

namespace FlexForm\Automation;

use FlexForm\Processors\Content\Save;
use MediaWiki\MediaWikiServices;
use RuntimeException;
use Wikimedia\Rdbms\IDatabase;

/** Durable outbox. Source and destination commits each include their corresponding event transition. */
class Events {
	public const TABLE = 'flexform_event';

	public static function db() {
		return MediaWikiServices::getInstance()->getConnectionProvider()->getPrimaryDatabase();
	}

	public static function atomic( callable $callback ) {
		return self::db()->doAtomicSection( __METHOD__, $callback, IDatabase::ATOMIC_CANCELABLE );
	}

	public static function once( string $delivery, callable $write ): void {
		self::atomic( static function ( $db ) use ( $delivery, $write ) {
			$db->insert( self::TABLE, [
				'event_id' => 'edit-' . $delivery, 'state' => 'applied', 'queued_at' => time(),
				'rule_id' => '', 'source_rev' => 0, 'payload' => ''
			], __METHOD__, [ 'IGNORE' ] );
			if ( $db->affectedRows() ) {
				$write();
			}
		} );
	}

	public static function record( int $revision, array $jobs ): void {
		$db = self::db();
		$batch = [];
		foreach ( $jobs as $job ) {
			$id = $revision . '-' . $job['rule'] . '-' . $job['index'];
			$db->insert( self::TABLE, [
				'event_id' => $id, 'source_rev' => $revision, 'rule_id' => $job['rule'],
				'state' => 'pending', 'queued_at' => time(),
				'payload' => json_encode( $job, JSON_THROW_ON_ERROR )
			], __METHOD__ );
			$batch[] = new ActionJob( null, [ 'event' => $id ] );
		}
		$db->onTransactionCommitOrIdle( static function () use ( $batch ) {
			try {
				MediaWikiServices::getInstance()->getJobQueueGroup()->push( $batch );
			} catch ( \Exception $e ) {
				\MediaWiki\Logger\LoggerFactory::getInstance( 'FlexForm' )->error(
					'Automation enqueue failed. Events remain pending for recovery.', [ 'exception' => $e ]
				);
			}
		} );
	}

	public static function run( string $id ): void {
		self::atomic( static function ( $db ) use ( $id ) {
			$row = $db->selectRow( self::TABLE, '*', [ 'event_id' => $id ], __METHOD__, [ 'FOR UPDATE' ] );
			if ( !$row || $row->state !== 'pending' ) {
				return;
			}
			$job = json_decode( $row->payload, true, 64, JSON_THROW_ON_ERROR );
			$title = \Title::newFromText( strtr( $job['title'], [ '$event' => $id, '$revision' => $row->source_rev ] + ( $job['tokens'] ?? [] ) ) );
			if ( !$title || !Rules::options() ) {
				throw new RuntimeException( 'Invalid target title or automations disabled.' );
			}
			$user = MediaWikiServices::getInstance()->getUserFactory()->newFromId( $job['actor'] );
			if ( !$user->isRegistered() || !$user->getName() ) {
				throw new RuntimeException( 'Source editor no longer exists.' );
			}
			$started = time();
			( new Save() )->createForAutomation( $user, $title, $job['slots'], 'FlexForm automation ' . $id );
			$db->update( self::TABLE, [
				'state' => 'done', 'started_at' => $started, 'finished_at' => time(), 'error' => null
			], [ 'event_id' => $id ], __METHOD__ );
		} );
	}
}
