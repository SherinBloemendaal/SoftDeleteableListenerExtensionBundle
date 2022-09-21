<?php

use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/** @var \Symfony\Component\DependencyInjection\ContainerBuilder $container */
$container->setDefinition('evence.softdeletale.listener.softdelete', new Definition('Evence\Bundle\SoftDeleteableExtensionBundle\EventListener\SoftDeleteListener'))

->addMethodCall('setContainer', array(
    new Reference('service_container'),
))
->addTag('doctrine.event_listener', array(
    'event' => 'preSoftDelete',
));
