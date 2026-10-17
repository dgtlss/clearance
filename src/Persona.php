<?php

namespace Dgtlss\Clearance;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

final class Persona
{
    public ?Closure $factory = null;

    public ?string $guardName = null;

    public bool $expectsAll = false;

    private function __construct(public readonly string $name)
    {
        if (! preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
            throw new InvalidArgumentException('Persona names must start with a lowercase letter and contain only letters, numbers, _ or -.');
        }
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public static function guest(): self
    {
        return new self('guest');
    }

    public function user(Closure $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    public function guard(string $guard): self
    {
        $this->guardName = $guard;

        return $this;
    }

    public function expectAll(): self
    {
        $this->expectsAll = true;

        return $this;
    }

    public function build(): ?Authenticatable
    {
        if ($this->factory === null) {
            if ($this->name !== 'guest') {
                throw new InvalidArgumentException("Persona [{$this->name}] needs a user factory.");
            }

            return null;
        }

        $user = ($this->factory)();

        if (! $user instanceof Authenticatable) {
            throw new InvalidArgumentException("Persona [{$this->name}] must build an Authenticatable user.");
        }

        return $user;
    }
}
