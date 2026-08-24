import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Search } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import type { AbsenceLearnersPayload } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select } from '@/components/ui/select';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { AbsAvatar } from '@/components/absences/badges';

export function AbsenceLearnersPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [groupExternalId, setGroupExternalId] = useState('');
  const [payload, setPayload] = useState<AbsenceLearnersPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (search !== '') params.set('search', search);
    if (groupExternalId !== '') params.set('groupExternalId', groupExternalId);
    return params.toString();
  }, [groupExternalId, search]);

  useEffect(() => {
    if (!token) return;

    let cancelled = false;

    const load = async () => {
      setLoading(true);
      setError(null);

      try {
        const data = await apiRequest<AbsenceLearnersPayload>(
          `/api/admin/absences/learners${queryString !== '' ? `?${queryString}` : ''}`,
          { token },
        );
        if (!cancelled) setPayload(data);
      } catch (caught) {
        if (!cancelled) setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [queryString, token]);

  return (
    <section className="flex flex-col gap-8">
      <div>
        <Breadcrumb items={[{ label: 'Absences' }, { label: 'Apprenants' }]} />
        <h2 className="mt-1.5 font-display text-3xl font-extrabold tracking-tight">Apprenants concernés</h2>
        <p className="mt-1.5 text-sm text-muted-foreground">
          Annuaire des apprenants ayant au moins une absence enregistrée, avec leur historique de justification.
        </p>
      </div>

      <div className="flex flex-wrap gap-3">
        <div className="relative min-w-[220px] flex-1">
          <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Rechercher un apprenant (nom ou email)..."
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            className="pl-10"
          />
        </div>
        <Select value={groupExternalId} onChange={(event) => setGroupExternalId(event.target.value)} className="sm:w-64">
          <option value="">Tous les groupes</option>
          {(payload?.filters.availableGroups ?? []).map((item) => (
            <option key={item.externalId} value={item.externalId}>
              {item.name}
            </option>
          ))}
        </Select>
      </div>

      {loading ? <Card><CardContent className="p-5 text-sm text-muted-foreground">Chargement...</CardContent></Card> : null}
      {error ? <Card className="border-abs-danger-200 bg-abs-danger-50"><CardContent className="p-5 text-sm text-abs-danger-700">{error}</CardContent></Card> : null}

      {payload ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {payload.learners.map((learner) => (
            <button
              key={learner.id}
              onClick={() => navigate(`/learners/${learner.id}`)}
              className="group text-left"
            >
              <Card className="h-full transition-all duration-200 hover:-translate-y-1 hover:shadow-soft-hover">
                <CardContent className="flex flex-col gap-4 p-5">
                  <div className="flex items-center gap-3">
                    <AbsAvatar name={learner.fullName} size="lg" />
                    <div className="min-w-0">
                      <p className="truncate font-semibold group-hover:text-abs-brand-600">{learner.fullName}</p>
                      <p className="truncate text-xs text-muted-foreground">{learner.group ?? 'Sans groupe'}</p>
                    </div>
                  </div>
                  <div className="grid grid-cols-3 gap-2 text-center">
                    <div className="rounded-lg bg-abs-ink-50 py-2">
                      <p className="text-lg font-bold text-abs-ink-900">{learner.totalAbsences}</p>
                      <p className="text-[10px] uppercase tracking-wide text-abs-ink-400">Total</p>
                    </div>
                    <div className="rounded-lg bg-abs-warning-50 py-2">
                      <p className="text-lg font-bold text-abs-warning-700">{learner.pending}</p>
                      <p className="text-[10px] uppercase tracking-wide text-abs-ink-400">Attente</p>
                    </div>
                    <div className="rounded-lg bg-abs-danger-50 py-2">
                      <p className="text-lg font-bold text-abs-danger-700">{learner.unjustified}</p>
                      <p className="text-[10px] uppercase tracking-wide text-abs-ink-400">Non just.</p>
                    </div>
                  </div>
                  {learner.alertActive ? (
                    <span className="inline-flex w-fit animate-pulse items-center gap-1.5 rounded-full bg-abs-danger-100 px-2.5 py-1 text-xs font-semibold text-abs-danger-800 ring-1 ring-abs-danger-200">
                      <span className="h-1.5 w-1.5 rounded-full bg-abs-danger-500" />
                      Alerte &middot; {learner.consecutiveUnjustifiedMasterclassAbsences} consécutives
                    </span>
                  ) : null}
                </CardContent>
              </Card>
            </button>
          ))}
          {payload.learners.length === 0 ? (
            <p className="col-span-full text-sm text-muted-foreground">Aucun apprenant ne correspond à ces filtres.</p>
          ) : null}
        </div>
      ) : null}
    </section>
  );
}
