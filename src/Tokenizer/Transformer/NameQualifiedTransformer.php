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
use PhpCsFixer\Tokenizer\FCT;
use PhpCsFixer\Tokenizer\Processor\ImportProcessor;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Transform NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED and T_NAME_RELATIVE into T_NAMESPACE T_NS_SEPARATOR T_STRING.
 *
 * @internal
 *
 * @no-named-arguments Parameter names are not covered by the backward compatibility promise.
 */
final class NameQualifiedTransformer extends AbstractTransformer
{
    public function getPriority(): int
    {
        return 1; // must run before NamespaceOperatorTransformer
    }

    public function getRequiredPhpVersionId(): int
    {
        return 8_00_00;
    }

    public function getCandidateKinds(): array
    {
        return [FCT::T_NAME_QUALIFIED, FCT::T_NAME_FULLY_QUALIFIED, FCT::T_NAME_RELATIVE];
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isAnyTokenKindsFound([FCT::T_NAME_QUALIFIED, FCT::T_NAME_FULLY_QUALIFIED, FCT::T_NAME_RELATIVE]);
    }

    private array $slices = [];

public function process(Tokens $tokens, Token $token, int $index): void
{ 
    $id = $token->getId();

    if (
        FCT::T_NAME_QUALIFIED !== $id
        && FCT::T_NAME_FULLY_QUALIFIED !== $id
        && FCT::T_NAME_RELATIVE !== $id
    ) {
        return;
    }

    $content = $token->getContent();
    \assert('' !== $content);

    $newTokens = ImportProcessor::tokenizeName($content);

    if (FCT::T_NAME_RELATIVE === $id) {
        $newTokens[0] = new Token([\T_NAMESPACE, 'namespace']);
    }

    $this->slices[$index] = $newTokens;
    $tokens->clearAt($index);
    }

    public function getCustomTokens(): array
    {
        return [];
    }

    private function transformQualified(Tokens $tokens, Token $token, int $index): void
    {
        //~ \assert('' !== $token->getContent());
        //~ $newTokens = ImportProcessor::tokenizeName($token->getContent());

        //~ $this->slices[$index] = $newTokens;
        //~ $tokens->clearAt($index);
    }

    private function transformRelative(Tokens $tokens, Token $token, int $index): void
    {
        //~ \assert('' !== $token->getContent());
        //~ $newTokens = ImportProcessor::tokenizeName($token->getContent());
        //~ $newTokens[0] = new Token([\T_NAMESPACE, 'namespace']);

        //~ $this->slices[$index] = $newTokens;
        //~ $tokens->clearAt($index);
    }


    /**
     * @return array<int, list<Token>|Token|Tokens>
     */
    public function getSlices(): array
    {
        return $this->slices;
    }

    public function resetSlices(): void
    {
        $this->slices = [];
    }

private static array $profile = [
    'getId' => ['time' => 0, 'calls' => 0],
    'candidateCheck' => ['time' => 0, 'calls' => 0],
    'tokenizeName'   => ['time' => 0, 'calls' => 0],
    'relativePatch'  => ['time' => 0, 'calls' => 0],
    'storeSlice'     => ['time' => 0, 'calls' => 0],
    'isGivenKind'     => ['time' => 0, 'calls' => 0],
];

private static bool $registeredShutdown = false;

private static function registerProfiler(): void
{
    if (self::$registeredShutdown) {
        return;
    }

    self::$registeredShutdown = true;

    register_shutdown_function(static function (): void {
        foreach (self::$profile as $name => $stats) {
            if (0 === $stats['calls']) {
                continue;
            }

            $totalMs = $stats['time'] / 1_000_000;
            $avgUs = $stats['time'] / $stats['calls'] / 1_000;

            fprintf(
                STDERR,
                "%-20s calls=%8d total=%10.3f ms avg=%8.3f µs\n",
                $name,
                $stats['calls'],
                $totalMs,
                $avgUs,
            );
        }
    });
}
}
