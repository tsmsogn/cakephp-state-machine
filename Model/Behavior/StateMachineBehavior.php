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
		'/when([A-Z][a-zA-Z0-9]+)/' => 'when',
		'/on([A-Z][a-zA-Z0-9]+)/' => 'on'
	);

	protected $_defaultSettings = array(
		'transition_listeners' => array(
			'transition' => array(
				'before' => array(),
				'after' => array()
			)
		),
		'state_listeners' => array(),
		'methods' => array()
	);

/**
 * Array of all configured states. Initialized by self::setup()
 * @var array
 */
	protected $_availableStates = array();

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
		if (! isset($this->settings[$model->alias])) {
			$this->settings[$model->alias] = $this->_defaultSettings;
		}

		foreach ($model->transitions as $transition => $states) {
			foreach ($states as $stateFrom => $stateTo) {
				$this->_availableStates[] = Inflector::camelize($stateFrom);
				foreach (array(
					'is' . Inflector::camelize($stateFrom),
					'is' . Inflector::camelize($stateTo)
				) as $methodName) {
					if (! $this->_hasMethod($model, $methodName)) {
						$this->mapMethods['/' . $methodName . '/'] = 'is';
					}
				}
			}

			$this->mapMethods['/can' . Inflector::camelize($transition) . '/'] = 'can';

			$transitionFunction = Inflector::variable($transition);
			$this->mapMethods['/' . $transitionFunction . '/'] = 'transition';
		}
	}

/**
 * Adds a user defined callback
 * {{{
 * $this->Vehicle->addMethod('myMethod', function() {});
 * $data = $this->Vehicle->myMethod();
 * }}}
 *
 * @param	Model		$model	The model being acted on
 * @param	string		$method	The method na,e
 * @param	Callable	$cb		The callback to execute
 * @throws	InvalidArgumentException	If the method already is registered
 * @return	void
 */
	public function addMethod(Model $model, $method, Callable $cb) {
		if ($this->_hasMethod($model, $method)) {
			throw new InvalidArgumentException("A method with the same name is already registered");
		}

		$this->settings[$model->alias]['methods'][$method] = $cb;
		$this->mapMethods['/' . $method . '/'] = 'handleMethodCall';

		// force model to re-load Behavior, so that the mapMethods are working correctly
		$model->Behaviors->load('Statemachine.StateMachine');
	}

/**
 * Handles user defined method calls, which are implemented using closures.
 *
 * @param	Model	$model	The model being acted on
 * @param	string	$method	The method name
 * @return	mixed			The return value of the callback, or an array if the method doesn't exist
 */
	public function handleMethodCall(Model $model, $method) {
		if (! isset($this->settings[$model->alias]['methods'][$method])) {
			return array('unhandled');
		}

		return call_user_func_array($this->settings[$model->alias]['methods'][$method], func_get_args());
	}

/**
 * Updates the model's state when a $model->save() call is performed
 * @param	Model	$model		The model being acted on
 * @param	boolean $created	Whether or not the model was created
 * @param	array	$options	Options passed to save
 * @return	boolean
 */
	public function afterSave(Model $model, $created, $options = array()) {
		if ($created) {
			$model->read();
			$model->saveField('state', $model->initialState);
		}

		return true;
	}

