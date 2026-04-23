<?php

declare(strict_types=1);

namespace Likeuntomurphy\Serverless\OGM\Tests;

use Likeuntomurphy\Serverless\OGM\PersistentCollection;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Author;
use Likeuntomurphy\Serverless\OGM\Tests\Fixture\Book;

/**
 * @internal
 *
 * @coversNothing
 */
class AdjacencyTableTest extends DynamoDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureTable('authors', 'PK');
        $this->ensureTable('books', 'PK');
        $this->ensureTable('author_books', 'authorId', 'bookId');
    }

    public function testHydratesPersistentCollection(): void
    {
        $author = $this->seedAuthorWithBooks('a1', ['Tolkien'], ['b1' => 'Hobbit', 'b2' => 'Fellowship']);

        $found = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($found);
        $this->assertInstanceOf(PersistentCollection::class, $found->books);
    }

    public function testCountDoesNotHydrateItems(): void
    {
        $this->seedAuthorWithBooks('a1', ['Tolkien'], ['b1' => 'Hobbit', 'b2' => 'Fellowship', 'b3' => 'Towers']);

        $this->dm->clear();
        $found = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($found);
        \assert($found->books instanceof PersistentCollection);

        $this->assertCount(3, $found->books);
        $this->assertFalse($found->books->isInitialized());
    }

    public function testChildIdsDoesNotHydrateItems(): void
    {
        $this->seedAuthorWithBooks('a1', ['Tolkien'], ['b1' => 'Hobbit', 'b2' => 'Fellowship']);

        $this->dm->clear();
        $found = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($found);
        \assert($found->books instanceof PersistentCollection);

        $ids = $found->books->childIds();
        sort($ids);

        $this->assertSame(['b1', 'b2'], $ids);
        $this->assertFalse($found->books->isInitialized());
    }

    public function testIsEmptyWithoutHydrating(): void
    {
        $author = new Author();
        $author->id = 'a-empty';
        $author->name = 'Nobody';
        $this->dm->persist($author);
        $this->dm->flush();
        $this->dm->clear();

        $found = $this->dm->find(Author::class, 'a-empty');
        self::assertNotNull($found);
        \assert($found->books instanceof PersistentCollection);

        $this->assertTrue($found->books->isEmpty());
        $this->assertFalse($found->books->isInitialized());
    }

    public function testIterationHydratesBooks(): void
    {
        $this->seedAuthorWithBooks('a1', ['Tolkien'], ['b1' => 'Hobbit', 'b2' => 'Fellowship']);

        $this->dm->clear();
        $found = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($found);
        \assert($found->books instanceof PersistentCollection);

        $titles = [];
        foreach ($found->books as $book) {
            \assert($book instanceof Book);
            $titles[] = $book->title;
        }
        sort($titles);

        $this->assertSame(['Fellowship', 'Hobbit'], $titles);
        $this->assertTrue($found->books->isInitialized());
    }

    public function testAddFlushWritesAdjacencyRow(): void
    {
        $this->seedAuthorWithBooks('a1', ['Tolkien'], []);
        $book = new Book();
        $book->id = 'b-new';
        $book->title = 'Silmarillion';
        $this->dm->persist($book);
        $this->dm->flush();

        $this->dm->clear();
        $author = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($author);
        \assert($author->books instanceof PersistentCollection);

        $author->books->add($book);
        $this->dm->flush();

        $this->dm->clear();
        $reloaded = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($reloaded);
        \assert($reloaded->books instanceof PersistentCollection);

        $this->assertCount(1, $reloaded->books);
        $this->assertSame(['b-new'], $reloaded->books->childIds());
    }

    public function testRemoveFlushDeletesAdjacencyRow(): void
    {
        $this->seedAuthorWithBooks('a1', ['Tolkien'], ['b1' => 'Hobbit', 'b2' => 'Fellowship']);

        $this->dm->clear();
        $author = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($author);
        \assert($author->books instanceof PersistentCollection);

        $first = $author->books->toArray()[0];
        $firstId = $first->id; // @phpstan-ignore property.notFound
        $author->books->remove($first);
        $this->dm->flush();

        $this->dm->clear();
        $reloaded = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($reloaded);
        \assert($reloaded->books instanceof PersistentCollection);

        $ids = $reloaded->books->childIds();
        $this->assertCount(1, $ids);
        $this->assertNotContains($firstId, $ids);
    }

    public function testClearMutationsAfterSuccessfulFlush(): void
    {
        $this->seedAuthorWithBooks('a1', ['Tolkien'], []);
        $book = new Book();
        $book->id = 'b-new';
        $book->title = 'Silmarillion';
        $this->dm->persist($book);
        $this->dm->flush();

        $this->dm->clear();
        $author = $this->dm->find(Author::class, 'a1');
        self::assertNotNull($author);
        \assert($author->books instanceof PersistentCollection);

        $author->books->add($book);
        $this->assertCount(1, $author->books->getAdded());

        $this->dm->flush();

        $this->assertCount(0, $author->books->getAdded());
    }

    public function testPreloadRelationsServesSubsequentReads(): void
    {
        $this->seedAuthorWithBooks('a1', ['Tolkien'], ['b1' => 'Hobbit', 'b2' => 'Fellowship']);
        $this->seedAuthorWithBooks('a2', ['Lewis'], ['b3' => 'Wardrobe']);

        $this->dm->clear();
        $this->dm->preloadRelations(Author::class, 'books');

        $cached = $this->dm->getCachedRelation('author_books', 'a1');
        self::assertNotNull($cached);
        sort($cached);
        $this->assertSame(['b1', 'b2'], $cached);

        $cached2 = $this->dm->getCachedRelation('author_books', 'a2');
        $this->assertSame(['b3'], $cached2);
    }

    /**
     * @param list<string>          $authorNames single element
     * @param array<string, string> $books       bookId => title
     */
    private function seedAuthorWithBooks(string $authorId, array $authorNames, array $books): Author
    {
        $author = new Author();
        $author->id = $authorId;
        $author->name = $authorNames[0];
        $this->dm->persist($author);

        $bookEntities = [];
        foreach ($books as $bookId => $title) {
            $book = new Book();
            $book->id = $bookId;
            $book->title = $title;
            $this->dm->persist($book);
            $bookEntities[] = $book;
        }

        $this->dm->flush();

        if ([] !== $bookEntities) {
            $this->dm->clear();
            $managedAuthor = $this->dm->find(Author::class, $authorId);
            self::assertNotNull($managedAuthor);
            \assert($managedAuthor->books instanceof PersistentCollection);
            foreach ($bookEntities as $book) {
                $managedAuthor->books->add($book);
            }
            $this->dm->flush();

            return $managedAuthor;
        }

        return $author;
    }
}
