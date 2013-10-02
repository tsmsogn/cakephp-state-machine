<?php

class VehicleFixture extends CakeTestFixture {

	public $fields = array(
		'id' => array('type' => 'integer', 'key' => 'primary'),
		'title' => array('type' => 'string', 'length' => 255, 'null' => false),
		'state' => array('type' => 'string', 'length' => 255, 'null' => true),
		'previous_state' => array('type' => 'string', 'length' => 255, 'null' => true),
	);

	public $records = array(
		array('id' => 1, 'title' => 'Audi Q4', 'state' => 'parked'),
		array('id' => 2, 'title' => 'Toyota Yaris', 'state' => 'parked'),
		array('id' => 3, 'title' => 'Opel Astra', 'state' => 'idling', 'previous_state' => 'parked'),
		array('id' => 4, 'title' => 'Nissan Leaf', 'state' => 'stalled', 'previous_state' => 'third_gear'),
	);
}
