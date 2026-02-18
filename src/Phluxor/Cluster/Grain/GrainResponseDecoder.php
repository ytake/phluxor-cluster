<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Grain;

use Google\Protobuf\Internal\Message;
use Phluxor\Cluster\Exception\GrainResponseDecodeException;
use Phluxor\Cluster\ProtoBuf\GrainResponse;

final class GrainResponseDecoder
{
    /**
     * @throws GrainResponseDecodeException
     */
    public function decode(GrainResponse $response): Message
    {
        $typeName = $response->getMessageTypeName();
        if ($typeName === '') {
            throw new GrainResponseDecodeException('message type name is empty');
        }

        if (!class_exists($typeName)) {
            throw new GrainResponseDecodeException('message class not found: ' . $typeName);
        }

        if (!is_subclass_of($typeName, Message::class)) {
            throw new GrainResponseDecodeException(
                'message class must extend ' . Message::class . ': ' . $typeName
            );
        }

        /** @var Message $message */
        $message = new $typeName();
        $message->mergeFromString($response->getMessageData());

        return $message;
    }
}
