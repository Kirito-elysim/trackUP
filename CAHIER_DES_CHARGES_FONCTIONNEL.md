# Cahier des charges fonctionnel — TrackUp

**Plateforme de pilotage et de conformité formation**

---

## 1. Contexte et objectifs

L'organisme de formation utilise une plateforme e-learning externe (LMS) pour dispenser ses
formations : gestion des apprenants, des contenus, des parcours, des sessions en classe virtuelle et
du suivi de progression. Cette plateforme ne propose toutefois aucun outil de pilotage transverse, ni
de fonctionnalités de gestion administrative propres à l'organisme (suivi des entreprises et tuteurs
d'alternance, gestion des absences, exports de conformité, etc.).

L'application à développer doit combler ce manque en offrant :

- une **vue de pilotage consolidée** de l'activité pédagogique (formations, parcours, apprenants,
  temps passé, progression), avec des analyses et comparaisons de périodes ;
- un **module de gestion de l'alternance** (entreprises, tuteurs, rattachement des apprenants),
  totalement absent du LMS ;
- un **module de suivi des absences** aux sessions (webinaires/masterclass et sessions en
  présentiel), avec un processus automatisé de justification et des alertes disciplinaires ;
- des **exports de conformité** permettant de prouver, apprenant par apprenant, l'ensemble de son
  activité (temps de connexion, progression, présence aux sessions) pour répondre aux exigences des
  organismes certificateurs et des financeurs ;
- une **synchronisation automatique quotidienne** avec le LMS, pour que toutes ces données restent à
  jour sans intervention manuelle, tout en gardant la possibilité de forcer une mise à jour à la
  demande.

L'application est un outil interne, réservé à l'équipe pédagogique et administrative. Les apprenants
n'y ont pas de compte : ils n'interagissent qu'avec une seule fonctionnalité, ponctuellement, via un
lien reçu par email (dépôt d'un justificatif d'absence), sans avoir besoin de se connecter.

---

## 2. Utilisateurs et profils d'accès

Deux catégories d'utilisateurs :

1. **Utilisateurs internes** (administrateurs, équipe pédagogique, managers) : comptes créés par un
   administrateur, protégés par email + mot de passe. Chaque utilisateur se voit attribuer un ou
   plusieurs **rôles**, et chaque rôle donne accès à un ensemble précis de fonctionnalités. Il doit
   être possible de créer des rôles sur mesure (par exemple un rôle "Pilotage" qui ne voit que les
   tableaux de bord et les analyses, sans accès à la gestion des utilisateurs ni à la synchronisation)
   en cochant, module par module, les droits accordés. Un utilisateur peut être désactivé sans être
   supprimé.

2. **Apprenants** : aucun compte, aucune connexion. Leur seul point de contact avec l'application est
   un lien sécurisé et à durée limitée reçu par email, utilisé une seule fois pour déposer un document
   justificatif d'absence.

Toute action de gestion (créer/modifier/supprimer une entreprise, valider une absence, changer un
rôle, etc.) doit être réservée aux utilisateurs disposant du droit correspondant. La simple
consultation d'un écran et la possibilité d'y agir doivent pouvoir être des droits distincts (par
exemple : un utilisateur peut avoir le droit de *consulter* les absences sans avoir le droit de les
*valider*).

---

## 3. Principe général vis-à-vis de la plateforme e-learning

L'application ne doit **jamais modifier** les données de la plateforme e-learning : elle en récupère
une copie à jour, régulièrement, et travaille sur cette copie locale. Toutes les fonctionnalités de
gestion propres à l'application (entreprises, tuteurs, absences, rôles) sont des ajouts qui n'existent
que dans l'application elle-même et n'ont pas vocation à être renvoyés vers le LMS.

La mise à jour des données doit se faire **automatiquement une fois par jour**, sans action humaine,
et il doit toujours être possible de **forcer une mise à jour immédiate** en un clic si besoin (par
exemple après avoir corrigé une donnée dans le LMS et vouloir la voir reflétée tout de suite).

**Limite connue et à accepter du LMS** : celui-ci ne permet de récupérer l'historique détaillé des
connexions/déconnexions des apprenants que via un export manuel depuis son interface
d'administration, au mieux une fois par semaine ou par mois — il n'existe aucun moyen automatique
(API ou notification) de récupérer cette donnée plus finement. L'application doit donc prévoir un
écran permettant d'**importer manuellement** ce fichier d'export quand il est disponible, plutôt que
de chercher à l'automatiser.

---

## 4. Modules fonctionnels

### 4.1 Authentification et gestion des accès

- Connexion par email/mot de passe.
- "Mot de passe oublié" : demande d'un lien de réinitialisation par email, valable une heure, à usage
  unique. Le message affiché est toujours le même que l'email existe ou non, pour ne pas révéler quels
  comptes existent.
