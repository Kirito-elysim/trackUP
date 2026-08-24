# Cahier des charges — TrackUp

**Plateforme de pilotage et de conformité formation, connectée à Rise Up (LMS)**

Ce document décrit l'intégralité des fonctionnalités, du modèle de données, de l'architecture
technique et des règles métier de l'application TrackUp, telle qu'elle existe aujourd'hui, dans le
but de permettre à une autre équipe (humaine ou IA) de la redévelopper à l'identique ou de s'en
inspirer comme base de référence.

---

## 1. Présentation générale

### 1.1 Contexte et objectif

TrackUp est une application interne de pilotage pédagogique et de conformité pour un organisme de
formation (EDUP-BS) qui utilise **Rise Up** comme LMS (plateforme e-learning). Rise Up expose une API
REST mais n'offre pas d'outils de pilotage transverses (tableaux de bord, alertes de conformité,
gestion des entreprises/tuteurs d'alternance, workflow de justification d'absences, etc.). TrackUp
comble ce manque en :

- **synchronisant quotidiennement** les données Rise Up (apprenants, formations, parcours, groupes,
  sessions, progression) dans une base locale ;
- offrant des **tableaux de bord et analyses** (temps de formation, progression, comparaisons de
  périodes) impossibles à obtenir nativement dans Rise Up ;
- gérant un **module d'alternance** (entreprises, tuteurs, apprenants) totalement absent de Rise Up ;
- automatisant la **détection et le suivi des absences** aux sessions (masterclass et présentiel),
  avec workflow de justificatif et alertes disciplinaires ;
- fournissant des **exports de conformité** (traçabilité complète par apprenant, journal de
  connexions) pour répondre aux exigences des organismes de certification (Qualiopi, financeurs).

### 1.2 Utilisateurs

- **Administrateurs / équipe pédagogique** (utilisateurs internes, comptes créés manuellement) —
  seuls utilisateurs authentifiés de l'application. Leurs droits sont déterminés par un système de
  rôles/permissions (voir §4).
- **Apprenants** — n'ont **aucun compte** dans TrackUp ; ils interagissent uniquement via un lien
  public à token sécurisé (dépôt de justificatif d'absence, voir §6.8), sans authentification.

### 1.3 Principe directeur : lecture/orchestration, pas d'écriture vers Rise Up

TrackUp est **unidirectionnel** vis-à-vis de Rise Up : il lit (synchronise) les données Rise Up en
local, mais n'écrit jamais dans Rise Up. Toutes les fonctionnalités de gestion (entreprises, tuteurs,
absences, rôles) sont propres à TrackUp et n'existent que localement.

---

## 2. Architecture technique

### 2.1 Stack

**Backend**
- PHP 8.4, Symfony 7.4 (framework-bundle, console, http-client, mailer, messenger, scheduler,
  security-bundle, serializer, validator, dotenv)
- Doctrine ORM 3.6 + Doctrine Migrations Bundle 4.0 (MySQL 8.4)
- `lexik/jwt-authentication-bundle` 3.2 — authentification par JWT
- `nelmio/cors-bundle` 2.6 — CORS
- Redis (transport Messenger asynchrone)
- PHPUnit 12 pour les tests

**Frontend**
- React 19.2 + React Router DOM 7.13 (SPA, routes chargées en lazy-loading)
- Vite 8 + TypeScript 5.9
- Tailwind CSS 4.3 (configuration CSS-first via `@theme`, pas de `tailwind.config.js`)
- Radix UI (Dialog, Tabs, Slot) comme primitives headless sous un design system maison
  (`components/ui/*`)
- `class-variance-authority` + `clsx`/`tailwind-merge` pour les variantes de composants
- `lucide-react` (icônes), `recharts` (graphiques)
- Vitest + Testing Library pour les tests
- **Pas de librairie de state management globale** (pas de Redux/Zustand/React Query) : chaque page
  gère son propre `useState`/`useEffect` et un helper `apiRequest()` partagé.

**Infrastructure (Docker Compose)**
- `backend` — serveur PHP (port 8080)
- `worker` — `messenger:consume async` (jobs asynchrones : sync sessions, sync groupes/memberships,
  sync groupée manuelle)
- `scheduler` — `messenger:consume scheduler_default` (déclenche les tâches planifiées, voir §5.4)
- `frontend` — serveur de dev Vite (port 5173)
- `mysql` — base de données
- `redis` — transport Messenger
- `phpmyadmin` — administration MySQL
- `mailer` — serveur SMTP de développement (type Mailpit) capturant tous les emails sans jamais les
  délivrer réellement, avec interface web (port 8025) — **usage développement/test uniquement**, en
  production `MAILER_DSN` doit pointer vers un vrai relais SMTP.

### 2.2 Environnement / configuration

Variables d'environnement clés (`.env` / `.env.local`) :
- `DATABASE_URL`, `MESSENGER_TRANSPORT_DSN` (Redis), `MAILER_DSN`, `MAILER_FROM_ADDRESS`,
  `FRONTEND_URL`, `CORS_ALLOW_ORIGIN`
- `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` / `JWT_PASSPHRASE`
- `RISEUP_API_BASE_URL`, `RISEUP_API_PUBLIC_KEY`, `RISEUP_API_PRIVATE_KEY` (OAuth2 client credentials)
- `ABSENCES_DISCIPLINARY_ALERT_EMAIL` (destinataire des alertes disciplinaires absences)
- `VITE_API_BASE_URL` (frontend → URL du backend)

### 2.3 Client API frontend

Toutes les pages appellent le backend via un helper unique `apiRequest<T>(path, options)`
(`frontend/src/lib/api.ts`) :
- ajoute `Authorization: Bearer <token>` automatiquement si un token est fourni ;
- sérialise le body en JSON, parse la réponse JSON ;
- lève une `ApiError` (avec `status`) sur réponse non-`ok`, message extrait de `payload.message` ou
  `payload.error` ;
