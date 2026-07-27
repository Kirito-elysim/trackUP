# TrackUp

Base projet `TrackUp` en `Symfony + React + MySQL + Redis + Docker`.

## Contenu

- `backend/`: API Symfony, JWT, RBAC `User / Role / Feature`, worker Messenger
- `frontend/`: back-office React avec navigation filtrée par permissions
- `docker-compose.yml`: stack locale complète

## Démarrage

1. Lancer les services :

```bash
docker compose up --build
```

2. Appliquer la migration :

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```

3. Initialiser les rôles, features et le compte admin :

```bash
docker compose exec backend php bin/console app:bootstrap-rbac
```

Compte admin par défaut :

- email : `admin@trackup.local`
- mot de passe : `TrackUp123!`

## URLs

- Frontend : `http://localhost:5173`
- Backend API : `http://localhost:8080`
- Healthcheck : `http://localhost:8080/api/health`

## Fonctionnel déjà prêt

- login JWT
- endpoint `/api/me`
- gestion des rôles et features
- gestion des utilisateurs internes
- menu frontend filtré par feature
- blocage frontend et backend des accès non autorisés
- test OAuth `Rise Up` via `app:riseup:test-auth`
- probe générique d'endpoint `Rise Up` via `app:riseup:probe`

## Permissions (RBAC)

Chaque feature (`backend/src/Command/BootstrapRbacCommand.php`) protège un ou plusieurs endpoints via `UserPermissionResolver::userHasFeature()`. Le libellé de la feature ne reflète pas toujours à lui seul la portée exacte de ce qu'elle protège — cette table fait foi :

| Feature | Catégorie | Endpoints protégés | Portée réelle |
| --- | --- | --- | --- |
| `dashboard.view` | Pilotage | `GET /api/dashboard`, `GET /api/groups/{id}`, `GET /api/groups/{groupId}/members/{learnerId}/sessions` | Lecture du tableau de bord **et** des données de groupe/apprenants associées |
| `analytics.view` | Pilotage | `GET /api/analytics` | Lecture des métriques d'analytics |
| `learningpaths.view` | Pilotage | `GET /api/learningpaths`, `GET /api/learningpaths/{id}` | Lecture des parcours |
| `learners.view` | Pilotage | `GET /api/learners`, `.../sessions` | Lecture des apprenants |
| `courses.view` | Pilotage | `GET /api/trainings` | Lecture des formations |
| `exports.view` | Pilotage | `GET /api/exports`, `GET /api/riseup-activity-logs`, `GET /api/riseup-activity-logs/export` | **Lecture seule** : listing et export des journaux Rise Up. Ne donne pas de droit d'écriture. |
| `activity_logs.import` | Administration | `POST /api/riseup-activity-logs/import` | **Écriture** : upload d'un fichier XLSX/CSV qui insère des lignes dans `riseup_activity_logs`. Volontairement distincte de `exports.view` (voir historique : cette route était protégée à tort par `exports.view`, permettant à un rôle lecture seule d'injecter des données). |
| `integrations.view` | Pilotage | `GET /api/integrations` | Lecture de l'état des synchronisations |
| `settings.learningpaths` | Administration | — (réservée, non encore branchée sur un contrôleur) | Prévue pour la supervision de sync des parcours |
| `settings.roles` | Administration | `/api/admin/roles/*`, `/api/admin/features` | Gestion des rôles/permissions |
| `settings.users` | Administration | `/api/admin/users/*` | Gestion des comptes. Côté frontend, gate aussi l'accès à la page de synchronisation (`SyncManagementPage`), dont les endpoints (`/api/sync/*`) sont eux protégés côté backend par `#[IsGranted('ROLE_ADMIN')]` au niveau contrôleur. |

Les routes d'écriture de `SyncController` (`/api/sync/*`) et les CRUD de `Admin/RoleController`/`Admin/UserController` n'ont pas ce type de mismatch : elles utilisent soit `#[IsGranted('ROLE_ADMIN')]` au niveau classe, soit la même feature en lecture et en écriture de façon cohérente.

Après avoir ajouté ou modifié une feature dans `BootstrapRbacCommand.php`, pense à écrire une migration de données (`role_feature`/`features`) plutôt que de compter uniquement sur un re-run manuel de `app:bootstrap-rbac` en production — voir `migrations/Version20260727120000.php` pour l'exemple.

## Intégration Rise Up

Clés locales à placer dans `backend/.env.local` :

