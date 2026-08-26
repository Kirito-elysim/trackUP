import {
  AlertTriangle,
  BarChart3,
  BookOpen,
  Building2,
  Download,
  FileSearch,
  LayoutDashboard,
  Plug,
  RefreshCw,
  Route,
  ShieldAlert,
  ShieldCheck,
  UserCog,
  Users,
  UserRound,
} from 'lucide-react';

// Roadmap 1.3 : lien Analytics masqué du menu à la demande du client (Dashboard recentré sur le
// pilotage par promotion) — la page/route reste entièrement fonctionnelle, seule l'entrée de nav est
// retirée. Repasser à `true` pour la faire réapparaître.
const ANALYTICS_NAV_ENABLED = false;

// Catalogue des pages de l'app, utilisé par la sidebar (AppLayout.tsx) et comme source des
// destinations disponibles pour les raccourcis du Dashboard personnalisable (DashboardCustomizePanel.tsx).
export const NAV_ITEMS = [
  { to: '/dashboard', label: 'Dashboard', feature: 'dashboard.view', group: 'Pilotage', icon: LayoutDashboard },
  { to: '/analytics', label: 'Analytics', feature: 'analytics.view', group: 'Pilotage', icon: BarChart3, hidden: !ANALYTICS_NAV_ENABLED },
  { to: '/learningpaths', label: 'Parcours', feature: 'learningpaths.view', group: 'Pilotage', icon: Route },
  { to: '/courses', label: 'Formations', feature: 'courses.view', group: 'Pilotage', icon: BookOpen },
  { to: '/learners', label: 'Apprenants', feature: 'learners.view', group: 'Alternance', icon: Users },
  { to: '/companies', label: 'Entreprises', feature: 'companies.view', group: 'Alternance', icon: Building2 },
  { to: '/tutors', label: 'Tuteurs', feature: 'companies.view', group: 'Alternance', icon: UserRound },
  { to: '/riseup-logs', label: 'Logs exacts', feature: 'exports.view', group: 'Conformité', icon: FileSearch },
  { to: '/exports', label: 'Exports', feature: 'exports.view', group: 'Conformité', icon: Download },
  { to: '/absences/dashboard', label: 'Tableau de bord', feature: 'absences.view', group: 'Absences', icon: LayoutDashboard },
  { to: '/absences', label: 'Absences', feature: 'absences.view', group: 'Absences', icon: AlertTriangle },
  { to: '/absences/alertes', label: 'Alertes', feature: 'absences.view', group: 'Absences', icon: ShieldAlert },
  { to: '/absences/apprenants', label: 'Apprenants', feature: 'absences.view', group: 'Absences', icon: UserRound },
  { to: '/integrations', label: 'Intégrations', feature: 'integrations.view', group: 'Administration', icon: Plug },
  { to: '/sync', label: 'Synchronisation', feature: 'settings.users', group: 'Administration', icon: RefreshCw },
  { to: '/roles', label: 'Rôles', feature: 'settings.roles', group: 'Administration', icon: ShieldCheck },
  { to: '/users', label: 'Utilisateurs', feature: 'settings.users', group: 'Administration', icon: UserCog },
];
