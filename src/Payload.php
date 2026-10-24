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
 */
final class Payload
{
    /** @var array<class-string, list<string>> */
    private static array $allowed = [];

    public static function encode(object $job): string
    {
        if ($job instanceof \Closure || new \ReflectionClass($job)->isAnonymous()) {
            throw new QueueException('Closures and anonymous classes cannot be queued; use a named job class.');
        }

        try {
            return serialize($job);
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
        if (preg_match('/^O:\d+:"([^"]+)"/', $payload, $match) !== 1 || !class_exists($match[1])) {
            throw new QueueException('The payload does not describe a known job class.');
        }

        return $match[1];
    }

    public static function decode(string $payload): object
    {
        $class = self::className($payload);
        try {
            $job = @unserialize($payload, ['allowed_classes' => self::allowedClasses($class)]);
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
