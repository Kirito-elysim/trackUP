import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// Generic status-word colorizer for the many free-text "state" strings the
// Rise Up API returns (training/session states, etc.) — keyword-matched so
// it doesn't depend on knowing the exact enum values up front.
export function stateChipVariant(state: string): 'success' | 'destructive' | 'accent' | 'neutral' {
  const normalized = state.toLowerCase();
  if (normalized.includes('cancel') || normalized.includes('annul') || normalized.includes('abandon')) {
    return 'destructive';
  }
  if (
    normalized.includes('valid') ||
    normalized.includes('complet') ||
    normalized.includes('confirm') ||
    normalized.includes('done') ||
    normalized.includes('termine')
  ) {
    return 'success';
  }
  if (normalized.includes('progress') || normalized.includes('cours') || normalized.includes('pending') || normalized.includes('attente')) {
    return 'accent';
  }
  return 'neutral';
}

// Learner "state" badge used in every search dropdown across the app —
// active gets the brand success green, suspended the brand destructive red,
// anything else stays the neutral chip default.
export function learnerStateChipClass(state: string): string {
  switch (state.toLowerCase()) {
    case 'active':
      return 'bg-[#00a67e1a] text-[#00a67e]';
    case 'suspended':
      return 'bg-destructive/10 text-destructive';
    default:
      return '';
  }
}
