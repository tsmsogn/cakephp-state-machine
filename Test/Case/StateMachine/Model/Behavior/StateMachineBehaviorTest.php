<?php
App::uses('StateMachineBehavior', 'StateMachine.Model/Behavior');

class BaseVehicle extends CakeTestModel {

	public $useTable = 'vehicles';

	public $actsAs = array('StateMachine.StateMachine');

	public $initialState = 'parked';

	public $transitions = array(
		'ignite' => array(
			'parked' => 'idling',
			'stalled' => 'stalled'
		),
		'park' => array(
			'idling' => 'parked',
			'first_gear' => 'parked'
		),
		'shift_up' => array(
			'idling' => 'first_gear',
			'first_gear' => 'second_gear',
			'second_gear' => 'third_gear'
		),
		'shift_down' => array(
			'first_gear' => 'idling',
			'second_gear' => 'first_gear',
			'third_gear' => 'second_gear'
		),
		'crash' => array(
			'first_gear' => 'stalled',
			'second_gear' => 'stalled',
			'third_gear' => 'stalled'
		),
		'repair' => array(
			'stalled' => 'parked'
		),
		'idle' => array(
			'first_gear' => 'idling'
		),
		'turn_off' => array(
			'all' => 'parked'
		),
		'baz' => array()
	);
}

class Vehicle extends BaseVehicle {

	public function onStateChange($newState) {
	}

	public function onStateIdling($newState) {
	}

	public function onBeforeTransition($currentState, $previousState, $transition) {
	}

	public function onAfterTransition($currentState, $previousState, $transition) {
	}

	public function onBeforeIgnite($currentState, $previousState, $transition) {
	}

}

class RulesVehicle extends BaseVehicle {

	public $transitionRules = array(
		'hardwire' => array(
			'role' => array('thief'),
		),
		'ignite' => array(
			'role' => array('driver'),
			'depends' => 'has_key'
		),
		'park'	=> array(
			'role' => array('driver', 'thief'),
			'depends' => 'available_parking'
		),
		'repair' => array(
			'role' => array('mechanic'),
			'depends' => 'has_tools'
		)
	);

	public function __construct($id = false, $table = null, $ds = null) {
		$this->transitions += array(
			'hardwire' => array(
				'parked' => 'idling',
				'stalled' => 'stalled'
			)
		);

		parent::__construct($id, $table, $ds);
	}

	public function hasKey($role) {
		if ($role == 'driver') {
			return true;
		}

		return false;
	}

	public function availableParking($role) {
		return $role == 'thief';
	}

}

class StateMachineBehaviorTest extends CakeTestCase {

	public $fixtures = array(
		'plugin.state_machine.vehicle'
	);

	public $Vehicle;

	public $StateMachine;

	public function setUp() {
		parent::setUp();

		$this->Vehicle = new Vehicle(1);
		$this->StateMachine = $this->Vehicle->Behaviors->StateMachine;
	}

	public function testGetAllTransitions() {
		$this->assertCount(9, $this->Vehicle->getAllTransitions());
	}

	public function testAvailableStates() {
		$this->assertCount(6, $this->Vehicle->getAvailableStates());
	}

	public function testTransitionById() {
		$this->Vehicle->igniteById(1);
		$this->assertEquals("idling", $this->Vehicle->getCurrentStateById(1));
		$this->assertEquals("parked", $this->Vehicle->getPreviousStateById(1));
		$this->assertEquals("ignite", $this->Vehicle->getLastTransitionById(1));
		$this->assertEquals("", $this->Vehicle->getLastRoleById(1));
		$this->Vehicle->shiftUpById(1);
		$this->assertEquals("first_gear", $this->Vehicle->getCurrentStateById(1));
		$this->assertEquals("idling", $this->Vehicle->getPreviousStateById(1));
		$this->Vehicle->shiftUpById(1);
		$this->assertEquals("second_gear", $this->Vehicle->getCurrentStateById(1));
		$this->assertEquals("first_gear", $this->Vehicle->getPreviousStateById(1));
	}

