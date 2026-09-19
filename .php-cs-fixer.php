<?php

/**
 * Code style check for this plugin - the same rules PKP applies to OJS and
 * pkp-lib.
 *
 * OJS' own .php-cs-fixer.php skips every directory under plugins/ that has its
 * own .git, so a mounted plugin is never covered by the instance's fixer. This
 * file reuses PKP's ruleset (lib/pkp/.php_cs_rules) and custom fixers instead.
 * The plugin is expected at <ojs>/plugins/blocks/socialFeedBlock.
 *
 * Run from the OJS root:
 *   php lib/pkp/lib/vendor/bin/php-cs-fixer fix --dry-run --diff \
 *       --config=plugins/blocks/socialFeedBlock/.php-cs-fixer.php
 */

$ojsRoot = dirname(__DIR__, 3);

$rules = include $ojsRoot . '/lib/pkp/.php_cs_rules';
require_once $ojsRoot . '/lib/pkp/classes/dev/fixers/bootstrap.php';

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->exclude(['cypress', 'locale', 'styles', 'templates']);

return (new PhpCsFixer\Config())
    ->setRules($rules)
    ->registerCustomFixers(new PKP\dev\fixers\Fixers())
    ->setFinder($finder);
