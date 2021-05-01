<?php

namespace EventSauce\MessageOutbox\Illuminate;

use EventSauce\EventSourcing\Message;
use EventSauce\EventSourcing\Serialization\MessageSerializer;
use EventSauce\MessageOutbox\OutboxMessageRepository;
use Illuminate\Database\ConnectionInterface;
use Traversable;

use function array_map;
use function json_decode;
use function json_encode;

class IlluminateOutboxMessageRepository implements OutboxMessageRepository
{
    const ILLUMINATE_OUTBOX_MESSAGE_ID = '__illuminate_outbox.message_id';

    public function __construct(
        private ConnectionInterface $connection,
        private string $tableName,
        private MessageSerializer $serializer
    ) {
    }

    public function persist(Message ...$messages): void
    {
        $inserts = array_map(function (Message $message) {
            return ['payload' => json_encode($this->serializer->serializeMessage($message))];
        }, $messages);

        $this->connection->table($this->tableName)->insert($inserts);
    }

    public function retrieveBatch(int $batchSize): Traversable
    {
        $results = $this->connection->table($this->tableName)
            ->where('consumed', false)
            ->select()
            ->limit($batchSize)
            ->offset(0)
            ->get();

        foreach ($results as $row) {
            $payload = json_decode($row->payload, true);
            $message = $this->serializer->unserializePayload($payload);

            yield $message->withHeader(self::ILLUMINATE_OUTBOX_MESSAGE_ID, (int) $row->id);
        }
    }

    public function markConsumed(Message ...$messages): void
    {
        $ids = array_map(
            fn(Message $message) => (int) $message->header(self::ILLUMINATE_OUTBOX_MESSAGE_ID),
            $messages,
        );

        $this->connection->table($this->tableName)
            ->whereIn('id', $ids)
            ->update(['consumed' => true]);
    }
}