- déclenche un handler global de déconnexion sur toute réponse `401` (voir §4.5) ;
- `apiUrl(path)` est exposé séparément pour les cas hors JSON (upload multipart, téléchargement de
  fichier/CSV en blob).

---

## 3. Modèle de données

Toutes les entités Doctrine sont regroupées ci-dessous par domaine. Sauf mention contraire, chaque
entité synchronisée depuis Rise Up porte un champ `externalId` (identifiant Rise Up, unique) et des
timestamps `riseUpCreatedAt`/`riseUpUpdatedAt`/`syncedAt`.

### 3.1 Authentification & permissions

| Entité | Table | Champs clés | Relations |
|---|---|---|---|
| `User` | `users` | `email` (unique), `password` (hashé), `firstName`, `lastName`, `active` (bool), `createdAt`/`updatedAt`, `resetToken` (unique, nullable), `resetTokenExpiresAt` | ManyToMany → `Role` (`user_role`) |
| `Role` | `roles` | `code` (unique, majuscule), `name`, `description`, `system` (bool — protège les rôles intégrés) | ManyToMany → `Feature` (`role_feature`) ; ManyToMany ← `User` |
| `Feature` | `features` | `code` (unique, ex. `absences.view`), `name`, `category` (regroupement UI), `description` | ManyToMany ← `Role` |

`User::getRoles()` retourne toujours `ROLE_USER` + `ROLE_<CODE_MAJUSCULE>` par rôle assigné.

### 3.2 Données Rise Up synchronisées — apprenants, formations, parcours

| Entité | Table | Champs clés | Relations |
|---|---|---|---|
| `Learner` | `learners` | `externalId` (unique), `username`, `email`, `firstName`, `lastName`, `language`, `phoneNumber`, `timezone`, `riseUpRole`, `type`, `state`, `activatedAt`, `suspendedAt`, `lastLoginAt` + **champs locaux absences** : `consecutiveUnjustifiedMasterclassAbsences` (int), `disciplinaryAlertSentAt`, `absenceCounterResetAt` | ManyToOne → `Tutor` (nullable, SET NULL) ; ManyToOne → `Company` (nullable, SET NULL) |
| `Training` | `trainings` | `externalId` (unique), `title`, `reference`, `description`, `language`, `objective`, `eduDuration`, `state`, `externalLink`, `type`, `sequential` (bool) | — |
| `TrainingModule` | `training_modules` | `externalId` (unique), `title`, `description`, `eduDuration`, `duration`, `type`, `reference`, `position`, `language` | ManyToOne → `Training` (CASCADE) |
| `TrainingStep` | `training_steps` | `externalId` (unique), `title`, `description`, `type`, `position`, `content` (text), `reference` | ManyToOne → `TrainingModule` (CASCADE) |
| `TrainingRegistration` | `training_registrations` | `externalId` (unique), `companyExternalId`, `validatorExternalId`, `registeredByExternalId`, `state`, `totalTime`, `progress` (%), `score`, `forceFinished`, `reference`, `subscribedAt`, `trainingEndAt`, `coursePeriodExternalId` | ManyToOne → `Learner` (CASCADE) ; ManyToOne → `Training` (CASCADE) |
| `LearnerStepState` | `learner_step_states` | `externalId` (unique), `state`, `timeSpent`, `totalTime`, `score` (float), `activityAt` | ManyToOne → `Learner` (CASCADE) ; ManyToOne → `TrainingStep` (CASCADE) |
| `LearningPath` | `learning_paths` | `externalId` (unique), `title`, `reference`, `language`, `description`, `sequential` (bool), `imageUrl` | — |
| `LearningPathTraining` | `learning_path_trainings` | `position`, `isRequired` (bool, défaut true) — **lien local**, non synchronisé | ManyToOne → `LearningPath` (CASCADE) ; ManyToOne → `Training` (CASCADE) ; unique (path, training) |
| `LearningPathRegistration` | `learning_path_registrations` | `externalId` (unique), `reference`, `score`, `progress`, `subscribedAt` | ManyToOne → `LearningPath` (CASCADE) ; ManyToOne → `Learner` (CASCADE) ; unique (path, learner) |
| `RiseUpGroup` | `riseup_groups` | `externalId` (unique), `name`, `reference`, `hidden` (bool), `community` (bool) | — |
| `RiseUpGroupLearningPath` | `riseup_group_learning_paths` | `learningPathExternalId` (int, pas de FK) | ManyToOne → `RiseUpGroup` (CASCADE) ; unique (group, path externalId) |
| `RiseUpLearnerGroup` | `riseup_learner_groups` | — (table de jonction) | ManyToOne → `Learner` (CASCADE) ; ManyToOne → `RiseUpGroup` (CASCADE) ; unique (learner, group) |

### 3.3 Sessions classe virtuelle / masterclass / présentiel

| Entité | Table | Champs clés | Relations |
|---|---|---|---|
| `ClassroomSession` | `classroom_sessions` | `externalId` (unique), `startAt`, `endAt`, `state`, `sessionType`, `location`, `seats`, `meetingUrl`, `description`, `room`, `language`, `eduDuration`, `reference`, `subscriptionCount` | ManyToOne → `TrainingModule` (nullable, SET NULL) ; ManyToOne → `Training` (nullable, SET NULL) |
| `ClassroomSessionRegistration` | `classroom_session_registrations` | `externalId` (unique), `subscribedAt`, `state`, `attended` (bool), `reference`, `eduDuration` | ManyToOne → `Learner` (CASCADE) ; ManyToOne → `ClassroomSession` (CASCADE) ; ManyToOne → `TrainingRegistration` (nullable, SET NULL) |
| `ClassroomSessionSignature` | `classroom_session_signatures` | `attendanceDate` (date), `period` (ex. matin/après-midi), `hasSigned` (bool), `signatureDate` | ManyToOne → `ClassroomSessionRegistration` (CASCADE) ; unique (registration, date, période) |

