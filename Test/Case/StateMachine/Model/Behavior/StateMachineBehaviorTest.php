<?php
App::uses('StateMachineBehavior', 'StateMachine.Model/Behavior');

class Vehicle extends CakeTestModel {

	public $useTable = 'vehicles';

	public $actsAs = array('StateMachine.StateMachine');

	public $stateName = 'Vehicle';

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

class StateMachineBehaviorTest extends CakeTestCase {

	public $fixtures = array(
		'plugin.state_machine.state',
		'plugin.state_machine.vehicle'
	);

	public $Vehicle;

	public $StateMachine;

	public function setUp() {
		parent::setUp();

		$this->Vehicle = new Vehicle(1);
		$this->StateMachine = $this->Vehicle->Behaviors->StateMachine;
	}

	public function testHasBehavior() {
		$this->assertTrue($this->Vehicle->Behaviors->enabled('StateMachine'));
	}

	public function testMappedMethods() {
		$this->assertTrue($this->Vehicle->Behaviors->hasMethod('isParked'));
		$this->assertTrue($this->Vehicle->Behaviors->hasMethod('canPark'));
		$this->assertTrue($this->Vehicle->Behaviors->hasMethod('onPark'));
		$this->assertTrue($this->Vehicle->Behaviors->hasMethod('whenParked'));
	}

	public function testStateName() {
		$this->assertEquals("Vehicle", $this->Vehicle->stateName);
	}

	public function testInitialState() {
		$this->assertEquals("parked", $this->Vehicle->initialState);
		$this->assertEquals($this->Vehicle->initialState, $this->Vehicle->getCurrentState());
	}

	public function testIsMethods() {
		$this->assertEquals($this->Vehicle->isParked(), $this->Vehicle->is('parked'));
		$this->assertTrue($this->Vehicle->isParked());

		$this->assertEquals($this->Vehicle->isIdling(), $this->Vehicle->is('idling'));
		$this->assertFalse($this->Vehicle->isIdling());

		$this->assertEquals($this->Vehicle->isStalled(), $this->Vehicle->is('stalled'));
		$this->assertFalse($this->Vehicle->isStalled());

		$this->assertEquals($this->Vehicle->isIdling(), $this->Vehicle->is('idling'));
		$this->assertFalse($this->Vehicle->isIdling());
	}

	public function testCanMethods() {
		$this->assertEquals($this->Vehicle->canShiftUp(), $this->Vehicle->can('shift_up'));
		$this->assertFalse($this->Vehicle->canShiftUp());

		$this->assertEquals("parked", $this->Vehicle->getCurrentState());
		$this->assertTrue($this->Vehicle->canIgnite());
		$this->Vehicle->ignite();
		$this->assertEquals("idling", $this->Vehicle->getCurrentState());

		$this->assertTrue($this->Vehicle->canShiftUp());
		$this->assertFalse($this->Vehicle->canShiftDown());
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

	public function testWhenMethods() {
		$this->Vehicle->whenStalled(function() {
			$this->assertEquals("stalled", $this->Vehicle->getCurrentState());
		});

		$this->Vehicle->when('parked', function() {
			$this->assertEquals('parked', $this->Vehicle->getCurrentState());
		});

		$this->assertTrue($this->Vehicle->isParked());
		$this->Vehicle->ignite();
		$this->assertTrue($this->Vehicle->isIdling());
		$this->assertFalse($this->Vehicle->canCrash());
		$this->Vehicle->shiftUp();
		$this->Vehicle->crash();
		$this->assertEquals("stalled", $this->Vehicle->getCurrentState());
		$this->assertTrue($this->Vehicle->isStalled());
		$this->Vehicle->repair();
		$this->assertTrue($this->Vehicle->isParked());
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
		$this->assertEquals("idling", $this->Vehicle->field('state'));
		$this->assertEquals("idling", $this->Vehicle->getCurrentState());
		$this->Vehicle->shiftUp();
		$this->assertEquals("first_gear", $this->Vehicle->field('state'));
		$this->assertEquals("first_gear", $this->Vehicle->getCurrentState());

		$this->Vehicle = new Vehicle(4);
		$this->assertEquals("Nissan Leaf", $this->Vehicle->field('title'));
		$this->assertEquals("stalled", $this->Vehicle->getCurrentState());
		$this->assertEquals("stalled", $this->Vehicle->field('state'));
		$this->assertTrue($this->Vehicle->canRepair());
		$this->assertTrue($this->Vehicle->repair());
		$this->assertNotEquals("idling", $this->Vehicle->getCurrentState());
		$this->assertEquals("parked", $this->Vehicle->getCurrentState());
	}

	public function tearDown() {
		parent::tearDown();
		unset($this->Vehicle, $this->StateMachine);
	}
}
