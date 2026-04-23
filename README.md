# Serverless OGM

An **Object-Graph Mapper** for DynamoDB. Maps PHP objects to items, tracks identity, hydrates references lazily, and flushes changes with batched writes or transactions.

The "G" is deliberate. Your domain is a graph of entities and relationships; storage choice determines only how that graph is serialized — normalized across tables with JOINs, nested in documents, or materialized as adjacency-list rows. As Rick Houlihan puts it: **NoSQL is not non-relational — it's non-normalized.** This package treats the graph as first-class: relationships are modeled as references between entities and stored as adjacency rows or inline ID lists, never collapsed into nested documents or overloaded sort keys.

**Scope.** This OGM owns object lifecycle — identity map, dirty tracking, lazy references, adjacency-backed collections, flush. For most apps, the built-in primitives cover the read path end-to-end: `find()` by `Identity`, `batchFind()`, and traversal through `#[Reference]` / `#[ReferenceMany]`. When an access pattern needs a custom `Query`, `Scan`, or GSI lookup, write it against the AWS SDK and hand the raw items to `$dm->attach()` — the OGM takes over from there. No query builder, no repository pattern, no `findBy`.

**Pitched against raw SDK + prayer, not against Doctrine.** If you're writing DynamoDB code with no identity map, no dirty tracking, and no structure for relationships, this is for you. If you're looking for a NoSQL Doctrine with a repository pattern and a query builder, this isn't.

## When not to use this

- **Single-table design.** Overloaded sort-key prefixes and filter-by-type rules belong in your app, not a framework. If that's your access-pattern model, stay with the raw SDK and build helpers as needed.
- **Heavy ad-hoc querying with no identifiable access patterns.** If most reads are unpredictable filter/sort combinations you can't model up front, you want a different storage engine (Postgres, OpenSearch) — DynamoDB itself will fight you, not just this OGM.
- **Search.** Full-text, geo, complex filter composition — use a secondary store (OpenSearch, Algolia) and let DynamoDB do what it's best at.
- **Cross-partition transactions > 100 items.** DynamoDB limit; the OGM can't lift it.

## Installation

```bash
composer require likeuntomurphy/serverless-ogm
```

