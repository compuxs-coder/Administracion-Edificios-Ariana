<x-mail::message>
# {{ $titulo }}

**{{ $edificio }}**

<div style="white-space: pre-line">{{ $cuerpo }}</div>

@if (count($documentos) > 0)
## Documentos

Los enlaces son personales y temporales. No los comparta con terceros.

@foreach ($documentos as $documento)
<x-mail::button :url="$documento['url']">
Descargar {{ $documento['name'] }}
</x-mail::button>
@endforeach
@endif

Este es un comunicado operativo enviado por Administración Ariana.
</x-mail::message>
