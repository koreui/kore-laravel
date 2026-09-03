# Patrones del boilerplate

**TL;DR**: aquí se documenta una solución **cuando aparece por tercera vez**.
Una vez es un caso, dos es una coincidencia, tres es un patrón. Un patrón que
nace en un proyecto derivado vuelve al padre por PR, con su verificador y su
entrada en el CHANGELOG.

Esto no es el catálogo de reglas. Una regla dice **qué no se puede hacer** y
alguien la verifica ([`../architecture/rules.md`](../architecture/rules.md)); un
patrón dice **cómo se hace lo que sí** y no falla el build. Los dos se citan
entre sí: casi todo patrón existe porque una o varias reglas empujan en esa
dirección.

## La regla de tres

Una solución sube a este directorio cuando cumple **las tres**:

1. **Ha aparecido tres veces.** Tres sitios reales del código, cada uno con su
   ruta. No cuentan las tres «hipotéticas» ni las tres del mismo commit: la
   gracia es que hayan aparecido por separado, sin que nadie las coordinara.
2. **Las tres se parecen de verdad.** Si la tercera necesita media página de
   excepciones para encajar, todavía no es un patrón: son dos casos y un primo
   lejano.
3. **Escribirla ahorra algo.** Que el cuarto que lo necesite no tenga que
   redescubrirlo leyendo los tres anteriores. Si la solución cabe en el nombre
   de una función, no hace falta un doc.

**Por qué tres y no dos.** Con dos apariciones no se sabe qué parte es el patrón
y qué parte es el caso: se acaba abstrayendo lo que las dos comparten, que
muchas veces es lo accidental. La tercera es la que enseña dónde está la
frontera. Y es barata: hasta entonces, el código está duplicado y localizable
con un `grep`, que es un problema mucho más pequeño que una abstracción
equivocada con tres usuarios.

Antes de la tercera: no hagas nada. Ni un helper «por si acaso», ni una carpeta
nueva (R3 no lo permite de todas formas), ni un doc.

## De un proyecto hijo al padre

Un patrón que nace en un derivado **vuelve al boilerplate**. Es el sentido de
tener un padre: lo que se aprende resolviendo un problema real en un proyecto lo
heredan los demás sin volver a pagarlo.

La receta completa —remoto, rama, qué pide el review— está en
[`../ops/upgrading-from-boilerplate.md`](../ops/upgrading-from-boilerplate.md).
En corto: se porta en genérico (sin nombres de tu dominio), se abre PR contra
`kore-laravel`, y si el patrón trae una regla nueva, la regla va con su
verificador y su entrada en el CHANGELOG (R42). Un patrón que sólo tiene sentido
con tus tablas y tus roles no es un patrón del boilerplate: es tuyo, y se queda
en tu `docs/`.

## Formato de un patrón

Un archivo por patrón, `docs/patterns/{nombre-en-kebab}.md`, con estas seis
secciones y en este orden:

```markdown
# Nombre del patrón

**TL;DR**: dos o tres líneas.

## Contexto      · dónde aparece y con qué restricciones
## Problema      · qué se rompe si no se hace así (el modo de fallo concreto)
## Solución      · la forma, con código real y mínimo
## Dónde está    · las rutas del código que lo implementan hoy
## Las tres apariciones · qué lo justificó, con versión y archivo
## Reglas relacionadas  · las `R{n}` que empujan hacia este patrón
```

«Las tres apariciones» no es decoración: es la prueba de que el patrón cumple la
regla de tres, y es lo que permite borrarlo el día que las tres desaparezcan.
Como en las cicatrices del catálogo de reglas, se escriben con su versión.

Y como todo doc: entra en el índice de [`../README.md`](../README.md) en el
mismo commit, o `composer arch` falla por R40.

## Catálogo

| Patrón | Cuándo | Reglas |
|--------|--------|--------|
| [`toggle-provider.md`](toggle-provider.md) | un módulo o paquete opcional que se enciende con una clave de `config/kore-app.php` | R10, R11, R12 |
| [`test-con-otro-entorno.md`](test-con-otro-entorno.md) | un test que necesita arrancar la aplicación con otras variables de entorno | R10, R11, R17 |
