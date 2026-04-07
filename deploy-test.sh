#!/bin/bash

# TrackUp - Script de test de déploiement local
# Ce script permet de tester le déploiement en production localement avant Coolify

set -e

echo "🚀 TrackUp - Test de déploiement production"
echo "==========================================="
echo ""

# Vérifier que .env.prod existe
if [ ! -f ".env.prod" ]; then
    echo "❌ Erreur: .env.prod n'existe pas"
    echo "📝 Copiez .env.prod.example vers .env.prod et remplissez les valeurs"
    echo ""
    echo "  cp .env.prod.example .env.prod"
    echo ""
    exit 1
fi

echo "✅ Fichier .env.prod trouvé"
echo ""

# Charger les variables d'environnement
set -a
source .env.prod
set +a

echo "📋 Variables d'environnement chargées"
echo ""

# Vérifier que les secrets sont changés
if [[ "$APP_SECRET" == *"CHANGE_ME"* ]] || [[ "$JWT_PASSPHRASE" == *"CHANGE_ME"* ]]; then
    echo "❌ Erreur: Les secrets ne sont pas configurés"
    echo "📝 Éditez .env.prod et changez tous les CHANGE_ME"
    echo ""
    exit 1
fi

echo "✅ Secrets configurés"
echo ""

# Nettoyer les containers précédents
echo "🧹 Nettoyage des containers précédents..."
docker-compose -f docker-compose.prod.yml down -v 2>/dev/null || true
echo ""

# Build des images
echo "🔨 Build des images Docker..."
docker-compose -f docker-compose.prod.yml build --no-cache
echo ""

# Démarrer les services
echo "🚀 Démarrage des services..."
docker-compose -f docker-compose.prod.yml up -d
echo ""

# Attendre que MySQL soit prêt
echo "⏳ Attente de MySQL..."
sleep 10

# Vérifier la santé des containers
echo "🏥 Vérification de la santé des services..."
echo ""

services=("mysql" "redis" "backend" "frontend")
all_healthy=true

for service in "${services[@]}"; do
    container_name="trackup-${service}-prod"
    
    if docker ps --format '{{.Names}}' | grep -q "^${container_name}$"; then
        status=$(docker inspect --format='{{.State.Status}}' "$container_name" 2>/dev/null || echo "not found")
        
        if [ "$status" == "running" ]; then
            echo "  ✅ $service: running"
        else
            echo "  ❌ $service: $status"
            all_healthy=false
        fi
    else
        echo "  ❌ $service: container not found"
        all_healthy=false
    fi
done

echo ""

if [ "$all_healthy" = false ]; then
    echo "❌ Certains services ne sont pas en bonne santé"
    echo "📋 Consultez les logs avec:"
    echo "   docker-compose -f docker-compose.prod.yml logs"
    exit 1
fi

# Exécuter les migrations
echo "🗄️  Exécution des migrations..."
docker exec trackup-backend-prod php bin/console doctrine:migrations:migrate --no-interaction
echo ""

echo "✅ Déploiement de test réussi!"
echo ""
echo "📊 Services disponibles:"
echo "  - Backend:  http://localhost:8080"
echo "  - Frontend: http://localhost:80"
echo ""
echo "📋 Commandes utiles:"
echo "  - Logs:     docker-compose -f docker-compose.prod.yml logs -f"
echo "  - Arrêt:    docker-compose -f docker-compose.prod.yml down"
echo "  - Stats:    docker stats"
echo ""
echo "🎉 Vous pouvez maintenant tester l'application!"
echo ""
