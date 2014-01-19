<?php
namespace Shmock;

require_once 'PHPUnit/Autoload.php';
require_once __DIR__ . '/Shmockers.php';
require_once __DIR__ . '/Policy.php';

class Shmock
{
	/**
	 * @var \Shmock\Policy[] Do not modify this directly, use {add_policy()} and {clear_policies()}
	 */
	public static $policies = [];

    public static function create($test_case, $class, $closure)
    {
        $shmock = new Shmock_Instance($test_case, $class);
        if ($closure) {
            $closure($shmock);
        }

        return $shmock->replay();
    }

    public static function create_class($test_case, $class, $closure)
    {
        $shmock_class = new Shmock_Class($test_case, $class);
        if ($closure) {
            $closure($shmock_class);
        }

        return $shmock_class->replay();
    }

	public static function add_policy(Policy $policy)
	{
		self::$policies[] = $policy;
	}

	public function clear_policies()
	{
		self::$policies = [];
	}
}


/**
* PHP 5.4 or later
*/
class Shmock_Instance
{

    /** @var \PHPUnit_Framework_TestCase */
    protected $test_case = null;
    protected $specs = array();
    protected $class = null;
    protected $preserve_original_methods = true;
    protected $disable_original_constructor = false;

    /** @var callable
    * If we want to shmock the static context of a shmock'd object
    * we need to call get_class() on the final mock, so we save
    * any configuration closure until after everything is done.
    */
    protected $shmock_class_closure = null;

    protected $order_matters = false;
    protected $call_index = 0;

    protected $constructor_arguments = array();
    protected $methods = array();

    public function __construct($test_case, $class)
    {
        $this->test_case = $test_case;
        $this->class = $class;
    }

    public function disable_original_constructor()
    {
        $this->disable_original_constructor = true;

        return $this;
    }

    public function disable_strict_method_checking()
    {
        throw new \BadMethodCallException("Shmock no longer allows you to disable strict method checking. If you are unsure of how to solve your issue, please talk to someone in qual-ed.");
    }

    /**
     * Any arguments passed in here will be included in the
     * constructor call for the mocked class.
     */
    public function set_constructor_arguments()
    {
        $this->constructor_arguments = func_get_args();
    }

    public function dont_preserve_original_methods()
    {
        $this->preserve_original_methods = false;

        return $this;
    }

    public function order_matters()
    {
        $this->order_matters = true;

        return $this;
    }

    public function order_doesnt_matter()
    {
        $this->order_matters = false;

        return $this;
    }

    protected function construct_mock()
    {
        $builder = $this->test_case->getMockBuilder($this->class);

        if ($this->disable_original_constructor) {
            $builder->disableOriginalConstructor();
        }
        if ($this->preserve_original_methods) {
            if (count($this->methods) == 0) {
                /*
                 * If you pass an empty array of methods to the PHPUnit mock builder,
                 * it's effectively like saying don't preserve any methods at all. Instead
                 * we tell the builder to mock a single fake method when necessary.
                 */
                $this->methods[] = "__fake_method_for_shmock_to_preserve_methods";
            }
            $builder->setMethods(array_unique($this->methods));
        }
        if ($this->constructor_arguments) {
            $builder->setConstructorArgs($this->constructor_arguments);
        }
        $mock = $builder->getMock();

        return $mock;
    }

    public function replay()
    {
        $shmock_instance_class = null;
        if ($this->shmock_class_closure) {
            /** @var callable $s */
            $s = $this->shmock_class_closure;
            $shmock_instance_class = new Shmock_Instance_Class($this->test_case, $this->class);
            $s($shmock_instance_class);
            $this->methods = array_merge($this->methods, $shmock_instance_class->methods);
        }

        $mock = $this->construct_mock();

        if ($shmock_instance_class) {
            $shmock_instance_class->set_mock($mock);
            $shmock_instance_class->replay();
        }

        foreach ($this->specs as $spec) {
            $spec->finalize_expectations($mock, Shmock::$policies, false, $this->class);
        }

        return $mock;
    }

    protected function do_strict_method_test($method, $with)
    {
        if (!class_exists($this->class) && !interface_exists($this->class)) {
            $this->test_case->fail("Class {$this->class} not found.");
        }

        $err_msg = "#$method is a static method on the class {$this->class}, but you expected it to be an instance method.";

        try {
            $reflection_method = new \ReflectionMethod($this->class, $method);
            $this->test_case->assertFalse($reflection_method->isStatic(), $err_msg);
            $this->test_case->assertFalse($reflection_method->isPrivate(), "#$method is a private method on {$this->class}, but you cannot mock a private method.");
        } catch (\ReflectionException $e) {
            $this->test_case->assertTrue(method_exists($this->class, '__call'), "The method $method does not exist on the class {$this->class}.");
        }
    }

