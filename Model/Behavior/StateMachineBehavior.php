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

	protected function _addAvailableState($state) {
		if ($state != 'All' && !in_array($state, $this->_availableStates)) {
			$this->_availableStates[] = Inflector::camelize($state);
		}
	}

/**
 * Sets up all the methods that builds up the state machine.
 * StateMachine->is<State>		    i.e. StateMachine->isParked()
 * StateMachine->can<Transition>	i.e. StateMachine->canShiftGear()
 * StateMachine-><transition>		i.e. StateMachine->shiftGear();
 *
 * @param Model $model The model being used
 * @param array $config Configuration for the Behavior
 * @return void
 */
	public function setup(Model $model, $config = array()) {
		if (! isset($this->settings[$model->alias])) {
			$this->settings[$model->alias] = $this->_defaultSettings;
		}

		foreach ($model->transitions as $transition => $states) {
			foreach ($states as $stateFrom => $stateTo) {
				$this->_addAvailableState(Inflector::camelize($stateFrom));
				$this->_addAvailableState(Inflector::camelize($stateTo));
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
			$this->mapMethods['/^' . $transitionFunction . 'ById' . '$/'] = 'transitionById';
			$this->mapMethods['/^' . $transitionFunction . '$/'] = 'transition';
		}
	}

/**
 * Adds a user defined callback
 * {{{
 * $this->Vehicle->addMethod('myMethod', function() {});
 * $data = $this->Vehicle->myMethod();
 * }}}
 *
 * @param	Model $model The model being acted on
 * @param string $method The method na,e
 * @param Callable $cb The callback to execute
 * @throws InvalidArgumentException If the method already is registered
 * @return void
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
 * @param Model $model The model being acted on
 * @param string $method The method name
 * @return mixed The return value of the callback, or an array if the method doesn't exist
 */
	public function handleMethodCall(Model $model, $method) {
		if (! isset($this->settings[$model->alias]['methods'][$method])) {
			return array('unhandled');
		}
		return call_user_func_array($this->settings[$model->alias]['methods'][$method], func_get_args());
	}

/**
 * Updates the model's state when a $model->save() call is performed
 * 
 * @param Model	$model		The model being acted on
 * @param boolean $created	Whether or not the model was created
 * @param array	$options	Options passed to save
 * @return boolean
 */
	public function afterSave(Model $model, $created, $options = array()) {
		if ($created) {
			$model->read();
			$model->saveField('state', $model->initialState);
		}

		return true;
	}

/**
 * returns all transitions defined in model 
 * 
 * @param Model $model  The model being acted on
 * @return array array of transitions
 * @author Frode Marton Meling
 */
	public function getAllTransitions($model) {
		$transitionArray = array();
		foreach ($model->transitions as $transition => $data) {
			$transitionArray[] = $transition;
		}
		return $transitionArray;
	}

/**
 * Returns an array of all configured states
 * 
 * @return array
 */
	public function getAvailableStates() {
		return $this->_availableStates;
	}

/**
 * checks if $state or Array of states are valid ones
 * 
 * @param string/array $state a string representation of state or a array of states
 * @return boolean
 * @author Frode Marton Meling
 */
	protected function _validState($state) {
		$availableStatesIncludingAll = array_merge(array('All'), $this->_availableStates);
		if (!is_array($state)) {
			return in_array(Inflector::camelize($state), $availableStatesIncludingAll);
		}

		foreach ($state as $singleState) {
			if (!in_array(Inflector::camelize($singleState), $availableStatesIncludingAll)) {
				return false;
			}
		}
		return true;
	}

/**
 * Finds all records in a specific state. Supports additional conditions, but will overwrite conditions with state
 * 
 * @param Model        $model    The model being acted on
 * @param string       $type     find type (ref. CakeModel)
 * @param array/string $state    The state to find. this will be checked for validity.
 * @param array        $params   Regular $params array for CakeModel->find
 * @return array            Returns datarray of $model records or false. Will return false if state is not set, or state is not configured in model
 * @author Frode Marton Meling
 */
	protected function _findByState(Model $model, $type, $state = null, $params = array()) {
		if ($state === null || ! $this->_validState($state)) {
			return false;
		}

		if (is_array($state) || Inflector::camelize($state) != 'All') {
			$params['conditions']["{$model->alias}.state"] = $state;
		}
		return $model->find($type, $params);
	}

/**
 * This function will add all availble (runnable) transitions on a model and add it to the dataArray given to the function.
 * 
 * @param Model        $model        The model being acted on
 * @param array        $modelRows    The model dataArray. this is an array of Models returned from a model->find.
 * @param string       $role         if specified, the function will limit the transitions based on a role
 * @return array        Returns datarray of $model with the available transitions inserted
 * @author Frode Marton Meling
 */
	protected function _addTransitionsToArray($model, $modelRows, $role) {
		if (!isset($modelRows) || $modelRows == false) {
			return $modelRows;
		}

		$allTransitions = $this->getAllTransitions($model);
		foreach ($modelRows as $key => $modelRow) {
			$model->id = $modelRow[$model->alias]['id'];
			// Note! We need this empty array if no transitions are availble. then we do not need to test if array exist in views.
			$modelRows[$key][$model->alias]['Transitions'] = array();
			foreach ($allTransitions as $transition) {
				if ($model->can($transition, $role)) {
					$modelRows[$key][$model->alias]['Transitions'][] = $transition;
				}
			}
		}
		return $modelRows;
	}

/**
 * Finds all records in a specific state. Supports additional conditions, but will overwrite conditions with state
 * 
 * @param Model        $model    The model being acted on
 * @param array/string $state    The state to find. this will be checked for validity.
 * @param array        $params   Regular $params array for CakeModel->find
 * @return array            Returns datarray of $model records or false. Will return false if state is not set, or state is not configured in model
 * @author Frode Marton Meling
 */
	public function findAllByState(Model $model, $state = null, $params = array(), $withTransitions = true, $role = null) {
		$modelRows = $this->_findByState($model, 'all', $state, $params);
		return ($withTransitions) ? $this->_addTransitionsToArray($model, $modelRows, $role) : $modelRows;
	}

/**
 * Finds first record in a specific state. Supports additional conditions, but will overwrite conditions with state
 * 
 * @param Model  $model    The model being acted on
 * @param array/string $state    The state to find. this will be checked for validity.
 * @param array  $params   Regular $params array for CakeModel->find
 * @return array            Returns datarray of $model records or false. Will return false if state is not set, or state is not configured in model
 * @author Frode Marton Meling
 */
	public function findFirstByState(Model $model, $state = null, $params = array(), $withTransitions = true, $role = null) {
		$modelRow = $this->_findByState($model, 'first', $state, $params);
		return ($withTransitions) ? $this->_addTransitionsToArray($model, ($modelRow) ? array($modelRow) : false, $role) : $modelRow;
	}

/**
 * Finds count of records in a specific state. Supports additional conditions, but will overwrite conditions with state
 * 
 * @param Model  $model    The model being acted on
 * @param array/string $state    The state to find. this will be checked for validity.
 * @param array  $params   Regular $params array for CakeModel->find
 * @return array            Returns datarray of $model records or false. Will return false if state is not set, or state is not configured in model
 * @author Frode Marton Meling
 */
	public function findCountByState(Model $model, $state = null, $params = array()) {
		return $this->_findByState($model, 'count', $state, $params);
	}

/**
 * Allows moving from one state to another by giving the Id of the Model entity to transition
 * @param Model $model The model being acted on
 * @param integer $id table id field to find object
 * @param string $role The rule executing the transition
 * @param string $transition The transition being initiated
 */
	public function transitionById(Model $model, $transition, $id = null, $role = null) {
		$transition = $this->_deFormalizeById($transition);
		$modelRow = $model->findById($id);
		if ($modelRow) {
			$model->id = $modelRow[$model->alias]['id'];
			return $this->transition($model, $transition, $role);
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
 * @param bool $validate whether or not validation being checked
 */
	public function transition(Model $model, $transition, $role = null, $validate = true) {
		$transition = Inflector::underscore($transition);
		$state = $this->getStates($model, $transition);
		if (! $state || $this->_checkRoleAgainstRule($model, $role, $transition) === false) {
			return false;
		}

		$this->_callTransitionListeners($model, $transition, 'before');

		$model->read(null, $model->id);
		$model->set('previous_state', $model->getCurrentState());
		$model->set('last_transition', $transition);
		$model->set('last_role', $role);
		$model->set('state', $state);
		$retval = $model->save(null, $validate);

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
 * @param Model $model The model being acted on
 * @param string $transition The transition to listen to
 * @param string $triggerType Either before or after
 * @param Callable $cb The callback function that will be called
 * @param Boolean $bubble Whether or not to bubble other listeners
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
 * @param Model $model The model being acted on
 * @param string $state The state which the machine should enter
 * @param Callable $cb The callback function that will be called
 */
	public function when(Model $model, $state, Callable $cb) {
		$this->settings[$model->alias]['state_listeners'][Inflector::underscore($state)][] = $cb;
	}

/**
 * Returns the states the machine would be in, after the given transition
 *
 * @param Model $model The model being acted on
 * @param string $transition The transition name
 * @return mixed False if the transition doesnt yield any states, or an array of states
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
 * Returns the current state of the machine givend its id
 * 
 * @param Model $model The model being acted on
 * @param integer $id The id of the item to check
 * @return string The current state of the machine
 */
	public function getCurrentStateById(Model $model, $id) {
		$modelRow = $model->findById($id);
		if ($modelRow) {
			$model->id = $modelRow[$model->alias]['id'];
			return $this->getCurrentState($model);
		}
		return false;
	}

/**
 * Returns the current state of the machine
 * 
 * @param Model $model The model being acted on
 * @return string The current state of the machine
 */
	public function getCurrentState(Model $model) {
		return (($model->field('state') != null)) ? $model->field('state') : $model->initialState;
	}

/**
 * Returns the current state of the machine givend its id
 * 
 * @param Model $model The model being acted on
 * @param integer $id The id of the item to check
 * @return string The current state of the machine
 */
	public function getPreviousStateById(Model $model, $id) {
		$modelRow = $model->findById($id);
		if ($modelRow) {
			$model->id = $modelRow[$model->alias]['id'];
			return $this->getPreviousState($model);
		}
		return false;
	}

/**
 * Returns the previous state of the machine
 * 
 * @param Model $model The model being acted on
 * @return string The previous state of the machine
 */
	public function getPreviousState(Model $model) {
		return $model->field('previous_state');
	}

/**
 * Returns the last transition ran of the machine givend its id
 * 
 * @param Model $model The model being acted on
 * @param integer $id The id of the item to check
 * @return string The current state of the machine
 */
	public function getLastTransitionById(Model $model, $id) {
		$modelRow = $model->findById($id);
		if ($modelRow) {
			$model->id = $modelRow[$model->alias]['id'];
			return $this->getLastTransition($model);
		}
		return false;
	}

/**
 * Returns the last transition ran
 * 
 * @param Model $model The model being acted on
 * @return string The transition last ran of the machine
 */
	public function getLastTransition(Model $model) {
		return $model->field('last_transition');
	}

/**
 * Returns the role that ran last transition of the machine givend its id
 * 
 * @param Model $model The model being acted on
 * @param integer $id The id of the item to check
 * @return string The current state of the machine
 */
	public function getLastRoleById(Model $model, $id) {
		$modelRow = $model->findById($id);
		if ($modelRow) {
			$model->id = $modelRow[$model->alias]['id'];
			return $this->getLastRole($model);
		}
		return false;
	}

/**
 * Returns the role that ran the last transition
 * 
 * @param Model $model The model being acted on
 * @return string The role that last ran a transition of the machine
 */
	public function getLastRole(Model $model) {
		return $model->field('last_role');
	}

/**
 * Simple method to return contents for a GV file, that
 * can be made into graphics by:
 * {{{
 * dot -Tpng -ofsm.png fsm.gv
 * }}}
 * Assuming that the contents are written to the file fsm.gv
 *
 * @param Model $model The model being acted on
 * @return string The contents of the graphviz file
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
 * This method prepares an array for each transition in the statemachine making it easier to iterate throug the machine for
 * output to various formats
 *
 * @param Model $model  The model being acted on
 * @param array $roles  The role(s) executing the transition change. with an options array.
 *                               'role' => array('color' => color of the arrows)
 *                               In the future many more Graphviz options can be added
 * @return array      returns an array of all transitions
 * @author  Frode Marton Meling <fm@saltship.com>
 */
	public function prepareForDotWithRoles(Model $model, $roles) {
		$preparedForDotArray = array();
		foreach ($model->transitions as $transition => $states) {
			foreach ($roles as $role => $options) {
				foreach ($states as $stateFrom => $stateTo) {
					// if roles are not defined in transitionRules we add or if roles are defined, at least one needs to be present
					if (!isset($model->transitionRules[$transition]['role']) || (isset($model->transitionRules[$transition]['role']) && $this->_containsAnyRoles($model->transitionRules[$transition]['role'], $roles))) {
						$dataToPrepare = array();
						$dataToPrepare = array(
							'stateFrom' => $stateFrom,
							'stateTo' => $stateTo,
							'transition' => $transition
						);
						if (isset($model->transitionRules[$transition]['role'])) {
							//debug($role);
							//debug($model->transitionRules[$transition]['role']);
							if (in_array($role, $model->transitionRules[$transition]['role'])) {
								$dataToPrepare['roles'] = array($role);
							}
						}
						if (isset($model->transitionRules[$transition]['depends'])) {
							$dataToPrepare['depends'] = $model->transitionRules[$transition]['depends'];
						}
						// we do not add if role is given as transitionRule, but part is not in it.
						$preparedForDotArray = $this->addToPrepareArray($model, $dataToPrepare, $preparedForDotArray);
					}
				}
			}
		}
		return $preparedForDotArray;
	}

/**
 * Method to return contents for a GV file based on array of roles. That means you can send
 * an array of roles (with options) and this method will calculate the presentation that
 * can be made into graphics by:
 * {{{
 * dot -Tpng -ofsm.png fsm.gv
 * }}}
 * Assuming that the contents are written to the file fsm.gv
 *
 * @param Model $model The model being acted on
 * @param array $role The role(s) executing the transition change. with an options array.
 *                               'role' => array('color' => color of the arrows)
 *                               In the future many more Graphviz options can be added
 * @param array $dotOptions Options for nodes
 * 								 'color' => 'color of all nodes'
 * 								 'activeColor' => 'the color you want the active node to have'
 * @return string The contents of the graphviz file
 * @author Frode Marton Meling <fm@saltship.com>
 */
	public function createDotFileForRoles(Model $model, $roles, $dotOptions) {
		//$rand = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e', 'f');
		$transitionsArray = $this->prepareForDotWithRoles($model, $roles);
		$digraph = "digraph finite_state_machine {\n\tfontsize=12;\n\tnode [shape = oval, style=filled, color = \"%s\"];\n\tstyle=filled;\n\tlabel=\"%s\"\n%s\n%s}\n";
		$activeState = "\t" . "\"" . Inflector::humanize($this->getCurrentState($model)) . "\"" . " [ color = " . $dotOptions['activeColor'] . " ];";

		//$node = "\t%s -> %s [ margin= \"0.9,0.9\" style = bold, fontsize = 9, arrowType = normal, label = \"%s %s%s\"%s headlabel=\"%s\" taillabel=\"%s\"];\n"; // with head and tail labels
		$node = "\t\"%s\" -> \"%s\" [ style = bold, fontsize = 9, arrowType = normal, label = \"%s %s%s\" %s];\n";
		$dotNodes = "";

		foreach ($transitionsArray as $transition) {
			$dotNodes .= sprintf($node,
				Inflector::humanize($transition['stateFrom']),
				Inflector::humanize($transition['stateTo']),
				Inflector::humanize($transition['transition']),
				(isset($transition['roles']) && (!$this->_containsAllRoles($transition['roles'], $roles) || (count($roles) == 1))) ? 'by (' . Inflector::humanize(implode(' or ', $transition['roles'])) . ')' : 'by All',
				(isset($transition['depends'])) ? "\nif " . Inflector::humanize($transition['depends']) : '',
				(isset($transition['roles']) && count($transition['roles']) == 1) ? "color = \"" . $roles[$transition['roles'][0]]['color'] . "\"" : ''//,
				//'when' . Inflector::camelize($transition['stateTo']),
				//'on' . Inflector::camelize($transition['stateFrom'])
			);
		}
		$graph = sprintf($digraph, $dotOptions['color'], 'Statemachine for ' . Inflector::humanize($model->alias) . ' role(s) : ' . Inflector::humanize(implode(', ', $this->getAllRoles($model, $roles))), $activeState, $dotNodes);
		return $graph;
	}

/**
 * This helperfunction fetches out all roles from an array of roles with options. Note that this is a ('role' => $options) array
 * I did not find a php method for this, so made it myself
 * 
 * @param Model	$model The model being acted on
 * @param Array $roles This is just an array of roles like array('role1', 'role2'...)
 * @return Array Returns an array of roles like array('role1', 'role2'...)
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	public function getAllRoles(Model $model, $roles) {
		$arrayToReturn = array();
		foreach ($roles as $role => $option) {
			$arrayToReturn[] = $role;
		}
		return $arrayToReturn;
	}

/**
 * This function is used to add transitions to Array. This tests for conditions and makes sure duplicates are not added.
 * 
 * @param Model $model The model being acted on
 * @param array $data An array of a transition to be added
 * @param array $prepareArray The current array to populate
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Move this to protected, Needs a reimplementation of the functiun in test to make it public for testing
 */
	public function addToPrepareArray(Model $model, $data, $prepareArray) {
		if (!is_array($data)) {
			return false;
		}

		if (!$this->_stateAndTransitionExist($data)) {
			return false;
		}

		// Check if we are preparing an object with states, transitions and depends
		if ($this->_stateTransitionAndDependsExist($data)) {
			$existingDataKey = $this->_stateTransitionAndDependsInArray($data, $prepareArray);
			if ($existingDataKey === false) {
				$prepareArray[] = $data;
			} elseif (isset($data['roles'])) {
				$this->_addRoles($data['roles'], $prepareArray[$existingDataKey]);
			}
			return $prepareArray;
		}
		$existingDataKey = $this->_stateAndTransitionInArray($data, $prepareArray);
		if ($existingDataKey !== false) {
			if (isset($data['roles'])) {
				$this->_addRoles($data['roles'], $prepareArray[$existingDataKey]);
			}
			return $prepareArray;
		}
		$prepareArray[] = $data;

		return $prepareArray;
	}

/**
 * This helperfunction checks if all roles in an array (roles) is present in $allArrays. Note that this is a ('role' => $options) array
 * I did not find a php method for this, so made it myself
 * 
 * @param Array $roles This is just an array of roles like array('role1', 'role2'...)
 * @param Array $allRoles This is the array to test on. This is a multidimentional array like array('role1' => array('of' => 'options'), 'role2' => array('of' => 'options') )
 * @return boolean Returns true if all roles are present, otherwise false
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	protected function _containsAllRoles($roles, $allRoles) {
		foreach ($allRoles as $role => $options) {
			if (!in_array($role, $roles)) {
				return false;
			}
		}
		return true;
	}

/**
 * This helperfunction checks if any of the roles in an array (roles) is present in $allArrays. Note that this is a ('role' => $options) array
 * I did not find a php method for this, so made it myself
 * 
 * @param Array $roles This is just an array of roles like array('role1', 'role2'...)
 * @param Array $allRoles This is the array to test on. This is a multidimentional array like array('role1' => array('of' => 'options'), 'role2' => array('of' => 'options') )
 * @return boolean Returns true if just one of the roles are present, otherwise false
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	protected function _containsAnyRoles($roles, $allRoles) {
		$atleastOne = false;
		foreach ($allRoles as $role => $options) {
			if (in_array($role, $roles)) {
				$atleastOne = true;
			}
		}
		return $atleastOne;
	}

/**
 * This helperfunction adds a role to an array. It checks for duplicates and only adds if it is not already in array
 * If also checks that the resultArray is valid and that there are roles there to begin with
 * 
 * @param Array $roles This is just an array of roles like array('role1', 'role2'...)
 * @param Array &$resultArray This function writes to this parameter by reference
 * @return boolean Returns true if added, otherwise false
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	protected function _addRoles($roles, &$resultArray) {
		$addedAtleastOne = false;
		foreach ($roles as $role) {
			if (!isset($resultArray['roles']) || isset($resultArray['roles']) && !in_array($role, $resultArray['roles'])) {
				$resultArray['roles'][] = $role;
				$addedAtleastOne = true;
			}
		}
		return $addedAtleastOne;
	}

/**
 * This helperfunction checks if state and transition is present in the array
 * 
 * @param array $data The array to check
 * @return boolean true if array is valid, otherwise false
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	protected function _stateAndTransitionExist($data) {
		if (isset($data['stateFrom']) && isset($data['stateTo']) && isset($data['transition'])) {
			return true;
		}
		return false;
	}

/**
 * This helperfunction checks if state, transition and depends exist in array
 * 
 * @param array $data The array to check
 * @return boolean True if state, transition and depends exist in array, otherwise false
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	protected function _stateTransitionAndDependsExist($data) {
		if (isset($data['stateFrom']) && isset($data['stateTo']) && isset($data['transition']) && isset($data['depends'])) {
			return true;
		}
		return false;
	}

/**
 * This helperfunction checks if state and transition is present in prepareArray. this is used to prevent adding duplicates
 * 
 * @param array $data The array for testing
 * @param array $prepareArray The array to check against
 * @return boolean index in array if state and transition is present in prepareArray, otherwise false
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	protected function _stateAndTransitionInArray($data, $prepareArray) {
		foreach ($prepareArray as $key => $value) {
			if (($value['stateFrom'] == $data['stateFrom']) && ($value['stateTo'] == $data['stateTo']) && ($value['transition'] == $data['transition'])) {
				return $key;
			}
		}
		return false;
	}

/**
 * This helperfunction checks if state, transition and depends is present in prepareArray. this is used to prevent adding duplicates
 * 
 * @param array $data The array for testing
 * @param array $prepareArray The array to check against
 * @return boolean the index in array if state, transition and depends is present in prepareArray, otherwise false
 * @author Frode Marton Meling <fm@saltship.com>
 * @todo Add separate tests
 */
	protected function _stateTransitionAndDependsInArray($data, $prepareArray) {
		foreach ($prepareArray as $key => $value) {
			if (!isset($value['depends'])) {
				continue;
			}
			if (($value['stateFrom'] == $data['stateFrom']) && ($value['stateTo'] == $data['stateTo']) && ($value['transition'] == $data['transition']) && ($value['depends'] == $data['depends'])) {
				return $key;
			}
		}
		return false;
	}

/**
 * Checks whether or not the given role may perform the transition change.
 * The callback in 'depends' must be a valid model method.
 *
 * @param Model $model The model being acted on
 * @param string $role The role executing the transition change
 * @param string $transition The transition
 * @throws InvalidArgumentException if the transition require it be executed by a rule, and none is given
 * @return boolean Whether or not the role may perform the action
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
 * @param string $transition The transition name
 * @param string $trigger Type Either before or after
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
 * @param string $name The model name
 * @return string The deformalized method name
 */
	protected function _deFormalizeMethodName($name) {
		return Inflector::underscore(preg_replace('#^(can|is)#', '', $name));
	}

/**
 * Deformalizes a method name, removing 'can' and 'is' as well as underscoring
 * the remaining text.
 *
 * @param string $name The model name
 * @return string The deformalized method name
 */
	protected function _deFormalizeById($name) {
		return Inflector::underscore(preg_replace('#By[a-zA-Z]+$#', '', $name));
	}

/**
 * Checks whether or not a user-defined method exists in the Behavior
 *
 * @param Model $model The model being acted on
 * @param string $method The method's name
 * @return boolean True if the method exists, false otherwise
 */
	protected function _hasMethod(Model $model, $method) {
		return isset($this->settings[$model->alias]['methods'][$method]) || isset($this->mapMethods['/' . $method . '/']);
	}
}
