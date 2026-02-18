<?php

declare(strict_types=1);

namespace Phluxor\Cluster\Automanaged;

final readonly class NodeModel
{
    /**
     * @param list<string> $kinds
     */
    public function __construct(
        public string $id,
        public string $address,
        public int $port,
        public int $autoManagePort,
        public array $kinds,
        public string $clusterName,
    ) {
    }

    public function toJson(): string
    {
        return json_encode([
            'id' => $this->id,
            'address' => $this->address,
            'port' => $this->port,
            'autoManagePort' => $this->autoManagePort,
            'kinds' => $this->kinds,
            'clusterName' => $this->clusterName,
        ], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        if (
            !isset($data['id'], $data['address'], $data['port'], $data['autoManagePort'], $data['kinds'], $data['clusterName'])
        ) {
            return null;
        }

        return new self(
            id: (string) $data['id'],
            address: (string) $data['address'],
            port: (int) $data['port'],
            autoManagePort: (int) $data['autoManagePort'],
            kinds: array_values(array_map('strval', (array) $data['kinds'])),
            clusterName: (string) $data['clusterName'],
        );
    }
}
