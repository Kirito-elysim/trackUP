# Git workflow — TrackUp

## Structure des branches

- `main` = production. Ne jamais push directement dessus.
  > Note projet : ce dépôt utilise `main` comme branche de prod (pas `master` — adapté ici en conséquence).
- Deux branches de préproduction (`preprod-1`, `preprod-2` ou équivalent) servent de zones de validation intermédiaire.
  > Note projet : ces branches n'existent pas encore dans ce dépôt à ce jour — à créer avant que cette partie du workflow ne s'applique.
- Chaque feature ou fix vit dans sa propre branche `feature/<nom-court>` ou `fix/<nom-court>`, créée depuis `main`.
- Ne jamais merger une branche preprod entière vers `main`. Chaque feature branch est mergée individuellement vers `main` une fois validée, jamais en bloc.

## Orchestration multi-agents (agent racine + subagents)

Quand on te confie une liste de features à traiter en parallèle :

1. Pour chaque feature, crée une branche dédiée `feature/<nom>` depuis `main`.
2. Crée un git worktree isolé pour cette branche (un répertoire distinct par branche — jamais deux branches dans le même worktree, jamais la même branche dans deux worktrees).
3. Lance un subagent en arrière-plan par feature, scope-le strictement à son worktree.
4. Chaque subagent doit :
   - Respecter les règles de ce fichier.
   - Écrire et exécuter les tests correspondant à sa feature avant de se déclarer terminé.
   - Ne jamais toucher aux fichiers hors de son périmètre déclaré. S'il détecte qu'il doit modifier un fichier partagé (ex. config globale, routes), il doit le signaler à l'agent racine au lieu d'éditer directement.
   - Lister dans son rapport final : fichiers modifiés, commandes exécutées, résultat des tests.
5. L'agent racine ne merge rien tant que l'utilisateur n'a pas validé chaque rapport de subagent.
6. Une fois validées, les branches sont mergées vers `main` une par une. Après chaque merge, les branches restantes doivent être rebasées sur le nouveau `main` avant leur propre merge.

## Zone à risque : `frontend/dist/`

> Note projet : `frontend/dist/` est actuellement listé dans `.gitignore` (`frontend/.gitignore` et `.gitignore` racine) — aucun artefact de build n'est committé aujourd'hui dans ce dépôt. Cette section reste en garde-fou préventif si cela venait à changer ; à adapter/supprimer si ça ne s'applique jamais à ce projet.

- Si des artefacts de build front (React/Vite) venaient à être committés dans ce dossier, ce serait une source probable de conflits de merge.
- Un seul agent/subagent à la fois ne doit travailler sur une branche touchant ce dossier.
- Avant tout merge d'une branche qui modifie `frontend/dist/`, régénérer le build sur `main` à jour (`npm run build`) plutôt que de résoudre le conflit à la main.
- En cas de doute, préférer exclure ce dossier du commit et le régénérer via la pipeline de build plutôt que de le committer manuellement.

## Déploiement

- Les déploiements passent par Coolify sur Scaleway.
- Ne jamais déclencher de déploiement en preprod ou prod depuis un subagent sans validation explicite de l'utilisateur.

## Tests avant merge

- Un subagent ne peut pas déclarer une feature "terminée" sans avoir fait tourner la suite de tests pertinente.
- Si les tests échouent, le subagent doit corriger avant de rapporter, ou signaler l'échec clairement dans son rapport si le blocage dépasse son périmètre.

## Limites de parallélisation

- Ne pas lancer plus de 2-3 features en parallèle par défaut, sauf demande explicite contraire — le coût en tokens augmente quasi linéairement avec le nombre d'agents actifs.
- Deux features touchant les mêmes fichiers ne doivent jamais être traitées en parallèle : elles doivent être séquencées.
