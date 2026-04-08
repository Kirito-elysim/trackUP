# 🚀 TrackUp - Guide de Déploiement Coolify

## 📋 Prérequis

- Compte Coolify actif
- Serveur avec Docker installé (min. 2GB RAM, 1 CPU)
- Dépôt Git (GitHub/GitLab) du projet
- Un domaine configuré :
  - `trackup.votredomaine.com` → Frontend + API (API sous `/api`)

---

## 🔧 Configuration Initiale

### 1. Préparer les secrets

Générez des secrets forts :

```bash
# APP_SECRET (32 caractères minimum)
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"

# JWT_PASSPHRASE (64 caractères recommandé)
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"

# MYSQL_PASSWORD et MYSQL_ROOT_PASSWORD
openssl rand -base64 32

# REDIS_PASSWORD
openssl rand -base64 32
```

### 2. Créer le fichier .env.prod

Copiez `.env.prod.example` vers `.env.prod` et remplissez les valeurs :

```bash
cp .env.prod.example .env.prod
```

**Important** : Modifiez ces variables :
- `APP_SECRET`
- `JWT_PASSPHRASE`
- `MYSQL_ROOT_PASSWORD`
- `MYSQL_PASSWORD`
- `REDIS_PASSWORD`
- `CORS_ALLOW_ORIGIN` → optionnel (si API exposée sur un autre domaine)
- `VITE_API_BASE_URL` → `/api`
- `DATABASE_URL` → utilisez le MYSQL_PASSWORD
- `MESSENGER_TRANSPORT_DSN` → utilisez le REDIS_PASSWORD

---

## 🌐 Déploiement sur Coolify

### Étape 1 : Créer le projet

1. Connectez-vous à Coolify
2. **Projects** → **+ New Project**
3. Nom : `TrackUp`
4. Description : `Application de suivi de formation`

### Étape 2 : Ajouter la ressource Docker Compose

1. Dans le projet → **+ Add Resource**
2. Sélectionnez **Docker Compose**
3. **Source** :
   - Type : Git Repository
   - Repository : Votre URL Git
   - Branch : `main` (ou votre branche de production)
4. **Build** :
   - Docker Compose File : `docker-compose.prod.yml`
   - Build Directory : `/`

### Étape 3 : Variables d'environnement

Ajoutez toutes les variables de `.env.prod` dans Coolify :

**Dans Coolify** → **Environment Variables** :

```env
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=votre_secret_genere
DATABASE_URL=mysql://trackup:votre_password@mysql:3306/trackup?serverVersion=8.4.0&charset=utf8mb4
MESSENGER_TRANSPORT_DSN=redis://:votre_redis_password@redis:6379/messages
CORS_ALLOW_ORIGIN=^https?://trackup[.]votredomaine[.]com$
JWT_PASSPHRASE=votre_jwt_passphrase
MYSQL_ROOT_PASSWORD=votre_root_password
MYSQL_PASSWORD=votre_user_password
REDIS_PASSWORD=votre_redis_password
VITE_API_BASE_URL=/api
```

### Étape 4 : Configuration des domaines

