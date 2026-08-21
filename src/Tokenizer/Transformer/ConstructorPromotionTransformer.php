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
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Transforms for Constructor Property Promotion.
 *
 * Transform T_PUBLIC, T_PROTECTED and T_PRIVATE of Constructor Property Promotion into custom tokens.
 *
 * @internal
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class ConstructorPromotionTransformer extends AbstractTransformer
{
    public function getRequiredPhpVersionId(): int
    {
        return 8_00_00;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(\T_FUNCTION);
    }

    public function process(Tokens $tokens): void
    {
        $count = $tokens->count();

        for ($index = 0; $index < $count; ++$index) {
            if (!$tokens[$index]->isGivenKind(\T_FUNCTION)) {
                continue;
            }

            $functionNameIndex = $tokens->getNextMeaningfulToken($index);

            if (!$tokens[$functionNameIndex]->isGivenKind(\T_STRING) || '__construct' !== strtolower($tokens[$functionNameIndex]->getContent())) {
                continue;
            }

            /** @var int $openParenthesisIndex */
            $openParenthesisIndex = $tokens->getNextMeaningfulToken($functionNameIndex); // we are @ '(' now
            $closeParenthesisIndex = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS, $openParenthesisIndex);

            for ($argsIndex = $openParenthesisIndex + 1; $argsIndex < $closeParenthesisIndex; ++$argsIndex) {
                $token = $tokens[$argsIndex];

                switch ($token->getId()) {
                    case \T_PUBLIC:
                        $newId = CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC;

                        break;

                    case \T_PROTECTED:
                        $newId = CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED;

                        break;

                    case \T_PRIVATE:
                        $newId = CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE;

                        break;

                    default:
                        continue 2;
                }

                $tokens[$argsIndex] = new Token([$newId, $token->getContent()]);
            }

            $index = $argsIndex + 1;
        }
    }

    public function getCustomTokens(): array
    {
        return [
            CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PUBLIC,
            CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PROTECTED,
            CT::T_CONSTRUCTOR_PROPERTY_PROMOTION_PRIVATE,
        ];
    }
}
