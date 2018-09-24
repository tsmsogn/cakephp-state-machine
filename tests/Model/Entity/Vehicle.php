<?php

namespace Tsmsogn\StateMachine\Test\Model\Entity;

use Cake\ORM\Entity;
use Tsmsogn\StateMachine\Model\Entity\StateMachineEntityInterface;

class Vehicle extends Entity implements StateMachineEntityInterface
{

    public function getCurrentState()
    {
        return $this->state;
    }

    public function getPreviousState()
    {
        return $this->previous_state;
    }

    public function getLastTransition()
    {
        return $this->last_transition;
    }

    public function getLastRole()
    {
        return $this->last_role;
    }
}
