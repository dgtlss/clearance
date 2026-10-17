<?php

namespace Dgtlss\Clearance\Dynamic;

use Dgtlss\Clearance\Persona;
use Dgtlss\Clearance\Personas;
use InvalidArgumentException;

final class PersonaRegistry
{
    /** @return array<string, Persona> */
    public function all(): array
    {
        $class = config('clearance.personas');

        if (! is_string($class) || ! class_exists($class)) {
            return ['guest' => Persona::guest()];
        }

        $definitions = app($class);

        if (! $definitions instanceof Personas) {
            throw new InvalidArgumentException('Configured personas must extend '.Personas::class.'.');
        }

        $personas = [];

        foreach ($definitions->define() as $persona) {
            if (! $persona instanceof Persona || isset($personas[$persona->name])) {
                throw new InvalidArgumentException('Each persona must be a Persona with a unique name.');
            }

            $personas[$persona->name] = $persona;
        }

        if ($personas === []) {
            throw new InvalidArgumentException('Define at least one persona.');
        }

        ksort($personas);

        return $personas;
    }
}
