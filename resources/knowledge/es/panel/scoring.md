---
id: tournaments.panel.scoring
title: Puntuación, desempates y emparejamientos
summary: Qué deciden las pestañas de reglas de un torneo o de un perfil, con las estadísticas, las reglas de puntuación, los desempates y el emparejamiento suizo explicados.
category: activities
audience: panel
order: 270
keywords: [puntuación, puntos, reglas de puntuación, puntos por victoria, empate, derrota, bye, estadísticas, puntos de victoria, desempate, desempates, buchholz, buchholz mediano, progresivo, omw, porcentaje de victorias, sonneborn-berger, enfrentamiento directo, margen de victoria, emparejamientos, sistema suizo, swiss, evitar repeticiones, asignación de bye, prioridad, condición]
related: [tournaments.panel.game-profiles, tournaments.panel.create-tournament, tournaments.panel.rounds, tournaments.panel.results]
conditions:
  permissions:
    any: ['tournaments:tournaments.create', 'tournaments:tournaments.update', 'tournaments:tournaments.manage_config']
---
Cuatro pestañas deciden las reglas de un torneo: **Estadísticas**, **Reglas de puntuación**, **Configuración de desempates** y **Configuración de emparejamientos**. Están en el formulario de cada torneo y en el de cada perfil de torneo, con los mismos campos.

## De dónde salen las reglas

- Al elegir un **Perfil de torneo** en el formulario del torneo, sus reglas se copian en estas pestañas, que llevan la marca **Heredado del perfil de juego**. Puedes cambiarlas para ese torneo sin tocar el perfil.
- Si una pestaña de puntuación o de desempates queda vacía, el torneo usa la del perfil.
- Sin perfil, el torneo empieza con 3 puntos por victoria, 1 por empate, 0 por derrota y 3 por bye, y los desempates Buchholz y Progresivo.

La pestaña **Configuración del torneo** de un evento solo muestra los puntos por resultado y una lista sencilla de desempates. Para lo demás, usa el formulario del torneo.

## Estadísticas

Son valores que se apuntan en cada partida, además del resultado, como los puntos de victoria de un juego de miniaturas. Sirven para las reglas de puntuación y los desempates. Por cada estadística:

- **Clave**: su identificador, solo minúsculas y guiones bajos (por ejemplo, `vp`). Es lo que usan las reglas.
- **Nombre**: lo que se ve al apuntarla.
- **Tipo**: **Entero**, **Decimal** o **Sí/No**.
- **Valor mínimo** y **Valor máximo**: opcionales, limitan lo que se puede apuntar.
- **Obligatoria**: si hay que rellenarla al registrar la partida.

Al registrar resultados, cada estadística sale para los dos jugadores, en el panel y en el formulario de los jugadores en la web.

## Reglas de puntuación

Cada regla da unos **Puntos** cuando se cumple su **Condición**. Para cada jugador y partida se miran las reglas de mayor a menor **Prioridad**, y cuenta la primera que se cumple. Si no se cumple ninguna, suma 0.

| Tipo de condición | Se cumple cuando |
|---|---|
| **Resultado de partida** | El jugador ha conseguido ese resultado: **Victoria**, **Empate**, **Derrota** o **Bye**. |
| **Comparación de estadísticas** | Su estadística cumple el **Operador** frente a la de su rival (por ejemplo, más puntos de victoria). |
| **Umbral de estadística** | Su estadística cumple el **Operador** frente al **Umbral** (por ejemplo, 10 o más). |
| **Diferencia de margen** | La diferencia entre su estadística y la de su rival cumple el **Operador** frente al **Umbral**. |

Si ninguna regla trata el bye, el bye cuenta como una victoria. En una **Doble derrota**, los dos jugadores puntúan como derrota.

## Desempates

Cuando dos jugadores tienen los mismos puntos, la clasificación los ordena con los desempates, en el orden de la lista: si el primero empata, se mira el segundo, y así. Por cada desempate eliges el **Tipo**, la **Dirección** (**Mayor es mejor** o **Menor es mejor**) y, si quieres, un **Valor mínimo**. Los de estadísticas piden además la **Estadística**.

Los más usados:

- **Buchholz**: la suma de los puntos de tus rivales. **Buchholz mediano** quita el mejor y el peor rival, si has tenido al menos tres.
- **Progresivo**: la suma de tus puntos acumulados ronda a ronda. Premia ir bien desde el principio.
- **Porcentaje de victorias de oponentes**, el OMW%: el porcentaje de victorias de tus rivales. Muchos juegos de cartas le ponen un mínimo de 0.33.
- **Porcentaje de victorias de partidas** y **de partidas de oponentes**: usan los puntos de cada partida, por ejemplo los juegos ganados en un al mejor de tres.
- **Enfrentamiento directo**: quién ganó cuando jugaron entre ellos.
- **Suma de estadísticas**, **Diferencia de estadísticas**, **Promedio de estadísticas** y **Máximo de estadísticas**: a partir de una estadística del torneo.
- **Margen de victoria**: la suma de lo que sacas a tus rivales en las partidas que ganas, con los puntos de cada partida.
- **Aleatorio**: un sorteo, como último recurso.

La página **Clasificación** de la web muestra siempre las columnas **Buchholz** y **OMW%**.

## Emparejamientos

- **Método de emparejamiento**: **Swiss estándar** enfrenta en cada ronda a jugadores con una puntuación parecida. **Aleatorio** empareja al azar.
- **Ordenar por**: cómo se ordena a los jugadores antes de emparejarlos: **Puntos**, **Estadística** (eliges cuál en **Estadística para ordenar**) o **Aleatorio**.
- **Evitar repeticiones**: intenta no emparejar a quien ya se ha enfrentado.
- **Máximo de byes por jugador**: cuántas veces puede descansar la misma persona.
- **Asignación de bye**: quién descansa cuando sois impares: **Jugador con peor clasificación**, **Aleatorio** o **Jugador con mejor clasificación**.

Los cambios en los emparejamientos cuentan desde la siguiente ronda que generes. Los de puntuación y desempates, desde el siguiente recálculo de la clasificación, que se hace con cada resultado y al generar cada ronda.

[[action:filament.admin.resources.game-profiles.index|Ver los perfiles de torneo|if=tournaments:tournaments.manage_config]]
