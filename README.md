CakePHP State Machine
=====================
[![Build Status](https://travis-ci.org/davidsteinsland/cakephp-state-machine.png?branch=master)](https://travis-ci.org/davidsteinsland/cakephp-state-machine) [![Coverage Status](https://coveralls.io/repos/davidsteinsland/cakephp-state-machine/badge.png?branch=master)](https://coveralls.io/r/davidsteinsland/cakephp-state-machine?branch=master)

Documentation is not finished yet either. See the tests if you want to learn something.

## What is a State Machine?
http://en.wikipedia.org/wiki/State_machine

## How to Use
```php
App::uses('StateMachineBehavior', 'StateMachine.Model/Behavior');

class VehicleModel extends AppModel {

	public $useTable = 'Vehicle';

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
		)
	);

    public function __construct($id = false, $ds = false, $table = false) {
        parent::__construct($id, $ds, $table);
        $this->on('ignite', 'after', function() {
            // the car just ignited!
        });
    }

    public function isMoving() {
        return in_array($this->getCurrentState(), array('first_gear', 'second_gear', 'third_gear'));
    }
}
```

```php
class Controller .... {
    public function method() {
        $this->Vehicle->create();
        $this->Vehicle->save(array(
            'Vehicle' => array(
                'title' => 'Toybota'
            )
        ));
        // $this->Vehicle->getCurrentState() == 'parked'
        $this->Vehicle->ignite();
        $this->Vehicle->shiftUp();
        // $this->Vehicle->getCurrentState() == 'first_gear'
    }
}
```