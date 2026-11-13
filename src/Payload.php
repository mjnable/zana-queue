<?php

declare(strict_types=1);

namespace Zana\Queue;

/**
 * Turns jobs into strings and back.
 *
 * Reading a payload only allows the job's class and the classes its typed
 * properties declare, recursively (enums and DateTime included). Objects held
 * in untyped or array properties come back as __PHP_Incomplete_Class, which
 * is rejected. Payloads must still come from a trusted store.
 *
 * Payloads are base64 (2.13): serialize() writes NUL bytes for protected and
 * private properties, and PostgreSQL silently truncates text at the first
 * one. Payloads written before 2.13, as plain serialize() output, are still
 * read.
 *
 * With a PayloadKey that signs (2.20), payloads are signed, and decode()
 * checks the signature before unserialize() builds any object.
 */
final class Payload
{
    /** @var array<class-string, list<string>> */
    private static array $allowed = [];

    public static function encode(object $job, ?PayloadKey $key = null): string
    {
        if ($job instanceof \Closure || new \ReflectionClass($job)->isAnonymous()) {
            throw new QueueException('Closures and anonymous classes cannot be queued; use a named job class.');
        }

        try {
            return ($key ?? new PayloadKey(null))->seal(base64_encode(serialize($job)));
        } catch (\Throwable $e) {
            throw new QueueException('The job ' . $job::class . ' cannot be serialized: ' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * The job class named by a payload, without reading the rest of it.
     *
     * @return class-string
     */
    public static function className(string $payload): string
    {
        if (preg_match('/^O:\d+:"([^"]+)"/', self::serialized(PayloadKey::inner($payload)), $match) !== 1 || !class_exists($match[1])) {
            throw new QueueException('The payload does not describe a known job class.');
        }

        return $match[1];
    }

    /**
     * @throws QueueException When the signature is wrong or missing, or the payload holds what its class does not declare
     */
    public static function decode(string $payload, ?PayloadKey $key = null): object
    {
        $payload = ($key ?? new PayloadKey(null))->open($payload);   // before anything is built
        $class = self::className($payload);
        try {
            $job = @unserialize(self::serialized($payload), ['allowed_classes' => self::allowedClasses($class)]);
        } catch (\Throwable $e) {
            throw new QueueException("The payload for {$class} cannot be read: " . $e->getMessage(), previous: $e);
        }

        if (!$job instanceof $class) {
            throw new QueueException("The payload for {$class} cannot be read.");
        }
        if (self::containsIncomplete($job)) {
            throw new QueueException("The payload for {$class} holds objects of classes its properties do not declare.");
        }

        return $job;
    }

    /**
     * The serialize() output in a payload: base64 since 2.13, plain before (it starts with "O:", which
     * base64 never contains).
     */
    private static function serialized(string $payload): string
    {
        if (str_starts_with($payload, 'O:')) {
            return $payload;
        }
        $decoded = base64_decode($payload, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    public static function allowedClasses(string $class): array
    {
        if (isset(self::$allowed[$class])) {
            return self::$allowed[$class];
        }

        $found = [];
        self::collect($class, $found);

        return self::$allowed[$class] = array_keys($found);
    }

    /**
     * @param array<string, true> $found
     */
    private static function collect(string $class, array &$found): void
    {
        if (isset($found[$class]) || !(class_exists($class) || enum_exists($class))) {
            return;
        }
        $found[$class] = true;

        for ($reflection = new \ReflectionClass($class); $reflection !== false; $reflection = $reflection->getParentClass()) {
            $found[$reflection->getName()] = true;
            foreach ($reflection->getProperties() as $property) {
                foreach (self::namedTypes($property->getType()) as $type) {
                    self::collect($type, $found);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function namedTypes(?\ReflectionType $type): array
    {
        return match (true) {
            $type instanceof \ReflectionNamedType => $type->isBuiltin() ? [] : [$type->getName()],
            $type instanceof \ReflectionUnionType, $type instanceof \ReflectionIntersectionType => array_merge(...array_map(self::namedTypes(...), $type->getTypes())),
            default => [],
        };
    }

    private static function containsIncomplete(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        if ($depth > 32 || !(is_array($value) || is_object($value))) {
            return false;
        }

        foreach (is_object($value) ? (array) $value : $value as $item) {
            if (self::containsIncomplete($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
