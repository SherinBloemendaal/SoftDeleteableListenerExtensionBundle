<?php

namespace Mapping\Attribute;

use Attribute;

#[Attribute]
class onSoftDelete
{
    public function __construct(public string $type)
    {
    }
}
