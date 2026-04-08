# Serverless OGM

A DynamoDB document persistence library for PHP. Maps PHP objects to DynamoDB items using attributes, tracks changes via a unit of work, and flushes with batch writes or transactions.

No query builder. No repository pattern. The database is storage, not a query engine. If you need full-text search or complex filtering, use a secondary system (Algolia, OpenSearch, etc.) and let DynamoDB do what it does best: fast key-value access at any scale.

## Installation

```bash
composer require likeuntomurphy/serverless-ogm
```

For Symfony integration, see [serverless-ogm-bundle](https://github.com/likeuntomurphy/serverless-ogm-bundle).

## Defining documents

```php
use Likeuntomurphy\Serverless\OGM\Mapping\Document;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;
use Likeuntomurphy\Serverless\OGM\Mapping\PartitionKey;

#[Document(table: 'users', pk: 'PK')]
class User
{
    #[PartitionKey]
    public string $email;

    #[Field]
    public string $name;

    #[Field]
    public ?string $role = null;
}
```

### Mapping attributes

| Attribute | Target | Description |
|---|---|---|
| `#[Document]` | Class | Marks a class as a mapped document. Requires `table` name; optional `pk`/`sk` for key attribute names. |
| `#[PartitionKey]` | Property | The partition key. Optional `name` overrides the DynamoDB attribute name. |
| `#[SortKey]` | Property | The sort key for composite primary keys. |
| `#[Field]` | Property | A persisted field. Optional `name` overrides the DynamoDB attribute name. |
| `#[Reference]` | Property | A lazy reference to another document, stored as its partition key value. |
| `#[ReferenceMany]` | Property | A list of references. Stored as an array of partition key values. |
| `#[EmbedOne]` | Property | An embedded sub-document, stored as a DynamoDB map. |
| `#[EmbedMany]` | Property | A list of embedded sub-documents, stored as a DynamoDB list of maps. |
| `#[Embedded]` | Class | Marks a class as an embeddable (no table of its own). |
| `#[Id]` | Property | Legacy single-key identifier (prefer `#[PartitionKey]`). |

## Using the DocumentManager

```php
use Aws\DynamoDb\DynamoDbClient;
use Likeuntomurphy\Serverless\OGM\DocumentManager;

$client = new DynamoDbClient([
    'region' => 'us-east-1',
    'version' => 'latest',
]);

$dm = new DocumentManager($client);
```

### Persist and flush

```php
$user = new User();
$user->email = 'alice@example.com';
$user->name = 'Alice';

$dm->persist($user);
$dm->flush();
```

### Find

```php
$user = $dm->find(User::class, 'alice@example.com');
```

With a composite key (partition key + sort key):

```php
$order = $dm->find(Order::class, 'user-123', 'order-456');
```

### Batch find

Fetches multiple documents in a single `BatchGetItem` request. Items already in the identity map are returned without a network call.

```php
$users = $dm->batchFind(User::class, ['alice@example.com', 'bob@example.com']);
```

### Update

Change properties on a managed document and flush. The OGM uses `UpdateItem` with `SET`/`REMOVE` expressions — only changed fields are written.

```php
$user = $dm->find(User::class, 'alice@example.com');
$user->name = 'Alice Smith';
$dm->flush();
```

### Remove

```php
$user = $dm->find(User::class, 'alice@example.com');
$dm->remove($user);
$dm->flush();
```

## References

A `#[Reference]` stores another document's partition key and loads it lazily via a PHP 8.2+ lazy ghost. The referenced document is not fetched until a non-ID property is accessed.

```php
#[Document(table: 'orders', pk: 'PK')]
class Order
{
    #[PartitionKey]
    public string $id;

    #[Reference(targetDocument: User::class)]
    public User $customer;
}
```

A `#[ReferenceMany]` stores an array of keys. Type the property as `Collection` for lazy batch loading via `BatchGetItem`, or as `array` for individual lazy ghosts.

```php
use Likeuntomurphy\Serverless\OGM\Collection;

#[Document(table: 'playlists', pk: 'PK')]
class Playlist
{
    #[PartitionKey]
    public string $id;

    #[ReferenceMany(targetDocument: Song::class)]
    public Collection $songs;
}
```

## Embeds

Embedded documents are stored inline as DynamoDB maps. They have no table or identity of their own.

```php
use Likeuntomurphy\Serverless\OGM\Mapping\Embedded;
use Likeuntomurphy\Serverless\OGM\Mapping\Field;

#[Embedded]
class Address
{
    #[Field]
    public string $street;

    #[Field]
    public string $city;
}
```

```php
use Likeuntomurphy\Serverless\OGM\Mapping\EmbedOne;

#[Document(table: 'contacts', pk: 'PK')]
class Contact
{
    #[PartitionKey]
    public string $id;

    #[EmbedOne(targetDocument: Address::class)]
    public Address $address;
}
```

## Type coercion

The hydrator automatically coerces DynamoDB values to match PHP property types:

- `int`, `float`, `bool` from DynamoDB number/string values
- `DateTime`, `DateTimeImmutable` from ISO 8601 strings
- Backed enums from their stored values
- Nested embeds recursively

## Flush strategies

The DocumentManager accepts an optional `FlushStrategyInterface` to control how writes are executed.

| Strategy | Behavior |
|---|---|
| `BatchWriteStrategy` (default) | Inserts and deletes via `BatchWriteItem` (up to 25 per request with retry). Updates via individual `UpdateItem` calls with expressions. |
| `TransactWriteStrategy` | All-or-nothing via `TransactWriteItems` (up to 100 items, 2x WCU cost). Inserts, updates, and deletes in a single transaction. |
| `SingleOperationStrategy` | One `PutItem`/`UpdateItem`/`DeleteItem` per entity. No batching. |

```php
use Likeuntomurphy\Serverless\OGM\FlushStrategy\TransactWriteStrategy;

$dm = new DocumentManager(
    $client,
    flushStrategy: new TransactWriteStrategy($client, $marshaler),
);
```

## Events

The DocumentManager dispatches events via any PSR-14 event dispatcher:

| Event | When |
|---|---|
| `PrePersistEvent` | Before a new document is written |
| `PostPersistEvent` | After a new document is written |
| `PreUpdateEvent` | Before a managed document is updated (includes field-level changeset) |
| `PostUpdateEvent` | After a managed document is updated |
| `PreRemoveEvent` | Before a document is deleted |
| `PostRemoveEvent` | After a document is deleted |
| `PostFlushEvent` | After all writes and deletes in a flush |

## Identity map

Each `find()` and `batchFind()` checks the identity map first. The same entity is never hydrated twice within a single DocumentManager lifecycle. Call `$dm->clear()` to reset the map.

## Profiling

Implement `ProfilingLogger` to receive identity map hit/miss and hydration counts:

```php
use Likeuntomurphy\Serverless\OGM\ProfilingLogger;

class MyLogger implements ProfilingLogger
{
    public function recordIdentityMapHit(): void { /* ... */ }
    public function recordIdentityMapMiss(): void { /* ... */ }
    public function recordHydration(): void { /* ... */ }
}

$dm->setProfilingLogger(new MyLogger());
```

The [serverless-ogm-bundle](https://github.com/likeuntomurphy/serverless-ogm-bundle) provides a Symfony profiler integration that uses this interface.

## Requirements

- PHP >= 8.5
- `aws/aws-sdk-php` ^3.0
- `psr/event-dispatcher` ^1.0

## License

MIT
