# Módulo de torneos

Módulo de GuildForge para organizar torneos vinculados a eventos: inscripción de usuarios e invitados, check-in, rondas con emparejamiento suizo, resultados con estadísticas por jugador, clasificación con reglas de puntuación y desempates configurables y perfiles de juego reutilizables.

## Características

- **Un torneo por evento**: cada torneo pertenece a un evento publicado (relación 1:1).
- **Inscripción**: usuarios con cuenta e invitados sin cuenta (si el torneo lo permite), con límite de plazas. Solo se aceptan inscripciones con el torneo en **Inscripción abierta**, dentro de las fechas de apertura y cierre si se han configurado y antes del inicio del evento. Desde el inicio del evento nadie puede inscribirse ni retirarse desde la web (tampoco con el enlace de cancelación de invitados), aunque el torneo siga en **Inscripción abierta** o **Inscripción cerrada**; desde el panel se pueden seguir añadiendo y retirando participantes. Si no se puede leer el inicio del evento, la inscripción y la retirada quedan cerradas por seguridad.
  Con la verificación de email obligatoria activa en el sitio, las cuentas a las que el core se la exige deben verificar su email antes de inscribirse, retirarse o hacer check-in; los invitados no se ven afectados.
  Mientras tanto, en la página del torneo, en la sección del torneo de la página del evento y en la página de check-in los botones de inscribirse, retirarse y hacer check-in aparecen desactivados, con el motivo debajo y un enlace «Ver cómo» a la página de verificación. Si la cuenta pasa a necesitar la verificación con la página ya abierta (por ejemplo, desde otra pestaña), al pulsar el botón se abre la página de verificación en lugar de un mensaje de error. Las cuentas que no deben verificar y los invitados ven la página igual que antes.
