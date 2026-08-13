import { useState } from 'react';
import { Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Input } from '@/components/ui/input';

export type SearchSelectOption = {
  value: string;
  label: string;
  sublabel?: string;
};

export function SearchSelect({
  options,
  value,
  onChange,
  placeholder,
  allLabel,
}: {
  options: SearchSelectOption[];
  value: string;
  onChange: (value: string) => void;
  placeholder: string;
  allLabel: string;
}) {
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);

  const selected = options.find((option) => option.value === value);
  const filtered =
    query.trim() === '' ? options : options.filter((option) => option.label.toLowerCase().includes(query.toLowerCase()));

  const select = (nextValue: string) => {
    onChange(nextValue);
    setQuery('');
    setOpen(false);
  };

  return (
    <div className="relative">
      <div className="relative">
        <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={open ? query : (selected?.label ?? '')}
          onChange={(event) => {
            setQuery(event.target.value);
            setOpen(true);
          }}
          onFocus={() => {
            setQuery('');
            setOpen(true);
          }}
          onBlur={() => setOpen(false)}
          placeholder={selected ? selected.label : placeholder}
          className="pl-9 pr-8"
        />
        {value && (
          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => select('')}
            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
            aria-label="Effacer"
          >
            <X size={14} />
          </button>
        )}
      </div>

      {open && (
        <div className="absolute top-full z-20 mt-1.5 max-h-64 w-full overflow-y-auto rounded-xl border border-border bg-card p-1.5 shadow-soft-hover">
          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => select('')}
            className={cn(
              'flex w-full items-center rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors hover:bg-primary/5',
              value === '' && 'text-primary',
            )}
          >
            {allLabel}
          </button>

          {filtered.map((option) => (
            <button
              key={option.value}
              type="button"
              onMouseDown={(event) => event.preventDefault()}
              onClick={() => select(option.value)}
              className={cn(
                'flex w-full flex-col items-start rounded-lg px-3 py-2 text-left text-sm transition-colors hover:bg-primary/5',
                option.value === value && 'text-primary',
              )}
            >
              <span className="font-medium">{option.label}</span>
              {option.sublabel && <span className="text-xs text-muted-foreground">{option.sublabel}</span>}
            </button>
          ))}

          {filtered.length === 0 && <p className="px-3 py-6 text-center text-sm text-muted-foreground">Aucun résultat</p>}
        </div>
      )}
    </div>
  );
}
