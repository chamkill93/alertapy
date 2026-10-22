# Motor de decisión OpenCode

## Estado

Este documento define el contrato de la Fase 5. La Fase 1 prepara `content_candidates` y variables de entorno, pero todavía no llama a OpenCode ni publica candidatos.

## Límite de seguridad

OpenCode vive fuera de Hostinger. Laravel enviará evidencia por HTTPS a `OPENCODE_DECISION_URL` usando un bearer token. El servicio externo:

- no recibe credenciales de MariaDB;
- no ejecuta SQL;
- no accede al hosting;
- no modifica registros;
- devuelve solamente una recomendación JSON.

Laravel valida y aplica la recomendación dentro de una transacción. El token nunca se escribe en logs.

## Entrada prevista

El paquete de evidencia incluirá:

- identificadores opacos de candidato;
- tipo, nombre y dominio de la fuente;
- URL y título originales;
- contenido relevante sin HTML ejecutable;
- fechas de publicación y detección;
- ubicación detectada y precisión disponible;
- incidentes cercanos potencialmente duplicados;
- candidatos relacionados y fuentes adicionales;
- cantidad de reportes ciudadanos coincidentes;
- estado anterior si se trata de una actualización.

No se envían secretos, credenciales de base de datos, IP pública en claro o archivos ejecutables.

## Salida estricta

La única respuesta admitida será un objeto JSON:

```json
{
  "decision": "PUBLISH",
  "category": "traffic_accident",
  "subcategory": "traffic_collision",
  "title": "Accidente de tránsito",
  "summary": "Accidente reportado sobre la Ruta PY02.",
  "severity": "medium",
  "verification": "press",
  "department": "Central",
  "city": "Ypacaraí",
  "neighborhood": null,
  "location_text": "Pedrozo",
  "location_confidence": 0.92,
  "confidence": 0.94,
  "duplicate_incident_id": null,
  "expires_at": null,
  "safety_flags": [],
  "reason": "Hecho territorial reciente con fuente periodística rastreable."
}
```

Decisiones permitidas: `PUBLISH`, `MERGE`, `WAIT`, `REJECT`, `CLOSE`. No se aceptan texto adicional, markdown, campos con tipos distintos, confianza fuera de 0–1 o un `duplicate_incident_id` inexistente.

## Fallo seguro

Timeout, error HTTP, JSON inválido, decisión desconocida o incompatibilidad de campos producen `WAIT`. Se incrementa `attempts`, se calcula `next_retry_at` y no se crea ni modifica contenido público.

## Guardrails finales de PHP

Incluso ante `PUBLISH`, Laravel rechazará o mantendrá en espera si falta ubicación mínima, la fecha no es válida, una URL de prensa no usa el dominio registrado, las coordenadas están fuera de Paraguay, hay una acusación personal, la fuente no es rastreable o la decisión contradice la evidencia.

El prompt operativo está en [opencode-moderator-prompt.md](opencode-moderator-prompt.md).
