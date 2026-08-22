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

namespace PhpCsFixer\Tokenizer\Transformer;

use PhpCsFixer\Tokenizer\AbstractTransformer;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\FCT;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Transform T_USE into:
 * - CT::T_USE_TRAIT for imports,
 * - CT::T_USE_LAMBDA for lambda variable uses.
 *
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * @internal
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class UseTransformer extends AbstractTransformer
{
    public function getPriority(): int
    {
        // Should run after CurlyBraceTransformer and before TypeColonTransformer
        return -5;
    }

    public function getRequiredPhpVersionId(): int
    {
        return 5_03_00;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_USE);
    }
    public function process(Tokens $tokens): void
    {
        $count = $tokens->count();
        $inClass = false;
        $level = 0;

        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            $id = $token->getId();

            if (!$inClass) {
                if (\T_USE === $id) {
                    if ($this->isUseForLambda($tokens, $index)) {
                        $tokens[$index] = new Token([
                            CT::T_USE_LAMBDA,
                            $token->getContent(),
                        ]);
                    }

                    continue;
                }

                if (
                    (\T_CLASS === $id
                        && !$tokens[$tokens->getPrevMeaningfulToken($index)]->isGivenKind(\T_DOUBLE_COLON))
                    || \T_TRAIT === $id
                    || FCT::T_ENUM === $id
                ) {
                    $inClass = true;
                    $level = 0;
                }

                continue;
            }

            if ($token->equals('{')) {
                ++$level;
            } elseif ($token->equals('}')) {
                if (0 === --$level) {
                    $inClass = false;
                }
            } elseif (\T_USE === $id) {
                if ($this->isUseForLambda($tokens, $index)) {
                    $tokens[$index] = new Token([
                        CT::T_USE_LAMBDA,
                        $token->getContent(),
                    ]);
                } elseif (1 === $level) {
                    $tokens[$index] = new Token([
                        CT::T_USE_TRAIT,
                        $token->getContent(),
                    ]);
                }
            }
        }
    }

    public function getCustomTokens(): array
    {
        return [CT::T_USE_TRAIT, CT::T_USE_LAMBDA];
    }

    /**
     * Check if token under given index is `use` statement for lambda function.
     */
    private function isUseForLambda(Tokens $tokens, int $index): bool
    {
        $nextToken = $tokens[$tokens->getNextMeaningfulToken($index)];

        // test `function () use ($foo) {}` case
        return $nextToken->equals('(');
    }
}
