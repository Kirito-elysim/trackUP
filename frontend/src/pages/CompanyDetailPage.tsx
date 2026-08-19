import { useDeferredValue, useEffect, useState } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { ArrowLeft, Building2, Loader2, Mail, MapPin, Pencil, Phone, Plus, Search, Trash2, User, UserPlus, Users } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import type { Company, CompanyDetail, Sector, Tutor, TutorsIndexResponse } from '../types/trackup';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Chip } from '@/components/ui/chip';
import { Avatar } from '@/components/ui/avatar';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogBody } from '@/components/ui/dialog';
import { CreatableSelect, type CreatableOption } from '@/components/ui/creatable-select';
import { FormError } from '@/components/ui/form-error';
import { FormField } from '@/components/ui/form-field';
import { validateCompanyForm, mapCompanyServerError } from '@/lib/companyValidation';
import { validateTutorForm, mapTutorServerError } from '@/lib/tutorValidation';

const EMPTY_CREATE_TUTOR_FORM = { firstName: '', lastName: '', email: '' };

type CompanyEditForm = {
  name: string;
  siret: string;
  address: string;
  postalCode: string;
  city: string;
  sector: CreatableOption | null;
  contactName: string;
  contactEmail: string;
  contactPhoneMobile: string;
  contactPhoneFixe: string;
};

const EMPTY_EDIT_FORM: CompanyEditForm = {
  name: '',
  siret: '',
  address: '',
  postalCode: '',
  city: '',
  sector: null,
  contactName: '',
  contactEmail: '',
  contactPhoneMobile: '',
  contactPhoneFixe: '',
};

