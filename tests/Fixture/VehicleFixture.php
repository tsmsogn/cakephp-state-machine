<?php

namespace Test\Fixture;

class VehicleFixture extends TestFixture
{

    public $fields = array(
        'id' => array('type' => 'integer', 'key' => 'primary'),
        'title' => array('type' => 'string', 'length' => 255, 'null' => false),
        'state' => array('type' => 'string', 'length' => 255, 'null' => true),
        'previous_state' => array('type' => 'string', 'length' => 255, 'null' => true),
        'last_transition' => array('type' => 'string', 'length' => 255, 'null' => true),
        'last_role' => array('type' => 'string', 'length' => 255, 'null' => true),
    );

    public $records = array(
        array('id' => 1, 'title' => 'Audi Q4', 'state' => 'parked', 'last_transition' => '', 'last_role' => ''),
        array('id' => 2, 'title' => 'Toyota Yaris', 'state' => 'parked', 'last_transition' => '', 'last_role' => ''),
        array('id' => 3, 'title' => 'Opel Astra', 'state' => 'idling', 'previous_state' => 'parked', 'last_transition' => 'ignite', 'last_role' => ''),
        array('id' => 4, 'title' => 'Nissan Leaf', 'state' => 'stalled', 'previous_state' => 'third_gear', 'last_transition' => 'crash', 'last_role' => ''),
    );
}
