# 🚀 TrackUp - Guide Rapide de Déploiement

## 📝 Contexte

Repository Git : **https://github.com/momoSfp/trackUP.git**  
Branche principale : **main**

---

## ✅ Checklist Avant Déploiement

### 1. Push du code sur GitHub

Votre code est déjà sur GitHub. Vérifiez que tout est à jour :

```bash
# Vérifier l'état
git status

# Si modifications non commitées
git add .
git commit -m "Ready for production deployment"
git push origin main
```

### 2. Générer les secrets

Exécutez ces commandes et **notez les résultats** :

```bash
# 1. APP_SECRET
php -r "echo 'APP_SECRET=' . bin2hex(random_bytes(16)) . PHP_EOL;"

# 2. JWT_PASSPHRASE
php -r "echo 'JWT_PASSPHRASE=' . bin2hex(random_bytes(32)) . PHP_EOL;"

# 3. MYSQL_ROOT_PASSWORD
echo "MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32)"

# 4. MYSQL_PASSWORD
echo "MYSQL_PASSWORD=$(openssl rand -base64 32)"

# 5. REDIS_PASSWORD
echo "REDIS_PASSWORD=$(openssl rand -base64 32)"
```

**💡 Tip** : Copiez ces résultats dans un fichier texte temporaire (hors git).

---

## 🌐 Configuration Coolify

### Étape 1 : Créer le Projet

1. Connexion à **Coolify**
2. **Projects** → **+ New Project**
3. Nom : `TrackUp`
4. **Create**

### Étape 2 : Ajouter Docker Compose

1. Dans le projet → **+ Add Resource**
2. Type : **Docker Compose**
3. Configuration :
   - **Name** : `TrackUp Production`
   - **Source** : Git Repository
   - **Repository** : `https://github.com/momoSfp/trackUP.git`
   - **Branch** : `main`
   - **Docker Compose Location** : `docker-compose.prod.yml`
   - **Base Directory** : `/`

4. **Continue**

### Étape 3 : Variables d'Environnement

Cliquez sur **Environment Variables** et ajoutez :

```env
# Backend - Symfony
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<collez_votre_APP_SECRET>
JWT_PASSPHRASE=<collez_votre_JWT_PASSPHRASE>

# Database - MySQL
MYSQL_ROOT_PASSWORD=<collez_votre_MYSQL_ROOT_PASSWORD>
MYSQL_PASSWORD=<collez_votre_MYSQL_PASSWORD>
DATABASE_URL=mysql://trackup:<MYSQL_PASSWORD>@mysql:3306/trackup?serverVersion=8.4.0&charset=utf8mb4

# Cache & Queue - Redis
REDIS_PASSWORD=<collez_votre_REDIS_PASSWORD>
MESSENGER_TRANSPORT_DSN=redis://:<REDIS_PASSWORD>@redis:6379/messages

# Frontend - React
VITE_API_BASE_URL=https://api.votredomaine.com

# CORS
CORS_ALLOW_ORIGIN=^https?://(app\.votredomaine\.com|api\.votredomaine\.com)$
```

**⚠️ Important** :
- Remplacez `<MYSQL_PASSWORD>` dans `DATABASE_URL` par votre vrai password
- Remplacez `<REDIS_PASSWORD>` dans `MESSENGER_TRANSPORT_DSN` par votre vrai password
- Remplacez `votredomaine.com` par votre vrai domaine
- Si vous avez Rise Up, ajoutez aussi `RISEUP_API_PUBLIC_KEY` et `RISEUP_API_PRIVATE_KEY`

### Étape 4 : Configuration des Domaines

#### Service Backend

1. Dans Coolify, sélectionnez le service **backend**
2. **Domains** :
   - Ajoutez : `api.votredomaine.com`
   - Port : `8000`
   - **Generate Domain**
3. **HTTPS** :
   - ✅ Enable HTTPS
   - ✅ Force HTTPS
   - Certificate : Let's Encrypt

#### Service Frontend

1. Dans Coolify, sélectionnez le service **frontend**
2. **Domains** :
   - Ajoutez : `app.votredomaine.com`
   - Port : `80`
   - **Generate Domain**
3. **HTTPS** :
   - ✅ Enable HTTPS
   - ✅ Force HTTPS
   - Certificate : Let's Encrypt

### Étape 5 : DNS Configuration

Avant de déployer, configurez vos DNS :

**Chez votre registrar (OVH, Cloudflare, etc.)** :

```
Type    Nom     Valeur                  TTL
A       api     <IP_DE_VOTRE_SERVEUR>   3600
A       app     <IP_DE_VOTRE_SERVEUR>   3600
```

**💡 Vérifier** :
```bash
# Depuis votre terminal local
nslookup api.votredomaine.com
nslookup app.votredomaine.com
```

### Étape 6 : Déployer !

