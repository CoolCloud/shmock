<?php

namespace Shmock\ClassBuilder;

class ClassBuilderTest extends \PHPUnit_Framework_TestCase
{
    public function buildClass(callable $fn=null)
    {
        $builder = new ClassBuilder();
        if ($fn) {
            $fn($builder);
        }

        return $builder->create();
    }

    public function testClassesCanBeBuilt()
    {
        $this->assertTrue(class_exists($this->buildClass()), "The $clazz should have been created");
    }

    public function testClassesCanBeMarkedAsSubclassesOfOtherClasses()
    {
        $class = $this->buildClass(function ($builder) {
            $builder->setExtends(get_class($this));
        });
        $this->assertTrue(is_subclass_of($class, get_class($this)));
    }

    public function testClassesCanHaveFunctionsAttached()
    {
        $class = $this->buildClass(function ($builder) {
            $builder->addMethod("add", function ($a, $b) {
                return $a + $b;
            }, ["", ""]);
        });

        $this->assertEquals(2, (new $class)->add(1,1));
    }

    public function testClassBuilderCanExtendOtherClasses()
    {
        $class = $this->buildClass(function ($builder) {
            $builder->addMethod("add", function ($a, $b) {
                return $a + $b;
            }, ["", ""]);
            $builder->setExtends("Shmock\ClassBuilder\SampleExtension");
        });

        $instance = new $class();
        $this->assertEquals(15, $instance->multiply($instance->add(1,2), 5));
    }

    public function testClassesPassAlongTypeHints()
    {
        $class = $this->buildClass(function ($builder) {
            $builder->addInterface("Shmock\ClassBuilder\SampleInterface");
            $builder->addMethod("firstAndLast", function (array $a, array $b) {
                return array_merge($a,$b);
            }, ["array", "array"]);
        });
        $instance = new $class();
        $this->assertEquals([1,-1], $instance->firstAndLast([1], [-1]));
        $this->assertTrue(is_subclass_of($class, 'Shmock\ClassBuilder\SampleInterface'));
    }
}

abstract class SampleExtension
{
    public function multiply($x, $y)
    {
        return $x * $y;
    }
}

interface SampleInterface
{
    public function firstAndLast(array $first, array $last);
}
