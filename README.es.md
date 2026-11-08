# Oksigenia Family (local_oksigeniafamily)

Una ficha por hogar para Moodle: los adultos y sus hijos alumnos, al día con los datos del propio centro, y que se
cierra sola cuando un alumno se va.

Los plugins que trabajan con familias (un comedor escolar, avisos por WhatsApp, una biblioteca escolar, una app móvil)
leen la misma ficha en lugar de llevar cada uno su lista de quién es familia de quién. Ninguno depende de ella: cada uno
funciona sin este plugin y lo usa si está instalado. Aparte de las autorizaciones, por sí solo no enseña nada a las
familias; cada plugin enseña lo suyo.

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

## Autorizaciones

Una salida, las fotos del curso, irse solo a casa: el centro pide permiso a las familias de algunos alumnos y cada adulto
contesta «Autorizo» o «No autorizo» por donde le llegue la petición.

- **Quién la pide.** Quien tenga `local/oksigeniafamily:requestconsent`: el profesor en su curso (*Más ▸
  Autorizaciones*), para todo el curso o uno de sus grupos; quien lleva el sitio, para un curso o una cohorte, en
  *Administración del sitio ▸ Usuarios ▸ Cuentas ▸ Autorizaciones*. Una petición lleva un título, un texto, una circular
  si se quiere y una fecha límite. Basta con un adulto de cada alumno, o se puede pedir que contesten todos; un «no» de
  cualquiera cuenta como no.
- **Cómo llega a las familias.** A los adultos con cuenta, una notificación de Moodle, en la app, por correo o en el
  sitio, según haya elegido cada uno. A los que no tienen cuenta, un correo con un enlace personal, firmado por el
  sitio, a una página donde contestan sin entrar. Con Oksigenia Marandu, además, una pregunta con dos botones por
  WhatsApp o Telegram a quien se haya apuntado allí. La víspera de la fecha límite, a quien no ha contestado le llega un
  recordatorio.
- **Lo que ve el centro.** Cuántos han dicho que sí y que no y quién falta, cada alumno con lo que ha contestado cada
  adulto, cuándo y por dónde; un botón para recordárselo a los que faltan, los alumnos nuevos del curso añadidos después,
  las respuestas en papel anotadas y todo el historial para descargar en CSV o PDF.
- **El registro.** Una respuesta nunca se cambia: cambiar de idea añade otra, y lo que se guarda es el historial. Una
  petición se borra con sus respuestas un número de días después de su fecha límite (365 de serie, en cada petición).

## En la app móvil

Las páginas del centro son para su personal, en un navegador, también desde el móvil. En la app de Moodle, las familias
contestan las autorizaciones en una página de la propia app, que se abre desde su notificación, desde el menú
(*Autorizaciones*, para quien tenga alguna por contestar) y, en Oksigenia Aty, desde la página de cada hijo. Lo demás lo
enseña cada plugin que trabaja con familias.

Con Oksigenia Tembiapo («Tu trabajo» en el área personal), quien lleva las fichas de familias tiene una puerta a ellas
con sus cifras, y quien pide autorizaciones, una a las suyas, con aviso el mismo día de las que se cierran con alumnos
todavía sin respuesta.

## Requisitos

- Moodle 4.5 o posterior (con soporte: 4.5 y 5.3).

## Instalación

Copia el plugin en `local/oksigeniafamily` de tu Moodle y termina la actualización desde *Administración del sitio*, o
instala el ZIP desde *Administración del sitio ▸ Extensiones ▸ Instalar extensiones*.

## Licencia

GNU GPL v3 o posterior. Ver [LICENSE](LICENSE).
