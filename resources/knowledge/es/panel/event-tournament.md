---
id: tournaments.panel.event-tournament
title: Configurar el torneo desde su evento
summary: Crea, cambia o quita el torneo de un evento desde su pestaña Configuración del torneo, con la capacidad, las fechas, la puntuación y las opciones básicas.
category: activities
audience: panel
order: 220
keywords: [evento, eventos, torneo del evento, habilitar torneo, activar torneo, configuración del torneo, jornada, quedada, convención, pesos de puntuación, puntos por victoria, desempates, invitados, check-in, quitar torneo, deshabilitar torneo]
related: [core.panel.manage-events, tournaments.panel.create-tournament, tournaments.panel.scoring, tournaments.panel.tournament-lifecycle]
conditions:
  permissions:
    all: ['events.update']
---
Cada evento puede tener un torneo. Desde el propio evento puedes crearlo y ajustar lo básico sin salir de su ficha. Para el resto de opciones, usa el formulario del torneo en **Torneos** (lo explica la guía «Crear un torneo»).

## Abrir la configuración

1. En el menú lateral, abre **Contenido** y elige **Eventos**.
2. Entra en el evento.
3. Baja hasta la pestaña **Configuración del torneo**.
4. Al terminar, pulsa **Guardar configuración**. Sale el aviso «Configuración guardada».

## Activar el torneo

Activa **Habilitar torneo**. Al hacerlo aparecen los demás campos, con el nombre del evento como **Nombre del torneo**. Al guardar, el torneo se crea como **Borrador**: no se ve en la web hasta que abres su inscripción desde **Torneos**.

> [!WARNING]
> Si desactivas **Habilitar torneo** y guardas, el torneo se borra, con sus inscripciones, sus rondas y sus resultados. Sale el aviso «Torneo deshabilitado».

## Los campos

- **General**: **Nombre del torneo** y **Descripción**.
- **Capacidad**: **Máximo de participantes** (vacío, sin límite), **Mínimo de participantes** (para poder iniciarlo) y **Número de rondas** (vacío, sin tope).
- **Fechas**: **Apertura de inscripción** y **Cierre de inscripción**. El cierre no puede pasar del inicio del evento.
- **Puntuación**: en **Pesos de puntuación**, los puntos de cada resultado (**Victoria**, **Empate**, **Derrota** y **Bye**). En **Criterios de desempate**, los desempates, que se aplican en el orden en que los eliges.
- **Opciones**: **Reporte de resultados**, **Permitir invitados**, **Requiere check-in** y **Minutos antes para check-in**. Si tu web tiene encuestas, también el campo **Encuesta al terminar**, con su propia ayuda.

La guía «Crear un torneo» explica qué decide cada opción.

## Lo que esta pestaña no cambia

Estas opciones solo están en el formulario del torneo, en **Torneos**: la **Organización**, el **Perfil de torneo**, la imagen, **Mostrar participantes**, **Requiere confirmación manual**, **Permitir auto check-in**, el **Email de notificaciones** y las estadísticas, las reglas avanzadas y los emparejamientos.

Un torneo creado desde aquí no tiene **Email de notificaciones**: añádelo en su formulario si quieres recibir los avisos de inscripciones, bajas y resultados discutidos.

Si el torneo usa reglas que esta pestaña no sabe mostrar, como condiciones con estadísticas, verás «Este torneo usa reglas de puntuación o desempates avanzados: se editan en Torneos y aquí no se modifican». Si sus puntos o desempates vienen de un perfil de torneo, un aviso te dice de cuál; si los cambias aquí, pasan a ser del torneo y tienen prioridad sobre el perfil.

## Si algo no sale bien

- **«El cierre de inscripción no puede ser posterior al inicio del evento (…)»**: adelanta el cierre o déjalo vacío.
- **No ves la pestaña Configuración del torneo**: el módulo de torneos no está habilitado.

[[action:filament.admin.resources.events.index|Ver los eventos]]
