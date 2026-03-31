import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { apiRequest, ApiError } from '../lib/api';
import type { Feature, Role } from '../types/auth';

export function RolesPage() {
  const { token } = useAuth();
  const [roles, setRoles] = useState<Role[]>([]);
  const [features, setFeatures] = useState<Feature[]>([]);
  const [message, setMessage] = useState<string | null>(null);
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
    void load();
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

    setMessage(null);

    try {
      await apiRequest<Role>('/api/admin/roles', {
        method: 'POST',
        token,
        body: form,
      });

      setForm({ code: '', name: '', description: '', featureCodes: [] });
      setMessage('Rôle créé.');
      await load();
    } catch (caught) {
      setMessage(caught instanceof ApiError ? caught.message : 'Création impossible.');
    }
  };

  return (
    <section className="page-section">
      <div className="page-header">
        <div>
          <p className="eyebrow eyebrow-dark">Administration</p>
          <h2>Rôles et permissions</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Modèle d’accès fin par feature pour piloter les écrans visibles selon les profils.</p>
          <span className="status-chip status-chip-soft">{roles.length} rôles</span>
        </div>
      </div>

      <div className="stats-grid stats-grid-compact">
        <article className="metric-card">
          <p className="metric-label">Rôles système</p>
          <strong className="metric-value">{roles.filter((role) => role.system).length}</strong>
          <p className="metric-hint">Profils de base livrés avec l’application</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">Rôles personnalisés</p>
          <strong className="metric-value">{roles.filter((role) => !role.system).length}</strong>
          <p className="metric-hint">Créés pour des usages métier spécifiques</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">Features disponibles</p>
          <strong className="metric-value">{features.length}</strong>
          <p className="metric-hint">Écrans et capacités attribuables</p>
        </article>
      </div>

      <div className="admin-page-grid">
        <div className="surface-stack">
          <article className="hero-panel hero-panel-compact">
            <div className="hero-copy">
              <span className="status-chip">Feature access</span>
              <h3>Composer des profils lisibles au lieu d’ouvrir tout le back-office.</h3>
              <p>
                Un rôle rassemble uniquement les modules nécessaires. L’objectif est d’exposer moins d’onglets, avec
                plus de clarté pour chaque équipe.
              </p>
            </div>
          </article>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Bibliothèque</p>
                <h3>Rôles existants</h3>
              </div>
            </div>

            <div className="stack">
              {roles.map((role) => (
                <article className="panel-card panel-card-nested" key={role.id}>
                  <div className="panel-headline">
                    <div>
                      <strong>{role.name}</strong>
                      <p className="muted">{role.code}</p>
                    </div>
                    {role.system ? <span className="status-pill">Système</span> : <span className="status-chip status-chip-soft">Custom</span>}
                  </div>
                  <p className="muted">{role.description ?? 'Aucune description.'}</p>
                  <div className="tag-list">
                    {role.featureCodes.map((featureCode) => (
                      <span className="tag" key={featureCode}>
                        {featureCode}
                      </span>
                    ))}
                  </div>
                </article>
              ))}
            </div>
          </article>
        </div>

        <article className="panel-card">
          <div className="panel-card-header">
            <div>
              <p className="section-kicker">Création</p>
              <h3>Nouveau rôle</h3>
            </div>
          </div>

          <form className="stack" onSubmit={handleSubmit}>
            <label className="field">
              <span>Code</span>
              <input
                placeholder="ex: coach"
                value={form.code}
                onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))}
              />
            </label>

            <label className="field">
              <span>Nom</span>
              <input
                placeholder="Coach pédagogique"
                value={form.name}
                onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
              />
            </label>

            <label className="field">
              <span>Description</span>
              <textarea
                placeholder="Accès limité à certaines vues."
                value={form.description}
                onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
              />
            </label>

            <div className="stack-sm">
              <span className="field-label">Features</span>
              <div className="role-feature-groups">
                {Object.entries(featureGroups).map(([category, categoryFeatures]) => (
                  <div className="feature-group-card" key={category}>
                    <p className="feature-group-title">{category}</p>
                    <div className="checkbox-grid">
                      {categoryFeatures.map((feature) => (
                        <label className="checkbox-row" key={feature.code}>
                          <input
                            checked={form.featureCodes.includes(feature.code)}
                            onChange={() => handleToggleFeature(feature.code)}
                            type="checkbox"
                          />
                          <span>
                            <strong>{feature.name}</strong>
                            <small>{feature.code}</small>
                          </span>
                        </label>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            </div>

            {message ? <p className="form-note">{message}</p> : null}

            <button className="primary-button" type="submit">
              Créer le rôle
            </button>
          </form>
        </article>
      </div>
    </section>
  );
}
