<?php
/**
 * CakePHP(tm) : Rapid Development Framework (http://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         DebugKit 1.0
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */

App::uses('String', 'Utility');

/**
 * Benchmark Shell Class
 *
 * Provides basic benchmarking of application requests
 * functionally similar to Apache AB
 *
 * @since         DebugKit 1.0
 * @todo Print/export time detail information
 * @todo Export/graphing of data to .dot format for graphviz visualization
 * @todo Make calculated results round to leading significant digit position of std dev.
 */
class DotShell extends Shell {

	public $Model;

	public function main() {
		$this->out('Hello world.');
	}

/**
 * This function generates a png file from a given dot. A dot can be generated wit *toDot functions
 * @param  Model	$model		 The model being acted on
 * @param  string   $dot         The contents for graphviz
 * @param  string   $destFile    Name with full path to where file is to be created
 * @return return                returns whatever shell_exec returns
 */
	protected function _generatePng($dot, $destFile) {
		if (!isset($dot)) {
			return false;
		}
		$dotExec = "echo '%s' | dot -Tpng -o%s";
		$path = pathinfo($destFile);
		$command = sprintf($dotExec, $dot, $destFile);
		return shell_exec($command);
	}

	public function generate() {
		$this->out('Generate files');
		switch ($this->args[1]) {
			case 'all':
				$this->out('Load Model:' . $this->args[0]);
				$this->loadModel($this->args[0]);
				$this->Model = new $this->args[0](1);

				// generate all roles
				$dot = $this->Model->createDotFileForRoles($this->Model->roles, array(
					'color' => 'lightgrey',
					'activeColor' => 'green'
					));
				$this->_generatePng($dot, TMP . $this->args[2]);

				foreach ($this->Model->roles as $role => $options) {
					$dot = $this->Model->createDotFileForRoles(array($role => $this->Model->roles[$role]), array(
						'color' => 'lightgrey',
						'activeColor' => 'green'
						));
					$this->_generatePng($dot, TMP . $role . '_' . $this->args[2]);
				}
				# code...
				break;

			default:
				# code...
				break;
		}
	}

}

