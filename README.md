# Módulo de torneos

Sistema de gestión de torneos con algoritmo de emparejamiento suizo, sistema de puntuación flexible, soporte para invitados y panel de administración completo.

## Características

- **Emparejamiento suizo**: Algoritmo Swiss pairing con soporte para acelerado y aleatorio
- **Sistema de puntuación flexible**: Reglas configurables por resultado o estadísticas
- **Múltiples criterios de desempate**: Buchholz, Median, Head-to-Head, OMW%, y más (17 tipos)
- **Registro de participantes**: Usuarios autenticados e invitados sin cuenta
- **Sistema de check-in**: Ventana de check-in configurable antes del torneo
- **Reporte de resultados**: Admin, jugadores con confirmación o jugadores confiables
- **Perfiles de juego**: Templates predefinidos con estadísticas, reglas y desempates
- **Notificaciones por email**: Registro, cancelación, confirmación
- **Integración con eventos**: Un evento puede tener un torneo asociado (1:1)
- **Cancelación de invitados**: Vía token único sin necesidad de login
- **Panel de administración**: Recursos Filament completos con relation managers
- **Estadísticas de partida**: Stats configurables por jugador en cada match

## Instalación

```bash
# Descubrir y habilitar el módulo
php artisan module:discover
php artisan module:enable tournaments

# Ejecutar migraciones
php artisan migrate

# Opcional: Cargar perfiles de juego predefinidos
php artisan db:seed --class="Modules\\Tournaments\\Database\\Seeders\\GameProfileSeeder"
```

## Configuración

### Configuración global

Valores por defecto en `config/settings.php`:

| Opción | Descripción | Por defecto |
|--------|-------------|-------------|
| `default_result_reporting` | Quién puede reportar resultados | `admin_only` |
| `default_allow_guests` | Permitir inscripción de invitados | `false` |
| `default_requires_check_in` | Requerir check-in antes del torneo | `true` |
| `default_check_in_starts_before` | Minutos antes para abrir check-in | `60` |

## Proceso de creación de torneos

### Flujo de creación

1. **Crear evento** (requisito previo): El torneo debe estar vinculado a un evento existente
2. **Acceder al panel**: Panel admin → Torneos → Crear torneo
3. **Completar pestañas**: 8 pestañas de configuración
4. **Guardar**: El torneo se crea en estado `Borrador`
5. **Abrir inscripción**: Manualmente o automáticamente según fechas configuradas

### Diagrama del proceso

```
[Evento publicado] → [Crear torneo] → [Configurar 8 pestañas] → [Guardar]
                                                                    ↓
[Torneo en Borrador] → [Abrir inscripción] → [Cerrar inscripción] → [Iniciar]
                                                                         ↓
                                           [Generar rondas] → [Reportar resultados] → [Finalizar]
```

## Pestañas de configuración del torneo

El formulario de creación/edición contiene **8 pestañas**:

### Pestaña 1: General 📄

Información básica del torneo.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Evento asociado | Select | ✅ | Evento al que pertenece el torneo (1:1). Solo eventos publicados |
| Nombre del torneo | Texto | ✅ | Nombre visible del torneo (máx. 255 caracteres) |
| Descripción | Texto | ❌ | Descripción breve (máx. 1000 caracteres) |
| Imagen | Archivo | ❌ | Imagen representativa (máx. 2MB) |
| Perfil de torneo | Select | ❌ | Perfil predefinido que carga estadísticas, reglas y desempates |

**Nota sobre perfiles**: Al seleccionar un perfil (ej: "Warhammer 40K"), se cargan automáticamente las configuraciones de las pestañas 4-7. Los perfiles del sistema aparecen con ⭐.

### Pestaña 2: Capacidad 👥

Límites de participantes y rondas.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Máximo de participantes | Número | ❌ | Límite de plazas. Vacío = ilimitado |
| Mínimo de participantes | Número | ✅ | Mínimo para iniciar (por defecto: 2) |
| Número de rondas | Número | ❌ | Rondas a jugar. Vacío = calcula automáticamente |

