import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, ArrowRight, Pencil, CheckCircle2, ExternalLink, FileText, Loader2, Mail, RefreshCw, Send, UserX, XCircle } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, apiUrl } from '../lib/api';
import { cn } from '../lib/utils';
import { Avatar } from './ui/avatar';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from './ui/dialog';
import { Progress } from './ui/progress';

type Item = { learnerId: number; name: string; tutorId?: number | null; tutor: string | null; email: string | null; problem: string | null; fix?: 'learner' | 'tutor' | null; pathTitle?: string; subject: string; text: string; filename: string; status: string; error?: string };
type Preview = { token: string; items: Item[] };
type Step = 'checking' | 'review' | 'sending' | 'done';
export type LogPath = { id: number; title: string };

const MAX_LEARNERS = 50;
const STEPS: { key: Step; label: string }[] = [
  { key: 'checking', label: 'Vérification des tuteurs' },
  { key: 'review', label: 'Aperçu du mail et du PDF' },
  { key: 'done', label: 'Envoi' },
];

// Tutor assignment is edited on the learner page, the tutor's email on the tutor page.
function fixLink(item: Item): { href: string; label: string } {
  return item.fix === 'tutor' && item.tutorId
    ? { href: `/tutors/${item.tutorId}`, label: 'Corriger l’email du tuteur' }
    : { href: `/learners/${item.learnerId}`, label: 'Assigner un tuteur' };
}

function statusBadge(item: Item, pdfReady: boolean) {
  if (item.problem) return <Badge variant="destructive"><UserX size={12} />Exclu</Badge>;
  if (item.status === 'sent') return <Badge variant="success"><CheckCircle2 size={12} />Envoyé</Badge>;
  if (item.status === 'failed') return <Badge variant="destructive"><XCircle size={12} />Échec</Badge>;
  if (item.status === 'sending') return <Badge variant="secondary">À vérifier</Badge>;
  if (!pdfReady) return <Badge variant="secondary"><Loader2 size={12} className="animate-spin" />PDF…</Badge>;
  return <Badge variant="success"><CheckCircle2 size={12} />Prêt</Badge>;
}

