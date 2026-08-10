import * as React from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import { cn } from '@/lib/utils';

export function TableShell({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('w-full overflow-auto', className)} {...props} />;
}

export function Table({ className, ...props }: React.TableHTMLAttributes<HTMLTableElement>) {
  return <table className={cn('w-full min-w-max border-collapse text-sm', className)} {...props} />;
}

export function TableHeader({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
  return <thead className={cn('border-b border-border', className)} {...props} />;
}

export function TableBody({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
  return <tbody className={cn('divide-y divide-border', className)} {...props} />;
}

export function TableRow({ className, ...props }: React.HTMLAttributes<HTMLTableRowElement>) {
  return <tr className={cn('transition-colors duration-150 hover:bg-primary/5', className)} {...props} />;
}

export function TableHead({ className, ...props }: React.ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      className={cn(
        'whitespace-nowrap px-3 py-2.5 text-left text-xs font-bold uppercase tracking-wide text-muted-foreground',
        className,
      )}
      {...props}
    />
  );
}

export function TableCell({ className, ...props }: React.TdHTMLAttributes<HTMLTableCellElement>) {
  return <td className={cn('px-3 py-3 align-top', className)} {...props} />;
}

export function SortableHead({
  className,
  active,
  direction,
  onClick,
  children,
}: {
  className?: string;
  active?: boolean;
  direction?: 'asc' | 'desc';
  onClick?: () => void;
  children: React.ReactNode;
}) {
  const Icon = !active ? ArrowUpDown : direction === 'asc' ? ArrowUp : ArrowDown;

  return (
    <th className={cn('whitespace-nowrap px-3 py-2.5 text-left', className)}>
      <button
        type="button"
        onClick={onClick}
        className={cn(
          'inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide transition-colors',
          active ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
        )}
      >
        {children}
        <Icon size={12} className={cn('shrink-0', !active && 'opacity-40')} />
      </button>
    </th>
  );
}
