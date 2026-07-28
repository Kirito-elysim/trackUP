import { clsx } from 'clsx';
import { useEffect, useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';

const NAV_ITEMS = [
  { to: '/dashboard', label: 'Dashboard', feature: 'dashboard.view', group: 'Pilotage' },
  { to: '/analytics', label: 'Analytics', feature: 'analytics.view', group: 'Pilotage' },
  { to: '/learningpaths', label: 'Parcours', feature: 'learningpaths.view', group: 'Pilotage' },
  { to: '/learners', label: 'Apprenants', feature: 'learners.view', group: 'Pilotage' },
  { to: '/courses', label: 'Formations', feature: 'courses.view', group: 'Pilotage' },
  { to: '/riseup-logs', label: 'Logs exacts', feature: 'exports.view', group: 'Conformité' },
  { to: '/exports', label: 'Exports', feature: 'exports.view', group: 'Conformité' },
  { to: '/integrations', label: 'Intégrations', feature: 'integrations.view', group: 'Conformité' },
  { to: '/sync', label: 'Synchronisation', feature: 'settings.users', group: 'Administration' },
  { to: '/roles', label: 'Rôles', feature: 'settings.roles', group: 'Administration' },
  { to: '/users', label: 'Utilisateurs', feature: 'settings.users', group: 'Administration' },
];

export function AppLayout() {
  const { user, logout, canAccess } = useAuth();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const visibleItems = NAV_ITEMS.filter((item) => canAccess(item.feature));
  const navGroups = Array.from(new Set(visibleItems.map((item) => item.group)));

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;

    if (sidebarOpen) {
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [sidebarOpen]);

  return (
    <div className="app-shell">
      <button
        aria-hidden={!sidebarOpen}
        className={clsx('sidebar-overlay', { 'sidebar-overlay-visible': sidebarOpen })}
        onClick={() => setSidebarOpen(false)}
        tabIndex={sidebarOpen ? 0 : -1}
        type="button"
      />

      <aside className={clsx('sidebar', { 'sidebar-open': sidebarOpen, 'sidebar-collapsed': sidebarCollapsed })} id="trackup-sidebar">
        <button 
          className="sidebar-collapse-btn" 
          onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
          type="button"
          title={sidebarCollapsed ? "Agrandir la barre latérale" : "Réduire la barre latérale"}
        >
          {sidebarCollapsed ? <ChevronRight size={20} /> : <ChevronLeft size={20} />}
        </button>

        {!sidebarCollapsed && (
          <>
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
              <p className="muted">Chaque vue n'est visible que si le rôle courant porte la feature correspondante.</p>
            </div>
          </>
        )}

        <nav className="nav-groups">
          {navGroups.map((group) => (
            <div className="nav-group" key={group}>
              {!sidebarCollapsed && <p className="nav-group-title">{group}</p>}
              <div className="nav-list">
                {visibleItems
                  .filter((item) => item.group === group)
                  .map((item) => (
                    <NavLink
                      key={item.to}
                      to={item.to}
                      onClick={() => setSidebarOpen(false)}
                      className={({ isActive }) =>
                        clsx('nav-link', {
                          'nav-link-active': isActive,
                        })
                      }
                      title={sidebarCollapsed ? item.label : undefined}
                    >
                      {sidebarCollapsed ? item.label.charAt(0) : item.label}
                    </NavLink>
                  ))}
              </div>
            </div>
          ))}
        </nav>

        {!sidebarCollapsed && (
          <div className="sidebar-footer">
            <div>
              <p className="sidebar-user">{user?.fullName}</p>
              <p className="muted">{user?.email}</p>
            </div>
            <button
              className="ghost-button"
              onClick={() => {
                setSidebarOpen(false);
                logout();
              }}
              type="button"
            >
              Déconnexion
            </button>
          </div>
        )}
      </aside>

      <main className="main-panel">
        <div className="main-panel-frame">
          <header className="topbar">
            <div className="topbar-main">
              <button
                aria-controls="trackup-sidebar"
                aria-expanded={sidebarOpen}
                className="mobile-nav-toggle"
                onClick={() => setSidebarOpen((current) => !current)}
                type="button"
              >
                <span />
                <span />
                <span />
                <span>Menu</span>
              </button>
              <div>
                <p className="topbar-eyebrow">TrackUp</p>
                <h2>Analytics & conformité formation</h2>
              </div>
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
