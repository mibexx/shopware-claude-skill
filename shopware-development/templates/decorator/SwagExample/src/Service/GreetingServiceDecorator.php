<?php declare(strict_types=1);

namespace SwagExample\Service;

class GreetingServiceDecorator extends AbstractGreetingService
{
    // Always type against the ABSTRACT class, never the concrete one.
    public function __construct(private readonly AbstractGreetingService $inner)
    {
    }

    public function getDecorated(): AbstractGreetingService
    {
        return $this->inner;
    }

    public function greet(string $name): string
    {
        // Call the inner service, otherwise the chain breaks for other plugins.
        return $this->inner->greet($name) . '!';
    }
}
