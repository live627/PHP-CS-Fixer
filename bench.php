<?php

declare(strict_types=1);

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\Token;
use PhpCsFixer\Tokenizer\Tokens;

/**
 * Internal micro-benchmark for the Token API.
 *
 * Each benchmark is timed as a whole loop over all tokens of a file (not per call),
 * so harness overhead is amortised and can be removed by subtracting `baseline`.
 * Run with `--sequential --using-cache=no`; the report is printed to STDERR at shutdown.
 *
 * The `set3-*`, `set6-*` and `set12-*` entries compare equalsAny() on a set of single characters
 * with alternatives (in_array, isset on a flipped map, strpos looped over the set, strpbrk, a
 * hand-written `===` chain and a switch). All of them require a null id first, because only non-array tokens can be a single
 * character; `set6-isset-map-unguarded` shows the cost of that guard and is not a safe replacement.
 *
 *     php php-cs-fixer fix src/Tokenizer/Tokens.php --config=bench.php --dry-run --sequential --using-cache=no
 *
 * @internal
 */
final class TokenApiBenchmarkFixer extends AbstractFixer
{
    private const WARMUP_PASSES = 1;
    private const TIMED_PASSES = 5;

    private const KIND_RETURN = [\T_RETURN];
    private const KIND_FUNCTION = [\T_FUNCTION];
    private const KIND_VARIABLE = [\T_VARIABLE];
    private const STRING_TRUE = [\T_STRING, 'true'];
    private const STRING_FALSE = [\T_STRING, 'false'];

    /** @var null|array<string, \Closure(Token): mixed> */
    private static ?array $benchmarks = null;

    /** @var array<string, list<float>> ns per op, one entry per timed pass */
    private static array $samples = [];

    /** Keeps benchmark results observable so they cannot be optimised away. */
    private static int $sink = 0;

