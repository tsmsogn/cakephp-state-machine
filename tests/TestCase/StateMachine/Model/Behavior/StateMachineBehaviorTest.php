<?php

namespace Tsmsogn\StateMachine\Test\TestCase\Model\Behavior;

use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;
use Cake\Validation\Validator;

class BaseVehicle extends Table
{

    public $useTable = 'vehicles';

    public $initialState = 'parked';

    public function initialize(array $config)
    {
        $this->setTable('vehicles');
        $this->setPrimaryKey('id');
        $this->addBehavior('Tsmsogn/StateMachine.StateMachine');
        $this->setEntityClass('Tsmsogn\StateMachine\Test\Model\Entity\Vehicle');
    }

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

class Vehicle extends BaseVehicle
{

    public function onStateChange($newState)
    {
    }

    public function onStateIdling($newState)
    {
    }

    public function onBeforeTransition($currentState, $previousState, $transition)
    {
    }

    public function onAfterTransition($currentState, $previousState, $transition)
    {
    }

    public function onBeforeIgnite($currentState, $previousState, $transition)
    {
    }

}

class RulesVehicle extends BaseVehicle
{

    public $transitionRules = array(
        'hardwire' => array(
            'role' => array('thief'),
        ),
        'ignite' => array(
            'role' => array('driver'),
            'depends' => 'has_key'
        ),
        'park' => array(
            'role' => array('driver', 'thief'),
            'depends' => 'available_parking'
        ),
        'repair' => array(
            'role' => array('mechanic'),
            'depends' => 'has_tools'
        )
    );

    public function initialize(array $config)
    {
        $this->transitions += array(
            'hardwire' => array(
                'parked' => 'idling',
                'stalled' => 'stalled'
            )
        );

        parent::initialize($config);

    }

    public function hasKey($role)
    {
        if ($role == 'driver') {
            return true;
        }

        return false;
    }

    public function availableParking($role)
    {
        return $role == 'thief';
    }

}

class ValidationsVehicle extends Vehicle
{

    public function validationDefault(Validator $validator)
    {
        $validator->add('title', 'custom', [
            'rule' => function ($value, $context) {
                return preg_match('/toyota yaris/i', $value);
            },
        ]);

        return $validator;
    }

}

class StateMachineBehaviorTest extends TestCase
{

    public $fixtures = array(
        'plugin.tsmsogn/state_machine.vehicles'
    );

    public $Vehicle;

    public $StateMachine;
    public $ValidationsVehicle;
    public $RulesVehicle;

    public function setUp()
    {
        parent::setUp();

        $this->BaseVehicle = TableRegistry::get('BaseVehicle', [
            'className' => 'Tsmsogn\StateMachine\Test\TestCase\Model\Behavior\BaseVehicle'
        ]);

        $this->Vehicle = TableRegistry::get('Vehicle', [
            'className' => 'Tsmsogn\StateMachine\Test\TestCase\Model\Behavior\Vehicle'
        ]);

        $this->ValidationsVehicle = TableRegistry::get('ValidationsVehicle', [
            'className' => 'Tsmsogn\StateMachine\Test\TestCase\Model\Behavior\ValidationsVehicle'
        ]);

        $this->RulesVehicle = TableRegistry::get('RulesVehicle', [
            'className' => 'Tsmsogn\StateMachine\Test\TestCase\Model\Behavior\RulesVehicle'
        ]);
    }

    public function testGetAllTransitions()
    {
        $this->assertCount(9, $this->Vehicle->getAllTransitions());
    }

    public function testAvailableStates()
    {
        $this->assertCount(6, $this->Vehicle->getAvailableStates());
    }

    public function testTransitionById()
    {
        $vehicle = $this->Vehicle->get(1);

        $this->Vehicle->transition($vehicle, 'ignite');
        $this->assertEquals("idling", $vehicle->getCurrentState());
        $this->assertEquals("parked", $vehicle->getPreviousState());
        $this->assertEquals("ignite", $vehicle->getLastTransition());
        $this->assertEquals("", $vehicle->getLastRole());
        $this->Vehicle->transition($vehicle, 'shift_up');
        $this->assertEquals("first_gear", $vehicle->getCurrentState());
        $this->assertEquals("idling", $vehicle->getPreviousState());
        $this->Vehicle->transition($vehicle, 'shift_up');
        $this->assertEquals("second_gear", $vehicle->getCurrentState());
        $this->assertEquals("first_gear", $vehicle->getPreviousState());
    }

    public function testIgnoreValidationOnTransition()
    {
        $vehicle = $this->ValidationsVehicle->get(1);

        $this->assertFalse($this->ValidationsVehicle->transition($vehicle, 'ignite'));
        $this->assertEquals("parked", $vehicle->getCurrentState());

        $this->assertTrue($this->ValidationsVehicle->transition($vehicle, 'ignite', null, false));
        $this->assertEquals("idling", $vehicle->getCurrentState());
    }