**Cálculo automático de rondas**: Para N participantes, se recomiendan `ceil(log2(N))` rondas. Ej: 16 participantes = 4 rondas.

### Pestaña 3: Fechas 📅

Ventanas de inscripción.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Apertura de inscripción | Fecha/hora | ❌ | Cuándo se permite inscribirse. Vacío = manualmente desde admin |
| Cierre de inscripción | Fecha/hora | ❌ | Cuándo se cierra la inscripción. Debe ser posterior a apertura |

### Pestaña 4: Estadísticas 📊

Valores numéricos que se registran en cada partida.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Clave | Texto | ✅ | Identificador único (`snake_case`). Ej: `victory_points` |
| Nombre | Texto | ✅ | Nombre visible. Ej: "Puntos de victoria" |
| Tipo | Select | ✅ | `Entero`, `Decimal` o `Sí/No` |
| Valor mínimo | Número | ❌ | Validación: valor mínimo aceptado |
| Valor máximo | Número | ❌ | Validación: valor máximo aceptado |
| Obligatoria | Toggle | ❌ | Si es obligatorio al reportar partidas |

**Tipos de estadística**:

| Tipo | Descripción |
|------|-------------|
| `integer` | Número entero |
| `float` | Número decimal |
| `boolean` | Sí/No (verdadero/falso) |

**Ejemplo para Warhammer 40K**:
- `victory_points` - Puntos de victoria (0-100)
- `kill_points` - Puntos de bajas (entero)
- `painted` - Ejército pintado (sí/no)

### Pestaña 5: Reglas de puntuación 🧮

Cómo se calculan los puntos del torneo según el resultado de cada partida.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Nombre | Texto | ✅ | Nombre de la regla. Ej: "Victoria" |
| Puntos | Número | ✅ | Puntos otorgados. Ej: 3, 1, 0.5 |
| Prioridad | Número | ❌ | Mayor = se evalúa primero (default: 0) |
| Tipo de condición | Select | ✅ | Ver tipos abajo |
| Campos adicionales | Variable | Depende del tipo de condición |

**Tipos de condición**:

| Tipo | Descripción | Campos adicionales |
|------|-------------|--------------------|
| `result` | Resultado de partida (victoria/empate/derrota/bye) | Valor: `win`, `draw`, `loss`, `bye` |
| `stat_comparison` | Un stat vs otro stat del oponente | Estadística, operador |
| `stat_threshold` | Stat alcanza cierto valor | Estadística, operador, umbral |
| `margin_diff` | Diferencia entre stats | Estadística, operador, valor |

**Ejemplo estándar** (configuración por defecto):
- Victoria: 3 puntos (condición: resultado = `win`)
- Empate: 1 punto (condición: resultado = `draw`)
- Derrota: 0 puntos (condición: resultado = `loss`)
- Bye: 3 puntos (condición: resultado = `bye`)

### Pestaña 6: Configuración de desempates ⚖️

Criterios para resolver empates en puntos. Se aplican en orden.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Clave | Texto | ✅ | Identificador único (`snake_case`) |
| Nombre | Texto | ✅ | Nombre visible |
| Tipo | Select | ✅ | Método de cálculo (ver tipos) |
| Estadística | Texto | Según tipo | Para tipos basados en stats |
| Dirección | Select | ❌ | "Mayor es mejor" o "Menor es mejor" |
| Valor mínimo | Número | ❌ | Valor mínimo garantizado (ej: 0.33 para OMW%) |

**Tipos de desempate**:

| Tipo | Descripción |
|------|-------------|
| `buchholz` | Suma de puntos de todos los oponentes |
| `median_buchholz` | Buchholz excluyendo mejor y peor oponente |
| `progressive` | Suma acumulativa de puntos ronda a ronda |
| `owp` | Porcentaje de victorias de los oponentes |
| `oowp` | OMW% de los oponentes (segundo nivel) |
| `gwp` | Porcentaje de victorias del jugador en partidas |
| `ogwp` | GWP% de los oponentes |
| `head_to_head` | Resultado del enfrentamiento directo |
| `sonneborn_berger` | Suma de puntos de oponentes derrotados |
| `stat_sum` | Suma de una estadística |
| `stat_diff` | Diferencia de una estadística |
| `stat_average` | Media de una estadística |
| `stat_max` | Valor máximo de una estadística |
| `sos` | Fortaleza del calendario |
| `mov` | Margen de victoria acumulado |
| `random` | Desempate aleatorio |