    public function shmock_class($closure)
    {
        $this->shmock_class_closure = $closure;
    }

    public function __call($method, $with)
    {
        $this->do_strict_method_test($method, $with);
        $this->methods[] = $method;
        $spec = new Shmock_PHPUnit_Spec($this->test_case, $this, $method, $with, $this->order_matters, $this->call_index);
        $this->specs[] = $spec;
        $this->call_index++;

        return $spec;
    }
}


class Shmock_Class extends Shmock_Instance
{

    protected function do_strict_method_test($method, $with)
    {
        $err_msg = "#$method is an instance method on the class {$this->class}, but you expected it to be static.";
        try {
            $reflection_method = new \ReflectionMethod($this->class, $method);
            $this->test_case->assertTrue($reflection_method->isStatic(), $err_msg);
            $this->test_case->assertFalse($reflection_method->isPrivate(), "#$method is a private method on {$this->class}, but you cannot mock a private method.");
        } catch (\ReflectionException $e) {
            $this->test_case->assertTrue(method_exists($this->class, '__callStatic'), "The method #$method does not exist on the class {$this->class}");
        }

    }

    /**
    * Since you can't use the builder paradigm for mock classes, we have to play dirty here.
    */
    public function replay()
    {
        $mock_class = get_class($this->construct_mock());

        foreach ($this->specs as $spec) {
            $spec->finalize_expectations($mock_class, Shmock::$policies, true, $this->class);
        }

        return $mock_class;
    }
}

/**
* This class is only used when in the context of mocked instance and the shmock_class function is used.
* For example
*/
class Shmock_Instance_Class extends Shmock_Class
{
    private $mock;

    public function set_mock($mock)
    {
        $this->mock = $mock;
    }

    protected function construct_mock()
    {
        return $this->mock;
    }
}

class Shmock_PHPUnit_Spec
{

    private $test_case = null;
    private $method = null;
    private $with = null;
    private $times = 1;
    private $will = null;
    private $order_matters = null;
    private $call_index = null;
    private $at_least_once = false;
    private $return_this = false;

    private $parameter_sets = array(); // for policies
    private $returned_values = array(); // for policies
    private $thrown_exceptions = array(); // for policies

    public function __construct($test, $shmock, $method, $with, $order_matters, $call_index)
    {
        $this->test_case = $test;
        $this->method = $method;
        $this->with = $with;
        if ($with) {
            $this->parameter_sets[] = $with;
        }
        $this->order_matters = $order_matters;
        $this->call_index = $call_index;
    }

    public function times($times)
    {
        $this->times = $times;

        return $this;
    }

    public function once()
    {
        return $this->times(1);
    }

    public function twice()
    {
        return $this->times(2);
    }

    public function any()
    {
        return $this->times(null);
    }

    public function never()
    {
        return $this->times(0);
    }

    public function at_least_once()
    {
        $this->at_least_once = true;

        return $this;
    }

    public function will($will_closure)
    {
        $this->will = $will_closure;

        return $this;
    }

    /**
    * An order-agnostic set of return values given a set of inputs.
    *
    * @param mixed[][] an array of arrays of arguments with the final value
    * of the array being the return value.
    *
    * For example, if you were simulating addition:
    *
    * $shmock_calculator->add()->return_value_map([
    * 	[1, 2, 3], // 1 + 2 = 3
    * 	[10, 15, 25],
    * 	[11, 11, 22]
    * ]);
    *
    */
    public function return_value_map($map_of_args_to_values)
    {
        $limit = count($map_of_args_to_values);
        $this->test_case->assertGreaterThan(0, $limit, 'Must specify at least one return value');
        $this->times($limit);

        $stub = new \PHPUnit_Framework_MockObject_Stub_ReturnValueMap($map_of_args_to_values);

        foreach ($map_of_args_to_values as $params_and_return) {
            $this->parameter_sets[] = array_slice($params_and_return, 0, count($params_and_return) - 1);
            $this->returned_values[] = $params_and_return[count($params_and_return) - 1];
        }

        return $this->will(function ($invocation) use ($stub) {
            return $stub->invoke($invocation);
        });
    }

