import { CheckCircle2 } from 'lucide-react';
import { cn } from '@/lib/utils';

// Pendant positif de FormError — même formulaire, même gabarit, pour que succès et erreur soient
// visuellement de la même famille au lieu d'un texte gris indifférencié.
export function FormSuccess({ message, className }: { message: string | null | undefined; className?: string }) {
  if (!message) {
    return null;
  }

  return (
    <div
      role="status"
      className={cn(
        'flex items-start gap-2.5 rounded-xl border border-success/20 bg-success/10 p-3.5 text-sm font-medium text-success',
        className,
      )}
    >
      <CheckCircle2 size={16} className="mt-0.5 shrink-0" />
      <span>{message}</span>
    </div>
  );
}
