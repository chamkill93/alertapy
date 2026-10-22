# Modelo temporal

AlertaPY distingue:

- `occurred_at`: momento del hecho, nullable.
- `published_at`: momento declarado por la fuente.
- `detected_at`: momento de detección por AlertaPY.
- `first_seen_at` y `last_seen_at`: primera y última evidencia observada.
- `resolved_at`: resolución respaldada por evidencia/regla compatible.
- `expired_at`: fin de vigencia por política temporal.

`occurred_at_accuracy` admite `exact`, `approximate`, `date_only`, `relative_parsed` y `unknown`. `temporal_evidence_json` conserva la expresión original y el resultado del parser.

El parser reconoce expresiones acotadas como “ayer”, “anoche”, “esta madrugada” y “hace N horas/minutos” tomando `published_at` como referencia. Si la expresión es ambigua, `occurred_at` queda nulo. El frontend muestra por separado “Ocurrió” y “Publicado”; nunca presenta la publicación como ocurrencia.

Las ventanas de freshness se configuran por categoría en `config/alertapy_freshness.php`.
