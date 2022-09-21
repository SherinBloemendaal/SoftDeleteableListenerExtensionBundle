<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\Exception;

class OnSoftDeleteUnknownTypeException extends \Exception
{
    public function __construct($type)
    {
        parent::__construct('Unexpected type. Given type: '.$type.' for does not exist.');
    }
}
