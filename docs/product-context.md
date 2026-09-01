# AI Gym Trainer — Contexto de producto (API)

> Documento de contexto para el backend. Describe **qué** construimos y **por qué**.
> El detalle técnico (modelo de datos, endpoints, agentes IA) va en `docs/plans/`.

## 1. Visión

Un **entrenador personal impulsado por IA**. El usuario define un perfil mínimo y la
aplicación genera su rutina de gimnasio semana a semana, dividida por grupos
musculares. El usuario registra cada serie que hace (peso, repeticiones, RPE). Al
cerrar cada día de entrenamiento la IA analiza ese avance y deja, por ejercicio, una
recomendación para la próxima vez: a qué peso avanzar y cuántas series y/o
repeticiones hacer, con su explicación. Esas recomendaciones acumuladas alimentan la
generación del ciclo de la semana siguiente.

## 2. Terminología

- **Rutina** — un programa de entrenamiento del usuario. El usuario puede tener
  **varias** rutinas, pero **solo una `active` a la vez** (el resto quedan
  `archived`). Al crear o activar una rutina nueva, la que estaba activa pasa a
  `archived` **de forma permanente**: queda de solo lectura (no se puede editar
  ni volver a activar) pero conserva todo su historial (ciclos, sesiones,
  recomendaciones) para consulta. No existe una acción manual de "archivar"
  aparte — archivar es siempre un efecto de activar otra rutina. Tiene `name`
  (etiqueta, ej. "Volumen invierno"), `goal` propio (`hypertrophy` / `strength` /
  `fat_loss` / `general_health` / `endurance`, independiente del `goal` global
  del perfil) y `days_per_cycle` (fijo en 5 en v1).
- **Ciclo** — una **semana** de esa rutina. Tiene `sequence_number` (1, 2, 3…) y
  estado (`generating` / `draft` / `active` / `completed` / `failed`). Por ahora
  **5 días por ciclo**; a futuro será configurable y elegible por el usuario.
- **Día del ciclo** — `order` 1..N dentro del ciclo, con `label` (ej. "Pecho") y
  grupos musculares foco. No está atado a un día de la semana concreto; el usuario
  entrena a su ritmo.
- **Ejercicio del día** — la **prescripción**: ejercicio, series, rango de reps,
  peso objetivo, RPE objetivo, descanso y un racional por ejercicio.
- **Sesión** — un día de entrenamiento realmente ejecutado (puede ser un día del
  ciclo o una sesión libre fuera de plan).
- **Registro de serie** — cada serie ejecutada: peso, reps, RPE opcional, nota.
- **Recomendación de ejercicio** — objetivo sugerido para la próxima vez que toque
  ese ejercicio (peso, series, reps, acción y explicación). Se genera al cerrar una
  sesión y se va reemplazando a medida que hay datos nuevos. Está acotada a
  `(usuario, rutina, ejercicio)`: cada rutina lleva sus propias recomendaciones.

## 3. Usuarios

- **Multiusuario** con autenticación (Sanctum modo SPA: cookie + CSRF).
- Cada usuario tiene sus rutinas, perfil, historial y recomendaciones, aislados por
  Policies (nadie ve datos de otro).
- Un usuario = un "entrenador personal" = **una rutina `active` (con su ciclo
  activo) a la vez**, más el historial de rutinas `archived` que fue dejando
  atrás (de solo lectura).

## 4. Bucle central del producto

1. **Onboarding** — el usuario carga su perfil de atleta:
   - Nivel de experiencia: `beginner` | `intermediate` | `advanced`
   - Días por semana disponibles
   - Duración de sesión (minutos)
   - Objetivo (`goal`): `hypertrophy` | `strength` | `fat_loss` | `general_health` | `endurance`
   - Notas libres (`notes`, nullable): lesiones, ejercicios que prefiere/evita,
     split preferido, limitaciones de equipo. Se pasa a la IA tal cual.