```dotenv
RISEUP_API_BASE_URL="https://<votre-instance-riseup>"
RISEUP_API_PUBLIC_KEY="..."
RISEUP_API_PRIVATE_KEY="..."
```

Commandes utiles :

```bash
docker compose run --no-deps --rm backend php bin/console app:riseup:test-auth
docker compose run --no-deps --rm backend php bin/console app:riseup:probe /v3/users
docker compose run --no-deps --rm backend php bin/console app:riseup:probe /v3/courses
docker compose exec backend php bin/console app:sync:learners
docker compose exec backend php bin/console app:sync:trainings
docker compose exec backend php bin/console app:sync:registrations
docker compose exec backend php bin/console app:sync:modules-steps
docker compose exec backend php bin/console app:sync:userstepstates
docker compose exec backend php bin/console app:sync:sessions
```

## Messenger / jobs asynchrones

Les syncs longues (`sessions`, `riseup-group-memberships`) tournent en tâche de fond via le worker Messenger (transport `async`, Redis). Un job qui échoue est retenté automatiquement (3 essais avec backoff), puis atterrit dans la file d'échec (`failed`), qui est **persistante en base MySQL** (table `messenger_messages`, transport `doctrine://`) — elle survit à un redémarrage du worker, contrairement à l'ancien transport `in-memory://`.

Un message qui échoue définitivement déclenche aussi un log de niveau `critical` (voir `src/EventListener/MessengerFailedMessageListener.php`), à surveiller dans les logs applicatifs.

Commandes utiles pour inspecter/rejouer les jobs en échec :

```bash
# Lister les messages en échec
docker compose exec backend php bin/console messenger:failed:show

# Voir le détail d'un message (stacktrace complète avec -vv)
docker compose exec backend php bin/console messenger:failed:show <id> -vv

# Rejouer un message en échec
docker compose exec backend php bin/console messenger:failed:retry <id>

# Rejouer tous les messages en échec, un par un avec confirmation
docker compose exec backend php bin/console messenger:failed:retry

# Supprimer définitivement un message en échec (ne sera plus rejouable)
docker compose exec backend php bin/console messenger:failed:remove <id>
```

---

## 🚀 Déploiement en Production (Coolify)

### Architecture

L'application utilise une architecture réseau optimisée pour Coolify :
- **Réseau `coolify`** : Permet au reverse proxy Traefik d'accéder aux services exposés (frontend, phpmyadmin)
- **Réseau `internal`** : Communication privée entre les services (backend, worker, mysql, redis)
- **Pas de `container_name` fixes** : Coolify génère les noms dynamiquement

### Prérequis

- Serveur Coolify actif avec Docker
- Domaines configurés (DNS A records pointant vers votre serveur)
- Repository Git : https://github.com/momoSfp/trackUP.git

### Étape 1 : Préparer les secrets

Générez des secrets forts pour la production :

```bash
# APP_SECRET (32 caractères minimum)
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"

# JWT_PASSPHRASE (64 caractères)
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# MYSQL_PASSWORD, MYSQL_ROOT_PASSWORD, REDIS_PASSWORD
openssl rand -base64 32
```

### Étape 2 : Configurer sur Coolify

1. **Créer un nouveau projet**
   - Dans Coolify → **Projects** → **+ New Project**
   - Nom : `TrackUp`

2. **Ajouter la ressource Docker Compose**
   - **+ Add Resource** → **Docker Compose**
   - Source : **Git Repository**
   - Repository URL : `https://github.com/momoSfp/trackUP.git`
   - Branch : `main`
   - Docker Compose File : `docker-compose.prod.yml`
   - Build Directory : `/`

3. **Configurer les variables d'environnement**

Dans Coolify → **Environment Variables**, ajoutez :

```env
# Backend - Symfony
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<votre_secret_32_chars>
JWT_PASSPHRASE=<votre_passphrase_64_chars>
CORS_ALLOW_ORIGIN=^https?://trackup[.]votredomaine[.]com$

# Database - MySQL
MYSQL_ROOT_PASSWORD=<votre_root_password>
MYSQL_PASSWORD=<votre_mysql_password>
DATABASE_URL=mysql://trackup:<votre_mysql_password>@mysql:3306/trackup?serverVersion=8.4.0&charset=utf8mb4

# Cache & Queue - Redis
REDIS_PASSWORD=<votre_redis_password>
MESSENGER_TRANSPORT_DSN=redis://:<votre_redis_password>@redis:6379/messages

# Frontend - React
# IMPORTANT : Utilisez /api pour le proxy Nginx vers le backend
VITE_API_BASE_URL=/api

# Rise Up (optionnel - uniquement si synchronisation Rise Up)
RISEUP_API_BASE_URL=https://<votre-instance-riseup>
RISEUP_API_PUBLIC_KEY=<votre_cle_publique>
RISEUP_API_PRIVATE_KEY=<votre_cle_privee>
```

