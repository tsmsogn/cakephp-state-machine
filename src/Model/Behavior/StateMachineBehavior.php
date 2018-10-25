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
namespace Tsmsogn\StateMachine\Model\Behavior;


use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\Utility\Inflector;
use InvalidArgumentException;
use Tsmsogn\StateMachine\Model\Entity\StateMachineEntityInterface;

class StateMachineBehavior extends Behavior
{

    /**
     * Allows us to support writing both: is('parked') and isParked()
     *
     * @var array
     */
    public $mapMethods = array(
        '/when([A-Z][a-zA-Z0-9]+)/' => 'when',
        '/on([A-Z][a-zA-Z0-9]+)/' => 'on'
    );

    protected $_defaultConfig = array(
        'transition_listeners' => array(
            'transition' => array(
                'before' => array(),
                'after' => array()
            )
        ),
        'state_listeners' => array(),
        'methods' => array(),
        'initial_state' => null,
        'state_field' => 'state',
        'previous_state_field' => 'previous_state',
        'last_role_field' => 'last_role',
        'last_transition_field' => 'last_transition',
    );

    /**
     * Array of all configured states. Initialized by self::setup()
     * @var array
     */
    protected $_availableStates = array();

    /**
     * Adds a available state
     *
     * @param string $state The state to be added.
     * @return void
     */
    protected function _addAvailableState($state)
    {
        if ($state != 'All' && !in_array($state, $this->_availableStates)) {
            $this->_availableStates[] = Inflector::camelize($state);
        }
    }