2. **Crear rutina / generar primer ciclo** — el usuario crea una rutina con `name`
   y `goal`, y opcionalmente un *hint* de texto libre ("quiero PPL", "que el lunes
   toque piernas", "full body en casa con mancuernas"). Al crearla queda `active`
   y la rutina que estaba activa pasa a `archived` **para siempre** (de solo
   lectura, no reactivable). Se dispara la generación del primer ciclo en un
   **job encolado**. Si más adelante quiere retomar el estilo de una rutina
   archivada, no la reactiva: crea una rutina nueva desde cero *(clonar una
   rutina archivada como punto de partida queda fuera de la v1)*.
3. **La IA arma el ciclo (una semana)** — decide el split (qué grupos musculares
   por día, 5 días por ahora), elige ejercicios y prescribe series, rango de
   repeticiones, peso objetivo, RPE objetivo y descanso. Devuelve un racional del
   split y un racional por ejercicio.
4. **Revisar y activar** — el ciclo nace como `draft`; el usuario lo activa. El
   ciclo anterior de la misma rutina pasa a `completed`.
5. **Entrenar y registrar, día a día** — por cada día entrenado el usuario crea una
   sesión y registra **cada serie**: peso, repeticiones, RPE opcional, nota.
6. **Análisis al cerrar cada día** — al completar la sesión, la IA analiza lo
   registrado en ese día y produce, **por ejercicio entrenado**, una *recomendación
   de ejercicio*: a qué peso avanzar, cuántas series y/o reps, una `action`
   (`advance_weight` / `hold` / `add_reps` / `add_set` / `deload` /
   `technique_focus`), `confidence` y explicación. Corre en un **job encolado**;
   cada recomendación nueva reemplaza (marca como `superseded`) la anterior del
   mismo ejercicio.
7. **Ver recomendaciones en vivo** — durante el ciclo en curso el usuario ya ve,
   para cada próximo día / ejercicio, el objetivo sugerido (ej. "próximo día de
   pecho → Press banca 82.5 kg, 3×8, subiste las 3×8 a RPE 7").
8. **Generar el ciclo siguiente** — el usuario pide el ciclo N+1 de la **rutina
   activa** (bajo demanda). La IA recibe: perfil + `goal` y `hint` de la rutina +
   **recomendaciones de ejercicio activas de esa rutina** + un **resumen de
   progresión** por ejercicio (prescrito vs. real de los días registrados,
   tendencia de peso/reps/RPE, señal de estancamiento). Genera la semana siguiente;
   las recomendaciones usadas quedan `applied`. Vuelve al paso 4.

## 5. Rol de la IA

- **Motor de progresión: 100% IA.** No hay reglas deterministas de "+2.5 kg si
  completaste todas las reps". La IA recibe el historial resumido por ejercicio y
  decide libremente peso, series y repeticiones, con explicación por ejercicio.
- **Dos momentos de análisis:**
  - *Al cerrar cada sesión* → agente corto que produce las recomendaciones de
    ejercicio de ese día (una llamada por sesión, no una por ejercicio).
  - *Al generar el ciclo N+1* → agente planificador que arma la semana completa
    apoyándose en las recomendaciones activas y el resumen de progresión.
- El **resumen de progresión** que consume la IA se calcula en el backend (PHP) a
  partir de los registros de series, para entregar dato limpio y acotado en tokens.
- **Nombres de ejercicios: IA libre**, pero normalizados. Cuando la IA nombra un
  ejercicio, el backend lo normaliza (slug sin acentos) y lo busca en el catálogo;
  si coincide lo reutiliza, si no lo inserta. Así el tracking y los gráficos por
  ejercicio quedan consistentes sin encasillar a la IA en un catálogo fijo.
- SDK: **`laravel/ai`** con agentes de salida estructurada. Providers `anthropic` y
  `openai` configurados; el default se elige por variable de entorno.

## 6. Alcance de la v1 (MVP)

La v1 es **solo el bucle central de IA**: perfil → generar ciclo → activar →
registrar series → recomendación → generar el siguiente. Todo lo periférico
(edición de rutina, históricos, progreso) queda para después.

**Incluye:**

- Registro / login / logout, perfil de atleta.
- **Varias rutinas por usuario** (una `active` a la vez): crear con `name` +
  `goal`. La anterior se archiva automáticamente y para siempre al crear/activar
  otra. En v1 la rutina no se edita después de crearla.
- Cada rutina con ciclos semanales (5 días fijos en v1) generados por IA (job
  encolado) con hint opcional.
- Revisión y activación de ciclos.
- Registro de sesiones y series (granularidad por serie); completar sesión.
- Análisis por IA al cerrar cada día → recomendaciones de ejercicio en vivo.
- Consultar las recomendaciones vigentes (`active`) de la rutina.
- Generación del ciclo siguiente apoyada en las recomendaciones + resumen de
  progresión por ejercicio (el resumen se calcula en el backend y solo alimenta a
  la IA; no se expone como endpoint de progreso al usuario en v1).

**Fuera de la v1:**

- Renombrar o editar el `goal` de una rutina mientras está `active` (en v1 se crea
  con `name` + `goal` y no se toca hasta generar otra).
- Endpoint de lectura de una rutina `archived` en detalle / solo lectura (el
  historial se conserva en la base, pero no hay endpoint dedicado en v1).
- Descartar un borrador de ciclo (en v1 el borrador se activa; no hay acción de
  descarte).
- Listar el historial de ciclos de una rutina.
- Listar el historial de sesiones.
- Endpoints de progreso: resumen (volumen semanal, PRs, adherencia) y series por
  ejercicio para gráficos (peso, volumen, e1RM en el tiempo).
- `days_per_cycle` configurable por el usuario (queda fijo en 5; el campo existe en
  la rutina pero no se expone para editar).
- Notificaciones / recordatorios push.
- Regeneración automática por scheduler (todo es bajo demanda).
- Reglas de deload automáticas fuera de lo que decida la IA.
- Varias rutinas `active` en paralelo (en v1 hay exactamente una activa a la vez).
- Reactivar o clonar una rutina `archived` (para retomar ese estilo hay que crear
  una rutina nueva desde cero en v1).
- Panel de administración para fusionar ejercicios duplicados (el dato se guarda
  y se loguea para un merge manual posterior).
- App móvil (la auth por cookie no lo impide del todo, pero no es objetivo v1).
- Multi-idioma, unidades en libras (v1 es **kg**).

## 7. Supuestos

- Equipo disponible: **gimnasio completo** (no hay campo estructurado; si aplica,
  el usuario lo aclara en `notes`).
- Unidades: **kilogramos**.
- Un ciclo = **1 semana = 5 días** en v1 (`days_per_cycle` en la rutina, no
  editable todavía).
- El usuario puede tener varias rutinas, pero **exactamente una `active`** a la vez;
  crear/activar otra archiva la anterior **de forma permanente** (no reactivable,
  no editable, historial visible en solo lectura). Archivar nunca es una acción
  manual independiente: es siempre efecto de activar otra rutina. Dentro de la
  rutina activa, un solo ciclo `active` y un solo ciclo `draft` a la vez.
- Cada rutina tiene su propio `goal`; el `goal` del perfil es la orientación general
  y sirve de valor por defecto al crear una rutina.
- Si el análisis de una sesión falla, la sesión igual queda completada; la
  recomendación simplemente no aparece hasta el reintento.

## 8. Stack

| Área | Decisión |
|---|---|
| Lenguaje | PHP 8.5 |
| Framework | Laravel 13, API REST JSON |
| IA | `laravel/ai ^0.6.8`, agentes `HasStructuredOutput` + `Promptable` |
| Providers IA | `anthropic` y `openai`, default por env |
| Auth | Laravel Sanctum (modo SPA: cookie + CSRF) |
| Async | Jobs encolados para generación de ciclo y para análisis de sesión (`database` queue en dev) |
| DB | PostgreSQL 17 (código agnóstico vía Eloquent; MySQL sigue siendo compatible) |
| Tests | Pest — feature por endpoint, unit para servicios, agentes con respuesta *fake* (sin llamadas reales a la IA) |
| Estilo | Laravel Pint |

## 9. Repos

- `gym-trainer-api/` — este proyecto (Laravel). Se construye y se prueba primero.
- `gym-trainer-spa/` — frontend React + Vite (proyecto hermano, se construye después).

## 10. Criterios de éxito

- Un usuario nuevo puede: registrarse → cargar perfil → generar su primer ciclo →
  activarlo → registrar los días de esa semana.
- Al cerrar el día de pecho, antes de generar nada más, el usuario ve el objetivo
  sugerido para el próximo día de pecho (peso, series, reps + explicación).
- El ciclo siguiente refleja ese avance: muestra recomendaciones de peso/series/reps
  distintas a las de la semana previa, con explicación por ejercicio basada en lo
  que el usuario realmente hizo.
- El catálogo de ejercicios queda consistente aunque la IA nombre el mismo
  ejercicio de formas distintas (normalización por slug), de modo que el tracking
  por ejercicio y la progresión que consume la IA son fiables.
- El suite de tests corre sin llamar a ningún proveedor de IA real.
