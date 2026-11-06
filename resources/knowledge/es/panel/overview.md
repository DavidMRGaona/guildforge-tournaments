---
id: tournaments.panel.overview
title: 'Torneos: cómo funcionan'
summary: Qué es un torneo, cómo se organiza de principio a fin, por qué estados pasan el torneo, las inscripciones y las rondas, y quién puede hacer cada cosa en el panel.
category: activities
audience: panel
order: 200
featured: true
keywords: [torneo, torneos, competición, campeonato, sistema suizo, suizo, swiss, ronda, rondas, emparejamientos, partidas, participantes, inscripciones, clasificación, estados, borrador, inscripción abierta, en curso, finalizado, cancelado, perfil de torneo, permisos, cómo funciona, introducción, empezar, organizar un torneo]
related: [tournaments.panel.create-tournament, tournaments.panel.tournament-lifecycle, tournaments.panel.participants, tournaments.panel.rounds, tournaments.panel.results, tournaments.panel.scoring, core.panel.roles-and-permissions]
conditions:
  permissions:
    any: ['tournaments:tournaments.view_any']
---
El módulo de torneos organiza competiciones por rondas dentro de los eventos de {{site_name}}: la gente se inscribe desde la web, en cada ronda se empareja a los jugadores y la clasificación se calcula sola con los resultados. Esta guía explica las piezas y el recorrido de un torneo. Las demás guías entran en el detalle de cada tarea.

## Las piezas

| Pieza | Qué es |
|---|---|
| **Torneo** | La competición. Siempre va unido a un evento publicado, y cada evento puede tener un solo torneo. La inscripción cierra como muy tarde cuando empieza el evento. |
| **Participante** | Una inscripción en el torneo, de una persona con cuenta o de un invitado sin cuenta. |
| **Ronda** | Una tanda de partidas. Al generarla, el módulo empareja a los jugadores y da a cada partida su número de **Mesa**. |
| **Partida** | Un enfrentamiento entre dos jugadores. Si el número de jugadores es impar, uno descansa con un **bye** y suma puntos sin jugar. |
| **Clasificación** | El orden de los jugadores por puntos y desempates. Se recalcula con cada resultado. |
| **Perfil de torneo** | Un conjunto de reglas listo para usar: estadísticas, puntuación, desempates y emparejamientos de un juego concreto. |

## El recorrido de un torneo

1. **Crearlo**, en **Torneos** o desde la pestaña **Configuración del torneo** de su evento. Nace como **Borrador** y no se ve en la web.
2. **Abrir la inscripción**. La gente se inscribe desde la página del torneo o desde la ficha del evento.
3. **Preparar a los participantes**: aprobar inscripciones si el torneo lo pide y hacer el check-in.
4. **Iniciar el torneo**. La inscripción se cierra.
5. **Jugar las rondas**: generar cada ronda, iniciarla, registrar los resultados y completarla.
6. **Finalizar el torneo**. La clasificación queda cerrada.

## Estados de un torneo

| Estado | Qué significa | A qué puede pasar |
|---|---|---|
| **Borrador** | No se ve en la web. | **Inscripción abierta** o **Cancelado** |
| **Inscripción abierta** | Admite inscripciones según sus fechas y sus plazas. En la web sale como **Inscripción próximamente** antes de la fecha de apertura, y como **Inscripción cerrada** después del cierre o cuando empieza el evento. | **Inscripción cerrada**, **En curso** o **Cancelado** |
| **Inscripción cerrada** | Ya no admite inscripciones. | **En curso** o **Cancelado** |
| **En curso** | Se están jugando las rondas. | **Finalizado** o **Cancelado** |
| **Finalizado** | Terminado, con la clasificación definitiva. Es definitivo. | Ninguno |
| **Cancelado** | No se juega. Es definitivo y no sale en la lista de torneos de la web. | Ninguno |

## Estados de un participante

| Estado | Qué significa |
|---|---|
| **Inscrito** | Se ha inscrito. Si el torneo pide confirmación manual, espera a que lo apruebes. |
| **Confirmado** | Tiene la inscripción aprobada. |
| **Registrado** | Ha hecho el check-in: está en el local. |
| **Retirado** | Se ha dado de baja, o lo has retirado tú. |
| **Descalificado** | Lo has descalificado. Es definitivo. |
| **Caducada** | Era una persona sin cuenta que no confirmó su email a tiempo. |

> [!IMPORTANT]
> Las rondas solo emparejan a los participantes **Confirmado** o **Registrado**. Quien sigue en **Inscrito** se queda fuera. Si el torneo no pide confirmación manual, haz el check-in de los presentes antes de generar la primera ronda.

## Estados de una ronda

| Estado | Qué significa |
|---|---|
| **Pendiente** | Generada, con sus emparejamientos, pero sin empezar. Los jugadores aún no pueden informar de sus resultados. |
| **En curso** | Se está jugando. |
| **Finalizada** | Todas sus partidas tienen resultado. Ya se puede generar la siguiente. |

## Web o panel

En la web, la gente se inscribe, cancela su inscripción, hace el check-in si el torneo lo permite y, según el torneo, informa de sus resultados. En el panel se hace todo lo demás: crear y cambiar los torneos, gestionar los participantes, generar las rondas, registrar y corregir resultados, y abrir, iniciar, finalizar o cancelar.

## Quién hace qué

Ninguno de estos permisos se concede solo: se dan a cada rol en **Roles**. La administración los tiene todos.

| Permiso | Qué permite |
|---|---|
| **Ver todos los torneos** y **Ver torneo** | Ver los torneos en el panel |
| **Crear torneo** | Crear torneos |
| **Actualizar torneo** | Cambiar los torneos, abrir y cerrar la inscripción, iniciarlos, finalizarlos, cancelarlos y generar, iniciar y completar las rondas |
| **Eliminar torneo** | Borrar torneos |
| **Gestionar participantes** | Añadir, confirmar, hacer el check-in, retirar, descalificar y borrar participantes |
| **Reportar resultados** | Registrar y corregir los resultados de las partidas y confirmar los que informan los jugadores |
| **Gestionar configuración de torneos** | Crear y cambiar los **Perfiles de torneo** |
| **Editar evento** (del núcleo) | Configurar el torneo de un evento en su pestaña **Configuración del torneo** |

Los ajustes generales del módulo están en **Administración** → **Módulos** y necesitan el permiso **Configurar módulos** (del núcleo).

## Dónde está cada cosa

- En el menú lateral, el grupo **Torneos** tiene **Torneos** y **Perfiles de torneo**.
- Dentro de un torneo están las pestañas **Participantes** y **Rondas**, y arriba los botones para cambiar su estado.
- Dentro de un evento, la pestaña **Configuración del torneo** crea o cambia su torneo con lo básico.

Si tu web tiene encuestas, el formulario del torneo trae además el campo **Encuesta al terminar**, con su propia ayuda.

[[action:filament.admin.resources.tournaments.index|Ver los torneos|if=tournaments:tournaments.view_any]]
