---
id: tournaments.panel.tournament-lifecycle
title: Abrir la inscripción, iniciar, finalizar y cancelar un torneo
summary: Cambia el estado de un torneo con los botones de su ficha, qué comprueba cada paso, qué correos salen y qué no se puede deshacer.
category: activities
audience: panel
order: 230
keywords: [abrir inscripción, cerrar inscripción, iniciar torneo, empezar torneo, finalizar torneo, terminar torneo, cancelar torneo, anular torneo, borrar torneo, eliminar torneo, estado del torneo, publicar torneo, borrador, en curso, finalizado, cancelado, editar torneo]
related: [tournaments.panel.overview, tournaments.panel.participants, tournaments.panel.rounds, tournaments.panel.results]
conditions:
  permissions:
    all: ['tournaments:tournaments.update']
---
El estado de un torneo se cambia con los botones de arriba de su ficha. Cada botón sale solo cuando el torneo puede dar ese paso.

## Abrir la ficha

1. En el menú lateral, abre **Actividades** y elige **Torneos**.
2. Pulsa el torneo en la lista (o **Editar** en su menú **⋯**). La página se llama **Editar torneo**.

Desde aquí también cambias sus datos: modifica lo que necesites y pulsa **Guardar cambios**.

## Abrir la inscripción

En un torneo en **Borrador**, pulsa **Abrir inscripción** y confirma. Pasa a **Inscripción abierta** y ya se ve en la web.

La web solo admite inscripciones dentro de las fechas del torneo y antes de que empiece el evento. Si al abrir aún no se puede, sale el aviso «La web aún no admite inscripciones» con el motivo:

- «La inscripción no se abrirá en la web hasta el …, la fecha de apertura configurada.»: la gente verá **Inscripción próximamente** hasta ese día.
- «La fecha de cierre de inscripción ya ha pasado.»: cambia el **Cierre de inscripción** en la pestaña **Fechas**.
- «El evento ya ha comenzado.»: ya no se puede inscribir nadie desde la web. Añade a los participantes desde la pestaña **Participantes**.

## Cerrar la inscripción

En un torneo con **Inscripción abierta**, pulsa **Cerrar inscripción** y confirma. Nadie más puede inscribirse. Las inscripciones también se cierran solas en la fecha de cierre o cuando empieza el evento, aunque el estado no cambie.

## Iniciar el torneo

Con la inscripción abierta o cerrada, pulsa **Iniciar torneo** y confirma. Pasa a **En curso** y se cierra la inscripción. Ya puedes generar la primera ronda en la pestaña **Rondas**.

Hace falta tener al menos el **Mínimo de participantes** con la inscripción activa (**Inscrito**, **Confirmado** o **Registrado**). Si no, sale «No hay suficientes participantes para iniciar el torneo».

> [!IMPORTANT]
> Las rondas solo emparejan a los participantes **Confirmado** o **Registrado**. Si el torneo pide confirmación manual o check-in, antes de generar la primera aprueba las inscripciones pendientes o haz el check-in de los presentes en la pestaña **Participantes**. Si no pide ninguna de las dos, las inscripciones ya están en **Confirmado**.

## Finalizar el torneo

Cuando se han jugado todas las rondas, pulsa **Finalizar torneo**. Marca la casilla «Entiendo que el torneo terminará con la clasificación actual y que no se puede deshacer» y confirma. Pasa a **Finalizado** y la clasificación queda cerrada tal como está.

No se puede finalizar mientras quede algún resultado informado por los jugadores sin confirmar o discutido: el aviso dice cuántos quedan. Resuélvelos antes en la pestaña **Partidas** (lo explica la guía de resultados).

Si tu web tiene encuestas, al finalizar puede salir la encuesta elegida para el final del torneo.

## Cancelar el torneo

Mientras no esté finalizado ni cancelado, pulsa **Cancelar torneo**. Marca la casilla «Entiendo que se anularán todas las inscripciones, se descartarán los resultados sin confirmar y que no se puede deshacer» y confirma.

> [!WARNING]
> Cancelar no se puede deshacer. Cada participante recibe el correo «Torneo cancelado», los resultados que los jugadores han informado y aún no cuentan se descartan, y el torneo deja de salir en la lista de la web.

## Borrar el torneo

Con el permiso **Eliminar torneo**, el botón **Borrar** quita el torneo con todas sus inscripciones, rondas y resultados. No avisa a nadie: si el torneo tenía participantes, cancélalo antes para que les llegue el correo. Los avisos de la campana sobre ese torneo sí desaparecen, para que no enlacen a una página que ya no existe.

## Si algo no sale bien

- **«Transición de estado no permitida»**: el torneo ha cambiado mientras tanto, por ejemplo desde otra pestaña. Recarga la página.
- **«No se puede finalizar el torneo»**: quedan resultados de los jugadores por resolver.
- **«No hay suficientes participantes para iniciar el torneo»**: añade participantes o baja el **Mínimo de participantes**.

[[action:filament.admin.resources.tournaments.index|Ver los torneos]]