- **Inscripciones de invitados con confirmación por email**: un invitado solo puede tener una inscripción activa por torneo (el email se compara sin mayúsculas ni espacios) y la confirma desde un enlace antes de que la organización sepa de ella. Ver [Inscripciones de invitados](#inscripciones-de-invitados).
- **Cancelación de invitados**: enlace con token único, sin necesidad de iniciar sesión.
- **Check-in**: ventana configurable antes del evento, desde el panel o desde la web pública. Con confirmación manual solo hacen check-in los participantes confirmados; sin ella, también los inscritos (la misma regla en el panel y en la web). La web solo tiene en cuenta inscripciones activas: la del email indicado o, con sesión, la de la cuenta. Admite 10 intentos por minuto y 60 por hora y IP.
- **Rondas con emparejamiento suizo**: **Generar ronda** crea la ronda y sus partidas, con bye para el número impar de jugadores.
- **Resultados y clasificación**: resultados con estadísticas por jugador registrados desde el panel; cada resultado recalcula la clasificación.
- **Reglas configurables**: estadísticas de partida, reglas de puntuación por resultado o por estadísticas y criterios de desempate.
- **Perfiles de juego**: plantillas con estadísticas, reglas, desempates y emparejamiento; incluye perfiles del sistema.
- **Correos**: aviso al participante y al organizador al inscribirse y al cancelar la inscripción.
- **Integración con el sitio**: pestaña de torneo en el formulario de eventos, sección en la página del evento, pestaña en el perfil, página «Mis torneos» y página enlazable desde el editor de menús.

## Requisitos

- GuildForge core `^3.10`
- PHP >= 8.5, Laravel >= 13, Filament 5

El core rechaza el módulo si no se cumplen (`module.json` → `requires`).

## Instalación

1. Descarga `tournaments-X.Y.Z.zip` de la última release de `DavidMRGaona/guildforge-tournaments` en GitHub.
2. En el panel, activa el modo mantenimiento (**Configuración del sitio** → pestaña **Mantenimiento**).
3. En **Módulos**, pulsa **Instalar**, sube el ZIP y confirma con **Instalar / actualizar**.
4. Pulsa **Habilitar** en el módulo: la primera vez ejecuta sus migraciones y seeders.
5. Desactiva el modo mantenimiento.

Los perfiles del sistema (Warhammer 40.000, Magic: The Gathering, Warhammer Age of Sigmar y Genérico) se cargan al habilitar el módulo con `GameProfilesSeeder`; todos puntúan victoria 3, empate 1, derrota 0 y bye 3.

Las versiones nuevas se aplican desde **Actualizaciones de módulos** (también con el modo mantenimiento activo) o con `php artisan module:update tournaments`.

### En desarrollo

Con el repositorio clonado en `src/modules/tournaments`:

```bash
php artisan module:discover
php artisan module:enable tournaments
php artisan module:build tournaments   # compila los componentes Vue (--force para recompilar)
```

En las releases los assets los compila el CI y van dentro del ZIP.

## Configuración del módulo

En **Módulos**, la acción **Configuración** del módulo guarda los valores iniciales de los formularios que crean un torneo (**Torneos** → **Crear torneo** y la pestaña **Configuración del torneo** del evento). Cada torneo puede cambiarlos después.

| Opción | Descripción | Por defecto |
|--------|-------------|-------------|
| `default_result_reporting` | Modo de reporte de resultados | `admin_only` |
| `default_allow_guests` | Permitir invitados | No |
| `default_requires_check_in` | Requiere check-in | No |
| `default_check_in_starts_before` | Minutos antes para check-in | 30 |
| `guest_confirmation.mode` | Plaza de un invitado sin confirmar: `hold` (reserva con plazo) o `wait` (no ocupa plaza hasta confirmar) | `hold` |
| `guest_confirmation.hold_hours` | Horas para confirmar en modo `hold` | 2 |
| `guest_confirmation.max_unconfirmed_held` | Plazas sin confirmar que puede reservar un torneo a la vez | 2 |
| `guest_accounts.mode` | Email que ya tiene cuenta: `require_login` (pedir que inicie sesión) o `allow_guest` (permitir como invitado) | `require_login` |
| `guest_rate_limit.per_minute` | Inscripciones de invitado aceptadas por IP y minuto | 5 |
| `guest_rate_limit.per_hour` | Inscripciones de invitado aceptadas por IP y hora | 20 |
| `duplicate_signal.window_hours` | Horas en que dos inscripciones desde la misma conexión y navegador se marcan como posible duplicado | 24 |

Los ajustes de invitados se validan en el servidor (enteros positivos y modos conocidos).

## Gestión de torneos

En el panel, el grupo **Torneos** contiene **Torneos** y **Perfiles de torneo**.

### Flujo

1. **Crear el evento** y publicarlo: el torneo se asocia a un evento publicado.
2. **Crear el torneo** en **Torneos** → **Crear torneo** (8 pestañas, ver abajo). Se crea en estado **Borrador**.
3. **Abrir inscripción** con la acción de la cabecera al editar el torneo. Con fechas de apertura o cierre configuradas, la web solo acepta inscripciones entre ellas; el inicio del evento la cierra siempre.
4. **Cerrar inscripción** e **Iniciar torneo** (comprueba el mínimo de participantes).
5. En la pestaña de **Rondas**: **Generar ronda** (crea la ronda y sus partidas, ver [Emparejamiento y clasificación](#emparejamiento-y-clasificación)), **Iniciar ronda**, **Gestionar partidas** (resultado y estadísticas de cada partida) y **Completar ronda** (exige que todas las partidas tengan resultado).
6. **Finalizar torneo**. Se puede **Cancelar torneo** en cualquier estado salvo finalizado o cancelado.

```
[Evento publicado] → [Crear torneo: Borrador] → [Abrir inscripción] → [Cerrar inscripción]
                                                                              ↓
                       [Finalizar] ← [Rondas: generar, iniciar, resultados, completar] ← [Iniciar]
```

También se puede crear un torneo simplificado desde el propio evento: el formulario de eventos tiene la pestaña **Configuración del torneo** (activar torneo, nombre, capacidad, fechas, puntos por resultado, desempates, reporte de resultados, invitados y check-in). Los puntos por resultado (victoria, empate, derrota, bye) y los desempates (Buchholz, Buchholz mediano, progresivo, enfrentamiento directo, porcentaje de victorias de los oponentes) se guardan como reglas de puntuación y desempates del torneo. La pestaña muestra las reglas que usa la clasificación: las del torneo y, para lo que no tenga propio, las de su perfil de torneo (un aviso nombra el perfil). En un torneo con perfil, guardar solo convierte en reglas del torneo los puntos o los desempates que se hayan cambiado en la pestaña; lo que no se toca sigue saliendo del perfil, también si el perfil cambia después. Sin perfil, guarda siempre los puntos y desempates de la pestaña. Si las reglas que usa el torneo, propias o del perfil, no se pueden mostrar en la pestaña (condiciones por estadísticas; desempates de otros tipos, ascendentes, con valor mínimo, estadística o clave propia), la pestaña lo indica y no las toca: se editan en **Torneos**. Desactivar el interruptor y guardar elimina el torneo del evento.

### Pestañas del formulario

#### General

| Campo | Obligatorio | Descripción |
|-------|-------------|-------------|
| Evento asociado | Sí | Evento publicado al que pertenece; un evento solo puede tener un torneo |
| Nombre del torneo | Sí | Máximo 255 caracteres |
| Descripción | No | Máximo 1000 caracteres |
| Imagen | No | Máximo 2 MB |
| Perfil de torneo | No | Al elegirlo rellena las pestañas Estadísticas, Reglas de puntuación, Configuración de desempates y Configuración de emparejamientos; los perfiles del sistema llevan ⭐ |

#### Capacidad

| Campo | Obligatorio | Descripción |
|-------|-------------|-------------|
| Máximo de participantes | No | Vacío = ilimitado |
| Mínimo de participantes | Sí | Necesarios para iniciar (por defecto 2) |
| Número de rondas | No | Rondas previstas |

#### Fechas

| Campo | Obligatorio | Descripción |
|-------|-------------|-------------|
| Apertura de inscripción | No | Antes de esta fecha no se aceptan inscripciones |
| Cierre de inscripción | No | Desde esta fecha no se aceptan inscripciones; posterior a la apertura y no posterior al inicio del evento |

#### Estadísticas

Valores que se registran por jugador en cada partida.

| Campo | Obligatorio | Descripción |
|-------|-------------|-------------|
| Clave | Sí | Identificador (`snake_case`), por ejemplo `victory_points` |
| Nombre | Sí | Nombre visible |
| Tipo | Sí | `integer` (entero), `float` (decimal) o `boolean` (sí/no) |
| Valor mínimo / máximo | No | Límites aceptados |
| Obligatoria | No | Si hay que rellenarla al reportar |

#### Reglas de puntuación

| Campo | Obligatorio | Descripción |
|-------|-------------|-------------|
| Nombre | Sí | Por ejemplo «Victoria» |
| Puntos | Sí | Puntos que otorga (admite decimales) |
| Prioridad | No | Mayor = se evalúa antes (por defecto 0) |
| Condición | Sí | Ver tipos |

| Condición | Descripción | Campos |
|-----------|-------------|--------|
| `result` | Resultado de la partida | `win`, `draw`, `loss` o `bye` |
| `stat_comparison` | Una estadística frente a la del oponente | Estadística, operador |
| `stat_threshold` | Una estadística alcanza un umbral | Estadística, operador, valor |
| `margin_diff` | Diferencia de una estadística entre ambos jugadores | Estadística, operador, valor |

Valores iniciales: Victoria 3, Empate 1, Derrota 0, Bye 3.

#### Configuración de desempates

Se aplican en orden.

| Campo | Obligatorio | Descripción |
|-------|-------------|-------------|
| Clave | Sí | Identificador (`snake_case`) |
| Nombre | Sí | Nombre visible |
| Tipo | Sí | Ver tipos |
| Estadística | Según el tipo | Para los tipos basados en estadísticas |
| Dirección | No | Mayor es mejor / menor es mejor |
| Valor mínimo | No | Suelo del valor. En `owp`, `oowp` y `ogwp` se aplica a cada oponente antes de hacer la media (0,33 es el suelo habitual del OMW%; el módulo cuenta el empate como 0,5 y la fórmula de MTG como 1/3, así que solo dan lo mismo sin empates) |

| Tipo | Descripción |
|------|-------------|
| `buchholz` | Suma de puntos de los oponentes |
| `median_buchholz` | Buchholz sin el mejor ni el peor oponente (con 3 o más oponentes) |
| `progressive` | Suma de los puntos acumulados tras cada ronda, en orden de ronda y con las reglas de puntuación del torneo |
| `owp` | Media del porcentaje de victorias de los oponentes: puntos de partida / partidas jugadas, con victoria 1, empate 0,5 y bye como victoria |
| `oowp` | Media del `owp` de los oponentes |
| `gwp` | Juegos ganados / juegos jugados, con las puntuaciones de cada partida (una partida sin puntuación cuenta como un juego, el empate como medio y el bye como un juego ganado) |
| `ogwp` | Media del `gwp` de los oponentes |
| `head_to_head` | Puntos ganados en las partidas contra los jugadores empatados a puntos |
| `sonneborn_berger` | Puntos de los oponentes derrotados más la mitad de los puntos de los empatados |
| `stat_sum` | Suma de una estadística |
| `stat_diff` | Diferencia de una estadística |
| `stat_average` | Media de una estadística |
| `stat_max` | Máximo de una estadística |
| `sos` | Media de los puntos de los oponentes |
| `mov` | Suma de las diferencias de puntuación a favor del jugador |
| `random` | Orden aleatorio fijo para cada torneo (la primera vez que se usa se guarda una semilla, así no cambia al recalcular) |

El bye no cuenta como rival en los desempates basados en oponentes, pero sí como victoria en el porcentaje propio de quien lo recibe. El valor mínimo se aplica también al resultado final de cada desempate.

Ejemplo con valor mínimo 0,33 en `owp`: un jugador que se enfrentó a un rival con 1 victoria de 1 (100 %) y a otro con un empate y una derrota (25 %, que cuenta como 33 %) tiene un `owp` de (1 + 0,33) / 2 = 0,665. Con la fórmula de MTG, que cuenta el empate como 1/3, ese segundo rival tendría un 17 % (1 punto de 6), que también cuenta como 33 %.

Valores iniciales: Buchholz y Progresivo (mayor es mejor).

#### Configuración de emparejamientos

**Generar ronda** los aplica (los del torneo; si no tiene, los de su perfil; si tampoco, los valores por defecto).

| Campo | Valores | Por defecto |
|-------|---------|-------------|
| Método | `swiss` (empareja jugadores de puntuación similar), `random` (empareja al azar en todas las rondas) | `swiss` |
| Ordenar por | `points` (grupos de puntuación: en cada grupo, la mitad superior contra la inferior), `stat` (total acumulado de una estadística, luego puntos; empareja en orden), `random` | `points` |
| Estadística para ordenar | Obligatoria si se ordena por estadística | — |
| Evitar repeticiones | Sí: busca una ronda sin rivales repetidos y solo repite si no existe; No: empareja en orden aunque ya se hayan enfrentado | Sí |
| Máximo de byes por jugador | Número de byes que puede recibir un jugador; si todos lo han alcanzado, el bye se asigna igualmente | 1 |
| Asignación de bye | `lowest_ranked` (el peor clasificado), `highest_ranked` (el mejor), `random` (al azar), siempre entre quienes no han alcanzado el máximo | `lowest_ranked` |

Un torneo o perfil guardado con el antiguo método `accelerated`, que no tenía reglas propias, se empareja como `swiss`. Su formulario marca el campo **Método** y no se guarda hasta elegir `swiss` o `random`; el módulo no cambia esos datos por su cuenta.

#### Opciones

| Campo | Obligatorio | Descripción |
|-------|-------------|-------------|
| Reporte de resultados | Sí | `admin_only`, `players_with_confirmation` o `players_trusted` |
| Permitir invitados | No | Inscripción sin cuenta |
| Mostrar participantes | No | Lista de inscritos en la página pública |
| Requiere check-in | No | Obliga a confirmar asistencia |
| Minutos antes para check-in | Si hay check-in | Antelación sobre el inicio del evento (por defecto 30) |
| Permitir auto check-in | Si hay check-in | Check-in desde la página pública |
| Requiere confirmación manual | No | Un administrador confirma cada inscripción antes del check-in |
| Email de notificaciones | Sí | Recibe los avisos de inscripciones y cancelaciones |

## Estados

### Torneo

| Estado | Valor | Siguiente estado posible |
|--------|-------|--------------------------|
| Borrador | `draft` | `registration_open`, `cancelled` |
| Inscripción abierta | `registration_open` | `registration_closed`, `cancelled` |
| Inscripción cerrada | `registration_closed` | `in_progress`, `cancelled` |
| En curso | `in_progress` | `finished`, `cancelled` |
| Finalizado | `finished` | — |
| Cancelado | `cancelled` | — |

Solo se aceptan inscripciones en `registration_open`, dentro de las fechas configuradas y antes del inicio del evento. Retirarse desde la web es posible hasta el inicio del evento mientras el torneo no esté en curso, finalizado o cancelado. El panel permite **Iniciar torneo** también desde `registration_open`.

### Participante

| Estado | Valor | Siguiente estado posible |
|--------|-------|--------------------------|
| Inscrito | `registered` | `confirmed`, `withdrawn` |
| Confirmado | `confirmed` | `checked_in`, `withdrawn`, `disqualified` |
| Registrado (check-in hecho) | `checked_in` | `withdrawn`, `disqualified` |
| Retirado | `withdrawn` | `registered` (se vuelve a inscribir) |
| Caducada | `expired` | — (el invitado puede volver a apuntarse) |
| Descalificado | `disqualified` | — |

Desde el panel (pestaña **Participantes**, con el permiso `tournaments.manage_participants`) se añaden participantes (inscritos, confirmados o con check-in hecho) y se confirman, se hace check-in, se retiran o se descalifican; confirmar, check-in y descalificar también funcionan en bloque.

### Resultado de partida

`player_one_win`, `player_two_win`, `draw`, `double_loss`, `bye` y `not_played` (valor inicial).

## Emparejamiento y clasificación

**Generar ronda** solo está disponible con el torneo **En curso** y se niega (con un aviso) si:

- la ronda anterior tiene partidas sin resultado o no se ha completado,
- el torneo ya jugó su **Número de rondas**,
- hay menos de 2 participantes confirmados o con check-in hecho (solo ellos se emparejan),
- otra sesión acaba de generar la misma ronda.

Al generarla:

1. La primera ronda se empareja al azar; las siguientes, según la [configuración de emparejamientos](#configuración-de-emparejamientos). Por defecto (`swiss` por puntos), los jugadores se agrupan por puntos y se ordenan por su posición en la clasificación; en cada grupo de *2k* jugadores, el 1.º se enfrenta al *k+1*.º, el 2.º al *k+2*.º y así sucesivamente. Si un grupo es impar, su último jugador baja al grupo siguiente. Si un emparejamiento repetiría rivales, se prueba con el siguiente rival en ese orden, y solo se repite si no hay ninguna ronda posible sin repeticiones.
2. Con un número impar de jugadores, uno recibe bye según la configuración (por defecto, el peor clasificado que aún no lo haya tenido): su partida queda con resultado `bye` y puntúa al momento.
3. Las partidas se numeran por mesa y la ronda queda **Pendiente**.

Cada resultado registrado desde **Gestionar partidas** (o desde la lista de partidas de la ronda) recalcula la clasificación entera. Con el resultado se pueden anotar la puntuación de cada jugador (juegos o puntos ganados en la partida, que usan `gwp`, `ogwp` y `mov`) y sus estadísticas; si se anotan las dos puntuaciones, deben cuadrar con el resultado (quien gana no puede puntuar menos y un empate exige puntuaciones iguales). Los cambios de **Gestionar partidas** se guardan todos o ninguno, con un solo recálculo. Corregir un resultado sustituye al anterior; volver a «No jugada» lo borra, salvo en una ronda completada que ya tiene una ronda posterior (ahí solo se puede corregir). Una partida con bye solo admite `bye` y una partida entre dos jugadores no lo admite.

La clasificación se calcula así:

- **Puntos**: las reglas de puntuación del torneo; si no tiene, las de su perfil; si tampoco, Victoria 3, Empate 1, Derrota 0 y Bye 3. Un bye sin regla `bye` puntúa como una victoria.
- **Orden**: puntos y, a igualdad, los desempates configurados en su orden y dirección (si el torneo no tiene, los del perfil; si tampoco, Buchholz y Progresivo).
- La página pública **Clasificación** muestra además Buchholz y el porcentaje de victorias de los oponentes de cada jugador.

## Perfiles de juego

**Torneos** → **Perfiles de torneo** gestiona plantillas con estadísticas, reglas de puntuación, desempates y emparejamiento. Al crear un torneo se elige el perfil en la pestaña General, se cargan sus valores y luego se pueden ajustar para ese torneo.

Los perfiles del sistema no se pueden borrar y solo se puede editar su descripción; la acción **Duplicar** crea una copia editable. Las actualizaciones del módulo pueden renovar las reglas de los perfiles del sistema, nunca los perfiles creados por los administradores.

## Inscripciones de invitados

Un invitado se inscribe con nombre, email y consentimiento, y su inscripción no cuenta hasta que confirma el email.

- **Confirmación.** Al enviar el formulario recibe un email con un enlace (`/torneos/confirmar/{token}`). La página solo muestra la inscripción: el botón **Confirmar inscripción** la confirma, de modo que un rastreador de enlaces no confirma nada. Al confirmar se envía el email de inscripción habitual y se avisa a la organización. Un enlace vencido muestra «Este enlace ya no es válido» y, si las inscripciones siguen abiertas, invita a volver a apuntarse.
- **Modo `hold`.** La plaza queda reservada hasta el plazo (`hold_hours`), que nunca pasa del cierre de inscripciones (o del inicio del evento si no hay cierre). Un torneo reserva como máximo `max_unconfirmed_held` plazas sin confirmar a la vez: las demás inscripciones esperan sin plaza.
- **Modo `wait`.** Nada se reserva hasta confirmar; si el torneo se llena mientras tanto, la inscripción caduca con «Se ha llenado mientras confirmabas». Las inscripciones en espera no cuentan en las plazas.
- **Reenvío.** Repetir el formulario con una inscripción pendiente no amplía el plazo ni cambia el nombre guardado: la respuesta es «Ya tienes una inscripción pendiente: revisa tu correo» y, pasados 5 minutos, se reenvía el email.
- **Caducidad.** `php artisan tournaments:expire-guest-registrations` (programado cada 5 minutos) marca como **Caducada** las inscripciones sin confirmar fuera de plazo, libera la plaza y avisa al invitado. También borra las huellas de los torneos que ya empezaron, terminaron o se cancelaron.
- **Emails con cuenta.** Con `require_login`, un email que pertenece a una cuenta verificada recibe un 409 con enlace para iniciar sesión; con `allow_guest` se inscribe como invitado. Una cuenta sin verificar no bloquea a nadie. Si un usuario se inscribe con la sesión iniciada y tiene una inscripción de invitado con el mismo email, esa inscripción pasa a su cuenta.
- **Límites.** Las inscripciones de invitado se limitan por IP (`per_minute` y `per_hour`; los usuarios con sesión no tienen límite). El enlace de confirmación admite 20 intentos por minuto y 100 por hora y IP. Al superar un límite la web responde 429 («Has hecho demasiados intentos…») o vuelve al formulario con el error.
- **Posible duplicado.** Cada inscripción guarda una huella (HMAC de IP y navegador) mientras el torneo no ha empezado. En la pestaña **Participantes** del panel, la columna **Posible duplicado** marca las inscripciones activas con la misma huella creadas dentro de `duplicate_signal.window_hours` una de otra. Es una pista para la organización, no bloquea nada.
- **Panel.** **Confirmar** y **Marcar email como confirmado** confirman el email y envían al invitado el email de inscripción habitual; el check-in (también en bloque) y descalificar lo confirman sin enviar nada; retirar no lo confirma. El invitado con el email sin confirmar no puede hacer check-in desde la web.
- **Aviso en el formulario.** Antes de enviar, el formulario explica qué pasará según los términos del servidor: con `hold`, que la plaza se libera si no confirma antes de la fecha del plazo; con `wait`, que la plaza no está garantizada.

### Cookie `tn_guest_registrations`

Tras una inscripción de invitado correcta, el navegador guarda los identificadores de los torneos (nunca emails ni nombres) para avisar de que ya hay una inscripción desde ese navegador si se intenta otra vez. El aviso no bloquea el envío. La cookie dura 90 días (`Max-Age`, que vuelve a contar con cada inscripción). Cada torneo guardado en ella caduca por su cuenta al cerrarse sus inscripciones (a los 90 días como máximo, o si no tienen fecha de cierre): desde entonces ya no avisa y se descarta en la siguiente escritura.

- Categoría `preferences`: solo se escribe y se lee si el visitante la ha aceptado en el banner, preguntando al contrato de consentimiento del core (`hasCookieConsent('preferences')` de `@/contracts/cookieConsent`); sin un módulo de consentimiento en la página no se guarda nada.
- La cookie se declara en el registro del core: si el módulo `cookie-consent` está instalado, aparece en la categoría de preferencias del banner y de la política de cookies. Los textos están en `lang/es/messages.php` (`cookies.*`).

## Web pública

### Rutas

| Método | Ruta | Nombre | Acceso |
|--------|------|--------|--------|
| GET | `/torneos` | `tournaments.index` | Público |
| GET | `/torneos/mis-torneos` | `tournaments.my-tournaments` | Usuarios autenticados |
| GET | `/torneos/{slug}` | `tournaments.show` | Público |
| GET | `/torneos/{slug}/clasificacion` | `tournaments.standings` | Público |
| GET | `/torneos/{slug}/rondas` | `tournaments.rounds` | Público |
| GET | `/torneos/{slug}/check-in` | `tournaments.check-in.show` | Público |
| POST | `/torneos/{slug}/check-in` | `tournaments.check-in.store` | Público (los invitados indican su email) |
| GET | `/torneos/{tournamentId}/inscripcion` | `tournaments.registration.show` | Público |
| POST | `/torneos/{tournamentId}/inscripcion` | `tournaments.registration.store` | Público (invitados si el torneo los permite) |
| DELETE | `/torneos/{tournamentId}/inscripcion` | `tournaments.registration.destroy` | Usuarios autenticados |
| GET | `/torneos/confirmar/{token}` | `tournaments.confirm-registration` | Invitados con enlace |
| POST | `/torneos/confirmar/{token}` | `tournaments.confirm-registration.store` | Invitados con enlace |
| GET | `/torneos/cancelar/{token}` | `tournaments.cancel-confirmation` | Invitados con enlace |
| DELETE | `/torneos/cancelar/{token}` | `tournaments.cancel-by-token` | Invitados con enlace |

`tournaments.index` aparece entre las páginas que se pueden enlazar desde el editor de menús.

### Páginas Vue

| Página | Descripción |
|--------|-------------|
| `Tournaments/Index` | Listado con filtro por estado |
| `Tournaments/Show` | Detalle, inscripción y participantes |
| `Tournaments/Standings` | Clasificación |
| `Tournaments/Rounds` | Rondas y partidas |
| `Tournaments/CheckIn` | Check-in |
| `Tournaments/CancelRegistration` | Cancelación de invitados por token |
| `Tournaments/ConfirmRegistration` | Confirmación del email de un invitado por token |
| `Tournaments/MyTournaments` | Torneos del usuario |

### Componentes Vue

| Componente | Uso |
|------------|-----|
| `TournamentList` | Listado de tarjetas |
| `TournamentCard` | Tarjeta de torneo |
| `TournamentStatusFilter` | Filtro por estado del listado |
| `MyTournamentCard` | Tarjeta de «Mis torneos» |
| `EventTournamentSection` | Sección del torneo en la página del evento (slot `event-detail-actions`) |
| `profile/ProfileTournamentsSection` | Pestaña «Torneos» del perfil (slot `profile-sections`) |
| `profile/ProfileTournamentCard` | Tarjeta de la pestaña del perfil |

El composable `useTournaments` y los tipos de `resources/js/types/tournaments.ts` acompañan a estos componentes; los textos están en `resources/js/locales/`.

## Correos

| Momento | Destinatarios |
|---------|---------------|
| Inscripción (`ParticipantRegistered`) | Participante y email de notificaciones del torneo |
| Cancelación de la inscripción (`ParticipantWithdrawn`) | Participante y email de notificaciones del torneo |
| Inscripción de invitado pendiente (`GuestConfirmationRequested`) | Invitado, con el enlace de confirmación |
| Inscripción de invitado caducada | Invitado, con la invitación a volver a apuntarse |
| Torneo cancelado desde el panel (`TournamentCancelled`) | Cada participante activo |
| Descalificación desde el panel (`ParticipantDisqualified`) | El participante descalificado |

Con la confirmación por email, el email de inscripción (`ParticipantRegistered`) se envía al confirmar. Los avisos de cancelación y descalificación van al email de la cuenta o al del invitado solo si este lo confirmó (o la organización lo avaló antes, por ejemplo al hacer el check-in); a una dirección sin confirmar no se envía nada. `src/Domain/Events` define además eventos de torneo, ronda, partida y clasificación que todavía no se emiten.

## Permisos

| Permiso | Rol propuesto | Uso |
|---------|-----------------|-----|
| `tournaments.view_any` | editor | Ver el listado del panel |
| `tournaments.view` | editor | Ver un torneo |
| `tournaments.create` | editor | Crear torneos |
| `tournaments.update` | editor | Editar torneos |
| `tournaments.delete` | — | Eliminar torneos |
| `tournaments.manage_config` | editor | Gestionar perfiles de torneo |
| `tournaments.report_results` | editor | Registrar resultados: **Gestionar partidas** en las rondas y **Reportar resultado** en las partidas |
| `tournaments.manage_participants` | editor | Gestionar participantes: añadir, confirmar, check-in, retirar, descalificar y eliminar, también en bloque |

El core los guarda con el prefijo del módulo (por ejemplo `tournaments:tournaments.view_any`), que es la clave que comprueban las policies: un rol con el permiso concedido en **Roles** accede a lo que el permiso indica. La columna de rol indica el rol al que el módulo propone el permiso; habilitar el módulo no lo asigna. Los administradores tienen acceso completo.

Las pestañas **Rondas** y **Participantes** están en la edición del torneo, así que piden también `tournaments.update`. Sin `tournaments.report_results` o `tournaments.manage_participants` se ven, pero sin esas acciones; generar, iniciar y completar rondas sigue dependiendo solo de `tournaments.update`. La actualización a la versión 2.4.0 concede los dos permisos a cada rol que ya tenía `tournaments.update`, para que nadie pierda lo que podía hacer.

## Limitaciones actuales

- **Reporte de resultados por jugadores**: la opción se guarda, pero la web pública no tiene formulario de resultados; se registran desde el panel.
- **Método `accelerated`**: los torneos y perfiles que aún lo tengan guardado se emparejan como `swiss`, pero hay que elegir otro método antes de guardar su formulario (ver [Configuración de emparejamientos](#configuración-de-emparejamientos)).

## Arquitectura

```
src/modules/tournaments/
├── config/
│   ├── module.php
│   └── settings.php            # Valores por defecto de la página de configuración
├── database/
│   ├── migrations/
│   └── seeders/                # GameProfilesSeeder; DevelopmentSeeder (solo local y testing)
├── lang/
│   └── es/messages.php         # Textos del panel y correos
├── resources/
│   ├── css/
│   ├── js/
│   │   ├── components/         # Componentes Vue (y profile/)
│   │   ├── composables/        # useTournaments
│   │   ├── locales/            # Textos del frontend
│   │   ├── pages/Tournaments/  # Páginas Inertia
│   │   └── types/              # Tipos TypeScript
│   └── views/filament/         # Vista de la pestaña del formulario de eventos
├── routes/
│   └── web.php
├── src/
│   ├── Application/            # DTOs y interfaces de servicios
│   ├── Domain/                 # Entidades, enums, eventos, excepciones, repositorios, servicios, VOs
│   ├── Filament/
│   │   ├── RelationManagers/   # TournamentConfigRelationManager (formulario de eventos)
│   │   └── Resources/          # TournamentResource (Participantes, Rondas), GameProfileResource
│   ├── Http/                   # Controladores, requests y resources
│   ├── Infrastructure/         # Persistencia Eloquent y servicios
│   ├── Listeners/              # Correos de inscripción y cancelación
│   ├── Notifications/
│   ├── Policies/
│   └── TournamentsServiceProvider.php
├── tests/
│   ├── Unit/
│   ├── Integration/
│   ├── Feature/
│   └── js/                     # Tests de los componentes Vue (Vitest)
├── module.json
├── package.json
├── phpstan.neon
├── phpunit.xml
├── vite.config.ts
└── vitest.config.ts
```

## Tests

Desde `src/` (o dentro del contenedor `guildforge_app`):

```bash
vendor/bin/phpunit -c modules/tournaments/phpunit.xml
vendor/bin/phpunit -c modules/tournaments/phpunit.xml --testsuite=Unit
```

`php artisan test` no ejecuta los tests de los módulos.

Los componentes Vue tienen sus propios tests en `tests/js` (Vitest, Vue Test Utils y happy-dom). Se ejecutan con el módulo dentro del core, porque los componentes importan sus fuentes con `@/`, y con las dependencias de npm del core y del módulo instaladas:

```bash
cd modules/tournaments
npm test
```

`tests/js/setup.ts` carga los textos del core y del módulo en castellano y sustituye Inertia (router y enlaces) y el layout del sitio. En cada pull request los ejecuta el job *Vue tests* de `tests.yml`.

## Análisis estático

PHPStan (Larastan, nivel 8) analiza `src/` y `tests/` con la configuración del core. `phpstan.neon` la incluye y añade las rutas del módulo, sus migraciones (para conocer las columnas de los modelos), los seeders y `tests/Support`. Desde `src/` del core:

```bash
vendor/bin/phpstan analyse -c modules/tournaments/phpstan.neon --memory-limit=2G
```

Sin baseline ni errores ignorados: en cada pull request lo ejecuta el job *PHPStan* de `tests.yml`.

## Publicar una versión

1. Sube `version` en `module.json`.
2. Crea y empuja la etiqueta `vX.Y.Z` (debe coincidir con `version`).
3. El workflow `release.yml` ejecuta los tests contra el core (`main`, o la variable `GUILDFORGE_HOST_REF`), compila los assets y publica `tournaments-X.Y.Z.zip` con su `.sha256` en la release.

## Licencia

Este módulo es parte de GuildForge y está bajo la misma licencia del proyecto principal.
