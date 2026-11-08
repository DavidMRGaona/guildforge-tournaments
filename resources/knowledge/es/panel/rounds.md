---
id: tournaments.panel.rounds
title: Generar y jugar las rondas
summary: Genera cada ronda con sus emparejamientos, iníciala para que se juegue y complétala cuando todas sus partidas tengan resultado.
category: activities
audience: panel
order: 250
keywords: [rondas, ronda, generar ronda, nueva ronda, siguiente ronda, emparejar, emparejamientos, emparejamiento suizo, sistema suizo, iniciar ronda, empezar ronda, completar ronda, cerrar ronda, terminar ronda, mesas, bye, descanso, número de rondas, partidas]
related: [tournaments.panel.results, tournaments.panel.participants, tournaments.panel.scoring, tournaments.panel.tournament-lifecycle]
conditions:
  permissions:
    all: ['tournaments:tournaments.update']
---
Las rondas de un torneo están en la pestaña **Rondas** de su ficha. Cada ronda pasa por tres pasos: la generas, la inicias y, cuando todas sus partidas tienen resultado, la completas.

## Ver las rondas

1. Abre **Actividades** → **Torneos** y pulsa **Editar** en el torneo.
2. Baja hasta la pestaña **Rondas**.

Cada fila muestra el **Número de ronda**, el **Estado**, cuántas **Partidas** tiene, cuántas están **Completadas** («5/8») y las fechas **Iniciada** y **Completada**. En una pantalla estrecha ves solo el **Número de ronda** y el **Estado**; el resto aparece según el ancho. Las acciones de la ronda (**Gestionar partidas**, **Iniciar ronda**, **Confirmar informes pendientes** y **Completar ronda**) están en el menú **⋯** de cada fila.

## Generar una ronda

Con el torneo **En curso**:

1. Pulsa **Generar ronda** y confirma.

Sale «Ronda … generada». La ronda queda **Pendiente**, con todos los emparejamientos hechos y el número de **Mesa** de cada partida. Los jugadores la ven en la página del torneo.

- Solo entran los participantes **Confirmado** o **Registrado**.
- Los emparejamientos siguen la **Configuración de emparejamientos** del torneo: normalmente el sistema suizo, que enfrenta a jugadores con puntos parecidos y evita repetir rivales.
- Si el número de jugadores es impar, uno descansa con un **bye**: suma sus puntos al momento y su partida ya sale con resultado.

Para generar la siguiente, la anterior tiene que estar completada.

## Iniciar la ronda

Abre el menú **⋯** de la fila, pulsa **Iniciar ronda** y confirma. La ronda pasa a **En curso**. Desde ahora, si el torneo lo permite, los jugadores pueden informar de sus resultados desde la web.

## Completar la ronda

Cuando todas las partidas tienen resultado, abre el menú **⋯** de la fila, pulsa **Completar ronda** y confirma. La ronda pasa a **Finalizada** y ya puedes generar la siguiente.

Si quedan partidas sin resultado, sale «No se han completado todas las partidas» con cuántas faltan y cuántas tienen un resultado de los jugadores pendiente de confirmar o discutido. Regístralas antes: lo explica la guía «Registrar resultados y revisar los informes de los jugadores».

## Cuántas rondas

Si el torneo tiene **Número de rondas**, no se puede generar más allá. Si no lo tiene, tú decides cuándo parar: completa la última ronda y finaliza el torneo.

## Si algo no sale bien

- **«La ronda anterior debe tener todos los resultados y estar completada»**: completa la ronda anterior.
- **«Hacen falta al menos 2 participantes confirmados o presentados para generar una ronda»**: aprueba inscripciones o haz el check-in en **Participantes**.
- **«No se pueden generar los emparejamientos: el torneo debe estar en curso y sin superar el máximo de rondas»**: inicia el torneo, o ya has llegado al **Número de rondas**.
- **«La ronda acaba de generarse desde otra sesión: recarga la lista de rondas»**: otra persona la ha generado a la vez. Recarga.
- **«Transición de estado no permitida»**: la ronda ha cambiado mientras tanto. Recarga la lista.

[[action:filament.admin.resources.tournaments.index|Ver los torneos]]