/**
 * Finds all records in a specific state. Supports additional conditions, but will overwrite conditions with state
 * @param  Model  $model    The model being acted on
 * @param  [type] $state    The state to find. this will be checked for validity.
 * @param  array  $params   Regular $params array for CakeModel->find
 * @return array            Returns datarray of $model records or false. Will return false if state is not set, or state is not configured in model
 * @author Frode Marton Meling
 */
	public function findByState(Model $model, $state = null, $params = array()) {
		if ($state === null || ! in_array(Inflector::camelize($state), $this->_availableStates)) {
			return false;
		}

		if (Inflector::camelize($state) == 'All') {
			return $model->find('all', $params);
		} else {
			$params['conditions']["{$model->alias}.state"] = $state;
			return $model->find('all', $params);
		}
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
 * @param string $role The rule executing the transition
 * @param string $transition The transition being initiated
 */
	public function transition(Model $model, $transition, $role = null) {
		$transition = Inflector::underscore($transition);
		$state = $this->getStates($model, $transition);

		if (! $state || $this->_checkRoleAgainstRule($model, $role, $transition) === false) {
			return false;
		}

		$this->_callTransitionListeners($model, $transition, 'before');

		$model->read(null, $model->id);
		$model->set('previous_state', $model->getCurrentState());
		$model->set('state', $state);
		$retval = $model->save();

		$this->_callTransitionListeners($model, $transition, 'after');

		$stateListeners = array();
		if (isset($this->settings[$model->alias]['state_listeners'][$state])) {
			$stateListeners = $this->settings[$model->alias]['state_listeners'][$state];
		}

		foreach (array(
			'onState' . Inflector::camelize($state),
			'onStateChange'
		) as $method) {
			if (method_exists($model, $method)) {
				$stateListeners[] = array($model, $method);
			}
		}

		foreach ($stateListeners as $cb) {
			$cb($state);
		}

		return (bool)$retval;
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
		return $this->getCurrentState($model) === $this->_deFormalizeMethodName($state);
	}

/**
 * Checks whether or not the machine is able to perform transition, in its current state
 *
 * @param Model $model The model being acted on
 * @param string $transition The transition being checked
 * @param string $role The role which should execute the transition
 * @return boolean whether or not the machine can perform the transition
 * @throws BadMethodCallException when method does not exists
 */
	public function can(Model $model, $transition, $role = null) {
		$transition = $this->_deFormalizeMethodName($transition);

		if (! $this->getStates($model, $transition) || $this->_checkRoleAgainstRule($model, $role, $transition) === false) {
			return false;
		}

		return true;
	}

/**
 * Registers a callback function to be called when the machine leaves one state.
 * The callback is fired either before or after the given transition.
 *
 * @param	Model	$model			The model being acted on
 * @param	string	$transition		The transition to listen to
 * @param	string	$triggerType	Either before or after
 * @param	Callable	$cb				The callback function that will be called
 * @param	Boolean	$bubble			Whether or not to bubble other listeners
 */
	public function on(Model $model, $transition, $triggerType, Callable $cb, $bubble = true) {
		$this->settings[$model->alias]['transition_listeners'][Inflector::underscore($transition)][$triggerType][] = array(
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
 * @param	Callable	$cb		The callback function that will be called
 */
	public function when(Model $model, $state, Callable $cb) {
		$this->settings[$model->alias]['state_listeners'][Inflector::underscore($state)][] = $cb;
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
		$currentState = $model->getCurrentState();

		if (isset($states[$currentState])) {
			return $states[$currentState];
		}

		if (isset($states['all'])) {
			return $states['all'];
		}

		return false;
	}

/**
 * Returns the current state of the machine
 * @param	Model	$model	The model being acted on
 * @return	string			The current state of the machine
 */
	public function getCurrentState(Model $model) {
		return $model->field('state');
	}

/**
 * Returns the previous state of the machine
 * @param	Model	$model	The model being acted on
 * @return	string			The previous state of the machine
 */
	public function getPreviousState(Model $model) {
		return $model->field('previous_state');
	}

/**
 * Simple method to return contents for a GV file, that
 * can be made into graphics by:
 * {{{
 * dot -Tpng -ofsm.png fsm.gv
 * }}}
 * Assuming that the contents are written to the file fsm.gv
 *
 * @param	Model	$model	The model being acted on
 * @return	string			The contents of the graphviz file
 */
	public function toDot(Model $model) {
		$digraph = <<<EOT
digraph finite_state_machine {
	rankdir=LR
	fontsize=12
	node [shape = circle];

EOT;

		foreach ($model->transitions as $transition => $states) {
			foreach ($states as $stateFrom => $stateTo) {
				$digraph .= sprintf("\t%s -> %s [ label = \"%s\" ];\n", $stateFrom, $stateTo, $transition);
			}
		}

		return $digraph . "}";
	}

/**
 * Checks whether or not the given role may perform the transition change.
 * The callback in 'depends' must be a valid model method.
 *
 * @param	Model	$model		The model being acted on
 * @param	string	$role		The role executing the transition change
 * @param	string	$transition	The transition
 * @throws	InvalidArgumentException	if the transition require it be executed by a rule, and none is given
 * @return	boolean				Whether or not the role may perform the action
 */
	protected function _checkRoleAgainstRule(Model $model, $role, $transition) {
		if (! isset($model->transitionRules[$transition])) {
			return null;
		}

		if (! $role) {
			throw new InvalidArgumentException('The transition ' . $transition . ' requires a role');
		}

		if (! in_array($role, $model->transitionRules[$transition]['role'])) {
			return false;
		}

		if (! isset($model->transitionRules[$transition]['depends'])) {
			return true;
		}

		$callback = Inflector::variable($model->transitionRules[$transition]['depends']);

		if ($this->_hasMethod($model, $callback)) {
			// Fix: if the method is supplied as an anonymous callback, we cannot call
			// it from the model directly
			$res = $this->settings[$model->alias]['methods'][$callback]($role);
		} else {
			$res = call_user_func(array($model, $callback), $role);
		}

		return $res;
	}

/**
 * Calls transition listeners before or after a particular transition.
 * Special model methods are also called, if they exist:
 * - onBeforeTransition
 * - onAfterTransition
 * - onBefore<Transition>	i.e. onBeforePark()
 * - onAfter<Transition>	i.e. onAfterPark()
 *
 * @param	string	$transition		The transition name
 * @param	string	$triggerType	Either before or after
 */
	protected function _callTransitionListeners(Model $model, $transition, $triggerType = 'after') {
		$transitionListeners = &$this->settings[$model->alias]['transition_listeners'];
		$listeners = $transitionListeners['transition'][$triggerType];

		if (isset($transitionListeners[$transition][$triggerType])) {
			$listeners = array_merge($transitionListeners[$transition][$triggerType], $listeners);
		}

		foreach (array(
			'on' . Inflector::camelize($triggerType . 'Transition'),
			'on' . Inflector::camelize($triggerType . $transition)
		) as $method) {
			if (method_exists($model, $method)) {
				$listeners[] = array(
					'cb' => array($model, $method),
					'bubble' => true
				);
			}
		}

		$currentState = $this->getCurrentState($model);
		$previousState = $this->getPreviousState($model);

		foreach ($listeners as $cb) {
			$cb['cb']($currentState, $previousState, $transition);

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
		return Inflector::underscore(preg_replace('#^(can|is)#', '', $name));
	}

/**
 * Checks whether or not a user-defined method exists in the Behavior
 *
 * @param	Model	$model	The model being acted on
 * @param	string	$method	The method's name
 * @return	boolean			True if the method exists, false otherwise
 */
	protected function _hasMethod(Model $model, $method) {
		return isset($this->settings[$model->alias]['methods'][$method]) || isset($this->mapMethods['/' . $method . '/']);
	}
}
