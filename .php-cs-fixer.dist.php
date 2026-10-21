<?php

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app/api'])
    ->notName(['config.php'])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'no_extra_blank_lines' => true,
        'whitespace_after_comma_in_array' => true,
        'blank_line_before_statement' => ['statements' => ['return']],
    ])
    ->setFinder($finder);
