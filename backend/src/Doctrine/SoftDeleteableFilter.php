<?php
declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

// Ajoute automatiquement "deleted_at IS NULL" à toute requête ORM portant sur une entité qui
// possède ce champ (Company, Tutor, Prospect, Sector) — pas besoin de le répéter dans chaque
// repository/QueryBuilder. Ne s'applique pas au SQL brut (DBAL) : ces requêtes doivent filtrer
// deleted_at manuellement (voir LearnerController::show()).
class SoftDeleteableFilter extends SQLFilter
{
    public function addFilterConstraint(ClassMetadata $targetEntity, $targetTableAlias): string
    {
        if (!$targetEntity->hasField('deletedAt')) {
            return '';
        }

        return sprintf('%s.deleted_at IS NULL', $targetTableAlias);
    }
}
