import { useDeferredValue, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { AlertCircle, ChevronDown, Loader2, Plus, Search, SlidersHorizontal, Trash2 } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import type { Company, Pagination, Tutor, TutorsIndexResponse } from '../types/trackup';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Avatar } from '@/components/ui/avatar';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogBody } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { SearchSelect } from '@/components/ui/search-select';
import { PaginationBar } from '@/components/ui/pagination-bar';
import { FormError } from '@/components/ui/form-error';
import { FormField } from '@/components/ui/form-field';
import { validateTutorForm, mapTutorServerError } from '@/lib/tutorValidation';
import { cn } from '@/lib/utils';

const EMPTY_FORM = {
  firstName: '',
  lastName: '',
  email: '',
  phoneMobile: '',
  phoneFixe: '',
  address: '',
  postalCode: '',
  city: '',
  dateOfBirth: '',
  companyIds: [] as number[],
};

const EMPTY_FILTERS = {
  email: '',
  city: '',
  phone: '',
  companyId: '',
};

export function TutorsPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [tutors, setTutors] = useState<Tutor[]>([]);
  const [companies, setCompanies] = useState<Company[]>([]);
  const [pagination, setPagination] = useState<Pagination | null>(null);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [search, setSearch] = useState('');
  const deferredSearch = useDeferredValue(search);

  const [filtersOpen, setFiltersOpen] = useState(false);
  const [filters, setFilters] = useState(EMPTY_FILTERS);
  const deferredFilters = useDeferredValue(filters);

  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [addCompanyValue, setAddCompanyValue] = useState('');
  const [formMessage, setFormMessage] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const updateField = <K extends keyof typeof EMPTY_FORM>(key: K, value: (typeof EMPTY_FORM)[K]) => {
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

  const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);

  const activeFilterCount = Object.values(filters).filter((value) => value !== '').length;

  const load = async () => {
    if (!token) {
      return;
    }

    const params = new URLSearchParams();
    params.set('page', String(page));
    params.set('pageSize', String(pageSize));
    if (deferredSearch.trim() !== '') {
      params.set('q', deferredSearch.trim());
    }
    for (const [key, value] of Object.entries(deferredFilters)) {
      if (value !== '') {
        params.set(key, value);
      }
    }

    const [tutorsPayload, companiesPayload] = await Promise.all([
      apiRequest<TutorsIndexResponse>(`/api/admin/tutors?${params.toString()}`, { token }),
      apiRequest<{ companies: Company[] }>('/api/admin/companies?pageSize=100', { token }),
    ]);

    setTutors(tutorsPayload.tutors);
    setPagination(tutorsPayload.pagination);
    setCompanies(companiesPayload.companies);
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, deferredSearch, deferredFilters, page, pageSize]);

  useEffect(() => {
    setPage(1);
  }, [deferredSearch, deferredFilters, pageSize]);

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
    setForm((current) => ({ ...current, companyIds: current.companyIds.filter((id) => id !== companyId) }));
  };

  const handleCreate = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!token) {
      return;
    }

    setFormMessage(null);

    const errors = validateTutorForm(form);
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      return;
    }

    setSubmitting(true);

    try {
      const created = await apiRequest<Tutor>('/api/admin/tutors', { method: 'POST', token, body: form });
      setForm(EMPTY_FORM);
      setCreateOpen(false);
      navigate(`/tutors/${created.id}`);
    } catch (caught) {
      const message = caught instanceof ApiError ? caught.message : 'Création impossible.';
      const field = mapTutorServerError(message);
      if (field) {
        setFieldErrors({ [field]: message });
      } else {
        setFormMessage(message);
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async () => {
    if (!token || pendingDeleteId === null) {
      return;
    }

    try {
      await apiRequest(`/api/admin/tutors/${pendingDeleteId}`, { method: 'DELETE', token });
      setPendingDeleteId(null);
      await load();
    } catch {
      setPendingDeleteId(null);
    }
  };

  return (
    <section className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Alternance</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Tuteurs</h2>
        </div>
        <div className="flex items-center gap-3">
          <Chip variant="neutral">{pagination?.totalRows ?? tutors.length} tuteurs</Chip>
          <Button
            size="sm"
            onClick={() => {
              setForm(EMPTY_FORM);
              setFieldErrors({});
              setAddCompanyValue('');
              setFormMessage(null);
              setCreateOpen(true);
            }}
          >
            <Plus size={15} />
            Ajouter un tuteur
          </Button>
        </div>
      </div>

      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-3">
          <div className="relative min-w-[220px] flex-1">
            <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Rechercher par nom ou email..."
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              className="pl-10"
            />
          </div>
          <Button variant="outline" onClick={() => setFiltersOpen((current) => !current)} className="gap-2">
            <SlidersHorizontal size={15} />
            Filtres avancés
            {activeFilterCount > 0 ? <Chip variant="info">{activeFilterCount}</Chip> : null}
            <ChevronDown size={14} className={cn('transition-transform', filtersOpen && 'rotate-180')} />
          </Button>
          {activeFilterCount > 0 ? (
            <Button variant="ghost" onClick={() => setFilters(EMPTY_FILTERS)}>
              Réinitialiser
            </Button>
          ) : null}
        </div>

        {filtersOpen ? (
          <Card className="p-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Email</span>
                <Input value={filters.email} onChange={(event) => setFilters((current) => ({ ...current, email: event.target.value }))} />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Téléphone</span>
                <Input value={filters.phone} onChange={(event) => setFilters((current) => ({ ...current, phone: event.target.value }))} />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Ville</span>
                <Input value={filters.city} onChange={(event) => setFilters((current) => ({ ...current, city: event.target.value }))} />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Entreprise</span>
                <select
                  value={filters.companyId}
                  onChange={(event) => setFilters((current) => ({ ...current, companyId: event.target.value }))}
                  className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                >
                  <option value="">Toutes</option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>
                      {company.name}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </Card>
        ) : null}
      </div>

      <Card className="overflow-hidden">
        <TableShell>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Tuteur</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Téléphone</TableHead>
                <TableHead>Entreprises</TableHead>
                <TableHead>Apprenants</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {tutors.map((tutor) => (
                <TableRow key={tutor.id} className="cursor-pointer" onClick={() => navigate(`/tutors/${tutor.id}`)}>
                  <TableCell>
                    <div className="flex items-center gap-2.5">
                      <Avatar name={tutor.fullName} className="h-9 w-9 text-xs" />
                      <p className="text-sm font-semibold">{tutor.fullName}</p>
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{tutor.email ?? '—'}</TableCell>
                  <TableCell className="text-muted-foreground">{tutor.phoneMobile ?? tutor.phoneFixe ?? '—'}</TableCell>
                  <TableCell>
                    <div className="flex flex-wrap gap-1.5">
                      {tutor.companies.length === 0 ? (
                        <span className="text-muted-foreground">—</span>
                      ) : (
                        tutor.companies.map((company) => (
                          <Chip variant="info" key={company.id}>
                            {company.name}
                          </Chip>
                        ))
                      )}
                    </div>
                  </TableCell>
                  <TableCell>{tutor.learnerCount}</TableCell>
                  <TableCell className="text-right">
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8 hover:border-destructive/40 hover:bg-destructive/5 hover:text-destructive"
                      onClick={(event) => {
                        event.stopPropagation();
                        setPendingDeleteId(tutor.id);
                      }}
                      aria-label="Supprimer"
                    >
                      <Trash2 size={14} />
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableShell>
        {tutors.length === 0 ? <p className="py-12 text-center text-sm text-muted-foreground">Aucun tuteur trouvé.</p> : null}
      </Card>

      {pagination && pagination.totalRows > 0 ? (
        <PaginationBar
          pagination={pagination}
          page={page}
          pageSize={pageSize}
          onPageChange={setPage}
          onPageSizeChange={setPageSize}
        />
      ) : null}

      <Dialog open={createOpen} onOpenChange={setCreateOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Nouveau tuteur</DialogTitle>
            <DialogDescription>Renseignez les informations du tuteur.</DialogDescription>
          </DialogHeader>
          <DialogBody>
            <form className="flex flex-col gap-4" noValidate onSubmit={handleCreate}>
              <div className="grid grid-cols-2 gap-4">
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

              <div className="grid grid-cols-2 gap-4">
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

              <div className="grid grid-cols-2 gap-4">
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

              <div className="grid grid-cols-2 gap-4">
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
                  options={companies
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
                      const company = companies.find((candidate) => candidate.id === companyId);
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
                <Button type="submit" disabled={submitting}>
                  {submitting ? <Loader2 size={15} className="animate-spin" /> : null}
                  Créer le tuteur
                </Button>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                  Annuler
                </Button>
              </div>
            </form>
          </DialogBody>
        </DialogContent>
      </Dialog>

      <Dialog open={pendingDeleteId !== null} onOpenChange={(open) => !open && setPendingDeleteId(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Supprimer ce tuteur ?</DialogTitle>
            <DialogDescription>
              Les apprenants rattachés seront simplement détachés, pas supprimés. La fiche tuteur sera masquée mais
              pourra être restaurée par un administrateur si nécessaire.
            </DialogDescription>
          </DialogHeader>
          <DialogBody className="flex flex-row justify-end gap-3">
            <Button variant="outline" onClick={() => setPendingDeleteId(null)}>
              Annuler
            </Button>
            <Button variant="destructive" onClick={() => void handleDelete()}>
              <AlertCircle size={15} />
              Supprimer
            </Button>
          </DialogBody>
        </DialogContent>
      </Dialog>
    </section>
  );
}
