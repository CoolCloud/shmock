<?php

namespace Shmock\ClassBuilder;

/**
 * @package ClassBuilder
 * Decorators allow people building dynamic classes to intercept
 * and alter any method invocation. This is useful for implementing
 * spying facilities
 */
interface Decorator
{
    /**
     * @param JoinPoint $joinPoint specifies information about
     * the invocation and gives callers an opportunity to respond.
     * @return mixed|null the desired return value from the execution
     * of `$joinPoint`. If you do not intend to modify this value, simply
     * return `$joinPoint->execute()` directly.
     */
    public function decorate(JoinPoint $joinPoint);
}
