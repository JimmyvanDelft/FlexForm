<?php

use FlexForm\Automation\Rules;

require_once __DIR__ . '/../src/Automation/Rules.php';
require __DIR__ . '/../docs/AutomateOptions.php';

function check( bool $ok, string $name ): void {
	if ( !$ok ) {
		throw new RuntimeException( $name );
	}
	echo "PASS $name\n";
}

function rejects( callable $call ): bool {
	try {
		$call();
		return false;
	} catch ( InvalidArgumentException $e ) {
		return true;
	}
}

check( Rules::matches( 'prefix', 'Task/', 'Task/123' ), 'prefix' );
check( !Rules::matches( 'prefix', 'Task/', 'Project:Task/123' ), 'namespace participates in selection' );
check( Rules::matches( 'regex', '^Task/[0-9]+$', 'Task/123' ), 'regular expression' );
check( rejects( fn () => Rules::matches( 'regex', '(', '' ) ), 'reject malformed expression' );
check( rejects( fn () => Rules::matches( 'regex', str_repeat( '.', 256 ), '' ) ), 'selector size limit' );
check( rejects( fn () => Rules::matches( 'regex', '(a+)+$', str_repeat( 'a', 160 ) . '!' ) ), 'regex budget' );
check( Rules::changed( 'old', '', 'equals', '' ), 'explicit clear' );
check( !Rules::changed( 'old', null, 'differs', 'other' ), 'absent field' );
check( Rules::changed( 'old', 'code review', 'contains', 'review' ), 'substring comparison' );
check( !Rules::changed( 'review', 'review', 'equals', 'review' ), 'identical value' );
check( Rules::changed( 'old', 'review', 'differs', 'done' ), 'inequality' );
$options = $wgFlexFormConfig['AutomateOptions'];
check( rejects( fn () => Rules::arguments( [ 'option' => 'archive', 'values' => [ 'slot' => 'main' ] ], $options ) ),
	'fixed slot is immutable' );
check( rejects( fn () => Rules::arguments( [ 'option' => 'set', 'values' => [ 'parameter' => 'Unknown', 'value' => 'x' ] ], $options ) ),
	'parameter allowlist' );
check( rejects( fn () => Rules::arguments( [ 'option' => 'set', 'values' => [ 'parameter' => 'Archived', 'value' => 'x|Other=y' ] ], $options ) ),
	'plain-text value cannot add a parameter' );

$free = $options;
$free['set']['allowAnyParameter'] = true;
unset( $free['set']['fields']['parameter']['choices'] );
check( Rules::arguments( [ 'option' => 'set', 'values' => [ 'parameter' => 'Custom field', 'value' => 'Yes' ] ], $free )['parameter'] === 'Custom field',
	'explicit free parameter configuration' );
foreach ( [ '', 'Other=x', "Bad\nname", 'x|Other', '{{x}}' ] as $parameter ) {
	check( rejects( fn () => Rules::arguments( [ 'option' => 'set',
		'values' => [ 'parameter' => $parameter, 'value' => 'x' ] ], $free ) ), 'reject structural parameter name ' . json_encode( $parameter ) );
}
$options['raw'] = [ 'type' => 'create', 'fields' => [
	'body' => [ 'type' => 'textarea', 'slot' => 'main', 'default' => '' ]
] ];
$body = "{{Example\n|Value=Yes\n}}\n\n== Details ==\n* Item";
check( Rules::arguments( [ 'option' => 'raw', 'values' => [ 'body' => $body ] ], $options )['body'] === $body,
	'multiline wikitext accepted for configured slot' );
check( Rules::arguments( [ 'option' => 'raw' ], $options )['body'] === '', 'textarea default for older rules' );
check( rejects( fn () => Rules::arguments( [ 'option' => 'raw', 'values' => [ 'body' => str_repeat( 'x', 65537 ) ] ], $options ) ),
	'textarea length bound' );
