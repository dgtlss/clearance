<?php

namespace Dgtlss\Clearance\Report;

final class GithubAnnotations
{
    public function render(array $finding): string
    {
        $level = in_array($finding['severity'], ['critical', 'high'], true) ? 'error' : 'warning';
        $source = $finding['source'] ?? null;
        $properties = $source ? ' file='.$this->escape($source['file'], true).',line='.(int) $source['line'] : '';

        return '::'.$level.$properties.'::'.$this->escape($finding['message'].' ('.$finding['route'].')');
    }

    private function escape(string $value, bool $property = false): string
    {
        $value = str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);

        return $property ? str_replace([':', ','], ['%3A', '%2C'], $value) : $value;
    }
}
