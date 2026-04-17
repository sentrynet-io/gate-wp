<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/src'])
    ->append([__DIR__ . '/gate-wp.php']);

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12'                    => true,
        'strict_param'              => true,
        'array_syntax'              => ['syntax' => 'short'],
        'ordered_imports'           => ['sort_algorithm' => 'alpha'],
        'no_unused_imports'         => true,
        'declare_strict_types'      => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true);