export function CompanyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { token } = useAuth();
  const [data, setData] = useState<CompanyDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<CompanyEditForm>(EMPTY_EDIT_FORM);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [formMessage, setFormMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const updateField = <K extends keyof CompanyEditForm>(key: K, value: CompanyEditForm[K]) => {
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

  const [attachTutorOpen, setAttachTutorOpen] = useState(false);
  const [tutorSearch, setTutorSearch] = useState('');
  const deferredTutorSearch = useDeferredValue(tutorSearch);
  const [tutorResults, setTutorResults] = useState<Tutor[]>([]);
  const [attachingTutorId, setAttachingTutorId] = useState<number | null>(null);
  const [attachTutorMessage, setAttachTutorMessage] = useState<string | null>(null);

  const [showCreateTutor, setShowCreateTutor] = useState(false);
  const [createTutorForm, setCreateTutorForm] = useState(EMPTY_CREATE_TUTOR_FORM);
  const [createTutorErrors, setCreateTutorErrors] = useState<Record<string, string>>({});
  const [creatingTutor, setCreatingTutor] = useState(false);

  const updateCreateTutorField = (key: keyof typeof EMPTY_CREATE_TUTOR_FORM, value: string) => {
    setCreateTutorForm((current) => ({ ...current, [key]: value }));
    setCreateTutorErrors((current) => {
      if (!(key in current)) {
        return current;
      }
      const next = { ...current };
      delete next[key];
      return next;
    });
  };

  const load = async () => {
    if (!token || !id) {
      return;
    }

    setLoading(true);
    setError(null);

    try {
      const payload = await apiRequest<CompanyDetail>(`/api/admin/companies/${id}`, { token });
      setData(payload);
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
    if (!token || !attachTutorOpen || deferredTutorSearch.trim().length < 2) {
      setTutorResults([]);
      return;
    }

    let cancelled = false;

    const search = async () => {
      try {
        const params = new URLSearchParams({ q: deferredTutorSearch.trim(), pageSize: '10' });
        const payload = await apiRequest<TutorsIndexResponse>(`/api/admin/tutors?${params.toString()}`, { token });
        if (!cancelled) {
          const attachedIds = new Set(data?.tutors.map((tutor) => tutor.id) ?? []);
          setTutorResults(payload.tutors.filter((tutor) => !attachedIds.has(tutor.id)));
        }
      } catch (caught) {
        if (!cancelled) {
          setAttachTutorMessage(caught instanceof ApiError ? caught.message : 'Recherche impossible.');
        }
      }
    };

    void search();

    return () => {
      cancelled = true;
    };
  }, [token, attachTutorOpen, deferredTutorSearch, data?.tutors]);

  const handleStartEdit = () => {
    if (!data) {
      return;
    }

    setForm({
      name: data.company.name,
      siret: data.company.siret ?? '',
      address: data.company.address ?? '',
      postalCode: data.company.postalCode ?? '',
      city: data.company.city ?? '',
      sector: data.company.sector,
      contactName: data.company.contactName ?? '',
      contactEmail: data.company.contactEmail ?? '',
      contactPhoneMobile: data.company.contactPhoneMobile ?? '',
      contactPhoneFixe: data.company.contactPhoneFixe ?? '',
    });
    setFieldErrors({});
    setFormMessage(null);
    setEditing(true);
  };

  const handleSave = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!token || !id) {
      return;
    }

    setFormMessage(null);

    const errors = validateCompanyForm(form);
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      return;
    }

    setSaving(true);

    try {
      const updated = await apiRequest<Company>(`/api/admin/companies/${id}`, {
        method: 'PUT',
        token,
        body: { ...form, sectorId: form.sector?.id ?? null },
      });
      setData((current) => (current ? { ...current, company: updated } : current));
      setEditing(false);
    } catch (caught) {
      const message = caught instanceof ApiError ? caught.message : 'Mise à jour impossible.';
      const field = mapCompanyServerError(message);
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
      await apiRequest(`/api/admin/companies/${id}`, { method: 'DELETE', token });
      navigate('/companies', { replace: true });
    } catch (caught) {
      setFormMessage(caught instanceof ApiError ? caught.message : 'Suppression impossible.');
      setDeleting(false);
      setDeleteOpen(false);
    }
  };

  const handleOpenAttachTutor = () => {
    setTutorSearch('');
    setTutorResults([]);
    setAttachTutorMessage(null);
    setShowCreateTutor(false);
    setCreateTutorForm(EMPTY_CREATE_TUTOR_FORM);
    setCreateTutorErrors({});
    setAttachTutorOpen(true);
  };

  const handleAttachTutor = async (tutor: Tutor) => {
    if (!token || !id) {
      return;
    }

    setAttachingTutorId(tutor.id);
    setAttachTutorMessage(null);

    try {
      const companyIds = Array.from(new Set([...tutor.companies.map((company) => company.id), Number(id)]));
      await apiRequest<Tutor>(`/api/admin/tutors/${tutor.id}`, { method: 'PUT', token, body: { companyIds } });
      setAttachTutorOpen(false);
      await load();
    } catch (caught) {
      setAttachTutorMessage(caught instanceof ApiError ? caught.message : 'Rattachement impossible.');
    } finally {
      setAttachingTutorId(null);
    }
  };

  const handleCreateAndAttachTutor = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!token || !id) {
      return;
    }

    const errors = validateTutorForm({ ...createTutorForm, phoneMobile: '', phoneFixe: '', postalCode: '' });
    setCreateTutorErrors(errors);
    if (Object.keys(errors).length > 0) {
      return;
    }

    setCreatingTutor(true);
    setAttachTutorMessage(null);

    try {
      await apiRequest<Tutor>('/api/admin/tutors', {
        method: 'POST',
        token,
        body: { ...createTutorForm, companyIds: [Number(id)] },
      });
      setAttachTutorOpen(false);
      await load();
    } catch (caught) {
      const message = caught instanceof ApiError ? caught.message : 'Création impossible.';
      const field = mapTutorServerError(message);
      if (field) {
        setCreateTutorErrors({ [field]: message });
      } else {
        setAttachTutorMessage(message);
      }
    } finally {
      setCreatingTutor(false);
    }
  };

  const searchSectors = async (query: string): Promise<CreatableOption[]> => {
    if (!token) {
      return [];
    }
    const params = new URLSearchParams();
    if (query !== '') {
      params.set('q', query);
    }
    return apiRequest<Sector[]>(`/api/admin/sectors?${params.toString()}`, { token });
  };

  const createSector = async (name: string): Promise<CreatableOption> => {
    if (!token) {
      throw new ApiError('Non authentifié.', 401);
    }
    return apiRequest<Sector>('/api/admin/sectors', { method: 'POST', token, body: { name } });
  };

  if (loading) {
    return <p className="py-14 text-center text-sm text-muted-foreground">Chargement...</p>;
  }

  if (error || !data) {
    return (
      <Card className="border-destructive/30 bg-destructive/5">
        <CardContent className="p-5 text-sm text-destructive">{error ?? 'Entreprise introuvable.'}</CardContent>
      </Card>
    );
  }

  const { company, tutors, learners } = data;

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
          <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-brand text-white">
            <Building2 size={22} />
          </span>
          <div>
            <h2 className="font-display text-3xl font-extrabold tracking-tight">{company.name}</h2>
            {company.sector ? <Chip variant="info" className="mt-1.5">{company.sector.name}</Chip> : null}
          </div>
        </div>
      </div>

      {editing ? (
        <Card>
          <CardContent className="p-6">
            <form className="flex flex-col gap-4" noValidate onSubmit={handleSave}>
              <FormField label="Nom" required error={fieldErrors.name}>
                <Input invalid={!!fieldErrors.name} value={form.name} onChange={(event) => updateField('name', event.target.value)} />
              </FormField>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label="SIRET" error={fieldErrors.siret}>
                  <Input invalid={!!fieldErrors.siret} value={form.siret} onChange={(event) => updateField('siret', event.target.value)} />
                </FormField>
                <FormField label="Secteur d’activité">
                  <CreatableSelect
                    selected={form.sector}
                    onSelect={(sector) => updateField('sector', sector)}
                    search={searchSectors}
                    create={createSector}
                    placeholder="Rechercher ou créer un secteur"
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

              <FormField label="Contact - nom">
                <Input value={form.contactName} onChange={(event) => updateField('contactName', event.target.value)} />
              </FormField>

              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField label="Contact - téléphone mobile" error={fieldErrors.contactPhoneMobile}>
                  <Input
                    invalid={!!fieldErrors.contactPhoneMobile}
                    value={form.contactPhoneMobile}
                    onChange={(event) => updateField('contactPhoneMobile', event.target.value)}
                  />
                </FormField>
                <FormField label="Contact - téléphone fixe" error={fieldErrors.contactPhoneFixe}>
                  <Input
                    invalid={!!fieldErrors.contactPhoneFixe}
                    value={form.contactPhoneFixe}
                    onChange={(event) => updateField('contactPhoneFixe', event.target.value)}
                  />
                </FormField>
              </div>

              <FormField label="Contact - email" error={fieldErrors.contactEmail}>
                <Input
                  invalid={!!fieldErrors.contactEmail}
                  type="email"
                  value={form.contactEmail}
                  onChange={(event) => updateField('contactEmail', event.target.value)}
                />
              </FormField>

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
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <Card>
            <CardContent className="flex flex-col gap-2 p-5 text-sm">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Identité</p>
              {company.siret ? <p>SIRET : {company.siret}</p> : <p className="text-muted-foreground">SIRET non renseigné</p>}
              {company.address || company.postalCode || company.city ? (
                <p className="flex items-center gap-1.5 text-muted-foreground">
                  <MapPin size={13} className="shrink-0" />
                  {[company.address, [company.postalCode, company.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')}
                </p>
              ) : (
                <p className="text-muted-foreground">Adresse non renseignée</p>
              )}
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex flex-col gap-2 p-5 text-sm">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Contact principal</p>
              {company.contactName ? <p>{company.contactName}</p> : <p className="text-muted-foreground">Non renseigné</p>}
              {company.contactEmail ? (
                <p className="flex items-center gap-1.5 text-muted-foreground">
                  <Mail size={13} />
                  {company.contactEmail}
                </p>
              ) : null}
              {company.contactPhoneMobile ? (
                <p className="flex items-center gap-1.5 text-muted-foreground">
                  <Phone size={13} />
                  {company.contactPhoneMobile} (mobile)
                </p>
              ) : null}
              {company.contactPhoneFixe ? (
                <p className="flex items-center gap-1.5 text-muted-foreground">
                  <Phone size={13} />
                  {company.contactPhoneFixe} (fixe)
                </p>
              ) : null}
            </CardContent>
          </Card>
          <Card>
            <CardContent className="flex flex-col gap-2 p-5 text-sm">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Vue d&rsquo;ensemble</p>
              <p className="flex items-center gap-1.5">
                <User size={13} className="text-primary" />
                {tutors.length} tuteur{tutors.length > 1 ? 's' : ''}
              </p>
              <p className="flex items-center gap-1.5">
                <Users size={13} className="text-primary" />
                {learners.length} apprenant{learners.length > 1 ? 's' : ''}
              </p>
            </CardContent>
          </Card>
        </div>
      )}

      <Card>
        <CardContent className="flex flex-col gap-5 p-6">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <h3 className="font-display text-lg font-bold tracking-tight">Tuteurs ({tutors.length})</h3>
            <Button variant="outline" size="sm" onClick={handleOpenAttachTutor}>
              <UserPlus size={14} />
              Rattacher un tuteur
            </Button>
          </div>
          {tutors.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">Aucun tuteur rattaché.</p>
          ) : (
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {tutors.map((tutor) => (
                <Link
                  key={tutor.id}
                  to={`/tutors/${tutor.id}`}
                  className="flex items-center gap-3 rounded-md border border-border p-3.5 transition hover:border-primary/40 hover:bg-muted/40"
                >
                  <Avatar name={tutor.fullName} className="h-9 w-9 text-xs" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{tutor.fullName}</p>
                    {tutor.email ? <p className="truncate text-xs text-muted-foreground">{tutor.email}</p> : null}
                  </div>
                  <Chip variant="neutral">{tutor.learnerCount}</Chip>
                </Link>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="flex flex-col gap-5 p-6">
          <h3 className="font-display text-lg font-bold tracking-tight">Apprenants rattachés ({learners.length})</h3>
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
            <DialogTitle>Supprimer cette entreprise ?</DialogTitle>
            <DialogDescription>
              Les tuteurs et apprenants rattachés seront simplement détachés, pas supprimés. La fiche entreprise sera
              masquée mais pourra être restaurée par un administrateur si nécessaire.
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

      <Dialog open={attachTutorOpen} onOpenChange={setAttachTutorOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Rattacher un tuteur</DialogTitle>
            <DialogDescription>Recherchez un tuteur à rattacher à {company.name}.</DialogDescription>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-4">
            <div className="relative">
              <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
              <Input
                autoFocus
                placeholder="Rechercher par nom ou email..."
                value={tutorSearch}
                onChange={(event) => setTutorSearch(event.target.value)}
                className="pl-10"
              />
            </div>

            <FormError message={attachTutorMessage} />

            <div className="flex max-h-72 flex-col gap-1.5 overflow-y-auto">
              {tutorSearch.trim().length < 2 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">Tapez au moins 2 caractères pour rechercher</p>
              ) : tutorResults.length === 0 ? (
                <div className="flex flex-col items-center gap-3 py-6 text-center">
                  <p className="text-sm text-muted-foreground">Aucun tuteur trouvé</p>
                  {!showCreateTutor ? (
                    <Button type="button" variant="outline" size="sm" onClick={() => setShowCreateTutor(true)}>
                      <Plus size={14} />
                      Créer un tuteur
                    </Button>
                  ) : null}
                </div>
              ) : (
                tutorResults.map((tutor) => (
                  <button
                    key={tutor.id}
                    type="button"
                    disabled={attachingTutorId !== null}
                    onClick={() => void handleAttachTutor(tutor)}
                    className="flex w-full items-center gap-3 rounded-md p-2.5 text-left transition hover:bg-muted disabled:opacity-60"
                  >
                    <Avatar name={tutor.fullName} className="h-9 w-9 text-xs" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold">{tutor.fullName}</p>
                      {tutor.email ? <p className="truncate text-xs text-muted-foreground">{tutor.email}</p> : null}
                    </div>
                    {attachingTutorId === tutor.id ? <Loader2 size={15} className="animate-spin text-primary" /> : null}
                  </button>
                ))
              )}
            </div>

            {showCreateTutor ? (
              <form
                className="flex flex-col gap-3 rounded-md border border-border p-3.5"
                noValidate
                onSubmit={handleCreateAndAttachTutor}
              >
                <p className="text-sm font-semibold">Nouveau tuteur</p>
                <div className="grid grid-cols-2 gap-3">
                  <FormField label="Prénom" required error={createTutorErrors.firstName}>
                    <Input
                      invalid={!!createTutorErrors.firstName}
                      value={createTutorForm.firstName}
                      onChange={(event) => updateCreateTutorField('firstName', event.target.value)}
                    />
                  </FormField>
                  <FormField label="Nom" required error={createTutorErrors.lastName}>
                    <Input
                      invalid={!!createTutorErrors.lastName}
                      value={createTutorForm.lastName}
                      onChange={(event) => updateCreateTutorField('lastName', event.target.value)}
                    />
                  </FormField>
                </div>
                <FormField label="Email" required error={createTutorErrors.email}>
                  <Input
                    invalid={!!createTutorErrors.email}
                    type="email"
                    value={createTutorForm.email}
                    onChange={(event) => updateCreateTutorField('email', event.target.value)}
                  />
                </FormField>
                <div className="flex gap-2">
                  <Button type="submit" size="sm" disabled={creatingTutor}>
                    {creatingTutor ? <Loader2 size={14} className="animate-spin" /> : <Plus size={14} />}
                    Créer et rattacher
                  </Button>
                  <Button type="button" variant="outline" size="sm" onClick={() => setShowCreateTutor(false)}>
                    Annuler
                  </Button>
                </div>
              </form>
            ) : null}
          </DialogBody>
        </DialogContent>
      </Dialog>
    </div>
  );
}
