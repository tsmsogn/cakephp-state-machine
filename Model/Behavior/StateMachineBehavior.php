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
		if (!isset($this->settings[$model->alias])) {
			$this->settings[$model->alias] = array(
				'transition_listeners' => array(
					'transition' => array(
						'before' => array(),
						'after' => array()
					)
				),
				'state_listeners' => array(),
				'methods' => array()
			);
		}

		$this->_initializeMethods($model);
	}

/**
 * Initializes the methods that the model can call
 * @param	Model	$model	The model being acted on
 * @return	void
 */
	protected function _initializeMethods(Model $model) {
		foreach ($model->transitions as $transition => $states) {
			foreach ($states as $stateFrom => $stateTo) {
				$methodName = '/is(' . Inflector::camelize($stateFrom) . ')/';
				if (! isset($this->mapMethods[$methodName])) {
					$this->mapMethods[$methodName] = 'is';
				}

				$methodName = '/is(' . Inflector::camelize($stateTo) . ')/';
				if (! isset($this->mapMethods[$methodName])) {
					$this->mapMethods[$methodName] = 'is';
				}
			}

			$this->mapMethods['/can(' . Inflector::camelize($transition) . ')/'] = 'can';

			$transitionFunction = Inflector::variable($transition);
			$this->mapMethods['/(' . $transitionFunction . ')/'] = 'transition';
		}
	}

	public function addMethod(Model $model, $method, Callable $cb) {
		$this->settings[$model->alias]['methods'][$method] = $cb;
		$this->mapMethods['/' . $method . '/'] = 'handleMethodCall';

		// force model to re-load Behavior, so that the mapMethods are working correctly
		$model->Behaviors->load('Statemachine.StateMachine');
	}

	public function handleMethodCall(Model $model, $method) {
		if (isset($this->settings[$model->alias]['methods'][$method])) {
			return call_user_func_array($this->settings[$model->alias]['methods'][$method], func_get_args());
		}

		return array('unhandled');
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
 * Allows moving from one state to another.
 * {{{
 * $this->Model->transition('shift_gear');
 * // or
 * $this->Model->shiftGear();
 * }}}
 *
 * @param Model $model The model being acted on
 * @param string $transition The transition being initiated
 */
	public function transition(Model $model, $transition) {
		$transition = Inflector::underscore($transition);
		$statesTo = $this->getStates($model, $transition);

		if (! $statesTo) {
			return false;
		}

		$this->_callListeners($model, $transition, 'before');

		$model->read(null, $model->id);
		$model->set('previous_state', $model->getCurrentState());
		$model->set('state', $statesTo);
		$retval = $model->save();

		$this->_callListeners($model, $transition, 'after');

		$stateListeners = array();

		if (isset($this->settings[$model->alias]['state_listeners'][$statesTo])) {
			$stateListeners = $this->settings[$model->alias]['state_listeners'][$statesTo];
		}

		if (method_exists($model, 'onState' . Inflector::camelize($statesTo))) {
			$stateListeners[] = array($model, 'onState' . Inflector::camelize($statesTo));
		}

		if (method_exists($model, 'onStateChange')) {
			$stateListeners[] = array($model, 'onStateChange');
		}

		foreach ($stateListeners as $cb) {
			if (is_array($cb) && method_exists($cb[0], $cb[1])) {
				call_user_func($cb, $statesTo);
			} elseif ($cb instanceof Closure || is_callable($cb)) {
				$cb($statesTo);
			}
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
 * @return boolean whether or not the machine can perform the transition
 * @throws BadMethodCallException when method does not exists
 */
	public function can(Model $model, $transition) {
		return !!$this->getStates($model, $this->_deFormalizeMethodName($transition));
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
		$this->settings[$model->alias]['transition_listeners'][strtolower($transition)][$triggerType][] = array(
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
		$this->settings[$model->alias]['state_listeners'][strtolower($state)][] = $cb;
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

		if (! isset($states[$model->getCurrentState()]) && ! isset($states['all'])) {
			// we canno move from the current state
			return false;
		}

		return isset($states[$model->getCurrentState()]) ? $states[$model->getCurrentState()] : $states['all'];
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
 * Calls transition listeners before or after a particular transition
 *
 * @param	string	$transition		The transition name
 * @param	string	$triggerType	Either before or after
 */
	protected function _callListeners(Model $model, $transition, $triggerType = 'after') {
		$listeners = array();
		if (isset($this->settings[$model->alias]['transition_listeners'][$transition][$triggerType])) {
			$listeners = $this->settings[$model->alias]['transition_listeners'][$transition][$triggerType];
		}

		$listeners = array_merge($listeners, $this->settings[$model->alias]['transition_listeners']['transition'][$triggerType]);

		if (method_exists($model, 'on' . Inflector::camelize($triggerType . 'Transition'))) {
			$listeners[] = array(
				'cb' => array(
					$model,
					'on' . Inflector::camelize($triggerType . 'Transition')
				),
				'bubble' => true
			);
		}

		if (method_exists($model, 'on' . Inflector::camelize($triggerType . $transition))) {
			$listeners[] = array(
				'cb' => array(
					$model,
					'on' . Inflector::camelize($triggerType . $transition)
				),
				'bubble' => true
			);
		}

		foreach ($listeners as $cb) {
			if (is_array($cb['cb']) && method_exists($cb['cb'][0], $cb['cb'][1])) {
				call_user_func($cb['cb'], $this->getCurrentState($model), $this->getPreviousState($model), $transition);
			} elseif ($cb['cb'] instanceof Closure) {
				$cb['cb']($this->getCurrentState($model), $this->getPreviousState($model), $transition);
			}

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
