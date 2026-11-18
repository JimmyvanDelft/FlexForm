<?php
/**
 * Disposable wiki fixture. Run via maintenance/run.php eval.php or the test runner
 * described in docs/Automations.md. Uses the example AutomateOptions configuration.
 */
use FlexForm\Automation\ActionJob;
use FlexForm\Automation\Events;
use FlexForm\Automation\Rules;
use FlexForm\Core\Config;
use FlexForm\Core\Core;
use FlexForm\Core\HandleResponse;
use FlexForm\Processors\Content\ContentCore;
use MediaWiki\MediaWikiServices;

$expect = static function ( $ok, string $label ) {
	if ( !$ok ) {
		throw new RuntimeException( $label );
	}
	echo "PASS $label\n";
};
global $wgFlexFormConfig;
$wgFlexFormConfig['AutomateOptions']['task']['fields']['body'] = [
	'type' => 'textarea', 'slot' => 'main', 'default' => ''
];
$body = "{{Example\n|Value=Yes\n}}\n\n== Details ==\n* [taaknummer] stays literal";
$services = MediaWikiServices::getInstance();
$db = Events::db();
if ( Rules::title()->exists() || Title::newFromText( 'Task/123' )->exists() ) {
	throw new RuntimeException( 'Use an empty disposable test wiki.' );
}
$user = $services->getUserFactory()->newFromName( 'TestAdmin' );
RequestContext::getMain()->setUser( $user );
RequestContext::getMain()->setTitle( Title::newFromText( 'Dashboard' ) );
Config::setConfigFromMW();
foreach ( [ 'csp-base-props', 'csp-class-props' ] as $role ) {
	if ( !$services->getSlotRoleRegistry()->isDefinedRole( $role ) ) {
		$services->getSlotRoleRegistry()->defineRoleWithModel( $role, CONTENT_MODEL_WIKITEXT );
	}
}
$page = static fn ( $title ) => $services->getWikiPageFactory()->newFromTitle( Title::newFromText( $title ) );
$rev = static fn ( $title ) => $page( $title )->getRevisionRecord();
$write = static function ( $target, $slots ) use ( $user ) {
	$updater = $target->newPageUpdater( $user );
	foreach ( $slots as $slot => $content ) {
		$updater->setContent( $slot, $content );
	}
	$updater->saveRevision( CommentStoreComment::newUnsavedComment( 'Test fixture' ) );
	if ( !$updater->getStatus()->isOK() ) {
		throw new RuntimeException( $updater->getStatus()->getMessage()->text() );
	}
};
$slot = 'csp-class-props';
$template = 'Csp class properties';
$rule = [ 'id' => str_repeat( 'a', 24 ), 'name' => 'Review', 'enabled' => true,
	'selector' => 'regex', 'pattern' => '^Task/[0-9]+$', 'slot' => $slot, 'template' => $template,
	'parameter' => 'Status', 'operator' => 'equals', 'value' => 'Code Review', 'actions' => [
		[ 'option' => 'archive', 'values' => [] ], [ 'option' => 'task', 'values' => [
			'body' => $body, 'display' => 'Evaluatie [taaknummer]', 'status' => 'Gepland', 'type' => 'Review', 'projectMode' => 'source', 'project' => ''
		] ]
	] ];
$write( Rules::page(), [ 'main' => new JsonContent( json_encode( [ 'schemaVersion' => 1, 'rules' => [ $rule ] ] ) ) ] );
$expect( $user->isAllowed( Rules::RIGHT ) && !( new User() )->definitelyCan( 'edit', Rules::title() ), 'configuration permissions' );
$apiContext = new RequestContext();
$apiContext->setUser( new User() );
$apiRequest = new FauxRequest( [
	'action' => 'edit', 'title' => Rules::title()->getPrefixedText(), 'text' => '{}',
	'token' => $apiContext->getUser()->getEditToken()
], true );
$apiContext->setRequest( $apiRequest );
$api = new ApiMain( $apiContext, true );
try {
	$api->execute();
	throw new RuntimeException( 'API edit should have failed.' );
} catch ( \MediaWiki\Api\ApiUsageException $e ) {
	$expect( $e->getMessageObject()->getKey() === 'badaccess-group0' &&
		Rules::load( Rules::page()->getRevisionRecord() ) === [ $rule ], 'API cannot overwrite rule storage' );
}
$task = 'Task/123';
$seed = "{{Csp class properties\n|Status=In uitvoering\n|Project=MinFin\n}}";
$write( $page( $task ), [ 'main' => new WikitextContent( '' ), $slot => new WikitextContent( $seed ) ] );
$edit = static fn ( $field, $parameter ) => implode( Core::DIVIDER, [
	$task, $template, $field, $parameter, '', $slot, 'wiki'
] );
$_POST = [ 'mwreturn' => 'Dashboard', 'mwedit' => [ $edit( 'html_status', 'Status' ), $edit( 'project', 'Project' ) ],
	'html_status' => 'Code Review', 'project' => 'MinFin 2' ];
