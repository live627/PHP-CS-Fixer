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

namespace PhpCsFixer\Fixer\Basic;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\Fixer\WhitespacesAwareFixerInterface;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Ensures that the file-level docblock appears before declare statements.
 *
 * @author John Rayes <live627@gmail.com>
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class FileHeaderOrderFixer extends AbstractFixer implements WhitespacesAwareFixerInterface
{
	public function getDefinition(): FixerDefinition
	{
		return new FixerDefinition(
			'Ensures that the file-level docblock appears before declare statements.',
			[
				new CodeSample(
					'<?php
declare(strict_types=1);

/**
 * Bootstrap for the application.
 */

namespace App;
',
				),
			],
			'PSR-12 requires the file-level docblock to precede declare statements.',
		);
	}

	public function isCandidate(Tokens $tokens): bool
	{
		return $tokens->isTokenKindFound(T_DECLARE)
			&& $tokens->isTokenKindFound(T_DOC_COMMENT);
	}

	public function getName(): string
	{
		return 'Live627/file_header_order';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Must run after DeclareStrictTypesFixer, HeaderCommentFixer.
	 */
	public function getPriority(): int
	{
		return -40;
	}

	protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
	{
		$first = $tokens->getNextNonWhitespace(0);

		if ($first === null || !$tokens[$first]->isGivenKind(T_DECLARE)) {
			return;
		}

		// Walk the run of consecutive declare statements. $last ends up as the
		// index of the final ';', and $index as the first token after the run.
		$index = $first;
		$last = null;

		while ($index !== null && $tokens[$index]->isGivenKind(T_DECLARE)) {
			$open = $tokens->getNextMeaningfulToken($index);

			if ($open === null || !$tokens[$open]->equals('(')) {
				return;
			}

			$close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_PARENTHESIS_BRACE, $open);
			$semicolon = $tokens->getNextMeaningfulToken($close);

			if ($semicolon === null || !$tokens[$semicolon]->equals(';')) {
				return;
			}

			$last = $semicolon;
			$index = $tokens->getNextNonWhitespace($semicolon);
		}

		// The token right after the declares must be the file docblock. Anything
		// else (class docblock, code, namespace, a plain comment) means no fix.
		if ($index === null || !$tokens[$index]->isGivenKind(T_DOC_COMMENT)) {
			return;
		}

		$docblockIndex = $index;
		$lineEnding = $this->whitespacesConfig->getLineEnding();

		// Whitespace between the last declare and the docblock, if any. Reusing it as
		// the docblock/declare separator avoids inventing a new token.
		$gap = $last + 1;
		$gapToken = $gap < $docblockIndex ? $tokens[$gap] : null;

		// Keep the gap if it already holds a blank line. Otherwise insert one.
		$separator = $gapToken !== null && substr_count($gapToken->getContent(), "\n") >= 2
			? $gapToken
			: new Token([T_WHITESPACE, $lineEnding . $lineEnding]);

		$newTokens = [$tokens[$docblockIndex], $separator];

		for ($i = $first; $i <= $last; ++$i) {
			$newTokens[] = $tokens[$i];
		}

		$beforeFirst = $tokens[$first - 1];

		if ($beforeFirst->isWhitespace()) {
			// Another fixer (e.g. header_comment) may have left extra blank lines
			// behind where the docblock used to be. The open tag already ends in a
			// newline, so a single line ending here yields exactly one blank line.
			if (substr_count($beforeFirst->getContent(), "\n") > 1) {
				$tokens[$first - 1] = new Token([T_WHITESPACE, $lineEnding]);
			}
		} else {
			// Nothing separates the open tag from the declare. If the open tag
			// already ends with a newline, one more line ending makes the blank
			// line. Otherwise (e.g. "<?php ") two are needed.
			$newTokens = [
				new Token([
					T_WHITESPACE,
					str_contains($beforeFirst->getContent(), "\n")
						? $lineEnding
						: $lineEnding . $lineEnding,
				]),
				...$newTokens,
			];
		}

		$tokens->overrideRange($first, $docblockIndex, $newTokens);
	}
}
