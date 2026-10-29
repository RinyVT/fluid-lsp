<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(getcwd())
    ->exclude(['vendor', 'Build'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
    ])
    ->setFinder($finder)
;
