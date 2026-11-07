<?php

declare(strict_types=1);

/*
 * This file is part of PHP CS Fixer.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *     Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * A diagnostic fixer that benchmarks alternative newline checks on whitespace tokens.
 *
 * @internal
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class NewlineCheckBenchmarkFixer extends AbstractFixer
{
    /**
     * @var array<string, array{calls: int, matches: int, nanoseconds: int}>
     */
    private static array $results = [
        'Preg::match(\\R)' => ['calls' => 0, 'matches' => 0, 'nanoseconds' => 0],
        'strpbrk(CR/LF)' => ['calls' => 0, 'matches' => 0, 'nanoseconds' => 0],
        'str_contains(CR/LF)' => ['calls' => 0, 'matches' => 0, 'nanoseconds' => 0],
        'strpos(CR/LF)' => ['calls' => 0, 'matches' => 0, 'nanoseconds' => 0],
        'strcspn(CR/LF)' => ['calls' => 0, 'matches' => 0, 'nanoseconds' => 0],
    ];

    private static bool $shutdownRegistered = false;

    private static bool $warmedUp = false;

    private static int $fileCount = 0;

    public function getName(): string
    {
        return 'Live627/' . parent::getName();
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Benchmarks alternative newline checks on whitespace tokens without modifying code.',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php
                        function example()
                        {
                            return true;
                        }
                        PHP,
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_WHITESPACE);
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'printResults']);
        }

        ++self::$fileCount;

        if (!self::$warmedUp) {
            Preg::match('/\\R/', ' ');
            self::$warmedUp = true;
        }

        $this->benchmarkPreg($tokens);
        $this->benchmarkStrpbrk($tokens);

        if (\function_exists('str_contains')) {
            $this->benchmarkStrContains($tokens);
        }

        $this->benchmarkStrpos($tokens);
        $this->benchmarkStrcspn($tokens);
    }

    /**
     * Prints aggregate measurements for the current PHP-CS-Fixer process.
     */
    public static function printResults(): void
    {
        if (!self::$shutdownRegistered) {
            return;
        }

        fwrite(\STDERR, PHP_EOL.'Newline check benchmark ('.self::$fileCount.' processed files)'.PHP_EOL);
        fwrite(\STDERR, str_repeat('-', 88).PHP_EOL);
        fwrite(\STDERR, str_pad('Check', 25).'  '.str_pad('Calls', 12, ' ', STR_PAD_LEFT).'  '.str_pad('Matches', 12, ' ', STR_PAD_LEFT).'  '.str_pad('Total ms', 12, ' ', STR_PAD_LEFT).'  ns/call'.PHP_EOL);

        foreach (self::$results as $name => $result) {
            $nanosecondsPerCall = 0 === $result['calls']
                ? 0
                : $result['nanoseconds'] / $result['calls'];

            fwrite(
                \STDERR,
                str_pad($name, 25)
                .'  '.str_pad((string) $result['calls'], 12, ' ', STR_PAD_LEFT)
                .'  '.str_pad((string) $result['matches'], 12, ' ', STR_PAD_LEFT)
                .'  '.str_pad(number_format($result['nanoseconds'] / 1_000_000, 3), 12, ' ', STR_PAD_LEFT)
                .'  '.number_format($nanosecondsPerCall, 1)
                .PHP_EOL,
            );
        }

        fwrite(\STDERR, str_repeat('-', 88).PHP_EOL);
        fwrite(\STDERR, 'Match counts vs Preg::match(\\R): ');
        $referenceMatches = self::$results['Preg::match(\\R)']['matches'];
        $sameMatchCounts = true;

        foreach (self::$results as $name => $result) {
            if ($result['matches'] !== $referenceMatches) {
                $sameMatchCounts = false;
                fwrite(\STDERR, $name.' differs; ');
            }
        }

        fwrite(\STDERR, $sameMatchCounts ? 'all aggregate counts agree (not a per-token equivalence proof)'.PHP_EOL : PHP_EOL);
        fwrite(\STDERR, 'Each check is timed in a separate pass over the same token stream; traversal overhead is included.'.PHP_EOL);
    }

    private function benchmarkPreg(Tokens $tokens): void
    {
        $start = hrtime(true);
        $calls = 0;
        $matches = 0;

        foreach ($tokens as $token) {
            if (!$token->isWhitespace()) {
                continue;
            }

            ++$calls;
            if (Preg::match('/\R/', $token->getContent())) {
                ++$matches;
            }
        }

        self::record('Preg::match(\\R)', $calls, $matches, hrtime(true) - $start);
    }

    private function benchmarkStrpbrk(Tokens $tokens): void
    {
        $start = hrtime(true);
        $calls = 0;
        $matches = 0;

        foreach ($tokens as $token) {
            if (!$token->isWhitespace()) {
                continue;
            }

            ++$calls;
            if (false !== strpbrk($token->getContent(), "\r\n")) {
                ++$matches;
            }
        }

        self::record('strpbrk(CR/LF)', $calls, $matches, hrtime(true) - $start);
    }

    private function benchmarkStrContains(Tokens $tokens): void
    {
        $start = hrtime(true);
        $calls = 0;
        $matches = 0;

        foreach ($tokens as $token) {
            if (!$token->isWhitespace()) {
                continue;
            }

            ++$calls;
            $content = $token->getContent();
            if (str_contains($content, "\n") || str_contains($content, "\r")) {
                ++$matches;
            }
        }

        self::record('str_contains(CR/LF)', $calls, $matches, hrtime(true) - $start);
    }

    private function benchmarkStrpos(Tokens $tokens): void
    {
        $start = hrtime(true);
        $calls = 0;
        $matches = 0;

        foreach ($tokens as $token) {
            if (!$token->isWhitespace()) {
                continue;
            }

            ++$calls;
            $content = $token->getContent();
            if (false !== strpos($content, "\n") || false !== strpos($content, "\r")) {
                ++$matches;
            }
        }

        self::record('strpos(CR/LF)', $calls, $matches, hrtime(true) - $start);
    }

    private function benchmarkStrcspn(Tokens $tokens): void
    {
        $start = hrtime(true);
        $calls = 0;
        $matches = 0;

        foreach ($tokens as $token) {
            if (!$token->isWhitespace()) {
                continue;
            }

            ++$calls;
            $content = $token->getContent();
            if (strcspn($content, "\r\n") < \strlen($content)) {
                ++$matches;
            }
        }

        self::record('strcspn(CR/LF)', $calls, $matches, hrtime(true) - $start);
    }

    private static function record(string $name, int $calls, int $matches, int $nanoseconds): void
    {
        self::$results[$name]['calls'] += $calls;
        self::$results[$name]['matches'] += $matches;
        self::$results[$name]['nanoseconds'] += $nanoseconds;
    }
}

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Fixer\Internal\ConfigurableFixerTemplateFixer;
use PhpCsFixer\RuleSet\Sets\Internal\InternalRiskySet;

