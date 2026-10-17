<?php

namespace Dgtlss\Clearance;

abstract class Personas
{
    /** @return list<Persona> */
    abstract public function define(): array;

    public function ownership(World $world): void {}
}
