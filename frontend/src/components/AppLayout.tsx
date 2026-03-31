import { clsx } from 'clsx';
import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';

const NAV_ITEMS = [
  { to: '/dashboard', label: 'Dashboard', feature: 'dashboard.view', group: 'Pilotage' },
  { to: '/learners', label: 'Apprenants', feature: 'learners.view', group: 'Pilotage' },
  { to: '/courses', label: 'Formations', feature: 'courses.view', group: 'Pilotage' },
  { to: '/exports', label: 'Exports', feature: 'exports.view', group: 'Conformité' },
  { to: '/integrations', label: 'Intégrations', feature: 'integrations.view', group: 'Conformité' },
  { to: '/roles', label: 'Rôles', feature: 'settings.roles', group: 'Administration' },
  { to: '/users', label: 'Utilisateurs', feature: 'settings.users', group: 'Administration' },
];

export function AppLayout() {
  const { user, logout, canAccess } = useAuth();
  const visibleItems = NAV_ITEMS.filter((item) => canAccess(item.feature));
  const navGroups = Array.from(new Set(visibleItems.map((item) => item.group)));

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand-block brand-block-sidebar">
          <img alt="TrackUp" className="brand-logo" src="/trackup-logo.png" />
          <div className="brand-copy">
            <p className="eyebrow">Analytics & conformité</p>
            <h1>Console de pilotage</h1>
            <p className="muted">Traçabilité Rise Up, suivi des heures et contrôle fin des accès par feature.</p>
          </div>
        </div>

        <div className="sidebar-spotlight">
          <p className="sidebar-spotlight-label">Statut</p>
          <strong>{user?.isAdmin ? 'Admin global' : 'Accès restreint'}</strong>
          <p className="muted">Chaque vue n’est visible que si le rôle courant porte la feature correspondante.</p>
        </div>

        <nav className="nav-groups">
          {navGroups.map((group) => (
            <div className="nav-group" key={group}>
              <p className="nav-group-title">{group}</p>
              <div className="nav-list">
                {visibleItems
                  .filter((item) => item.group === group)
                  .map((item) => (
                    <NavLink
                      key={item.to}
                      to={item.to}
                      className={({ isActive }) =>
                        clsx('nav-link', {
                          'nav-link-active': isActive,
                        })
                      }
                    >
                      {item.label}
                    </NavLink>
                  ))}
              </div>
            </div>
          ))}
        </nav>

        <div className="sidebar-footer">
          <div>
            <p className="sidebar-user">{user?.fullName}</p>
            <p className="muted">{user?.email}</p>
          </div>
          <button className="ghost-button" onClick={logout} type="button">
            Déconnexion
          </button>
        </div>
      </aside>

      <main className="main-panel">
        <div className="main-panel-frame">
          <header className="topbar">
            <div>
              <p className="topbar-eyebrow">TrackUp</p>
              <h2>Analytics & conformité formation</h2>
            </div>
            <div className="topbar-usercard">
              <span className="status-dot" />
              <div>
                <strong>{user?.roles.map((role) => role.name).join(', ') || 'Utilisateur'}</strong>
                <p>{user?.isAdmin ? 'Accès complet' : 'Accès limité par rôle'}</p>
              </div>
            </div>
          </header>

          <Outlet />
        </div>
      </main>
    </div>
  );
}