    public function testValidateOnTransition()
    {
        $vehicle = $this->ValidationsVehicle->get(1);
        $this->assertFalse($this->Vehicle->transition($vehicle, 'ignite'));

        $vehicle = $this->ValidationsVehicle->get(2);
        $this->assertTrue($this->Vehicle->transition($vehicle, 'ignite'));
    }

    public function testStateListener()
    {
        // test state listener on transition failed
        $vehicle = $this->Vehicle->get(1);
        $this->Vehicle = $this->getMockForModel('Tsmsogn\StateMachine\Test\TestCase\Model\Behavior\ValidationsVehicle', array(
            'onStateChange', 'onStateIdling', 'onBeforeTransition', 'onAfterTransition'));
        $this->Vehicle->expects($this->once())->method('onBeforeTransition');
        $this->Vehicle->expects($this->never())->method('onAfterTransition');
        $this->Vehicle->expects($this->never())->method('onStateChange');
        $this->Vehicle->expects($this->never())->method('onStateIdling');
        $this->assertFalse($this->Vehicle->transition($vehicle, 'ignite'));

        // test state listener on transition success
        $vehicle = $this->Vehicle->get(2);
        $this->Vehicle = $this->getMockForModel('Tsmsogn\StateMachine\Test\TestCase\Model\Behavior\ValidationsVehicle', array(
            'onStateChange', 'onStateIdling', 'onBeforeTransition', 'onAfterTransition'));
        $this->Vehicle->expects($this->once())->method('onBeforeTransition');
        $this->Vehicle->expects($this->once())->method('onAfterTransition');
        $this->Vehicle->expects($this->once())->method('onStateChange');
        $this->Vehicle->expects($this->once())->method('onStateIdling');
        $this->assertTrue($this->Vehicle->trasition($vehicle, 'ignite'));
    }

    public function testCanTransitionById()
    {
        $vehicle = $this->Vehicle->get(1);
        $this->assertTrue($this->Vehicle->is($vehicle, 'parked'));

        $this->assertFalse($this->Vehicle->can($vehicle, 'shift_up'));

        $this->assertTrue($this->Vehicle->can($vehicle, 'ignite'));
        $this->Vehicle->transition($vehicle, 'ignite');
        $this->assertEquals("idling", $vehicle->getCurrentState());

        $this->assertTrue($this->Vehicle->can($vehicle, 'shift_up'));
        $this->assertFalse($this->Vehicle->can($vehicle, 'shift_down'));

        $vehicle = $this->Vehicle->get(2);
        $this->assertFalse($this->Vehicle->can($vehicle, 'shift_up'));
    }

    public function testInitialState()
    {
        $vehicle = $this->Vehicle->get(1);
        $this->assertEquals("parked", $vehicle->getCurrentState());
        $this->assertEquals('parked', $this->Vehicle->getStates($vehicle, 'turn_off'));
    }

    public function testIsMethodsById()
    {
        $vehicle = $this->Vehicle->get(1);
        $this->assertEquals("parked", $vehicle->getCurrentState());
        $this->assertTrue($this->Vehicle->is($vehicle, 'parked'));

        $this->Vehicle->transition($vehicle, 'ignite');

        $this->assertEquals("idling", $vehicle->getCurrentState());
        $this->assertTrue($this->Vehicle->is($vehicle, 'idling'));

        $vehicle = $this->Vehicle->get(2);
        $this->assertEquals("parked", $vehicle->getCurrentState());
        $this->assertTrue($this->Vehicle->is($vehicle, 'parked'));
        $this->assertFalse($this->Vehicle->is($vehicle, 'idling'));
    }

    public function testIsMethods()
    {
        $vehicle = $this->Vehicle->get(1);
        $this->assertTrue($this->Vehicle->is($vehicle, 'parked'));
        $this->assertFalse($this->Vehicle->is($vehicle, 'idling'));
        $this->assertFalse($this->Vehicle->is($vehicle, 'stalled'));

        $this->assertFalse($this->Vehicle->can($vehicle, 'shift_up'));

        $this->assertTrue($this->Vehicle->can($vehicle, 'ignite'));

        $this->Vehicle->transition($vehicle, 'ignite');
        $this->assertEquals("idling", $vehicle->getCurrentState());

        $this->assertTrue($this->Vehicle->can($vehicle, 'shift_up'));
        $this->assertFalse($this->Vehicle->can($vehicle, 'shift_down'));

        $this->assertTrue($this->Vehicle->is($vehicle, 'idling'));
        $this->assertFalse($this->Vehicle->can($vehicle, 'crash'));
        $this->Vehicle->transition($vehicle, 'shift_up');
        $this->Vehicle->transition($vehicle, 'crash');
        $this->assertEquals("stalled", $vehicle->getCurrentState());
        $this->assertTrue($this->Vehicle->is($vehicle, 'stalled'));
        $this->Vehicle->transition($vehicle, 'repair');
        $this->assertTrue($this->Vehicle->is($vehicle, 'parked'));
    }

