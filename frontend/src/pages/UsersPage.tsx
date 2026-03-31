import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import type { Role, UserSummary } from '../types/auth';

export function UsersPage() {
  const { token } = useAuth();
  const [users, setUsers] = useState<UserSummary[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [message, setMessage] = useState<string | null>(null);
  const [form, setForm] = useState({
    firstName: '',
    lastName: '',
    email: '',
    password: '',
    active: true,
    roleIds: [] as number[],
  });

  const load = async () => {
    if (!token) {
      return;
    }

    const [usersPayload, rolesPayload] = await Promise.all([
      apiRequest<UserSummary[]>('/api/admin/users', { token }),
      apiRequest<Role[]>('/api/admin/roles', { token }),
    ]);

    setUsers(usersPayload);
    setRoles(rolesPayload);
  };

  useEffect(() => {
    if (!token) {
      return;
    }

    let cancelled = false;

    const loadUsers = async () => {
      const [usersPayload, rolesPayload] = await Promise.all([
        apiRequest<UserSummary[]>('/api/admin/users', { token }),
        apiRequest<Role[]>('/api/admin/roles', { token }),
      ]);

      if (cancelled) {
        return;
      }

      setUsers(usersPayload);
      setRoles(rolesPayload);
    };

    void loadUsers();

    return () => {
      cancelled = true;
    };
  }, [token]);

  const activeUsersCount = useMemo(() => users.filter((user) => user.active).length, [users]);
  const adminUsersCount = useMemo(() => users.filter((user) => user.roles.some((role) => role.code === 'ADMIN')).length, [users]);

  const handleToggleRole = (roleId: number) => {
    setForm((current) => ({
      ...current,
      roleIds: current.roleIds.includes(roleId)
        ? current.roleIds.filter((currentRoleId) => currentRoleId !== roleId)
        : [...current.roleIds, roleId],
    }));
  };

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!token) {
      return;
    }

    setMessage(null);

    if (form.password.trim() === '') {
      setMessage('Le mot de passe est obligatoire.');
      return;
    }

    try {
      await apiRequest<UserSummary>('/api/admin/users', {
        method: 'POST',
        token,
        body: form,
      });

      setForm({
        firstName: '',
        lastName: '',
        email: '',
        password: '',
        active: true,
        roleIds: [],
      });
      setMessage('Utilisateur créé.');
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
          <h2>Utilisateurs</h2>
        </div>
        <div className="header-meta">
          <p className="muted">Gestion des comptes internes et de l’attribution des rôles applicatifs.</p>
          <span className="status-chip status-chip-soft">{users.length} comptes</span>
        </div>
      </div>

      <div className="stats-grid stats-grid-compact">
        <article className="metric-card">
          <p className="metric-label">Comptes actifs</p>
          <strong className="metric-value">{activeUsersCount}</strong>
          <p className="metric-hint">Utilisateurs immédiatement opérationnels</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">Admins</p>
          <strong className="metric-value">{adminUsersCount}</strong>
          <p className="metric-hint">Accès complet aux features système</p>
        </article>
        <article className="metric-card">
          <p className="metric-label">Rôles disponibles</p>
          <strong className="metric-value">{roles.length}</strong>
          <p className="metric-hint">Profils réutilisables pour les équipes</p>
        </article>
      </div>

      <div className="admin-page-grid">
        <div className="surface-stack">
          <article className="hero-panel hero-panel-compact">
            <div className="hero-copy">
              <span className="status-chip">RBAC</span>
              <h3>Accorder seulement les écrans utiles.</h3>
              <p>
                Les utilisateurs n’affichent que les onglets autorisés par leurs rôles. L’objectif est de garder un
                back-office lisible et sûr, sans surface inutile.
              </p>
            </div>
          </article>

          <article className="panel-card">
            <div className="panel-card-header">
              <div>
                <p className="section-kicker">Annuaire</p>
                <h3>Comptes internes</h3>
              </div>
            </div>

            <div className="stack">
              {users.map((user) => (
                <article className="panel-card panel-card-nested" key={user.id}>
                  <div className="panel-headline">
                    <div>
                      <strong>
                        {user.firstName} {user.lastName}
                      </strong>
                      <p className="muted">{user.email}</p>
                    </div>
                    <span className={`status-pill ${user.active ? 'status-pill-active' : 'status-pill-off'}`}>
                      {user.active ? 'Actif' : 'Inactif'}
                    </span>
                  </div>
                  <div className="tag-list">
                    {user.roles.map((role) => (
                      <span className="tag" key={role.id}>
                        {role.name}
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
              <p className="section-kicker">Provisioning</p>
              <h3>Nouvel utilisateur</h3>
            </div>
          </div>

          <form className="stack" onSubmit={handleSubmit}>
            <div className="inline-fields">
              <label className="field">
                <span>Prénom</span>
                <input
                  value={form.firstName}
                  onChange={(event) => setForm((current) => ({ ...current, firstName: event.target.value }))}
                />
              </label>

              <label className="field">
                <span>Nom</span>
                <input
                  value={form.lastName}
                  onChange={(event) => setForm((current) => ({ ...current, lastName: event.target.value }))}
                />
              </label>
            </div>

            <label className="field">
              <span>Email</span>
              <input
                type="email"
                value={form.email}
                onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))}
              />
            </label>

            <label className="field">
              <span>Mot de passe</span>
              <input
                required
                type="password"
                value={form.password}
                onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))}
              />
            </label>

            <label className="switch-row">
              <input
                checked={form.active}
                onChange={(event) => setForm((current) => ({ ...current, active: event.target.checked }))}
                type="checkbox"
              />
              <span>Compte actif dès la création</span>
            </label>

            <div className="stack-sm">
              <span className="field-label">Rôles</span>
              <div className="checkbox-grid">
                {roles.map((role) => (
                  <label className="checkbox-row" key={role.id}>
                    <input
                      checked={form.roleIds.includes(role.id)}
                      onChange={() => handleToggleRole(role.id)}
                      type="checkbox"
                    />
                    <span>
                      <strong>{role.name}</strong>
                      <small>{role.code}</small>
                    </span>
                  </label>
                ))}
              </div>
            </div>

            {message ? <p className="form-note">{message}</p> : null}

            <button className="primary-button" type="submit">
              Créer l’utilisateur
            </button>
          </form>
        </article>
      </div>
    </section>
  );
}
