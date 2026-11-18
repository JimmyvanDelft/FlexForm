<?php

namespace FlexForm\Automation;

use InvalidArgumentException;
use MediaWiki\MediaWikiServices;

class Rules {
	public const RIGHT = 'flexform-manage-automations';

	public static function options(): array {
		return MediaWikiServices::getInstance()->getMainConfig()->get( 'FlexFormConfig' )['AutomateOptions'] ?? [];
	}

	public static function defaults(): array {
		$config = MediaWikiServices::getInstance()->getMainConfig()->get( 'FlexFormConfig' );
		return array_intersect_key( $config['AutomateDefaults'] ?? [], [ 'slot' => true, 'template' => true ] );
	}

	public static function title() {
		return \Title::makeTitle( NS_PROJECT, 'FlexForm/Automations.json' );
	}

	public static function protect( $title, $user, $action, &$result ) {
		if ( $title->equals( self::title() ) && $action !== 'read'
			&& !$user->isAllowed( self::RIGHT ) ) {
			$result = [ 'badaccess-group0' ];
			return false;
		}
		return true;
	}

	public static function page() {
		return MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( self::title() );
	}

	public static function load( $rev ): array {
		if ( !$rev ) {
			return [];
		}
		$data = json_decode( $rev->getContent( 'main' )->serialize(), true, 64, JSON_THROW_ON_ERROR );
		if ( ( $data['schemaVersion'] ?? null ) !== 1 || !is_array( $data['rules'] ?? null )
			|| !array_is_list( $data['rules'] ) ) {
			throw new InvalidArgumentException( 'Invalid automation configuration.' );
		}
		return $data['rules'];
	}

	public static function matches( string $type, string $pattern, string $title ): bool {
		if ( $pattern === '' || strlen( $pattern ) > 255 ) {
			throw new InvalidArgumentException( 'Enter a selector of 1–255 bytes.' );
		}
		if ( $type === 'prefix' ) {
			return str_starts_with( $title, $pattern );
		}
		if ( $type !== 'regex' || str_contains( $pattern, "\x1f" ) ) {
			throw new InvalidArgumentException( 'Invalid selector.' );
		}
		$result = @preg_match( "\x1f(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=128)" . $pattern . "\x1fu", $title );
		if ( $result === false ) {
			throw new InvalidArgumentException( 'Invalid regular expression or execution limit exceeded.' );
		}
		return $result === 1;
	}

	public static function validate( array $rule, array $options ): void {
		foreach ( [ 'name', 'slot', 'template', 'parameter' ] as $key ) {
			if ( !is_string( $rule[$key] ?? null ) || trim( $rule[$key] ) === '' ) {
				throw new InvalidArgumentException( "Missing $key." );
			}
		}
		self::matches( $rule['selector'], $rule['pattern'], '' );
		if ( !in_array( $rule['operator'], [ 'equals', 'differs', 'contains' ], true )
			|| !is_string( $rule['value'] ) || empty( $rule['actions'] ) ) {
			throw new InvalidArgumentException( 'Invalid condition or missing action.' );
		}
		foreach ( $rule['actions'] as $action ) {
			self::arguments( $action, $options );
		}
	}

	public static function arguments( array $action, array $options ): array {
		$option = $options[$action['option']] ?? null;
		if ( !$option || !in_array( $option['type'], [ 'set', 'create' ], true ) ) {
			throw new InvalidArgumentException( 'Unknown automation option.' );
		}
		if ( $option['type'] === 'set' && ( $option['allowAnyParameter'] ?? false ) === true &&
			( empty( $option['fixed']['slot'] ) || empty( $option['fixed']['template'] ) ) ) {
			throw new InvalidArgumentException( 'Free parameters require a fixed slot and template.' );
		}
		$fields = $option['fields'] ?? [];
		$values = $action['values'] ?? [];
		if ( !is_array( $values ) || array_diff_key( $values, $fields ) ) {
			throw new InvalidArgumentException( 'Unexpected action field.' );
		}
		foreach ( $fields as $key => $field ) {
			$value = $values[$key] ?? $field['default'] ?? null;
			if ( !is_string( $value ) || strlen( $value ) > ( ( $field['type'] ?? 'text' ) === 'textarea' ? 65536 : 1024 ) ||
				( isset( $field['choices'] ) && !in_array( $value, $field['choices'], true ) ) ) {
				throw new InvalidArgumentException( "Invalid $key." );
			}
			if ( ( $field['type'] ?? 'text' ) === 'textarea' &&
				( $option['type'] !== 'create' || empty( $field['slot'] ) ||
					isset( $field['choices'] ) || isset( $option['fixed'][$key] ) ||
					isset( $option['inherit'][$key] ) ) ) {
				throw new InvalidArgumentException( 'Textarea requires a creation action and a fixed destination slot.' );
			}
			$values[$key] = $value;
		}
		$args = ( $option['fixed'] ?? [] ) + $values;
		if ( $option['type'] === 'set' && preg_match( '/[|{}<>\r\n]/u', $args['value'] ) ) {
			throw new InvalidArgumentException( 'Enter a plain-text parameter value.' );
		}
		if ( $option['type'] === 'set' && ( trim( $args['parameter'] ) === '' ||
			preg_match( '/[=|{}<>\r\n\x00-\x1f\x7f]/u', $args['parameter'] ) ) ) {
			throw new InvalidArgumentException( 'Invalid parameter name.' );
		}
		if ( $option['type'] === 'set' && ( $option['allowAnyParameter'] ?? false ) !== true &&
			!in_array( $args['parameter'], $option['parameters'] ?? [], true ) ) {
			throw new InvalidArgumentException( 'Parameter is not permitted.' );
		}
		return $args;
	}

	public static function changed( ?string $before, ?string $after, string $operator, string $value ): bool {
		if ( $after === null || $after === $before ) {
			return false;
		}
		return match ( $operator ) {
			'equals' => $after === $value,
			'differs' => $after !== $value,
			'contains' => str_contains( $after, $value ),
			default => false
		};
	}
}
