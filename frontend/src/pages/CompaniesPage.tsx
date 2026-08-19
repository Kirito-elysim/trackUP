import { useDeferredValue, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  AlertCircle,
  Building2,
  CheckCircle,
  ChevronDown,
  FileUp,
  Loader2,
  Plus,
  Search,
  SlidersHorizontal,
  Trash2,
  Upload,
  XCircle,
} from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, apiUrl, ApiError } from '../lib/api';
import type { CompaniesIndexResponse, Company, Pagination, Sector } from '../types/trackup';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogBody } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow, TableShell } from '@/components/ui/table';
import { CreatableSelect, type CreatableOption } from '@/components/ui/creatable-select';
import { PaginationBar } from '@/components/ui/pagination-bar';
import { FormError } from '@/components/ui/form-error';
import { FormField } from '@/components/ui/form-field';
import { validateCompanyForm, mapCompanyServerError } from '@/lib/companyValidation';
import { cn } from '@/lib/utils';

const EMPTY_FORM = {
  name: '',
  siret: '',
  address: '',
  postalCode: '',
  city: '',
  sector: null as CreatableOption | null,
  contactName: '',
  contactEmail: '',
  contactPhoneMobile: '',
  contactPhoneFixe: '',
};

const EMPTY_FILTERS = {
  name: '',
  siret: '',
  city: '',
  postalCode: '',
  contactEmail: '',
  contactPhone: '',
  sectorId: '',
};

