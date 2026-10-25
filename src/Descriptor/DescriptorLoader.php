<?php

namespace Proteo\DrupalBackend\Descriptor;

use Psr\Log\LoggerInterface;

/**
 * Builds the descriptor from config and enforces the offline rules: a broken
 * descriptor stops the app outside production and is logged in production, so
 * a deploy with a config slip does not take the live site down.
 */
class DescriptorLoader
{
    public function __construct(
        private readonly DescriptorFactory $factory,
        private readonly DescriptorValidator $validator,
        private readonly LoggerInterface $log,
        private readonly bool $production,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidDescriptor
     */
    public function load(array $config): Descriptor
    {
        $descriptor = $this->factory->make($config);
        $problems = $this->validator->problems($descriptor);

        if ($problems === []) {
            return $descriptor;
        }

        if (! $this->production) {
            throw InvalidDescriptor::withProblems($problems);
        }

        foreach ($problems as $problem) {
            $this->log->error('Invalid drupal-backend descriptor: '.$problem);
        }

        return $descriptor;
    }
}
