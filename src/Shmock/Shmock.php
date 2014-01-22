<?php

/**
 * @package \Shmock provides a stricter, more fluid interface on top of vanilla PHPUnit mockery. In
 * addition to a fluent builder syntax, it will do stricter inspection of mock objects to
 * ensure that they meet important criteria. Out of the box, Shmock will enforce that static
 * methods cannot be mocked non-statically, that private methods cannot be mocked at all,
 * and that the class or interface being mocked must exist. You can even program custom
 * checks that will apply to every mock object you create using Shmock Policies.
 */
namespace Shmock;

require_once 'PHPUnit/Autoload.php';
require_once __DIR__ . '/PHPUnit_Spec.php';
require_once __DIR__ . '/Shmockers.php';
require_once __DIR__ . '/Policy.php';

/**
 * The Shmock\Shmock class is the entry point to the fluent Shmock interface. You may use this class
 * directly to create mocks. Alternatively, use the Shmockers trait to include shorthand versions
 * of create() and create_class() in your test cases.
 *
 * Sample usage:
 * <pre>
 * // build a mock of MyCalculator, expecting a call to add
 * // with arguments [1,2] and return the value 3, exactly once.
 *
 * $mock = \Shmock\Shmock::create($this, 'MyCalculator', function ($calc) {
 *   $calc->add(1,2)->return_value(3);
 * });
 * </pre>
 *
 * In the example above, the invocation target of the method <code>add(1,2)</code>
 * is an of \Shmock\Shmock_Instance. This instance will allow you to mock any
 * instance method on the class MyCalculator, so it might allow <code>add</code> or <code>subtract</code>,
 * but not <code>openFileStream()</code> or <code>sbutract</code>. The result of the method
 * is an instance of \Shmock\PHPUnit_Spec, which contains many of the familiar
 * expectation-setting methods for mock frameworks.
 *
 * @see \Shmock\PHPUnit_Spec See \Shmock\PHPUnit_Spec to get a sense of what methods are available for setting expectations.
 */
class Shmock
{
    /**
     * @var \Shmock\Policy[] Do not modify this directly, use {add_policy()} and {clear_policies()}
     */
    public static $policies = [];

    /**
     * Create an instance of a mock object. Shmock uses a build / replay model for building mock objects.
     * The third argument to the create method is a callable that acts as the mock's build phase. The resulting
     * object from the create method is the object in the replay phase. You may easily design your own
     * build / replay lifecycle to meet your needs by using the Shmock_Instance and Shmock_Class classes directly.
     *
     * <pre>
     * $shmock = new \Shmock\Shmock_Instance($this, 'MyCalculator');
     * $shmock->add(1,2)->return_value(3);
     * $mock = $shmock->replay();
     * </pre>
     *
     * @param  \PHPUnit_Framework_TestCase $test_case
     * @param  string                      $class     the class being mocked
     * @param  callable                    $closure   the build phase of the mock
     * @return mixed                       An instance of a subclass of $class. PHPUnit mocks require that all mocks
     * be subclasses of the target class in order to replace target methods. For this reason, mocking
     * will fail if the class is final.
     * @see \Shmock\Shmock_Instance \Shmock\Shmock_Instance
     * @see \Shmock\Shmock_Class \Shmock\Shmock_Class
     */
    public static function create(\PHPUnit_Framework_TestCase $test_case, $class, callable $closure)
    {
        $shmock = new Shmock_Instance($test_case, $class);
        if ($closure) {
            $closure($shmock);
        }

        return $shmock->replay();
    }

    /**
     * Create a mock class. Mock classes go through the build / replay lifecycle like mock instances do.
     * @param  \PHPUnit_Framework_TestCase $test_case
     * @param  string                      $class     the class to be mocked
     * @param  callable                    $closure   the closure to apply to the class mock in its build phase.
     * @return string                      a subclass of $class that has mock expectations set on it.
     * @see \Shmock\Shmock::create()
     */
    public static function create_class($test_case, $class, $closure)
    {
        $shmock_class = new Shmock_Class($test_case, $class);
        if ($closure) {
            $closure($shmock_class);
        }

        return $shmock_class->replay();
    }

    /**
     * Add a policy to Shmock that ensures qualities about mock objects as they are created. Policies
     * allow you to highly customize the behavior of Shmock.
     * @param  \Shmock\Policy $policy
     * @return void
     * @see \Shmock\Policy See \Shmock\Policy for documentation on how to create custom policies.
     */
    public static function add_policy(Policy $policy)
    {
        self::$policies[] = $policy;
    }

    /**
     * Clears any set policies.
     * @return void
     */
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
    protected $specs = [];
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

    /**
     * Prevent the original constructor from being called when
     * the replay phase begins. This can be important if the
     * constructor of the class being mocked takes complex arguments or
     * performs work that cannot be intercepted.
     * @return \Shmock\Shmock_Instance
     * @see \Shmock\Shmock_Instance::set_constructor_arguments()
     */
    public function disable_original_constructor()
    {
        $this->disable_original_constructor = true;

        return $this;
    }

    /**
     * @deprecated
     * @throws \BadMethodCallException
     */
    public function disable_strict_method_checking()
    {
        throw new \BadMethodCallException("Shmock no longer allows you to disable strict method checking. If you are unsure of how to solve your issue, please talk to someone in qual-ed.");
    }

    /**
     * Any arguments passed in here will be included in the
     * constructor call for the mocked class.
     * @param *mixed|null Arguments to the target constructor
     * @return void
     * @see \Shmock\Shmock_Instance::disable_original_constructor()
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
        $spec = new PHPUnit_Spec($this->test_case, $this, $method, $with, $this->order_matters, $this->call_index);
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