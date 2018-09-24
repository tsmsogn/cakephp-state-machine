<?php

namespace Tsmsogn\StateMachine\Model\Entity;

interface StateMachineEntityInterface
{

    public function getCurrentState();

    public function getPreviousState();

    public function getLastTransition();

    public function getLastRole();
}
