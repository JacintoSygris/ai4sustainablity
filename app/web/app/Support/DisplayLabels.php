<?php

namespace App\Support;

/** Closed machine enums only. User-authored text is never passed to this boundary. */
final class DisplayLabels
{
    private const LABELS = [
        'draft' => ['Borrador', 'Draft'],
        'submitted' => ['Enviado', 'Submitted'],
        'queued' => ['En cola', 'Queued'],
        'processing' => ['En proceso', 'Processing'],
        'waiting' => ['En espera', 'Waiting'],
        'failed' => ['Fallido', 'Failed'],
        'timed_out' => ['Tiempo agotado', 'Timed out'],
        'completed' => ['Completado', 'Completed'],
        'complete' => ['Completo', 'Complete'],
        'ready' => ['Listo', 'Ready'],
        'blocked' => ['Bloqueado', 'Blocked'],
        'incomplete' => ['Incompleto', 'Incomplete'],
        'in_progress' => ['En curso', 'In progress'],
        'missing' => ['Pendiente', 'Pending'],
        'not_started' => ['Sin empezar', 'Not started'],
        'not_implemented' => ['No disponible en esta versión', 'Unavailable in this version'],
        'generation_pending' => ['Pendiente de generación', 'Awaiting generation'],
        'scoping_only' => ['Modo alcance', 'Scoping mode'],
        'confirmed' => ['Confirmado', 'Confirmed'],
        'not_confirmed' => ['Sin confirmar', 'Not confirmed'],
        'fresh' => ['Vigente', 'Current'],
        'stale' => ['Desactualizado', 'Out of date'],
        'approved' => ['Aprobado', 'Approved'],
        'review_required' => ['Revisión requerida', 'Review required'],
        'reviewed' => ['Revisado', 'Reviewed'],
        'pending' => ['Pendiente', 'Pending'],
        'not_applicable' => ['No aplica', 'Not applicable'],
        'unavailable' => ['No disponible', 'Unavailable'],
    ];

    public static function code(?string $code, ?string $locale = null): string
    {
        $labels = self::LABELS[$code ?? ''] ?? ['Estado no disponible', 'Status unavailable'];

        return $labels[ApplicationLocale::normalize($locale ?? app()->getLocale()) === 'en' ? 1 : 0];
    }
}
