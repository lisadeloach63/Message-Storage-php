<?php

namespace EventSauce\IlluminateMessageRepository;

use EventSauce\DoctrineMessageRepository\DummyAggregateRootId;
use EventSauce\EventSourcing\DefaultHeadersDecorator;
use EventSauce\EventSourcing\Header;
use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\Serialization\ConstructingMessageSerializer;
use EventSauce\EventSourcing\UnableToPersistMessages;
use EventSauce\EventSourcing\UnableToRetrieveMessages;
use EventSauce\MessageOutbox\TestTooling\DummyEvent;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

use function iterator_to_array;

class IlluminateUuidV4MessageRepositoryTest extends TestCase
{
    private ConnectionInterface $connection;
    private string $tableName = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tableName = 'domain_messages_uuid';

        $manager = new Manager;
        $manager->addConnection(
            [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => '3306',
                'database' => 'outbox_messages',
                'username' => 'username',
                'password' => 'password',
                'charset' => 'utf8',
                'collation' => 'utf8_unicode_ci',
            ]
        );

        $this->connection = $manager->getConnection();
        $this->connection->table('domain_messages_uuid')->truncate();
    }

    protected function messageRepository(): IlluminateUuidV4MessageRepository
    {
        return new IlluminateUuidV4MessageRepository(
            $this->connection, $this->tableName, new ConstructingMessageSerializer(),
        );
    }

    protected function createMessage(string $value): Message
    {
        $message = new Message(new DummyEvent($value));

        return (new DefaultHeadersDecorator())->decorate($message);
    }

    /**
     * @test
     */
    public function inserting_and_retrieving_messages(): void
    {
        $aggregateRootId = DummyAggregateRootId::generate();
        $repository = $this->messageRepository();
        $eventUuid = Uuid::uuid4()->toString();
        $message1 = $this->createMessage('one')->withHeader(Header::AGGREGATE_ROOT_ID, $aggregateRootId)->withHeader(
                Header::AGGREGATE_ROOT_VERSION,
                0
            );
        $message2 = $this->createMessage('two')->withHeaders(
            [
                Header::AGGREGATE_ROOT_ID => $aggregateRootId,
                Header::EVENT_ID => $eventUuid,
                Header::AGGREGATE_ROOT_VERSION => 1,
            ]
        );

        $repository->persist($message1, $message2);
        /** @var list<Message> $messages */
        $messages = iterator_to_array($repository->retrieveAll($aggregateRootId));

        self::assertCount(2, $messages);
        self::assertEquals($aggregateRootId, $messages[0]->aggregateRootId());
        self::assertIsString($messages[0]->header(Header::EVENT_ID));
        self::assertEquals($eventUuid, $messages[1]->header(Header::EVENT_ID));
    }

    /**
     * @test
     */
    public function retrieving_messages_after_a_version(): void
    {
        $aggregateRootId = DummyAggregateRootId::generate();
        $repository = $this->messageRepository();
        $message1 = $this->createMessage('one')->withHeader(Header::AGGREGATE_ROOT_ID, $aggregateRootId);
        $message2 = $this->createMessage('two')->withHeaders(
            [
                Header::AGGREGATE_ROOT_ID => $aggregateRootId,
                Header::AGGREGATE_ROOT_VERSION => 3,
            ]
        );

        $repository->persist($message1, $message2);
        /** @var list<Message> $messages */
        $messages = iterator_to_array($repository->retrieveAllAfterVersion($aggregateRootId, 2));
        $noMessages = iterator_to_array($repository->retrieveAllAfterVersion($aggregateRootId, 3));

        self::assertCount(1, $messages);
        self::assertCount(0, $noMessages);
    }

    /**
     * @tes
     */
    public function inserting_no_messages(): void
    {
        $aggregateRootId = DummyAggregateRootId::generate();
        $repository = $this->messageRepository();

        $repository->persist();
        $messages = iterator_to_array($repository->retrieveAll($aggregateRootId));

        self::assertCount(0, $messages);
    }

    /**
     * @test
     */
    public function failing_to_persist_messages(): void
    {
        $this->tableName = 'invalid';
        $repository = $this->messageRepository();

        self::expectException(UnableToPersistMessages::class);

        $message = $this->createMessage('one')->withHeader(Header::AGGREGATE_ROOT_ID, DummyAggregateRootId::generate());
        $repository->persist($message);
    }

    /**
     * @test
     */
    public function failing_to_retrieve_all_messages(): void
    {
        $this->tableName = 'invalid';
        $repository = $this->messageRepository();

        self::expectException(UnableToRetrieveMessages::class);

        $repository->retrieveAll(DummyAggregateRootId::generate());
    }

    /**
     * @test
     */
    public function failing_to_retrieve_messages_after_version(): void
    {
        $this->tableName = 'invalid';
        $repository = $this->messageRepository();

        self::expectException(UnableToRetrieveMessages::class);

        $repository->retrieveAllAfterVersion(DummyAggregateRootId::generate(), 5);
    }

    /**
     * @test
     */
    public function interting_no_messages(): void
    {
        $aggregateRootId = DummyAggregateRootId::generate();
        $repository = $this->messageRepository();

        $repository->persist();
        $messages = iterator_to_array($repository->retrieveAll($aggregateRootId));

        self::assertCount(0, $messages);;
    }
}
