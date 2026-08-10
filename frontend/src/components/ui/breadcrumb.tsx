import { ChevronRight } from 'lucide-react';

export function Breadcrumb({ items }: { items: Array<{ label: string; onClick?: () => void }> }) {
  return (
    <nav className="flex flex-wrap items-center gap-1.5 text-xs uppercase tracking-wide text-muted-foreground">
      {items.map((item, index) => (
        <span className="flex items-center gap-1.5" key={item.label}>
          {index > 0 && <ChevronRight size={11} className="text-border" aria-hidden="true" />}
          {item.onClick ? (
            <button
              type="button"
              onClick={item.onClick}
              className="transition-colors hover:text-foreground"
            >
              {item.label}
            </button>
          ) : (
            <span className={index === items.length - 1 ? 'text-primary' : undefined}>{item.label}</span>
          )}
        </span>
      ))}
    </nav>
  );
}
