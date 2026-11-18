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

// ~ namespace PhpCsFixer\Fixer\Internal;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Updates calls to equalsAny() to use isGivenKindV4().
 *
 * Also converts single-element token prototypes into their corresponding
 * token kinds.
 *
 * @internal
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class EqualsAnyToIsGivenKindV4Fixer extends AbstractFixer
{
    public function getName(): string
    {
        return 'Live627/equals_any_to_is_given_kind_v4';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Replaces calls to equalsAny() with isGivenKindV4().',
            [
                new CodeSample(
                    <<<'PHP'
                        <?php
                        $token->equalsAny([
                            '(',
                            ')',
                            [T_STRING],
                            [CT::T_CUSTOM_TOKEN],
                        ]);
                        PHP,
                ),
            ],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_STRING);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        $changed = false;

        foreach ($tokens as $index => $token) {
            if (!$token->isGivenKind(\T_STRING) || 'equalsany' !== strtolower($token->getContent())) {
                continue;
            }

            $operatorIndex = $tokens->getPrevMeaningfulToken($index);

            if (
                null === $operatorIndex
                || (!$tokens[$operatorIndex]->getContent('->') && !$tokens[$operatorIndex]->getContent('?->'))
            ) {
                continue;
            }

            $callOpenIndex = $tokens->getNextMeaningfulToken($index);

            if (null === $callOpenIndex || !$tokens[$callOpenIndex]->equals('(')) {
                continue;
            }

            $callCloseIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $callOpenIndex);
            $arrayStartIndex = $tokens->getNextMeaningfulToken($callOpenIndex);

            if (null === $arrayStartIndex) {
                continue;
            }

            $array = $this->getArrayLiteral($tokens, $arrayStartIndex);

            if (
                null === $array
                || $array['close'] !== $tokens->getPrevMeaningfulToken($callCloseIndex)
            ) {
                // Only support a single array argument.
                continue;
            }

            $items = $this->getArrayItems($tokens, $array['open'], $array['close']);

            if (null === $items) {
                continue;
            }

            $wrappers = [];
            $valid = true;

            foreach ($items as [$itemStart, $itemEnd]) {
                $itemArray = $this->getArrayLiteral($tokens, $itemStart);

                if (null === $itemArray) {
                    if (!$this->isKindExpression($tokens, $itemStart, $itemEnd)) {
                        $valid = false;

                        break;
                    }

                    continue;
                }

                // A nested array represents a legacy token prototype.
                if ($itemArray['close'] !== $itemEnd) {
                    $valid = false;

                    break;
                }

                $prototypeItems = $this->getArrayItems(
                    $tokens,
                    $itemArray['open'],
                    $itemArray['close'],
                );

                if (
                    null === $prototypeItems
                    || 1 !== count($prototypeItems)
                ) {
                    // Full token prototypes cannot be represented by a token kind.
                    $valid = false;

                    break;
                }

                [$kindStart, $kindEnd] = $prototypeItems[0];

                if (!$this->isKindExpression($tokens, $kindStart, $kindEnd, true)) {
                    $valid = false;

                    break;
                }

                $wrappers[] = $itemArray;
            }

            if (!$valid) {
                continue;
            }

            // Rename the method.
            $tokens[$index] = new Token([\T_STRING, 'isGivenKindV4']);

            // Remove the nested prototype array delimiters.
            foreach ($wrappers as $wrapper) {
                foreach ($wrapper['prefix'] as $prefixIndex) {
                    $tokens->clearAt($prefixIndex);
                }

                $tokens->clearAt($wrapper['open']);
                $tokens->clearAt($wrapper['close']);
            }

            $changed = true;
        }

        if ($changed) {
            $tokens->clearEmptyTokens();
        }
    }

    /**
     * Get the boundaries of an array literal.
     *
     * Supports both [] and array() syntax.
     *
     * @return null|array{
     *     open: int,
     *     close: int,
     *     prefix: list<int>,
     * }
     */
    private function getArrayLiteral(Tokens $tokens, int $index): ?array
    {
        $block = Tokens::detectBlockType($tokens[$index]);

        if (
            null !== $block
            && $block['isStart']
            && Tokens::BLOCK_TYPE_ARRAY_BRACKET === $block['type']
        ) {
            return [
                'open' => $index,
                'close' => $tokens->findBlockEnd($block['type'], $index),
                'prefix' => [],
            ];
        }

        if (!$tokens[$index]->isGivenKind(\T_ARRAY)) {
            return null;
        }

        $openIndex = $tokens->getNextMeaningfulToken($index);

        if (null === $openIndex || !$tokens[$openIndex]->equals('(')) {
            return null;
        }

        return [
            'open' => $openIndex,
            'close' => $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $openIndex),
            'prefix' => [$index],
        ];
    }

    /**
     * Split an array's contents into its top-level elements.
     *
     * Nested blocks are skipped so commas inside expressions do not
     * incorrectly split elements.
     *
     * @return null|list<array{int, int}>
     */
    private function getArrayItems(Tokens $tokens, int $openIndex, int $closeIndex): ?array
    {
        $items = [];
        $itemStart = $tokens->getNextMeaningfulToken($openIndex);

        if ($itemStart === $closeIndex) {
            return [];
        }

        if (null === $itemStart) {
            return null;
        }

        for ($index = $itemStart; $index < $closeIndex; ++$index) {
            $block = Tokens::detectBlockType($tokens[$index]);

            if (null !== $block && $block['isStart']) {
                $index = $tokens->findBlockEnd($block['type'], $index);

                continue;
            }

            if (!$tokens[$index]->equals(',')) {
                continue;
            }

            $itemEnd = $tokens->getPrevMeaningfulToken($index);

            if (
                null === $itemEnd
                || $itemEnd < $itemStart
                || $tokens[$itemEnd]->equals(',')
            ) {
                return null;
            }

            $items[] = [$itemStart, $itemEnd];

            $itemStart = $tokens->getNextMeaningfulToken($index);

            if ($itemStart === $closeIndex) {
                // Trailing comma.
                return $items;
            }

            if (null === $itemStart || $tokens[$itemStart]->equals(',')) {
                return null;
            }
        }

        $itemEnd = $tokens->getPrevMeaningfulToken($closeIndex);

        if (null === $itemEnd || $itemEnd < $itemStart) {
            return null;
        }

        $items[] = [$itemStart, $itemEnd];

        return $items;
    }

    /**
     * Check whether an expression can represent a token kind.
     *
     * @param bool $tokenId require an integer token ID expression
     */
    private function isKindExpression(
        Tokens $tokens,
        int $start,
        int $end,
        bool $tokenId = false,
    ): bool {
        $allowedKinds = [
            \T_STRING,
            \T_NAME_QUALIFIED,
            \T_NAME_FULLY_QUALIFIED,
            \T_NAME_RELATIVE,
            \T_NS_SEPARATOR,
            \T_DOUBLE_COLON,
            \T_LNUMBER,
        ];

        if (!$tokenId) {
            $allowedKinds[] = \T_CONSTANT_ENCAPSED_STRING;
        }

        $foundExpression = false;

        for ($index = $start; $index <= $end; ++$index) {
            $token = $tokens[$index];

            if ($token->isWhitespace() || $token->isGivenKind([\T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            if (!$token->isGivenKind($allowedKinds)) {
                return false;
            }

            $foundExpression = true;
        }

        return $foundExpression;
    }
}

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
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
        new EqualsAnyToIsGivenKindV4Fixer(), // @TODO shall be registered while registering the Set with it
    ])
    ->setRules([
        'Live627/equals_any_to_is_given_kind_v4' => true,
    ])
    ->setFinder(
        (new Finder())
            ->in(__DIR__.'/src')
            ->append([__DIR__.'/php-cs-fixer'])
            ->exclude(['dev-tools/phpstan', 'tests/Fixtures'])
            ->ignoreDotFiles(false), // @TODO v4 line no longer needed
    )
;
