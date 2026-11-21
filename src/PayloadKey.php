<?php

declare(strict_types=1);

namespace Zana\Queue;

/**
 * Signs queued payloads, so that a row the application did not write is
 * refused before it is unserialized (2.20, S28): unserialize() builds
 * objects, and a class's __wakeup() or __destruct() runs before anything can
 * check that the object is a job.
 *
 * The key is queue.key, or one derived from security.secret. Payloads written
 * before 2.20 are unsigned: they are refused, unless queue.accept_unsigned is
 * on while the queue drains. Without any key, payloads are neither signed nor
 * checked, as before 2.20, and check warns.
 */
final readonly class PayloadKey
{
    public const int MINIMUM_LENGTH = 32;

    private const string PREFIX = 'zs1.';

    /** Signed with a nonce and the issue time (2.34). */
    private const string PREFIX_NONCE = 'zs2.';

    private ?string $key;

    public function __construct(#[\SensitiveParameter] ?string $key, private bool $acceptUnsigned = false)
    {
        if ($key !== null && strlen($key) < self::MINIMUM_LENGTH) {
            throw new QueueException('queue.key must be at least ' . self::MINIMUM_LENGTH . ' characters; generate one with: php -r "echo bin2hex(random_bytes(32));"');
        }
        $this->key = $key;
    }

    /**
     * A key derived from the application secret, so that one secret signs several kinds of payload apart.
     */
    public static function fromSecret(#[\SensitiveParameter] ?string $secret, string $purpose, bool $acceptUnsigned = false): self
    {
        return new self($secret === null || $secret === '' ? null : hash_hmac('sha256', $purpose, $secret), $acceptUnsigned);
    }

    public function signs(): bool
    {
        return $this->key !== null;
    }

    public function acceptsUnsigned(): bool
    {
        return $this->acceptUnsigned;
    }

    /**
     * Sign a payload. With a nonce (2.34), the signature covers it and the time the job was issued too, so a row
     * copied in the database is known for a copy: see DatabaseQueue.
     */
    public function seal(string $payload, ?string $nonce = null, ?int $issuedAt = null): string
    {
        if ($this->key === null) {
            return $payload;
        }
        if ($nonce === null) {
            return self::PREFIX . $this->mac($payload) . '.' . $payload;
        }
        if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            throw new QueueException('A payload nonce is 32 hexadecimal characters.');
        }
        $issuedAt ??= time();

        return self::PREFIX_NONCE . $this->mac(self::envelopeOf($nonce, $issuedAt, $payload)) . ".{$nonce}.{$issuedAt}.{$payload}";
    }

    /**
     * The payload inside, once its signature is checked.
     *
     * @throws QueueException When the signature is wrong, or an unsigned payload is not accepted
     */
    public function open(string $stored): string
    {
        return $this->opened($stored)['payload'];
    }

    /**
     * The payload's nonce and issue time (2.34), once its signature is checked; null for a payload signed without
     * them, before 2.34, or not signed.
     *
     * @return array{nonce: string, issuedAt: int}|null
     *
     * @throws QueueException When the signature is wrong, or an unsigned payload is not accepted
     */
    public function envelope(string $stored): ?array
    {
        $opened = $this->opened($stored);

        return $opened['nonce'] === null ? null : ['nonce' => $opened['nonce'], 'issuedAt' => (int) $opened['issuedAt']];
    }

    /**
     * @return array{payload: string, nonce: ?string, issuedAt: ?int}
     */
    private function opened(string $stored): array
    {
        if (!self::isSealed($stored)) {
            if ($this->key !== null && !$this->acceptUnsigned) {
                throw new QueueException('The payload is not signed, so it was not read. Payloads written before 2.20 are unsigned: set queue.accept_unsigned to true until the queue is drained.');
            }

            return ['payload' => $stored, 'nonce' => null, 'issuedAt' => null];
        }
        if ($this->key === null) {
            throw new QueueException('The payload is signed, but no key is set to check it: set security.secret or queue.key.');
        }
        if (str_starts_with($stored, self::PREFIX_NONCE)) {
            $parts = explode('.', substr($stored, strlen(self::PREFIX_NONCE)), 4);
            if (count($parts) !== 4 || preg_match('/^[0-9a-f]{32}$/', $parts[1]) !== 1 || preg_match('/^[0-9]{1,12}$/', $parts[2]) !== 1
                || !hash_equals($this->mac(self::envelopeOf($parts[1], (int) $parts[2], $parts[3])), $parts[0])) {
                throw new QueueException('The payload signature is wrong, so it was not read: it was changed, or written with another key.');
            }

            return ['payload' => $parts[3], 'nonce' => $parts[1], 'issuedAt' => (int) $parts[2]];
        }
        $parts = explode('.', substr($stored, strlen(self::PREFIX)), 2);
        if (count($parts) !== 2 || !hash_equals($this->mac($parts[1]), $parts[0])) {
            throw new QueueException('The payload signature is wrong, so it was not read: it was changed, or written with another key.');
        }

        return ['payload' => $parts[1], 'nonce' => null, 'issuedAt' => null];
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX) || str_starts_with($stored, self::PREFIX_NONCE);
    }

    /**
     * The payload inside, unchecked: to name its class, never to read it.
     */
    public static function inner(string $stored): string
    {
        if (str_starts_with($stored, self::PREFIX_NONCE)) {
            return explode('.', substr($stored, strlen(self::PREFIX_NONCE)), 4)[3] ?? '';
        }
        if (!self::isSealed($stored)) {
            return $stored;
        }
        $parts = explode('.', substr($stored, strlen(self::PREFIX)), 2);

        return $parts[1] ?? '';
    }

    /**
     * The nonce a stored payload names, unchecked: to keep it with the row, never to trust it.
     */
    public static function nonceOf(string $stored): ?string
    {
        if (!str_starts_with($stored, self::PREFIX_NONCE)) {
            return null;
        }
        $nonce = explode('.', substr($stored, strlen(self::PREFIX_NONCE)), 4)[1] ?? '';

        return preg_match('/^[0-9a-f]{32}$/', $nonce) === 1 ? $nonce : null;
    }

    private static function envelopeOf(string $nonce, int $issuedAt, string $payload): string
    {
        return "zs2|{$nonce}|{$issuedAt}|{$payload}";
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, (string) $this->key);
    }
}