    private static bool $reportRegistered = false;

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Internal benchmark.',
            [new CodeSample("<?php echo \$foo + bar(\$baz);\n")],
        );
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'Custom/token_api_benchmark';
    }

    protected function applyFix(\SplFileInfo $file, Tokens $tokens): void
    {
        $list = $tokens->toArray();
        $count = \count($list);

        if (0 === $count) {
            return;
        }

        self::registerReport();

        foreach (self::benchmarks() as $name => $op) {
            for ($i = 0; $i < self::WARMUP_PASSES; ++$i) {
                self::$sink ^= self::pass($op, $list);
            }

            for ($i = 0; $i < self::TIMED_PASSES; ++$i) {
                $start = hrtime(true);
                $result = self::pass($op, $list);
                $elapsed = hrtime(true) - $start;

                self::$sink ^= $result;
                self::$samples[$name][] = $elapsed / $count;
            }
        }
    }

    /**
     * @param list<Token> $list
     */
    private static function pass(\Closure $op, array $list): int
    {
        $sink = 0;

        foreach ($list as $token) {
            $sink ^= (int) $op($token);
        }

        return $sink;
    }

    /**
     * @return array<string, \Closure(Token): mixed>
     */
    private static function benchmarks(): array
    {
        if (null !== self::$benchmarks) {
            return self::$benchmarks;
        }

        $semi = new Token(';');
        $return = new Token([\T_RETURN, 'return']);
        $true = new Token([\T_STRING, 'true']);

        $charsFwd = [';', ',', '(', ')', '{', '}'];
        $charsRev = array_reverse($charsFwd);

        $kinds = [self::KIND_RETURN, self::KIND_FUNCTION, self::KIND_VARIABLE];

        $mixedFwd = [';', self::KIND_RETURN, self::KIND_FUNCTION, self::STRING_TRUE, self::STRING_FALSE, ')'];
        $mixedRev = array_reverse($mixedFwd);

        $kindIds = [\T_STRING, \T_VARIABLE, \T_FUNCTION, \T_RETURN, \T_IF, \T_ELSE];

        // single-character sets of three sizes; the 6-entry set is the one used by `equalsAny-char-*`
        $set3 = [';', ',', ')'];
        $set12 = [';', ',', '(', ')', '{', '}', '[', ']', '=', '.', '?', ':'];
        $map6 = array_flip($charsFwd);

        return self::$benchmarks = [
            'baseline' => static fn (Token $t): int => 0,

            // raw accessors
            'getId' => static fn (Token $t): int => (int) $t->getId(),
            'getName' => static fn (Token $t): int => null === $t->getName() ? 0 : 1,
            'getContent' => static fn (Token $t): int => \strlen($t->getContent()),

            // id comparisons: cached vs uncached (worst case = miss, all branches evaluated)
            'id-chain-cached' => static function (Token $t): bool {
                $id = $t->getId();

                return \T_STRING === $id
                    || \T_VARIABLE === $id
                    || \T_FUNCTION === $id
                    || \T_RETURN === $id
                    || \T_IF === $id
                    || \T_ELSE === $id;
            },
            'id-chain-uncached' => static fn (Token $t): bool => \T_STRING === $t->getId()
                || \T_VARIABLE === $t->getId()
                || \T_FUNCTION === $t->getId()
                || \T_RETURN === $t->getId()
                || \T_IF === $t->getId()
                || \T_ELSE === $t->getId(),

            // isGivenKind shapes
            'isGivenKind-array6' => static fn (Token $t): bool => $t->isGivenKind($kindIds),
            'isGivenKind-array1' => static fn (Token $t): bool => $t->isGivenKind([\T_RETURN]),
            'isGivenKind-scalar' => static fn (Token $t): bool => $t->isGivenKind(\T_RETURN),
            'raw-id===const' => static fn (Token $t): bool => \T_RETURN === $t->getId(),

            // equals(): single character
            'equals-char-hit' => static fn (Token $t): bool => $t->equals(';'),
            'equals-char-miss' => static fn (Token $t): bool => $t->equals('@'),
            'raw-content===char' => static fn (Token $t): bool => ';' === $t->getContent(),

            // equals(): kind prototype
            'equals-kind-return' => static fn (Token $t): bool => $t->equals(self::KIND_RETURN),
            'equals-kind-function' => static fn (Token $t): bool => $t->equals(self::KIND_FUNCTION),

            // equals(): [id, content] prototype
            'equals-string-true' => static fn (Token $t): bool => $t->equals(self::STRING_TRUE),
            'equals-string-false' => static fn (Token $t): bool => $t->equals(self::STRING_FALSE),
            'raw-id+content' => static fn (Token $t): bool => \T_STRING === $t->getId() && 'true' === $t->getContent(),

            // equals(Token)
            'equals-token-semi' => static fn (Token $t): bool => $t->equals($semi),
            'equals-token-return' => static fn (Token $t): bool => $t->equals($return),
            'equals-token-true' => static fn (Token $t): bool => $t->equals($true),

            // equalsAny(): position of the hit matters, so test both orders
            'equalsAny-char-fwd' => static fn (Token $t): bool => $t->equalsAny($charsFwd),
            'equalsAny-char-rev' => static fn (Token $t): bool => $t->equalsAny($charsRev),
            'equalsAny-kind' => static fn (Token $t): bool => $t->equalsAny($kinds),
            'equalsAny-mixed-fwd' => static fn (Token $t): bool => $t->equalsAny($mixedFwd),
            'equalsAny-mixed-rev' => static fn (Token $t): bool => $t->equalsAny($mixedRev),

            // single-character sets: alternatives to equalsAny()
            //
            // Only non-array tokens (null id) can be a single character, so every alternative first
            // requires a null id. `set6-isset-map-unguarded` drops that guard to show what it costs,
            // but it is NOT a safe replacement: an array token such as inline HTML can contain just `;`.
            // The 6-entry equalsAny() baseline is `equalsAny-char-fwd` above.
            'set3-equalsAny' => static fn (Token $t): bool => $t->equalsAny($set3),
            'set12-equalsAny' => static fn (Token $t): bool => $t->equalsAny($set12),
            'set6-isset-map-unguarded' => static fn (Token $t): bool => isset($map6[$t->getContent()]),

            // hand-written comparisons against a once-read content
            'set3-chain' => static function (Token $t): bool {
                if (null !== $t->getId()) {
                    return false;
                }

                $c = $t->getContent();

                return ';' === $c || ',' === $c || ')' === $c;
            },
            'set6-chain' => static function (Token $t): bool {
                if (null !== $t->getId()) {
                    return false;
                }

                $c = $t->getContent();

                return ';' === $c || ',' === $c || '(' === $c || ')' === $c || '{' === $c || '}' === $c;
            },
            'set12-chain' => static function (Token $t): bool {
                if (null !== $t->getId()) {
                    return false;
                }

                $c = $t->getContent();

                return ';' === $c || ',' === $c || '(' === $c || ')' === $c || '{' === $c || '}' === $c
                    || '[' === $c || ']' === $c || '=' === $c || '.' === $c || '?' === $c || ':' === $c;
            },

            // switch (loose comparison; fine here, as non-array tokens are punctuation)
            'set3-switch' => static function (Token $t): bool {
                if (null !== $t->getId()) {
                    return false;
                }

                switch ($t->getContent()) {
                    case ';':
                    case ',':
                    case ')':
                        return true;
                    default:
                        return false;
                }
            },
            'set6-switch' => static function (Token $t): bool {
                if (null !== $t->getId()) {
                    return false;
                }

                switch ($t->getContent()) {
                    case ';':
                    case ',':
                    case '(':
                    case ')':
                    case '{':
                    case '}':
                        return true;
                    default:
                        return false;
                }
            },
            'set12-switch' => static function (Token $t): bool {
                if (null !== $t->getId()) {
                    return false;
                }

                switch ($t->getContent()) {
                    case ';':
                    case ',':
                    case '(':
                    case ')':
                    case '{':
                    case '}':
                    case '[':
                    case ']':
                    case '=':
                    case '.':
                    case '?':
                    case ':':
                        return true;
                    default:
                        return false;
                }
            },
        ] + self::charSetOps('set3', $set3)
          + self::charSetOps('set6', $charsFwd)
          + self::charSetOps('set12', $set12);
    }

    /**
     * Lookup-style alternatives to equalsAny() for a set of single characters, each guarded by a null id.
     *
     * @param list<string> $chars
     *
     * @return array<string, \Closure(Token): mixed>
     */
    private static function charSetOps(string $label, array $chars): array
    {
        $map = array_flip($chars);
        $string = implode('', $chars);

        return [
            $label.'-in_array' => static fn (Token $t): bool => null === $t->getId() && \in_array($t->getContent(), $chars, true),
            $label.'-isset-map' => static fn (Token $t): bool => null === $t->getId() && isset($map[$t->getContent()]),
            // strpos() once per character of the set, stopping at the first hit
            $label.'-strpos' => static function (Token $t) use ($chars): bool {
                if (null !== $t->getId()) {
                    return false;
                }

                $content = $t->getContent();

                foreach ($chars as $char) {
                    if (false !== strpos($content, $char)) {
                        return true;
                    }
                }

                return false;
            },
            // strpbrk() searches the content for any character of the set; the content is a single character here
            $label.'-strpbrk' => static fn (Token $t): bool => null === $t->getId() && false !== strpbrk($t->getContent(), $string),
        ];
    }

    private static function registerReport(): void
    {
        if (self::$reportRegistered) {
            return;
        }

        self::$reportRegistered = true;
        register_shutdown_function(static function (): void {
            $median = static function (array $values): float {
                sort($values);
                $n = \count($values);
                $mid = intdiv($n, 2);

                return 1 === $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
            };

            $base = isset(self::$samples['baseline']) ? $median(self::$samples['baseline']) : 0.0;
            $rows = [];

            foreach (self::$samples as $name => $samples) {
                $rows[$name] = max(0.0, $median($samples) - $base);
            }

            fwrite(\STDERR, \sprintf("\nToken API benchmark (median ns/op, baseline %.1f ns subtracted, sink %d)\n", $base, self::$sink));

            foreach ($rows as $name => $ns) {
                fwrite(\STDERR, \sprintf("  %-26s %8.1f\n", $name, $ns));
            }

            $out = getenv('TOKEN_BENCH_JSON');

            if (\is_string($out) && '' !== $out) {
                file_put_contents($out, json_encode($rows, \JSON_PRETTY_PRINT));
            }
        });
    }
}

use PhpCsFixer\Config;

return (new Config())
    ->registerCustomFixers([new TokenApiBenchmarkFixer()])
    ->setRules(['Custom/token_api_benchmark' => true]);