**Ejemplo estándar** (configuración por defecto):
1. Buchholz (mayor es mejor)
2. Progresivo (mayor es mejor)

### Pestaña 7: Configuración de emparejamientos 🔀

Cómo se generan las parejas en cada ronda.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Método de emparejamiento | Select | ❌ | `Swiss estándar`, `Aleatorio`, `Swiss acelerado` |
| Ordenar por | Select | ❌ | `Puntos`, `Estadística`, `Aleatorio` |
| Estadística para ordenar | Texto | Según ordenar | Si ordenar = Estadística |
| Evitar repeticiones | Toggle | ❌ | No emparejar jugadores que ya se enfrentaron (default: sí) |
| Máximo de byes por jugador | Número | ❌ | Límite de descansos por jugador (default: 1) |
| Asignación de bye | Select | ❌ | `Jugador con peor clasificación`, `Aleatorio`, `Jugador con mejor clasificación` |

**Métodos de emparejamiento**:

| Método | Descripción |
|--------|-------------|
| `swiss` | Empareja jugadores con puntuación similar |
| `random` | Emparejamiento totalmente aleatorio |
| `accelerated` | Primera ronda divide en mitades (fuertes vs débiles) |

**Criterios de ordenación**:

| Criterio | Descripción |
|----------|-------------|
| `points` | Por puntos de torneo |
| `stat` | Por una estadística específica |
| `random` | Orden aleatorio |

**Asignación de bye**:

| Método | Descripción |
|--------|-------------|
| `lowest_ranked` | Jugador con peor clasificación |
| `random` | Aleatorio |
| `highest_ranked` | Jugador con mejor clasificación |

### Pestaña 8: Opciones ⚙️

Configuración general del torneo.

| Campo | Tipo | Obligatorio | Descripción |
|-------|------|-------------|-------------|
| Reporte de resultados | Select | ✅ | Quién puede reportar resultados |
| Permitir invitados | Toggle | ❌ | Inscripción sin cuenta de usuario |
| Mostrar participantes | Toggle | ❌ | Mostrar lista de inscritos públicamente |
| Requiere check-in | Toggle | ❌ | Obliga a confirmar asistencia |
| → Minutos antes para check-in | Número | Si requiere | Ventana de check-in antes del evento |
| → Permitir auto check-in | Toggle | Si requiere | Check-in desde la web pública |
| Requiere confirmación manual | Toggle | ❌ | Admin debe aprobar inscripciones |
| Email de notificaciones | Email | ✅ | Recibe avisos de inscripciones |

**Modos de reporte de resultados**:

| Modo | Descripción |
|------|-------------|
| `admin_only` | Solo administradores pueden reportar |
| `players_with_confirmation` | Jugadores reportan, el oponente debe confirmar |
| `players_trusted` | Jugadores reportan, se acepta automáticamente |

## Estados de torneo

| Estado | Valor | Descripción |
|--------|-------|-------------|
| Borrador | `draft` | No visible públicamente |
| Inscripciones abiertas | `registration_open` | Se aceptan inscripciones |
| Inscripciones cerradas | `registration_closed` | No se aceptan más inscripciones |
| En curso | `in_progress` | Torneo activo, rondas en juego |
| Finalizado | `finished` | Torneo completado |
| Cancelado | `cancelled` | Torneo cancelado |

### Transiciones permitidas

```
draft → registration_open → registration_closed → in_progress → finished
  ↓           ↓                    ↓                  ↓
cancelled  cancelled           cancelled          cancelled
```

## Estados de participante

| Estado | Valor | Descripción |
|--------|-------|-------------|
| Registrado | `registered` | Inscripción realizada |
| Confirmado | `confirmed` | Aprobado por administrador |
| Check-in realizado | `checked_in` | Confirmó asistencia |
| Retirado | `withdrawn` | Se retiró del torneo |
| Descalificado | `disqualified` | Descalificado por admin |

