<?php

namespace Proteo\DrupalBackend\Descriptor;

final readonly class RoutesConfig
{
    /**
     * @param  list<string>  $middleware
     */
    public function __construct(
        public bool $auto = true,
        public string $prefix = '',
        public array $middleware = ['web'],
        public string $aliasPattern = '[a-z0-9-]{1,128}',
    ) {}
}
