<?php declare(strict_types=1);
/**
 * This file is part of the Parsica library.
 *
 * Copyright (c) 2020 Mathias Verraes <mathias@verraes.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Verraes\Parsica;

use Exception;
use Verraes\Parsica\Internal\Fail;
use function Verraes\Parsica\ParseResult\{fail};

/**
 * A parser is any function that takes a string input and returns a {@see ParseResult}. The Parser class is a wrapper
 * around such functions. The {@see Parser::make()} static constructor takes a callable that does the actual parsing.
 * Usually you don't need to instantiate this class directly. Instead, build your parser from existing parsers and
 * combinators.
 *
 * At the moment, there is no Parser interface, and no Parser abstract class to extend from. This is intentional, but
 * will be changed if we find use cases where those would be the best solutions.
 *
 * @template T
 */
final class Parser
{
    /**
     * @var callable(string) : ParseResult<T> $parserF
     */
    private $parserFunction;

    /** @var 'non-recursive'|'awaiting-recurse'|'recursion-was-setup' */
    private string $recursionStatus;

    /**
     * @param callable(string) : ParseResult<T> $parserFunction
     * @param 'non-recursive'|'awaiting-recurse'|'recursion-was-setup' $recursionStatus
     */
    private function __construct(callable $parserFunction, string $recursionStatus)
    {
        $this->parserFunction = $parserFunction;
        $this->recursionStatus = $recursionStatus;
    }

    /**
     * Make a recursive parser. Use {@see recursive()}.
     *
     * @return Parser<T>
     * @internal
     */
    public static function recursive(): Parser
    {
        return new Parser(
        // Make a placeholder parser that will throw when you try to run it.
            function (string $input): ParseResult {
                throw new Exception(
                    "Can't run a recursive parser that hasn't been setup properly yet. "
                    . "A parser created by recursive(), must then be called with ->recurse(Parser) "
                    . "before it can be used."
                );
            },
            'awaiting-recurse');
    }

    /**
     * Recurse on a parser. Used in combination with {@see recursive()}. After calling this method, this parser behaves
     * like a regular parser.
     */
    public function recurse(Parser $parser): Parser
    {
        switch ($this->recursionStatus) {
            case 'non-recursive':
                throw new Exception(
                    "You can't recurse on a non-recursive parser. Create a recursive parser first using recursive(), "
                    . "then call ->recurse() on it."
                );
            case 'recursion-was-setup':
                throw new Exception("You can only call recurse() once on a recursive parser.");
            case 'awaiting-recurse':
                // Replace the placeholder parser from recursive() with a call to the inner parser. This must be dynamic,
                // because it's possible that the inner parser is also a recursive parser that has not been setup yet.
                $this->parserFunction = fn(string $input): ParseResult => $parser->run($input);
                $this->recursionStatus = 'recursion-was-setup';
                break;
            default:
                throw new Exception("Unexpected recursionStatus value");
        }

        return $this;
    }

    /**
     * Run the parser on an input
     *
     * @return ParseResult<T>
     */
    public function run(string $input): ParseResult
    {
        $f = $this->parserFunction;
        return $f($input);
    }

    /**
     * Optionally parse something, but still succeed if the thing is not there.
     *
     * @psalm-suppress InvalidReturnType
     * @psalm-suppress InvalidReturnStatement
     *
     * @return Parser<T>
     * @see optional()
     */
    public function optional(): Parser
    {
        return $this->or(pure(""));
    }

    /**
     * Try the first parser, and failing that, try the second parser. Returns the first succeeding result, or the first
     * failing result.
     *
     * Caveat: The order matters!
     * string('http')->or(string('https')
     *
     * @param Parser<T> $other
     *
     * @return Parser<T>
     */
    public function or(Parser $other): Parser
    {
        // This is the canonical implementation: run both parsers, and pick the first succeeding one, by delegating
        // this work to ParseResult::alternative.

        return Parser::make(function (string $input) use ($other): ParseResult {
            // @TODO When the first parser succeeds, this implementation unnecessarily evaluates $other anyway.
            return $this->run($input)
                ->alternative(
                    $other->run($input)
                );
        });

        // @TODO For a more performant version, we'll probably need to replace the above implementation with this one.
        // The reason is that the above implementation runs both parsers, even if the first one succeeds.
        // The implementation below only runs the second parser if the first one fails.
        /*
        return Parser::make(function (string $input) use ($other): ParseResult {
            $r1 = $this->run($input);
            if($r1->isSuccess()) {
                return $r1;
            }
            $r2 = $other->run($input);
            return $r2->isSuccess() ? $r2 : $r1;
        });
        */
    }

    /**
     * Make a new parser.
     *
     * @param callable(string) : ParseResult<T2> $parserFunction
     *
     * @return Parser<T2>
     * @internal
     * @template T2
     *
     */
    public static function make(callable $parserFunction): Parser
    {
        return new Parser($parserFunction, 'non-recursive');
    }

