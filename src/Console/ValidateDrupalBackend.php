<?php

namespace Proteo\DrupalBackend\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Proteo\DrupalBackend\Descriptor\Descriptor;
use Proteo\DrupalBackend\Validation\BackendValidator;
use Proteo\DrupalBackend\Validation\CheckResult;
use Proteo\DrupalBackend\Validation\CheckStatus;

#[Signature('drupal:validate {source? : Check only this Source} {--json : Print the results as JSON for CI}')]
#[Description('Check a live Drupal backend against the descriptor')]
class ValidateDrupalBackend extends Command
{
    public function handle(BackendValidator $validator, Descriptor $descriptor): int
    {
        $only = $this->argument('source');

        // An unknown name would otherwise check nothing and report success.
        if ($only !== null && ! isset($descriptor->sources[$only])) {
            $this->components->error(sprintf('Unknown source [%s]. Declared sources: %s.', $only, implode(', ', array_keys($descriptor->sources))));

            return self::FAILURE;
        }

        $results = $validator->validate($only);
        $failed = array_any($results, fn (CheckResult $result): bool => $result->status === CheckStatus::Fail);

        if ($this->option('json')) {
            $this->output->writeln(json_encode(['ok' => ! $failed, 'results' => array_map(fn (CheckResult $r): array => $r->toArray(), $results)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result->scope][] = $result;
        }

        foreach ($grouped as $scope => $group) {
            $this->components->twoColumnDetail("<options=bold>$scope</>");

            foreach ($group as $result) {
                $tag = match ($result->status) {
                    CheckStatus::Pass => '<fg=green>PASS</>',
                    CheckStatus::Warn => '<fg=yellow>WARN</>',
                    CheckStatus::Fail => '<fg=red>FAIL</>',
                };
                $this->components->twoColumnDetail("$result->check: expected $result->expected, found $result->found", $tag);

                if ($result->status !== CheckStatus::Pass && $result->hint !== null) {
                    $this->line("    Fix: $result->hint");
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
