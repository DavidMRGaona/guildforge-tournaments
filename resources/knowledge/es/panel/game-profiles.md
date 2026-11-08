---
id: tournaments.panel.game-profiles
title: Perfiles de torneo
summary: Usa los perfiles del sistema o crea los tuyos, con las estadísticas, la puntuación, los desempates y los emparejamientos de cada juego, para no configurarlos en cada torneo.
category: activities
audience: panel
order: 280
keywords: [perfil de torneo, perfiles, perfil de juego, plantilla de torneo, reglas de un juego, warhammer, warhammer 40.000, age of sigmar, magic, magic the gathering, genérico, duplicar perfil, copiar perfil, perfil del sistema, crear perfil, puntuación por defecto, desempates por defecto]
related: [tournaments.panel.scoring, tournaments.panel.create-tournament]
conditions:
  permissions:
    all: ['tournaments:tournaments.manage_config']
---
Un perfil de torneo reúne las reglas de un juego: sus estadísticas, su puntuación, sus desempates y sus emparejamientos. Al crear un torneo eliges un perfil y sus reglas se cargan solas.

## Ver los perfiles

En el menú lateral, abre **Catálogos** (si está plegado, ábrelo) y elige **Perfiles de torneo**. La lista muestra el **Nombre**, la **Descripción**, si es un **Perfil del sistema** y cuántos **Torneos** lo usan.

## Los perfiles del sistema

El módulo trae cuatro: **Warhammer 40.000**, **Magic: The Gathering**, **Warhammer Age of Sigmar** y **Genérico**. En el formulario de un torneo llevan una estrella.

- Sus reglas las mantiene el módulo y se actualizan con él. Solo puedes cambiar su **Descripción**: el formulario lo avisa con «Este es un perfil del sistema. Solo se puede modificar la descripción.».
- No se pueden borrar.
- Para usar unas reglas parecidas con cambios, duplícalo.

## Crear un perfil

1. Pulsa **Crear perfil de torneo**.
2. En **General**, escribe el **Nombre** y, si quieres, una **Descripción**.
3. Rellena **Estadísticas**, **Reglas de puntuación**, **Configuración de desempates** y **Configuración de emparejamientos**. La guía «Puntuación, desempates y emparejamientos» explica cada campo.
4. Pulsa **Crear**.

## Duplicar un perfil

1. Abre el menú **⋯** del perfil que quieres copiar y pulsa **Duplicar**.
2. Escribe el **Nombre** del nuevo.
3. Confirma.

Sale «Perfil duplicado correctamente». La copia es un perfil tuyo: puedes cambiar todas sus reglas.

## Cambiar o borrar un perfil

Abre el menú **⋯** del perfil, pulsa **Editar**, cambia lo que necesites y pulsa **Guardar cambios**. Para quitar un perfil tuyo, pulsa **Borrar** en su ficha.

Al elegir un perfil en un torneo, sus reglas se copian en el torneo. Cambiar el perfil después no cambia esas copias: solo afecta a los torneos que lo elijan desde entonces y a los que no tengan reglas propias de puntuación o de desempates.

[[action:filament.admin.resources.game-profiles.index|Ver los perfiles de torneo]]
