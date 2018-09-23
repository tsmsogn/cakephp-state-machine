<?php
/**
 * All StateMachine plugin tests
 */
namespace Test\Case;

class AllStateMachineTest extends TestCase {

/**
 * Suite define the tests for this plugin
 *
 * @return void
 */
	public static function suite() {
		$suite = new CakeTestSuite('All StateMachine test');

		$path = Plugin::path('StateMachine') . 'Test' . DS . 'Case' . DS;
		$suite->addTestDirectoryRecursive($path);

		return $suite;
	}

}
