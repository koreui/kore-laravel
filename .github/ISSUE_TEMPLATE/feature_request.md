---
name: Propuesta de mejora
about: Algo que el boilerplate debería traer hecho
title: 'feat: '
labels: enhancement
assignees: ''
---

## Qué problema resuelve

<!-- El problema, no la solución. Qué te tocó resolver a mano y cuántas veces. -->

## Cuántas veces te ha pasado

<!-- La regla de tres (docs/patterns/README.md): una vez es un caso, dos es una
     coincidencia, tres es un patrón. Si vas por la primera, cuéntalo igual —
     pero es probable que todavía no toque subirlo al boilerplate. -->

- Aparición 1 (proyecto / archivo):
- Aparición 2:
- Aparición 3:

## Cómo lo resolviste tú

<!-- Código real, aunque sea feo. Es lo que permite juzgar si generaliza. -->

```php
```

## Qué tendría que traer

- [ ] ¿Trae **toggle**? Si es una capacidad opcional, va detrás de una clave de
      `config/kore-app.php` con su lector real (R10, R11) — ver
      `docs/patterns/toggle-provider.md`.
- [ ] ¿Trae **regla nueva** en `docs/architecture/rules.md`? Entonces con su
      enunciado, su enforcement, su severidad, su válvula y su cicatriz.
- [ ] ¿Trae **verificador**? Una regla sin verificador es una sugerencia; si no
      hay forma barata de comprobarla, dilo y márcala **Manual**.
- [ ] ¿Trae **tests**? Un test Pest por Action, componente y ruta (R35); si hay
      UI, smoke + happy path + autorización (R36).
- [ ] ¿Trae **paquete nuevo**? Di cuál, su licencia y por qué no se puede sin él.

## Alternativas que consideraste

<!-- Incluida la de no hacer nada: un boilerplate crece por acumulación y cada
     pieza la hereda todo proyecto derivado. -->