    public function testOnMethods()
    {
        $scope = $this;
        $this->Vehicle->on('ignite', 'before', function ($currentState, $previousState, $transition) use ($scope) {
            $scope->assertEquals("parked", $currentState);
            $scope->assertNull($previousState);
            $scope->assertEquals("ignite", $transition);
        });

        $this->Vehicle->on('ignite', 'after', function ($currentState, $previousState, $transition) use ($scope) {
            $scope->assertEquals("idling", $currentState);
            $scope->assertEquals("parked", $previousState);
            $scope->assertEquals("ignite", $transition);
        });

        $vehicle = $this->Vehicle->get(1);
        $this->Vehicle->transition($vehicle, 'ignite');
    }

    public function testBadMethodCall()
    {
        $this->expectException('PDOException');
        $vehicle = $this->Vehicle->get(1);
        $this->Vehicle->is($vehicle, 'foobar');
    }

    public function whenParked()
    {
        $this->assertEquals('parked', $this->Vehicle->getCurrentState());
    }

    public function testWhenMethods()
    {
        $this->Vehicle->when('stalled', function () {
            $this->assertEquals("stalled", $this->Vehicle->getCurrentState());
        });

        $this->Vehicle->when('parked', array($this, 'whenParked'));

        $vehicle = $this->Vehicle->get(1);
        $this->Vehicle->transition($vehicle, 'ignite');
        $this->Vehicle->transition($vehicle, 'shift_up');
        $this->Vehicle->transition($vehicle, 'crash');
        $this->Vehicle->transition($vehicle, 'repair');
    }

    public function testBubble()
    {
        $scope = $this;
        $this->Vehicle->on('ignite', 'before', function () use ($scope) {
            $scope->assertEquals("parked", $scope->Vehicle->getCurrentState());
        }, false);

        $this->Vehicle->on('transition', 'before', function () use ($scope) {
            // this should never be called
            $scope->assertTrue(false);
        });

        $vehicle = $this->Vehicle->get(1);
        $this->Vehicle->transition($vehicle, 'ignite');
    }

    public function testInvalidTransition()
    {
        $vehicle = $this->Vehicle->get(1);
        $this->assertFalse($this->Vehicle->getStates($vehicle, 'foobar'));
        $this->assertFalse($this->Vehicle->getStates($vehicle, 'baz'));
        $this->assertFalse($this->Vehicle->transition($vehicle, 'baz'));
    }

    public function testVehicleTitle()
    {
        $vehicle = $this->Vehicle->get(3);
        $this->assertEquals("Opel Astra", $vehicle->title);
        $this->assertEquals("idling", $vehicle->getCurrentState());
        $this->Vehicle->transition($vehicle, 'shift_up');
        $this->assertEquals("first_gear", $vehicle->getCurrentState());

        $vehicle = $this->Vehicle->get(4);
        $this->assertEquals("Nissan Leaf", $vehicle->title);
        $this->assertEquals("stalled", $vehicle->getCurrentState());
        $this->assertTrue($this->Vehicle->can($vehicle, 'repair'));
        $this->assertTrue($this->Vehicle->transition($vehicle, 'repair'));
        $this->assertEquals("parked", $vehicle->getCurrentState());
    }

    public function testCreateVehicle()
    {
        $vehicle = $this->Vehicle->newEntity();
        $vehicle->title = 'Toybota';
        $this->Vehicle->save($vehicle);
        $this->assertEquals($this->Vehicle->initialState, $vehicle->getCurrentState());
    }

