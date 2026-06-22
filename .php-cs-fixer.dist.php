<?php
//v1
declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__ . '/src');

$date = (new \DateTime())->format("Y");
$header = <<<EOF
@copyright #year# Crehler Sp. z o.o.
@link https://crehler.com/
@license proprietary
support@crehler.com
EOF;

$header = str_replace('#year#', $date, $header);

$config = new Config();

return $config->setRules([
    '@PSR12' => true,
    '@Symfony' => true,
    'header_comment' => ['header' => $header, 'separate' => 'both', 'comment_type' => 'PHPDoc', 'location' => 'after_declare_strict'],
    'native_function_invocation' => ['include' => ['@internal']],
    'no_useless_else' => true,
    'no_useless_return' => true,
    'ordered_class_elements' => true,
    'phpdoc_order' => true,
    'phpdoc_summary' => false,
    'concat_space' => ['spacing' => 'one'],
    'array_syntax' => ['syntax' => 'short'],
    'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    'global_namespace_import' => ['import_classes' => true, 'import_constants' => true, 'import_functions' => true],
    'no_alias_functions' => true,
    'group_import' => true,
    'single_import_per_statement' => false,
    'protected_to_private' => true,
    'declare_strict_types' => true,
    'single_blank_line_at_eof' => true,
    'blank_line_after_opening_tag' => true,
    'no_closing_tag' => true,
    'full_opening_tag' => true,
])
    ->setFinder($finder);