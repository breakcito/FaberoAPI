<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que se dispara cuando se activa o desactiva el modo auditoría.
 */
class ModoAuditoriaToggled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Determina si el modo auditoría está activo.
     *
     * @var bool
     */
    public $en_modo_auditable;

    /**
     * Create a new event instance.
     */
    public function __construct(bool $en_modo_auditable)
    {
        $this->en_modo_auditable = $en_modo_auditable;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('global-audit-mode'),
        ];
    }

    /**
     * Nombre del evento para el frontend.
     */
    public function broadcastAs(): string
    {
        return 'audit.mode.toggled';
    }
}