    public function testAddToPrepareArrayNoRoles()
    {
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

    public function testAddToPrepareArrayWithRoles()
    {
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

    public function testAddToPrepareArrayWithDependsAndRoles()
    {
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

    public function testToDot()
    {
        $this->Vehicle->toDot();
    }

    public function testCreateDotFileForRoles()
    {
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
        $this->assertEquals($expected, $this->RulesVehicle->createDotFileForRoles(array(
            'driver' => array(
                'color' => 'blue'),
            'thief' => array(
                'color' => 'red')
        ), array(
                'color' => 'lightgrey',
                'activeColor' => 'green'
            )
        ));
    }

    public function testCallable()
    {
        $this->Vehicle->addMethod('whatIsMyName', function (Table $table, $method, $name) {
            return $table->getAlias() . '-' . $method . '-' . $name;
        });

        $this->assertEquals("Vehicle-whatIsMyName-Toybota", $this->Vehicle->whatIsMyName("Toybota"));
    }

    public function testExistingCallable()
    {
        $this->Vehicle->addMethod('foobar', function () {
        });

        $this->expectException('InvalidArgumentException');
        $this->Vehicle->addMethod('foobar', function () {
        });
    }

    public function testUnhandled()
    {
        $this->expectException('PDOException');
        $this->assertEquals(array("unhandled"), $this->Vehicle->handleMethodCall("foobar"));
    }

    public function testInvalidOnStateChange()
    {
        $vehicle = $this->BaseVehicle->get(1);
        $this->BaseVehicle->transition($vehicle, 'ignite');
    }

    public function testOnStateChange()
    {
        $vehicle = $this->Vehicle->get(1);

        $this->Vehicle = $this->getMockForModel('Tsmsogn\StateMachine\Test\TestCase\Model\Behavior\Vehicle', array(
            'onStateChange', 'onStateIdling', 'onBeforeTransition', 'onAfterTransition'));
        $this->Vehicle->expects($this->once())->method('onBeforeTransition');
        $this->Vehicle->expects($this->once())->method('onAfterTransition');
        $this->Vehicle->expects($this->once())->method('onStateChange');
        $this->Vehicle->expects($this->once())->method('onStateIdling');

        $this->assertTrue($this->Vehicle->transition($vehicle, 'ignite'));
    }

    public function testRules()
    {
        $vehicle = $this->RulesVehicle->get(1);

        $this->assertTrue($this->RulesVehicle->can($vehicle, 'ignite', 'driver'));
        $this->assertFalse($this->RulesVehicle->can($vehicle, 'ignite', 'thief'));
        $this->assertTrue($this->RulesVehicle->can($vehicle, 'hardwire', 'thief'));
        $this->assertFalse($this->RulesVehicle->can($vehicle, 'hardwire', 'driver'));

        $this->RulesVehicle->transition($vehicle, 'ignite', 'driver');

        $this->RulesVehicle->transition($vehicle, 'ignite', 'driver');
        $this->assertEquals("idling", $vehicle->getCurrentState());
        $this->assertEquals("parked", $vehicle->getPreviousState());
        $this->assertEquals("ignite", $vehicle->getLastTransition());
        $this->assertEquals("driver", $vehicle->getLastRole());

        $this->assertFalse($this->RulesVehicle->can($vehicle, 'park', 'driver'));
        $this->assertTrue($this->RulesVehicle->can($vehicle, 'park', 'thief'));
    }

    public function testRulesWithCanTransitionById()
    {
        $vehicle = $this->RulesVehicle->get(1);

        $this->assertTrue($this->RulesVehicle->can($vehicle, 'ignite', 'driver'));
        $this->assertFalse($this->RulesVehicle->can($vehicle, 'ignite', 'thief'));
        $this->assertTrue($this->RulesVehicle->can($vehicle, 'hardwire', 'thief'));
        $this->assertFalse($this->RulesVehicle->can($vehicle, 'hardwire', 'driver'));

        $this->RulesVehicle->transition($vehicle, 'ignite', 'driver');

        $this->RulesVehicle->transition($vehicle, 'ignite', 'driver');
        $this->assertEquals("idling", $vehicle->getCurrentState());
        $this->assertEquals("parked", $vehicle->getPreviousState());
        $this->assertEquals("ignite", $vehicle->getLastTransition());
        $this->assertEquals("driver", $vehicle->getLastRole());

        $this->assertFalse($this->RulesVehicle->can($vehicle, 'park', 'driver'));
        $this->assertTrue($this->RulesVehicle->can($vehicle, 'park', 'thief'));
    }

    public function testRuleWithCallback()
    {
        $vehicle = $this->RulesVehicle->get(1);

        $this->RulesVehicle->transition($vehicle, 'ignite', 'driver');
        $this->RulesVehicle->transition($vehicle, 'shift_up');
        $this->RulesVehicle->transition($vehicle, 'crash');

        $this->RulesVehicle->addMethod('hasTools', function ($role) {
            return $role == 'mechanic';
        });

        $this->assertTrue($this->RulesVehicle->can($vehicle, 'repair', 'mechanic'));
        $this->assertTrue($this->RulesVehicle->transition($vehicle, 'repair', 'mechanic'));
    }

    public function testInvalidRules()
    {
        $this->expectException('InvalidArgumentException');

        $vehicle = $this->RulesVehicle->get(1);
        $this->RulesVehicle->transition($vehicle, 'ignite');
    }

    public function testWrongRole()
    {
        $vehicle = $this->RulesVehicle->get(1);
        $this->assertFalse($this->RulesVehicle->transition($vehicle, 'transition', 'thief'));
    }

    public function tearDown()
    {
        parent::tearDown();
        unset($this->Vehicle, $this->StateMachine);
    }
}
