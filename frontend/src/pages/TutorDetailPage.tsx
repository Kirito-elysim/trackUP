import { useDeferredValue, useEffect, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { ArrowLeft, Building2, Loader2, Mail, Pencil, Phone, Search, Trash2, UserPlus, UserRound, Users } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import type { Company, LearnerSummary, Tutor, TutorDetail } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/ui/chip';
import { Avatar } from '@/components/ui/avatar';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogBody } from '@/components/ui/dialog';
import { SearchSelect } from '@/components/ui/search-select';
import { FormError } from '@/components/ui/form-error';
import { FormField } from '@/components/ui/form-field';
import { validateTutorForm, mapTutorServerError } from '@/lib/tutorValidation';

type TutorEditForm = {
  firstName: string;
  lastName: string;
  email: string;
  phoneMobile: string;
  phoneFixe: string;
  address: string;
  postalCode: string;
  city: string;
  dateOfBirth: string;
  companyIds: number[];
};

const EMPTY_EDIT_FORM: TutorEditForm = {
  firstName: '',
  lastName: '',
  email: '',
  phoneMobile: '',
  phoneFixe: '',
  address: '',
  postalCode: '',
  city: '',
  dateOfBirth: '',
  companyIds: [],
};

export function TutorDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { token } = useAuth();
  const [data, setData] = useState<TutorDetail | null>(null);
  const [allCompanies, setAllCompanies] = useState<Company[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<TutorEditForm>(EMPTY_EDIT_FORM);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [addCompanyValue, setAddCompanyValue] = useState('');
  const [formMessage, setFormMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const updateField = <K extends keyof TutorEditForm>(key: K, value: TutorEditForm[K]) => {
    setForm((current) => ({ ...current, [key]: value }));
    setFieldErrors((current) => {
      if (!(key in current)) {
        return current;
      }
      const next = { ...current };
      delete next[key as string];
      return next;
    });
  };

  const [attachLearnerOpen, setAttachLearnerOpen] = useState(false);
  const [learnerSearch, setLearnerSearch] = useState('');
  const deferredLearnerSearch = useDeferredValue(learnerSearch);
  const [learnerResults, setLearnerResults] = useState<LearnerSummary[]>([]);
  const [attachingLearnerId, setAttachingLearnerId] = useState<number | null>(null);
  const [attachLearnerMessage, setAttachLearnerMessage] = useState<string | null>(null);

  const load = async () => {
    if (!token || !id) {
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const [payload, companiesPayload] = await Promise.all([
        apiRequest<TutorDetail>(`/api/admin/tutors/${id}`, { token }),
        apiRequest<{ companies: Company[] }>('/api/admin/companies?pageSize=100', { token }),
      ]);
      setData(payload);
      setAllCompanies(companiesPayload.companies);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Chargement impossible.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, id]);

  useEffect(() => {
    if (!token || !attachLearnerOpen || deferredLearnerSearch.trim().length < 2) {
      setLearnerResults([]);
      return;
    }

    let cancelled = false;

    const search = async () => {
      try {
        const params = new URLSearchParams({ q: deferredLearnerSearch.trim(), limit: '10' });
        const payload = await apiRequest<LearnerSummary[]>(`/api/learners?${params.toString()}`, { token });
        if (!cancelled) {
          setLearnerResults(payload);
        }
      } catch (caught) {
        if (!cancelled) {
          setAttachLearnerMessage(caught instanceof ApiError ? caught.message : 'Recherche impossible.');
        }
      }
    };

    void search();

    return () => {
      cancelled = true;
    };
  }, [token, attachLearnerOpen, deferredLearnerSearch]);

  const handleStartEdit = () => {
    if (!data) {
      return;
    }

    setForm({
      firstName: data.tutor.firstName,
      lastName: data.tutor.lastName,
      email: data.tutor.email ?? '',
      phoneMobile: data.tutor.phoneMobile ?? '',
      phoneFixe: data.tutor.phoneFixe ?? '',
      address: data.tutor.address ?? '',
      postalCode: data.tutor.postalCode ?? '',
      city: data.tutor.city ?? '',
      dateOfBirth: data.tutor.dateOfBirth ?? '',
      companyIds: data.tutor.companies.map((company) => company.id),
    });
    setAddCompanyValue('');
    setFieldErrors({});
    setFormMessage(null);
    setEditing(true);
  };

  const handleAddCompany = (value: string) => {
    if (value === '') {
      return;
    }
    const companyId = Number(value);
    setForm((current) =>
      current.companyIds.includes(companyId) ? current : { ...current, companyIds: [...current.companyIds, companyId] },
    );
    setAddCompanyValue('');
  };

  const handleRemoveCompany = (companyId: number) => {
    setForm((current) => ({ ...current, companyIds: current.companyIds.filter((cid) => cid !== companyId) }));
  };

  const handleSave = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!token || !id) {
      return;
    }

    setFormMessage(null);

    const errors = validateTutorForm(form);
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      return;
    }

    setSaving(true);

    try {
      const updated = await apiRequest<Tutor>(`/api/admin/tutors/${id}`, { method: 'PUT', token, body: form });
      setData((current) => (current ? { ...current, tutor: updated } : current));
      setEditing(false);
    } catch (caught) {
      const message = caught instanceof ApiError ? caught.message : 'Mise à jour impossible.';
      const field = mapTutorServerError(message);
      if (field) {
        setFieldErrors({ [field]: message });
      } else {
        setFormMessage(message);
      }
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async () => {
    if (!token || !id) {
      return;
    }

    setDeleting(true);

    try {
      await apiRequest(`/api/admin/tutors/${id}`, { method: 'DELETE', token });
      navigate('/tutors', { replace: true });
    } catch (caught) {
      setFormMessage(caught instanceof ApiError ? caught.message : 'Suppression impossible.');
      setDeleting(false);
      setDeleteOpen(false);
    }
  };

  const handleOpenAttachLearner = () => {
    setLearnerSearch('');
    setLearnerResults([]);
    setAttachLearnerMessage(null);
    setAttachLearnerOpen(true);
  };

  const handleAttachLearner = async (learnerId: number) => {
    if (!token || !id) {
      return;
    }

    setAttachingLearnerId(learnerId);
    setAttachLearnerMessage(null);

    try {
      // companyId volontairement omis : le backend déduit automatiquement l'entreprise si ce
      // tuteur n'en a qu'une seule, sinon l'admin devra la préciser depuis la fiche apprenant.
      await apiRequest(`/api/learners/${learnerId}/assignment`, { method: 'PUT', token, body: { tutorId: Number(id) } });
      setAttachLearnerOpen(false);
      await load();
    } catch (caught) {
      setAttachLearnerMessage(caught instanceof ApiError ? caught.message : 'Rattachement impossible.');
    } finally {
      setAttachingLearnerId(null);
    }
  };

  if (loading) {
    return <p className="py-14 text-center text-sm text-muted-foreground">Chargement...</p>;
  }

  if (error || !data) {
    return (
      <Card className="border-destructive/30 bg-destructive/5">
        <CardContent className="p-5 text-sm text-destructive">{error ?? 'Tuteur introuvable.'}</CardContent>
      </Card>
    );
  }

  const { tutor, learners } = data;

  return (
    <div className="flex flex-col gap-8">
      <div>
        <div className="mb-4 flex items-center justify-between gap-3">
          <Button variant="outline" size="sm" onClick={() => navigate(-1)}>
            <ArrowLeft size={15} />
            Retour
          </Button>
          {!editing ? (
            <div className="flex gap-2.5">
              <Button variant="outline" size="sm" onClick={handleStartEdit}>
                <Pencil size={14} />
                Modifier
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="hover:border-destructive/40 hover:bg-destructive/5 hover:text-destructive"
                onClick={() => setDeleteOpen(true)}
              >
                <Trash2 size={14} />
                Supprimer
              </Button>
            </div>
          ) : null}
        </div>
        <div className="flex items-center gap-3">
          <Avatar name={tutor.fullName} className="h-12 w-12 text-base" />
          <div>
            <h2 className="font-display text-3xl font-extrabold tracking-tight">{tutor.fullName}</h2>
            <div className="mt-1.5 flex flex-wrap gap-1.5">
              {tutor.companies.map((company) => (
                <Chip variant="info" key={company.id}>
                  {company.name}
                </Chip>
              ))}
            </div>
          </div>
        </div>
      </div>

      {editing ? (
        <Card>
          <CardContent className="p-6">
            <form className="flex flex-col gap-4" noValidate onSubmit={handleSave}>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label="Prénom" required error={fieldErrors.firstName}>
                  <Input
                    invalid={!!fieldErrors.firstName}
                    value={form.firstName}
                    onChange={(event) => updateField('firstName', event.target.value)}
                  />
                </FormField>
                <FormField label="Nom" required error={fieldErrors.lastName}>
                  <Input
                    invalid={!!fieldErrors.lastName}
                    value={form.lastName}
                    onChange={(event) => updateField('lastName', event.target.value)}
                  />
                </FormField>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label="Email" required error={fieldErrors.email}>
                  <Input
                    invalid={!!fieldErrors.email}
                    type="email"
                    value={form.email}
                    onChange={(event) => updateField('email', event.target.value)}
                  />
                </FormField>
                <FormField label="Date de naissance">
                  <Input type="date" value={form.dateOfBirth} onChange={(event) => updateField('dateOfBirth', event.target.value)} />
                </FormField>
              </div>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label="Téléphone mobile" error={fieldErrors.phoneMobile}>
                  <Input
                    invalid={!!fieldErrors.phoneMobile}
                    value={form.phoneMobile}
                    onChange={(event) => updateField('phoneMobile', event.target.value)}
                  />
                </FormField>
                <FormField label="Téléphone fixe" error={fieldErrors.phoneFixe}>
                  <Input
                    invalid={!!fieldErrors.phoneFixe}
                    value={form.phoneFixe}
                    onChange={(event) => updateField('phoneFixe', event.target.value)}
                  />
                </FormField>
              </div>

              <FormField label="Adresse">
                <Input value={form.address} onChange={(event) => updateField('address', event.target.value)} />
              </FormField>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label="Code postal" error={fieldErrors.postalCode} helperText="5 chiffres">
                  <Input
                    invalid={!!fieldErrors.postalCode}
                    value={form.postalCode}
                    maxLength={5}
                    inputMode="numeric"
                    onChange={(event) => updateField('postalCode', event.target.value)}
                  />
                </FormField>
                <FormField label="Ville">
                  <Input value={form.city} onChange={(event) => updateField('city', event.target.value)} />
                </FormField>
              </div>

              <div className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Entreprises</span>
                <SearchSelect
                  options={allCompanies
                    .filter((company) => !form.companyIds.includes(company.id))
                    .map((company) => ({ value: String(company.id), label: company.name }))}
                  value={addCompanyValue}
                  onChange={handleAddCompany}
                  placeholder="Ajouter une entreprise"
                  allLabel="Aucune entreprise sélectionnée"
                />
                {form.companyIds.length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {form.companyIds.map((companyId) => {
                      const company = allCompanies.find((candidate) => candidate.id === companyId);
                      return (
                        <Chip key={companyId} variant="info" className="gap-1.5">
                          {company?.name ?? companyId}
                          <button
                            type="button"
                            onClick={() => handleRemoveCompany(companyId)}
                            className="ml-0.5 opacity-70 hover:opacity-100"
                            aria-label="Retirer"
                          >
                            ×
                          </button>
                        </Chip>
                      );
                    })}
                  </div>
                ) : null}
              </div>

              <FormError message={formMessage} />

              <div className="flex gap-3">
                <Button type="submit" disabled={saving}>
                  {saving ? <Loader2 size={15} className="animate-spin" /> : null}
                  Enregistrer
                </Button>
                <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                  Annuler
                </Button>
              </div>
            </form>
          </CardContent>
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card>
            <CardContent className="flex flex-col gap-2 p-5 text-sm">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Coordonnées</p>
              {tutor.email ? (
                <p className="flex items-center gap-1.5">
                  <Mail size={13} className="text-primary" />
                  {tutor.email}
                </p>
              ) : (
                <p className="text-muted-foreground">Email non renseigné</p>
              )}
              {tutor.phoneMobile ? (
                <p className="flex items-center gap-1.5">
                  <Phone size={13} className="text-primary" />
                  {tutor.phoneMobile} (mobile)
                </p>
              ) : null}
              {tutor.phoneFixe ? (
                <p className="flex items-center gap-1.5">
                  <Phone size={13} className="text-primary" />
                  {tutor.phoneFixe} (fixe)
                </p>
              ) : null}
              {tutor.dateOfBirth ? <p className="text-muted-foreground">Né(e) le {tutor.dateOfBirth}</p> : null}
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex flex-col gap-2 p-5 text-sm">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Adresse</p>
              {tutor.address || tutor.postalCode || tutor.city ? (
                <p className="text-muted-foreground">
                  {[tutor.address, [tutor.postalCode, tutor.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')}
                </p>
              ) : (
                <p className="text-muted-foreground">Non renseignée</p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex flex-col gap-2 p-5 text-sm">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Entreprises</p>
              {tutor.companies.length === 0 ? (
                <p className="text-muted-foreground">Aucune entreprise rattachée</p>
              ) : (
                tutor.companies.map((company) => (
                  <Link
                    key={company.id}
                    to={`/companies/${company.id}`}
                    className="flex items-center gap-1.5 text-primary hover:underline"
                  >
                    <Building2 size={13} />
                    {company.name}
                  </Link>
                ))
              )}
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex flex-col gap-2 p-5 text-sm">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Vue d&rsquo;ensemble</p>
              <p className="flex items-center gap-1.5">
                <Users size={13} className="text-primary" />
                {learners.length} apprenant{learners.length > 1 ? 's' : ''} suivi{learners.length > 1 ? 's' : ''}
              </p>
            </CardContent>
          </Card>
        </div>
      )}

      <Card>
        <CardContent className="flex flex-col gap-5 p-6">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h3 className="font-display text-lg font-bold tracking-tight">
              <UserRound size={17} className="mr-1.5 inline-block text-primary" />
              Apprenants suivis ({learners.length})
            </h3>
            <Button variant="outline" size="sm" onClick={handleOpenAttachLearner}>
              <UserPlus size={14} />
              Rattacher un apprenant
            </Button>
          </div>
          {learners.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucun apprenant rattaché.</p>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {learners.map((learner) => (
                <Link
                  key={learner.id}
                  to={`/learners/${learner.id}`}
                  className="flex items-center gap-3 rounded-md border border-border p-3.5 transition hover:border-primary/40 hover:bg-muted/40"
                >
                  <Avatar name={learner.fullName} className="h-9 w-9 text-xs" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{learner.fullName}</p>
                    {learner.email ? <p className="truncate text-xs text-muted-foreground">{learner.email}</p> : null}
                  </div>
                </Link>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Supprimer ce tuteur ?</DialogTitle>
            <DialogDescription>
              Les apprenants rattachés seront simplement détachés, pas supprimés. La fiche tuteur sera masquée mais
              pourra être restaurée par un administrateur si nécessaire.
            </DialogDescription>
          </DialogHeader>
          <DialogBody className="flex flex-row justify-end gap-3">
            <Button variant="outline" onClick={() => setDeleteOpen(false)}>
              Annuler
            </Button>
            <Button variant="destructive" disabled={deleting} onClick={() => void handleDelete()}>
              {deleting ? <Loader2 size={15} className="animate-spin" /> : <Trash2 size={15} />}
              Supprimer
            </Button>
          </DialogBody>
        </DialogContent>
      </Dialog>

      <Dialog open={attachLearnerOpen} onOpenChange={setAttachLearnerOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Rattacher un apprenant</DialogTitle>
            <DialogDescription>Recherchez un apprenant à rattacher à {tutor.fullName}.</DialogDescription>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-4">
            <div className="relative">
              <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
              <Input
                autoFocus
                placeholder="Rechercher par nom ou email..."
                value={learnerSearch}
                onChange={(event) => setLearnerSearch(event.target.value)}
                className="pl-10"
              />
            </div>

            <FormError message={attachLearnerMessage} />

            <div className="flex max-h-72 flex-col gap-1.5 overflow-y-auto">
              {learnerSearch.trim().length < 2 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">Tapez au moins 2 caractères pour rechercher</p>
              ) : learnerResults.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">Aucun apprenant trouvé</p>
              ) : (
                learnerResults.map((learner) => (
                  <button
                    key={learner.id}
                    type="button"
                    disabled={attachingLearnerId !== null}
                    onClick={() => void handleAttachLearner(learner.id)}
                    className="flex w-full items-center gap-3 rounded-md p-2.5 text-left transition hover:bg-muted disabled:opacity-60"
                  >
                    <Avatar name={learner.fullName} className="h-9 w-9 text-xs" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold">{learner.fullName}</p>
                      <p className="truncate text-xs text-muted-foreground">{learner.email}</p>
                    </div>
                    {attachingLearnerId === learner.id ? <Loader2 size={15} className="animate-spin text-primary" /> : null}
                  </button>
                ))
              )}
            </div>
          </DialogBody>
        </DialogContent>
      </Dialog>
    </div>
  );
}
