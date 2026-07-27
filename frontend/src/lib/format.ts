const DATETIME_FORMATTER = new Intl.DateTimeFormat('fr-FR', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

export function formatDuration(totalMinutes: number | null | undefined): string {
  const minutes = Math.max(0, Math.floor(totalMinutes ?? 0));
  const hours = Math.floor(minutes / 60);
  const remainingMinutes = minutes % 60;

  if (hours === 0) {
    return `${remainingMinutes} min`;
  }

  return `${hours} h ${remainingMinutes.toString().padStart(2, '0')}`;
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return 'Aucune donnée';
  }

  const date = new Date(value.replace(' ', 'T'));

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return DATETIME_FORMATTER.format(date);
}

export function formatPercentage(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '0 %';
  }

  return `${Math.round(value)} %`;
}

// The API always returns progress as a 0-100 percentage, never a 0-1 fraction.
// Use this wherever a progress value drives a CSS width or a >=/< threshold,
// so no page has to re-derive that scale (and risk multiplying by 100 again).
export function clampPercentage(value: number | null | undefined): number {
  return Math.min(Math.max(value ?? 0, 0), 100);
}

export function minutesToHours(totalMinutes: number | null | undefined): number {
  return (totalMinutes ?? 0) / 60;
}
