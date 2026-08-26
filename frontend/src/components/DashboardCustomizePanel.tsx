import { useEffect, useState } from 'react';
import { ArrowDown, ArrowUp, Plus, X } from 'lucide-react';
import { useAuth } from '../contexts/useAuth';
import { apiRequest, ApiError } from '../lib/api';
import { DASHBOARD_KPIS, DEFAULT_DASHBOARD_KPIS } from '../lib/dashboardKpis';
import { NAV_ITEMS } from '../lib/navItems';
import type { DashboardPreferences, DashboardTheme } from '../types/auth';
import { Dialog, DialogBody, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/select';
import { FormError } from '@/components/ui/form-error';

const THEMES: Array<{ key: DashboardTheme; label: string; swatch: string }> = [
  { key: 'brand', label: 'Rose & Orange', swatch: 'linear-gradient(135deg, #ff0f7b, #ff6b2c)' },
  { key: 'blue', label: 'Bleu', swatch: 'linear-gradient(135deg, #2563eb, #06b6d4)' },
  { key: 'green', label: 'Vert', swatch: 'linear-gradient(135deg, #16a34a, #84cc16)' },
  { key: 'violet', label: 'Violet', swatch: 'linear-gradient(135deg, #7c3aed, #ec4899)' },
];

function defaultPreferences(): DashboardPreferences {
  return { theme: 'brand', kpis: [...DEFAULT_DASHBOARD_KPIS], shortcuts: [] };
}

export function DashboardCustomizePanel({
  open,
  onOpenChange,
  preferences,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  preferences: DashboardPreferences | null;
}) {
  const { token, canAccess, refreshUser } = useAuth();
  const [theme, setTheme] = useState<DashboardTheme>('brand');
  const [kpis, setKpis] = useState<string[]>(DEFAULT_DASHBOARD_KPIS);
  const [shortcuts, setShortcuts] = useState<Array<{ to: string; label: string | null }>>([]);
  const [newShortcutTo, setNewShortcutTo] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;

    const current = preferences ?? defaultPreferences();
    setTheme(current.theme);
    setKpis(current.kpis);
    setShortcuts(current.shortcuts);
    setNewShortcutTo('');
    setError(null);
  }, [open, preferences]);

  const availableTargets = NAV_ITEMS.filter((item) => canAccess(item.feature));
  const unselectedKpis = DASHBOARD_KPIS.filter((kpi) => !kpis.includes(kpi.key));

  const moveKpi = (index: number, direction: -1 | 1) => {
    setKpis((current) => {
      const next = [...current];
      const target = index + direction;
      if (target < 0 || target >= next.length) return current;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  };

  const addShortcut = () => {
    if (newShortcutTo === '' || shortcuts.some((s) => s.to === newShortcutTo)) return;
    setShortcuts((current) => [...current, { to: newShortcutTo, label: null }]);
    setNewShortcutTo('');
  };

  const save = async () => {
    if (!token) return;

    setSaving(true);
    setError(null);
    try {
      await apiRequest('/api/me/dashboard-preferences', {
        method: 'PUT',
        token,
        body: { theme, kpis, shortcuts },
      });
      await refreshUser();
      onOpenChange(false);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Enregistrement impossible.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Personnaliser le tableau de bord</DialogTitle>
          <DialogDescription>Ces réglages sont propres à votre compte.</DialogDescription>
        </DialogHeader>
        <DialogBody className="flex flex-col gap-6">
          <div className="flex flex-col gap-2.5">
            <span className="text-sm font-semibold">Thème</span>
            <div className="flex flex-wrap gap-3">
              {THEMES.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  onClick={() => setTheme(item.key)}
                  className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition-colors ${
                    theme === item.key ? 'border-primary bg-primary/5 font-semibold' : 'border-border hover:border-primary/40'
                  }`}
                >
                  <span className="h-5 w-5 shrink-0 rounded-full" style={{ backgroundImage: item.swatch }} />
                  {item.label}
                </button>
              ))}
            </div>
          </div>

          <div className="flex flex-col gap-2.5">
            <span className="text-sm font-semibold">Indicateurs affichés ({kpis.length})</span>
            <div className="flex flex-col divide-y divide-border rounded-xl border border-border">
              {kpis.map((key, index) => {
                const kpi = DASHBOARD_KPIS.find((item) => item.key === key);
                if (!kpi) return null;
                const Icon = kpi.icon;
                return (
                  <div key={key} className="flex items-center gap-3 px-3 py-2.5">
                    <Icon size={15} className="shrink-0 text-muted-foreground" />
                    <span className="flex-1 text-sm">{kpi.label}</span>
                    <button
                      type="button"
                      disabled={index === 0}
                      onClick={() => moveKpi(index, -1)}
                      className="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted disabled:opacity-30"
                    >
                      <ArrowUp size={14} />
                    </button>
                    <button
                      type="button"
                      disabled={index === kpis.length - 1}
                      onClick={() => moveKpi(index, 1)}
                      className="flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted disabled:opacity-30"
                    >
                      <ArrowDown size={14} />
                    </button>
                    <button
                      type="button"
                      onClick={() => setKpis((current) => current.filter((k) => k !== key))}
                      className="flex h-7 w-7 items-center justify-center rounded-md text-destructive hover:bg-destructive/10"
                    >
                      <X size={14} />
                    </button>
                  </div>
                );
              })}
              {kpis.length === 0 ? <p className="p-3 text-sm text-muted-foreground">Aucun indicateur sélectionné.</p> : null}
            </div>

            {unselectedKpis.length > 0 ? (
              <div className="flex flex-wrap gap-2">
                {unselectedKpis.map((kpi) => (
                  <button
                    key={kpi.key}
                    type="button"
                    onClick={() => setKpis((current) => [...current, kpi.key])}
                    className="flex items-center gap-1.5 rounded-full border border-dashed border-border px-3 py-1.5 text-xs text-muted-foreground hover:border-primary/40 hover:text-foreground"
                  >
                    <Plus size={12} /> {kpi.label}
                  </button>
                ))}
              </div>
            ) : null}
          </div>

          <div className="flex flex-col gap-2.5">
            <span className="text-sm font-semibold">Raccourcis ({shortcuts.length})</span>
            <div className="flex flex-col divide-y divide-border rounded-xl border border-border">
              {shortcuts.map((shortcut) => {
                const target = NAV_ITEMS.find((item) => item.to === shortcut.to);
                return (
                  <div key={shortcut.to} className="flex items-center gap-3 px-3 py-2.5">
                    <span className="flex-1 text-sm">{shortcut.label ?? target?.label ?? shortcut.to}</span>
                    <span className="text-xs text-muted-foreground">{shortcut.to}</span>
                    <button
                      type="button"
                      onClick={() => setShortcuts((current) => current.filter((s) => s.to !== shortcut.to))}
                      className="flex h-7 w-7 items-center justify-center rounded-md text-destructive hover:bg-destructive/10"
                    >
                      <X size={14} />
                    </button>
                  </div>
                );
              })}
              {shortcuts.length === 0 ? <p className="p-3 text-sm text-muted-foreground">Aucun raccourci ajouté.</p> : null}
            </div>

            <div className="flex gap-2">
              <Select value={newShortcutTo} onChange={(event) => setNewShortcutTo(event.target.value)} className="flex-1">
                <option value="">Choisir une page...</option>
                {availableTargets
                  .filter((item) => !shortcuts.some((s) => s.to === item.to))
                  .map((item) => (
                    <option key={item.to} value={item.to}>
                      {item.label}
                    </option>
                  ))}
              </Select>
              <Button variant="outline" type="button" onClick={addShortcut} disabled={newShortcutTo === ''}>
                <Plus size={15} /> Ajouter
              </Button>
            </div>
          </div>

          <FormError message={error} />

          <div className="flex justify-end gap-2">
            <Button variant="ghost" type="button" onClick={() => onOpenChange(false)}>
              Annuler
            </Button>
            <Button type="button" disabled={saving} onClick={() => void save()}>
              {saving ? 'Enregistrement...' : 'Enregistrer'}
            </Button>
          </div>
        </DialogBody>
      </DialogContent>
    </Dialog>
  );
}
