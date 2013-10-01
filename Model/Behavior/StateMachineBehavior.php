<?php
App::uses('ModelBehavior', 'Model');
App::uses('Model', 'Model');
App::uses('Inflector', 'Utility');

class StateMachineBehavior extends ModelBehavior {

	public $mapMethods = array(
		'/is([A-Z][a-zA-Z0-9]+)/' => 'is',
		'/when([A-Z][a-zA-Z0-9]+)/' => 'when',
		'/can([A-Z][a-zA-Z0-9]+)/' => 'can',
		'/on([A-Z][a-zA-Z0-9]+)/' => 'on'
	);

	public $transitionListeners = array(
		'transition' => array(
			'before' => array(),
			'after' => array()
		)
	);

	public $stateListeners = array();

	public $currentState;

	public $previousState;

	public $methods = array();

	public function setup(Model $model, $config = array()) {
		parent::setup($model, $config);

		$this->currentState = $model->initialState;
		$availableStates = array();

		foreach ($model->transitions as $transition => $states) {
			foreach ($states as $stateFrom => $stateTo) {
				if (! in_array($stateFrom, $availableStates)) {
					$availableStates[] = $stateFrom;
				}

				if (! in_array($stateTo, $availableStates)) {
					$availableStates[] = $stateTo;
				}
			}

			$this->addMethod($model, 'can' . Inflector::camelize($transition), function($func) use($model) {
				return !!$this->getStates($model, $this->_deFormalizeMethodName($func));
			});

			$transitionFunction = Inflector::variable($transition);
			$this->mapMethods['/(' . $transitionFunction . ')/'] = 'transition';

			$this->addMethod($model, $transitionFunction, function($func) use($model) {
				$transition = $this->_deFormalizeMethodName($func);
				$statesTo = $this->getStates($model, $transition);

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
			});
		}

		foreach ($availableStates as $state) {
			$this->addMethod($model, 'is' . Inflector::camelize($state), function($func) {
				return $this->currentState === $this->_deFormalizeMethodName($func);
			});
		}
	}

	public function addMethod(Model $model, $name, Closure $method) {
		$this->methods[$name] = $method;
	}

	protected function _handleMethodCall($type, $method, $args = array()) {
		if (strlen($type) > 0) {
			$method = strpos($method, $type) === 0 ? substr($method, strlen($type)) : $method;
			$formalized = $type . Inflector::camelize($method);
		} else {
			$formalized = Inflector::variable($method);
		}

		array_unshift($args, $formalized);
		if (isset($this->methods[$formalized])) {
			return call_user_func_array($this->methods[$formalized], $args);
		}

		throw new BadMethodCallException('Method ' . $formalized . ' does not exists');
	}

	public function transition(Model $model, $transition) {
		return $this->_handleMethodCall(null, $transition);
	}

	public function is(Model $model, $state) {
		return $this->_handleMethodCall('is', $state, array($this->currentState));
	}

	public function can(Model $model, $transition) {
		return $this->_handleMethodCall('can', $transition);
	}

	public function on(Model $model, $transition, $triggerType, Closure $cb, $bubble = true) {
		$this->transitionListeners[strtolower($transition)][$triggerType][] = array(
			'cb' => $cb,
			'bubble' => $bubble
		);
	}

	public function when(Model $model, $state, Closure $cb) {
		$this->stateListeners[strtolower($state)][] = $cb;
	}

	public function getStates(Model $model, $transition) {
		if (! isset($model->transitions[$transition])) {
			// transition doesn't exist
			return false;
		}

		// get the states the machine can move from and to
		$states = $model->transitions[$transition];

		if (! isset($states[$this->currentState]) && ! isset($states['all'])) {
			// we canno move from the current state
			return false;
		}

		return isset($states[$this->currentState]) ? $states[$this->currentState] : $states['all'];
	}

	public function getCurrentState(Model $model) {
		return $this->currentState;
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

	protected function _deFormalizeMethodName($name) {
		$name = preg_replace('#^(can|is)#', '', $name);
		return Inflector::underscore($name);
	}
}
