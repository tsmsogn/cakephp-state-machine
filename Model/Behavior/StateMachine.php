<?php
/**
 *  State Machine in PHP.
 *  
 *  A state machine is a series on events and transitions. You cannot move from one event to another, unless the 
 *  transitions allows this.
 */

class StateMachine {

	public $name;
	
	public $initialState;
	
	public $currentState;
	
	public $previousState;
	
	public $transitions = array();
	
	public $transitionListeners = array(
		'transition' => array(
			'before' => array(),
			'after'	=> array()
		)
	);
	
	public $stateListeners = array();
	
	public $methods = array();
	
	public function __construct($name, $initial_state, $transitions) {
		$this->name = $name;
		$this->initialState = $this->currentState = $initial_state;
		$this->transitions = $transitions;
		
		$availableStates = array();
		
		foreach ($transitions as $transition => $states) {
			foreach ($states as $state_from => $state_to) {
				if (! in_array($state_from, $availableStates)) {
					$availableStates[] = $state_from;
				}
				
				if (! in_array($state_to, $availableStates)) {
					$availableStates[] = $state_to;
				}
			}
			
			$this->addMethod('can' . $this->formalizeMethodName($transition), function($func) {
				return !!$this->getStates($this->deFormalizeMethodName($func));
			});
			
			$this->addMethod($this->formalizeMethodName($transition, true), function($func) {
				$transition = $this->deFormalizeMethodName($func);
				// the states we are allowed to switch to
				$statesTo = $this->getStates($transition);
				
				// perform transition $transition
				if (! $statesTo) {
					return false;
				}
				
				$this->callListeners($transition, 'before');
				
				$this->previousState = $this->currentState;
				$this->currentState = $statesTo;
				
				$this->callListeners($transition, 'after');
				
				if (isset($this->stateListeners[$this->currentState])) {
					foreach ($this->stateListeners[$this->currentState] as $cb) {
						$cb();
					}
				}
				
				return $this->currentState;
			});
		}
		
		foreach ($availableStates as $state) {
			$this->addMethod('is' . $this->formalizeMethodName($state), function($func) {
				return $this->currentState === $this->deFormalizeMethodName($func);
			});
		}
	}
	
	/**
	 *  StateMachine->is<State>		i.e. StateMachine->isParked()
	 *  StateMachine->can<Action>	i.e. StateMachine->canShiftGear()
	 *  StateMachine-><action>		i.e. StateMachine->shiftGear();
	 */
	public function __call($function, array $args = array()) {
		if (method_exists($this, $function)) {
			return call_user_func_array(array ($this, $function), $args);
		}
		
		if (isset($this->methods[$function])) {
			return $this->methods[$function]($function, $this->currentState);
		}
		
		if (preg_match('#^on([a-zA-Z0-9_-]+)#', $function, $matches)) {
			if ($args[0] instanceof Closure) {
				array_unshift($args, 'after');
			}
			
			array_unshift($args, $matches[1]);
			
			return call_user_func_array(array($this, 'on'), $args);
		}
		
		throw new Exception('Method does not exist: ' . $function);
	}
	
	/**
	 *  Returns a formalized method name
	 *  shift_gear => shiftGear
	 */
	protected function formalizeMethodName($name, $camelCase = false) {
		$name = ucwords(str_replace('_', ' ', $name));
		
		if ($camelCase) {
			// first letter must be lowercased
			$names = explode(' ', $name);
			return strtolower($names[0]) . implode('', array_slice($names, 1));
		}
		
		return str_replace (' ', '', $name);
	}
	
	protected function deFormalizeMethodName($name) {
		$name = preg_replace('#^(can|is)#', '', $name);
		$names = preg_split('/([[:upper:]][[:lower:]]+)/', $name, null, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
		$name = strtolower(implode('_', $names));
		
		return $name;
	}
	
	public function addMethod($method, Closure $cb) {
		$this->methods[$method] = $cb;
	}
	
	protected function callListeners($transition, $triggerType = 'after') {
		$listeners = array();
		if (isset($this->transitionListeners[$transition][$triggerType])) {
			$listeners = $this->transitionListeners[$transition][$triggerType];
		}
		
		$listeners = array_merge($listeners, $this->transitionListeners['transition'][$triggerType]);
		
		foreach ($listeners as $cb) {
			$cb['cb']($this->currentState, $this->previousState, $transition);
			
			if (! $cb['bubble']) {
				break;
			}
		}
	}
	
	public function on($transition, $triggerType = 'after', Closure $cb, $bubble = true) {
		$this->transitionListeners[strtolower($transition)][$triggerType][] = array(
			'cb' => $cb,
			'bubble' => $bubble
		);
	}
	
	public function when($state, Closure $cb) {
		$this->stateListeners[strtolower($state)][] = $cb;
	}
	
	/**
	 *  Returns the states the machine can be in, if $transition
	 *  is performed.
	 */
	public function getStates($transition) {
		if (! isset($this->transitions[$transition])) {
			// transition doesn't exist
			return false;
		}
		
		// get the states the machine can move from and to
		$states = $this->transitions[$transition];
		
		if (! isset($states[$this->currentState]) && ! isset($states['all'])) {
			// we canno move from the current state
			return false;
		}
		
		return isset($states[$this->currentState]) ? $states[$this->currentState] : $states['all'];
	}
}