$before = $rev( $task )->getId();
ContentCore::saveToWiki( new HandleResponse() );
$source = $rev( $task );
$contents = $source->getContent( $slot )->serialize();
$expect( $source->getParentId() === $before && str_contains( $contents, '|Archived=Yes' )
	&& str_contains( $contents, '|Status=Code Review' ), 'dashboard alias and archive in one revision' );
$row = $db->selectRow( Events::TABLE, '*', [ 'state' => 'pending' ], __FILE__ );
$expect( $row && (int)$row->source_rev === $source->getId(), 'one durable action tied to source revision' );
$_POST['mwedit'][] = $edit( 'description', 'Description' );
$_POST['description'] = 'Only description changed';
ContentCore::saveToWiki( new HandleResponse() );
unset( $_POST['html_status'] );
ContentCore::saveToWiki( new HandleResponse() );
$expect( str_contains( $rev( $task )->getContent( $slot )->serialize(), '|Status=Code Review' )
	&& (int)$db->selectRowCount( Events::TABLE, '*', [], __FILE__ ) === 1, 'unchanged and omitted fields' );
$job = new ActionJob( null, [ 'event' => $row->event_id ] );
$expect( $job->run(), 'follow-up job' );
$target = 'Task/' . $row->event_id;
$first = $rev( $target );
$targetText = $first->getContent( $slot )->serialize();
$expect( $first->getContent( 'main' )->serialize() === $body, 'textarea saved verbatim through jobqueue' );
$expect( str_contains( $first->getContent( 'csp-base-props' )->serialize(), '|Title=Evaluatie 123' )
	&& str_contains( $targetText, '|Status=Gepland' ), 'administrator title pattern and custom status' );
$expect( str_contains( $targetText, '|Project=MinFin 2' ) && str_contains( $targetText, '|Task type=Review' ), 'source snapshot in created slots' );
$expect( ( new ActionJob( null, [ 'event' => $row->event_id ] ) )->run() && $rev( $target )->getId() === $first->getId(),
	'redelivery after target commit' );
$expect( (int)$db->selectRowCount( Events::TABLE, '*', [], __FILE__ ) === 1, 'no recursive actions' );

$queue = $services->getJobQueueGroup();
$services->getDBLoadBalancerFactory()->commitPrimaryChanges( __FILE__ );
$queued = $queue->pop( 'FlexFormAutomation' );
$expect( $queued instanceof ActionJob && $queued->run(), 'MediaWiki queue delivery' );
$queue->ack( $queued );

$_POST = [ 'mwreturn' => 'Dashboard', 'mwjob' => 'jobCreate', 'mwedit' => [ $edit( 'html_status', 'Status' ) ],
	'html_status' => 'Code Review' ];
ContentCore::saveToWiki( new HandleResponse() );
$expect( (int)$db->selectRowCount( Events::TABLE, '*', [], __FILE__ ) === 1, 'submit does not evaluate queued edit' );
$write( $page( $task ), [ $slot => new WikitextContent( $seed ) ] );
$sourceJob = $queue->pop( 'FlexFormEdit' );
$expect( $sourceJob && $sourceJob->run(), 'run original queued edit' );
$expect( (int)$db->selectRowCount( Events::TABLE, '*', [ 'state' => 'pending' ], __FILE__ ) === 1, 'job compares current base' );
$write( $page( $task ), [ $slot => new WikitextContent( $seed ) ] );
$expect( $sourceJob->run() && $rev( $task )->getContent( $slot )->serialize() === $seed, 'replayed source job after intervening edit' );
$queue->ack( $sourceJob );
unset( $_POST['mwjob'] );
$count = $db->selectRowCount( Events::TABLE, '*', [], __FILE__ );
$latest = $db->selectField( 'page', 'page_latest', [ 'page_title' => $task ], __FILE__ );
try {
	Events::atomic( static function () {
		ContentCore::saveToWiki( new HandleResponse() );
		throw new RuntimeException( 'rollback fixture' );
	} );
} catch ( RuntimeException $e ) {
	if ( $e->getMessage() !== 'rollback fixture' ) {
		throw $e;
	}
}
$expect( $db->selectField( 'page', 'page_latest', [ 'page_title' => $task ], __FILE__ ) === $latest &&
	$db->selectRowCount( Events::TABLE, '*', [], __FILE__ ) === $count, 'rollback removes source revision and pending action' );

