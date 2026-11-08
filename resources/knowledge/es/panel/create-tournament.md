---
id: tournaments.panel.create-tournament
title: Crear un torneo
summary: Crea un torneo para un evento con su capacidad, sus fechas de inscripción, sus reglas y sus opciones de inscripción, check-in y resultados.
category: activities
audience: panel
order: 210
keywords: [crear torneo, nuevo torneo, añadir torneo, organizar torneo, evento asociado, plazas, máximo de participantes, mínimo de participantes, número de rondas, apertura de inscripción, cierre de inscripción, invitados, check-in, confirmación manual, email de notificaciones, reporte de resultados, perfil de torneo, organización, imagen]
related: [tournaments.panel.overview, tournaments.panel.scoring, tournaments.panel.tournament-lifecycle, tournaments.panel.event-tournament, tournaments.panel.game-profiles]
conditions:
  permissions:
    all: ['tournaments:tournaments.create']
---
Un torneo siempre pertenece a un evento publicado. Puedes crearlo aquí, con todas sus opciones, o desde la pestaña **Configuración del torneo** del evento, con lo básico (lo explica la guía «Configurar el torneo desde su evento»).

## Crear el torneo

1. En el menú lateral, abre **Actividades** y elige **Torneos**.
2. Pulsa **Crear torneo**.
3. Rellena las pestañas que necesites (las explica esta guía).
4. Pulsa **Crear**.

El torneo nace como **Borrador**: no se ve en la web hasta que abres la inscripción. Lo explica la guía «Abrir la inscripción, iniciar, finalizar y cancelar un torneo».

## General

- **Evento asociado**: el evento donde se juega. Solo salen los eventos publicados, y cada evento admite un solo torneo. La fecha de inicio del evento es la del torneo.
- **Nombre del torneo** y **Descripción**: lo que se ve en la web.
- **Imagen**: opcional, hasta 2 MB.
- **Perfil de torneo**: elige uno para cargar sus estadísticas, su puntuación, sus desempates y sus emparejamientos en las pestañas de reglas. Los perfiles del sistema llevan una estrella. Si no eliges ninguno, el torneo empieza con 3 puntos por victoria, 1 por empate, 0 por derrota y 3 por bye, y los desempates Buchholz y Progresivo.
- **Organización**: las personas que organizan el torneo. Si tu web tiene encuestas, pueden gestionar las del torneo desde la web.

## Capacidad

- **Máximo de participantes**: déjalo vacío para no poner límite. Con el máximo alcanzado, nadie más puede inscribirse.
- **Mínimo de participantes**: cuántas inscripciones activas hacen falta para iniciar el torneo. Como mínimo, 2.
- **Número de rondas**: el tope de rondas. Si lo dejas vacío, el módulo no pone tope: generas rondas hasta que finalizas el torneo.

## Fechas

- **Apertura de inscripción**: desde cuándo se puede inscribir la gente. Vacío, desde que abres la inscripción.
- **Cierre de inscripción**: hasta cuándo. Tiene que ser posterior a la apertura y no puede pasar del inicio del evento. Vacío, la inscripción cierra al empezar el evento.

## Reglas

Las pestañas **Estadísticas**, **Reglas de puntuación**, **Configuración de desempates** y **Configuración de emparejamientos** deciden cómo se puntúa, cómo se desempata y cómo se empareja. Si has elegido un perfil, ya vienen rellenas y llevan la marca **Heredado del perfil de juego**. Lo explica la guía «Puntuación, desempates y emparejamientos».

## Opciones

| Campo | Qué decide |
|---|---|
| **Reporte de resultados** | Quién registra los resultados: **Solo administradores**, **Jugadores con confirmación** (un jugador informa y su rival confirma o discute) o **Jugadores sin confirmación** (cuenta en cuanto se informa). La organización siempre puede registrarlos y corregirlos. |
| **Permitir invitados** | Si se pueden inscribir personas sin cuenta, con su nombre y su correo. |
| **Mostrar participantes** | Si la página del torneo muestra la lista de inscritos mientras está abierta la inscripción. |
| **Requiere check-in** | Si los participantes tienen que hacer el check-in antes de empezar. |
| **Minutos antes para check-in** | Con check-in, cuántos minutos antes del inicio del evento se abre. |
| **Permitir auto check-in** | Con check-in, si los participantes lo hacen desde la web. Si no, lo haces tú en la pestaña **Participantes**. |
| **Requiere confirmación manual** | Si cada inscripción necesita tu aprobación antes de poder jugar. |
| **Email de notificaciones** | Obligatorio. Recibe un aviso de cada inscripción y de cada baja, y de los resultados que los jugadores discuten. |

Los valores con que empiezan **Reporte de resultados**, **Permitir invitados**, **Requiere check-in** y **Minutos antes para check-in** salen de los ajustes del módulo.

Si tu web tiene encuestas, esta pestaña trae además el campo **Encuesta al terminar**, con su propia ayuda.

## Si algo no sale bien

- **No sale el evento en Evento asociado**: no está publicado. Publícalo antes.
- **No deja guardar con un evento**: ese evento ya tiene torneo. Búscalo en la lista de torneos.
- **«El cierre de inscripción no puede ser posterior al inicio del evento (…)»**: adelanta el cierre o déjalo vacío.

[[action:filament.admin.resources.tournaments.create|Crear un torneo]]
