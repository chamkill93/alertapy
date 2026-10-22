# Decisiones deterministas

El proveedor predeterminado es `rules`. Publica candidatos oficiales, sensores y prensa permitida únicamente cuando tienen URL, fecha, ubicación suficiente y pasan controles de contenido. Discovery y redes sociales esperan corroboración; acusaciones personales se rechazan. El cierre exige lenguaje de normalización y compatibilidad territorial y temporal con el incidente.

`EvidenceScorer` conserva puntaje total y componentes. La salida usa el mismo contrato `PUBLISH`, `MERGE`, `WAIT`, `REJECT` y `CLOSE`, de modo que OpenCode puede habilitarse después sin cambiar el flujo:

```dotenv
ALERTAPY_DECISION_PROVIDER=rules
OPENCODE_ENABLED=false
```

OpenCode permanece como adaptador optativo y seguro: si no está explícitamente habilitado no realiza llamadas ni bloquea el ciclo. `ALERTAPY_LOCAL_RULES_PUBLISH=false` es un interruptor de emergencia que deja candidatos en espera.
