<?php

namespace Proteo\DrupalBackend\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Proteo\DrupalBackend\Contract\ContractGenerator;

#[Signature('drupal:contract')]
#[Description('Print the checklist of what the Drupal backend must provide for the descriptor')]
class DrupalContract extends Command
{
    public function handle(ContractGenerator $generator): int
    {
        // Raw write: the output is Markdown meant to be redirected to a file.
        $this->output->write($generator->markdown());

        return self::SUCCESS;
    }
}
