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
