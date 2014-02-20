<?php

namespace Shmock;

/**
* This class is only used when in the context of mocked instance and the shmock_class function is used.
* @internal
*/
class InstanceClass extends StaticClass
{
    /**
     * @internal
     */
    private $mock;

    /**
     * @internal
     * @param mixed
     * @return void
     */
    public function set_mock($mock)
    {
        $this->mock = $mock;
    }

    /**
     * @internal
     * @return mixed
     */
    protected function construct_mock()
    {
        return $this->mock;
    }
}