import { useDeferredValue, useEffect, useState } from 'react';
import { Loader2, Plus, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import { Input } from '@/components/ui/input';

export type CreatableOption = {
  id: number;
  name: string;
};

// Combobox recherché côté serveur (debounce via useDeferredValue) avec option de création à la
// volée quand aucune correspondance exacte n'existe — utilisé pour le secteur d'activité, une
// entité à part entière qu'on ne veut pas figer dans une liste fermée.
export function CreatableSelect({
  selected,
  onSelect,
  search,
  create,
  placeholder,
  createLabel = (value) => `Créer « ${value} »`,
  invalid,
}: {
  selected: CreatableOption | null;
  onSelect: (option: CreatableOption | null) => void;
  search: (query: string) => Promise<CreatableOption[]>;
  create: (name: string) => Promise<CreatableOption>;
  placeholder: string;
  createLabel?: (value: string) => string;
  invalid?: boolean;
}) {
  const [query, setQuery] = useState('');
  const deferredQuery = useDeferredValue(query);
  const [open, setOpen] = useState(false);
  const [options, setOptions] = useState<CreatableOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [creating, setCreating] = useState(false);

  useEffect(() => {
    if (!open) {
      return;
    }

    let cancelled = false;
    setLoading(true);

    search(deferredQuery.trim())
      .then((results) => {
        if (!cancelled) {
          setOptions(results);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [open, deferredQuery, search]);

  const select = (option: CreatableOption) => {
    onSelect(option);
    setQuery('');
    setOpen(false);
  };

  const trimmedQuery = query.trim();
  const hasExactMatch = options.some((option) => option.name.toLowerCase() === trimmedQuery.toLowerCase());

  const handleCreate = async () => {
    if (trimmedQuery === '' || creating) {
      return;
    }

    setCreating(true);

    try {
      const created = await create(trimmedQuery);
      select(created);
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="relative">
      <div className="relative">
        <Search size={14} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
        <Input
          value={open ? query : (selected?.name ?? '')}
          onChange={(event) => {
            setQuery(event.target.value);
            setOpen(true);
          }}
          onFocus={() => {
            setQuery('');
            setOpen(true);
          }}
          onBlur={() => setOpen(false)}
          placeholder={selected ? selected.name : placeholder}
          className="pl-9 pr-8"
          invalid={invalid}
        />
        {selected && (
          <button
            type="button"
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => onSelect(null)}
            className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground transition-colors hover:text-foreground"
            aria-label="Effacer"
          >
            <X size={14} />
          </button>
        )}
      </div>

      {open && (
        <div className="absolute top-full z-20 mt-1.5 max-h-64 w-full overflow-y-auto rounded-xl border border-border bg-card p-1.5 shadow-soft-hover">
          {loading ? (
            <div className="flex items-center justify-center gap-2 px-3 py-6 text-sm text-muted-foreground">
              <Loader2 size={14} className="animate-spin" />
              Recherche...
            </div>
          ) : (
            <>
              {options.map((option) => (
                <button
                  key={option.id}
                  type="button"
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => select(option)}
                  className={cn(
                    'flex w-full items-center rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors hover:bg-primary/5',
                    selected?.id === option.id && 'text-primary',
                  )}
                >
                  {option.name}
                </button>
              ))}

              {options.length === 0 && trimmedQuery === '' ? (
                <p className="px-3 py-6 text-center text-sm text-muted-foreground">Aucun résultat</p>
              ) : null}

              {trimmedQuery !== '' && !hasExactMatch ? (
                <button
                  type="button"
                  disabled={creating}
                  onMouseDown={(event) => event.preventDefault()}
                  onClick={() => void handleCreate()}
                  className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-primary transition-colors hover:bg-primary/5 disabled:opacity-60"
                >
                  {creating ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}
                  {createLabel(trimmedQuery)}
                </button>
              ) : null}
            </>
          )}
        </div>
      )}
    </div>
  );
}
