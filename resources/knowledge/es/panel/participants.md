---
id: tournaments.panel.participants
title: Gestionar los participantes de un torneo
summary: Añade participantes, aprueba inscripciones, haz el check-in, retira o descalifica a alguien y revisa a las personas sin cuenta, desde la pestaña Participantes del torneo.
category: activities
audience: panel
order: 240
keywords: [participantes, inscritos, inscripciones, jugadores, añadir participante, apuntar a alguien, aprobar inscripción, confirmar participante, confirmación manual, check-in, presentado, retirar, dar de baja, descalificar, expulsar, invitado, sin cuenta, email sin confirmar, posible duplicado, caducada, semilla, bye recibido]
related: [tournaments.panel.tournament-lifecycle, tournaments.panel.rounds, tournaments.panel.settings, tournaments.panel.overview]
conditions:
  permissions:
    all: ['tournaments:tournaments.manage_participants']
---
Los participantes de un torneo están en la pestaña **Participantes** de su ficha. Desde ahí haces todo lo que la web no deja hacer a los jugadores.

## Ver los participantes

1. Abre **Torneos** → **Torneos** y pulsa **Editar** en el torneo.
2. Baja hasta la pestaña **Participantes**.

Arriba de la lista ves un resumen, por ejemplo «12 total · 8 confirmados · 4 con check-in». Cada fila muestra el **Nombre**, el **Email**, si es **Invitado** (sin cuenta), el **Estado**, el **Bye recibido**, la **Semilla**, la **Hora de check-in** y la **Fecha de inscripción**. Puedes filtrar por **Estado** y por **Email confirmado**.

Dos marcas te ayudan con las personas sin cuenta:

- Bajo el estado, **Email sin confirmar**: aún no ha pulsado el enlace del correo. Dice hasta cuándo tiene plaza («caduca el …») o que no ocupa plaza hasta confirmar.
- **Posible duplicado**: otra inscripción del torneo se hizo desde la misma conexión y navegador hace poco. Revísala antes de aprobarla.

## Quién juega las rondas

Las rondas solo emparejan a los participantes **Confirmado** o **Registrado**:

- Si el torneo tiene **Requiere confirmación manual**, aprueba cada inscripción con **Confirmar**.
- Si tiene **Requiere check-in**, las inscripciones se quedan en **Inscrito** hasta que hacen el check-in. **Check-in seleccionados** lo hace de una vez.
- Si no pide ninguna de las dos, la inscripción pasa sola a **Confirmado**: con cuenta, al inscribirse; sin cuenta, al confirmar su email. No reciben el correo «Inscripción aprobada».

Si quitas la confirmación manual o el check-in de un torneo que aún no ha empezado, y ya no pide ninguna de las dos, las inscripciones en **Inscrito** que no esperan la confirmación del email pasan a **Confirmado** al guardar.

## Añadir a alguien

1. Pulsa **Añadir participante**.
2. Elige su **Usuario** o, si no tiene cuenta, escribe el **Nombre del invitado** y, si quieres, el **Email del invitado**.
3. Elige el **Estado**: **Inscrito**, **Confirmado** o **Registrado**.
4. Si quieres, escribe su **Semilla**.
5. Pulsa **Crear**.

Desde el panel no cuentan las fechas de inscripción: puedes añadir a alguien con la inscripción cerrada o el torneo empezado. Sí cuenta el **Máximo de participantes**. El email de un invitado añadido aquí se da por confirmado, y no se envía ningún correo.

## Las acciones de cada fila

| Acción | Cuándo sale | Qué hace | Correo |
|---|---|---|---|
| **Confirmar** | Inscripción **Inscrito** en un torneo con confirmación manual | La aprueba. Puedes escribir **Notas para el participante**. | «Inscripción aprobada», con las notas |
| **Marcar email como confirmado** | Persona sin cuenta con el email sin confirmar | Hace como si hubiera pulsado el enlace del correo y ocupa su plaza. | Su correo de inscripción |
| **Check-in** | Inscripción que puede hacer el check-in (en un torneo con confirmación manual, solo **Confirmado**) | La pasa a **Registrado**. | Ninguno |
| **Retirar** | Inscripción activa | La pasa a **Retirado** y libera su plaza. | Ninguno |
| **Descalificar** | Inscripción activa | La pasa a **Descalificado**. Puedes escribir un **Motivo**. No se puede deshacer, y esa persona no puede volver a inscribirse. | «Descalificación», con el motivo |
| **Borrar** | Siempre | La quita de la lista. | Ninguno |

Seleccionando varias filas tienes **Confirmar seleccionados**, **Check-in seleccionados**, **Descalificar seleccionados** y borrar. Cada una se aplica a quien la admite; si alguien no la admite, sale «Participantes sin cambios: …» con el motivo.

Desde el panel puedes retirar a alguien en cualquier momento, también con el torneo en curso. Los jugadores solo pueden cancelar su inscripción desde la web hasta que empieza el evento.

## Personas sin cuenta

En un torneo con **Permitir invitados**, quien no tiene cuenta se inscribe con su nombre y su correo, y recibe un enlace para confirmarlo. Si no lo confirma a tiempo, su inscripción pasa a **Caducada**, recibe un correo y su plaza queda libre. La guía de ajustes explica el plazo y si guarda plaza mientras tanto.

## Si algo no sale bien

- **«El torneo está completo»**: no queda plaza. Retira a alguien o sube el **Máximo de participantes**.
- **«Este usuario ya tiene una inscripción en este torneo.»**: búscalo en la lista.
- **«Ya hay una inscripción con este email.»**: ya hay una persona sin cuenta inscrita con ese correo.
- **«Esta inscripción no admite esa acción en su estado actual.»**: el estado ha cambiado o la acción no vale para él. Recarga la lista.

[[action:filament.admin.resources.tournaments.index|Ver los torneos]]
