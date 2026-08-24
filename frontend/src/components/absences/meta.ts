import type { AbsenceStatus, AbsenceType } from '@/types/trackup';

// Palette et libellés repris à l'identique de /Users/mahdjoub/www/project_absence
// (data/types.ts, STATUS_META/TYPE_META) — voir les tokens "abs-*" dans index.css.
export const ABS_STATUS_META: Record<AbsenceStatus, { label: string; chip: string; dot: string; bar: string }> = {
  en_attente: {
    label: 'En attente',
    chip: 'bg-abs-warning-100 text-abs-warning-800',
    dot: 'bg-abs-warning-500',
    bar: 'bg-abs-warning-500',
  },
  justifiee: {
    label: 'Justifiée',
    chip: 'bg-abs-success-100 text-abs-success-800',
    dot: 'bg-abs-success-500',
    bar: 'bg-abs-success-500',
  },
  non_justifiee: {
    label: 'Non justifiée',
    chip: 'bg-abs-danger-100 text-abs-danger-800',
    dot: 'bg-abs-danger-500',
    bar: 'bg-abs-danger-500',
  },
  autre: {
    label: 'Autre',
    chip: 'bg-abs-ink-100 text-abs-ink-700',
    dot: 'bg-abs-ink-400',
    bar: 'bg-abs-ink-400',
  },
};

export const ABS_TYPE_META: Record<AbsenceType, { label: string; chip: string }> = {
  masterclass: { label: 'Masterclass', chip: 'bg-abs-brand-100 text-abs-brand-700' },
  presentiel: { label: 'Session présentiel', chip: 'bg-abs-accent-100 text-abs-accent-800' },
};
