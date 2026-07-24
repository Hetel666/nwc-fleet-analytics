<?php

namespace App\Data;

use Carbon\CarbonInterface;

class UnitPositionData
{
    public function __construct(
        public readonly int $equipmentId,
        public readonly ?string $externalUnitId,
        public readonly mixed $latitude,
        public readonly mixed $longitude,
        public readonly mixed $positionAt,
        public readonly mixed $speed = null,
        public readonly string $source = 'local_last_position',
        public readonly mixed $receivedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $position
     */
    public static function fromStoredPosition(int $equipmentId, ?string $externalUnitId, ?array $position): ?self
    {
        if (! is_array($position)) {
            return null;
        }

        return new self(
            equipmentId: $equipmentId,
            externalUnitId: $externalUnitId,
            latitude: $position['lat'] ?? null,
            longitude: $position['lng'] ?? null,
            positionAt: $position['time'] ?? null,
            speed: $position['speed'] ?? null,
            source: 'local_last_position',
            receivedAt: $position['received_at'] ?? null,
        );
    }

    /**
     * @return array{lat: mixed, lng: mixed, speed: mixed, time: mixed, source: string, received_at: mixed}
     */
    public function toMonitoringPayload(): array
    {
        return [
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'speed' => $this->speed,
            'time' => $this->positionAt instanceof CarbonInterface
                ? $this->positionAt->toDateTimeString()
                : $this->positionAt,
            'source' => $this->source,
            'received_at' => $this->receivedAt,
        ];
    }
}
