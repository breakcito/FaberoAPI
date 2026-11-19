<?php

namespace App\Events;

use App\Shared\Enums\_Generic\OrigenUnidadParaPesar;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Evento que avisa que el conjunto de unidades disponibles para pesar cambió.
 *
 * Se dispara cuando:
 *  - se registra una recepción de unidad (ingreso a planta),
 *  - se confirma una programación de recepción,
 *  - se confirma una programación de despacho.
 *
 * La balanza (módulo de recepción de mineral) escucha este evento para
 * refrescar sola la lista de unidades a weighar, sin que el operador
 * tenga que recargar la página.
 *
 * Se difunde de forma síncrona (ShouldBroadcastNow) a propósito: no depende
 * de que haya un worker de cola levantado y el aviso llega de inmediato.
 */
class UnidadesParaPesarActualizadas implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Canal público de la balanza.
     */
    public const CANAL = 'balanza-unidades';

    /**
     * Nombre del evento en el frontend.
     */
    public const EVENTO = 'unidades.para.pesar.actualizadas';

    /**
     * Proceso que originó el cambio.
     */
    public string $origen;

    /**
     * Recepción de unidad afectada (null si no aplica).
     */
    public ?int $id_recepcion_unidad;

    /**
     * Sucursal donde quedó disponible la unidad (null si no aplica).
     */
    public ?int $id_sucursal;

    /**
     * Tipo de ingreso de la unidad (null si no aplica).
     */
    public ?string $tipo_ingreso;

    /**
     * Placa del vehículo principal de la unidad (null si no aplica).
     */
    public ?string $placa;

    public function __construct(
        OrigenUnidadParaPesar $origen,
        ?int $idRecepcionUnidad = null,
        ?int $idSucursal = null,
        ?string $tipoIngreso = null,
        ?string $placa = null,
    ) {
        $this->origen = $origen->value;
        $this->id_recepcion_unidad = $idRecepcionUnidad;
        $this->id_sucursal = $idSucursal;
        $this->tipo_ingreso = $tipoIngreso;
        $this->placa = $placa;
    }

    /**
     * Construye el evento a partir de una fila de recepción de unidad.
     *
     * @param  array<string, mixed>  $recepcion
     */
    public static function desdeRecepcion(OrigenUnidadParaPesar $origen, array $recepcion): self
    {
        return new self(
            origen: $origen,
            idRecepcionUnidad: isset($recepcion['id']) ? (int) $recepcion['id'] : null,
            idSucursal: isset($recepcion['id_sucursal']) ? (int) $recepcion['id_sucursal'] : null,
            tipoIngreso: isset($recepcion['tipo_ingreso']) ? (string) $recepcion['tipo_ingreso'] : null,
            placa: isset($recepcion['vehiculo_placa']) ? (string) $recepcion['vehiculo_placa'] : null,
        );
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel(self::CANAL),
        ];
    }

    /**
     * Nombre del evento para el frontend.
     */
    public function broadcastAs(): string
    {
        return self::EVENTO;
    }
}