> **Constat important pour la reprise du projet** : dans les données actuelles, 100 % des sessions
> synchronisées sont de type `virtual` (masterclass). Le type `présentiel` est prévu dans le modèle
> mais n'a jamais été alimenté par Rise Up pour ce compte — à garder à l'esprit lors des tests.

### 3.4 Journal de connexions (import manuel, hors API)

| Entité | Table | Champs clés |
|---|---|---|
| `RiseUpActivityLog` | `riseup_activity_logs` | `sourceFileName`, `sourceImportedAt`, `trainingExternalId`, `learnerExternalId` (nullable), `learnerEmail` (nullable), `loginAt`, `logoutAt` (nullable), `durationSeconds`, `device` (nullable), `rowFingerprint` (unique, SHA-256 anti-doublon), `createdAt` |

> **Limite connue de Rise Up** (validée avec le support Rise Up dans le cadre de ce projet) : **aucun
> endpoint API ni webhook** n'expose les logs de connexion/déconnexion par apprenant. La seule source
> est un export manuel XLSX/CSV ("Journal d'activité") depuis l'interface d'administration Rise Up, en
> fréquence hebdomadaire/mensuelle au mieux, à importer manuellement dans TrackUp. C'est une limite
> externe à documenter clairement pour toute reprise du projet — pas un défaut de TrackUp.

### 3.5 Alternance — entreprises, tuteurs, prospects

