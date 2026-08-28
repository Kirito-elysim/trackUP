import { ChevronLeft, ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { Pagination } from '@/types/trackup';

const PAGE_SIZE_OPTIONS = [10, 20, 50, 100, 200, 500];

// Numéros affichés : toujours la première et la dernière page, la page courante
// et ses voisines directes, des ellipses comblent les trous (1 … 4 5 6 … 12).
function pageNumbers(current: number, total: number): Array<number | 'ellipsis'> {
  if (total <= 7) {
    return Array.from({ length: total }, (_, index) => index + 1);
  }

  const wanted = [...new Set([1, total, current - 1, current, current + 1])]
    .filter((value) => value >= 1 && value <= total)
    .sort((a, b) => a - b);

  const result: Array<number | 'ellipsis'> = [];
  let previous = 0;
  for (const value of wanted) {
    if (previous !== 0 && value - previous > 1) {
      result.push('ellipsis');
    }
    result.push(value);
    previous = value;
  }
  return result;
}

// Pied de tableau partagé (façon Material : « Lignes par page », « 1-10 sur 393 »,
// numéros de pages + chevrons). Se place en dernier enfant d'une carte contenant
// un TableShell ; passer className="-mx-6 -mb-6 mt-4" quand le CardContent a du padding.
export function PaginationBar({
  pagination,
  page,
  pageSize,
  onPageChange,
  onPageSizeChange,
  className,
}: {
  pagination: Pagination;
  page: number;
  pageSize: number;
  onPageChange: (page: number) => void;
  onPageSizeChange: (pageSize: number) => void;
  className?: string;
}) {
  const start = pagination.totalRows === 0 ? 0 : (pagination.page - 1) * pagination.pageSize + 1;
  const end = Math.min(pagination.page * pagination.pageSize, pagination.totalRows);

  return (
    <div
      className={cn(
        'flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-border bg-muted/40 px-4 py-3 text-sm',
        className,
      )}
    >
      <div className="flex items-center gap-2 text-muted-foreground">
        <span>Lignes par page :</span>
        <select
          value={pageSize}
          onChange={(event) => onPageSizeChange(Number(event.target.value))}
          className="h-8 cursor-pointer rounded-md border border-border bg-background px-2 text-sm transition-colors hover:border-primary/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          {PAGE_SIZE_OPTIONS.map((size) => (
            <option key={size} value={size}>
              {size}
            </option>
          ))}
        </select>
      </div>

      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <span className="tabular text-muted-foreground">
          {start}-{end} sur <strong className="font-semibold text-foreground">{pagination.totalRows}</strong>
        </span>

        <nav className="flex items-center gap-1" aria-label="Pagination">
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => onPageChange(page - 1)}
            aria-label="Page précédente"
            className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-background hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
          >
            <ChevronLeft size={15} />
          </button>

          {pageNumbers(page, pagination.totalPages).map((item, index) =>
            item === 'ellipsis' ? (
              <span key={`ellipsis-${index}`} className="flex h-8 w-6 items-center justify-center text-muted-foreground" aria-hidden="true">
                …
              </span>
            ) : (
              <button
                key={item}
                type="button"
                onClick={() => onPageChange(item)}
                aria-current={item === page ? 'page' : undefined}
                className={cn(
                  'tabular flex h-8 min-w-8 items-center justify-center rounded-md px-2 text-sm font-semibold transition-colors',
                  item === page
                    ? 'bg-primary text-primary-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-background hover:text-foreground',
                )}
              >
                {item}
              </button>
            ),
          )}

          <button
            type="button"
            disabled={page >= pagination.totalPages}
            onClick={() => onPageChange(page + 1)}
            aria-label="Page suivante"
            className="flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-background hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
          >
            <ChevronRight size={15} />
          </button>
        </nav>
      </div>
    </div>
  );
}
