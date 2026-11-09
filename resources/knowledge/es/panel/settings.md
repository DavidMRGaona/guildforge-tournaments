---
id: tournaments.panel.settings
title: 'Ajustes de torneos: valores por defecto, invitados y recordatorio'
summary: Qué decide cada campo de la configuración del módulo de torneos y cómo afecta a los torneos nuevos, a las inscripciones sin cuenta y a los correos.
category: activities
audience: panel
order: 290
keywords: [ajustes, configuración, configurar torneos, ajustes del módulo, valores por defecto, reporte de resultados, invitados, sin cuenta, confirmar email, plazo de confirmación, caducar, reservar plaza, posible duplicado, límite por ip, recordatorio, correo recordatorio, antelación, check-in por defecto]
related: [tournaments.panel.create-tournament, tournaments.panel.participants, core.panel.modules]
conditions:
  permissions:
    all: ['modules.configure']
---
La configuración del módulo decide los valores con que empieza cada torneo nuevo, cómo funcionan las inscripciones de personas sin cuenta y el recordatorio por correo.

## Abrir la configuración

1. En el menú lateral, abre **Sistema** y elige **Módulos**.
2. En la tarjeta de **Torneos**, pulsa **Configuración**.
3. Al terminar, pulsa **Guardar configuración**.

## Valores por defecto

Son los valores con que empieza cada torneo nuevo, en su formulario y en la pestaña **Configuración del torneo** de un evento. Cambiarlos no toca los torneos que ya existen.

- **Modo de reporte de resultados**: **Solo administradores** (el que viene), **Jugadores con confirmación** o **Jugadores sin confirmación**.
- **Permitir invitados**: si los torneos nuevos admiten personas sin cuenta.
- **Requiere check-in**: si los torneos nuevos piden check-in.
- **Minutos antes para check-in**: cuántos minutos antes del inicio del evento se abre el check-in (30, si no lo cambias).

## Inscripciones de invitados

Los invitados son personas sin cuenta que se inscriben con su correo en un torneo con **Permitir invitados**. Reciben un enlace para confirmar el correo.

| Campo | Qué decide |
|---|---|
| **Plaza mientras no confirma** | **Reserva la plaza con plazo**: le guarda la plaza hasta que confirme o venza el plazo. **No ocupa plaza hasta confirmar**: no le guarda nada; al confirmar entra si queda sitio. |
| **Plazo de confirmación** | Cuántas horas tiene para pulsar el enlace (de 1 a 168; 2 si no lo cambias), nunca más allá del cierre de la inscripción. Al vencer, su inscripción pasa a **Caducada** y se le avisa por correo. |
| **Máximo de plazas sin confirmar** | Cuántos invitados sin confirmar pueden guardar plaza a la vez en un torneo (de 0 a 50). Los siguientes esperan sin ocupar plaza hasta que confirmen. |
| **Email con cuenta registrada** | Qué pasa si el correo ya es de una cuenta verificada: **Pedir que inicie sesión** o **Permitir como invitado** (salvo que esa cuenta ya esté inscrita). |
| **Inscripciones por IP y minuto** y **Inscripciones por IP y hora** | Frenan las inscripciones de invitados en masa desde una misma conexión. |
| **Ventana de posible duplicado** | Durante cuántas horas dos inscripciones de la misma conexión y navegador se marcan como **Posible duplicado** en la pestaña **Participantes**. |

Las inscripciones sin confirmar se revisan cada cinco minutos.

## Recordatorio

- **Enviar recordatorio antes del torneo**: viene desactivado. Activado, cada inscripción activa (con cuenta, o de invitado con el correo confirmado) recibe un correo antes del inicio del evento.
- **Antelación del recordatorio**: cuántas horas antes sale (de 1 a 168; 24 si no lo cambias).

Este interruptor manda sobre las preferencias personales: si lo apagas, nadie recibe el recordatorio. Si lo activas, cada persona con cuenta puede dejar de recibirlo en la pestaña **Correos** de su perfil (categoría «Recordatorios antes del torneo»); las inscripciones de invitados siempre lo reciben.

## Si algo no sale bien

- **No ves Configuración en la tarjeta de Torneos**: el módulo no está habilitado, o no tienes el permiso **Configurar módulos**.

[[action:filament.admin.pages.modules-page|Ver los módulos]]
