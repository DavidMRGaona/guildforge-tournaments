---
id: tournaments.panel.results
title: Registrar resultados y revisar los informes de los jugadores
summary: Apunta o corrige los resultados de una ronda desde el panel, confirma los que informan los jugadores y resuelve los que se discuten.
category: activities
audience: panel
order: 260
keywords: [resultados, resultado, registrar resultado, apuntar resultado, reportar resultado, corregir resultado, cambiar resultado, gestionar partidas, partidas, puntos, puntuación, estadísticas, empate, doble derrota, sin jugar, informes, informes pendientes, confirmar informes, resultado discutido, disputa, disputado, reporte de resultados, clasificación]
related: [tournaments.panel.rounds, tournaments.panel.scoring, tournaments.panel.tournament-lifecycle]
conditions:
  permissions:
    all: ['tournaments:tournaments.report_results']
---
Los resultados se registran ronda a ronda, desde la pestaña **Rondas** del torneo. La clasificación se recalcula sola con cada cambio.

## Quién registra los resultados

Lo decide el campo **Reporte de resultados** del torneo:

- **Solo administradores**: los registras tú, desde el panel.
- **Jugadores con confirmación**: un jugador informa desde la web y su rival lo confirma o lo discute. Mientras tanto, el resultado no cuenta.
- **Jugadores sin confirmación**: el resultado cuenta en cuanto un jugador lo informa.

En cualquier modo puedes registrar o corregir un resultado desde el panel. Lo que registras tú es el resultado oficial y descarta el informe de los jugadores que hubiera en esa partida. Con **Jugadores con confirmación**, si el rival de quien informa no tiene cuenta, nadie puede confirmarlo desde la web: lo confirmas tú.

## Registrar los resultados de una ronda

1. Abre **Torneos** → **Torneos**, pulsa **Editar** en el torneo y baja hasta la pestaña **Rondas**.
2. Pulsa **Gestionar partidas** en la ronda. Se abre la lista de sus partidas, una por mesa («Mesa 1 - … vs …»).
3. En cada partida, elige el resultado en **Estado**: **Victoria jugador 1**, **Victoria jugador 2**, **Empate**, **Doble derrota** o **Sin jugar**.
4. Si quieres, escribe **Puntos jugador 1** y **Puntos jugador 2**: los juegos o puntos ganados en la partida. Los usan algunos desempates.
5. Si el torneo usa estadísticas, rellénalas en **Estadísticas jugador 1** y **Estadísticas jugador 2**.
6. Guarda los cambios.

Sale «Resultados actualizados». Solo se guardan las partidas que has cambiado, y o se guardan todas o ninguna.

Para dejar una partida sin resultado, elige **Sin jugar**. La partida de un bye ya tiene su resultado y no hay que tocarla.

## Corregir un resultado

Abre **Gestionar partidas** en la ronda, aunque esté completada, cambia el resultado y guarda. La clasificación se recalcula. Si la ronda ya está completada y hay otra después, puedes cambiar el resultado, pero no dejarlo **Sin jugar**.

## Los informes de los jugadores

Con **Jugadores con confirmación**, un resultado informado espera a que el rival lo confirme. En la página **Rondas** de la web sale como **Pendiente de confirmación**, o como **Discutido** si el rival no está de acuerdo.

- **Confirmar los que nadie discute**: con la ronda en curso, pulsa **Confirmar informes pendientes** en la fila y confirma. Los resultados informados que nadie discute pasan a ser definitivos. Los discutidos se quedan para que los revises uno a uno.
- **Resolver uno discutido**: cuando un rival discute, el **Email de notificaciones** del torneo recibe el correo «Resultado discutido en …», con el resultado informado y el motivo. Decide qué pasó y registra el resultado correcto en **Gestionar partidas**. El jugador que informó también recibe el aviso y puede corregir su informe desde la web.

No se puede completar la ronda ni finalizar el torneo mientras quede algún informe sin confirmar o discutido.

## Si algo no sale bien

- **«Las puntuaciones no cuadran con el resultado: quien gana no puede puntuar menos y un empate necesita puntuaciones iguales»**: revisa los puntos de esa partida.
- **«La ronda ya está completada y tiene una ronda posterior: el resultado se puede corregir, pero no dejar sin jugar»**: elige un resultado.
- **«Algunos resultados cambiaron mientras editabas»**: un jugador informó o alguien cambió una partida mientras tenías la lista abierta. Esas partidas no se han tocado: vuelve a abrir **Gestionar partidas** para verlas.
- **«El resultado no es válido para este enfrentamiento»**: ese resultado no vale para la partida, por ejemplo un bye en una partida con dos jugadores.

[[action:filament.admin.resources.tournaments.index|Ver los torneos]]
