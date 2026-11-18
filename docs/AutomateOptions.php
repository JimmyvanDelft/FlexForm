<?php
// Include after wfLoadExtension( 'FlexForm' ). Use slot roles registered on your wiki.
$properties = 'csp-class-props';
$base = 'csp-base-props';
$wgFlexFormConfig['AutomateOptions'] = [
	'archive' => [
		'label' => 'Archiveren', 'type' => 'set', 'parameters' => [ 'Archived' ],
		'fixed' => [ 'slot' => $properties, 'template' => 'Csp class properties', 'parameter' => 'Archived', 'value' => 'Yes' ]
	],
	'set' => [
		'label' => 'Parameter instellen', 'type' => 'set', 'parameters' => [ 'Archived', 'Priority' ],
		'fixed' => [ 'slot' => $properties, 'template' => 'Csp class properties' ],
		'fields' => [ 'parameter' => [ 'choices' => [ 'Archived', 'Priority' ] ], 'value' => [] ]
	],
	'task' => [
		'label' => 'Taak aanmaken', 'type' => 'create', 'title' => 'Task/$event',
		'fields' => [
			'display' => [ 'label' => 'Weergavetitel' ], 'type' => [ 'label' => 'Taaktype' ],
			'projectMode' => [ 'label' => 'Projectbron', 'choices' => [ 'fixed', 'source' ] ],
			'project' => [ 'label' => 'Vast project' ]
		],
		'inherit' => [ 'project' => [
			'mode' => 'projectMode', 'slot' => $properties, 'template' => 'Csp class properties', 'parameter' => 'Project'
		] ],
		'slots' => [
			$base => "{{Base properties\n|Class=Task\n|Title=\$display\n}}",
			$properties => "{{Csp class properties\n|Task type=\$type\n|Project=\$project\n}}"
		]
	]
];
