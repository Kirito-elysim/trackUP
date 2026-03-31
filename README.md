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

## Prochaine étape logique

- brancher la synchro `Rise Up`
- créer les tables métier `learners / courses / registrations / sessions`
- alimenter les écrans `Apprenants`, `Formations`, `Exports`
