<?php

namespace Dgtlss\Clearance\Matrix;

final class Entries
{
    public static function all(array $matrix): array
    {
        return $matrix['routes'] + ($matrix['surfaces'] ?? []);
    }

    public static function section(string $id): string
    {
        return str_starts_with($id, 'livewire ') || str_starts_with($id, 'filament ') ? 'surfaces' : 'routes';
    }
}