| Entité | Table | Champs clés | Relations |
|---|---|---|---|
| `Company` | `companies` | `name`, `siret`, `address`, `postalCode`, `city`, `contactName`, `contactEmail`, `contactPhoneMobile`, `contactPhoneFixe` + `createdAt`/`updatedAt`/`deletedAt` (**soft delete**) | ManyToOne → `Sector` (nullable, SET NULL) ; ManyToMany → `Tutor` ; OneToMany → `Learner` |
| `Tutor` | `tutors` | `firstName`, `lastName`, `email`, `phoneMobile`, `phoneFixe`, `address`, `postalCode`, `city`, `dateOfBirth` + timestamps + `deletedAt` (**soft delete**) | ManyToMany → `Company` (`company_tutor`, CASCADE) ; OneToMany → `Learner` |
| `Sector` | `sectors` | `name` + timestamps + `deletedAt` (**soft delete**) | référencé par `Company` |
| `Prospect` | `prospects` | `email` (clé de rapprochement — **pas de FK**, volontairement, pour ne jamais être écrasé par la synchro Rise Up ni exiger que l'apprenant existe déjà), `phoneMobile`, `phoneFixe`, `address`, `postalCode`, `city`, `dateOfBirth`, `comment` + timestamps + `deletedAt` (soft delete) | — |

### 3.6 Module Absences

| Entité | Table | Champs clés | Relations |
|---|---|---|---|
| `Absence` | `absences` | `type` (`masterclass`\|`presentiel`), `status` (`en_attente`\|`justifiee`\|`non_justifiee`\|`autre`), `detectedAt`, `justificationToken` (unique, nullable), `justificationTokenExpiresAt`, `justificationFilePath`, `justificationFileOriginalName`, `justificationSubmittedAt`, `validatedAt`, `adminNote` (text), `notificationSentAt`, `confirmationSentAt` | ManyToOne → `ClassroomSessionRegistration` (unique, CASCADE) ; ManyToOne → `User` (`validatedBy`, nullable, SET NULL) |

Champs de suivi disciplinaire portés directement par `Learner` (voir §3.2) :
`consecutiveUnjustifiedMasterclassAbsences`, `disciplinaryAlertSentAt`, `absenceCounterResetAt`.

### 3.7 Journal de synchronisation

| Entité | Table | Champs clés | Relations |
|---|---|---|---|
| `SyncRun` | `sync_runs` | `triggerType` (`scheduled`\|`manual`), `status` (`running`\|`success`\|`partial`\|`failed`), `startedAt`, `finishedAt`, `steps` (JSON — `{command,label,status,durationMs,output}` par étape), `currentStepIndex`, `currentStepLabel` (pour le polling temps réel) | ManyToOne → `User` (`triggeredBy`, nullable, SET NULL) |

---

## 4. Authentification & gestion des droits

### 4.1 Authentification

- JWT via `lexik/jwt-authentication-bundle`. Deux firewalls Symfony :
  - `login` (`^/api/auth/login`, stateless) : `json_login` (champs `email`/`password`), succès →
    handler Lexik qui émet le JWT, échec → handler personnalisé. Un `UserChecker` personnalisé
    vérifie le flag `active` du compte.
  - `api` (`^/api`, stateless) : garde JWT (`jwt: ~`) sur toutes les routes, provider Doctrine sur
    `User::email`.
- Routes publiques explicites (`PUBLIC_ACCESS`) : `/api/auth/login`, `/api/auth/forgot-password`,
  `/api/auth/reset-password`, `/api/health`, `/api/absences/justification`. Tout le reste sous `/api`
  exige `ROLE_USER`.
- **Mot de passe oublié** : `POST /api/auth/forgot-password` — réponse générique systématique (anti
  énumération de comptes) ; si le compte existe et est actif, génère un token
  (`bin2hex(random_bytes(32))`, en clair en base), valable 1h, envoie un email avec lien
  `{FRONTEND_URL}/reset-password?token=...`. `POST /api/auth/reset-password` — vérifie
  token+expiration, hash le nouveau mot de passe, invalide le token (usage unique).

### 4.2 Modèle RBAC (Role-Based Access Control)

- Une **Feature** = une capacité unitaire identifiée par un code (`dashboard.view`,
  `absences.manage`, etc.), regroupée par `category` pour l'affichage.
- Un **Role** porte un ensemble de Features (ManyToMany `role_feature`).
- Un **User** porte un ensemble de Roles.
- **Résolution** (`UserPermissionResolver`) :
  - `resolveFeatureCodes(user)` : si l'utilisateur a le rôle Symfony `ROLE_ADMIN` (rôle système), il
    reçoit **toutes** les features existantes sans mapping explicite (contournement admin) ; sinon,
    union des features de tous ses rôles.
  - `userHasFeature(user, code)` : `false` si non connecté ; `true` systématique si `ROLE_ADMIN` ;
    sinon vérifie l'appartenance au set résolu.
- Chaque action de contrôleur vérifie `userHasFeature($this->getUser(), '<code>')` en tête de méthode
  et renvoie **403** sinon (pattern répété manuellement dans chaque contrôleur, pas de middleware
  générique). **Exception** : `SyncController` utilise `#[IsGranted('ROLE_ADMIN')]` au niveau classe —
  déclenchement de synchronisation réservé aux administrateurs stricts, indépendamment du système de
  Features.
- `GET /api/me` expose `isAdmin` + la liste résolue des `features` au frontend, qui s'en sert comme
  unique primitive de gating (`canAccess(code)`), aussi bien pour la navigation que pour les routes et
  les actions conditionnelles en page.

### 4.3 Catalogue complet des Features

| Code | Nom | Catégorie | Description |
|---|---|---|---|
| `dashboard.view` | Dashboard | Pilotage | Voir le tableau de bord global |
| `analytics.view` | Analytics | Pilotage | Analyser l'activité, le temps tracé et la progression par période |
| `learningpaths.view` | Parcours | Pilotage | Consulter les parcours et leur synthèse |
| `learners.view` | Apprenants | Pilotage | Consulter les apprenants et leur suivi |
| `courses.view` | Formations | Pilotage | Consulter les formations et leur avancement |
| `exports.view` | Exports | Pilotage | Accéder aux exports PDF et CSV |
| `integrations.view` | Intégrations | Pilotage | Voir l'état des synchronisations Rise Up |
| `companies.view` | Entreprises & tuteurs | Pilotage | Gérer les entreprises, tuteurs, rattachement des apprenants (alternance) |
| `activity_logs.import` | Import de journaux Rise Up | Administration | Importer un export Rise Up (XLSX/CSV) — droit d'écriture distinct de la consultation |
| `settings.learningpaths` | Parcours | Administration | Superviser la synchronisation des parcours Rise Up |
| `settings.roles` | Rôles et permissions | Administration | Gérer les rôles et les droits |
| `settings.users` | Utilisateurs | Administration | Gérer les comptes utilisateurs internes |
| `absences.view` | Absences | Conformité | Consulter le tableau de bord des absences |
| `absences.manage` | Gestion des absences | Conformité | Valider/rejeter les justificatifs, changer le statut, ajouter une note interne |

Rôles système initiaux (seedés par `app:bootstrap-rbac`) : `ADMIN` (toutes les features, bypass
automatique) et `MANAGER` (les 7 features "Pilotage").

### 4.4 Administration Utilisateurs & Rôles (UI)

- **Page Utilisateurs** (`/users`, feature `settings.users`) : annuaire (nom, email, actif/inactif,
  rôles), formulaire de création (prénom/nom/email/mot de passe/actif + cases à cocher rôles).
  `POST/PUT /api/admin/users` (pas de suppression, seulement désactivation via `active`).
- **Page Rôles** (`/roles`, feature `settings.roles`) : bibliothèque des rôles existants (code, nom,
  description, badge système/personnalisé, features assignées), formulaire de création (code, nom,
  description, cases à cocher features **groupées par catégorie**). `POST/PUT /api/admin/roles` (le
  `code` n'est modifiable que si le rôle n'est pas `system`). `GET /api/admin/features` liste tout le
  catalogue.

### 4.5 Session côté frontend

- JWT stocké dans `localStorage` (`trackup.auth.token`).
- Au montage, vérifie l'expiration du JWT (décodage du payload, comparaison du claim `exp`) ; si
  expiré, purge immédiatement et affiche un état "session expirée".
- `AuthContext` s'enregistre comme handler global de 401 (`setUnauthorizedHandler`) : toute requête
  authentifiée recevant un 401 déclenche une déconnexion automatique sans que chaque page ait à le
  gérer.
- `ProtectedRoute` redirige vers `/login` si pas de token valide, en conservant l'URL d'origine
  (`state.from`) pour y revenir après connexion.
- `FeatureGate` (par route, dans `App.tsx`) : si l'utilisateur n'a pas la feature requise, redirige
  silencieusement vers `/dashboard` (pas de page 403 visible).

---

## 5. Intégration Rise Up

### 5.1 Authentification API

OAuth2 client-credentials contre `POST {RISEUP_API_BASE_URL}/v3/oauth/token` (Basic Auth avec
`RISEUP_API_PUBLIC_KEY:RISEUP_API_PRIVATE_KEY`). Token récupéré une fois par process (pas de cache
partagé entre requêtes).

### 5.2 Client générique

`RiseUpApiClient` : `get()`/`getCollection()` (pagination automatique via `limit`/`range`, taille de
page max 500, plafond de sécurité 500 pages), `request()` (Bearer token, traite 401/429/5xx comme des
échecs francs plutôt que de retourner un corps d'erreur comme si c'était une donnée valide).

**Quota documenté par Rise Up : 300 appels/minute (fenêtre glissante, non extensible)**. Le
`http_client.yaml` configure un retry automatique sur 429 (3 tentatives, backoff exponentiel avec
jitter) — insuffisant seul contre une boucle qui dépasse durablement le quota (voir le cas de la
synchro signatures ci-dessous, qui doit se cadencer elle-même).

### 5.3 Services de synchronisation (ressource Rise Up → table locale)

| Service | Endpoint(s) Rise Up | Table(s) locale(s) |
|---|---|---|
| `LearnerSyncService` | `GET /v3/users` | `learners` |
| `TrainingSyncService` | `GET /v3/courses` | `trainings` |
| `RiseUpGroupSyncService` | `GET /v3/groups` | `riseup_groups`, `riseup_group_learning_paths` |
| `RiseUpGroupMembershipSyncService` | `GET /v3/users/{id}` (par apprenant) | `riseup_learner_groups` |
| `TrainingModuleStepSyncService` | `GET /v3/modules`, `GET /v3/steps` | `training_modules`, `training_steps` |
| `LearningPathSyncService` | `GET /v3/learningpaths`, `GET /v3/learningpathregistrations` | `learning_paths`, `learning_path_registrations` |
| `TrainingRegistrationSyncService` | `GET /v3/courseregistrations` | `training_registrations` |
| `LearnerStepStateSyncService` | `GET /v3/userstepstates` | `learner_step_states` |
| `ClassroomSessionSyncService` | `GET /v3/classroomsessions`, `GET /v3/classroomsessionregistrations`, `GET /v3/classroomsessionregistrations/{id}/signatures` (**pas d'alternative en masse — un appel par inscription**, cadencé à 220ms d'intervalle pour rester sous 300/min) | `classroom_sessions`, `classroom_session_registrations`, `classroom_session_signatures` |

Chaque service isole les échecs par élément (try/catch autour de chaque appel individuel dans les
boucles à fort volume comme les signatures) pour qu'une erreur ponctuelle Rise Up ne fasse pas
échouer tout le lot.

### 5.4 Orchestration et planification

- `app:sync:all` (commande CLI) et `SyncOrchestratorService::runAll(trigger, user?)` exécutent les 9
  syncs **dans cet ordre de dépendance** : Apprenants → Formations → Groupes → Appartenances groupes →
  Modules/Étapes → Parcours → Inscriptions formation → Progression apprenants → Sessions & signatures.
- Une étape en échec **n'interrompt pas** les suivantes (préférence pour 8 jeux de données à jour + 1
  en erreur plutôt que tout bloquer).
