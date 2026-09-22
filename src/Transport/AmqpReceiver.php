<?php

declare(strict_types=1);

namespace Jwage\PhpAmqpLibMessengerBundle\Transport;

use LogicException;
use Override;
use PhpAmqpLib\Exception\AMQPExceptionInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\QueueReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

class AmqpReceiver implements QueueReceiverInterface, MessageCountAwareInterface
{
    public function __construct(
        private Connection $connection,
        private SerializerInterface $serializer,
    ) {
    }

    /**
     * $fetchSize is the hint the Symfony 8.1+ worker passes. It is accepted and ignored: the consumer
     * hands over everything the broker has delivered so far, and stopping part-way through that
     * buffer would leave the rest unacked and invisible to this worker until the channel closes.
     *
     * @return iterable<Envelope>
     */
    #[Override]
    public function get(int $fetchSize = 1): iterable
    {
        yield from $this->getFromQueues($this->connection->getQueueNames(), $fetchSize);
    }

    /**
     * @param array<string> $queueNames
     *
     * @return iterable<Envelope>
     */
    #[Override]
    public function getFromQueues(array $queueNames, int $fetchSize = 1): iterable
    {
        foreach ($queueNames as $queueName) {
            yield from $this->getEnvelopes($queueName);
        }
    }

    /**
     * @throws TransportException
     * @throws LogicException
     * @throws Throwable
     */
    #[Override]
    public function ack(Envelope $envelope): void
    {
        $amqpEnvelope = $this->findAMQPReceivedStamp($envelope)->getAmqpEnvelope();
        $amqpEnvelope->ack();
    }

    /**
     * @throws TransportException
     * @throws LogicException
     * @throws Throwable
     */
    #[Override]
    public function reject(Envelope $envelope): void
    {
        $amqpEnvelope = $this->findAMQPReceivedStamp($envelope)->getAmqpEnvelope();
        $amqpEnvelope->nack();
    }

    /** @throws TransportException */
    #[Override]
    public function getMessageCount(): int
    {
        try {
            return $this->connection->countMessagesInQueues();
        } catch (AMQPExceptionInterface $e) {
            throw new TransportException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return iterable<Envelope>
     *
     * @throws MessageDecodingFailedException
     * @throws TransportException
     * @throws Throwable
     */
    private function getEnvelopes(string $queueName): iterable
    {
        $amqpEnvelopes = $this->connection->consume($queueName);

        foreach ($amqpEnvelopes as $amqpEnvelope) {
            $body = $amqpEnvelope->getBody();

            /** @var array<string, string> $headers */
            $headers = $amqpEnvelope->getHeaders();

            try {
                $envelope = $this->serializer->decode([
                    'body' => $body,
                    'headers' => $headers,
                ]);
            } catch (MessageDecodingFailedException $e) {
                $amqpEnvelope->nack();

                throw $e;
            }

            if (($messageId = $amqpEnvelope->getMessageId()) !== null) {
                $envelope = $envelope
                    ->withoutAll(TransportMessageIdStamp::class)
                    ->with(new TransportMessageIdStamp($messageId));
            }

            yield $envelope->with(new AmqpReceivedStamp($amqpEnvelope, $queueName));
        }
    }

    /** @throws LogicException */
    private function findAMQPReceivedStamp(Envelope $envelope): AmqpReceivedStamp
    {
        $amqpReceivedStamp = $envelope->last(AmqpReceivedStamp::class);

        if ($amqpReceivedStamp === null) {
            throw new LogicException('No "AMQPReceivedStamp" stamp found on the Envelope.');
        }

        return $amqpReceivedStamp;
    }
}
