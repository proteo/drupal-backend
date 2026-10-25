<?php

namespace Proteo\DrupalBackend\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Proteo\DrupalBackend\Cache\DrupalManager;

#[Signature('drupal:flush {source? : Flush only this Source (and the ones it also flushes)}')]
#[Description('Discard cached Drupal content so the next requests fetch fresh copies')]
class FlushDrupalCache extends Command
{
    public function handle(DrupalManager $drupal): int
    {
        try {
            $flushed = $this->argument('source') === null
                ? $drupal->flushAll()
                : $drupal->flush((string) $this->argument('source'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Drupal content cache flushed ['.implode(', ', $flushed).'].');

        return self::SUCCESS;
    }
}