    /**
     * Sets up all the methods that builds up the state machine.
     * StateMachine->is<State>            i.e. StateMachine->isParked()
     * StateMachine->can<Transition>    i.e. StateMachine->canShiftGear()
     * StateMachine-><transition>        i.e. StateMachine->shiftGear();
     *
     * @param array $config Configuration for the Behavior
     * @return void
     */
    public function initialize(array $config)
    {
        parent::initialize($config);

        foreach ($this->getTable()->transitions as $transition => $states) {
            foreach ($states as $stateFrom => $stateTo) {
                $this->_addAvailableState(Inflector::camelize($stateFrom));
                $this->_addAvailableState(Inflector::camelize($stateTo));
                foreach (array(
                             'is' . Inflector::camelize($stateFrom),
                             'is' . Inflector::camelize($stateTo)
                         ) as $methodName) {
                    if (!$this->_hasMethod($methodName)) {
                        $this->mapMethods['/' . $methodName . '$/'] = 'is';
                    }
                }
            }

            $this->mapMethods['/^can' . Inflector::camelize($transition) . '$/'] = 'can';

            $transitionFunction = Inflector::variable($transition);
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
     * @param string $method The method na,e
     * @param string $cb The callback to execute
     * @throws \InvalidArgumentException If the method already is registered
     * @return void
     */
    public function addMethod($method, $cb)
    {
        if ($this->_hasMethod($method)) {
            throw new InvalidArgumentException("A method with the same name is already registered");
        }

        $methods = $this->getConfig('methods');
        $methods[$method] = $cb;
        $this->mapMethods['/' . $method . '/'] = 'handleMethodCall';

        // force model to re-load Behavior, so that the mapMethods are working correctly
        $this->getTable()->behaviors()->load('Tsmsogn/StateMachine.StateMachine');
    }

    /**
     * Handles user defined method calls, which are implemented using closures.
     *
     * @param string $method The method name
     * @return mixed The return value of the callback, or an array if the method doesn't exist
     */
    public function handleMethodCall($method)
    {
        $methods = $this->getConfig('methods');
        if (!isset($methods[$method])) {
            return array('unhandled');
        }
        return call_user_func_array($methods[$method], func_get_args());
    }

    /**
     * Updates the entity's state when a $this->getTable()->save() call is performed
     *
     * @param Event $event
     * @param EntityInterface $entity
     * @param ArrayObject $options
     */
    public function afterSave(Event $event, EntityInterface $entity, ArrayObject $options)
    {
        if ($entity->isNew()) {
            $entity->{$this->_getStateField()} = $this->_getInitialState();
            $this->getTable()->save($entity);
        }
    }

    /**
     * returns all transitions defined in model
     *
     * @return array array of transitions
     * @author Frode Marton Meling
     */
    public function getAllTransitions()
    {
        $transitionArray = array();
        foreach ($this->getTable()->transitions as $transition => $data) {
            $transitionArray[] = $transition;
        }
        return $transitionArray;
    }

    /**
     * Returns an array of all configured states
     *
     * @return array
     */
    public function getAvailableStates()
    {
        return $this->_availableStates;
    }

    /**
     * checks if $state or Array of states are valid ones
     *
     * @param string|array $state a string representation of state or a array of states
     * @return bool
     * @author Frode Marton Meling
     */
    protected function _validState($state)
    {
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
     * This function will add all available (runnable) transitions on a model and add it to the dataArray given to the function.
     *
     * @param Model $model The model being acted on
     * @param array $modelRows The model dataArray. this is an array of Models returned from a model->find.
     * @param string $role if specified, the function will limit the transitions based on a role
     * @return array        Returns datarray of $model with the available transitions inserted
     * @author Frode Marton Meling
     */
    protected function _addTransitionsToArray($model, $modelRows, $role)
    {
        if (!isset($modelRows) || $modelRows == false) {
            return $modelRows;
        }

        $allTransitions = $this->getAllTransitions($model);
        foreach ($modelRows as $key => $modelRow) {
            $model->id = $modelRow[$model->alias]['id'];
            // Note! We need this empty array if no transitions are available. then we do not need to test if array exist in views.
            $modelRows[$key][$model->alias]['Transitions'] = array();
            foreach ($allTransitions as $transition) {
                if ($model->can($transition, $model->id, $role)) {
                    $modelRows[$key][$model->alias]['Transitions'][] = $transition;
                }
            }
        }
        return $modelRows;
    }

    /**
     * Allows moving from one state to another.
     * {{{
     * $this->Model->transition('shift_gear');
     * // or
     * $this->Model->shiftGear();
     * }}}
     *
     * @param \Tsmsogn\StateMachine\Model\Entity\StateMachineEntityInterface $entity
     * @param string $transition The transition being initiated
     * @param string $role The rule executing the transition
     * @param bool $validate whether or not validation being checked
     * @return bool Returns true if the transition be executed, otherwise false
     */
    public function transition(StateMachineEntityInterface $entity, $transition, $role = null, $validate = true)
    {
        $transition = Inflector::underscore($transition);
        $state = $this->getStates($entity, $transition);
        if (!$state || $this->_checkRoleAgainstRule($entity, $role, $transition) === false) {
            return false;
        }

        $this->_callTransitionListeners($entity, $transition, 'before');

        $entity->{$this->_getPreviousStateField()} = $entity->getCurrentState();
        $entity->{$this->_getLastTransitionField()} = $transition;
        $entity->{$this->_getLastRoleField()} = $role;
        $entity->{$this->_getStateField()} = $state;
        $retval = $this->getTable()->save($entity, ['validate' => $validate]);

        if ($retval) {
            $this->_callTransitionListeners($entity, $transition, 'after');

            $stateListeners = array();
            if (isset($this->settings[$entity->alias]['state_listeners'][$state])) {
                $stateListeners = $this->settings[$entity->alias]['state_listeners'][$state];
            }

            foreach (array(
                         'onState' . Inflector::camelize($state),
                         'onStateChange'
                     ) as $method) {
                if (method_exists($entity, $method)) {
                    $stateListeners[] = array($entity, $method);
                }
            }

            foreach ($stateListeners as $cb) {
                call_user_func($cb, $state);
            }
        }

        return (bool)$retval;
    }

    /**
     * Checks whether the state machine is in the given state
     *
     * @param \Tsmsogn\StateMachine\Model\Entity\StateMachineEntityInterface $entity
     * @param string $state The state being checked
     * @return bool whether or not the state machine is in the given state
     */
    public function is(StateMachineEntityInterface $entity, $state)
    {
        return $entity->getCurrentState() === $this->_deFormalizeMethodName($state);
    }

    /**
     * Checks whether or not the machine is able to perform transition, in its current state
     *
     * @param \Tsmsogn\StateMachine\Model\Entity\StateMachineEntityInterface $entity
     * @param string $transition The transition being checked
     * @param string $role The role which should execute the transition
     * @return bool whether or not the machine can perform the transition
     * @throws \BadMethodCallException when method does not exists
     */
    public function can(StateMachineEntityInterface $entity, $transition, $role = null)
    {
        $transition = $this->_deFormalizeMethodName($transition);
        if (!$this->getStates($entity, $transition) || $this->_checkRoleAgainstRule($entity, $role, $transition) === false) {
            return false;
        }

        return true;
    }

    /**
     * Registers a callback function to be called when the machine leaves one state.
     * The callback is fired either before or after the given transition.
     *
     * @param string $transition The transition to listen to
     * @param string $triggerType Either before or after
     * @param string $cb The callback function that will be called
     * @param bool $bubble Whether or not to bubble other listeners
     * @return void
     */
    public function on($transition, $triggerType, $cb, $bubble = true)
    {
        $transition_listeners = $this->getConfig('transition_listeners');
        $transition_listeners[Inflector::underscore($transition)][$triggerType][] = array(
            'cb' => $cb,
            'bubble' => $bubble
        );
    }

    /**
     * Registers a callback that will be called when the state machine enters the given
     * state.
     *
     * @param string $state The state which the machine should enter
     * @param string $cb The callback function that will be called
     * @return void
     */
    public function when($state, $cb)
    {
        $state_listeners = $this->getConfig('state_listeners');
        $state_listeners[Inflector::underscore($state)][] = $cb;
    }

    /**
     * Returns the states the machine would be in, after the given transition
     *
     * @param \Tsmsogn\StateMachine\Model\Entity\StateMachineEntityInterface $entity
     * @param string $transition The transition name
     * @return mixed False if the transition doesnt yield any states, or an array of states
     */
    public function getStates(StateMachineEntityInterface $entity, $transition)
    {
        if (!isset($this->getTable()->transitions[$transition])) {
            // transition doesn't exist
            return false;
        }

        // get the states the machine can move from and to
        $states = $this->getTable()->transitions[$transition];
        $currentState = $entity->getCurrentState();

        if (isset($states[$currentState])) {
            return $states[$currentState];
        }

        if (isset($states['all'])) {
            return $states['all'];
        }

        return false;
    }

    /**
     * Simple method to return contents for a GV file, that
     * can be made into graphics by:
     * {{{
     * dot -Tpng -ofsm.png fsm.gv
     * }}}
     * Assuming that the contents are written to the file fsm.gv
     *
     * @return string The contents of the graphviz file
     */
    public function toDot()
    {
        $digraph = <<<EOT
digraph finite_state_machine {
	rankdir=LR
	fontsize=12
	node [shape = circle];

EOT;

        foreach ($this->getTable()->transitions as $transition => $states) {
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
     * @param array $roles The role(s) executing the transition change. with an options array.
     *                               'role' => array('color' => color of the arrows)
     *                               In the future many more Graphviz options can be added
     * @return array      returns an array of all transitions
     * @author  Frode Marton Meling <fm@saltship.com>
     */
    public function prepareForDotWithRoles($roles)
    {
        $preparedForDotArray = array();
        foreach ($this->getTable()->transitions as $transition => $states) {
            foreach ($roles as $role => $options) {
                foreach ($states as $stateFrom => $stateTo) {
                    // if roles are not defined in transitionRules we add or if roles are defined, at least one needs to be present
                    if (!isset($this->getTable()->transitionRules[$transition]['role']) || (isset($this->getTable()->transitionRules[$transition]['role']) && $this->_containsAnyRoles($this->getTable()->transitionRules[$transition]['role'], $roles))) {
                        $dataToPrepare = array(
                            'stateFrom' => $stateFrom,
                            'stateTo' => $stateTo,
                            'transition' => $transition
                        );
                        if (isset($this->getTable()->transitionRules[$transition]['role'])) {
                            if (in_array($role, $this->getTable()->transitionRules[$transition]['role'])) {
                                $dataToPrepare['roles'] = array($role);
                            }
                        }
                        if (isset($this->getTable()->transitionRules[$transition]['depends'])) {
                            $dataToPrepare['depends'] = $this->getTable()->transitionRules[$transition]['depends'];
                        }
                        // we do not add if role is given as transitionRule, but part is not in it.
                        $preparedForDotArray = $this->addToPrepareArray($this->getTable(), $dataToPrepare, $preparedForDotArray);
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
     * @param array $roles The role(s) executing the transition change. with an options array.
     *                                 'role' => array('color' => color of the arrows)
     *                                 In the future many more Graphviz options can be added
     * @param array $dotOptions Options for nodes
     *                                 'color' => 'color of all nodes'
     *                                 'activeColor' => 'the color you want the active node to have'
     * @return string The contents of the graphviz file
     * @author Frode Marton Meling <fm@saltship.com>
     */
    public function createDotFileForRoles($roles, $dotOptions)
    {
        $transitionsArray = $this->prepareForDotWithRoles($roles);
        $digraph = "digraph finite_state_machine {\n\tfontsize=12;\n\tnode [shape = oval, style=filled, color = \"%s\"];\n\tstyle=filled;\n\tlabel=\"%s\"\n%s\n%s}\n";
        $activeState = "\t" . "\"" . Inflector::humanize($this->_getInitialState()) . "\"" . " [ color = " . $dotOptions['activeColor'] . " ];";

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
            );
        }
        $graph = sprintf($digraph, $dotOptions['color'], 'Statemachine for ' . Inflector::humanize($this->getTable()->alias) . ' role(s) : ' . Inflector::humanize(implode(', ', $this->getAllRoles($this->getTable(), $roles))), $activeState, $dotNodes);
        return $graph;
    }

    /**
     * This helperfunction fetches out all roles from an array of roles with options. Note that this is a ('role' => $options) array
     * I did not find a php method for this, so made it myself
     *
     * @param array $roles This is just an array of roles like array('role1', 'role2'...)
     * @return array Returns an array of roles like array('role1', 'role2'...)
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    public function getAllRoles($roles)
    {
        $arrayToReturn = array();
        foreach ($roles as $role => $option) {
            $arrayToReturn[] = $role;
        }
        return $arrayToReturn;
    }

    /**
     * This function is used to add transitions to Array. This tests for conditions and makes sure duplicates are not added.
     *
     * @param array $data An array of a transition to be added
     * @param array $prepareArray The current array to populate
     * @return mixed
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Move this to protected, Needs a reimplementation of the function in test to make it public for testing @codingStandardsIgnoreLine
     */
    public function addToPrepareArray($data, $prepareArray)
    {
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
     * @param array $roles This is just an array of roles like array('role1', 'role2'...)
     * @param array $allRoles This is the array to test on. This is a multidimentional array like array('role1' => array('of' => 'options'), 'role2' => array('of' => 'options') )
     * @return bool Returns true if all roles are present, otherwise false
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    protected function _containsAllRoles($roles, $allRoles)
    {
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
     * @param array $roles This is just an array of roles like array('role1', 'role2'...)
     * @param array $allRoles This is the array to test on. This is a multidimentional array like array('role1' => array('of' => 'options'), 'role2' => array('of' => 'options') )
     * @return bool Returns true if just one of the roles are present, otherwise false
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    protected function _containsAnyRoles($roles, $allRoles)
    {
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
     * @param array $roles This is just an array of roles like array('role1', 'role2'...)
     * @param array &$resultArray This function writes to this parameter by reference
     * @return bool Returns true if added, otherwise false
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    protected function _addRoles($roles, &$resultArray)
    {
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
     * @return bool true if array is valid, otherwise false
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    protected function _stateAndTransitionExist($data)
    {
        if (isset($data['stateFrom']) && isset($data['stateTo']) && isset($data['transition'])) {
            return true;
        }
        return false;
    }

    /**
     * This helperfunction checks if state, transition and depends exist in array
     *
     * @param array $data The array to check
     * @return bool True if state, transition and depends exist in array, otherwise false
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    protected function _stateTransitionAndDependsExist($data)
    {
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
     * @return bool index in array if state and transition is present in prepareArray, otherwise false
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    protected function _stateAndTransitionInArray($data, $prepareArray)
    {
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
     * @return bool the index in array if state, transition and depends is present in prepareArray, otherwise false
     * @author Frode Marton Meling <fm@saltship.com>
     * @todo Add separate tests @codingStandardsIgnoreLine
     */
    protected function _stateTransitionAndDependsInArray($data, $prepareArray)
    {
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
     * @param string $role The role executing the transition change
     * @param string $transition The transition
     * @throws \InvalidArgumentException if the transition require it be executed by a rule, and none is given
     * @return bool Whether or not the role may perform the action
     */
    protected function _checkRoleAgainstRule($role, $transition)
    {
        if (!isset($this->getTable()->transitionRules[$transition])) {
            return null;
        }

        if (!$role) {
            throw new InvalidArgumentException('The transition ' . $transition . ' requires a role');
        }

        if (!in_array($role, $this->getTable()->transitionRules[$transition]['role'])) {
            return false;
        }

        if (!isset($this->getTable()->transitionRules[$transition]['depends'])) {
            return true;
        }

        $callback = Inflector::variable($this->getTable()->transitionRules[$transition]['depends']);

        if ($this->_hasMethod($callback)) {
            // Fix: if the method is supplied as an anonymous callback, we cannot call
            // it from the model directly
            $methods = $this->getConfig('methods');
            $res = $methods[$callback]($role);
        } else {
            $res = call_user_func(array($this->getTable(), $callback), $role);
        }

        return $res;
    }

    /**
     * Calls transition listeners before or after a particular transition.
     * Special model methods are also called, if they exist:
     * - onBeforeTransition
     * - onAfterTransition
     * - onBefore<Transition>    i.e. onBeforePark()
     * - onAfter<Transition>    i.e. onAfterPark()
     *
     * @param \Tsmsogn\StateMachine\Model\Entity\StateMachineEntityInterface $entity
     * @param string $transition The transition name
     * @param string $triggerType Either before or after
     * @return void
     */
    protected function _callTransitionListeners(StateMachineEntityInterface $entity, $transition, $triggerType = 'after')
    {
        $transitionListeners = $this->getConfig('transition_listeners');
        $listeners = $transitionListeners['transition'][$triggerType];

        if (isset($transitionListeners[$transition][$triggerType])) {
            $listeners = array_merge($transitionListeners[$transition][$triggerType], $listeners);
        }

        foreach (array(
                     'on' . Inflector::camelize($triggerType . 'Transition'),
                     'on' . Inflector::camelize($triggerType . $transition)
                 ) as $method) {
            if (method_exists($entity, $method)) {
                $listeners[] = array(
                    'cb' => array($entity, $method),
                    'bubble' => true
                );
            }
        }

        $currentState = $entity->getCurrentState();
        $previousState = $entity->getPreviousState();

        foreach ($listeners as $cb) {
            call_user_func_array($cb['cb'], array($currentState, $previousState, $transition));

            if (!$cb['bubble']) {
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
    protected function _deFormalizeMethodName($name)
    {
        return Inflector::underscore(preg_replace('#^(can|is)#', '', $name));
    }

    /**
     * Checks whether or not a user-defined method exists in the Behavior
     *
     * @param string $method The method's name
     * @return bool True if the method exists, false otherwise
     */
    protected function _hasMethod($method)
    {
        $methods = $this->getConfig('methods');
        return isset($methods[$method]) || isset($this->mapMethods['/' . $method . '/']);
    }

    /**
     * @return mixed
     */
    protected function _getPreviousStateField()
    {
        return $this->getConfig('previous_state_field');
    }

    /**
     * @return mixed
     */
    protected function _getLastTransitionField()
    {
        return $this->getConfig('last_transition_field');
    }

    /**
     * @return mixed
     */
    protected function _getLastRoleField()
    {
        return $this->getConfig('last_role_field');
    }

    /**
     * @return mixed
     */
    protected function _getStateField()
    {
        return $this->getConfig('state_field');
    }

    /**
     * @return mixed
     */
    protected function _getInitialState()
    {
        return $this->getConfig('initial_state');
    }
}
