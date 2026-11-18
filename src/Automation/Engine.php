<?php

namespace FlexForm\Automation;

use FlexForm\Processors\Content\Edit;
use RuntimeException;

class Engine {
	private Edit $edit;
	private array $original;
	private array $submitted;
	private $parent;

	public function __construct( $parent, array $slots, array $submitted ) {
		$this->edit = new Edit();
		$this->original = $slots;
		$this->submitted = $submitted;
		$this->parent = $parent;
	}

	private function slot( string $slot ): string {
		if ( !$this->parent->hasSlot( $slot ) ) {
			return '';
		}
		return $this->parent->getContent( $slot )->serialize();
	}

	private function read( array $field, bool $before = false ): ?string {
		$slot = $field['slot'];
		$text = $before ? $this->slot( $slot ) : ( $this->original[$slot] ?? $this->slot( $slot ) );
		$content = $this->edit->getTemplate( $text, $field['template'] );
		if ( $content === false ) {
			return null;
		}
		$value = null;
		foreach ( Edit::pregExplode( $content ) as $line ) {
			$pair = explode( '=', $line, 2 );
			if ( count( $pair ) === 2 && trim( $pair[0] ) === $field['parameter'] ) {
				$value = trim( $pair[1] );
			}
		}
		return $value;
	}

	public function apply( $title, $user, array &$slots ): array {
		$options = Rules::options();
		$jobs = [];
		foreach ( Rules::load( Rules::page()->getRevisionRecord() ) as $rule ) {
			if ( !$rule['enabled'] || !Rules::matches( $rule['selector'], $rule['pattern'], $title->getPrefixedText() )
				|| empty( $this->submitted[$rule['slot']][$rule['template']][$rule['parameter']] )
				|| !Rules::changed( $this->read( $rule, true ), $this->read( $rule ), $rule['operator'], $rule['value'] ) ) {
				continue;
			}
			Rules::validate( $rule, $options );
			foreach ( $rule['actions'] as $index => $action ) {
				$option = $options[$action['option']];
				$args = Rules::arguments( $action, $options );
				$variables = [];
				foreach ( $option['variables'] ?? [] as $name => $source ) {
					$variables['[' . $name . ']'] = match ( $source ) {
						'title' => $title->getPrefixedText(),
						'number' => substr( $title->getText(), strrpos( '/' . $title->getText(), '/' ) ),
						default => throw new RuntimeException( 'Unknown automation variable source.' )
					};
				}
				$rawSlots = [];
				foreach ( $option['fields'] ?? [] as $key => $field ) {
					if ( ( $field['type'] ?? 'text' ) === 'textarea' ) {
						$rawSlots[$field['slot']] = $args[$key];
						unset( $args[$key] );
					}
				}
				$args = array_map( static fn ( $value ) => strtr( $value, $variables ), $args );
				if ( !$user->isRegistered() || !$user->definitelyCan( 'read', $title ) ) {
					throw new RuntimeException( 'Automation requires an identified editor with read access.' );
				}
				if ( $option['type'] === 'set' ) {
					self::authorize( $user, $title, 'edit' );
					$slot = $args['slot'];
					$slots[$slot] = $this->edit->setParameter( $slots[$slot] ?? $this->slot( $slot ), $args );
				} else {
					foreach ( $option['inherit'] ?? [] as $key => $field ) {
						if ( $args[$field['mode']] === 'source' ) {
							$args[$key] = $this->read( $field ) ?? '';
						}
					}
					$tokens = [];
					foreach ( $args as $key => $value ) {
						if ( preg_match( '/[|{}<>\r\n]/u', $value ) ) {
							throw new RuntimeException( "Invalid plain-text substitution: $key." );
						}
						$tokens['$' . $key] = $value;
					}
					$jobs[] = [
						'rule' => $rule['id'], 'index' => $index, 'actor' => $user->getId(),
						'title' => $option['title'], 'tokens' => $tokens, 'slots' => $rawSlots + array_map(
							static fn ( $text ) => strtr( $text, $tokens ), $option['slots'] ?? []
						)
					];
				}
			}
		}
		return $jobs;
	}

	public static function authorize( $user, $title, string $action ): void {
		if ( !$title->canExist() || $title->isExternal() || $title->hasFragment() || $title->equals( Rules::title() )
			|| !$user->authorizeWrite( $action, $title ) ) {
			throw new RuntimeException( "Automation permission denied ($action)." );
		}
	}
}
