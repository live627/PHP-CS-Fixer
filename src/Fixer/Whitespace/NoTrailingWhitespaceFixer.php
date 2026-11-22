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
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Preg;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Fixer for rules defined in PSR2 ¶2.3.
 *
 * Don't add trailing spaces at the end of non-blank lines.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Dariusz Rumiński <dariusz.ruminski@gmail.com>
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class NoTrailingWhitespaceFixer extends AbstractFixer
{
    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'There must be no trailing whitespace at the end of non-blank lines.',
            [new CodeSample("<?php\n\$a = 1;     \n")],
        );
    }

    /**
     * {@inheritdoc}
     *
     * Must run after CombineConsecutiveIssetsFixer, CombineConsecutiveUnsetsFixer, EmptyLoopBodyFixer, EmptyLoopConditionFixer, FunctionToConstantFixer, ModernizeStrposFixer, NoEmptyCommentFixer, NoEmptyPhpdocFixer, NoEmptyStatementFixer, NoUnneededControlParenthesesFixer, NoUselessElseFixer, StringLengthToEmptyFixer, TernaryToElvisOperatorFixer.
     */
    public function getPriority(): int
    {
        return 0;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return true;
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        for ($index = \count($tokens) - 1; $index >= 0; --$index) {
            $token = $tokens[$index];

            if (
                $token->isGivenKind(\T_OPEN_TAG)
                && $tokens->offsetExists($index + 1)
                && $tokens[$index + 1]->isWhitespace()
                && Preg::match('/(.*)\h$/', $token->getContent(), $openTagMatches)
                && Preg::match('/^(\R)(.*)$/s', $tokens[$index + 1]->getContent(), $whitespaceMatches)
            ) {
                $tokens[$index] = new Token([\T_OPEN_TAG, $openTagMatches[1].$whitespaceMatches[1]]);
                $tokens->ensureWhitespaceAtIndex($index + 1, 0, $whitespaceMatches[2]);

                continue;
            }

            if (!$token->isWhitespace()) {
                continue;
            }

            $content = $token->getContent();

            // Skip single-line whitespace unless it is at the end of the file.
            if (
                $tokens->offsetExists($index + 1)
                && false === strpbrk($content, "\r\n")
            ) {
                continue;
            }

            $lines = Preg::split('/(\R+)/', $content, -1, \PREG_SPLIT_DELIM_CAPTURE);
            $linesSize = \count($lines);

            if (!$tokens[$index - 1]->isGivenKind(\T_OPEN_TAG) || !str_ends_with($tokens[$index - 1]->getContent(), "\n")) {
                \assert(isset($lines[0]));
                $lines[0] = rtrim($lines[0], " \t");
            }

            for ($i = 1; $i < $linesSize; ++$i) {
                \assert(isset($lines[$i]));

                $trimmedLine = rtrim($lines[$i], " \t");

                if ('' !== $trimmedLine) {
                    $lines[$i] = $trimmedLine;
                }
            }

            $newContent = implode('', $lines);

            if ($content === $newContent) {
                continue;
            }

            if ('' !== $newContent) {
                $tokens[$index] = new Token([$token->getId(), $newContent]);
            } else {
                $tokens->clearAt($index);
            }
        }
    }
}
