<?php

namespace Shmock;

/**
* Shmock policies describe rules for how mocks can be constructed. Extend this abstract
* class and intercept what you need. Failures in each of these methods should trigger an Exception.
*/
abstract class Policy
{
    /**
     * @param  string  $class      the class being mocked
     * @param  string  $method     the method being mocked
     * @param  array   $parameters the parameters to the mocked method
     * @param  boolean $static     whether the method is static or not.
     * @return void
     */
    public function check_method_parameters($class, $method, $parameters, $static) {}

    /**
     * @param  string     $class
     * @param  string     $method
     * @param  mixed|null $return_value the return value that is expected to be returned.
     * @param  boolean    $static
     * @return void
     */
    public function check_method_return_value($class, $method, $return_value, $static) {}

    /**
     * @param  string    $class
     * @param  string    $method
     * @param  Exception $exception the exception that is expected to be thrown
     * @param  boolean   $static
     * @return void
     */
    public function check_method_throws($class, $method, $exception, $static) {}

}
