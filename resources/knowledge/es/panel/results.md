---
id: tournaments.panel.results
title: Registrar resultados y revisar los informes de los jugadores
summary: Apunta o corrige los resultados de una ronda desde el panel, confirma los que informan los jugadores, resuelve los que se discuten y consulta el historial de cada partida.
category: activities
audience: panel
order: 260
keywords: [resultados, resultado, registrar resultado, apuntar resultado, reportar resultado, corregir resultado, cambiar resultado, gestionar partidas, partidas, pestaña partidas, restablecer, restablecer resultado, historial, historial de la partida, confirmar informe, puntos, puntuación, estadísticas, empate, doble derrota, sin jugar, informes, informes pendientes, confirmar informes, resultado discutido, disputa, disputado, reporte de resultados, clasificación]
related: [tournaments.panel.rounds, tournaments.panel.scoring, tournaments.panel.tournament-lifecycle]
conditions:
  permissions:
    all: ['tournaments:tournaments.report_results']
---
Los resultados se registran desde la ficha del torneo: ronda a ronda en la pestaña **Rondas**, o partida a partida en la pestaña **Partidas**. La clasificación se recalcula sola con cada cambio.

## Quién registra los resultados

Lo decide el campo **Reporte de resultados** del torneo:

- **Solo administradores**: los registras tú, desde el panel.
- **Jugadores con confirmación**: un jugador informa desde la web y su rival lo confirma o lo discute. Mientras tanto, el resultado no cuenta.
- **Jugadores sin confirmación**: el resultado cuenta en cuanto un jugador lo informa.

En cualquier modo puedes registrar o corregir un resultado desde el panel. Lo que registras tú es el resultado oficial y descarta el informe de los jugadores que hubiera en esa partida. Con **Jugadores con confirmación**, si el rival de quien informa no tiene cuenta, nadie puede confirmarlo desde la web: lo confirmas tú.

## Registrar los resultados de una ronda

1. Abre **Torneos** → **Torneos**, pulsa **Editar** en el torneo y baja hasta la pestaña **Rondas**.
2. En el menú **⋯** de la ronda, pulsa **Gestionar partidas**. Se abre la lista de sus partidas, una por mesa («Mesa 1 - … vs …»).
3. En cada partida, elige el resultado en **Estado**: **Victoria jugador 1**, **Victoria jugador 2**, **Empate**, **Doble derrota** o **Sin jugar**.
4. Si quieres, escribe **Puntos jugador 1** y **Puntos jugador 2**: los juegos o puntos ganados en la partida. Los usan algunos desempates.
5. Si el torneo usa estadísticas, rellénalas en **Estadísticas jugador 1** y **Estadísticas jugador 2**.
6. Guarda los cambios.

Sale «Resultados actualizados». Solo se guardan las partidas que has cambiado, y o se guardan todas o ninguna.

Para dejar una partida sin resultado, elige **Sin jugar**. La partida de un bye ya tiene su resultado y no hay que tocarla.

## La pestaña Partidas

Abre **Torneos** → **Torneos**, pulsa **Editar** en el torneo y baja hasta la pestaña **Partidas**. Muestra las partidas de la última ronda; con el filtro **Ronda** ves las de otra, y con el filtro **Informe**, solo las que tienen un informe de los jugadores **Pendiente** o **Discutido**. La columna **Informe** dice lo mismo, y al pasar por encima de **Discutido** ves el motivo.

Cada partida tiene sus acciones en el menú **⋯** de su fila. En una pantalla estrecha ves solo la **Mesa** y los jugadores; el resto de columnas aparecen según el ancho:

| Acción | Cuándo sale | Qué hace |
|---|---|---|
| **Reportar resultado** | Partida sin resultado | Registra el resultado oficial, con los puntos y las estadísticas. Si había un informe de los jugadores, lo descarta. |
| **Confirmar informe** | Partida con un informe de los jugadores, también discutido | Da por bueno lo que informó el jugador. Si lo corrige mientras tienes la ventana abierta, no se confirma: sale «El jugador ha corregido su informe mientras lo mirabas: vuelve a abrirlo para ver el nuevo». |
| **Restablecer** | Partida con resultado o con un informe | La deja sin resultado y descarta el informe. Escribe un **Motivo**: queda en el historial. Después puedes registrar el resultado otra vez. |
| **Historial** | Partida con cambios | Muestra cada cambio de resultado, cuándo, quién lo hizo y el motivo. |

## Corregir un resultado

Abre **Gestionar partidas** en el menú **⋯** de la ronda, aunque esté completada, cambia el resultado y guarda. También puedes pulsar **Restablecer** en la partida, en la pestaña **Partidas**, y volver a registrarlo. La clasificación se recalcula. Si la ronda ya está completada y hay otra después, puedes cambiar el resultado, pero no dejarlo **Sin jugar**.

## Los informes de los jugadores

Con **Jugadores con confirmación**, un resultado informado espera a que el rival lo confirme. En la página **Rondas** de la web sale como **Pendiente de confirmación**, o como **Discutido** si el rival no está de acuerdo.

- **Confirmar los que nadie discute**: con la ronda en curso, abre el menú **⋯** de la fila de la ronda, pulsa **Confirmar informes pendientes** y confirma. Los resultados informados que nadie discute pasan a ser definitivos. Los discutidos se quedan para que los revises uno a uno.
- **Resolver uno discutido**: cuando un rival discute, el **Email de notificaciones** del torneo recibe el correo «Resultado discutido en …», con el resultado informado y el motivo. Busca la partida en la pestaña **Partidas** (filtro **Informe** → **Discutido**) y decide qué pasó: **Confirmar informe** si el informe es correcto, o **Restablecer** y registra el resultado correcto. El jugador que informó también recibe el aviso y puede corregir su informe desde la web.

No se puede completar la ronda ni finalizar el torneo mientras quede algún informe sin confirmar o discutido.

## Si algo no sale bien

- **«Las puntuaciones no cuadran con el resultado: quien gana no puede puntuar menos y un empate necesita puntuaciones iguales»**: revisa los puntos de esa partida.
- **«La ronda ya está completada y tiene una ronda posterior: el resultado se puede corregir, pero no dejar sin jugar»**: elige un resultado.
- **«Algunos resultados cambiaron mientras editabas»**: un jugador informó o alguien cambió una partida mientras tenías la lista abierta. Esas partidas no se han tocado: vuelve a abrir **Gestionar partidas** para verlas.
- **«El resultado no es válido para este enfrentamiento»**: ese resultado no vale para la partida, por ejemplo un bye en una partida con dos jugadores.

[[action:filament.admin.resources.tournaments.index|Ver los torneos]]