export function CompaniesPage() {
  const { token } = useAuth();
  const navigate = useNavigate();
  const [companies, setCompanies] = useState<Company[]>([]);
  const [sectors, setSectors] = useState<Sector[]>([]);
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

  const [importOpen, setImportOpen] = useState(false);
  const [importFile, setImportFile] = useState<File | null>(null);
  const [importStatus, setImportStatus] = useState<{ status: 'idle' | 'loading' | 'success' | 'error'; message?: string }>({
    status: 'idle',
  });

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

    const payload = await apiRequest<CompaniesIndexResponse>(`/api/admin/companies?${params.toString()}`, { token });

    setCompanies(payload.companies);
    setSectors(payload.sectors);
    setPagination(payload.pagination);
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token, deferredSearch, deferredFilters, page, pageSize]);

  useEffect(() => {
    setPage(1);
  }, [deferredSearch, deferredFilters, pageSize]);

  const handleCreate = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!token) {
      return;
    }

    setFormMessage(null);

    const errors = validateCompanyForm(form);
    setFieldErrors(errors);
    if (Object.keys(errors).length > 0) {
      return;
    }

    setSubmitting(true);

    try {
      const created = await apiRequest<Company>('/api/admin/companies', {
        method: 'POST',
        token,
        body: { ...form, sectorId: form.sector?.id ?? null },
      });
      setForm(EMPTY_FORM);
      setCreateOpen(false);
      navigate(`/companies/${created.id}`);
    } catch (caught) {
      const message = caught instanceof ApiError ? caught.message : 'Création impossible.';
      const field = mapCompanyServerError(message);
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
      await apiRequest(`/api/admin/companies/${pendingDeleteId}`, { method: 'DELETE', token });
      setPendingDeleteId(null);
      await load();
    } catch {
      setPendingDeleteId(null);
    }
  };

  const handleImport = async () => {
    if (!importFile || !token) {
      return;
    }

    setImportStatus({ status: 'loading' });

    const formData = new FormData();
    formData.append('file', importFile);

    try {
      const response = await fetch(apiUrl('/api/admin/companies/import'), {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}` },
        body: formData,
      });

      const data = await response.json();

      if (!response.ok || data.success === false) {
        throw new ApiError(data.message || 'Import impossible.', response.status);
      }

      setImportStatus({ status: 'success', message: data.message });
      setImportFile(null);
      await load();
    } catch (caught) {
      setImportStatus({
        status: 'error',
        message: caught instanceof ApiError ? caught.message : 'Erreur lors de l’import.',
      });
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

  return (
    <section className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Alternance</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Entreprises</h2>
        </div>
        <div className="flex items-center gap-3">
          <Chip variant="neutral">{pagination?.totalRows ?? companies.length} entreprises</Chip>
          <Button variant="outline" size="sm" onClick={() => setImportOpen(true)}>
            <FileUp size={15} />
            Importer
          </Button>
          <Button
            size="sm"
            onClick={() => {
              setForm(EMPTY_FORM);
              setFieldErrors({});
              setFormMessage(null);
              setCreateOpen(true);
            }}
          >
            <Plus size={15} />
            Ajouter une entreprise
          </Button>
        </div>
      </div>

      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap gap-3">
          <div className="relative min-w-[220px] flex-1">
            <Search size={16} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Rechercher par nom ou SIRET..."
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
                <span className="text-xs font-semibold text-muted-foreground">Nom</span>
                <Input value={filters.name} onChange={(event) => setFilters((current) => ({ ...current, name: event.target.value }))} />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">SIRET</span>
                <Input value={filters.siret} onChange={(event) => setFilters((current) => ({ ...current, siret: event.target.value }))} />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Ville</span>
                <Input value={filters.city} onChange={(event) => setFilters((current) => ({ ...current, city: event.target.value }))} />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Code postal</span>
                <Input
                  value={filters.postalCode}
                  onChange={(event) => setFilters((current) => ({ ...current, postalCode: event.target.value }))}
                />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Email</span>
                <Input
                  value={filters.contactEmail}
                  onChange={(event) => setFilters((current) => ({ ...current, contactEmail: event.target.value }))}
                />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Téléphone</span>
                <Input
                  value={filters.contactPhone}
                  onChange={(event) => setFilters((current) => ({ ...current, contactPhone: event.target.value }))}
                />
              </label>
              <label className="flex flex-col gap-1.5">
                <span className="text-xs font-semibold text-muted-foreground">Secteur</span>
                <select
                  value={filters.sectorId}
                  onChange={(event) => setFilters((current) => ({ ...current, sectorId: event.target.value }))}
                  className="h-10 rounded-md border border-border bg-background px-3 text-sm"
                >
                  <option value="">Tous</option>
                  {sectors.map((sector) => (
                    <option key={sector.id} value={sector.id}>
                      {sector.name}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </Card>
        ) : null}
      </div>

      <Card className="overflow-hidden p-0">
        <TableShell>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Entreprise</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Numéro</TableHead>
                <TableHead>Tuteurs</TableHead>
                <TableHead>Apprenants</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {companies.map((company) => (
                <TableRow
                  key={company.id}
                  className="cursor-pointer"
                  onClick={() => navigate(`/companies/${company.id}`)}
                >
                  <TableCell>
                    <div className="flex items-center gap-2.5">
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-gradient-brand text-white">
                        <Building2 size={15} />
                      </span>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold">{company.name}</p>
                        {company.city ? <p className="truncate text-xs text-muted-foreground">{company.city}</p> : null}
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="text-muted-foreground">{company.contactEmail ?? '—'}</TableCell>
                  <TableCell className="text-muted-foreground">
                    {company.contactPhoneMobile ?? company.contactPhoneFixe ?? '—'}
                  </TableCell>
                  <TableCell>{company.tutorCount}</TableCell>
                  <TableCell>{company.learnerCount}</TableCell>
                  <TableCell className="text-right">
                    <Button
                      variant="outline"
                      size="icon"
                      className="h-8 w-8 hover:border-destructive/40 hover:bg-destructive/5 hover:text-destructive"
                      onClick={(event) => {
                        event.stopPropagation();
                        setPendingDeleteId(company.id);
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
        {companies.length === 0 ? (
          <p className="py-12 text-center text-sm text-muted-foreground">Aucune entreprise trouvée.</p>
        ) : null}
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
            <DialogTitle>Nouvelle entreprise</DialogTitle>
            <DialogDescription>Renseignez les informations de l&rsquo;entreprise.</DialogDescription>
          </DialogHeader>
          <DialogBody>
            <form className="flex flex-col gap-4" noValidate onSubmit={handleCreate}>
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
                <Button type="submit" disabled={submitting}>
                  {submitting ? <Loader2 size={15} className="animate-spin" /> : null}
                  Créer l&rsquo;entreprise
                </Button>
                <Button type="button" variant="outline" onClick={() => setCreateOpen(false)}>
                  Annuler
                </Button>
              </div>
            </form>
          </DialogBody>
        </DialogContent>
      </Dialog>

      <Dialog
        open={importOpen}
        onOpenChange={(open) => {
          setImportOpen(open);
          if (!open) {
            setImportFile(null);
            setImportStatus({ status: 'idle' });
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Import initial (CSV/XLSX)</DialogTitle>
            <DialogDescription>
              Chaque entreprise et tuteur est créé ou mis à jour, et les apprenants correspondants (par email) sont
              automatiquement rattachés. Ré-importer le même fichier est sans risque : aucun doublon n&rsquo;est créé.
            </DialogDescription>
          </DialogHeader>
          <DialogBody className="flex flex-col gap-4">
            <div className="relative">
              <input
                type="file"
                id="companies-import-file"
                accept=".csv,.xlsx"
                onChange={(event) => setImportFile(event.target.files?.[0] || null)}
                className="absolute h-px w-px overflow-hidden opacity-0"
              />
              <label
                htmlFor="companies-import-file"
                className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-border p-8 text-center transition hover:border-primary/40 hover:bg-muted/30"
              >
                <FileUp size={28} className="text-primary" />
                <p className="text-sm font-semibold">{importFile ? importFile.name : 'Choisir un fichier CSV ou XLSX'}</p>
                <p className="text-xs text-muted-foreground">
                  {importFile ? `Taille : ${(importFile.size / 1024).toFixed(2)} Ko` : 'Cliquez pour sélectionner ou glissez-déposez'}
                </p>
              </label>
            </div>

            {importFile ? (
              <Button onClick={() => void handleImport()} disabled={importStatus.status === 'loading'} className="w-fit">
                {importStatus.status === 'loading' ? <Loader2 size={15} className="animate-spin" /> : <Upload size={15} />}
                {importStatus.status === 'loading' ? 'Import en cours...' : 'Importer le fichier'}
              </Button>
            ) : null}

            {importStatus.message ? (
              <div
                className={cn(
                  'flex items-start gap-2 rounded-md border px-3 py-2.5 text-xs font-medium',
                  importStatus.status === 'success' && 'border-success/25 bg-success/10 text-success',
                  importStatus.status === 'error' && 'border-destructive/25 bg-destructive/10 text-destructive',
                )}
              >
                {importStatus.status === 'success' ? <CheckCircle size={14} className="mt-0.5 shrink-0" /> : null}
                {importStatus.status === 'error' ? <XCircle size={14} className="mt-0.5 shrink-0" /> : null}
                <span>{importStatus.message}</span>
              </div>
            ) : null}
          </DialogBody>
        </DialogContent>
      </Dialog>

      <Dialog open={pendingDeleteId !== null} onOpenChange={(open) => !open && setPendingDeleteId(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Supprimer cette entreprise ?</DialogTitle>
            <DialogDescription>
              Les tuteurs et apprenants rattachés seront simplement détachés, pas supprimés. La fiche entreprise sera
              masquée mais pourra être restaurée par un administrateur si nécessaire.
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
