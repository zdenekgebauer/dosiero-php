<?php

declare(strict_types=1);

use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use PhpCsFixer\Fixer\ArrayNotation\ArraySyntaxFixer;
use PhpCsFixer\Fixer\CastNotation\CastSpacesFixer;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use PhpCsFixer\Fixer\ControlStructure\TrailingCommaInMultilineFixer;
use PhpCsFixer\Fixer\FunctionNotation\FunctionDeclarationFixer;
use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Operator\ConcatSpaceFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocOrderFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocSeparationFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocToCommentFixer;
use PhpCsFixer\Fixer\PhpUnit\PhpUnitMethodCasingFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\Whitespace\BlankLineBetweenImportGroupsFixer;
use PhpCsFixer\Fixer\Whitespace\NoExtraBlankLinesFixer;
use Symplify\CodingStandard\Fixer\Spacing\StandaloneLinePromotedPropertyFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPreparedSets(psr12: true)
    ->withSpacesLevel(22)
    ->withArrayLevel(9)
    ->withControlStructuresLevel(14)
    ->withDocblockLevel(11)
    ->withPhpCsFixerSets(php83Migration: true)
    ->withRules([
        BlankLineBetweenImportGroupsFixer::class,
        DeclareStrictTypesFixer::class,
        NoUnusedImportsFixer::class,
        OrderedImportsFixer::class,
    ])
    ->withConfiguredRule(NoExtraBlankLinesFixer::class, [
        'tokens' => ['extra'],
    ])
    ->withConfiguredRule(GlobalNamespaceImportFixer::class, [
        'import_classes' => false,
        'import_constants' => false,
        'import_functions' => true,
    ])
    ->withConfiguredRule(FunctionDeclarationFixer::class, [
        'closure_fn_spacing' => 'none',
    ])
    ->withConfiguredRule(OrderedImportsFixer::class, [
        'sort_algorithm' => 'alpha',
        'imports_order' => ['class', 'function', 'const'],
    ])
    ->withConfiguredRule(LineLengthSniff::class, [
        'absoluteLineLimit' => 120,
    ])
    ->withConfiguredRule(OrderedClassElementsFixer::class, [
        'order' => [
            'use_trait',
            'case',
            'constant_public',
            'constant_protected',
            'constant_private',
            'property_public',
            'property_protected',
            'property_private',
            'construct',
            'destruct',
            'magic',
            'method_public',
            'method_protected',
            'method_private',
            'phpunit',
        ],
        'sort_algorithm' => 'alpha',
    ])
    ->withConfiguredRule(PhpUnitMethodCasingFixer::class, [
        'case' => 'camel_case',
    ])
    ->withConfiguredRule(ArraySyntaxFixer::class, [
        'syntax' => 'short',
    ])
    ->withConfiguredRule(TrailingCommaInMultilineFixer::class, [
        'elements' => ['arrays', 'arguments', 'parameters'],
    ])
    ->withConfiguredRule(ConcatSpaceFixer::class, [
        'spacing' => 'one',
    ])
    ->withConfiguredRule(CastSpacesFixer::class, [
        'space' => 'none',
    ])
    ->withConfiguredRule(PhpdocOrderFixer::class, [
        'order' => ['param', 'throws', 'return'],
    ])
    ->withConfiguredRule(PhpdocLineSpanFixer::class, [
        'property' => 'single',
        'method' => 'single',
        'const' => 'single',
    ])
    ->withConfiguredRule(PhpdocToCommentFixer::class, [
        'ignored_tags' => ['phpstan-var', 'psalm-var', 'var'],
    ])
    ->withConfiguredRule(PhpdocSeparationFixer::class, [
        'groups' => [
            ['deprecated', 'link', 'see', 'since'],
            ['author', 'copyright', 'license'],
            ['category', 'package', 'subpackage'],
            ['property', 'property-read', 'property-write'],
            ['param', 'return'],
        ],
        'skip_unlisted_annotations' => false,
    ])
    ->withSkip(
        [
            NotOperatorWithSuccessorSpaceFixer::class,
            StandaloneLinePromotedPropertyFixer::class,
            __DIR__ . '/tests/_support/_generated',
            __DIR__ . '/tests/Support/_generated',
        ]
    )
    ->withParallel()
    ->withCache(
        directory: __DIR__ . '/build/cache/ecs',
    );