    /**
     * Alias for `sequence()`. Parse something, then follow by something else. Ignore the result of the first parser and return the result of the
     * second parser.
     *
     * @template T2
     *
     * @param Parser<T2> $second
     *
     * @return Parser<T2>
     */
    public function followedBy(Parser $second): Parser
    {
        return $this->sequence($second);
    }

    /**
     * Parse something, then follow by something else. Ignore the result of the first parser and return the result of the
     * second parser.
     *
     * @template T2
     *
     * @param Parser<T2> $second
     *
     * @return Parser<T2>
     * @see sequence()
     */
    public function sequence(Parser $second): Parser
    {
        return $this->bind(
        /** @param mixed $_ */
            function ($_) use ($second) {
                return $second;
            }
        )->label('sequence');
    }

    /**
     * Label a parser. When a parser fails, instead of a generated error message, you'll see your label.
     * eg (char(':')->followedBy(char(')')).followedBy(char(')')).
     *
     * @return Parser<T>
     */
    public function label(string $label): Parser
    {
        // @todo perhaps something like $parser->onSuccess($f)->onFailure($g) ?
        return Parser::make(function (string $input) use ($label) : ParseResult {
            $result = $this->run($input);
            return ($result->isSuccess())
                ? $result
                : new Fail($label, $input);
        });
    }

    /**
     * Create a parser that takes the output from the first parser (if successful) and feeds it to the callable. The
     * callable must return another parser. If the first parser fails, the first parser is returned.
     *
     * @template T2
     *
     * @param callable(T) : Parser<T2> $f
     *
     * @return Parser<T2>
     * @see bind()
     */
    public function bind(callable $f): Parser
    {
        /** @var Parser<T2> $parser */
        $parser = Parser::make(function (string $input) use ($f) : ParseResult {
            $result = $this->map($f)->run($input);
            if ($result->isSuccess()) {
                $p2 = $result->output();
                return $result->continueWith($p2);
            } else {
                return $result;
            }
        });
        return $parser;
    }

    /**
     * Map a function over the parser (which in turn maps it over the result).
     *
     * @template T2
     *
     * @param callable(T) : T2 $transform
     *
     * @return Parser<T2>
     */
    public function map(callable $transform): Parser
    {
        return Parser::make(fn(string $input): ParseResult => $this->run($input)->map($transform));
    }

    /**
     * Take the remaining input from the result and parse it
     */
    public function continueFrom(ParseResult $result): ParseResult
    {
        return $this->run($result->remainder());
    }

    /**
     * Construct a class with thee parser's output as the constructor argument
     *
     * @template T2
     *
     * @param class-string<T2> $className
     *
     * @return Parser<T2>
     */
    public function construct(string $className): Parser
    {
        return $this->map(
        /** @param mixed $val */
            fn($val) => new $className($val)
        );
    }

    /**
     * Combine the parser with another parser of the same type, which will cause the results to be appended.
     *
     * @param Parser<T> $other
     *
     * @return Parser<T>
     */
    public function append(Parser $other): Parser
    {
        return append($this, $other);
    }

    /**
     * Try to parse the input, or throw an exception;
     *
     * @return ParseResult<T>
     *
     * @throws Fail
     */
    public function try(string $input): ParseResult
    {
        $result = $this->run($input);
        if ($result->isFail()) {
            /** @psalm-suppress InvalidThrow */
            throw $result;
        }
        return $result;
    }

    /**
     * Sequential application.
     *
     * The first parser must be of type Parser<callable(T2):T3>.
     *
     * apply :: f (a -> b) -> f a -> f b
     *
     * @template T2
     * @template T3
     *
     * @param Parser<T2> $parser
     *
     * @return Parser<T3>
     *
     * @psalm-suppress MixedArgumentTypeCoercion
     */
    public function apply(Parser $parser): Parser
    {
        return $this->bind(fn(callable $f) => $parser->map($f));
    }

    /**
     * Sequence two parsers, and return the output of the first one, ignore the second.
     *
     * @template T2
     *
     * @param Parser<T2> $other
     *
     * @return Parser<T>
     * @see keepFirst()
     */
    public function thenIgnore(Parser $other): Parser
    {
        return keepFirst($this, $other);
    }

    /**
     * notFollowedBy only succeeds when $second fails. It never consumes any input.
     *
     * Example:
     *
     * `string("print")` will also match "printXYZ"
     *
     * `string("print")->notFollowedBy(alphaNumChar()))` will match "print something" but not "printXYZ something"
     *
     * @see notFollowedBy()
     *
     * @template T2
     *
     * @param Parser<T2> $parser
     *
     * @return Parser<T>
     */
    public function notFollowedBy(Parser $second) : Parser
    {
        return keepFirst($this, notFollowedBy($second));
    }
}
