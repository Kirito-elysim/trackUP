import { useEffect, useState } from 'react';
import { Check, XCircle, Clock, Calendar } from 'lucide-react';
import { apiRequest, ApiError } from '../lib/api';
import { useAuth } from '../contexts/useAuth';
import { formatDateTime, formatDuration } from '../lib/format';
import type { LearnerSessionsPayload } from '../types/trackup';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Chip } from '@/components/ui/chip';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';

type Props = {
  endpoint: string;
  learnerName: string;
  subtitle: string;
  emptyMessage: string;
  showLearningPathColumn: boolean;
  onClose: () => void;
};

export function SessionsModal({ endpoint, learnerName, subtitle, emptyMessage, showLearningPathColumn, onClose }: Props) {
  const { token } = useAuth();
  const [data, setData] = useState<LearnerSessionsPayload | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const payload = await apiRequest<LearnerSessionsPayload>(endpoint, { token });

        if (!cancelled) {
          setData(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [token, endpoint]);

  const upcoming = data?.sessions.filter((s) => s.isFuture) ?? [];
  const past = data?.sessions.filter((s) => !s.isFuture) ?? [];

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="w-[min(94vw,900px)]">
        <DialogHeader>
          <DialogTitle>Sessions de {learnerName}</DialogTitle>
          <DialogDescription>{subtitle}</DialogDescription>
        </DialogHeader>

        <DialogBody className="flex flex-col gap-8">
          {loading ? <p className="py-10 text-center text-sm text-muted-foreground">Chargement des sessions...</p> : null}
          {error ? <p className="py-10 text-center text-sm text-destructive">{error}</p> : null}

          {data && data.sessions.length === 0 ? (
            <div className="flex flex-col items-center gap-3 py-14 text-center text-muted-foreground">
              <Calendar size={40} className="opacity-40" />
              <p className="text-sm">{emptyMessage}</p>
            </div>
          ) : null}

          {upcoming.length > 0 ? (
            <section className="flex flex-col gap-3">
              <h4 className="flex items-center gap-2 border-b border-border pb-2.5 font-display text-sm font-bold uppercase tracking-wide text-primary">
                <Calendar size={16} />
                Sessions à venir ({upcoming.length})
              </h4>
              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Session</TableHead>
                      {showLearningPathColumn ? <TableHead>Parcours</TableHead> : null}
                      <TableHead>Formation</TableHead>
                      <TableHead>Dates</TableHead>
                      <TableHead>Durée prévue</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {upcoming.map((session) => (
                      <TableRow key={session.id} className="bg-primary/5">
                        <TableCell>
                          <div className="flex flex-col gap-1.5">
                            <strong className="text-sm font-semibold">{session.title || 'Session sans titre'}</strong>
                            <Chip variant="primary" className="w-fit">
                              À venir
                            </Chip>
                          </div>
                        </TableCell>
                        {showLearningPathColumn ? (
                          <TableCell className="text-sm text-muted-foreground">{session.learningPathTitle}</TableCell>
                        ) : null}
                        <TableCell className="text-sm text-muted-foreground">{session.trainingTitle}</TableCell>
                        <TableCell>
                          <SessionDates session={session} />
                        </TableCell>
                        <TableCell className="tabular text-sm font-semibold">
                          {session.eduDuration ? formatDuration(session.eduDuration) : 'N/A'}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>
            </section>
          ) : null}

          {past.length > 0 ? (
            <section className="flex flex-col gap-3">
              <h4 className="flex items-center gap-2 border-b border-border pb-2.5 font-display text-sm font-bold uppercase tracking-wide">
                <Clock size={16} />
                Sessions passées ({past.length})
              </h4>
              <TableShell>
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Session</TableHead>
                      {showLearningPathColumn ? <TableHead>Parcours</TableHead> : null}
                      <TableHead>Formation</TableHead>
                      <TableHead>Dates</TableHead>
                      <TableHead>Durée</TableHead>
                      <TableHead>Temps compté</TableHead>
                      <TableHead>Signature</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {past.map((session) => (
                      <TableRow key={session.id}>
                        <TableCell>
                          <div className="flex flex-col gap-1.5">
                            <strong className="text-sm font-semibold">{session.title || 'Session sans titre'}</strong>
                            <Chip variant="neutral" className="w-fit">
                              {session.sessionType}
                            </Chip>
                          </div>
                        </TableCell>
                        {showLearningPathColumn ? (
                          <TableCell className="text-sm text-muted-foreground">{session.learningPathTitle}</TableCell>
                        ) : null}
                        <TableCell className="text-sm text-muted-foreground">{session.trainingTitle}</TableCell>
                        <TableCell>
                          <SessionDates session={session} />
                        </TableCell>
                        <TableCell className="tabular text-sm font-semibold">
                          {session.eduDuration ? formatDuration(session.eduDuration) : 'N/A'}
                        </TableCell>
                        <TableCell
                          className={cnDurationClass(session.countedTime)}
                        >
                          {formatDuration(session.countedTime)}
                        </TableCell>
                        <TableCell>
                          {session.hasSigned ? (
                            <div className="flex items-center gap-1.5">
                              <Check size={16} className="text-success" />
                              <div className="flex flex-col">
                                <span className="text-sm font-semibold text-success">Signé</span>
                                {session.signatureDate ? (
                                  <span className="text-xs text-muted-foreground">{formatDateTime(session.signatureDate)}</span>
                                ) : null}
                              </div>
                            </div>
                          ) : (
                            <div className="flex items-center gap-1.5">
                              <XCircle size={16} className="text-destructive" />
                              <span className="text-sm font-semibold text-destructive">Non signé</span>
                            </div>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableShell>
            </section>
          ) : null}
        </DialogBody>
      </DialogContent>
    </Dialog>
  );
}

function cnDurationClass(countedTime: number): string {
  return `tabular text-sm font-semibold ${countedTime > 0 ? 'text-primary' : 'text-muted-foreground'}`;
}

function SessionDates({ session }: { session: LearnerSessionsPayload['sessions'][number] }) {
  if (!session.startAt) {
    return <span className="text-sm italic text-muted-foreground">Non planifiée</span>;
  }

  return (
    <div className="flex flex-col gap-1">
      <span className="flex items-center gap-1.5 text-xs text-foreground">
        <Clock size={12} className="text-muted-foreground" />
        {formatDateTime(session.startAt)}
      </span>
      {session.endAt ? (
        <span className="flex items-center gap-1.5 text-xs text-foreground">
          <Clock size={12} className="text-muted-foreground" />
          {formatDateTime(session.endAt)}
        </span>
      ) : null}
    </div>
  );
}