For Symfony integration, see [serverless-ogm-bundle](https://github.com/likeuntomurphy/serverless-ogm-bundle).

Requirements: PHP >= 8.5, `aws/aws-sdk-php` ^3.0, `psr/event-dispatcher` ^1.0.

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
| `#[Document]` | Class | Marks a class as a mapped document. Requires `table`; optional `pk`/`sk` attribute names. |
| `#[PartitionKey]` | Property | The partition key. Optional `name` overrides the DynamoDB attribute name. |
| `#[SortKey]` | Property | The sort key for composite primary keys. |
| `#[Field]` | Property | A persisted field. Optional `name` overrides the attribute name. |
| `#[Reference]` | Property | A lazy reference to another document, stored as its partition key value. |
| `#[ReferenceMany]` | Property | A collection of references. Inline by default; opt into an adjacency table with `adjacencyTable:`. |
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

Every document is identified by an `Identity` value object carrying its partition key and, for composite-keyed documents, its sort key.

```php
use Likeuntomurphy\Serverless\OGM\Identity;

$user = $dm->find(User::class, new Identity('alice@example.com'));

// Composite key
$order = $dm->find(Order::class, new Identity('user-123', 'order-456'));
```

`Identity::of($pk, ?$sk)` is an equivalent shorthand.

### Batch find

Fetches multiple documents in a single `BatchGetItem` request. Identity-map hits skip the network.

```php
$users = $dm->batchFind(User::class, [
    new Identity('alice@example.com'),
    new Identity('bob@example.com'),
]);
```

### Update

Change properties on a managed document and flush. Updates become `UpdateItem` with `SET`/`REMOVE` expressions — only changed fields are written.

```php
$user = $dm->find(User::class, new Identity('alice@example.com'));
$user->name = 'Alice Smith';
$dm->flush();
```

### Remove

```php
$user = $dm->find(User::class, new Identity('alice@example.com'));
$dm->remove($user);
$dm->flush();
```

### Attach (bring-your-own query)

When you run a `Query`, `Scan`, or `Index` query yourself, hand the raw unmarshaled item to `attach()` to register it with the identity map and unit of work:

```php
$result = $client->query([
    'TableName' => 'users',
    'IndexName' => 'role-index',
    'KeyConditionExpression' => '#role = :role',
    'ExpressionAttributeNames' => ['#role' => 'role'],
    'ExpressionAttributeValues' => [':role' => ['S' => 'admin']],
]);

$marshaler = new Aws\DynamoDb\Marshaler();
$admins = $dm->attachAll(
    User::class,
    array_map(fn (array $item): array => $marshaler->unmarshalItem($item), $result['Items']),
);
```

`attach()` handles one item; `attachAll()` handles a list. Duplicate identities dedupe via the identity map.

## References

A `#[Reference]` stores another document's partition key and loads it lazily via a PHP 8+ lazy ghost. The referenced document is not fetched until a non-identity property is accessed.

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

### ReferenceMany: two modes

`#[ReferenceMany]` has two storage modes. The target property must be typed `Collection`.

**Inline (default).** IDs stored as a list attribute on the parent item. Hydrates to an `ArrayCollection` of lazy ghosts. Good for small-to-medium relationships that comfortably fit inside the parent item.

```php
use Likeuntomurphy\Serverless\OGM\ArrayCollection;
use Likeuntomurphy\Serverless\OGM\Collection;

#[Document(table: 'playlists', pk: 'PK')]
class Playlist
{
    #[PartitionKey]
    public string $id;

    #[ReferenceMany(targetDocument: Song::class)]
    public Collection $songs;

    public function __construct()
    {
        $this->songs = new ArrayCollection();
    }
}
```

**Adjacency table (opt-in).** A dedicated `{parentId, childId}` table. Hydrates to a `PersistentCollection` with lazy count, ids-only enumeration, and paginated `slice()`. Good for large relationships, relationships that need independent queryability, or anywhere the ID list would push the parent item toward DynamoDB's 400 KB item limit.

```php
#[ReferenceMany(
    targetDocument: Song::class,
    adjacencyTable: 'playlist_songs',
    adjacencyPk: 'playlistId',
    adjacencySk: 'songId',
)]
public Collection $songs;
```

Switching between the modes is a pure mapping change — both return `Collection`, so no entity source edits are needed.

Mutations on a `PersistentCollection` (`$collection->add($song)`, `$collection->remove($song)`) are tracked and flushed as adjacency-table `PutItem`/`DeleteItem` calls on the next `$dm->flush()`.

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

The hydrator coerces DynamoDB values to match PHP property types:

- `int`, `float`, `bool` from DynamoDB numbers/strings
- `DateTime`, `DateTimeImmutable` from ISO 8601 strings
- Backed enums from their stored values
- Nested embeds recursively

## Flush strategies

The DocumentManager accepts an optional `FlushStrategyInterface`.

| Strategy | Behavior |
|---|---|
| `BatchWriteStrategy` (default) | Inserts and deletes via `BatchWriteItem` (up to 25 per request with retry). Updates via individual `UpdateItem` with expressions. |
| `TransactWriteStrategy` | All-or-nothing via `TransactWriteItems` (up to 100 items, 2x WCU cost). |
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

Every `find()` / `batchFind()` / `attach()` checks the identity map first. The same entity is never hydrated twice within a single DocumentManager lifecycle. `$dm->clear()` resets the map (and the relation preload cache).

## Profiling

Implement `ProfilingLogger` to receive identity-map hit/miss and hydration counts:

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

## Limitations

- **References to composite-keyed targets.** A `#[Reference]` or `#[ReferenceMany]` whose target has both a `#[PartitionKey]` and a `#[SortKey]` currently drops the sort key — the reference stores the pk only. Loading the referenced ghost throws at init. Planned.
- **No query builder, no repository pattern.** By design. Use app-owned queries + `attach()`.
- **No optimistic locking / conditional writes yet.** Planned.

## License

MIT
