import { useState } from 'react';
import { useAuth } from '../contexts/useAuth';
import { ApiError, apiRequest, apiUrl } from '../lib/api';
import { RefreshCw, Upload, Database, Users, TrendingUp, BookOpen, CheckCircle, XCircle, Clock, FileText } from 'lucide-react';

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
      color: '#FF6B9D'
    },
    {
      type: 'learningPaths',
      label: 'Parcours d\'apprentissage',
      description: 'Synchroniser les parcours et leurs contenus',
      endpoint: '/api/sync/learning-paths',
      icon: TrendingUp,
      color: '#5B8DEE'
    },
    {
      type: 'trainings',
      label: 'Formations',
      description: 'Synchroniser toutes les formations disponibles',
      endpoint: '/api/sync/trainings',
      icon: BookOpen,
      color: '#FFB946'
    },
    {
      type: 'learners',
      label: 'Apprenants',
      description: 'Synchroniser les apprenants et leurs inscriptions',
      endpoint: '/api/sync/learners',
      icon: Users,
      color: '#00A67E'
    },
    {
      type: 'sessions',
      label: 'Sessions',
      description: 'Synchroniser les sessions et les présences',
      endpoint: '/api/sync/sessions',
      icon: Clock,
      color: '#C239B3'
    }
  ];

  const formatDuration = (start?: Date, end?: Date) => {
    if (!start || !end) return null;
    const duration = Math.round((end.getTime() - start.getTime()) / 1000);
    return `${duration}s`;
  };

  return (
    <div className="page-container">
      <div className="page-header-modern">
        <div>
          <div className="page-breadcrumb">
            <span className="breadcrumb-item">Administration</span>
            <span className="breadcrumb-separator">›</span>
            <span className="breadcrumb-item active">Synchronisation</span>
          </div>
          <h1>Gestion de la synchronisation</h1>
          <p className="page-description">Synchronisez manuellement les données depuis l'API Rise Up ou importez des logs d'activité</p>
        </div>
      </div>

      {/* Sync from API Section */}
      <div className="sync-section">
        <div className="section-title">
          <Database size={24} color="#FF6B9D" />
          <h2>Synchronisation depuis l'API Rise Up</h2>
        </div>
        <p className="section-description">
          Cliquez sur un bouton pour lancer la synchronisation manuelle des données depuis l'API Rise Up.
        </p>

        <div className="sync-grid">
          {syncActions.map((action) => {
            const status = syncStatuses[action.type] || { status: 'idle' };
            const Icon = action.icon;

            return (
              <div key={action.type} className="sync-card">
                <div className="sync-card-header">
                  <div className="sync-icon" style={{ background: `linear-gradient(135deg, ${action.color} 0%, ${action.color}dd 100%)` }}>
                    <Icon size={24} color="white" />
                  </div>
                  <div className="sync-info">
                    <h3>{action.label}</h3>
                    <p>{action.description}</p>
                  </div>
                </div>

                <button
                  className={`sync-button ${status.status === 'loading' ? 'sync-button-loading' : ''}`}
                  onClick={() => triggerSync(action.type, action.endpoint, action.label)}
                  disabled={status.status === 'loading'}
                  type="button"
                >
                  {status.status === 'loading' ? (
                    <>
                      <RefreshCw size={18} className="spin" />
                      <span>Synchronisation...</span>
                    </>
                  ) : (
                    <>
                      <RefreshCw size={18} />
                      <span>Synchroniser</span>
                    </>
                  )}
                </button>

                {status.message && (
                  <div className={`sync-status sync-status-${status.status}`}>
                    {status.status === 'success' && <CheckCircle size={16} />}
                    {status.status === 'error' && <XCircle size={16} />}
                    <span>{status.message}</span>
                    {status.startTime && status.endTime && (
                      <span className="sync-duration">({formatDuration(status.startTime, status.endTime)})</span>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </div>

      {/* File Upload Section */}
      <div className="sync-section">
        <div className="section-title">
          <Upload size={24} color="#5B8DEE" />
          <h2>Import de logs d'activité</h2>
        </div>
        <p className="section-description">
          Importez un fichier CSV d'export d'activité Rise Up pour alimenter le journal d'activité.
        </p>

        <div className="format-info-card">
          <div className="format-info-header">
            <FileText size={20} color="#5B8DEE" />
            <h4>Format de fichier requis</h4>
          </div>
          <div className="format-info-content">
            <p><strong>Type :</strong> Fichier Excel (XLSX)</p>
            <p><strong>Colonnes obligatoires :</strong></p>
            <ul className="format-list">
              <li><code>ID de la formation</code> - Identifiant Rise Up de la formation</li>
              <li><code>Date de connexion</code> - Date et heure de début de session</li>
              <li><code>Date de déconnexion</code> - Date et heure de fin de session</li>
              <li><code>Temps passé (heures)</code> - Durée en heures décimales</li>
            </ul>
            <p className="format-note">
              <strong>Note :</strong> Le fichier doit correspondre au format des exports d'activité Rise Up standard (XLSX).
            </p>
          </div>
        </div>

        <div className="upload-card">
          <div className="upload-area">
            <input
              type="file"
              id="file-upload"
              accept=".xlsx"
              onChange={(e) => setSelectedFile(e.target.files?.[0] || null)}
              className="file-input"
            />
            <label htmlFor="file-upload" className="file-label">
              <FileText size={48} color="#5B8DEE" />
              <p className="upload-title">
                {selectedFile ? selectedFile.name : 'Choisir un fichier XLSX'}
              </p>
              <p className="upload-hint">
                {selectedFile 
                  ? `Taille: ${(selectedFile.size / 1024).toFixed(2)} KB`
                  : 'Cliquez pour sélectionner ou glissez-déposez un fichier'
                }
              </p>
            </label>
          </div>

          {selectedFile && (
            <button
              className={`upload-button ${uploadStatus.status === 'loading' ? 'upload-button-loading' : ''}`}
              onClick={handleFileUpload}
              disabled={uploadStatus.status === 'loading'}
              type="button"
            >
              {uploadStatus.status === 'loading' ? (
                <>
                  <RefreshCw size={18} className="spin" />
                  <span>Import en cours...</span>
                </>
              ) : (
                <>
                  <Upload size={18} />
                  <span>Importer le fichier</span>
                </>
              )}
            </button>
          )}

          {uploadStatus.message && (
            <div className={`sync-status sync-status-${uploadStatus.status}`}>
              {uploadStatus.status === 'success' && <CheckCircle size={16} />}
              {uploadStatus.status === 'error' && <XCircle size={16} />}
              <span>{uploadStatus.message}</span>
              {uploadStatus.startTime && uploadStatus.endTime && (
                <span className="sync-duration">({formatDuration(uploadStatus.startTime, uploadStatus.endTime)})</span>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
