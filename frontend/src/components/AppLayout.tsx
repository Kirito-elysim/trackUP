import { clsx } from 'clsx';
import { useEffect, useState } from 'react';
import { NavLink, Outlet } from 'react-router-dom';
import { ChevronLeft, ChevronRight, LogOut, Menu, X } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { NAV_ITEMS } from '../lib/navItems';
import { apiRequest } from '../lib/api';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// Badges de notification sur les items de nav "Alertes"/"Absences" (sidebar) : réhydratés au montage
// puis toutes les 60s, pour rester à peu près à jour sans justifier une infra de websocket/polling
// partagée. Un item de nav → l'endpoint de comptage léger correspondant.
const NAV_BADGE_POLL_INTERVAL_MS = 60_000;
const NAV_BADGE_SOURCES: Array<{ to: string; endpoint: string }> = [
  { to: '/absences/alertes', endpoint: '/api/admin/absences/alerts/count' },
  { to: '/absences', endpoint: '/api/admin/absences/pending-review/count' },
];

export function AppLayout() {
  const { user, token, logout, canAccess } = useAuth();
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
  const [navBadgeCounts, setNavBadgeCounts] = useState<Record<string, number>>({});
  const visibleItems = NAV_ITEMS.filter((item) => !item.hidden && canAccess(item.feature));
  const navGroups = Array.from(new Set(visibleItems.map((item) => item.group)));
  const canSeeAbsencesBadges = canAccess('absences.view');

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;

    if (sidebarOpen) {
      document.body.style.overflow = 'hidden';
    }

    return () => {
      document.body.style.overflow = previousOverflow;
    };
  }, [sidebarOpen]);

  useEffect(() => {
    if (!token || !canSeeAbsencesBadges) {
      return;
    }

    let cancelled = false;

    const load = async () => {
      try {
        const results = await Promise.all(
          NAV_BADGE_SOURCES.map((source) => apiRequest<{ count: number }>(source.endpoint, { token })),
        );
        if (!cancelled) {
          setNavBadgeCounts(
            Object.fromEntries(NAV_BADGE_SOURCES.map((source, index) => [source.to, results[index].count])),
          );
        }
      } catch {
        // Badges silencieux en cas d'échec — pas de dégradation visible de la sidebar pour ça.
      }
    };

    void load();
    const interval = setInterval(load, NAV_BADGE_POLL_INTERVAL_MS);

    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, [token, canSeeAbsencesBadges]);

  return (
    <div className="flex min-h-screen bg-background">
      <button
        aria-hidden={!sidebarOpen}
        className={cn('fixed inset-0 z-30 hidden bg-foreground/40 backdrop-blur-[1px] lg:!hidden', sidebarOpen && 'block')}
        onClick={() => setSidebarOpen(false)}
        tabIndex={sidebarOpen ? 0 : -1}
        type="button"
      />

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-40 flex w-72 shrink-0 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0',
          sidebarOpen ? 'translate-x-0' : '-translate-x-full',
          sidebarCollapsed && 'lg:w-[4.5rem]',
        )}
        id="trackup-sidebar"
      >
        <button
          className="absolute right-4 top-4 z-10 flex h-7 w-7 items-center justify-center rounded-full text-muted-foreground hover:bg-sidebar-accent hover:text-foreground lg:hidden"
          onClick={() => setSidebarOpen(false)}
          type="button"
          aria-label="Fermer le menu"
        >
          <X size={16} />
        </button>

        <div className="flex items-center justify-between gap-2 px-5 py-5">
          {!sidebarCollapsed && <img alt="TrackUp" className="h-6 w-auto" src="/trackup-logo.png" />}
          <button
            className={cn(
              'flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-sidebar-border text-muted-foreground transition hover:bg-sidebar-accent hover:text-foreground',
              sidebarCollapsed && 'mx-auto',
            )}
            onClick={() => setSidebarCollapsed(!sidebarCollapsed)}
            type="button"
            title={sidebarCollapsed ? 'Agrandir la barre latérale' : 'Réduire la barre latérale'}
          >
            {sidebarCollapsed ? <ChevronRight size={15} /> : <ChevronLeft size={15} />}
          </button>
        </div>

        {!sidebarCollapsed && (
          <div className="flex flex-col gap-3 px-5 pb-5">
            <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
              Console &middot; Conformité formation
            </p>
            <div className="flex items-center justify-between rounded-xl border border-sidebar-border bg-card px-3.5 py-3 shadow-soft">
              <div>
                <p className="text-[0.62rem] font-semibold uppercase tracking-[0.12em] text-muted-foreground">Statut</p>
                <strong className="font-display text-sm font-bold tracking-tight">
                  {user?.isAdmin ? 'Admin global' : 'Accès restreint'}
                </strong>
              </div>
              <span className="h-2.5 w-2.5 rounded-full bg-success shadow-[0_0_0_4px_rgba(31,174,125,0.16)]" aria-hidden="true" />
            </div>
          </div>
        )}

        <nav className="flex flex-1 flex-col gap-6 overflow-y-auto px-3 py-4">
          {navGroups.map((group) => (
            <div className="flex flex-col gap-1" key={group}>
              {!sidebarCollapsed && (
                <p className="mb-1 px-3 text-[0.64rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground/70">
                  {group}
                </p>
              )}
              <div className="flex flex-col gap-1">
                {visibleItems
                  .filter((item) => item.group === group)
                  .map((item) => {
                    const Icon = item.icon;
                    const badgeCount = navBadgeCounts[item.to] ?? 0;
                    const showBadge = badgeCount > 0;
                    const badgeLabel = badgeCount > 99 ? '99+' : badgeCount;

                    return (
                      <NavLink
                        key={item.to}
                        to={item.to}
                        end={item.to === '/absences'}
                        onClick={() => setSidebarOpen(false)}
                        className={({ isActive }) =>
                          clsx(
                            'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-muted-foreground transition-all duration-200 hover:translate-x-0.5 hover:bg-sidebar-accent hover:text-foreground',
                            sidebarCollapsed && 'justify-center px-0',
                            isActive && 'bg-gradient-brand text-white shadow-soft hover:translate-x-0 hover:text-white',
                          )
                        }
                        title={sidebarCollapsed ? `${item.label}${showBadge ? ` (${badgeCount})` : ''}` : undefined}
                      >
                        <span className="relative shrink-0">
                          <Icon size={17} />
                          {showBadge && sidebarCollapsed && (
                            <span className="absolute -right-1.5 -top-1.5 flex h-3.5 min-w-3.5 items-center justify-center rounded-full bg-destructive px-1 text-[0.55rem] font-bold leading-none text-destructive-foreground">
                              {badgeLabel}
                            </span>
                          )}
                        </span>
                        {!sidebarCollapsed && (
                          <span className="flex flex-1 items-center justify-between gap-2">
                            {item.label}
                            {showBadge && (
                              <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1.5 text-[0.65rem] font-bold leading-none text-destructive-foreground">
                                {badgeLabel}
                              </span>
                            )}
                          </span>
                        )}
                      </NavLink>
                    );
                  })}
              </div>
            </div>
          ))}
        </nav>

        <div className="mt-auto px-5 py-4">
          <NavLink
            to="/profile"
            onClick={() => setSidebarOpen(false)}
            className={({ isActive }) =>
              cn(
                'mb-3 flex items-center gap-2.5 rounded-xl p-1.5 transition-colors duration-200 hover:bg-sidebar-accent',
                isActive && 'bg-sidebar-accent',
                sidebarCollapsed && 'justify-center px-0',
              )
            }
            title={sidebarCollapsed ? 'Profil' : undefined}
          >
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-brand text-xs font-bold text-white">
              {user?.fullName?.[0]?.toUpperCase() ?? '?'}
            </span>
            {!sidebarCollapsed && (
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold">{user?.fullName}</p>
                <p className="truncate text-xs text-muted-foreground">{user?.email}</p>
              </div>
            )}
          </NavLink>
          <Button
            className={cn('w-full justify-start', sidebarCollapsed && 'justify-center px-0')}
            onClick={() => {
              setSidebarOpen(false);
              logout();
            }}
            variant="outline"
            title={sidebarCollapsed ? 'Déconnexion' : undefined}
          >
            <LogOut size={16} />
            {!sidebarCollapsed && 'Déconnexion'}
          </Button>
        </div>
      </aside>

      <main className="min-w-0 flex-1">
        <header className="flex flex-wrap items-center justify-between gap-4 border-b border-border bg-card/80 px-5 py-4 backdrop-blur-sm lg:px-8">
          <div className="flex min-w-0 items-center gap-3.5">
            <button
              aria-controls="trackup-sidebar"
              aria-expanded={sidebarOpen}
              className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-border text-foreground lg:hidden"
              onClick={() => setSidebarOpen((current) => !current)}
              type="button"
              aria-label="Ouvrir le menu"
            >
              <Menu size={18} />
            </button>
            <div className="min-w-0">
              <p className="text-[0.66rem] font-semibold uppercase tracking-[0.14em] text-muted-foreground">TrackUp</p>
              <h2 className="font-display truncate text-[clamp(1.2rem,2vw,1.5rem)] font-extrabold leading-tight tracking-tight">
                Analytics &amp; conformité formation
              </h2>
            </div>
          </div>
          <div className="inline-flex min-w-0 max-w-full items-center gap-3 rounded-xl border border-border bg-card px-3.5 py-2 shadow-soft">
            <span className="h-2 w-2 shrink-0 rounded-full bg-gradient-brand" aria-hidden="true" />
            <div className="min-w-0">
              <strong className="block truncate text-sm">
                {user?.roles.map((role) => role.name).join(', ') || 'Utilisateur'}
              </strong>
              <p className="truncate text-xs uppercase tracking-wide text-muted-foreground">
                {user?.isAdmin ? 'Accès complet' : 'Accès limité'}
              </p>
            </div>
          </div>
        </header>

        <div className="px-5 py-6 lg:px-8">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
