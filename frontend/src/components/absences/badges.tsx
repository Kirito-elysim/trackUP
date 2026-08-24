import { cn } from '@/lib/utils';
import type { AbsenceStatus, AbsenceType } from '@/types/trackup';
import { ABS_STATUS_META, ABS_TYPE_META } from './meta';

const chipBaseClass = 'inline-flex w-fit items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium';

export function AbsStatusChip({ status, className }: { status: AbsenceStatus; className?: string }) {
  const meta = ABS_STATUS_META[status];
  return (
    <span className={cn(chipBaseClass, meta.chip, className)}>
      <span className={cn('h-1.5 w-1.5 rounded-full', meta.dot)} />
      {meta.label}
    </span>
  );
}

export function AbsTypeChip({ type, className }: { type: AbsenceType; className?: string }) {
  const meta = ABS_TYPE_META[type];
  return <span className={cn(chipBaseClass, meta.chip, className)}>{meta.label}</span>;
}

export function AbsAlertBadge({ count, className }: { count: number; className?: string }) {
  return (
    <span
      className={cn(
        chipBaseClass,
        'animate-pulse bg-abs-danger-100 font-semibold text-abs-danger-800 ring-1 ring-abs-danger-200',
        className,
      )}
    >
      <span className="h-1.5 w-1.5 rounded-full bg-abs-danger-500" />
      Alerte &middot; {count} abs. consécutives
    </span>
  );
}

export function AbsAvatar({
  name,
  size = 'md',
  className,
}: {
  name: string;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}) {
  const initials =
    name
      .trim()
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase() ?? '')
      .join('') || '?';
  const sizeClass = size === 'lg' ? 'h-12 w-12 text-base' : size === 'sm' ? 'h-8 w-8 text-xs' : 'h-10 w-10 text-sm';

  return (
    <span
      className={cn(
        sizeClass,
        'flex shrink-0 items-center justify-center rounded-full bg-abs-brand-600 font-semibold text-white shadow-sm',
        className,
      )}
      aria-hidden="true"
    >
      {initials}
    </span>
  );
}
