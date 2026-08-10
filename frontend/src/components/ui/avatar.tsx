import { cn } from '@/lib/utils';

function getInitials(name: string): string {
  const parts = name.trim().split(/\s+/).slice(0, 2);
  return parts.map((part) => part[0]?.toUpperCase() ?? '').join('') || '?';
}

export function Avatar({ name, className }: { name: string; className?: string }) {
  return (
    <span
      className={cn(
        'flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-xs font-bold text-white',
        className,
      )}
      aria-hidden="true"
    >
      {getInitials(name)}
    </span>
  );
}
