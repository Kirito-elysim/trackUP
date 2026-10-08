import { useEffect, useMemo, useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import type { Role, UserSummary } from '../types/auth';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Badge } from '@/components/ui/badge';
import { CountUp } from '@/components/ui/stat';
import { FormError } from '@/components/ui/form-error';
import { FormSuccess } from '@/components/ui/form-success';
import { cn } from '@/lib/utils';
import { Pencil } from 'lucide-react';

const EMPTY_FORM = { firstName: '', lastName: '', email: '', password: '', active: true, roleIds: [] as number[] };

export function UsersPage() {
  const { token } = useAuth();
  const [users, setUsers] = useState<UserSummary[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [formError, setFormError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState(EMPTY_FORM);

  const startEdit = (user: UserSummary) => {
    setEditingId(user.id);
    setFormError(null);
    setSuccessMessage(null);
    setForm({
      firstName: user.firstName,
      lastName: user.lastName,
      email: user.email,
      password: '',
      active: user.active,
      roleIds: user.roles.map((role) => role.id),
    });
  };

  const cancelEdit = () => {
    setEditingId(null);
    setFormError(null);
    setForm(EMPTY_FORM);
  };

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

    setFormError(null);
    setSuccessMessage(null);

    if (editingId === null && form.password.trim() === '') {
      setFormError('Le mot de passe est obligatoire.');
      return;
    }

    try {
      if (editingId === null) {
        await apiRequest<UserSummary>('/api/admin/users', { method: 'POST', token, body: form });
        setSuccessMessage('Utilisateur créé.');
      } else {
        // Empty password = keep the current one.
        const { password, ...changes } = form;
        await apiRequest<UserSummary>(`/api/admin/users/${editingId}`, {
          method: 'PUT',
          token,
          body: password.trim() === '' ? changes : form,
        });
        setSuccessMessage('Utilisateur mis à jour.');
      }

      setEditingId(null);
      setForm(EMPTY_FORM);
      await load();
    } catch (caught) {
      setFormError(caught instanceof ApiError ? caught.message : editingId === null ? 'Création impossible.' : 'Mise à jour impossible.');
    }
  };

  return (
    <section className="flex flex-col gap-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <p className="mb-1.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">Administration</p>
          <h2 className="font-display text-3xl font-extrabold tracking-tight">Utilisateurs</h2>
        </div>
        <div className="flex flex-col items-end gap-2 text-right">
          <p className="max-w-[38ch] text-sm text-muted-foreground">
            Gestion des comptes internes et de l&rsquo;attribution des rôles applicatifs.
          </p>
          <Chip variant="neutral">{users.length} comptes</Chip>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover">
          <CardContent className="flex flex-col gap-1.5 p-5">
            <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Comptes actifs</p>
            <CountUp value={activeUsersCount} className="text-xl" />
            <p className="text-xs text-muted-foreground">Utilisateurs immédiatement opérationnels</p>
          </CardContent>
        </Card>
        <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: '80ms' }}>
          <CardContent className="flex flex-col gap-1.5 p-5">
            <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Admins</p>
            <CountUp value={adminUsersCount} className="text-xl" />
            <p className="text-xs text-muted-foreground">Accès complet aux features système</p>
          </CardContent>
        </Card>
        <Card className="animate-rise-in hover:-translate-y-1 hover:shadow-soft-hover" style={{ animationDelay: '160ms' }}>
          <CardContent className="flex flex-col gap-1.5 p-5">
            <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">Rôles disponibles</p>
            <CountUp value={roles.length} className="text-xl" />
            <p className="text-xs text-muted-foreground">Profils réutilisables pour les équipes</p>
          </CardContent>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1.1fr_0.9fr]">
        <div className="flex flex-col gap-6">
          <Card className="overflow-hidden border-0 bg-gradient-brand text-white">
            <CardContent className="flex flex-col gap-3 p-7">
              <Chip variant="onGradient" className="w-fit">RBAC</Chip>
              <h3 className="font-display text-xl font-extrabold leading-tight tracking-tight">
                Accorder seulement les écrans utiles.
              </h3>
              <p className="max-w-[55ch] text-sm text-white/65">
                Les utilisateurs n&rsquo;affichent que les onglets autorisés par leurs rôles. L&rsquo;objectif est de
                garder un back-office lisible et sûr, sans surface inutile.
              </p>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="flex flex-col gap-5 p-6">
              <div>
                <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">Annuaire</p>
                <h3 className="font-display text-lg font-bold tracking-tight">Comptes internes</h3>
              </div>

              <div className="flex flex-col gap-4">
                {users.map((user) => (
                  <div
                    className={cn('rounded-md border p-4 transition-colors', editingId === user.id ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border')}
                    key={user.id}
                  >
                    <div className="flex items-start justify-between gap-3">
                      <div>
                        <strong className="text-sm font-semibold">
                          {user.firstName} {user.lastName}
                        </strong>
                        <p className="text-xs text-muted-foreground">{user.email}</p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Chip variant={user.active ? 'success' : 'destructive'}>{user.active ? 'Actif' : 'Inactif'}</Chip>
                        <Button size="sm" variant="outline" onClick={() => startEdit(user)}>
                          <Pencil />
                          Modifier
                        </Button>
                      </div>
                    </div>
                    <div className="mt-3 flex flex-wrap gap-1.5">
                      {user.roles.map((role) => (
                        <Badge variant="secondary" key={role.id}>
                          {role.name}
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
              <p className="mb-1 text-xs uppercase tracking-wide text-muted-foreground">{editingId === null ? 'Provisioning' : 'Modification'}</p>
              <h3 className="font-display text-lg font-bold tracking-tight">
                {editingId === null ? 'Nouvel utilisateur' : `Modifier ${form.firstName} ${form.lastName}`.trim()}
              </h3>
            </div>

            <form className="flex flex-col gap-5" noValidate onSubmit={handleSubmit}>
              <div className="grid grid-cols-2 gap-4">
                <label className="flex flex-col gap-2">
                  <span className="text-sm font-semibold">Prénom</span>
                  <Input
                    value={form.firstName}
                    onChange={(event) => setForm((current) => ({ ...current, firstName: event.target.value }))}
                  />
                </label>

                <label className="flex flex-col gap-2">
                  <span className="text-sm font-semibold">Nom</span>
                  <Input
                    value={form.lastName}
                    onChange={(event) => setForm((current) => ({ ...current, lastName: event.target.value }))}
                  />
                </label>
              </div>

              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Email</span>
                <Input
                  type="email"
                  value={form.email}
                  onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))}
                />
              </label>

              <label className="flex flex-col gap-2">
                <span className="text-sm font-semibold">Mot de passe</span>
                <Input
                  required={editingId === null}
                  placeholder={editingId === null ? undefined : 'Laisser vide pour conserver le mot de passe actuel'}
                  type="password"
                  value={form.password}
                  onChange={(event) => setForm((current) => ({ ...current, password: event.target.value }))}
                />
              </label>

              <label className="flex items-center gap-2.5 rounded-md border border-border bg-muted/30 px-3.5 py-3 text-sm font-medium">
                <input
                  checked={form.active}
                  onChange={(event) => setForm((current) => ({ ...current, active: event.target.checked }))}
                  type="checkbox"
                  className="accent-primary"
                />
                {editingId === null ? 'Compte actif dès la création' : 'Compte actif'}
              </label>

              <div className="flex flex-col gap-3">
                <span className="text-sm font-semibold">Rôles</span>
                <div className="flex flex-col gap-1.5">
                  {roles.map((role) => (
                    <label className="flex items-start gap-2.5 rounded-md border border-border px-2.5 py-2 text-sm" key={role.id}>
                      <input
                        checked={form.roleIds.includes(role.id)}
                        onChange={() => handleToggleRole(role.id)}
                        type="checkbox"
                        className="mt-0.5 accent-primary"
                      />
                      <span className="flex flex-col">
                        <strong className="font-medium">{role.name}</strong>
                        <small className="text-xs text-muted-foreground">{role.code}</small>
                      </span>
                    </label>
                  ))}
                </div>
              </div>

              <FormError message={formError} />
              <FormSuccess message={successMessage} />

              <div className="flex gap-2">
                <Button type="submit">{editingId === null ? 'Créer l\u2019utilisateur' : 'Enregistrer les modifications'}</Button>
                {editingId !== null && (
                  <Button type="button" variant="outline" onClick={cancelEdit}>
                    Annuler
                  </Button>
                )}
              </div>
            </form>
          </CardContent>
        </Card>
      </div>
    </section>
  );
}