- Chaque utilisateur consulte et modifie son propre profil (identité, changement de mot de passe).
- **Gestion des utilisateurs** (réservée aux administrateurs) : créer un compte (identité, email, mot
  de passe, statut actif/inactif), lui attribuer un ou plusieurs rôles, le désactiver.
- **Gestion des rôles** (réservée aux administrateurs) : créer un rôle, lui donner un nom et une
  description, et cocher, parmi une liste de droits regroupés par thème (pilotage, administration,
  conformité...), ceux qui lui sont accordés. Certains rôles "de base" fournis avec l'application
  (par exemple un rôle Administrateur complet) doivent être protégés contre la suppression ou le
  renommage de leur code technique, pour éviter de casser la configuration.

### 4.2 Tableau de bord

Écran d'accueil donnant une vue d'ensemble immédiate :
- indicateurs clés en un coup d'œil (nombre de formations, de parcours, d'apprenants, temps de
  formation cumulé sur l'année en cours) ;
- liste des groupes/classes actifs avec, pour chacun, son effectif, le nombre de parcours suivis, le
  temps cumulé et la progression moyenne ;
- accès rapide au détail de chaque groupe.

### 4.3 Analyse d'activité (Analytics)

Écran d'analyse plus poussé permettant de :
- choisir une **période d'observation** (jour, semaine, mois, année, ou dates personnalisées) ;
- obtenir automatiquement une **comparaison avec la période équivalente précédente** (évolution en
  valeur et en pourcentage), pour visualiser une tendance sans calcul manuel ;
- filtrer l'analyse sur un parcours et/ou un apprenant en particulier ;
- visualiser un graphique de répartition du temps passé (e-learning vs. sessions en direct) sur la
  période, avec le niveau de détail adapté (par heure, par jour ou par mois selon la période choisie) ;
- consulter un classement des parcours les plus suivis (temps passé, nombre d'apprenants,
  avancement) ;
- consulter un tableau détaillé apprenant par apprenant et parcours par parcours (temps passé,
  avancement, dernière activité, dernière connexion), avec tri sur chaque colonne, et la possibilité
  de cliquer sur un apprenant pour faire apparaître le détail formation par formation de son parcours ;
- exporter ce tableau détaillé au format tableur.

### 4.4 Suivi des apprenants

- Recherche d'un apprenant par nom ou email.
- Fiche apprenant complète : identité, statut, dernière connexion, formations suivies, sessions
  auxquelles il est inscrit, activité récente, et absences (voir §4.7).
- Possibilité de rattacher l'apprenant à une entreprise et/ou un tuteur (voir §4.6), et de compléter
  des informations de contact qui ne viennent pas du LMS (téléphone, adresse, date de naissance, note
  libre) — ces informations complémentaires ne doivent jamais être écrasées par la synchronisation
  automatique avec le LMS.

### 4.5 Formations et parcours de formation

- **Formations** : liste consultable et filtrable (par titre, par statut), avec pour chacune le détail
  de sa structure (modules, sessions programmées), le nombre d'apprenants inscrits, leur progression
  moyenne, le temps cumulé, et un classement des apprenants les plus avancés.
- **Parcours de formation** (regroupements de plusieurs formations) : présentation sous forme de
  vignettes avec effectif, nombre de formations incluses, temps cumulé et progression moyenne ; détail
  d'un parcours avec la liste des apprenants inscrits et, pour chacun, la possibilité de consulter le
  détail de ses sessions suivies dans ce parcours.
- **Groupes/classes** : détail d'un groupe avec ses parcours associés et la liste de ses membres, même
  logique de détail des sessions par membre.

Ces trois écrans sont des écrans de **consultation** : les contenus pédagogiques eux-mêmes ne sont ni
créés ni modifiés dans l'application (ils viennent du LMS).

### 4.6 Gestion de l'alternance (Entreprises & Tuteurs)

Module de gestion propre à l'application, totalement absent du LMS.

**Entreprises**
- Liste recherchable et filtrable (nom, SIRET, ville, code postal, contact, secteur d'activité).
- Création, modification, suppression d'une fiche entreprise (identité, adresse, secteur, contact
  principal). La suppression ne doit jamais effacer définitivement la donnée (voir règle transverse
  §5.4) — les tuteurs et apprenants qui y étaient rattachés sont simplement détachés.
- Fiche détail : informations de l'entreprise, tuteurs qui y sont rattachés (avec possibilité d'en
  rattacher un existant ou d'en créer un nouveau directement depuis cet écran), apprenants qui y sont
  rattachés.
