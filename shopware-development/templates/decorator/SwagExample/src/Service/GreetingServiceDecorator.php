<?php declare(strict_types=1);

namespace SwagExample\Service;

class GreetingServiceDecorator extends AbstractGreetingService
{
    // Immer die ABSTRAKTE Klasse typisieren, nie die konkrete.
    public function __construct(private readonly AbstractGreetingService $inner)
    {
    }

    public function getDecorated(): AbstractGreetingService
    {
        return $this->inner;
    }

    public function greet(string $name): string
    {
        // Den inneren Service aufrufen, sonst bricht die Kette fuer andere Plugins.
        return $this->inner->greet($name) . '!';
    }
}
