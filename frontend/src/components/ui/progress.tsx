import { cn } from '@/lib/utils';

export function Progress({
  value,
  className,
  barClassName,
}: {
  value: number;
  className?: string;
  barClassName?: string;
}) {
  const clamped = Math.min(Math.max(value, 0), 100);

  return (
    <div
      className={cn('h-2 w-full overflow-hidden rounded-full bg-muted', className)}
      role="progressbar"
      aria-valuenow={Math.round(clamped)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div
        className={cn('h-full rounded-full bg-gradient-brand transition-[width] duration-700 ease-out', barClassName)}
        style={{ width: `${clamped}%` }}
      />
    </div>
  );
}