**⚠️ Important** :
- Remplacez `<votre_mysql_password>` dans `DATABASE_URL` par votre vrai mot de passe
- Remplacez `<votre_redis_password>` dans `MESSENGER_TRANSPORT_DSN` par votre vrai mot de passe
- `VITE_API_BASE_URL=/api` permet au frontend de communiquer avec le backend via le reverse proxy

4. **Configurer les domaines**

**Service `frontend`** (Exposé publiquement) :
- Domain : `trackup.votredomaine.com`
- Port : `80`
- HTTPS : ✅ Activé (Let's Encrypt)
- **L'API est accessible via `/api`** (ex: `https://trackup.votredomaine.com/api/health`)

**Service `phpmyadmin`** (Optionnel) :
- Domain : `pma.votredomaine.com`
- Port : `80`
- HTTPS : ✅ Activé

**Service `backend`** : Pas de domaine public (communication interne uniquement)

5. **Déployer**
   - Cliquez sur **Deploy**
   - Attendez la fin du build (~5-10 min)
   - Surveillez les logs pour vérifier le build

### Étape 3 : Post-déploiement

Une fois tous les services **Running** et **Healthy** :

```bash
# Via l'interface Coolify
# Service "backend" → Terminal → Execute

# Ou via SSH sur le serveur (les noms de conteneurs sont générés par Coolify)
docker ps  # Pour voir les noms des conteneurs
docker exec -it <nom-du-conteneur-backend> sh
```

Puis dans le container backend :

```bash
# 1. Exécuter les migrations
php bin/console doctrine:migrations:migrate --no-interaction

# 2. Initialiser RBAC et créer l'admin
php bin/console app:bootstrap-rbac

# 3. Synchroniser le schéma (si nécessaire)
php bin/console doctrine:schema:update --force

# 4. Vérifier
php bin/console doctrine:schema:validate
```

### Étape 4 : Tester l'application

#### Backend API
```bash
curl https://trackup.votredomaine.com/api/health
# Réponse attendue : {"status":"ok","service":"trackup-backend"}
```

#### Frontend
Ouvrez dans votre navigateur : `https://trackup.votredomaine.com`

**Credentials par défaut** :
- Email : `admin@trackup.local`
- Password : `TrackUp123!`

### Mises à jour

Pour déployer une nouvelle version :

```bash
# En local
git add .
git commit -m "Votre message"
git push origin main

# Sur Coolify
# Le redéploiement automatique se déclenchera (si activé)
# Ou cliquez sur "Redeploy" manuellement
```

### Ressources nécessaires

- **CPU** : 1-2 cores
- **RAM** : 2-4GB (minimum 2GB)
- **Stockage** : 10GB minimum (pour les volumes MySQL et Redis)

### Troubleshooting

**Service "unhealthy" ou ne démarre pas** :
```bash
# Via Coolify : Service → Logs
# Via SSH : voir les logs du conteneur
docker ps  # Trouver le nom du conteneur
docker logs -f <nom-du-conteneur>
```

**Gateway Timeout ou "no available server"** :
- Vérifiez que le service est **Healthy** dans Coolify
- Vérifiez que le réseau `coolify` est bien configuré (présent dans docker-compose.prod.yml)
- Vérifiez les healthchecks dans les logs

**Erreur CORS** :
Vérifiez que `CORS_ALLOW_ORIGIN` correspond à votre domaine :
```env
CORS_ALLOW_ORIGIN=^https?://trackup[.]votredomaine[.]com$
```

**Frontend page blanche** :
- Vérifiez que `VITE_API_BASE_URL=/api` dans les variables d'environnement
- Vérifiez les logs du frontend : Service `frontend` → Logs dans Coolify

### Documentation complète

Pour plus de détails, consultez `DEPLOYMENT.md`

---

## 📚 Documentation

- **Guide de déploiement** : [DEPLOYMENT.md](./DEPLOYMENT.md)
- **Variables d'environnement** : [.env.prod.example](./.env.prod.example)
- **Test local** : `./deploy-test.sh`

## 🔗 Repository

GitHub : https://github.com/momoSfp/trackUP.git