	public function testCanTransitionById() {
		$this->assertTrue($this->Vehicle->is('parked'));

		$this->assertEquals($this->Vehicle->canTransitionById('shift_up', 1), $this->Vehicle->canShiftUpById(1));
		$this->assertFalse($this->Vehicle->canTransitionById('shift_up', 1));

		$this->assertTrue($this->Vehicle->canTransitionById('ignite', 1));
		$this->Vehicle->ignite();
		$this->assertEquals("idling", $this->Vehicle->getCurrentState());

		$this->assertEquals($this->Vehicle->canTransitionById('shift_up', 1), $this->Vehicle->canShiftUpById(1));
		$this->assertTrue($this->Vehicle->canShiftUpById(1));
		$this->assertFalse($this->Vehicle->canShiftDownById(1));

		$this->assertFalse($this->Vehicle->canShiftUpById(2));
	}

	public function testFindAllByState() {
		$this->assertFalse($this->Vehicle->findAllByState());
		$this->assertFalse($this->Vehicle->findAllByState('illegal_state_should_not_be_possible'));
		$this->assertFalse($this->Vehicle->findAllByState(array('illegal_state_should_not_be_possible', 'parked')));
		$this->assertCount(2, $this->Vehicle->findAllByState('parked'));
		$this->assertCount(1, $this->Vehicle->findAllByState('parked', array('conditions' => array('Vehicle.title' => 'Audi Q4'))));
		$this->assertCount(4, $this->Vehicle->findAllByState('all'));
		$this->assertCount(1, $this->Vehicle->findAllByState('idling'));
		$this->assertCount(3, $this->Vehicle->findAllByState(array('idling', 'parked')));

		// test with transition array
		$testTransitions = $this->Vehicle->findAllByState('parked');
		$this->assertTrue(is_array($testTransitions[0]['Vehicle']['Transitions']));
		$this->assertCount(2, $testTransitions[0]['Vehicle']['Transitions']);

		// test without transitions array
		$testTransitions = $this->Vehicle->findAllByState('parked', array(), false);
		$this->assertFalse(isset($testTransitions[0]['Vehicle']['Transitions']));

		$this->Vehicle = new RulesVehicle(1);
		$testTransitions = $this->Vehicle->findAllByState('parked', array(), true, 'driver' );
		$this->assertEqual(array('ignite', 'turn_off'), $testTransitions[0]['RulesVehicle']['Transitions']);
	}

	public function testFindCountByState() {
		$this->assertFalse($this->Vehicle->findCountByState());
		$this->assertFalse($this->Vehicle->findCountByState('illegal_state_should_not_be_possible'));
		$this->assertFalse($this->Vehicle->findCountByState(array('illegal_state_should_not_be_possible', 'parked')));
		$this->assertEqual(2, $this->Vehicle->findCountByState('parked'));
		$this->assertEqual(1, $this->Vehicle->findCountByState('parked', array('conditions' => array('Vehicle.title' => 'Audi Q4'))));
		$this->assertEqual(4, $this->Vehicle->findCountByState('all'));
		$this->assertEqual(1, $this->Vehicle->findCountByState('idling'));
		$this->assertEqual(3, $this->Vehicle->findCountByState(array('idling', 'parked')));
	}

	public function testFindFirstByState() {
		$this->assertFalse($this->Vehicle->findFirstByState());
		$this->assertFalse($this->Vehicle->findFirstByState('illegal_state_should_not_be_possible'));
		$this->assertFalse($this->Vehicle->findFirstByState(array('illegal_state_should_not_be_possible', 'parked')));
		$this->assertCount(1, $this->Vehicle->findFirstByState('parked'));
		$this->assertCount(1, $this->Vehicle->findFirstByState('parked', array('conditions' => array('Vehicle.title' => 'Audi Q4'))));
		$this->assertCount(1, $this->Vehicle->findFirstByState('all'));
		$this->assertCount(1, $this->Vehicle->findFirstByState('idling'));
		$this->assertCount(1, $this->Vehicle->findFirstByState(array('idling', 'parked')));

		// test with transition array
		$testTransitions = $this->Vehicle->findFirstByState('parked');
		$this->assertTrue(is_array($testTransitions[0]['Vehicle']['Transitions']));
		$this->assertCount(2, $testTransitions[0]['Vehicle']['Transitions']);

		// test without transitions array
		$testTransitions = $this->Vehicle->findFirstByState('parked', array(), false);
		$this->assertFalse(isset($testTransitions[0]['Vehicle']['Transitions']));

		$this->Vehicle = new RulesVehicle(1);
		$testTransitions = $this->Vehicle->findFirstByState('parked', array(), true, 'driver' );
		$this->assertEqual(array('ignite', 'turn_off'), $testTransitions[0]['RulesVehicle']['Transitions']);
	}