export function LearnerLogDeliveryDialog({ learnerIds, paths, groupId, contextLabel, onClose }: { learnerIds: number[]; paths: LogPath[]; groupId?: number; contextLabel?: string; onClose: () => void }) {
  const { token } = useAuth();
  const pathId = groupId ? undefined : paths[0]?.id;
  const scopeTitle = contextLabel ?? paths.map(path => path.title).join(' · ');
  const tooMany = learnerIds.length > MAX_LEARNERS;
  const [preview, setPreview] = useState<Preview | null>(null);
  const [urls, setUrls] = useState<Record<number, string>>({});
  const [activeId, setActiveId] = useState<number | null>(null);
  const [step, setStep] = useState<Step>('checking');
  const [error, setError] = useState('');
  const ownedUrls = useRef<string[]>([]);
  const mounted = useRef(true);
  const started = useRef(false);
  const run = useRef(0);
  const busy = step === 'sending' || (step === 'checking' && !error);

  useEffect(() => { const allocated = ownedUrls.current; mounted.current = true; return () => { mounted.current = false; allocated.forEach(URL.revokeObjectURL); }; }, []);

  async function prepare() {
    const current = ++run.current;
    const stale = () => !mounted.current || run.current !== current;
    setError(''); setStep('checking'); setPreview(null); setUrls({});
    try {
      const result = await apiRequest<Preview>('/api/learner-log-deliveries/preview', { method: 'POST', token, body: { learnerIds, ...(groupId ? { groupId } : { learningPathId: pathId }) } });
      if (stale()) return;
      setPreview(result);
      setActiveId((result.items.find(item => !item.problem) ?? result.items[0])?.learnerId ?? null);
      setStep('review');
      // One PDF at a time: the server freezes each attachment into the draft.
      for (const item of result.items.filter(item => !item.problem)) {
        const response = await fetch(apiUrl(`/api/learner-log-deliveries/${result.token}/pdf/${item.learnerId}`), { headers: { Authorization: `Bearer ${token}` } });
        if (!response.ok) throw new Error(`Impossible de générer le PDF de ${item.name}.`);
        const blob = await response.blob();
        if (stale()) return;
        const url = URL.createObjectURL(blob); ownedUrls.current.push(url);
        setUrls(current => ({ ...current, [item.learnerId]: url }));
      }
    } catch (e) { if (!stale()) setError(e instanceof Error ? e.message : 'Préparation impossible.'); }
  }

  useEffect(() => {
    if (started.current || tooMany || learnerIds.length === 0) return;
    started.current = true;
    void prepare();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- runs once when the dialog opens
  }, []);

  async function send() {
    if (!preview) return;
    setStep('sending'); setError('');
    try {
      const result = await apiRequest<{ items: Item[] }>(`/api/learner-log-deliveries/${preview.token}/send`, { method: 'POST', token });
      setPreview({ ...preview, items: result.items }); setStep('done');
    } catch (e) { setError(e instanceof Error ? e.message : 'Envoi impossible.'); setStep('review'); }
  }

  const items = preview?.items ?? [];
  const active = items.find(item => item.learnerId === activeId);
  const eligible = items.filter(item => !item.problem);
  const excluded = items.length - eligible.length;
  const pdfCount = eligible.filter(item => urls[item.learnerId]).length;
  const ready = eligible.length > 0 && pdfCount === eligible.length;
  const sentCount = items.filter(item => item.status === 'sent').length;
  const failedCount = items.filter(item => item.status === 'failed').length;
  const stepIndex = step === 'sending' ? 2 : STEPS.findIndex(s => s.key === step);

  return <Dialog open onOpenChange={open => { if (!open && !busy) onClose(); }}>
    <DialogContent className="h-[min(90vh,820px)] max-h-[90vh] w-[min(96vw,1180px)]">
      <DialogHeader className="gap-3 bg-gradient-to-br from-primary/5 via-card to-card pb-4">
        <div className="flex items-center gap-3">
          <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary"><Mail size={20} /></span>
          <div className="min-w-0">
            <DialogTitle>Envoyer les logs aux tuteurs</DialogTitle>
            <DialogDescription className="truncate">
              {learnerIds.length} apprenant{learnerIds.length > 1 ? 's' : ''}{scopeTitle ? ` · ${scopeTitle}` : ''} · un mail et un PDF personnel par apprenant
            </DialogDescription>
          </div>
        </div>
        <ol className="flex flex-wrap items-center gap-2 text-xs font-semibold">
          {STEPS.map((s, index) => <li key={s.key} className="flex items-center gap-2">
            <span className={cn('flex items-center gap-1.5 rounded-full px-3 py-1 transition-colors duration-300',
              index < stepIndex ? 'bg-success/10 text-success' : index === stepIndex ? 'bg-primary text-primary-foreground shadow-sm' : 'bg-muted text-muted-foreground')}>
              {index < stepIndex ? <CheckCircle2 size={13} /> : <span>{index + 1}</span>}{s.label}
            </span>
            {index < STEPS.length - 1 && <ArrowRight size={13} className="text-muted-foreground" />}
          </li>)}
        </ol>
      </DialogHeader>

      <div className="flex min-h-0 flex-1 flex-col">
        {tooMany ? <div className="m-6 flex items-center gap-3 rounded-xl border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive"><AlertTriangle size={18} />Sélectionnez au maximum {MAX_LEARNERS} apprenants par envoi.</div>
        : !preview ? <div className="flex flex-1 flex-col items-center justify-center gap-3 p-10 text-center">
          {error ? <>
            <AlertTriangle className="text-destructive" size={32} />
            <p role="alert" className="text-sm text-destructive">{error}</p>
            <Button variant="outline" onClick={() => void prepare()}><RefreshCw />Réessayer</Button>
          </> : <>
            <Loader2 className="animate-spin text-primary" size={32} />
            <p className="font-semibold">Vérification des tuteurs…</p>
            <p className="text-sm text-muted-foreground">On contrôle que chaque apprenant a un tuteur avec un email valide.</p>
          </>}
        </div>
        : <div className="grid min-h-0 flex-1 lg:grid-cols-[340px_1fr]">
          <aside className="flex min-h-0 flex-col border-b border-border lg:border-b-0 lg:border-r">
            <div className="flex flex-wrap gap-2 px-4 pt-4">
              <Badge variant="success">{eligible.length} destinataire{eligible.length > 1 ? 's' : ''} valide{eligible.length > 1 ? 's' : ''}</Badge>
              {excluded > 0 && <Badge variant="destructive">{excluded} exclu{excluded > 1 ? 's' : ''}</Badge>}
              {excluded > 0 && step === 'review' && <button type="button" onClick={() => void prepare()} className="ml-auto inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline"><RefreshCw size={12} />Revérifier</button>}
            </div>
            {step === 'review' && eligible.length > 0 && !ready && <div className="px-4 pt-3">
              <div className="mb-1 flex justify-between text-xs text-muted-foreground"><span>Génération des PDF</span><span>{pdfCount}/{eligible.length}</span></div>
              <Progress value={(pdfCount / eligible.length) * 100} barClassName="bg-primary" />
            </div>}
            <ul className="max-h-60 min-h-0 flex-1 space-y-2 overflow-y-auto p-4 lg:max-h-none">
              {items.map((item, index) => <li key={item.learnerId} className="animate-rise-in" style={{ animationDelay: `${Math.min(index, 12) * 40}ms` }}>
                <div role="button" tabIndex={0} onClick={() => setActiveId(item.learnerId)}
                  onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); setActiveId(item.learnerId); } }}
                  className={cn('w-full cursor-pointer rounded-xl border p-3 text-left transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md',
                    activeId === item.learnerId ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-card',
                    item.problem && 'opacity-75')}>
                  <div className="flex items-center gap-3">
                    <Avatar name={item.name} className={cn('h-8 w-8', item.problem && 'grayscale')} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold">{item.name}</p>
                      <p className={cn('truncate text-xs', item.problem ? 'text-destructive' : 'text-muted-foreground')}>
                        {item.problem ?? `→ ${item.tutor} · ${item.email}`}
                      </p>
                    </div>
                    {statusBadge(item, Boolean(urls[item.learnerId]))}
                  </div>
                  {item.problem && <a href={fixLink(item).href} target="_blank" rel="noreferrer" onClick={event => event.stopPropagation()}
                    className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                    <Pencil size={11} />{fixLink(item).label}<ExternalLink size={11} />
                  </a>}
                  {item.status === 'failed' && item.error && <p className="mt-2 text-xs text-destructive">{item.error}</p>}
                </div>
              </li>)}
            </ul>
          </aside>

          <section className="min-h-0 overflow-y-auto bg-muted/30 p-4 lg:p-6">
            {!active ? <p className="text-sm text-muted-foreground">Sélectionnez un apprenant pour voir l’aperçu.</p>
            : active.problem ? <div key={active.learnerId} className="animate-rise-in mx-auto max-w-lg rounded-2xl border border-destructive/30 bg-card p-6 text-center">
              <UserX className="mx-auto mb-3 text-destructive" size={32} />
              <p className="font-semibold">{active.name} ne recevra pas d’envoi</p>
              <p className="mt-1 text-sm text-destructive">{active.problem}</p>
              <p className="mt-3 text-sm text-muted-foreground">
                {active.fix === 'tutor'
                  ? <>Le tuteur <strong>{active.tutor}</strong> n’a pas d’email valide : corrigez-le dans sa fiche.</>
                  : <>Assignez un tuteur (avec un email) depuis la fiche de l’apprenant.</>}
                {' '}La page s’ouvre dans un nouvel onglet ; revenez ici et cliquez sur « Revérifier ».
              </p>
              <div className="mt-4 flex flex-wrap justify-center gap-2">
                <Button asChild><a href={fixLink(active).href} target="_blank" rel="noreferrer"><Pencil />{fixLink(active).label}<ExternalLink /></a></Button>
                <Button variant="outline" onClick={() => void prepare()}><RefreshCw />Revérifier</Button>
              </div>
            </div>
            : <div key={active.learnerId} className="animate-rise-in mx-auto flex max-w-4xl flex-col gap-4">
              <article className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                <div className="space-y-1.5 border-b border-border px-5 py-4 text-sm">
                  <p><span className="inline-block w-14 text-muted-foreground">À</span><span className="font-medium">{active.tutor}</span> <span className="text-muted-foreground">&lt;{active.email}&gt;</span></p>
                  <p><span className="inline-block w-14 text-muted-foreground">Objet</span><span className="font-semibold">{active.subject}</span></p>
                </div>
                <p className="whitespace-pre-wrap px-5 py-4 text-sm leading-relaxed">{active.text}</p>
                <div className="border-t border-border px-5 py-3">
                  {urls[active.learnerId]
                    ? <a href={urls[active.learnerId]} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 rounded-lg border border-border bg-muted/50 px-3 py-2 text-sm font-medium transition-colors hover:border-primary hover:text-primary">
                        <FileText size={16} className="text-primary" />{active.filename}<ExternalLink size={13} />
                      </a>
                    : <span className="inline-flex items-center gap-2 text-sm text-muted-foreground"><Loader2 size={14} className="animate-spin" />Génération de la pièce jointe…</span>}
                </div>
              </article>
              {urls[active.learnerId]
                ? <iframe title={`PDF de ${active.name}`} src={urls[active.learnerId]} className="h-[520px] w-full rounded-2xl border border-border bg-card" />
                : <div className="h-[520px] w-full animate-pulse rounded-2xl border border-border bg-muted" />}
            </div>}
          </section>
        </div>}
      </div>

      <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-card px-6 py-4">
        <div className="min-w-0 text-sm">
          {error && preview && <p role="alert" className="text-destructive">{error}</p>}
          {!error && step === 'done' && <p role="status" className="flex items-center gap-2 font-medium text-success">
            <CheckCircle2 size={16} />{sentCount} email{sentCount > 1 ? 's' : ''} envoyé{sentCount > 1 ? 's' : ''}{failedCount > 0 && <span className="text-destructive"> · {failedCount} en échec</span>}
          </p>}
          {!error && step === 'review' && <p className="text-muted-foreground">{ready ? 'Tout est prêt. Vérifiez les aperçus avant de confirmer.' : eligible.length === 0 ? 'Aucun tuteur ne peut recevoir le mail.' : 'Préparation des pièces jointes…'}</p>}
        </div>
        <div className="flex gap-2">
          {step === 'done'
            ? <Button onClick={onClose}>Fermer</Button>
            : <>
              <Button variant="outline" disabled={busy} onClick={onClose}>Annuler</Button>
              {preview && <Button disabled={step !== 'review' || !ready} onClick={() => void send()}>
                {step === 'sending' ? <Loader2 className="animate-spin" /> : <Send />}
                {step === 'sending' ? 'Envoi en cours…' : `Confirmer l’envoi (${eligible.length})`}
              </Button>}
            </>}
        </div>
      </footer>
    </DialogContent>
  </Dialog>;
}