### Transiciones permitidas

```
registered → confirmed → checked_in
    ↓           ↓            ↓
withdrawn   withdrawn    withdrawn
                ↓            ↓
          disqualified  disqualified
```

## Perfiles de juego

Los perfiles permiten crear templates reutilizables con:

- **Estadísticas predefinidas**: Stats específicas del juego
- **Reglas de puntuación**: Configuración de puntos por resultado
- **Criterios de desempate**: Orden y tipos de tiebreakers
- **Configuración de emparejamientos**: Método y opciones de pairing

### Uso de perfiles

1. Crear perfil en **Torneos > Perfiles de juego**
2. Al crear un torneo, seleccionar el perfil en la pestaña General
3. Las configuraciones del perfil se cargan automáticamente
4. Pueden modificarse para el torneo específico

## Arquitectura

```
src/modules/tournaments/
├── config/
│   ├── module.php              # Configuración del módulo
│   └── settings.php            # Valores por defecto
├── database/
│   ├── migrations/             # Migraciones de base de datos
│   └── seeders/                # Seeders (GameProfileSeeder)
├── lang/
│   ├── en/messages.php         # Traducciones en inglés
│   └── es/messages.php         # Traducciones en español
├── resources/
│   ├── js/
│   │   ├── components/         # Componentes Vue
│   │   └── types/              # Tipos TypeScript
│   └── views/
│       └── filament/           # Vistas Blade para Filament
├── routes/
│   └── web.php                 # Rutas web públicas
├── src/
│   ├── Application/
│   │   ├── DTOs/               # Data Transfer Objects
│   │   │   └── Response/       # DTOs de respuesta
│   │   └── Services/           # Interfaces de servicios
│   ├── Domain/
│   │   ├── Entities/           # Tournament, Round, TournamentMatch, etc.
│   │   ├── Enums/              # TournamentStatus, ParticipantStatus, etc.
│   │   ├── Events/             # Eventos de dominio
│   │   ├── Exceptions/         # Excepciones de dominio
│   │   ├── Repositories/       # Interfaces de repositorios
│   │   ├── Services/           # Interfaces de servicios de dominio
│   │   └── ValueObjects/       # StatDefinition, ScoringRule, etc.
│   ├── Filament/
│   │   ├── Resources/          # TournamentResource, GameProfileResource
│   │   ├── RelationManagers/   # Participants, Rounds, Matches
│   │   └── Widgets/            # Widgets del dashboard
│   ├── Http/
│   │   ├── Controllers/        # Controladores web
│   │   └── Requests/           # Form Requests
│   ├── Infrastructure/
│   │   ├── Persistence/        # Repositorios Eloquent
│   │   └── Services/           # Implementaciones de servicios
│   ├── Listeners/              # Listeners de eventos
│   ├── Notifications/          # Notificaciones por email
│   └── Policies/               # Políticas de autorización
├── tests/
│   ├── Integration/            # Tests de integración
│   └── Unit/                   # Tests unitarios
├── module.json                 # Manifiesto del módulo
└── phpunit.xml                 # Configuración de tests
```

## Rutas públicas

| Método | Ruta | Nombre | Descripción |
|--------|------|--------|-------------|
| GET | `/torneos` | `tournaments.index` | Listado de torneos |
| GET | `/torneos/{slug}` | `tournaments.show` | Detalle del torneo |
| GET | `/torneos/{slug}/clasificacion` | `tournaments.standings` | Clasificación |
| GET | `/torneos/{slug}/rondas` | `tournaments.rounds` | Rondas y partidas |
| GET | `/torneos/{slug}/check-in` | `tournaments.check-in.show` | Página de check-in |
| POST | `/torneos/{slug}/check-in` | `tournaments.check-in.store` | Realizar check-in |
| GET | `/torneos/{id}/inscripcion` | `tournaments.registration.show` | Estado de inscripción |
| POST | `/torneos/{id}/inscripcion` | `tournaments.registration.store` | Inscribirse |
| DELETE | `/torneos/{id}/inscripcion` | `tournaments.registration.destroy` | Cancelar inscripción |
| GET | `/torneos/cancelar/{token}` | `tournaments.cancel-confirmation` | Página de cancelación (invitados) |
| DELETE | `/torneos/cancelar/{token}` | `tournaments.cancel-by-token` | Cancelar por token (invitados) |

