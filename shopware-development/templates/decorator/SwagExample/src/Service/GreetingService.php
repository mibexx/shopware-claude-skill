<?php declare(strict_types=1);

namespace SwagExample\Service;

use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;

class GreetingService extends AbstractGreetingService
{
    public function getDecorated(): AbstractGreetingService
    {
        // Basisklasse der Kette: es gibt nichts Inneres
        throw new DecorationPatternException(self::class);
    }

    public function greet(string $name): string
    {
        return 'Hello ' . $name;
    }
}