- Crée/maintient un `SyncRun` : statut `running` dès le départ, `currentStepIndex`/`currentStepLabel`
  mis à jour avant chaque étape (polling temps réel), chaque étape ajoutée au tableau `steps` dès sa
  fin (pas en fin de run), statut final `success`/`partial`/`failed`, email de bilan envoyé à tous les
  utilisateurs ayant le rôle `ADMIN`.
- **Planification (Symfony Scheduler, `Schedule.php`)** :
  - `0 2 * * *` — synchro complète (9 étapes), planifiée
  - `0 3 * * *` — détection des absences (après la synchro, pour des émargements à jour)
  - `0 4 * * *` — expiration des délais de justification (après la détection)
- **Déclenchement manuel** : bouton "Tout synchroniser" (`POST /api/sync/all`) déclenche un message
  asynchrone (`RunManualSyncAllMessage`, transport Redis `async`, consommé par le worker Docker) ; le
  frontend suit la progression via polling de `GET /api/sync/runs/latest` (toutes les 2-3s tant que
  `running`). 9 boutons de déclenchement individuel existent également par jeu de données.
- Deux endpoints de sync (sessions, appartenances groupes) sont eux-mêmes asynchrones à l'appel direct
  (timeout HTTP trop long en synchrone) ; les 7 autres restent synchrones à l'appel individuel.

### 5.5 Import manuel du journal de connexions

`RiseUpActivityLogImportService` : import XLSX (parsing manuel via `ZipArchive`+`DOMDocument`, sans
librairie externe) ou CSV (délimiteur `;`) du "Journal d'activité" Rise Up.
- Colonnes obligatoires : `ID de la formation`, `Date de connexion`, `Date de déconnexion`, `Temps
  passé (heures)`. Colonnes optionnelles : `Identifiant d'utilisateur`, `Email`, `Appareil`.
- Déduplication par empreinte SHA-256 (`rowFingerprint` = hash des champs clés) — réimporter le même
  fichier ou des exports qui se chevauchent ne crée jamais de doublons.
- Flush par lots de 200 lignes.

---

## 6. Modules fonctionnels

### 6.1 Dashboard (`/dashboard`, feature `dashboard.view`)

- 4 indicateurs clés : Formations, Parcours, Apprenants, Temps de formation (année civile en cours).
- Métriques complémentaires calculées côté API (apprenants actifs, nb sessions/masterclass,
  émargements signés, temps total toutes périodes, progression moyenne) — disponibles pour extension
  future du widget.
- Grille de groupes Rise Up (hors groupes marqués `hidden`) : image (ou placeholder), effectif, nombre
  de parcours associés, temps cumulé, progression moyenne — clic → `/groups/:id`.

### 6.2 Analytics (`/analytics`, feature `analytics.view`)

Le module le plus riche fonctionnellement.

- **Sélecteur de période** : jour / 7 jours / 30 jours / mois / année / **personnalisée** (dates
  libres). Le backend calcule aussi automatiquement une **période de comparaison** de même durée
  immédiatement précédente.
- **Sourcing des données** : deux flux d'activité unifiés par UNION SQL — e-learning
  (`learner_step_states`) et sessions/masterclass (`classroom_session_registrations` avec présence ou
  durée positive) — filtrables par apprenant et/ou par parcours.
- **KPI** : temps tracé total / e-learning / masterclass, apprenants actifs, nb de parcours en
  périmètre, nb d'activités, progression moyenne, date de dernière synchro.
- **Comparaison** : delta absolu + delta % vs période précédente pour chaque métrique de temps.
- **Série temporelle** : graphique en barres empilées (e-learning + masterclass) par heure/jour/mois
  selon la granularité de la période sélectionnée (Recharts).
- **Table "Synthèse par parcours"** (top 6, triable) : apprenants, temps période, e-learning,
  masterclass, durée cible, % temps/parcours, % avancement.