- **Import en masse** : possibilité de charger un fichier (tableur) contenant une liste
  d'entreprises/tuteurs/apprenants à rattacher, avec reconnaissance automatique des entreprises et
  tuteurs déjà existants pour éviter les doublons en cas de réimport.

**Tuteurs**
- Mêmes principes : liste recherchable/filtrable, création/modification/suppression douce, fiche
  détail avec les entreprises rattachées (un tuteur peut être rattaché à plusieurs entreprises) et les
  apprenants qu'il suit.

**Rattachement apprenant ↔ tuteur ↔ entreprise**
- Depuis la fiche d'un apprenant, d'un tuteur ou d'une entreprise, il doit être possible de créer ou
  modifier ce rattachement.
- Règle métier : si on rattache un tuteur à un apprenant sans préciser explicitement l'entreprise, et
  que ce tuteur n'est rattaché qu'à une seule entreprise, celle-ci doit être déduite et assignée
  automatiquement à l'apprenant (pour éviter une saisie redondante) ; si le tuteur a plusieurs
  entreprises, la sélection doit rester manuelle pour éviter toute ambiguïté.

### 4.7 Suivi des absences (module de conformité)

Module central de conformité, permettant de détecter, notifier, faire justifier et suivre les
absences des apprenants aux sessions de formation (webinaires/masterclass ou sessions en présentiel).

**Détection automatique**
- Une fois par jour, l'application doit identifier automatiquement les apprenants qui étaient inscrits
  à une session déjà terminée, sans qu'aucune preuve de présence (émargement) n'ait été enregistrée
  pour eux sur cette session. Une absence est alors créée avec le statut "en attente de justificatif".

**Notification et justification (sans compte apprenant)**
- Dès la détection d'une absence, un email est envoyé automatiquement à l'apprenant l'informant de
  son absence et l'invitant à déposer un justificatif via un lien sécurisé, valable un temps limité
  (14 jours).
- Ce lien ouvre une page publique, sans connexion requise, où l'apprenant peut déposer un document
  (PDF ou image). Une fois le délai dépassé sans dépôt, l'absence passe automatiquement au statut "non
  justifiée".

**Validation par l'équipe pédagogique**
- Un écran dédié permet de consulter toutes les absences, filtrables par apprenant, groupe, période et
  statut, avec des indicateurs (nombre total, en attente, justifiées, non justifiées).
- Pour chaque absence : visualiser le justificatif déposé (le cas échéant), l'accepter (statut
  "justifiée"), la rejeter (statut "non justifiée"), la classer autrement ("autre"), ou ajouter une
  note interne. Chaque décision déclenche un email de confirmation à l'apprenant.
- Une fiche détaillée par absence doit présenter, sous forme de frise chronologique, les 4 étapes du
  processus (détection → notification envoyée → réponse de l'apprenant → décision de l'équipe
  pédagogique) avec leur date de réalisation.

**Alerte disciplinaire**
- Lorsqu'un apprenant cumule **3 absences non justifiées consécutives** à des sessions de type
  webinaire/masterclass (les absences encore en attente de justificatif comptent déjà dans ce cumul,
  sans attendre leur résolution finale), une alerte doit être envoyée automatiquement par email à
  l'équipe pédagogique pour déclencher une procédure disciplinaire.
- Le compteur de séries consécutives doit se remettre à zéro automatiquement dès qu'une absence est
  justifiée (ou classée "autre") — seule une suite ininterrompue d'absences non justifiées doit
  compter.
- Un administrateur doit pouvoir **réinitialiser manuellement** ce compteur pour un apprenant donné
  (par exemple après avoir traité le dossier disciplinaire), afin de repartir sur une base propre sans
  que l'historique plus ancien ne refasse remonter une alerte.
- Un écran dédié doit lister les apprenants actuellement en alerte active (avec le détail des absences
  concernées, la possibilité de renvoyer l'email d'alerte ou de réinitialiser le compteur) ainsi que
  les apprenants "à surveiller" (1 ou 2 absences consécutives, sous le seuil d'alerte).
- Un indicateur visuel d'alerte doit apparaître directement sur la fiche de l'apprenant concerné.
- Un annuaire dédié doit permettre de visualiser, apprenant par apprenant, le total de ses absences et
  leur répartition par statut.

### 4.8 Exports de conformité

- Écran permettant de sélectionner un apprenant et d'obtenir l'intégralité de sa traçabilité : temps
  passé sur la plateforme, temps passé sur les modules e-learning, temps passé en session, nombre de
  présences signées / non signées, avancement par parcours et par formation.
- Un **journal chronologique complet et unifié** doit regrouper, dans l'ordre, tous les événements de
  l'apprenant quelle que soit leur origine (inscription à un parcours, inscription à une formation,
  activité sur un module e-learning, présence à une session avec son statut de signature), pour
  fournir une preuve d'activité continue et exportable en un seul document (format tableur).
