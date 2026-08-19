import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

// Label + champ + message d'aide/erreur, homogène sur tous les formulaires : astérisque pour les
// champs obligatoires, liseré rouge et message sous le champ concerné dès qu'il est invalide —
// tous les champs en erreur s'affichent simultanément (calculés côté client avant l'envoi), au lieu
// d'un message global découvert un par un à chaque tentative d'enregistrement.
export function FormField({
  label,
  required,
  error,
  helperText,
  className,
  children,
}: {
  label: string;
  required?: boolean;
  error?: string | null;
  helperText?: string;
  className?: string;
  children: ReactNode;
}) {
  return (
    <label className={cn('flex flex-col gap-2', className)}>
      <span className="text-sm font-semibold">
        {label} {required ? <span className="text-destructive">*</span> : null}
      </span>
      {children}
      {error ? (
        <span className="text-xs font-medium text-destructive">{error}</span>
      ) : helperText ? (
        <span className="text-xs text-muted-foreground">{helperText}</span>
      ) : null}
    </label>
  );
}