**Frontend** (service `frontend`) :
- Domain : `trackup.votredomaine.com`
- Port : `80`
- HTTPS : Activé (Let's Encrypt auto)

**ℹ️ Important** : l'API est accessible via le même domaine, sous `/api` (ex: `https://trackup.votredomaine.com/api/health`). Le service `backend` n'a pas besoin de domaine public.

### Étape 5 : Déployer

1. Cliquez sur **Deploy**
2. Attendez la fin du build (~5-10 minutes)
3. Vérifiez les logs de chaque service

---

## 🗄️ Post-Déploiement

### 1. Migrations de base de données

Une fois déployé, exécutez les migrations :

```bash
# Sur Coolify, ouvrez le terminal du service "backend"
# Ou via SSH sur votre serveur :

docker compose -f docker-compose.prod.yml exec backend sh

# Puis dans le container :
php bin/console doctrine:migrations:migrate --no-interaction
```

### 2. Créer un utilisateur admin

```bash
# Dans le container backend :
php bin/console app:create-admin

# Ou créez manuellement via la console Symfony
```

### 3. Warm up du cache

```bash
php bin/console cache:warmup --env=prod
```

---

## 📊 Monitoring

### Healthchecks configurés

- **Backend** : `GET https://trackup.votredomaine.com/api/health` (via le proxy frontend)
- **Frontend** : `GET https://trackup.votredomaine.com/health` (toutes les 30s)
- **MySQL** : `mysqladmin ping` (toutes les 10s)
- **Redis** : `redis-cli ping` (toutes les 10s)

### Logs

Consultez les logs dans Coolify :
- **Backend** : Logs applicatifs Symfony
- **Worker** : Logs des jobs async
- **Frontend** : Logs Nginx
- **MySQL** : Logs base de données
- **Redis** : Logs cache/queue

---

## 🔄 Mises à jour

### Déploiement automatique

Si activé dans Coolify, chaque push sur la branche `main` déclenche un redéploiement.

### Déploiement manuel

1. Push votre code sur Git
2. Dans Coolify → **Redeploy**
3. Attendre la fin du build
4. Vérifier que tout fonctionne

### Rollback

En cas de problème :
1. Coolify → **Deployments**
2. Sélectionnez un déploiement précédent
3. **Restore**

---

## 🔒 Sécurité

### Checklist de sécurité

- [x] Secrets forts générés (32+ caractères)
- [x] HTTPS activé sur tous les domaines
- [x] CORS configuré avec vos domaines uniquement
- [x] APP_DEBUG=0 en production
- [x] `.env.prod` dans `.gitignore`
- [x] Backups MySQL activés
- [x] Healthchecks configurés

### Backups recommandés

Configurez des backups automatiques dans Coolify :
- **MySQL** : Snapshot quotidien
- **Volumes** : Backup hebdomadaire
- **Code** : Déjà versionné sur Git

---

## 🐛 Troubleshooting

### Backend ne démarre pas

```bash
# Vérifier les logs
docker compose -f docker-compose.prod.yml logs -f backend

# Vérifier la connexion MySQL
docker compose -f docker-compose.prod.yml exec backend php bin/console doctrine:schema:validate
```

### Frontend affiche une page blanche

```bash
# Vérifier les logs Nginx
docker compose -f docker-compose.prod.yml logs -f frontend

# Vérifier que VITE_API_BASE_URL est correct
docker compose -f docker-compose.prod.yml exec frontend sh -lc "grep -R \"VITE_API_BASE_URL\" -n /usr/share/nginx/html/assets || true"
```

### Worker ne consomme pas les messages

```bash
# Vérifier les logs du worker
docker compose -f docker-compose.prod.yml logs -f worker

# Vérifier la connexion Redis
docker compose -f docker-compose.prod.yml exec backend php bin/console debug:messenger
```

### Erreur "no available server"

Si vous voyez `no available server` (souvent une réponse Traefik/Coolify), vérifiez :

- Le domaine pointe vers le bon service (**backend** port `8000` / **frontend** port `80`)
- Les conteneurs `backend`/`frontend` sont `healthy`
- Les services exposés sont joignables depuis le réseau reverse-proxy Coolify (réseau Docker `coolify`)

### Erreur CORS

Vérifiez que `CORS_ALLOW_ORIGIN` inclut bien vos domaines :
```env
CORS_ALLOW_ORIGIN=^https?://(app\.votredomaine\.com|api\.votredomaine\.com)$
```

---

## 📞 Support

En cas de problème :
1. Vérifiez les logs dans Coolify
2. Consultez la documentation Symfony : https://symfony.com/doc
3. Documentation Coolify : https://coolify.io/docs

---

## 📝 Notes

- **Build time** : ~5-10 minutes
- **Temps de démarrage** : ~30-60 secondes
- **Ressources recommandées** : 2GB RAM, 1-2 CPU
- **Espaces disque** : ~2GB (images Docker + volumes)

Bon déploiement ! 🚀
