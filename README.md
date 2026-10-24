# Zana Queue

Background jobs for Zana applications. Jobs go onto a queue (in memory, in
the database, or run at once) and a worker processes them. Each job runs in
its own container scope, with retries and failure storage. Requires PHP 8.5
or later.

```
composer require mj-zana/queue
```

## Jobs

A job is a plain, serializable class with a `handle()` method. Its
dependencies are injected from the job's scope:

```php
#[JobOptions(queue: 'mail', tries: 3, backoff: [10, 60])]
final class SendWelcomeMail
{
    public function __construct(public readonly string $patientId) {}

    public function handle(Mailer $mailer, PatientRepository $patients): void { /* ... */ }
}

$dispatcher->dispatch(new SendWelcomeMail($id));            // to the "mail" queue
$dispatcher->dispatch(new SendWelcomeMail($id), delay: 60);  // available in a minute
```

- **Retries:** a failed job is retried after `backoff[attempt - 1]` seconds.
  The last value repeats, and an empty list means no delay.
- **Final failure:** after `tries` attempts the job moves to the failed jobs
  and an `error` is logged. Each retry before that logs a `warning`.
- **Payloads:** jobs are stored with `serialize()`. When a payload is read
  back, only the job class and the classes its typed properties declare are
  allowed. An object in an untyped or array property makes the job fail.
  Closures and anonymous classes cannot be queued.

## Configuration

Register `QueueServiceProvider` with `ConsoleServiceProvider` (and
`DatabaseServiceProvider` for the database driver):

```php
// config/queue.php
return [
    'default' => 'database',  // sync (default), memory or database
    'connection' => 'main',   // optional; the default connection otherwise
    'retry_after' => 90,      // seconds before an abandoned reservation is taken again
];
```

- **`sync`:** runs each job inside `dispatch()`, once, and rethrows its
  failure.
- **`memory`:** keeps jobs for the life of the process. Use it for tests.
- **`database`:** stores jobs in `zana_jobs` and failures in
  `zana_failed_jobs`. Create the tables with:

  ```
  php bin/zana queue:table
  php bin/zana migrate
  ```

## Workers

```
php bin/zana queue:work mail --sleep=3
php bin/zana queue:work --max-jobs=100 --stop-when-empty
php bin/zana queue:failed
php bin/zana queue:retry 12 13
php bin/zana queue:retry all
```

Each job runs in a scope of type `queue`, named after the job class, with
the attribute `job_id`. Scoped services are created per job and disposed
afterwards.

## License

MIT — see [LICENSE](LICENSE).
