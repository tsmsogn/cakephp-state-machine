CakePHP State Machine
=====================
[![Build Status](https://travis-ci.org/davidsteinsland/cakephp-state-machine.png?branch=master)](https://travis-ci.org/davidsteinsland/cakephp-state-machine) [![Coverage Status](https://coveralls.io/repos/davidsteinsland/cakephp-state-machine/badge.png?branch=master)](https://coveralls.io/r/davidsteinsland/cakephp-state-machine?branch=master)

**This Plugin is still highly developed, and DB integrations is not yet completed. Please do not use this Plugin yet. I will put it on Packagist when a stable release is ready**.

Documentation is not finished yet either. See the tests if you want to learn something.

## What is a State Machine?
http://en.wikipedia.org/wiki/State_machine

## How to Use
```php
App::uses('StateMachineBehavior', 'StateMachine.Model/Behavior');

class VehicleModel extends CakeTestModel {

	public $useTable = false;

	public $actAs = array('StateMachine.StateMachine');

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
		)
	);
}
```

```php
class Controller .... {
    public function method() {
        $this->Vehicle->ignite();
        $this->Vehicle->shiftUp();
    }
}
```