	public function testInitialState() {
		$this->assertEquals("parked", $this->Vehicle->getCurrentState());
		$this->assertEquals('parked', $this->Vehicle->getStates('turn_off'));
	}

	public function testIsMethods() {
		$this->assertEquals($this->Vehicle->isParked(), $this->Vehicle->is('parked'));
		$this->assertEquals($this->Vehicle->isIdling(), $this->Vehicle->is('idling'));
		$this->assertEquals($this->Vehicle->isStalled(), $this->Vehicle->is('stalled'));
		$this->assertEquals($this->Vehicle->isIdling(), $this->Vehicle->is('idling'));

		$this->assertEquals($this->Vehicle->canShiftUp(), $this->Vehicle->can('shift_up'));
		$this->assertFalse($this->Vehicle->canShiftUp());

		$this->assertTrue($this->Vehicle->canIgnite());
		$this->Vehicle->ignite();
		$this->assertEquals("idling", $this->Vehicle->getCurrentState());

		$this->assertTrue($this->Vehicle->canShiftUp());
		$this->assertFalse($this->Vehicle->canShiftDown());

		$this->assertTrue($this->Vehicle->isIdling());
		$this->assertFalse($this->Vehicle->canCrash());
		$this->Vehicle->shiftUp();
		$this->Vehicle->crash();
		$this->assertEquals("stalled", $this->Vehicle->getCurrentState());
		$this->assertTrue($this->Vehicle->isStalled());
		$this->Vehicle->repair();
		$this->assertTrue($this->Vehicle->isParked());
	}

	public function testOnMethods() {
		$this->Vehicle->onIgnite('before', function($currentState, $previousState, $transition) {
			$this->assertEquals("parked", $currentState);
			$this->assertNull($previousState);
			$this->assertEquals("ignite", $transition);
		});

		$this->Vehicle->on('ignite', 'after', function($currentState, $previousState, $transition) {
			$this->assertEquals("idling", $currentState);
			$this->assertEquals("parked", $previousState);
			$this->assertEquals("ignite", $transition);
		});

		$this->Vehicle->ignite();
	}

	public function testBadMethodCall() {
		$this->setExpectedException('PDOException');
		$this->Vehicle->isFoobar();
	}

	public function whenParked() {
		$this->assertEquals('parked', $this->Vehicle->getCurrentState());
	}

	public function testWhenMethods() {
		$this->Vehicle->whenStalled(function() {
			$this->assertEquals("stalled", $this->Vehicle->getCurrentState());
		});

		$this->Vehicle->when('parked', array($this, 'whenParked'));

		$this->Vehicle->ignite();
		$this->Vehicle->shiftUp();
		$this->Vehicle->crash();
		$this->Vehicle->repair();
	}

	public function testBubble() {
		$this->Vehicle->on('ignite', 'before', function() {
			$this->assertEquals("parked", $this->Vehicle->getCurrentState());
		}, false);

		$this->Vehicle->on('transition', 'before', function() {
			// this should never be called
			$this->assertTrue(false);
		});

		$this->Vehicle->ignite();
	}

	public function testInvalidTransition() {
		$this->assertFalse($this->Vehicle->getStates('foobar'));
		$this->assertFalse($this->Vehicle->getStates('baz'));
		$this->assertFalse($this->Vehicle->baz());
	}