- **Table "Temps et avancement par parcours"** (top 25 apprenant×parcours, triable) : progression
  formation et parcours, temps, dernière activité/connexion. **Clic sur une ligne** = "focus" qui
  affiche en dessous le détail formation-par-formation de cet apprenant sur ce parcours.
- **Export CSV** côté client de la table apprenant×parcours.

### 6.3 Apprenants (`/learners`, `/learners/:id`, feature `learners.view`)

- Recherche (nom/email, avec debounce) → liste ; sélection → panneau de détail inline (pas de route
  séparée pour le détail, même composant).
- Détail : profil Rise Up complet, rattachement tuteur/entreprise (édition si feature
  `companies.view`), informations "prospect" éditables (téléphones, adresse, date de naissance,
  commentaire — jamais écrasées par la synchro), onglets **Formations / Sessions / Activité récente /
  Absences**, badge d'alerte disciplinaire + bouton de réinitialisation du compteur (si
  `absences.manage`).

### 6.4 Formations (`/courses`, feature `courses.view`)

- Vue maître-détail : liste filtrable (titre/référence, statut publié/brouillon/archivé) à gauche,
  détail à droite (temps cumulé, progression moyenne, modules, sessions, top apprenants). Lecture
  seule.

### 6.5 Parcours (`/learningpaths`, `/learningpaths/:id`, feature `learningpaths.view`)

- Grille de cartes (image, effectif, nb formations, temps, progression) ; modale de détail rapide
  avant navigation complète.
