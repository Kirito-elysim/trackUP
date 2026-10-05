import { useState, useMemo } from 'react';
import { LearnerLogDeliveryDialog, type LogPath } from './LearnerLogDeliveryDialog';
import { Search, Send } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { clampPercentage, formatDuration, formatPercentage } from '../lib/format';
import { cn } from '@/lib/utils';
import type { ElearningReminderReason } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Avatar } from '@/components/ui/avatar';
import { Dialog, DialogBody, DialogContent, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { SortableHead, Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';

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
};

type SortField =
  | 'name'
  | 'sessionTime'
  | 'elearningTime'
  | 'expectedTime'
  | 'expectedElearningTime'
  | 'timeCompletion'
  | 'elearningCompletion'
  | 'progress';

type SortDirection = 'asc' | 'desc';

type LearnerTableProps = {
  data: LearnerTableData[];
  logPaths?: LogPath[];
  logGroupId?: number;
  logContextLabel?: string;
  title?: string;
  showProgress?: boolean;
  onRowClick?: (learner: LearnerTableData) => void;
};

const REMINDER_OPTIONS: Array<{ value: ElearningReminderReason; label: string; description: string }> = [
  {
    value: 'progress',
    label: 'Avancement insuffisant',
    description: "L'avancement dans les modules n'est pas conforme aux attentes — invite à se reconnecter pour finaliser.",
  },
  {
    value: 'schedule',
    label: 'Horaires de connexion',
    description: 'Rappelle de rester connecté(e) pendant les créneaux prévus (8h-12h et 14h-17h) lors des sessions e-learning.',
  },
];

// Colonnes resserrées (roadmap : partagé entre fiche groupe et fiche parcours, toute modification ici
// s'applique aux deux) pour que tout tienne sans scroll horizontal : police réduite, padding resserré,
// en-têtes autorisés à passer à la ligne (sinon un libellé long comme "Temps prévu masterclass"
// déborderait de sa colonne à largeur fixe). Deux jeux de largeurs selon showProgress, pour garder le
// total à 100% avec ou sans la colonne Progression.
const CELL_CLASS = 'px-2 py-2';
const HEAD_CLASS = 'whitespace-normal break-words px-2 py-2 leading-tight';
const COLUMN_WIDTHS_WITH_PROGRESS = [3, 15, 7, 8, 13, 7, 8, 13, 12, 14];
const COLUMN_WIDTHS_WITHOUT_PROGRESS = [3, 17, 8, 9, 15, 8, 9, 15, 16];

export function LearnerTable({ data, title = 'Apprenants', showProgress = true, onRowClick, logPaths, logGroupId, logContextLabel }: LearnerTableProps) {
  const { token, canAccess } = useAuth();
  const [logTargets, setLogTargets] = useState<number[] | null>(null);
  const canSendLogs = logPaths !== undefined && canAccess('learners.manage') && canAccess('exports.view');
  const [searchQuery, setSearchQuery] = useState('');
  const [sortField, setSortField] = useState<SortField>('name');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
  const [reminderTargets, setReminderTargets] = useState<LearnerTableData[] | null>(null);
  const [reminderReason, setReminderReason] = useState<ElearningReminderReason>('progress');
  const [sending, setSending] = useState(false);
  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null);

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
        default:
          return 0;
      }

      if (aValue < bValue) return sortDirection === 'asc' ? -1 : 1;
      if (aValue > bValue) return sortDirection === 'asc' ? 1 : -1;
      return 0;
    });

    return sorted;
  }, [data, searchQuery, sortField, sortDirection]);

  const columnWidths = showProgress ? COLUMN_WIDTHS_WITH_PROGRESS : COLUMN_WIDTHS_WITHOUT_PROGRESS;

  const allVisibleSelected = filteredAndSorted.length > 0 && filteredAndSorted.every((learner) => selectedIds.has(learner.id));

  const toggleAll = () => {
    setSelectedIds((current) => {
      if (allVisibleSelected) {
        const next = new Set(current);
        filteredAndSorted.forEach((learner) => next.delete(learner.id));
        return next;
      }
      const next = new Set(current);
      filteredAndSorted.forEach((learner) => next.add(learner.id));
      return next;
    });
  };

  const toggleOne = (id: number) => {
    setSelectedIds((current) => {
      const next = new Set(current);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const openReminder = (targets: LearnerTableData[]) => {
    setFeedback(null);
    setReminderReason('progress');
    setReminderTargets(targets);
  };

  const sendReminder = async () => {
    if (!token || !reminderTargets || reminderTargets.length === 0) return;

    setSending(true);
    setFeedback(null);
    try {
      const result = await apiRequest<{ sent: number; skipped: number }>('/api/learners/elearning-reminder', {
        method: 'POST',
        token,
        body: { learnerIds: reminderTargets.map((learner) => learner.learnerId), reason: reminderReason },
      });
      setFeedback({
        type: 'success',
        message:
          result.skipped > 0
            ? `${result.sent} email(s) envoyé(s), ${result.skipped} ignoré(s) (pas d'adresse email).`
            : `${result.sent} email(s) envoyé(s).`,
      });
      setSelectedIds(new Set());
    } catch (caught) {
      setFeedback({ type: 'error', message: caught instanceof ApiError ? caught.message : 'Envoi impossible.' });
    } finally {
      setSending(false);
    }
  };

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

          <div className="flex flex-wrap items-center gap-3">
            {selectedIds.size > 0 ? (
              <Button size="sm" onClick={() => openReminder(data.filter((learner) => selectedIds.has(learner.id)))}>
                <Send size={14} />
                Envoyer une relance ({selectedIds.size})
              </Button>
            ) : null}
            {canSendLogs && selectedIds.size > 0 && <Button size="sm" variant="outline" onClick={() => setLogTargets(data.filter(learner => selectedIds.has(learner.id)).map(learner => learner.learnerId))}>Envoyer les logs ({selectedIds.size})</Button>}
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
        </div>

        <TableShell className="overflow-hidden">
          <Table className="min-w-0 table-fixed text-xs">
            <colgroup>
              {columnWidths.map((width, index) => (
                <col key={index} style={{ width: `${width}%` }} />
              ))}
            </colgroup>
            <TableHeader>
              <TableRow>
                <TableHead className={CELL_CLASS}>
                  <input
                    type="checkbox"
                    className="accent-primary"
                    checked={allVisibleSelected}
                    onChange={toggleAll}
                    aria-label="Tout sélectionner"
                  />
                </TableHead>
                <SortableHead {...sortableHeadProps('name')} className={HEAD_CLASS}>{title === 'Membres' ? 'Membre' : 'Apprenant'}</SortableHead>
                <SortableHead {...sortableHeadProps('sessionTime')} className={HEAD_CLASS}>Temps masterclass</SortableHead>
                <SortableHead {...sortableHeadProps('expectedTime')} className={HEAD_CLASS}>Temps prévu masterclass</SortableHead>
                <SortableHead {...sortableHeadProps('timeCompletion')} className={HEAD_CLASS}>Completion masterclass</SortableHead>
                <SortableHead {...sortableHeadProps('elearningTime')} className={HEAD_CLASS}>Temps e-learning</SortableHead>
                <SortableHead {...sortableHeadProps('expectedElearningTime')} className={HEAD_CLASS}>Temps prévu e-learning</SortableHead>
                <SortableHead {...sortableHeadProps('elearningCompletion')} className={HEAD_CLASS}>Completion e-learning</SortableHead>
                {showProgress ? <SortableHead {...sortableHeadProps('progress')} className={HEAD_CLASS}>Progression</SortableHead> : null}
                <TableHead className={cn(CELL_CLASS, 'text-right')}>Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredAndSorted.map((learner) => (
                <TableRow
                  key={learner.id}
                  onClick={() => onRowClick?.(learner)}
                  className={onRowClick ? 'cursor-pointer' : undefined}
                >
                  <TableCell className={CELL_CLASS} onClick={(event) => event.stopPropagation()}>
                    <input
                      type="checkbox"
                      className="accent-primary"
                      checked={selectedIds.has(learner.id)}
                      onChange={() => toggleOne(learner.id)}
                      aria-label={`Sélectionner ${learner.fullName}`}
                    />
                  </TableCell>
                  <TableCell className={CELL_CLASS}>
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar name={learner.fullName} />
                      <div className="min-w-0">
                        <strong className="block truncate text-sm font-semibold">{learner.fullName}</strong>
                        <p className="truncate text-xs text-muted-foreground">{learner.email}</p>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className={cn('tabular whitespace-nowrap font-semibold', CELL_CLASS)}>
                    {formatDuration(learner.sessionTime)}
                  </TableCell>
                  <TableCell className={cn('tabular whitespace-nowrap font-semibold', CELL_CLASS)}>
                    {formatDuration(learner.expectedTime)}
                  </TableCell>
                  <TableCell className={CELL_CLASS}>
                    <CompletionCell current={learner.sessionTime} expected={learner.expectedTime} />
                  </TableCell>
                  <TableCell className={cn('tabular whitespace-nowrap font-semibold', CELL_CLASS)}>
                    {formatDuration(learner.elearningTime)}
                  </TableCell>
                  <TableCell className={cn('tabular whitespace-nowrap font-semibold', CELL_CLASS)}>
                    {formatDuration(learner.expectedElearningTime)}
                  </TableCell>
                  <TableCell className={CELL_CLASS}>
                    <CompletionCell current={learner.elearningTime} expected={learner.expectedElearningTime} />
                  </TableCell>
                  {showProgress ? (
                    <TableCell className={CELL_CLASS}>
                      <div className="flex items-center gap-2.5">
                        <Progress value={clampPercentage(learner.averageProgress)} className="w-24" />
                        <span className="tabular text-xs font-semibold text-muted-foreground">
                          {formatPercentage(learner.averageProgress ?? 0)}
                        </span>
                      </div>
                    </TableCell>
                  ) : null}
                  <TableCell className={cn(CELL_CLASS, 'text-right')} onClick={(event) => event.stopPropagation()}>
                    <div className="flex flex-wrap justify-end gap-1">
                    <Button variant="outline" size="sm" onClick={() => openReminder([learner])}>
                      <Send size={13} />
                      Relance
                    </Button>
                    {canSendLogs && <Button variant="outline" size="sm" onClick={() => setLogTargets([learner.learnerId])}>Logs</Button>}
                    </div>
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

      {logTargets && logPaths && <LearnerLogDeliveryDialog learnerIds={logTargets} paths={logPaths} groupId={logGroupId} contextLabel={logContextLabel} onClose={() => setLogTargets(null)} />}

      <Dialog open={reminderTargets !== null} onOpenChange={(open) => !open && setReminderTargets(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Envoyer une relance e-learning</DialogTitle>
            <DialogDescription>
              {reminderTargets?.length === 1
                ? reminderTargets[0].fullName
                : `${reminderTargets?.length ?? 0} apprenant(s) sélectionné(s)`}
            </DialogDescription>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-3">
            {REMINDER_OPTIONS.map((option) => (
              <label
                key={option.value}
                className="flex items-start gap-2.5 rounded-md border border-border px-3.5 py-3 text-sm hover:bg-muted/40"
              >
                <input
                  type="radio"
                  name="reminderReason"
                  className="mt-0.5 accent-primary"
                  checked={reminderReason === option.value}
                  onChange={() => setReminderReason(option.value)}
                />
                <span>
                  <strong className="block font-medium">{option.label}</strong>
                  <span className="text-xs text-muted-foreground">{option.description}</span>
                </span>
              </label>
            ))}

            {feedback ? (
              <p className={feedback.type === 'success' ? 'text-sm font-medium text-success' : 'text-sm font-medium text-destructive'}>
                {feedback.message}
              </p>
            ) : null}

            <div className="flex justify-end gap-2 pt-1">
              <Button variant="outline" onClick={() => setReminderTargets(null)}>
                Fermer
              </Button>
              <Button disabled={sending} onClick={() => void sendReminder()}>
                {sending ? 'Envoi...' : 'Envoyer'}
              </Button>
            </div>
          </DialogBody>
        </DialogContent>
      </Dialog>
    </Card>
  );
}

function CompletionCell({ current, expected }: { current: number; expected: number }) {
  const percent = expected > 0 ? (current / expected) * 100 : 0;

  return (
    <div className="flex items-center gap-1.5">
      <Progress value={Math.min(percent, 100)} className="w-24" />
      <span className="tabular whitespace-nowrap text-xs font-semibold text-muted-foreground">
        {expected > 0 ? formatPercentage(percent) : '0%'}
      </span>
    </div>
  );
}
