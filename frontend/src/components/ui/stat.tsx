import { useEffect, useState, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

// The signature motion of the system: headline numbers count up (when the
// value is a plain number) or pop into place (when pre-formatted, e.g.
// "2 h 30") the first time they mount. Pass a `key` to replay on change.
export function CountUp({ value, className }: { value: ReactNode; className?: string }) {
  const isNumeric = typeof value === 'number';
  const [display, setDisplay] = useState<ReactNode>(isNumeric ? 0 : value);

  useEffect(() => {
    if (!isNumeric) {
      return;
    }

    const target = value as number;
    const duration = 700;
    const start = performance.now();

    let frame: number;
    const tick = (now: number) => {
      const progress = Math.min(1, (now - start) / duration);
      const eased = 1 - Math.pow(1 - progress, 3);
      setDisplay(Math.round(eased * target).toLocaleString('fr-FR'));
      if (progress < 1) {
        frame = requestAnimationFrame(tick);
      }
    };
    frame = requestAnimationFrame(tick);

    return () => cancelAnimationFrame(frame);
  }, [isNumeric, value]);

  return (
    <span className={cn('tabular animate-rise-in inline-block font-display font-extrabold leading-none tracking-tight', className)}>
      {display}
    </span>
  );
}