- Un indicateur global doit signaler le nombre de sessions auxquelles un apprenant a assisté sans que
  sa présence n'ait été signée — signal d'alerte de conformité à corriger.

### 4.9 Journal des connexions (import manuel)

- Écran permettant d'importer le fichier d'export du LMS contenant l'historique détaillé des
  connexions/déconnexions (voir limite décrite en §3).
- Une fois importé, ce journal doit être consultable et filtrable (apprenant, groupe, parcours,
  formation, période) et exportable.
- Le système d'import doit être capable de détecter et ignorer automatiquement les lignes déjà
  importées lors d'un import précédent, pour permettre de réimporter un fichier plus récent qui
  chevauche partiellement un import antérieur sans créer de doublons.

### 4.10 Suivi de la synchronisation avec le LMS

- Écran de suivi (lecture seule) indiquant l'état de la connexion au LMS, la date de dernière
  synchronisation, le volume de données synchronisées par catégorie, et le détail de la dernière
  exécution automatique (réussie / partiellement réussie / échouée, avec le détail par type de
  donnée).
- Écran de pilotage (réservé aux administrateurs) permettant de déclencher manuellement, à tout
  moment, la synchronisation d'une catégorie de données en particulier, ou de **tout synchroniser en
  un seul clic**, avec un suivi de progression en temps réel pendant l'exécution.
- Qu'elle soit automatique (planifiée chaque nuit) ou déclenchée manuellement, une synchronisation
  groupée doit toujours suivre le même ordre logique (par exemple : les apprenants avant les
  inscriptions, qui elles-mêmes précèdent les données de progression), produire la même trace
  consultable a posteriori, et envoyer un email de bilan à l'équipe administratrice à la fin de
  l'exécution.
- Si une catégorie de données échoue pendant une synchronisation groupée, les autres catégories
  doivent tout de même continuer à se mettre à jour plutôt que de tout bloquer.

---

## 5. Règles métier transverses

1. **Aucune écriture vers le LMS** : l'application ne doit jamais chercher à modifier une donnée côté
   plateforme e-learning ; elle en consomme uniquement une copie.
2. **Respect strict d'un quota d'appels** imposé par le LMS lors de toute synchronisation à fort
   volume (par exemple la récupération des émargements, qui nécessite un appel par inscription) : le
   rythme des appels doit être maîtrisé pour ne jamais dépasser la limite imposée, plutôt que de
   compter uniquement sur des tentatives de nouvel essai après un refus.
3. **Aucun envoi d'email de test vers une vraie adresse** en dehors d'un contexte de production
   validé : tout test d'un flux d'email (notification, alerte, confirmation) doit passer par un
   environnement de test qui capture les emails sans jamais les délivrer réellement.
4. **Suppression douce** pour toutes les données de gestion propres à l'application (entreprises,
   tuteurs, secteurs, informations de contact complémentaires) : une suppression doit rendre la donnée
   invisible et non réutilisable, sans effacement définitif en base, pour permettre un historique et
   une éventuelle restauration.
5. **Idempotence des imports** : réimporter un même fichier (entreprises/tuteurs, journal de
   connexions) ne doit jamais créer de doublons.
6. **Distinction claire entre "consulter" et "agir"** dans le système de droits : pouvoir voir une
   information ne doit pas automatiquement donner le droit de la modifier.
7. **Aucune notification rétroactive** lors de la mise en service d'une nouvelle fonctionnalité
   automatisée (détection d'absences, alertes) sur des données déjà anciennes : un mécanisme
   nouvellement activé ne doit pas déclencher une vague massive d'emails ou d'alertes portant sur un
   passif qui n'a plus de sens à traiter aujourd'hui — seuls les nouveaux événements, à partir de la
   mise en service, doivent déclencher les automatismes.

---

## 6. Attentes en matière d'expérience utilisateur

- Interface unique et cohérente pour l'ensemble des modules, avec une barre de navigation latérale
  regroupant les écrans par thème (pilotage, alternance, conformité, administration).
- Les écrans de liste volumineux (entreprises, tuteurs, absences, logs) doivent systématiquement
  proposer : une recherche libre, des filtres avancés combinables, un système de pagination, et un
  export des données affichées.
- Les tableaux comportant plusieurs colonnes numériques ou temporelles doivent être triables par
  colonne.
- Les indicateurs chiffrés importants (compteurs, totaux) doivent être mis en avant visuellement en
  tête de chaque écran de pilotage.
- Le module de suivi des absences peut adopter une identité visuelle spécifique, distincte du reste de
  l'application, à condition de conserver les mêmes standards d'ergonomie (mêmes types de composants,
  mêmes comportements de filtre/tri/pagination) que le reste de l'outil.
