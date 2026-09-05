# Manual de usuario

Cada guía es un recorrido completo por una parte de la aplicación, explicado
paso a paso y con una captura de pantalla real por paso.

**Las capturas no se toman a mano.** Salen de recorridos ejecutados contra la
aplicación de verdad (`tests/e2e/manual/*.guia.ts`), con el navegador pulsando
donde pulsaría una persona. Si la pantalla cambia y el recorrido deja de
encontrar lo que buscaba, la generación falla y el manual avisa — que es la
única forma de que un manual con imágenes no envejezca mintiendo.

> **Este archivo es lo único del manual que se versiona.** Las guías y sus
> capturas son artefactos: se generan en local o en CI y están en `.gitignore`.
> Si has llegado aquí desde un clon recién hecho, los enlaces de abajo no
> apuntan a nada todavía: genera el manual y aparecen.

Para generarlo:

```bash
npm run manual        # recorridos + capturas + Markdown
npm run manual:pdf    # el manual entero en un PDF (necesita Gotenberg)
```

Cómo se escribe una guía y qué hace cada pieza: [`../quality/manual.md`](../quality/manual.md).

## Guías

| Guía | Para quién |
|---|---|
| [Gestionar usuarios](./01-usuarios.md) | Para quien administra las cuentas: dar de alta, editar y encontrar personas. |

---

<sub>Todos los nombres y correos que aparecen en las capturas son ficticios y
usan el dominio reservado `.test`. Ni un solo dato proviene de una persona
real.</sub>