if (
    filter_var(getenv('PHP_CS_FIXER_TESTS_SYSTEM_UNDER_TEST'), \FILTER_VALIDATE_BOOL)
    && !filter_var(getenv('PHP_CS_FIXER_TESTS_ALLOW_ONE_TIME_SELF_CONFIG_USAGE'), \FILTER_VALIDATE_BOOL)
) {
    throw new Error(sprintf('This configuration file ("%s") is not meant to be used in tests.', __FILE__));
}

$fileHeaderParts = [
    <<<'EOF'
        This file is part of PHP CS Fixer.

        (c) Fabien Potencier <fabien@symfony.com>
            Dariusz Rumiński <dariusz.ruminski@gmail.com>

        EOF,
    <<<'EOF'

        This source file is subject to the MIT license that is bundled
        with this source code in the file LICENSE.
        EOF,
];

return (new Config())
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(true)
    ->registerCustomRuleSets([
        new InternalRiskySet(), // available only on repo level, not exposed to external installations or phar build
    ])
    ->registerCustomFixers([
        new ConfigurableFixerTemplateFixer(), // @TODO shall be registered while registering the Set with it
        new NewlineCheckBenchmarkFixer(),
    ])
    ->setRules([
        'Live627/newline_check_benchmark' => true,
    ])
    ->setFinder(
        (new Finder())
            ->in(__DIR__)
            ->append([__DIR__.'/php-cs-fixer'])
            ->exclude(['dev-tools/phpstan', 'tests/Fixtures'])
            ->ignoreDotFiles(false), // @TODO v4 line no longer needed
    )
;