1. Dans Coolify, retournez au projet TrackUp
2. Cliquez sur **Deploy** 🚀
3. Attendez le build (~5-10 minutes)
4. Surveillez les logs en temps réel

---

## 🔧 Post-Déploiement

### 1. Exécuter les Migrations

Une fois tous les containers "Running" :

```bash
# Option A : Via l'interface Coolify
# Service "backend" → Terminal → Execute

# Option B : Via SSH sur le serveur
ssh votre-serveur
docker exec -it trackup-backend-prod sh
```

Puis dans le container :

```bash
# Migrations
php bin/console doctrine:migrations:migrate --no-interaction

# Bootstrap RBAC + Admin
php bin/console app:bootstrap-rbac

# Vérifier
php bin/console doctrine:schema:validate
```

### 2. Tester l'Application

#### Backend API
```bash
curl https://api.votredomaine.com/api/health
# Réponse attendue : {"status":"ok"}
```

#### Frontend
Ouvrez dans votre navigateur : `https://app.votredomaine.com`

**Login par défaut** :
- Email : `admin@trackup.local`
- Password : `TrackUp123!`

### 3. (Optionnel) Synchroniser Rise Up

Si vous avez configuré les clés Rise Up :

```bash
docker exec -it trackup-backend-prod sh

php bin/console app:sync:learners
php bin/console app:sync:trainings
php bin/console app:sync:registrations
php bin/console app:sync:sessions
```

---

## 🔄 Déployer une Mise à Jour

```bash
# 1. Sur votre machine locale
git add .
git commit -m "Description de vos changements"
git push origin main

# 2. Sur Coolify
# Si auto-deploy activé : Automatique ✅
# Sinon : Cliquez sur "Redeploy"
```

---

## 🐛 Troubleshooting

### Backend ne répond pas

```bash
# Vérifier les logs
docker logs trackup-backend-prod

# Vérifier la santé
docker ps | grep trackup-backend-prod

# Redémarrer si nécessaire
docker restart trackup-backend-prod
```

### Frontend page blanche

```bash
# Vérifier les logs Nginx
docker logs trackup-frontend-prod

# Vérifier que l'API URL est correcte
docker exec trackup-frontend-prod cat /usr/share/nginx/html/index.html | grep -o 'VITE_API_BASE_URL[^"]*'
```

### Erreur 502 Bad Gateway

C'est probablement un problème de réseau entre services :

```bash
# Vérifier que tous les services sont "healthy"
docker ps --format "table {{.Names}}\t{{.Status}}"

# Vérifier les networks
docker network ls
docker network inspect <network_name>
```

### Erreur CORS

Vérifiez dans Coolify → Environment Variables :

```env
CORS_ALLOW_ORIGIN=^https?://(app\.votredomaine\.com|api\.votredomaine\.com)$
```

Les domaines doivent correspondre **exactement** à ceux configurés.

### Base de données non accessible

```bash
# Se connecter au container MySQL
docker exec -it trackup-mysql-prod sh

# Tester la connexion
mysql -u trackup -p
# Entrez le MYSQL_PASSWORD

# Vérifier la base
SHOW DATABASES;
USE trackup;
SHOW TABLES;
```

---

## 📊 Monitoring

### Healthchecks

Configurés automatiquement :
- **Backend** : PHP health check (30s)
- **Frontend** : Nginx health check (30s)
- **MySQL** : `mysqladmin ping` (10s)
- **Redis** : `redis-cli ping` (10s)

### Logs

```bash
# Tous les services
docker-compose -f docker-compose.prod.yml logs -f

# Un service spécifique
docker logs -f trackup-backend-prod
docker logs -f trackup-frontend-prod
docker logs -f trackup-mysql-prod
docker logs -f trackup-worker-prod
```

### Statistiques

```bash
# Utilisation ressources
docker stats

# Espace disque
docker system df
```

---

## 🔒 Sécurité

### Checklist Sécurité

- [x] Secrets générés (32+ caractères)
- [x] HTTPS activé avec Let's Encrypt
- [x] `APP_DEBUG=0` en production
- [x] CORS configuré avec domaines spécifiques
- [x] `.env.prod` **NON COMMITÉ** dans Git
- [x] Backups MySQL configurés dans Coolify
- [x] Mots de passe admin changés

### Changer le mot de passe admin

```bash
docker exec -it trackup-backend-prod sh

# Créer un nouvel admin ou modifier
php bin/console app:create-user
```

---

## 📞 Support

- **Documentation complète** : `DEPLOYMENT.md`
- **Repository** : https://github.com/momoSfp/trackUP.git
- **Symfony Docs** : https://symfony.com/doc
- **Coolify Docs** : https://coolify.io/docs

---

**✅ Votre application TrackUp est maintenant en production !** 🎉

N'oubliez pas de :
1. Changer le mot de passe admin par défaut
2. Configurer les backups automatiques
3. Tester toutes les fonctionnalités
4. Monitorer les logs les premiers jours
