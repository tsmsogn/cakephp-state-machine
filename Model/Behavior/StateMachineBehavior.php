<?php
/**
 * StateMachineBehavior
 *
 * A finite state machine is a machine that cannot move between states unless
 * a specific transition fired. It has a specified amount of legal directions it can
 * take from each state. It also supports state listeners and transition listeners.
 *
 * @author David Steinsland
 */
App::uses('Model', 'Model');
App::uses('Inflector', 'Utility');

class StateMachineBehavior extends ModelBehavior {

/**
 * Allows us to support writing both: is('parked') and isParked()
 *
 * @var array
 */
	public $mapMethods = array(
		'/is([A-Z][a-zA-Z0-9]+)/' => 'is',
		'/when([A-Z][a-zA-Z0-9]+)/' => 'when',
		'/can([A-Z][a-zA-Z0-9]+)/' => 'can',
		'/on([A-Z][a-zA-Z0-9]+)/' => 'on'
	);

/**
 * Transition listeners are fired when the state machine goes from one state to another.
 *
 * @see StateMachineBehavior::on()
 * @var array
 */
	public $transitionListeners = array(
		'transition' => array(
			'before' => array(),
			'after' => array()
		)
	);

/**
 * A more generic way of adding state listeners. These are fired after the transition listeners,
 * and after the state has been changed.
 *
 * @see StateMachineBehavior::when()
 * @var array
 */
	public $stateListeners = array();

/**
 * The current state of the machine
 *
 * @see StateMachineBehavior::getCurrentState()
 * @var string
 */
	public $currentState;

/**
 * The previous state of the machine
 *
 * @var string
 */
	public $previousState;

/**
 * All the methods that the state machine has implemented. User defined
 * methods may be added via addMethod().
 *
 * @see StateMachineBehavior::addMethod()
 * @var array
 */
	public $methods = array();

/**
 * Sets up all the methods that builds up the state machine.
 * StateMachine->is<State>		    i.e. StateMachine->isParked()
 * StateMachine->can<Transition>	i.e. StateMachine->canShiftGear()
 * StateMachine-><transition>		i.e. StateMachine->shiftGear();
 *
 * @param	Model	$model The model being used
 * @param	array	$config	Configuration for the Behavior
 * @return void
 */
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

				$this->_callListeners($transition, 'before');

				$this->previousState = $this->currentState;
				$this->currentState = $statesTo;

				$this->_callListeners($transition, 'after');

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

/**
 * Creates a method for the State Machine. Can also be used
 * to create a user defined method, like so:
 *
 * {{{
 * $this->Model->addMethod('isMoving', function($f, $currentState) {
 *     return in_array($currentState, array('first_gear', 'second_gear', 'third_gear'));
 * });
 *
 * var_dump($this->Model->isMoving());
 * }}}
 *
 * @param	Model	$model	The model which the behavior belongs to
 * @param	object	$name	The name of the method
 * @param	Closure	$method	The callback which will be fired
 * @return void
 */
	public function addMethod(Model $model, $name, Closure $method) {
		$this->methods[$name] = $method;
	}

/**
 * Handles method calls to is(), on(), when() and can().
 * Formats the method name such that it is like Inflector::variable()
 *
 * Transition: shift_up => canShiftUp() and shiftUp()
 * State: second_gear   => isSecondGear()
 *
 * @param string $type The type of the method call: can|is|on|when
 * @param string $method The method name
 * @param array $args Arguments for the callback function
 * @throws BadMethodCallException when method does not exists
 */
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

/**
 * Allows moving from one state to another.
 * {{{
 * $this->Model->transition('shift_gear');
 * // or
 * $this->Model->shiftGear();
 * }}}
 *
 * @param Model $model The model being acted on
 * @param string $transition The transition being initiated
 * @throws BadMethodCallException when method does not exists
 */
	public function transition(Model $model, $transition) {
		return $this->_handleMethodCall(null, $transition);
	}

/**
 * Checks whether the state machine is in the given state
 *
 * @param Model $model The model being acted on
 * @param string $state The state being checked
 * @return boolean whether or not the state machine is in the given state
 * @throws BadMethodCallException when method does not exists
 */
	public function is(Model $model, $state) {
		return $this->_handleMethodCall('is', $state, array($this->currentState));
	}

/**
 * Checks whether or not the machine is able to perform transition, in its current state
 *
 * @param Model $model The model being acted on
 * @param string $transition The transition being checked
 * @return boolean whether or not the machine can perform the transition
 * @throws BadMethodCallException when method does not exists
 */
	public function can(Model $model, $transition) {
		return $this->_handleMethodCall('can', $transition);
	}

/**
 * Registers a callback function to be called when the machine leaves one state.
 * The callback is fired either before or after the given transition.
 *
 * @param	Model	$model			The model being acted on
 * @param	string	$transition		The transition to listen to
 * @param	string	$triggerType	Either before or after
 * @param	Closure	$cb				The callback function that will be called
 * @param	Boolean	$bubble			Whether or not to bubble other listeners
 */
	public function on(Model $model, $transition, $triggerType, Closure $cb, $bubble = true) {
		$this->transitionListeners[strtolower($transition)][$triggerType][] = array(
			'cb' => $cb,
			'bubble' => $bubble
		);
	}

/**
 * Registers a callback that will be called when the state machine enters the given
 * state.
 *
 * @param	Model	$model	The model being acted on
 * @param	string	$state	The state which the machine should enter
 * @param	Closure	$cb		The callback function that will be called
 */
	public function when(Model $model, $state, Closure $cb) {
		$this->stateListeners[strtolower($state)][] = $cb;
	}

/**
 * Returns the states the machine would be in, after the given transition
 *
 * @param	Model	$model		The model being acted on
 * @param	string	$transition	The transition name
 * @return	mixed				False if the transition doesnt yield any states, or an array of states
 */
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

/**
 * Returns the current state of the machine
 * @param	Model	$model	The model being acted on
 * @return	string			The current state of the machine
 */
	public function getCurrentState(Model $model) {
		return $this->currentState;
	}

/**
 * Calls transition listeners before or after a particular transition
 *
 * @param	string	$transition		The transition name
 * @param	string	$triggerType	Either before or after
 */
	protected function _callListeners($transition, $triggerType = 'after') {
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

/**
 * Deformalizes a method name, removing 'can' and 'is' as well as underscoring
 * the remaining text.
 *
 * @param	string	$name	The model name
 * @return	string			The deformalized method name
 */
	protected function _deFormalizeMethodName($name) {
		$name = preg_replace('#^(can|is)#', '', $name);
		return Inflector::underscore($name);
	}
}
