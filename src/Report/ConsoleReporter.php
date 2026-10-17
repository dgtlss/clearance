<?php

namespace Dgtlss\Clearance\Report;

use Dgtlss\Clearance\Matrix\MarkdownRenderer;
use Illuminate\Console\Command;

final class ConsoleReporter
{
    public function matrix(Command $command, array $matrix): void
    {
        $renderer = new MarkdownRenderer;
        $rows = [];

        foreach ($matrix['routes'] as $id => $route) {
            $rows[] = [$id, $route['verdict'], ...array_map(fn (string $persona) => $renderer->cell($route['access'][$persona] ?? 'unverified'), $matrix['personas'])];
        }

        $command->table(['Route', 'Static', ...$matrix['personas']], $rows);
        if (($matrix['surfaces'] ?? []) !== []) {
            $rows = [];
            foreach ($matrix['surfaces'] as $id => $surface) {
                $rows[] = [$id, 'static only', $surface['verdict'], implode(', ', $surface['guards']['checks'])];
            }
            $command->table(['Surface', 'Evidence', 'Static verdict', 'Checks'], $rows);
        }

        $this->summary($command, $matrix);

        foreach (app(Findings::class)->forMatrix($matrix) as $finding) {
            $command->line('['.$finding['severity'].'] '.$finding['route'].': '.$finding['message']);
        }
    }

    public function summary(Command $command, array $matrix): void
    {
        $summary = app(VerificationSummary::class)->forMatrix($matrix);
        $command->line($summary['scenarios'].' scenarios, '.$summary['responses'].' HTTP responses.');
        foreach ($summary['incomplete'] as $category => $group) {
            $command->line('['.$category.'] '.$group['count'].' incomplete: '.$group['hint']);
        }
        foreach ($matrix['collectors'] ?? [] as $kind => $collector) {
            if ($collector['status'] === 'error') {
                $command->line('['.$kind.'] '.implode(' ', $collector['diagnostics']));
            }
        }
    }
}