    /**
     * Maps regex to parameters to return values. See Pattern_Map class for more info.
     * This would be awesome to incorporate with call counts, but you cant use the times() because
     * phpunit is not granualar enough. It can assert on how many times this method was called,
     * but not the method with certain patterns.
     *
     * Need to do that after the test is over: $stub->has_met_minimum_call_count()... how?
     *
     * @param array - pattern_maps - An array of Pattern_Map pbjects
     */
    public function return_pattern_match(Pattern_Match $pattern_match)
    {
        return $this->will(function ($invocation) use ($pattern_match) {
            return $pattern_match($invocation->parameters);
        });
    }

    public function return_true()
    {
        return $this->return_value(true);
    }

    public function return_false()
    {
        return $this->return_value(false);
    }

    public function return_null()
    {
        return $this->return_value(null);
    }

    public function return_value($value)
    {
        $this->returned_values[] = $value;

        return $this->will(function () use ($value) {
            return $value;
        });
    }

    public function return_this()
    {
        $this->return_this = true;
    }

    public function throw_exception($e=null)
    {
        $this->thrown_exceptions[] = $e ?: new \Exception();

        return $this->will(function () use ($e) {
            if (!$e) {
                $e = new Shmock_Exception();
            }
            throw $e;
        });
    }

    public function return_consecutively($array_of_values, $keep_returning_last_value=false)
    {
        $this->returned_values = array_merge($this->returned_values, $array_of_values);
        $this->will(function () use ($array_of_values, $keep_returning_last_value) {
            static $counter = -1;
            $counter++;
            if ($counter == count($array_of_values)) {
                if ($keep_returning_last_value) {
                    return $array_of_values[count($array_of_values)-1];
                }
            } else {
                return $array_of_values[$counter];
            }
        });
        if (!$keep_returning_last_value) {
            $this->times(count($array_of_values));
        }

        return $this;
    }

    public function return_shmock($class, $shmock_closure=null)
    {
        $test_case = $this->test_case;
        if ($shmock_closure) {
            return $this->return_value(Shmock::create($test_case, $class, $shmock_closure));
        } else {
            return $this;
        }
    }

    /**
    * @param mixed $mock
    * @param \Shmock\Policy[] $policies
    * @param boolean $static
    */
    public function finalize_expectations($mock, array $policies, $static, $class)
    {
        $test_case = $this->test_case;

        foreach ($policies as $policy) {
            foreach ($this->returned_values as $returned_value) {
                $policy->check_method_return_value($class, $this->method, $returned_value, $static);
            }
            foreach ($this->thrown_exceptions as $thrown) {
                $policy->check_method_throws($class, $this->method, $thrown, $static);
            }
            foreach ($this->parameter_sets as $parameter_set) {
                $policy->check_method_parameters($class, $this->method, $parameter_set, $static);
            }
        }

        if ($this->times === null) {
            if ($static) {
                $builder = $mock::staticExpects($test_case->any());
            } else {
                $builder = $mock->expects($test_case->any());
            }
        } elseif ($this->order_matters) {
            if ($static) {
                $builder = $mock::staticExpects($test_case->at($this->call_index));
            } else {
                $builder = $mock->expects($test_case->at($this->call_index));
            }
        } elseif ($this->at_least_once) {
            if ($static) {
                $builder = $mock::staticExpects($test_case->atLeastOnce());
            } else {
                $builder = $mock->expects($test_case->atLeastOnce());
            }
        } else {
            if ($static) {
                $builder = $mock::staticExpects($test_case->exactly($this->times));
            } else {
                $builder = $mock->expects($test_case->exactly($this->times));
            }
        }

        $builder->method($this->method);

        if ($this->with) {
            $function = new \ReflectionMethod(get_class($builder),'with');
            $function->invokeargs($builder, $this->with);

        }

        if ($this->return_this) {
            if ($this->will) {
                throw new \InvalidArgumentException("You cannot specify return_this with another will() operation like return_value or throw_exception");
            } else {
                $this->return_value($mock);
            }
        }

        if ($this->will) {
            $builder->will(new Shmock_Closure_Invoker($this->will));
        }
    }
}

class Shmock_Closure_Invoker implements \PHPUnit_Framework_MockObject_Stub
{
    /** @var callable */
    private $closure = null;

    public function __construct($closure)
    {
        $this->closure = $closure;
    }
    public function invoke(\PHPUnit_Framework_MockObject_Invocation $invocation)
    {
        $fn = $this->closure;

        return $fn($invocation);
    }

    public function toString()
    {
        return "Closure invoker";
    }
}

class Shmock_Exception extends \Exception {}
