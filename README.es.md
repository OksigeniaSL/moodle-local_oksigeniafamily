# Oksigenia Family (local_oksigeniafamily)

Una ficha por hogar para Moodle: los adultos y sus hijos alumnos, al día con los datos del propio centro, y que se
cierra sola cuando un alumno se va.

Los plugins que trabajan con familias (un comedor escolar, avisos por WhatsApp, una biblioteca escolar, una app móvil)
leen la misma ficha en lugar de llevar cada uno su lista de quién es familia de quién. Ninguno depende de ella: cada uno
funciona sin este plugin y lo usa si está instalado. Por sí solo no enseña nada a las familias; cada plugin enseña lo
suyo.

**Estado:** primeras fases. El diseño se está acordando con los plugins que lo usarán. La primera versión trae las
fichas, cómo se rellenan (una importación con los datos del centro y un código por alumno que reclama la familia) y la
baja automática.

## Requisitos

- Moodle 4.5 o posterior (con soporte: 4.5 y 5.3).

## Instalación

Copia el plugin en `local/oksigeniafamily` de tu Moodle y termina la actualización desde *Administración del sitio*, o
instala el ZIP desde *Administración del sitio ▸ Extensiones ▸ Instalar extensiones*.

## Licencia

GNU GPL v3 o posterior. Ver [LICENSE](LICENSE).
