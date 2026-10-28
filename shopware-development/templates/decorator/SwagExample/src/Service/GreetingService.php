<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class GreetingService extends AbstractGreetingService
{
    public function getDecorated(): AbstractGreetingService
    {
        // Base class of the chain: there is nothing inner
        throw new DecorationPatternException(self::class);
    }

    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }
}
