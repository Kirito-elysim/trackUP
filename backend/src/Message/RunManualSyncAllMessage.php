<?php
declare(strict_types=1);

namespace App\Message;

// Déclenché par le bouton "Tout synchroniser" (POST /api/sync/all) — routé sur le même transport
// async que les autres syncs lentes existantes (sessions, appartenances groupes) pour éviter un
// timeout HTTP côté navigateur. Ne transporte que l'id de l'utilisateur (jamais l'entité elle-même)
// pour rester sérialisable indépendamment de l'identity map Doctrine du process qui l'a dispatché.
class RunManualSyncAllMessage
{
    public function __construct(
        public readonly int $triggeredByUserId,
    ) {
    }
}
