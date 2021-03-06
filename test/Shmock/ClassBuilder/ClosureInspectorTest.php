<?php

namespace Shmock\ClassBuilder;

class ClosureInspectorTest extends \PHPUnit_Framework_TestCase
{
    public function hintableFunctions()
    {
        return [
            [function ($a, $b) {}, ["\$a", "\$b"]],
            [function (array $a) {}, ["array \$a"]],
            [function (array $a, ClosureInspectorTest $test) {}, ["array \$a", "\Shmock\ClassBuilder\ClosureInspectorTest \$test"]],
        ];
    }

    /**
     * @dataProvider hintableFunctions
     */
    public function testMethodInspectorCanNameTheTypeHintsOnAFunction(callable $fn, array $typeHints)
    {
        $inspector = new ClosureInspector($fn);
        $this->assertSame($inspector->signatureArgs(), $typeHints);
    }
}
