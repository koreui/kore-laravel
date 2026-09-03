<!--
  Este boilerplate se lee en español. Borra lo que no aplique, pero no borres
  la checklist: es lo que el review da por hecho.
-->

## Qué cambia

<!-- Una o dos frases. Qué hace ahora que no hacía antes, o al revés. -->

## Por qué

<!-- El problema real. Si hubo un incidente, cuéntalo: es la «cicatriz» que
     acaba en docs/architecture/rules.md si el cambio trae regla nueva. -->

## Reglas afectadas

<!-- Cita por número las reglas del catálogo (docs/architecture/rules.md) que
     este PR toca, refuerza o relaja. Por ejemplo: R11, R23.
     Si no toca ninguna, escribe «ninguna». -->

- R…

## Cómo se probó

<!-- Comandos concretos y qué salió. Si hay UI, di qué navegaste. -->

```
```

## Checklist

- [ ] `composer ci` en verde (Pint · Larastan + PHPat + disallowed-calls · `composer arch` · Rector · Pest).
- [ ] `npm run e2e` en verde **si el PR toca rutas, vistas, Livewire o permisos**; si añade una pantalla, trae su spec en `tests/e2e/specs/{modulo}/` (R36).
- [ ] La documentación afectada está actualizada **en este mismo PR** (R40), y todo doc nuevo aparece en `docs/README.md`.
- [ ] `CHANGELOG.md` tiene la entrada en `[Unreleased]`, con nota de migración si un proyecto derivado tiene que hacer algo (R42).
- [ ] `AGENTS.md` regenerado con `php artisan kore:agents:sync` si se tocó `CLAUDE.md` (R50).
- [ ] **Ninguna válvula de escape nueva sin `@owner`** —y ninguna escrita por un agente— (R44). Si hay una, dilo aquí y explica por qué.
- [ ] Los commits siguen Conventional Commits (R43); el hook `commit-msg` lo verifica.

## Válvulas o excepciones introducidas

<!-- Pega aquí cada `arch-exception` / `arch-accepted` / `@phpstan-ignore` /
     `allowIn` que añade este PR, con su dueño y su fecha. Si no hay ninguna,
     escribe «ninguna». Un PR que añade una válvula en silencio se devuelve. -->

ninguna
