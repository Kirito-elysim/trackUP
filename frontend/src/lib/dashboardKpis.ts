import {
  Activity,
  AlertTriangle,
  Building2,
  CalendarRange,
  CheckCircle2,
  Clock,
  GraduationCap,
  ShieldAlert,
  TrendingUp,
  UserCheck,
  Users,
  Video,
} from 'lucide-react';
import { formatDuration, formatPercentage } from './format';
import type { DashboardMetrics } from '@/types/trackup';

export type DashboardKpiContext = {
  metrics: DashboardMetrics;
  promotionsCount: number;
};

export type DashboardKpiDefinition = {
  key: string;
  label: string;
  hint?: string;
  icon: typeof Users;
  getValue: (ctx: DashboardKpiContext) => string | number;
};

// Catalogue fixe des KPI sélectionnables dans le panneau de personnalisation du Dashboard — les clés
// doivent rester synchronisées avec ALLOWED_KPIS côté backend (DashboardPreferencesController).
export const DASHBOARD_KPIS: DashboardKpiDefinition[] = [
  {
    key: 'activeLearners7d',
    label: 'Apprenants actifs',
    hint: 'Logs des 7 derniers jours',
    icon: UserCheck,
    getValue: (ctx) => ctx.metrics.activeLearnersLast7Days,
  },
  {
    key: 'learningPathsCount',
    label: 'Parcours / diplômes',
    icon: GraduationCap,
    getValue: (ctx) => ctx.metrics.learningPathsCount,
  },
  {
    key: 'promotionsCount',
    label: 'Promotions',
    icon: CalendarRange,
    getValue: (ctx) => ctx.promotionsCount,
  },
  {
    key: 'companiesTutors',
    label: 'Entreprises / Tuteurs',
    icon: Building2,
    getValue: (ctx) => ctx.metrics.companiesCount,
  },
  {
    key: 'learnersCount',
    label: 'Apprenants (total)',
    icon: Users,
    getValue: (ctx) => ctx.metrics.learnersCount,
  },
  {
    key: 'activeLearnersCount',
    label: 'Apprenants actifs Rise Up',
    hint: 'Statut "actif" Rise Up',
    icon: UserCheck,
    getValue: (ctx) => ctx.metrics.activeLearnersCount,
  },
  {
    key: 'trainingsCount',
    label: 'Formations',
    icon: GraduationCap,
    getValue: (ctx) => ctx.metrics.trainingsCount,
  },
  {
    key: 'sessionsCount',
    label: 'Sessions',
    icon: Video,
    getValue: (ctx) => ctx.metrics.sessionsCount,
  },
  {
    key: 'signedAttendancesCount',
    label: 'Émargements signés',
    icon: CheckCircle2,
    getValue: (ctx) => ctx.metrics.signedAttendancesCount,
  },
  {
    key: 'totalTrackedTime',
    label: 'Temps tracé (total)',
    icon: Clock,
    getValue: (ctx) => formatDuration(ctx.metrics.totalTrackedTime),
  },
  {
    key: 'totalYearTime',
    label: 'Temps de formation (année)',
    icon: Activity,
    getValue: (ctx) => formatDuration(ctx.metrics.totalYearTime),
  },
  {
    key: 'averageProgress',
    label: 'Progression moyenne',
    icon: TrendingUp,
    getValue: (ctx) => formatPercentage(ctx.metrics.averageProgress),
  },
  {
    key: 'absencesCount',
    label: 'Absences (total)',
    icon: AlertTriangle,
    getValue: (ctx) => ctx.metrics.absencesCount,
  },
  {
    key: 'absencesPendingCount',
    label: 'Absences en attente',
    hint: 'Justificatif non encore traité',
    icon: AlertTriangle,
    getValue: (ctx) => ctx.metrics.absencesPendingCount,
  },
  {
    key: 'absencesActiveAlertsCount',
    label: 'Alertes disciplinaires',
    hint: '3 absences masterclass consécutives',
    icon: ShieldAlert,
    getValue: (ctx) => ctx.metrics.absencesActiveAlertsCount,
  },
];

export const DEFAULT_DASHBOARD_KPIS = ['activeLearners7d', 'learningPathsCount', 'promotionsCount', 'companiesTutors'];

export function getDashboardKpi(key: string): DashboardKpiDefinition | undefined {
  return DASHBOARD_KPIS.find((kpi) => kpi.key === key);
}
