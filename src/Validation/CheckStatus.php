<?php

namespace Proteo\DrupalBackend\Validation;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    case Fail = 'fail';
}