	public function testVehicleTitle() {
		$this->Vehicle = new Vehicle(3);

		$this->assertEquals("Opel Astra", $this->Vehicle->field('title'));
		$this->assertEquals("idling", $this->Vehicle->getCurrentState());
		$this->Vehicle->shiftUp();
		$this->assertEquals("first_gear", $this->Vehicle->getCurrentState());

		$this->Vehicle = new Vehicle(4);
		$this->assertEquals("Nissan Leaf", $this->Vehicle->field('title'));
		$this->assertEquals("stalled", $this->Vehicle->getCurrentState());
		$this->assertTrue($this->Vehicle->canRepair());
		$this->assertTrue($this->Vehicle->repair());
		$this->assertEquals("parked", $this->Vehicle->getCurrentState());
	}

	public function testCreateVehicle() {
		$this->Vehicle->create();
		$this->Vehicle->save(array(
			'Vehicle' => array(
				'title' => 'Toybota'
			)
		));
		$this->Vehicle->id = $this->Vehicle->getLastInsertID();
		$this->assertEquals($this->Vehicle->initialState, $this->Vehicle->getCurrentState());
	}

	public function testAddToPrepareArrayNoRoles() {
		$this->Vehicle = new Vehicle(1);

		$dataArrayToFill = array();
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch'
		);

		// Case 1 - illegal parameters
		$this->assertFalse($this->Vehicle->addToPrepareArray(null, $dataArrayToFill));
		$this->assertFalse($this->Vehicle->addToPrepareArray(array(), $dataArrayToFill));

