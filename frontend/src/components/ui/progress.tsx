import { cn } from '@/lib/utils';

// Below 20%: red (critical). 20-80%: blue (on track) — kept clearly apart from
// red/orange so the two lowest tiers never get confused at a glance. 80%+: green (done).
// Pass `barClassName` to override this when the bar isn't a completion metric.
function autoColor(value: number): string {
  if (value < 20) return 'bg-destructive';
  if (value >= 80) return 'bg-success';
  return 'bg-info';
}

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
      className={cn('h-2 w-full overflow-hidden rounded-full bg-[#ff0f7b1a]', className)}
      role="progressbar"
      aria-valuenow={Math.round(clamped)}
      aria-valuemin={0}
      aria-valuemax={100}
    >
      <div
        className={cn('h-full rounded-full transition-[width] duration-700 ease-out', barClassName ?? autoColor(clamped))}
        style={{ width: `${clamped}%` }}
      />
    </div>
  );
}
