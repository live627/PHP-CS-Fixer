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

namespace PhpCsFixer\Fixer\Whitespace;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Fixer for rules defined in PSR2 ¶2.2.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class LineEndingFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
    public function isCandidate(Tokens $tokens): bool
    {
        return true;
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'All PHP files must use same line ending.',
            [
                new CodeSample(
                    "<?php \$b = \" \$a \r\n 123\"; \$a = <<<TEST\r\nAAAAA \r\n |\r\nTEST;\n",
                ),
            ],
        );
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $ending = $this->whitespacesConfig->getLineEnding();

        $lineEndings = [
            "\r\n" => $ending,
            "\r" => $ending,
            "\n" => $ending,
        ];

        for ($index = 0, $count = \count($tokens); $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token->isGivenKind(\T_ENCAPSED_AND_WHITESPACE)) {
                $nextIndex = $tokens->getNextMeaningfulToken($index);

                if (null === $nextIndex || !$tokens[$nextIndex]->isGivenKind(\T_END_HEREDOC)) {
                    continue;
                }
            } elseif (!$token->isGivenKind([\T_CLOSE_TAG, \T_COMMENT, \T_DOC_COMMENT, \T_OPEN_TAG, \T_START_HEREDOC, \T_WHITESPACE])) {
                continue;
            }

            $content = $token->getContent();
            $newContent = strtr($content, $lineEndings);

            if ($content !== $newContent) {
                $tokens[$index] = new Token([
                    $token->getId(),
                    $newContent,
                ]);
            }
        }
    }
}