		// Case 2 - adding first array
		$expected[] = $dataToAdd;
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 3 - adding second array
		$dataToAdd = array(
			'stateFrom' => 'second',
			'stateTo' => 'third',
			'transition' => 'launch2',
			'not_care' => 'anyvalue'
		);
		$expected[] = $dataToAdd;
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 4 - adding same array should not increase
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 5 - adding depends
		$dataToAdd = array(
			'stateFrom' => 'second',
			'stateTo' => 'third',
			'transition' => 'launch2',
			'depends' => 'hasValid'
		);
		$expected[] = $dataToAdd;
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 6 - adding same depends should skip it
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);
	}

	public function testAddToPrepareArrayWithRoles() {
		$this->Vehicle = new Vehicle(1);
		$expected = $dataArrayToFill = array();

		// Case 1 - adding role without depends, we reset the data
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'roles' => array('role1')
		);
		$dataArrayToFill[] = $expected[] = $dataToAdd;
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		//debug($dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 2 - adding same role twice shoud not duplicate
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 3 - Adding same state&Transition but with additional role
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'roles' => array('role2')
		);
		$expected = array();
		$expected[] = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'roles' => array('role1', 'role2')
		);
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 4 - Adding same state&Transition but with additional role
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'roles' => array('role3', 'role4')
		);
		$expected = array();
		$expected[] = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'roles' => array('role1', 'role2', 'role3', 'role4')
		);
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);
	}

	public function testAddToPrepareArrayWithDependsAndRoles() {
		$this->Vehicle = new Vehicle(1);
		$expected = $dataArrayToFill = array();

		// Case 1 - adding role without depends, we reset the data
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'depends' => 'is_allowed',
			'roles' => array('role1')
		);
		$dataArrayToFill[] = $expected[] = $dataToAdd;
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		//debug($dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 2 - adding same role twice shoud not duplicate
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 3 - Adding same state&Transition&depends but with additional role
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'depends' => 'is_allowed',
			'roles' => array('role2')
		);
		$expected = array();
		$expected[] = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'depends' => 'is_allowed',
			'roles' => array('role1', 'role2')
		);
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 4 - Adding same state&Transition&depends but with additional role
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'depends' => 'is_allowed',
			'roles' => array('role3', 'role4')
		);
		$expected = array();
		$expected[] = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'depends' => 'is_allowed',
			'roles' => array('role1', 'role2', 'role3', 'role4')
		);
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);

		// Case 5 - Adding same state&Transition&depends but with different depends
		$dataToAdd = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'depends' => 'different_is_allowed',
			'roles' => array('role3', 'role4')
		);
		$expected[] = array(
			'stateFrom' => 'initial',
			'stateTo' => 'second',
			'transition' => 'launch',
			'depends' => 'different_is_allowed',
			'roles' => array('role3', 'role4')
		);
		$dataArrayToFill = $this->Vehicle->addToPrepareArray($dataToAdd, $dataArrayToFill);
		$this->assertEqual($expected, $dataArrayToFill);
	}

	public function testToDot() {
		$this->Vehicle->toDot();
	}

	public function testCreateDotFileForRoles() {
		$this->Vehicle = new RulesVehicle(1);

		$expected = <<<EOT
digraph finite_state_machine {
	fontsize=12;
	node [shape = oval, style=filled, color = "lightgrey"];
	style=filled;
	label="Statemachine for RulesVehicle role(s) : Driver, Thief"
	"Parked" [ color = green ];
	"Parked" -> "Idling" [ style = bold, fontsize = 9, arrowType = normal, label = "Ignite by (Driver)
if Has Key" color = "blue"];
	"Stalled" -> "Stalled" [ style = bold, fontsize = 9, arrowType = normal, label = "Ignite by (Driver)
if Has Key" color = "blue"];
	"Idling" -> "Parked" [ style = bold, fontsize = 9, arrowType = normal, label = "Park by All
if Available Parking" ];
	"First Gear" -> "Parked" [ style = bold, fontsize = 9, arrowType = normal, label = "Park by All
if Available Parking" ];
	"Idling" -> "First Gear" [ style = bold, fontsize = 9, arrowType = normal, label = "Shift Up by All" ];
	"First Gear" -> "Second Gear" [ style = bold, fontsize = 9, arrowType = normal, label = "Shift Up by All" ];
	"Second Gear" -> "Third Gear" [ style = bold, fontsize = 9, arrowType = normal, label = "Shift Up by All" ];
	"First Gear" -> "Idling" [ style = bold, fontsize = 9, arrowType = normal, label = "Shift Down by All" ];
	"Second Gear" -> "First Gear" [ style = bold, fontsize = 9, arrowType = normal, label = "Shift Down by All" ];
	"Third Gear" -> "Second Gear" [ style = bold, fontsize = 9, arrowType = normal, label = "Shift Down by All" ];
	"First Gear" -> "Stalled" [ style = bold, fontsize = 9, arrowType = normal, label = "Crash by All" ];
	"Second Gear" -> "Stalled" [ style = bold, fontsize = 9, arrowType = normal, label = "Crash by All" ];
	"Third Gear" -> "Stalled" [ style = bold, fontsize = 9, arrowType = normal, label = "Crash by All" ];
	"First Gear" -> "Idling" [ style = bold, fontsize = 9, arrowType = normal, label = "Idle by All" ];
	"All" -> "Parked" [ style = bold, fontsize = 9, arrowType = normal, label = "Turn Off by All" ];
	"Parked" -> "Idling" [ style = bold, fontsize = 9, arrowType = normal, label = "Hardwire by (Thief)" color = "red"];
	"Stalled" -> "Stalled" [ style = bold, fontsize = 9, arrowType = normal, label = "Hardwire by (Thief)" color = "red"];
}

EOT;
		$this->assertEqual($expected, $this->Vehicle->createDotFileForRoles(array(
			'driver' => array(
				'color' => 'blue'),
			'thief' => array(
				'color' => 'red')
			), array(
			'color' => 'lightgrey',
			'activeColor' => 'green'
			)
		));
		// debug($expected, $this->Vehicle->createDotFileForRoles(array(
		// 	'driver' => array(
		// 		'color' => 'blue'),
		// 	'thief' => array(
		// 		'color' => 'red')
		// 	), array(
		// 	'color' => 'lightgrey',
		// 	'activeColor' => 'green'
		// 	)
		// ));
	}

	public function testCallable() {
		$this->Vehicle->addMethod('whatIsMyName', function(Model $model, $method, $name) {
			return $model->alias . '-' . $method . '-' . $name;
		});

		$this->assertEquals("Vehicle-whatIsMyName-Toybota", $this->Vehicle->whatIsMyName("Toybota"));
	}

	public function testExistingCallable() {
		$this->Vehicle->addMethod('foobar', function() {
		});

		$this->setExpectedException('InvalidArgumentException');
		$this->Vehicle->addMethod('foobar', function() {
		});
	}

	public function testUnhandled() {
		$this->setExpectedException('PDOException');
		$this->assertEquals(array("unhandled"), $this->Vehicle->handleMethodCall("foobar"));
	}

	public function testInvalidOnStateChange() {
		$this->Vehicle = new BaseVehicle(1);
		$this->Vehicle->ignite();
	}

	public function testOnStateChange() {
		$this->Vehicle = $this->getMock('Vehicle', array(
			'onStateChange', 'onStateIdling', 'onBeforeTransition', 'onAfterTransition'));
		$this->Vehicle->expects($this->once())->method('onBeforeTransition');
		$this->Vehicle->expects($this->once())->method('onAfterTransition');
		$this->Vehicle->expects($this->once())->method('onStateChange');
		$this->Vehicle->expects($this->once())->method('onStateIdling');

		$this->assertTrue($this->Vehicle->ignite());
	}

	public function testRules() {
		$this->Vehicle = new RulesVehicle(1);

		$this->assertTrue($this->Vehicle->canIgnite('driver'));
		$this->assertFalse($this->Vehicle->canIgnite('thief'));
		$this->assertTrue($this->Vehicle->canHardwire('thief'));
		$this->assertFalse($this->Vehicle->canHardwire('driver'));

		$this->Vehicle->ignite('driver');

		$this->Vehicle->igniteById(1, 'driver');
		$this->assertEquals("idling", $this->Vehicle->getCurrentStateById(1));
		$this->assertEquals("parked", $this->Vehicle->getPreviousStateById(1));
		$this->assertEquals("ignite", $this->Vehicle->getLastTransitionById(1));
		$this->assertEquals("driver", $this->Vehicle->getLastRoleById(1));

		$this->assertFalse($this->Vehicle->canPark('driver'));
		$this->assertTrue($this->Vehicle->canPark('thief'));
	}

	public function testRulesWithCanTransitionById() {
		$this->Vehicle = new RulesVehicle(1);

		$this->assertTrue($this->Vehicle->canIgniteById(1, 'driver'));
		$this->assertFalse($this->Vehicle->canIgniteById(1, 'thief'));
		$this->assertTrue($this->Vehicle->canHardwireById(1, 'thief'));
		$this->assertFalse($this->Vehicle->canHardwireById(1, 'driver'));

		$this->Vehicle->ignite('driver');

		$this->Vehicle->igniteById(1, 'driver');
		$this->assertEquals("idling", $this->Vehicle->getCurrentStateById(1));
		$this->assertEquals("parked", $this->Vehicle->getPreviousStateById(1));
		$this->assertEquals("ignite", $this->Vehicle->getLastTransitionById(1));
		$this->assertEquals("driver", $this->Vehicle->getLastRoleById(1));

		$this->assertFalse($this->Vehicle->canParkById(1, 'driver'));
		$this->assertTrue($this->Vehicle->canParkById(1, 'thief'));
	}

	public function testRuleWithCallback() {
		$this->Vehicle = new RulesVehicle(1);
		$this->Vehicle->ignite('driver');
		$this->Vehicle->shiftUp();
		$this->Vehicle->crash();

		$this->Vehicle->addMethod('hasTools', function($role) {
			return $role == 'mechanic';
		});

		$this->assertTrue($this->Vehicle->canRepair('mechanic'));
		$this->assertTrue($this->Vehicle->repair('mechanic'));
	}

	public function testInvalidRules() {
		$this->setExpectedException('InvalidArgumentException');

		$this->Vehicle = new RulesVehicle(1);
		$this->Vehicle->ignite();
	}

	public function testWrongRole() {
		$this->Vehicle = new RulesVehicle(1);
		$this->assertFalse($this->Vehicle->ignite('thief'));
	}

	public function tearDown() {
		parent::tearDown();
		unset($this->Vehicle, $this->StateMachine);
	}
}
