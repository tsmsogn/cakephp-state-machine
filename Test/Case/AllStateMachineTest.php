<?php

/**
 * All StateMachine plugin tests
 */
class AllStateMachineTest extends CakeTestCase {

/**
 * Suite define the tests for this plugin
 *
 * @return void
 */
	public static function suite() {
		$suite = new CakeTestSuite('All StateMachine test');

		$path = CakePlugin::path('StateMachine') . 'Test' . DS . 'Case' . DS;
		$suite->addTestDirectoryRecursive($path);

		return $suite;
	}

}