- Détail : stats globales + table des apprenants inscrits (temps, progression, date d'inscription) ;
  clic sur une ligne ouvre une modale des sessions de cet apprenant sur ce parcours.

### 6.6 Groupes (`/groups/:id`, feature `dashboard.view`)

- Détail d'un groupe Rise Up : stats globales, grille des parcours associés, table des membres
  (temps, date d'adhésion) avec la même modale de détail de sessions par membre.

### 6.7 Alternance — Entreprises / Tuteurs (features `companies.view`)

**Entreprises** (`/companies`, `/companies/:id`)
- Liste paginée, recherche + filtres avancés (nom, SIRET, ville, code postal, email/téléphone
  contact, secteur).
- CRUD complet (création, édition, **suppression douce** = `deletedAt`, jamais de suppression
  physique), import CSV/XLSX en masse (rapprochement par SIRET/email, idempotent).
- Détail : identité, contact, tuteurs rattachés (avec rattachement/création inline d'un nouveau
  tuteur), apprenants rattachés.

**Tuteurs** (`/tutors`, `/tutors/:id`)
- Mêmes principes : liste + filtres, CRUD + suppression douce, détail avec entreprises multiples
  (ManyToMany) et apprenants suivis (rattachement via recherche d'apprenant).

**Règle de déduction automatique d'entreprise** : lors du rattachement d'un tuteur à un apprenant sans
préciser l'entreprise, si ce tuteur n'a qu'une seule entreprise, elle est assignée automatiquement ;
sinon la sélection manuelle est obligatoire (garde-fou anti-ambiguïté).

### 6.8 Absences (sous-section `/absences/*`, features `absences.view` / `absences.manage`)

Module de conformité central. Sous-section dédiée avec sa propre entrée de navigation groupée
("Absences") et sa propre palette de couleurs (voir §7.3), à la demande explicite du client, séparée
visuellement des couleurs générales de TrackUp mais avec les mêmes composants structurels
(Card/Table/etc.).

**Modèle de statut** : `en_attente` → `justifiee` | `non_justifiee` | `autre` (4ᵉ statut libre pour
les cas particuliers). **Type** : `masterclass` (session `virtual`) vs `presentiel` (tout le reste).

**Workflow automatisé en 4 étapes** :
1. **Détection** (cron quotidien, 3h00) : une inscription à une session dont l'heure de fin est
   passée, sans **aucune** signature (`hasSigned=true`) sur cette session → absence créée en
   `en_attente`. Une seule période signée sur une session à périodes multiples suffit à ne **pas**
   déclencher d'absence.
2. **Notification** : email automatique à l'apprenant avec un lien de dépôt sécurisé à token
   (`bin2hex(random_bytes(32))`, expiration 14 jours), à la création de l'absence.
3. **Réponse apprenant** : page **publique** (`/absences/justificatif?token=...`, aucune connexion
   requise) — dépôt PDF/JPG/PNG (10 Mo max), stocké sur disque local (`var/uploads/absences/`).
4. **Validation admin** : `PATCH /api/admin/absences/{id}` (valider/rejeter/reclasser + note interne)
   → email de confirmation à l'apprenant.

**Passage automatique en `non_justifiee`** (2ᵉ déclencheur, en plus du rejet admin explicite) :
expiration du délai de 14 jours sans dépôt (cron quotidien 4h00, `AbsenceExpiryService`).

**Alerte disciplinaire (3 absences masterclass injustifiées consécutives)** :
- Le compteur (`Learner.consecutiveUnjustifiedMasterclassAbsences`) est **recalculé** (jamais
  incrémenté à la main) à chaque événement pertinent : compte, en remontant les absences masterclass
  les plus récentes, celles en `en_attente` **ou** `non_justifiee` (une absence en attente compte
  déjà, sans attendre sa résolution), jusqu'à la première `justifiee`/`autre` qui casse la série.
- Au 3ᵉ franchissement : email à l'adresse configurée (`ABSENCES_DISCIPLINARY_ALERT_EMAIL`,
  typiquement `pedagogie@edup-bs.com`), `disciplinaryAlertSentAt` renseigné (empêche un renvoi
  redondant tant que la série reste ≥ 3).
- **Réinitialisation manuelle** (`POST /api/learners/{id}/absence-counter/reset`) : ne remet pas
  juste le compteur à 0, pose une date de référence `absenceCounterResetAt` — seules les absences
  détectées **après** cette date recomptent, pour éviter qu'un nouvel événement ne fasse
  instantanément remonter tout l'historique.

**Sous-section (4 vues)** :
- **Tableau de bord** (`/absences/dashboard`) : bannière, 4 stats, répartition par statut (barre +
  liste), comparatif par groupe, aperçu des alertes actives, tableau des absences récentes.
- **Liste** (`/absences`) : recherche + filtres (groupe/type/période) + **onglets de statut à
  compteurs** (Toutes/En attente/Justifiées/Non justifiées/Autre — les compteurs restent stables quel
  que soit l'onglet sélectionné), table avec actions rapides (valider/rejeter/détail), export CSV.
- **Détail d'une absence** (`/absences/:id`) : en-tête (statut/type/alerte), timeline du workflow en
  4 étapes dérivée des timestamps réels, carte justificatif, aperçu de l'email envoyé, changement de
  statut, note interne, lien vers la fiche apprenant.
- **Alertes** (`/absences/alertes`) : bannière, liste des alertes actives (détail des absences
  consécutives, boutons "Renvoyer l'email"/"Réinitialiser"), liste "à surveiller" (1-2 consécutives,
  pas encore alertées), encart règle métier.
- **Apprenants** (`/absences/apprenants`) : annuaire agrégé (total/justifiées/non
  justifiées/attente par apprenant, badge d'alerte).

Endpoints backend : `GET/POST /api/admin/absences[...]` (index, show, dashboard, alerts, learners,
alerts/{id}/resend, PATCH update) + `POST /api/absences/justification` (public) + `POST
/api/learners/{id}/absence-counter/reset`.

### 6.9 Exports (`/exports`, feature `exports.view`)

Dossier de conformité **par apprenant sélectionné** :
- Métriques globales (apprenants prêts, nb parcours, inscriptions émargées, sessions sans émargement
  — alerte conformité, temps tracé total).
- Pour l'apprenant choisi : temps plateforme/module/masterclass, émargements signés/non signés,
  synthèse par parcours, détail par formation, et un **journal chronologique unifié** fusionnant 4
  types de traces (inscription parcours, inscription formation, activité e-learning au niveau étape,
  présence en session avec statut de signature) — export CSV du journal complet.

### 6.10 Logs exacts Rise Up (`/riseup-logs`, feature `exports.view`)

- Table paginée des logs de connexion importés manuellement (voir §5.5/§6.9), filtrable (apprenant
  autocomplete, groupe, parcours, formation, plage de dates).
- Export CSV côté serveur (téléchargement de fichier).
- Import de fichier (`activity_logs.import`) — bouton dédié sur la page Synchronisation.

### 6.11 Intégrations (`/integrations`, feature `integrations.view`)

Vue **lecture seule** : mode/statut de connexion Rise Up, date de dernière synchro, nombre de jeux de
données et de lignes locales, détail de la dernière exécution groupée (statut, déclencheur, détail par
étape). Aucune action possible ici (les déclenchements sont sur `/sync`).

### 6.12 Synchronisation (`/sync`, feature `settings.users`, actions réservées `ROLE_ADMIN`)

- Une carte de statut par jeu de données synchronisable (9 au total), bouton de déclenchement
  individuel.
- Bouton "Tout synchroniser" (asynchrone, polling de progression).
- Zone d'upload pour l'import du journal d'activité Rise Up.

### 6.13 Profil (`/profile`, aucune feature — accessible à tout utilisateur connecté)

Carte d'identité (nom/email/rôles) + changement de mot de passe.

### 6.14 Authentification (pages publiques)

`/login`, `/forgot-password`, `/reset-password` — voir §4.1 pour la logique backend associée.

---

## 7. Design system & charte graphique

### 7.1 Typographie et ton visuel général

- Police de titres : **Sora** (600/700/800). Police de corps : **Plus Jakarta Sans** (400-800).
  Aucune police à chasse fixe nulle part — alignement des nombres via `.tabular` (`font-variant-numeric:
  tabular-nums`).
- Identité "chaleur SaaS" : fond lavande chaud, cartes blanches, dégradé de marque
  rose→orange utilisé avec parcimonie (bannières, avatars, éléments actifs).

### 7.2 Palette générale (Tailwind v4, tokens CSS `@theme`, `frontend/src/index.css`)

| Token | Valeur | Usage |
|---|---|---|
| `background` / `foreground` | `#f6f4fb` / `#1e1b2e` | fond app / texte |
| `card` / `card-foreground` | `#ffffff` / `#1e1b2e` | cartes |
| `muted` / `muted-foreground` | `#efedf9` / `#726f87` | zones neutres, texte secondaire |
| `border` / `input` | `#e7e3f3` | bordures, champs |
| `primary` | `#ff0f7b` (rose) | actions principales, focus ring |
| `accent` | `#ff6b2c` (orange) | second point du dégradé de marque |
| `destructive` | `#e5484d` | erreurs, rejets |
| `success` | `#1fae7d` | validations |
| `info` | `#5b8dee` | informatif |
| `sidebar*` | famille dédiée (`#fdfcff`, etc.) | barre latérale |

Rayons de bordure échelonnés (`--radius` à `--radius-2xl`). Utilitaires notables :
`.bg-gradient-brand`/`.text-gradient-brand` (dégradé diagonal rose→orange), `.shadow-soft`/
`.shadow-soft-hover` (ombres teintées violet), `.animate-rise-in` (entrée fade+translateY, respecte
`prefers-reduced-motion`).

### 7.3 Palette secondaire — module Absences

Le module Absences porte une **seconde palette isolée**, préfixée `abs-` (ex. `bg-abs-danger-100`,
`text-abs-brand-600`) : familles complètes `ink` (neutres), `brand` (bleu), `accent` (ambre/orange),
`success`, `warning`, `danger`, chacune sur 10-11 nuances. Deux dégradés dédiés
(`.bg-gradient-abs-hero`, `.bg-gradient-abs-alert`). Cette palette a été reprise à l'identique d'une
maquette externe fournie par le client, spécifiquement pour ce module, **à la demande explicite du
client** — un choix de conception délibéré à documenter clairement, pas une incohérence : le reste de
l'application utilise la palette générale (§7.2), le module Absences seul utilise cette seconde
palette. Composants dédiés : `AbsStatusChip`, `AbsTypeChip`, `AbsAlertBadge`, `AbsAvatar`
(`frontend/src/components/absences/`).

### 7.4 Composants du design system (`frontend/src/components/ui/`)

`Avatar`, `Badge` (variantes default/secondary/success/destructive/outline), `Breadcrumb`, `Button`
(CVA : default/secondary/outline/ghost/destructive × sm/default/lg/icon), `Card`/`CardHeader`/
`CardContent`/`CardFooter`, `Chip` (pastille de statut, plus de variantes que Badge : primary/accent/
info/success/destructive/neutral/gradient/onGradient), `CreatableSelect` (combobox recherche +
création à la volée), `Dialog` (Radix), `FormError`/`FormSuccess`/`FormField`, `Input`,
`PaginationBar`, `Progress` (couleur automatique selon la valeur), `SearchSelect` (combobox filtrée
côté client), `Select`, `Separator`, `Skeleton`, `CountUp` (animation de montée en valeur), `Table` +
`SortableHead` (en-tête cliquable avec icône de tri), `Tabs` (Radix).

### 7.5 Navigation

Barre latérale collapsible (icônes seules en mode réduit) + tiroir mobile. Items groupés par libellé
(ordre d'apparition = ordre des groupes) : **Pilotage** (Dashboard, Analytics, Parcours, Formations),
**Alternance** (Apprenants, Entreprises, Tuteurs), **Conformité** (Logs exacts, Exports),
**Absences** (Tableau de bord, Absences, Alertes, Apprenants — sous-section dédiée), **Administration**
(Intégrations, Synchronisation, Rôles, Utilisateurs). Chaque item filtré par `canAccess(feature)`.

---

## 8. Référence API — vue d'ensemble par domaine

Toutes les routes sont préfixées `/api`. Authentification JWT sauf mention "public". Autorisation par
Feature sauf mention contraire.

| Domaine | Préfixe | Notes |
|---|---|---|
| Auth | `/api/auth/*`, `/api/me*` | login public, forgot/reset public |
| Santé | `/api/health` | public |
| Dashboard | `/api/dashboard` | feature `dashboard.view` |
| Analytics | `/api/analytics` | feature `analytics.view` |
| Apprenants | `/api/learners*` | features `learners.view` / `companies.view` / `absences.manage` selon l'action |
| Parcours | `/api/learningpaths*` | feature `learningpaths.view` |
| Formations | `/api/trainings*` | feature `courses.view` |
| Groupes | `/api/groups*` | feature `dashboard.view` |
| Exports | `/api/exports` | feature `exports.view` |
| Logs Rise Up | `/api/riseup-activity-logs*` | feature `exports.view` (lecture) / `activity_logs.import` (import) |
| Intégrations | `/api/integrations` | feature `integrations.view` |
| Synchronisation | `/api/sync/*` | `ROLE_ADMIN` strict (pas de Feature) |
| Absences (public) | `/api/absences/justification` | public |
| Absences (admin) | `/api/admin/absences*` | features `absences.view` / `absences.manage` |
| Entreprises | `/api/admin/companies*` | feature `companies.view` |
| Tuteurs | `/api/admin/tutors*` | feature `companies.view` |
| Secteurs | `/api/admin/sectors*` | feature `companies.view` |
| Utilisateurs | `/api/admin/users*` | feature `settings.users` |
| Rôles | `/api/admin/roles*` | feature `settings.roles` |
| Features | `/api/admin/features` | feature `settings.roles` |

*(Le détail exact de chaque action — verbe HTTP, paramètres, forme de réponse — est dans les rapports
d'exploration source de ce document ; à redemander en détail si besoin pour l'implémentation d'une
route précise.)*

---

## 9. Points d'attention pour une reprise du projet

1. **Aucune donnée apprenant n'est modifiable côté Rise Up** — TrackUp est un miroir en lecture ; tout
   ce qui est "gérable" (entreprises, tuteurs, absences, rôles) est un ajout propre à TrackUp, stocké
   uniquement en local.
2. **Rate limit Rise Up (300 appels/min)** — toute nouvelle synchronisation à fort volume (comme les
   signatures) doit se cadencer elle-même ; le retry automatique seul ne suffit pas.
3. **Pas d'API de logs de connexion côté Rise Up** — confirmé avec le support Rise Up. Ne pas
   chercher à automatiser cette donnée sans nouvel accord contractuel avec Rise Up ; le flux manuel
   (import XLSX/CSV) est la seule voie actuelle.
4. **Emails en environnement de développement** — toujours utiliser un serveur SMTP de capture (type
   Mailpit) en local/test ; ne jamais tester un flux d'email contre le vrai `MAILER_DSN` de
   production sans confirmation explicite, car cela enverrait un email réel à un vrai destinataire.
5. **Suppression = suppression douce** partout où c'est pertinent (entreprises, tuteurs, secteurs,
   prospects) — jamais de `DELETE` physique sur ces entités.
6. **Système de permissions non centralisé** — chaque contrôleur vérifie sa propre feature en début de
   méthode (pas de middleware/annotation générique à part `#[IsGranted]` pour le cas isolé de
   `SyncController`). Une réécriture pourrait envisager de centraliser ce contrôle (attribut PHP
   personnalisé, voter Symfony) pour réduire la duplication.
7. **`SyncController` est le seul contrôleur "ROLE_ADMIN strict"** — tous les autres contrôleurs
   utilisent le système de Features, ce qui permet des rôles intermédiaires (ex. `MANAGER`) sans accès
   admin complet. À perpétuer si l'objectif est de garder une granularité de droits fine.
