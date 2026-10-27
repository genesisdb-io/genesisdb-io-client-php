# GenesisDB client for PHP

The official client for [GenesisDB](https://www.genesisdb.io), the open source event sourcing database. PHP 8.2+, built on Guzzle, typed value objects and exceptions.

> **Version 2.0.0 targets GenesisDB 2.0 and is not compatible with GenesisDB 1.x servers or with the 1.x client API.** Routes, configuration, type names and method signatures have changed. Keep a 1.x client for a 1.x server.
>
> Existing 1.x clients keep working against a GenesisDB 2.0 server, which still serves the unchanged 1.x API under `/api/v1`, so the server can be upgraded first and the clients at your own pace. New 2.0 features (stored events returned by commit, upper bounds, schemas, typed errors) need this 2.0.0 client.

```bash
composer require genesisdb/client-sdk
```

## Quick start

```php
use GenesisDB\Client;
use GenesisDB\NewEvent;

$db = new Client(url: 'http://localhost:8080', token: getenv('GENESISDB_AUTH_TOKEN'));

$db->commit([
    new NewEvent('io.example.shop', '/customer/42', 'io.example.shop.customer-registered', ['name' => 'Bruce Wayne']),
]);

$events = $db->fetch('/customer/42');
```

`url` and `token` fall back to `GENESISDB_URL` and `GENESISDB_AUTH_TOKEN`, so `new Client()` works in an environment that sets both.

## Events

```php
use GenesisDB\FetchOptions;

// Commit a batch atomically. The server assigns ID, time and hashes and returns the stored events.
$stored = $db->commit([
    new NewEvent('io.example.shop', '/order/1', 'io.example.shop.order-placed', ['total' => 99.5]),
    new NewEvent('io.example.shop', '/order/1/item/1', 'io.example.shop.item-added', ['sku' => 'A1', 'qty' => 2]),
]);
$stored[0]->id;    // "3f2c..."
$stored[0]->hash;  // "a9b1..."
$stored[0]->time;  // DateTimeImmutable
$stored[0]->data;  // ['total' => 99.5]

// A subject with one segment gets a generated ID appended.
$db->commit([new NewEvent('io.example.shop', '/customer', 'io.example.shop.customer-registered', ['name' => 'Selina'])]);

// Fetch a subject with all descendants, in commit order.
$all = $db->fetch('/order/1');

// Resume after a known event, or stop at one.
$since = $db->fetch('/order', new FetchOptions(lowerBound: $lastSeenId, includeLowerBoundEvent: false));
$until = $db->fetch('/order', new FetchOptions(upperBound: $someId, includeUpperBoundEvent: true));

// Only the newest event of a type below the subject.
$latest = $db->fetch('/order/1', new FetchOptions(latestByEventType: 'io.example.shop.order-placed'));
```

`data` accepts an associative array, a `JsonSerializable` or an object. An empty array is sent as `{}`.

## Preconditions

Preconditions are checked atomically with the commit. A failed precondition throws `PreconditionFailedException` and writes nothing.

```php
use GenesisDB\Precondition;
use GenesisDB\PreconditionFailedException;

try {
    $db->commit(
        [new NewEvent('io.example.shop', '/customer/42', 'io.example.shop.customer-registered', ['email' => 'bruce@example.com'])],
        [
            Precondition::isSubjectNew('/customer/42'),
            Precondition::isQueryResultTrue("STREAM e FROM events WHERE e.data.email == 'bruce@example.com' MAP COUNT() == 0"),
        ],
    );
} catch (PreconditionFailedException $e) {
    echo $e->preconditionType, ': ', $e->reason; // "isSubjectNew: subject /customer/42 already has events"
}
```

Factories: `isSubjectNew`, `isSubjectExisting`, `isQueryResultTrue`.

## Subscriptions

`subscribe` is a generator over Server-Sent Events. It yields the existing history first, then new events as they are committed, and runs until you `break` out of the loop.

```php
foreach ($db->subscribe('/order', interval: 0.5) as $event) {
    echo $event->type, ' ', json_encode($event->data), PHP_EOL;
    if ($shouldStop) {
        break;
    }
}
```

`interval` is the poll interval in seconds (default 1). The bound options of `fetch` work here as well (`options: new FetchOptions(lowerBound: ...)`), so a consumer that persists the last processed event ID resumes where it left off. A stream the server ends with an error throws `GenesisDBException`.

## Queries

[GDBQL](https://github.com/genesisdb-io/genesisdb/blob/main/docs/gdbql.md) runs on the server. Results come back as rows, a bare aggregate as a single-element list.

```php
$rows = $db->query("STREAM e FROM events WHERE e.subject UNDER '/customer' MAP { name: e.data.name, total: e.data.total }");

[$count] = $db->query("STREAM e FROM events WHERE e.type == 'io.example.shop.order-placed' MAP COUNT()");

$byCategory = $db->query(
    "STREAM e FROM events WHERE e.type == 'io.example.shop.item-added' GROUP BY e.data.category MAP { category: e.data.category, items: COUNT(), revenue: SUM(e.data.total) }"
);
```

## GDPR: reference payloads and erasure

Store a payload as reference data and it can be erased later. The event, its hash and the chain stay intact, the payload becomes `{}`.

```php
$db->commit([
    (new NewEvent('io.example.shop', '/customer/42', 'io.example.shop.customer-registered', ['name' => 'Bruce', 'email' => 'bruce@example.com']))->asReference(),
]);

$db->erase('/customer/42'); // administrator token
```

Inline payloads are immutable. `erase` on a subject without reference payloads throws `UnprocessableException` (422).

## Schemas

```php
$db->registerSchema('io.example.shop.order-placed', [
    'type' => 'object',
    'properties' => ['total' => ['type' => 'number'], 'currency' => ['type' => 'string']],
    'required' => ['total'],
]);

$schemas = $db->schemas();
```

Commits of that type are validated. A violation throws `GenesisDBException` with status 400.

## Catalog, status, administration

```php
$db->subjects();          // ["/customer", "/order"]
$db->types();             // ["io.example.shop.customer-registered", ...]
$db->status();            // version, event counts, storage format, readiness, raw document
$db->ping();              // "Pong", no token required

$db->audit();             // "Audit passed. All 1234 events integrity checks succeeded."
```

Audit, erase and schema registration need the administrator token. Everything else works with a reader token (`GENESISDB_READ_TOKEN`), commit additionally with a writer token.

## Errors

Every non-2xx answer throws `GenesisDBException` with public readonly `status`, `body` and `operation`. Subclasses: `PreconditionFailedException` (412, with `preconditionType` and `reason`), `AuthenticationException` (401, 403), `NotFoundException` (404), `ConflictException` (409), `UnprocessableException` (422), `NotReadyException` (503). Transport failures throw `GenesisDBException` with status 0 and the Guzzle exception as previous.

## Configuration

```php
new Client(
    url: 'http://localhost:8080',     // or GENESISDB_URL
    token: '...',                     // or GENESISDB_AUTH_TOKEN
    timeout: 30.0,                    // seconds, for non-streaming calls, 0 disables
    http: new GuzzleHttp\Client(...), // optional, bring your own Guzzle client (proxies, TLS, handler stack)
);
```

## Development

```bash
composer install
vendor/bin/phpunit --testsuite unit                                                       # against Guzzle's MockHandler
GENESISDB_URL=http://localhost:8080 GENESISDB_AUTH_TOKEN=... vendor/bin/phpunit           # also runs IntegrationTest against a running GenesisDB
```

## License

MIT
