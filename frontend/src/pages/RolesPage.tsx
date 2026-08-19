import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import type { Feature, Role } from '../types/auth';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Badge } from '@/components/ui/badge';
import { CountUp } from '@/components/ui/stat';
import { FormError } from '@/components/ui/form-error';
import { FormSuccess } from '@/components/ui/form-success';

export function RolesPage() {
  const { token } = useAuth();
  const [roles, setRoles] = useState<Role[]>([]);
  const [features, setFeatures] = useState<Feature[]>([]);
  const [formError, setFormError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [form, setForm] = useState({
    code: '',
    name: '',
    description: '',
    featureCodes: [] as string[],
  });

  const load = async () => {
    if (!token) {
      return;
    }

    const [rolesPayload, featuresPayload] = await Promise.all([
      apiRequest<Role[]>('/api/admin/roles', { token }),
      apiRequest<Feature[]>('/api/admin/features', { token }),
    ]);

    setRoles(rolesPayload);
    setFeatures(featuresPayload);
  };

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadRoles = async () => {
      const [rolesPayload, featuresPayload] = await Promise.all([
        apiRequest<Role[]>('/api/admin/roles', { token }),
        apiRequest<Feature[]>('/api/admin/features', { token }),
      ]);

      if (cancelled) {
        return;
      }

      setRoles(rolesPayload);
      setFeatures(featuresPayload);
    };

    void loadRoles();

    return () => {
      cancelled = true;
    };
  }, [token]);

  const featureGroups = useMemo(() => {
    return features.reduce<Record<string, Feature[]>>((groups, feature) => {
      const key = feature.category || 'Autres';
      groups[key] = [...(groups[key] ?? []), feature];
      return groups;
    }, {});
  }, [features]);

  const handleToggleFeature = (code: string) => {
    setForm((current) => ({
      ...current,
      featureCodes: current.featureCodes.includes(code)
        ? current.featureCodes.filter((featureCode) => featureCode !== code)
        : [...current.featureCodes, code],
    }));
  };

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!token) {
      return;
    }

    setFormError(null);
    setSuccessMessage(null);

    try {
      await apiRequest<Role>('/api/admin/roles', {
        method: 'POST',
        token,
        body: form,
      });

      setForm({ code: '', name: '', description: '', featureCodes: [] });
      setSuccessMessage('Rôle créé.');
      await load();
    } catch (caught) {
      setFormError(caught instanceof ApiError ? caught.message : 'Création impossible.');
    }
  };

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Administration</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Rôles et permissions</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            Modèle d&rsquo;accès fin par feature pour piloter les écrans visibles selon les profils.
          </p>
          <Chip variant="neutral">{roles.length} rôles</Chip>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover">
          <CardContent className="flex flex-col gap-1.5 p-5">
            <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Rôles système</p>
            <CountUp value={roles.filter((role) => role.system).length} className="text-xl" />
            <p className="text-xs text-muted-foreground">Profils de base livrés avec l&rsquo;application</p>
          </CardContent>
        </Card>
        <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: '80ms' }}>
          <CardContent className="flex flex-col gap-1.5 p-5">
            <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Rôles personnalisés</p>
            <CountUp value={roles.filter((role) => !role.system).length} className="text-xl" />
            <p className="text-xs text-muted-foreground">Créés pour des usages métier spécifiques</p>
          </CardContent>
        </Card>
        <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: '160ms' }}>
          <CardContent className="flex flex-col gap-1.5 p-5">
            <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Features disponibles</p>
            <CountUp value={features.length} className="text-xl" />
            <p className="text-xs text-muted-foreground">Écrans et capacités attribuables</p>
          </CardContent>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1.1fr_0.9fr]">
        <div className="flex flex-col gap-6">
          <Card className="overflow-hidden border-0 bg-gradient-brand text-white">
            <CardContent className="flex flex-col gap-3 p-7">
              <Chip variant="onGradient" className="w-fit">Feature access</Chip>
              <h3 className="font-display text-xl font-extrabold leading-tight tracking-tight">
                Composer des profils lisibles au lieu d&rsquo;ouvrir tout le back-office.
              </h3>
              <p className="max-w-[55ch] text-sm text-white/65">
                Un rôle rassemble uniquement les modules nécessaires. L&rsquo;objectif est d&rsquo;exposer moins
                d&rsquo;onglets, avec plus de clarté pour chaque équipe.
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Bibliothèque</p>
                <h3 className="font-display text-lg font-bold tracking-tight">Rôles existants</h3>
              </div>

              <div className="flex flex-col gap-4">
                {roles.map((role) => (
                  <div className="rounded-md border border-border p-4" key={role.id}>
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <strong className="text-sm font-semibold">{role.name}</strong>
                        <p className="text-xs text-muted-foreground">{role.code}</p>
                      </div>
                      {role.system ? <Chip variant="neutral">Système</Chip> : <Chip variant="primary">Custom</Chip>}
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">{role.description ?? 'Aucune description.'}</p>
                    <div className="mt-3 flex flex-wrap gap-1.5">
                      {role.featureCodes.map((featureCode) => (
                        <Badge variant="secondary" key={featureCode}>
                          {featureCode}
                        </Badge>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </div>

        <Card>
          <CardContent className="p-6">
            <div className="mb-5">
              <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Création</p>
              <h3 className="font-display text-lg font-bold tracking-tight">Nouveau rôle</h3>
            </div>

            <form className="flex flex-col gap-5" noValidate onSubmit={handleSubmit}>
              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Code</span>
                <Input
                  placeholder="ex: coach"
                  value={form.code}
                  onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))}
                />
              </label>

              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Nom</span>
                <Input
                  placeholder="Coach pédagogique"
                  value={form.name}
                  onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                />
              </label>

              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Description</span>
                <Textarea
                  placeholder="Accès limité à certaines vues."
                  value={form.description}
                  onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
                />
              </label>

              <div className="flex flex-col gap-3">
                <span className="text-sm font-semibold">Features</span>
                <div className="flex max-h-72 flex-col gap-3 overflow-y-auto pr-1">
                  {Object.entries(featureGroups).map(([category, categoryFeatures]) => (
                    <div className="rounded-md border border-border bg-muted/30 p-3.5" key={category}>
                      <p className="mb-2.5 text-[0.7rem] font-bold uppercase tracking-wide text-muted-foreground">
                        {category}
                      </p>
                      <div className="flex flex-col gap-1.5">
                        {categoryFeatures.map((feature) => (
                          <label
                            className="flex items-start gap-2.5 rounded-md bg-card px-2.5 py-2 text-sm"
                            key={feature.code}
                          >
                            <input
                              checked={form.featureCodes.includes(feature.code)}
                              onChange={() => handleToggleFeature(feature.code)}
                              type="checkbox"
                              className="mt-0.5 accent-primary"
                            />
                            <span className="flex flex-col">
                              <strong className="font-medium">{feature.name}</strong>
                              <small className="text-xs text-muted-foreground">{feature.code}</small>
                            </span>
                          </label>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <FormError message={formError} />
              <FormSuccess message={successMessage} />

              <Button type="submit">Créer le rôle</Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}
