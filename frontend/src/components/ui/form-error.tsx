import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

// Bloc d'erreur homogène pour tous les formulaires de l'app (auth, admin, module Alternance) —
// évite que chaque page réinvente son propre style ou, pire, un simple <p> gris comme c'était le
// cas sur certaines pages, qui rendait les messages d'erreur incohérents d'un formulaire à l'autre.
export function FormError({ message, className }: { message: string | null | undefined; className?: string }) {
  if (!message) {
    return null;
  }

  return (
    <div
      role="alert"
      className={cn(
        'flex items-start gap-2.5 rounded-xl border border-destructive/20 bg-destructive/10 p-3.5 text-sm font-medium text-destructive',
        className,
      )}
    >
      <AlertCircle size={16} className="mt-0.5 shrink-0" />
      <span>{message}</span>
    </div>
  );
}
