import { useState, useMemo } from 'react';
import { Search } from 'lucide-react';
import { clampPercentage, formatDuration, formatPercentage, formatDateTime } from '../lib/format';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Avatar } from '@/components/ui/avatar';
import { SortableHead, Table, TableBody, TableCell, TableHeader, TableRow, TableShell } from '@/components/ui/table';

export type LearnerTableData = {
  id: number;
  learnerId: number;
  fullName: string;
  email: string | null;
  totalTime: number;
  sessionTime: number;
  elearningTime: number;
  expectedTime: number;
  expectedElearningTime: number;
  averageProgress?: number;
  subscribedAt?: string | null;
  joinedAt?: string | null;
};

type SortField =
  | 'name'
  | 'combinedTime'
  | 'sessionTime'
  | 'elearningTime'
  | 'expectedTime'
  | 'expectedElearningTime'
  | 'timeCompletion'
  | 'elearningCompletion'
  | 'progress'
  | 'subscribedAt';

type SortDirection = 'asc' | 'desc';

type LearnerTableProps = {
  data: LearnerTableData[];
  title?: string;
  showProgress?: boolean;
  onRowClick?: (learner: LearnerTableData) => void;
};

export function LearnerTable({ data, title = 'Apprenants', showProgress = true, onRowClick }: LearnerTableProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [sortField, setSortField] = useState<SortField>('name');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');

  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const sortableHeadProps = (field: SortField) => ({
    active: sortField === field,
    direction: sortDirection,
    onClick: () => handleSort(field),
  });

  const filteredAndSorted = useMemo(() => {
    let filtered = data;

    if (searchQuery.trim() !== '') {
      const query = searchQuery.toLowerCase();
      filtered = filtered.filter(
        (learner) =>
          learner.fullName.toLowerCase().includes(query) || learner.email?.toLowerCase().includes(query),
      );
    }

    const sorted = [...filtered].sort((a, b) => {
      let aValue: string | number;
      let bValue: string | number;

      switch (sortField) {
        case 'name':
          aValue = a.fullName.toLowerCase();
          bValue = b.fullName.toLowerCase();
          break;
        case 'combinedTime':
          aValue = a.totalTime;
          bValue = b.totalTime;
          break;
        case 'sessionTime':
          aValue = a.sessionTime;
          bValue = b.sessionTime;
          break;
        case 'elearningTime':
          aValue = a.elearningTime;
          bValue = b.elearningTime;
          break;
        case 'expectedTime':
          aValue = a.expectedTime;
          bValue = b.expectedTime;
          break;
        case 'expectedElearningTime':
          aValue = a.expectedElearningTime;
          bValue = b.expectedElearningTime;
          break;
        case 'timeCompletion':
          aValue = a.expectedTime > 0 ? (a.sessionTime / a.expectedTime) * 100 : 0;
          bValue = b.expectedTime > 0 ? (b.sessionTime / b.expectedTime) * 100 : 0;
          break;
        case 'elearningCompletion':
          aValue = a.expectedElearningTime > 0 ? (a.elearningTime / a.expectedElearningTime) * 100 : 0;
          bValue = b.expectedElearningTime > 0 ? (b.elearningTime / b.expectedElearningTime) * 100 : 0;
          break;
        case 'progress':
          aValue = a.averageProgress ?? 0;
          bValue = b.averageProgress ?? 0;
          break;
        case 'subscribedAt':
          aValue = a.subscribedAt || a.joinedAt || '';
          bValue = b.subscribedAt || b.joinedAt || '';
          break;
        default:
          return 0;
      }

      if (aValue < bValue) return sortDirection === 'asc' ? -1 : 1;
      if (aValue > bValue) return sortDirection === 'asc' ? 1 : -1;
      return 0;
    });

    return sorted;
  }, [data, searchQuery, sortField, sortDirection]);

  return (
    <Card>
      <CardContent className="flex flex-col gap-5 p-6">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h3 className="font-display text-lg font-bold tracking-tight">{title}</h3>
            <p className="text-sm text-muted-foreground">
              {filteredAndSorted.length} {title.toLowerCase()} ({data.length} total)
            </p>
          </div>

          <div className="relative w-full max-w-xs">
            <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              type="text"
              className="pl-9"
              placeholder={`Rechercher un ${title.toLowerCase().slice(0, -1)}...`}
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
            />
          </div>
        </div>

        <TableShell>
          <Table className="min-w-[75rem]">
            <TableHeader>
              <TableRow>
                <SortableHead {...sortableHeadProps('name')}>{title === 'Membres' ? 'Membre' : 'Apprenant'}</SortableHead>
                <SortableHead {...sortableHeadProps('combinedTime')}>Temps passé</SortableHead>
                <SortableHead {...sortableHeadProps('sessionTime')}>Temps sessions</SortableHead>
                <SortableHead {...sortableHeadProps('expectedTime')}>Temps prévu sessions</SortableHead>
                <SortableHead {...sortableHeadProps('timeCompletion')}>Completion sessions</SortableHead>
                <SortableHead {...sortableHeadProps('elearningTime')}>Temps e-learning</SortableHead>
                <SortableHead {...sortableHeadProps('expectedElearningTime')}>Temps prévu e-learning</SortableHead>
                <SortableHead {...sortableHeadProps('elearningCompletion')}>Completion e-learning</SortableHead>
                {showProgress ? <SortableHead {...sortableHeadProps('progress')}>Progression</SortableHead> : null}
                <SortableHead {...sortableHeadProps('subscribedAt')}>Date d&apos;inscription</SortableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredAndSorted.map((learner) => (
                <TableRow
                  key={learner.id}
                  onClick={() => onRowClick?.(learner)}
                  className={onRowClick ? 'cursor-pointer' : undefined}
                >
                  <TableCell>
                    <div className="flex items-center gap-3">
                      <Avatar name={learner.fullName} />
                      <div className="min-w-0">
                        <strong className="block truncate text-sm font-semibold">{learner.fullName}</strong>
                        <p className="truncate text-xs text-muted-foreground">{learner.email}</p>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="tabular whitespace-nowrap text-sm font-semibold">
                    {formatDuration(learner.sessionTime + learner.elearningTime)}
                  </TableCell>
                  <TableCell className="tabular whitespace-nowrap text-sm font-semibold">
                    {formatDuration(learner.sessionTime)}
                  </TableCell>
                  <TableCell className="tabular whitespace-nowrap text-sm font-semibold">
                    {formatDuration(learner.expectedTime)}
                  </TableCell>
                  <TableCell>
                    <CompletionCell current={learner.sessionTime} expected={learner.expectedTime} />
                  </TableCell>
                  <TableCell className="tabular whitespace-nowrap text-sm font-semibold">
                    {formatDuration(learner.elearningTime)}
                  </TableCell>
                  <TableCell className="tabular whitespace-nowrap text-sm font-semibold">
                    {formatDuration(learner.expectedElearningTime)}
                  </TableCell>
                  <TableCell>
                    <CompletionCell current={learner.elearningTime} expected={learner.expectedElearningTime} />
                  </TableCell>
                  {showProgress ? (
                    <TableCell>
                      <div className="flex items-center gap-2.5">
                        <Progress value={clampPercentage(learner.averageProgress)} className="w-24" />
                        <span className="tabular text-xs font-semibold text-muted-foreground">
                          {formatPercentage(learner.averageProgress ?? 0)}
                        </span>
                      </div>
                    </TableCell>
                  ) : null}
                  <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                    {learner.subscribedAt
                      ? formatDateTime(learner.subscribedAt)
                      : learner.joinedAt
                        ? formatDateTime(learner.joinedAt)
                        : '-'}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableShell>

        {filteredAndSorted.length === 0 && (
          <p className="py-10 text-center text-sm text-muted-foreground">
            Aucun {title.toLowerCase().slice(0, -1)} trouvé.
          </p>
        )}
      </CardContent>
    </Card>
  );
}

function CompletionCell({ current, expected }: { current: number; expected: number }) {
  const percent = expected > 0 ? (current / expected) * 100 : 0;
  const onTrack = expected > 0 && current / expected >= 0.8;

  return (
    <div className="flex items-center gap-2.5">
      <Progress value={Math.min(percent, 100)} className="w-24" barClassName={onTrack ? 'bg-success' : 'bg-primary'} />
      <span className="tabular text-xs font-semibold text-muted-foreground">
        {expected > 0 ? formatPercentage(percent) : '0%'}
      </span>
    </div>
  );
}
