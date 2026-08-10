import { useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { ApiError, apiRequest, apiUrl } from '../lib/api';
import { RefreshCw, Upload, Database, Users, TrendingUp, BookOpen, CheckCircle, XCircle, Clock, FileText, Layers, ClipboardList, CheckSquare, Link2 } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Breadcrumb } from '@/components/ui/breadcrumb';
import { cn } from '@/lib/utils';

interface SyncStatus {
  type: string;
  status: 'idle' | 'loading' | 'success' | 'error';
  message?: string;
  startTime?: Date;
  endTime?: Date;
}

export function SyncManagementPage() {
  const { token } = useAuth();
  const [syncStatuses, setSyncStatuses] = useState<Record<string, SyncStatus>>({});
  const [uploadStatus, setUploadStatus] = useState<SyncStatus>({ type: 'upload', status: 'idle' });
  const [selectedFile, setSelectedFile] = useState<File | null>(null);

  const updateSyncStatus = (type: string, status: Partial<SyncStatus>) => {
    setSyncStatuses(prev => ({
      ...prev,
      [type]: { ...prev[type], type, ...status }
    }));
  };

  const triggerSync = async (syncType: string, endpoint: string, label: string) => {
    if (!token) {
      return;
    }

    updateSyncStatus(syncType, { status: 'loading', startTime: new Date(), message: undefined });

    try {
      const response = await apiRequest<{ message: string; synced?: number }>(
        endpoint,
        { token, method: 'POST' }
      );

      updateSyncStatus(syncType, {
        status: 'success',
        message: response.message || `${label} synchronisé avec succès`,
        endTime: new Date()
      });
    } catch (error) {
      updateSyncStatus(syncType, {
        status: 'error',
        message: error instanceof ApiError ? error.message : `Erreur lors de la synchronisation ${label}`,
        endTime: new Date()
      });
    }
  };

  const handleFileUpload = async () => {
    if (!selectedFile || !token) {
      return;
    }

    setUploadStatus({ type: 'upload', status: 'loading', startTime: new Date() });

    const formData = new FormData();
    formData.append('file', selectedFile);

    try {
      const response = await fetch(
        apiUrl('/api/riseup-activity-logs/import'),
        {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${token}`,
          },
          body: formData,
        }
      );

      const data = await response.json();

      if (!response.ok) {
        throw new ApiError(data.message || 'Upload impossible.', response.status);
      }

      setUploadStatus({
        type: 'upload',
        status: 'success',
        message: data.message || 'Fichier importé avec succès',
        endTime: new Date()
      });
      setSelectedFile(null);
    } catch (error) {
      setUploadStatus({
        type: 'upload',
        status: 'error',
        message: error instanceof ApiError ? error.message : 'Erreur lors de l\'upload',
        endTime: new Date()
      });
    }
  };

  const syncActions = [
    {
      type: 'groups',
      label: 'Groupes & Classes',
      description: 'Synchroniser tous les groupes et classes depuis Rise Up',
      endpoint: '/api/sync/groups',
      icon: Users,
    },
    {
      type: 'groupMemberships',
      label: 'Appartenances Groupes',
      description: 'Synchroniser les liens apprenants ↔ groupes',
      endpoint: '/api/sync/riseup-group-memberships',
      icon: Link2,
    },
    {
      type: 'learningPaths',
      label: 'Parcours d\'apprentissage',
      description: 'Synchroniser les parcours et leurs contenus',
      endpoint: '/api/sync/learning-paths',
      icon: TrendingUp,
    },
    {
      type: 'trainings',
      label: 'Formations',
      description: 'Synchroniser toutes les formations disponibles',
      endpoint: '/api/sync/trainings',
      icon: BookOpen,
    },
    {
      type: 'learners',
      label: 'Apprenants',
      description: 'Synchroniser les apprenants et leurs inscriptions',
      endpoint: '/api/sync/learners',
      icon: Users,
    },
    {
      type: 'sessions',
      label: 'Sessions',
      description: 'Synchroniser les sessions et les présences',
      endpoint: '/api/sync/sessions',
      icon: Clock,
    },
    {
      type: 'registrations',
      label: 'Inscriptions',
      description: 'Synchroniser les inscriptions aux formations',
      endpoint: '/api/sync/registrations',
      icon: ClipboardList,
    },
    {
      type: 'modulesSteps',
      label: 'Modules & Étapes',
      description: 'Synchroniser les modules et les étapes des formations',
      endpoint: '/api/sync/modules-steps',
      icon: Layers,
    },
    {
      type: 'userStepStates',
      label: 'Progression',
      description: 'Synchroniser la progression des apprenants (états des étapes)',
      endpoint: '/api/sync/userstepstates',
      icon: CheckSquare,
    }
  ];

  const formatDuration = (start?: Date, end?: Date) => {
    if (!start || !end) return null;
    const duration = Math.round((end.getTime() - start.getTime()) / 1000);
    return `${duration}s`;
  };

  return (
    <div className="flex flex-col gap-10">
      <div>
        <Breadcrumb items={[{ label: 'Administration' }, { label: 'Synchronisation' }]} />
        <h2 className="mt-1.5 font-display text-3xl font-extrabold tracking-tight">Gestion de la synchronisation</h2>
        <p className="mt-1.5 text-sm text-muted-foreground">
          Synchronisez manuellement les données depuis l&rsquo;API Rise Up ou importez des logs d&rsquo;activité
        </p>
      </div>

      <section className="flex flex-col gap-5">
        <div className="flex items-center gap-2.5">
          <Database size={18} className="text-primary" />
          <h3 className="font-display text-lg font-bold tracking-tight">Synchronisation depuis l&rsquo;API Rise Up</h3>
        </div>
        <p className="-mt-2 text-sm text-muted-foreground">
          Cliquez sur un bouton pour lancer la synchronisation manuelle des données depuis l&rsquo;API Rise Up.
        </p>

        <div className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {syncActions.map((action) => {
            const status = syncStatuses[action.type] || { status: 'idle' };
            const Icon = action.icon;

            return (
              <Card key={action.type}>
                <CardContent className="flex flex-col gap-4 p-5">
                  <div className="flex items-start gap-3">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-gradient-brand text-white">
                      <Icon size={20} />
                    </span>
                    <div className="min-w-0">
                      <h4 className="text-sm font-semibold">{action.label}</h4>
                      <p className="mt-0.5 text-xs text-muted-foreground">{action.description}</p>
                    </div>
                  </div>

                  <Button
                    variant={status.status === 'loading' ? 'secondary' : 'outline'}
                    onClick={() => triggerSync(action.type, action.endpoint, action.label)}
                    disabled={status.status === 'loading'}
                  >
                    <RefreshCw size={15} className={status.status === 'loading' ? 'animate-spin' : undefined} />
                    {status.status === 'loading' ? 'Synchronisation...' : 'Synchroniser'}
                  </Button>

                  {status.message && (
                    <div
                      className={cn(
                        'flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-medium',
                        status.status === 'success' && 'border-success/25 bg-success/10 text-success',
                        status.status === 'error' && 'border-destructive/25 bg-destructive/10 text-destructive',
                      )}
                    >
                      {status.status === 'success' && <CheckCircle size={14} className="shrink-0" />}
                      {status.status === 'error' && <XCircle size={14} className="shrink-0" />}
                      <span className="flex-1">{status.message}</span>
                      {status.startTime && status.endTime && (
                        <span className="tabular opacity-70">({formatDuration(status.startTime, status.endTime)})</span>
                      )}
                    </div>
                  )}
                </CardContent>
              </Card>
            );
          })}
        </div>
      </section>

      <section className="flex flex-col gap-5">
        <div className="flex items-center gap-2.5">
          <Upload size={18} className="text-primary" />
          <h3 className="font-display text-lg font-bold tracking-tight">Import de logs d&rsquo;activité</h3>
        </div>
        <p className="-mt-2 text-sm text-muted-foreground">
          Importez un fichier CSV d&rsquo;export d&rsquo;activité Rise Up pour alimenter le journal d&rsquo;activité.
        </p>

        <Card className="border-0 bg-gradient-brand text-white">
          <CardContent className="flex flex-col gap-3 p-6">
            <div className="flex items-center gap-2.5 border-b border-white/10 pb-3">
              <FileText size={17} className="text-white" />
              <h4 className="text-sm font-semibold">Format de fichier requis</h4>
            </div>
            <div className="flex flex-col gap-2.5 text-sm text-white/70">
              <p><strong className="text-white">Type :</strong> Fichier Excel (XLSX)</p>
              <p><strong className="text-white">Colonnes obligatoires :</strong></p>
              <ul className="flex flex-col gap-1.5">
                <li className="flex gap-2"><span className="text-white/70">→</span><span><code className="rounded bg-white/15 px-1.5 py-0.5 text-xs font-semibold text-white">ID de la formation</code> - Identifiant Rise Up de la formation</span></li>
                <li className="flex gap-2"><span className="text-white/70">→</span><span><code className="rounded bg-white/15 px-1.5 py-0.5 text-xs font-semibold text-white">Date de connexion</code> - Date et heure de début de session</span></li>
                <li className="flex gap-2"><span className="text-white/70">→</span><span><code className="rounded bg-white/15 px-1.5 py-0.5 text-xs font-semibold text-white">Date de déconnexion</code> - Date et heure de fin de session</span></li>
                <li className="flex gap-2"><span className="text-white/70">→</span><span><code className="rounded bg-white/15 px-1.5 py-0.5 text-xs font-semibold text-white">Temps passé (heures)</code> - Durée en heures décimales</span></li>
              </ul>
              <p className="rounded-md border-l-2 border-white/40 bg-white/[0.08] px-3 py-2">
                <strong className="text-white">Note :</strong> Le fichier doit correspondre au format des exports d&rsquo;activité Rise Up standard (XLSX).
              </p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardContent className="flex flex-col gap-4 p-6">
            <div className="relative">
              <input
                type="file"
                id="file-upload"
                accept=".xlsx"
                onChange={(e) => setSelectedFile(e.target.files?.[0] || null)}
                className="absolute h-px w-px overflow-hidden opacity-0"
              />
              <label
                htmlFor="file-upload"
                className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-border p-12 text-center transition hover:border-primary/40 hover:bg-muted/30"
              >
                <FileText size={36} className="text-primary" />
                <p className="text-sm font-semibold">{selectedFile ? selectedFile.name : 'Choisir un fichier XLSX'}</p>
                <p className="text-xs text-muted-foreground">
                  {selectedFile ? `Taille: ${(selectedFile.size / 1024).toFixed(2)} KB` : 'Cliquez pour sélectionner ou glissez-déposez un fichier'}
                </p>
              </label>
            </div>

            {selectedFile && (
              <Button onClick={() => void handleFileUpload()} disabled={uploadStatus.status === 'loading'}>
                <RefreshCw size={15} className={uploadStatus.status === 'loading' ? 'animate-spin' : 'hidden'} />
                <Upload size={15} className={uploadStatus.status === 'loading' ? 'hidden' : undefined} />
                {uploadStatus.status === 'loading' ? 'Import en cours...' : 'Importer le fichier'}
              </Button>
            )}

            {uploadStatus.message && (
              <div
                className={cn(
                  'flex items-center gap-2 rounded-md border px-3 py-2 text-xs font-medium',
                  uploadStatus.status === 'success' && 'border-success/25 bg-success/10 text-success',
                  uploadStatus.status === 'error' && 'border-destructive/25 bg-destructive/10 text-destructive',
                )}
              >
                {uploadStatus.status === 'success' && <CheckCircle size={14} className="shrink-0" />}
                {uploadStatus.status === 'error' && <XCircle size={14} className="shrink-0" />}
                <span className="flex-1">{uploadStatus.message}</span>
                {uploadStatus.startTime && uploadStatus.endTime && (
                  <span className="tabular opacity-70">({formatDuration(uploadStatus.startTime, uploadStatus.endTime)})</span>
                )}
              </div>
            )}
          </CardContent>
        </Card>
      </section>
    </div>
  );
}
