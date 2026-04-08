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
# Backend
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<votre_secret_32_chars>
JWT_PASSPHRASE=<votre_passphrase_64_chars>
# Optionnel (utile si vous exposez l'API sur un autre domaine)
CORS_ALLOW_ORIGIN=^https?://trackup[.]votredomaine[.]com$

# Database
MYSQL_ROOT_PASSWORD=<votre_root_password>
MYSQL_PASSWORD=<votre_mysql_password>
DATABASE_URL=mysql://trackup:<votre_mysql_password>@mysql:3306/trackup?serverVersion=8.4.0&charset=utf8mb4

# Redis
REDIS_PASSWORD=<votre_redis_password>
MESSENGER_TRANSPORT_DSN=redis://:<votre_redis_password>@redis:6379/messages

# Frontend
# Même domaine (pas de CORS) : le frontend proxy `/api` vers le backend
VITE_API_BASE_URL=/api

# Rise Up (si configuré)
RISEUP_API_PUBLIC_KEY=<votre_cle_publique>
RISEUP_API_PRIVATE_KEY=<votre_cle_privee>
```

4. **Configurer les domaines**

Pour le service **frontend** :
- Domain : `trackup.votredomaine.com`
- Port : `80`
- HTTPS : ✅ Activé (Let's Encrypt)

L'API est exposée via le même domaine, sous `/api` (ex: `https://trackup.votredomaine.com/api/health`).

5. **Déployer**
   - Cliquez sur **Deploy**
   - Attendez la fin du build (~5-10 min)

### Étape 3 : Post-déploiement

Une fois le déploiement terminé :

```bash
# 1. Exécuter les migrations
# Sur Coolify : utilisez le terminal du service "backend"
# Hors Coolify (compose classique) :
docker compose -f docker-compose.prod.yml exec backend sh
php bin/console doctrine:migrations:migrate --no-interaction

# 2. Initialiser RBAC et créer l'admin
php bin/console app:bootstrap-rbac

# 3. Vérifier la santé
curl https://trackup.votredomaine.com/api/health
```

### Étape 4 : Premier login

Connectez-vous sur `https://trackup.votredomaine.com` avec :
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
- **RAM** : 2GB minimum
- **Stockage** : 5GB minimum

### Troubleshooting

**Backend ne démarre pas** :
```bash
docker compose -f docker-compose.prod.yml logs -f backend
```

**Erreur CORS** :
Vérifiez que `CORS_ALLOW_ORIGIN` contient vos domaines :
```env
CORS_ALLOW_ORIGIN=^https?://(app\.votredomaine\.com|api\.votredomaine\.com)$
```

**Frontend page blanche** :
```bash
# Vérifier que VITE_API_BASE_URL est correct
docker compose -f docker-compose.prod.yml logs -f frontend
```

### Documentation complète

Pour plus de détails, consultez `DEPLOYMENT.md`

---

## 📚 Documentation

- **Guide de déploiement** : [DEPLOYMENT.md](./DEPLOYMENT.md)
- **Variables d'environnement** : [.env.prod.example](./.env.prod.example)
- **Test local** : `./deploy-test.sh`

## 🔗 Repository

GitHub : https://github.com/momoSfp/trackUP.git