$form = static function ( array $data, $actor ) {
	$context = new RequestContext();
	$context->setRequest( new FauxRequest( $data, true ) );
	$context->setUser( $actor );
	$special = new \FlexForm\Specials\SpecialFlexForm();
	$special->setContext( $context );
	$context->setTitle( $special->getPageTitle( 'automations' ) );
	$special->execute( 'automations' );
	return $context->getOutput();
};
$newContext = new RequestContext();
$newContext->setUser( $user );
$newContext->setRequest( new FauxRequest() );
$newSpecial = new \FlexForm\Specials\SpecialFlexForm();
$newSpecial->setContext( $newContext );
$newContext->setTitle( $newSpecial->getPageTitle( 'automations' ) );
$newSpecial->execute( 'automations' );
$html = $newContext->getOutput()->getHTML();
$dom = new DOMDocument();
@$dom->loadHTML( '<meta charset="utf-8">' . $html );
$xpath = new DOMXPath( $dom );
$expect( $xpath->query( '//input[@name="slot" and @value="csp-class-props"]' )->length === 1 &&
	$xpath->query( '//input[@name="template" and @value="Csp class properties"]' )->length === 1 &&
	$xpath->query( '//details[@class="ff-automation-advanced" and not(@open)]' )->length === 1,
	'new rule prefilled with collapsed advanced settings' );
$post = $rule;
$post['actions'] = [ [ 'option' => 'archive' ] ];
$post['token'] = $user->getEditToken();
$post['base'] = Rules::page()->getRevisionRecord()->getId();
$post['operation'] = 'save';
$post['name'] = 'Updated review';
$expect( (bool)$form( $post, $user )->getRedirect(), 'save using the management form' );
$expect( str_contains( $form( $post, $user )->getHTML(), 'Edit conflict' ), 'stale management form refused' );
$stored = Rules::page()->getRevisionRecord()->getId();
$form( $post, new User() );
$expect( Rules::page()->getRevisionRecord()->getId() === $stored, 'unauthorized management change refused' );

$post['operation'] = 'save';
$post['base'] = $stored;
unset( $post['enabled'] );
$form( $post, $user );
$entry = Rules::load( Rules::page()->getRevisionRecord() )[0];
$expect( !$entry['enabled'] && $entry['id'] === $rule['id'], 'disable preserves stable ID' );
$post['enabled'] = '1';
$post['base'] = Rules::page()->getRevisionRecord()->getId();
$form( $post, $user );
$expect( Rules::load( Rules::page()->getRevisionRecord() )[0]['enabled'], 'enable rule' );
$second = 'Task/124';
$write( $page( $second ), [ 'main' => new WikitextContent( '' ), $slot => new WikitextContent( $seed ) ] );
$_POST = [ 'mwreturn' => 'Dashboard', 'html_status' => 'Code Review', 'mwedit' => [
	$edit( 'html_status', 'Status' ), str_replace( $task, $second, $edit( 'html_status', 'Status' ) )
] ];
ContentCore::saveToWiki( new HandleResponse() );
foreach ( [ $task, $second ] as $name ) {
	$expect( str_contains( $rev( $name )->getContent( $slot )->serialize(), '|Archived=Yes' ), "multi-page target $name" );
}

$pending = $db->selectRow( Events::TABLE, '*', [ 'state' => 'pending' ], __FILE__ );
$collision = 'Task/' . $pending->event_id;
$write( $page( $collision ), [ 'main' => new WikitextContent( 'Existing content' ) ] );
$collisionRev = $rev( $collision )->getId();
$failed = new ActionJob( null, [ 'event' => $pending->event_id ] );
$expect( !$failed->run() && $rev( $collision )->getId() === $collisionRev, 'target collision preserves existing page' );
$expect( $db->selectField( Events::TABLE, 'state', [ 'event_id' => $pending->event_id ], __FILE__ ) === 'failed',
	'failed action recorded' );

$post['operation'] = 'delete';
$post['base'] = Rules::page()->getRevisionRecord()->getId();
$form( $post, $user );
$expect( Rules::load( Rules::page()->getRevisionRecord() ) === [], 'delete rule' );
