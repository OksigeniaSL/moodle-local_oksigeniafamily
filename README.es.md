# Oksigenia Family (local_oksigeniafamily)

Una ficha por hogar para Moodle: los adultos y sus hijos alumnos, al día con los datos del propio centro, y que se
cierra sola cuando un alumno se va.

Los plugins que trabajan con familias (un comedor escolar, avisos por WhatsApp, una biblioteca escolar, una app móvil)
leen la misma ficha en lugar de llevar cada uno su lista de quién es familia de quién. Ninguno depende de ella: cada uno
funciona sin este plugin y lo usa si está instalado. Por sí solo no enseña nada a las familias; cada plugin enseña lo
suyo.

**Estado:** primeras fases de desarrollo.

## Cómo entran las familias

Todo está en *Administración del sitio ▸ Usuarios ▸ Cuentas ▸ Familias*, para quien tenga el permiso
`local/oksigeniafamily:manage`, que el rol de gestor tiene de serie.

- **Desde un fichero.** Un CSV con una fila por persona: `family` y `type` (`adult` o `child`), más `firstname`,
  `lastname`, `email`, `phone`, `username`, `idnumber` y `key`. Antes de que pase nada ves lo que cambiaría, con lo que
  está mal en cada fila por su línea, y repetir un fichero no duplica nada. Un hijo necesita una cuenta de Moodle o una
  clave en tus datos. La página ofrece un fichero para empezar.
- **Con un código por alumno.** Eliges un alumno, un grupo, un curso o una cohorte e imprimes una carta por alumno, con
  el código y su QR. Cada adulto de la familia se une por su cuenta en `/local/oksigeniafamily/claim.php`, sin necesidad
  de cuenta. Los códigos solo se guardan como huella, así que una carta solo se puede imprimir desde la página que la
  saca; una carta perdida se sustituye por un código nuevo, y el anterior deja de valer.
- **A mano**, para los casos sueltos.

Los alumnos que se van se ocupan solos: cuando su cuenta se suspende o se borra dejan sus familias, y una familia sin
hijos se cierra y se borra pasados los plazos de los ajustes del plugin.

## En la app móvil

Estas páginas son para el personal del centro, en un navegador, también desde el móvil. El plugin no añade nada a la app
de Moodle: cada plugin que trabaja con familias enseña allí lo suyo.

## Requisitos

- Moodle 4.5 o posterior (con soporte: 4.5 y 5.3).

## Instalación

Copia el plugin en `local/oksigeniafamily` de tu Moodle y termina la actualización desde *Administración del sitio*, o
instala el ZIP desde *Administración del sitio ▸ Extensiones ▸ Instalar extensiones*.

## Licencia

GNU GPL v3 o posterior. Ver [LICENSE](LICENSE).
