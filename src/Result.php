<?php

declare(strict_types=1);

namespace SendClipper;

use SendClipper\Exception\ApiException;

/**
 * Every API method returns one. Exactly one of `$data` and `$error` is set.
 * It destructures to `[$data, $error]`:
 *
 *     [$data, $error] = $sc->emails->send([...]);
 *
 * @template-covariant T
 * @implements \ArrayAccess<int, mixed>
 */
final class Result implements \ArrayAccess
{
    /**
     * A failed call: no data, whatever the call would have returned.
     *
     * @param array<string, string>|null $headers
     * @return Result<never>
     */
    public static function failure(ErrorResponse $error, ?array $headers = null): self
    {
        /** @var Result<never> $result a failure's $data is null for every T */
        $result = new self(null, $error, $headers);
        return $result;
    }

    /**
     * @param T $data null on an error
     * @param array<string, string>|null $headers lower-case names; null when no answer arrived
     */
    public function __construct(
        public readonly mixed $data,
        public readonly ?ErrorResponse $error,
        public readonly ?array $headers,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }

    /**
     * `$data`, or throw the error as an `ApiException`.
     *
     * @return T
     */
    public function unwrap(): mixed
    {
        if ($this->error !== null) {
            throw new ApiException(
                $this->error->statusCode ?? 0,
                $this->error->name,
                $this->error->message,
                $this->error->detail,
                $this->headers ?? [],
            );
        }
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset === 0 || $offset === 1;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            0 => $this->data,
            1 => $this->error,
            default => throw new \OutOfRangeException('A Result destructures to [$data, $error].'),
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('A Result is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('A Result is read-only.');
    }
}
