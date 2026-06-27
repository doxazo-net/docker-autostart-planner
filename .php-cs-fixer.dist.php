<?php

declare(strict_types=1);

// Style/auto-format config. Covers the core engine (src/), tests, and - once
// Plan B lands - the Unraid plugin PHP under plugin/ (including *.page files).
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/src', __DIR__ . '/tests'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