## Componentes Vue

### TournamentList

Lista de torneos con filtros por estado.

```vue
<TournamentList :tournaments="tournaments" />
```

### TournamentDetail

Detalle del torneo con tabs para información, participantes, rondas y clasificación.

```vue
<TournamentDetail :tournament="tournament" />
```

### StandingsTable

Tabla de clasificación con puntos y tiebreakers.

```vue
<StandingsTable :standings="standings" :tiebreakers="tiebreakers" />
```

### RoundsList

Lista de rondas con partidas y resultados.

```vue
<RoundsList :rounds="rounds" />
```

### RegistrationButton

Botón de inscripción/cancelación para el torneo.

```vue
<RegistrationButton :tournament="tournament" />
```

### CheckInForm

Formulario de check-in para participantes.

```vue
<CheckInForm :tournament="tournament" />
```

## Eventos de dominio

| Evento | Cuándo se dispara |
|--------|-------------------|
| `TournamentCreated` | Al crear un torneo |
| `TournamentStarted` | Al iniciar el torneo (primera ronda) |
| `TournamentFinished` | Al finalizar todas las rondas |
| `TournamentCancelled` | Al cancelar el torneo |
| `ParticipantRegistered` | Al inscribirse un participante |
| `ParticipantWithdrawn` | Al retirarse un participante |
| `ParticipantDisqualified` | Al descalificar un participante |
| `RoundGenerated` | Al generar emparejamientos de una ronda |
| `RoundStarted` | Al iniciar una ronda |
| `RoundCompleted` | Al completar todas las partidas de una ronda |
| `MatchResultReported` | Al reportar resultado de una partida |
| `StandingsUpdated` | Al recalcular la clasificación |

## Permisos

| Permiso | Descripción |
|---------|-------------|
| `tournaments.view_any` | Ver listado de torneos |
| `tournaments.view` | Ver detalle de torneo |
| `tournaments.create` | Crear torneos |
| `tournaments.update` | Editar torneos |
| `tournaments.delete` | Eliminar torneos |
| `tournaments.manage_config` | Gestionar configuración del módulo |
| `tournaments.report_results` | Reportar resultados de partidas |
| `tournaments.manage_participants` | Gestionar participantes (confirmar, descalificar) |

## Panel de administración

### TournamentResource

Recurso principal para gestión de torneos:
- **Listado**: Filtros por estado, búsqueda por nombre
- **Creación/Edición**: Formulario con 8 pestañas
- **Relation managers**: Participantes, Rondas, Partidas

### GameProfileResource

Gestión de perfiles de juego predefinidos:
- **CRUD completo**: Crear, editar, eliminar perfiles
- **Configuración**: Stats, scoring rules, tiebreakers, pairing config

### Relation managers

- `ParticipantsRelationManager`: Gestionar inscripciones, check-in, estados
- `RoundsRelationManager`: Ver y gestionar rondas
- `MatchesRelationManager`: Reportar resultados, ver historial

### Widgets

- **Estadísticas de torneos**: Total activos, participantes, partidas completadas
- **Próximos torneos**: Lista de torneos por iniciar
- **Torneos en curso**: Torneos activos con progreso de rondas

## Tests

```bash
# Ejecutar todos los tests del módulo
php artisan test --filter=Tournament

# Ejecutar tests unitarios
cd src/modules/tournaments && ../../../vendor/bin/phpunit --testsuite=Unit

# Ejecutar tests de integración
cd src/modules/tournaments && ../../../vendor/bin/phpunit --testsuite=Integration
```

## Licencia

Este módulo es parte de GuildForge y está bajo la misma licencia del proyecto principal.
