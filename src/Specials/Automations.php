<?php

namespace FlexForm\Specials;

use FlexForm\Automation\Events;
use FlexForm\Automation\Rules;
use MediaWiki\Html\Html;
use RuntimeException;

class Automations {
	private $special;
	private array $options;

	public function __construct( SpecialFlexForm $special ) {
		$this->special = $special;
		$this->options = Rules::options();
	}

	private function label( string $key ): string {
		return $this->special->msg( 'flexform-automation-' . $key )->text();
	}

	private static function input( string $label, string $name, string $value, ?array $choices = null ): string {
		if ( $choices === null ) {
			$control = Html::input( $name, $value, 'text' );
		} else {
			$items = '';
			foreach ( $choices as $key => $text ) {
				$items .= Html::element( 'option', [ 'value' => $key, 'selected' => (string)$key === $value ], $text );
			}
			$control = Html::rawElement( 'select', [ 'name' => $name ], $items );
		}
		return Html::rawElement( 'div', [ 'class' => 'ff-automation-field' ], Html::rawElement( 'label', [], Html::element( 'span', [], $label ) . $control ) );
	}

	public function show(): void {
		$s = $this->special;
		$out = $s->getOutput();
		$out->setPageTitle( $s->msg( 'flexform-automations' )->text() );
		$user = $s->getUser();
		if ( !$user->isAllowed( Rules::RIGHT ) || !$this->options ) {
			$out->addWikiMsg( 'badaccess-group0' );
			return;
		}
		$request = $s->getRequest();
		$url = $s->getPageTitle( 'automations' );
		$page = Rules::page();
		$revision = $page->getRevisionRecord();
		$rules = Rules::load( $revision );
		$byId = array_column( $rules, null, 'id' );
		$id = $request->getText( 'id' );
		$rule = $byId[$id] ?? [];
		$base = $revision ? $revision->getId() : 0;
		if ( $request->wasPosted() ) {
			try {
				if ( !$user->matchEditToken( $request->getText( 'token' ) ) ) {
					throw new RuntimeException( 'Invalid edit token.' );
				}
				$base = $request->getInt( 'base', -1 );
				$operation = $request->getText( 'operation' );
				if ( $id !== '' && !isset( $byId[$id] ) ) {
					throw new RuntimeException( 'Edit conflict. The rule was removed.' );
				}
				if ( $operation !== 'delete' ) {
					$rule = [ 'id' => $id ?: bin2hex( random_bytes( 12 ) ),
						'enabled' => $request->getBool( 'enabled' ), 'actions' => [] ];
					foreach ( [ 'name', 'selector', 'pattern', 'slot', 'template', 'parameter', 'operator', 'value' ] as $key ) {
						$rule[$key] = $request->getText( $key );
					}
					foreach ( $request->getArray( 'actions', [] ) as $action ) {
						$key = $action['option'];
						$rule['actions'][] = [ 'option' => $key, 'values' => $action['values'][$key] ?? [] ];
					}
					if ( $operation === 'test' ) {
						$title = \Title::newFromText( $request->getText( 'example' ) );
						if ( !$title || $title->isExternal() || $title->hasFragment() ) {
							throw new RuntimeException( 'Invalid example title.' );
						}
						$matches = Rules::matches( $rule['selector'], $rule['pattern'], $title->getPrefixedText() );
						$out->addHTML( Html::element( 'p', [], $title->getPrefixedText() . ( $matches ? ' ✓' : ' ✗' ) ) );
					} else {
						Rules::validate( $rule, $this->options );
						$byId[$rule['id']] = $rule;
					}
				} else {
					unset( $byId[$id] );
				}
				if ( in_array( $operation, [ 'delete', 'save' ], true ) ) {
					$updater = $page->newPageUpdater( $user );
					$parent = $updater->grabParentRevision();
					if ( $base !== ( $parent ? $parent->getId() : 0 ) || $base !== ( $revision ? $revision->getId() : 0 ) ) {
						throw new RuntimeException( 'Edit conflict. Reload before saving.' );
					}
					$content = json_encode( [ 'schemaVersion' => 1, 'rules' => array_values( $byId ) ],
						JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
					if ( !$user->authorizeWrite( 'edit', Rules::title() ) ||
						( !$parent && !$user->authorizeWrite( 'create', Rules::title() ) ) ) {
						throw new RuntimeException( 'Write permission denied.' );
					}
					$updater->setContent( 'main', \ContentHandler::makeContent( $content, Rules::title(), CONTENT_MODEL_JSON ) );
					$updater->saveRevision( \CommentStoreComment::newUnsavedComment( "FlexForm: $operation" ) );
					if ( !$updater->getStatus()->isOK() ) {
						throw new RuntimeException( $updater->getStatus()->getMessage()->text() );
					}
					$out->redirect( $url->getLocalURL( $operation === 'save' ? [ 'id' => $rule['id'], 'saved' => 1 ] : [] ) );
					return;
				}
			} catch ( \Exception $e ) {
				$out->addHTML( Html::element( 'p', [ 'class' => 'error' ], $e->getMessage() ) );
			}
		}
		$navigation = Html::element( 'h2', [], $this->label( 'rules' ) );
		$navigation .= Html::element( 'a', [
			'href' => $url->getLocalURL(), 'class' => 'ff-automation-new'
		], '+ ' . $this->label( 'new' ) );
		if ( !$rules ) {
			$navigation .= Html::element( 'p', [ 'class' => 'ff-automation-help' ], $this->label( 'empty' ) );
		}
		foreach ( $rules as $item ) {
			$active = $item['id'] === $id;
			$navigation .= Html::rawElement( 'a', [
				'href' => $url->getLocalURL( [ 'id' => $item['id'] ] ),
				'class' => 'ff-automation-rule' . ( $active ? ' is-selected' : '' ),
				'aria-current' => $active ? 'page' : null
			], Html::element( 'strong', [], $item['name'] ) .
				Html::element( 'span', [ 'class' => 'ff-automation-status' ],
					$this->label( $item['enabled'] ? 'enabled' : 'disabled' ) ) .
				Html::element( 'small', [], $item['pattern'] ) );
		}
		$out->addHTML( '<div class="ff-automations">' .
			Html::rawElement( 'nav', [ 'class' => 'ff-automation-nav', 'aria-label' => $this->label( 'rules' ) ], $navigation ) .
			'<div class="ff-automation-editor">' );
		$out->addHTML( Html::element( 'p', [ 'class' => 'ff-automation-context' ],
			$this->label( $id !== '' ? 'editing' : 'creating' ) ) .
			Html::element( 'h2', [ 'class' => 'ff-automation-heading' ],
				$id !== '' ? ( $rule['name'] ?? $this->label( 'new' ) ) : $this->label( 'new' ) ) );
		if ( !$request->wasPosted() && $request->getBool( 'saved' ) ) {
			$out->addHTML( Html::element( 'p', [ 'class' => 'ff-automation-success', 'role' => 'status' ], $this->label( 'saved' ) ) );
		}
		$form = Html::hidden( 'token', $user->getEditToken() ) . Html::hidden( 'base', $base ) . Html::hidden( 'id', $id );
		$form .= '<section class="ff-automation-section">';
		$form .= self::input( $this->label( 'name' ), 'name', $rule['name'] ?? '' );
		$form .= Html::rawElement( 'label', [], Html::input( 'enabled', '1', 'checkbox',
			[ 'checked' => $rule['enabled'] ?? true ] ) . ' ' . htmlspecialchars( $this->label( 'enabled' ) ) );
		$form .= '</section><section class="ff-automation-section">';
		$form .= Html::element( 'h3', [], $this->label( 'pages' ) );
		$form .= Html::element( 'p', [ 'class' => 'ff-automation-help' ], $this->label( 'pages-help' ) );
		$form .= self::input( $this->label( 'selector' ), 'selector', $rule['selector'] ?? 'prefix',
			[ 'prefix' => $this->label( 'prefix' ), 'regex' => $this->label( 'regex' ) ] );
		$form .= self::input( $this->label( 'pattern' ), 'pattern', $rule['pattern'] ?? '' );
		$form .= '<div class="ff-automation-test">';
		$form .= self::input( $this->label( 'example' ), 'example', $request->getText( 'example' ) );
		$form .= Html::element( 'button', [ 'name' => 'operation', 'value' => 'test' ], $this->label( 'test' ) );
		$form .= '</div></section><section class="ff-automation-section">';
		$form .= Html::element( 'h3', [], $this->label( 'condition' ) );
		$form .= Html::element( 'p', [ 'class' => 'ff-automation-help' ], $this->label( 'condition-help' ) );
		$form .= '<div class="ff-automation-grid">';
		foreach ( [ 'slot', 'template', 'parameter' ] as $key ) {
			$form .= self::input( $this->label( $key ), $key, $rule[$key] ?? '' );
		}
		$form .= '</div>';
		$form .= self::input( $this->label( 'new-value' ), 'operator', $rule['operator'] ?? 'equals',
			[ 'equals' => $this->label( 'equals' ), 'differs' => $this->label( 'differs' ), 'contains' => $this->label( 'contains' ) ] );
		$form .= self::input( $this->label( 'comparison' ), 'value', $rule['value'] ?? '' );
		$form .= '</section><section class="ff-automation-section">';
		$form .= Html::element( 'h3', [], $this->label( 'actions' ) );
		$form .= Html::element( 'p', [ 'class' => 'ff-automation-help' ], $this->label( 'actions-help' ) );
		$rows = '';
		foreach ( $rule['actions'] ?? [] as $index => $action ) {
			$rows .= $this->action( $index, $action );
		}
		$form .= Html::rawElement( 'div', [ 'id' => 'ff-actions' ], $rows );
		$form .= Html::rawElement( 'template', [ 'id' => 'ff-action-template' ], $this->action( 'INDEX', [] ) );
		$form .= Html::element( 'button', [ 'type' => 'button', 'id' => 'ff-add' ], $this->label( 'add-action' ) );
		$form .= '</section><div class="ff-automation-footer">';
		$form .= Html::element( 'button', [ 'class' => 'ff-automation-primary', 'name' => 'operation', 'value' => 'save' ], $this->label( 'save' ) );
		if ( $id !== '' ) {
			$form .= Html::element( 'button', [ 'class' => 'ff-automation-delete', 'name' => 'operation', 'value' => 'delete' ], $this->label( 'delete' ) );
		}
		$form .= '</div>';
		$out->addHTML( Html::rawElement( 'form', [ 'method' => 'post', 'action' => $url->getLocalURL() ], $form ) );
		$out->addModules( 'ext.flexform.automations' );
		$out->addModuleStyles( 'ext.flexform.automations' );
		$out->addHTML( '</div></div>' );
		$out->addHTML( Html::element( 'h2', [], $this->label( 'failures' ) ) );
		foreach ( Events::db()->select( Events::TABLE, [ 'event_id', 'error' ], [ 'state' => 'failed' ],
			__METHOD__, [ 'LIMIT' => 50, 'ORDER BY' => 'finished_at DESC' ] ) as $failure ) {
			$out->addHTML( Html::element( 'p', [], $failure->event_id . ' — ' . $failure->error ) );
		}
	}

	private function action( $index, array $action ): string {
		$name = "actions[$index]";
		$labels = array_column( $this->options, 'label' );
		$row = Html::element( 'legend', [ 'class' => 'ff-automation-action-title' ], $this->label( 'action' ) );
		$row .= self::input( $this->label( 'action' ), $name . '[option]', $action['option'] ?? '',
			array_combine( array_keys( $this->options ), $labels ) );
		foreach ( $this->options as $key => $option ) {
			$html = '';
			foreach ( $option['fields'] ?? [] as $field => $spec ) {
				$choices = $spec['choices'] ?? null;
				$value = ( $action['option'] ?? '' ) === $key ? ( $action['values'][$field] ?? $spec['default'] ?? '' ) : ( $spec['default'] ?? '' );
				$html .= self::input( $spec['label'] ?? $field, $name . "[values][$key][$field]", $value,
					$choices ? array_combine( $choices, $choices ) : null );
				if ( isset( $spec['help'] ) ) {
					$html .= Html::element( 'p', [ 'class' => 'ff-automation-help' ], $spec['help'] );
				}
			}
			$row .= Html::rawElement( 'div', [ 'data-option' => $key ], $html );
		}
		$row .= Html::element( 'button', [ 'type' => 'button', 'class' => 'ff-up' ], $this->label( 'up' ) );
		$row .= Html::element( 'button', [ 'type' => 'button', 'class' => 'ff-remove' ], $this->label( 'remove' ) );
		return Html::rawElement( 'fieldset', [ 'class' => 'ff-automation-action' ], $row );
	}
}
