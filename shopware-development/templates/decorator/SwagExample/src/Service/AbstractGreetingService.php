<?php declare(strict_types=1);

namespace SwagExample\Service;

abstract class AbstractGreetingService
{
    abstract public function getDecorated(): AbstractGreetingService;

    abstract public function greet(string $name): string;
}
