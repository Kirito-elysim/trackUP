import { useState, useMemo } from 'react';
import { Clock, Search, ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-react';
import { clampPercentage, formatDuration, formatPercentage, formatDateTime } from '../lib/format';

export type LearnerTableData = {
  id: number;
  learnerId: number;
  fullName: string;
  email: string | null;
  totalTime: number;
  sessionTime: number;
  elearningTime: number;
  expectedTime: number;
  expectedElearningTime: number;
  averageProgress?: number;
  subscribedAt?: string | null;
  joinedAt?: string | null;
};

type SortField = 
  | 'name' 
  | 'combinedTime' 
  | 'sessionTime' 
  | 'elearningTime' 
  | 'expectedTime' 
  | 'expectedElearningTime' 
  | 'timeCompletion' 
  | 'elearningCompletion' 
  | 'progress' 
  | 'subscribedAt';

type SortDirection = 'asc' | 'desc';

type LearnerTableProps = {
  data: LearnerTableData[];
  title?: string;
  showProgress?: boolean;
  onRowClick?: (learner: LearnerTableData) => void;
};

type SortIconProps = {
  field: SortField;
  sortField: SortField;
  sortDirection: SortDirection;
};

function SortIcon({ field, sortField, sortDirection }: SortIconProps) {
  if (sortField !== field) {
    return <ArrowUpDown size={14} className="sort-icon" />;
  }
  return sortDirection === 'asc' ? (
    <ArrowUp size={14} className="sort-icon active" />
  ) : (
    <ArrowDown size={14} className="sort-icon active" />
  );
}

export function LearnerTable({ 
  data, 
  title = 'Apprenants',
  showProgress = true,
  onRowClick 
}: LearnerTableProps) {
  const [searchQuery, setSearchQuery] = useState('');
  const [sortField, setSortField] = useState<SortField>('name');
  const [sortDirection, setSortDirection] = useState<SortDirection>('asc');

  const handleSort = (field: SortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const filteredAndSorted = useMemo(() => {
    let filtered = data;

    if (searchQuery.trim() !== '') {
      const query = searchQuery.toLowerCase();
      filtered = filtered.filter(
        (learner) =>
          learner.fullName.toLowerCase().includes(query) ||
          learner.email?.toLowerCase().includes(query)
      );
    }

    const sorted = [...filtered].sort((a, b) => {
      let aValue: string | number;
      let bValue: string | number;

      switch (sortField) {
        case 'name':
          aValue = a.fullName.toLowerCase();
          bValue = b.fullName.toLowerCase();
          break;
        case 'combinedTime':
          aValue = a.totalTime;
          bValue = b.totalTime;
          break;
        case 'sessionTime':
          aValue = a.sessionTime;
          bValue = b.sessionTime;
          break;
        case 'elearningTime':
          aValue = a.elearningTime;
          bValue = b.elearningTime;
          break;
        case 'expectedTime':
          aValue = a.expectedTime;
          bValue = b.expectedTime;
          break;
        case 'expectedElearningTime':
          aValue = a.expectedElearningTime;
          bValue = b.expectedElearningTime;
          break;
        case 'timeCompletion':
          aValue = a.expectedTime > 0 ? (a.sessionTime / a.expectedTime) * 100 : 0;
          bValue = b.expectedTime > 0 ? (b.sessionTime / b.expectedTime) * 100 : 0;
          break;
        case 'elearningCompletion':
          aValue = a.expectedElearningTime > 0 ? (a.elearningTime / a.expectedElearningTime) * 100 : 0;
          bValue = b.expectedElearningTime > 0 ? (b.elearningTime / b.expectedElearningTime) * 100 : 0;
          break;
        case 'progress':
          aValue = a.averageProgress ?? 0;
          bValue = b.averageProgress ?? 0;
          break;
        case 'subscribedAt':
          aValue = a.subscribedAt || a.joinedAt || '';
          bValue = b.subscribedAt || b.joinedAt || '';
          break;
        default:
          return 0;
      }

      if (aValue < bValue) return sortDirection === 'asc' ? -1 : 1;
      if (aValue > bValue) return sortDirection === 'asc' ? 1 : -1;
      return 0;
    });

    return sorted;
  }, [data, searchQuery, sortField, sortDirection]);

  return (
    <div className="section-card">
      <div className="learners-section-header">
        <div className="learners-title">
          <h3>{title}</h3>
          <p className="muted">{filteredAndSorted.length} {title.toLowerCase()} ({data.length} total)</p>
        </div>

        <div className="search-bar">
          <Search size={18} className="search-icon" />
          <input
            type="text"
            className="search-input"
            placeholder={`Rechercher un ${title.toLowerCase().slice(0, -1)}...`}
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
        </div>
      </div>

      <div className="learners-table-container" style={{ overflowX: 'auto' }}>
        <table className="learners-table" style={{ minWidth: '75rem' }}>
          <thead>
            <tr>
              <th className="sortable-header" onClick={() => handleSort('name')}>
                <div className="header-content">
                  <span>{title === 'Membres' ? 'Membre' : 'Apprenant'}</span>
                  <SortIcon field="name" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              <th className="sortable-header" onClick={() => handleSort('combinedTime')}>
                <div className="header-content">
                  <span>Temps passé</span>
                  <SortIcon field="combinedTime" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              <th className="sortable-header" onClick={() => handleSort('sessionTime')}>
                <div className="header-content">
                  <span>Temps sessions</span>
                  <SortIcon field="sessionTime" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              <th className="sortable-header" onClick={() => handleSort('expectedTime')}>
                <div className="header-content">
                  <span>Temps prévu sessions</span>
                  <SortIcon field="expectedTime" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              <th className="sortable-header" onClick={() => handleSort('timeCompletion')}>
                <div className="header-content">
                  <span>Completion sessions</span>
                  <SortIcon field="timeCompletion" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              <th className="sortable-header" onClick={() => handleSort('elearningTime')}>
                <div className="header-content">
                  <span>Temps e-learning</span>
                  <SortIcon field="elearningTime" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              <th className="sortable-header" onClick={() => handleSort('expectedElearningTime')}>
                <div className="header-content">
                  <span>Temps prévu e-learning</span>
                  <SortIcon field="expectedElearningTime" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              <th className="sortable-header" onClick={() => handleSort('elearningCompletion')}>
                <div className="header-content">
                  <span>Completion e-learning</span>
                  <SortIcon field="elearningCompletion" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
              {showProgress && (
                <th className="sortable-header" onClick={() => handleSort('progress')}>
                  <div className="header-content">
                    <span>Progression</span>
                    <SortIcon field="progress" sortField={sortField} sortDirection={sortDirection} />
                  </div>
                </th>
              )}
              <th className="sortable-header" onClick={() => handleSort('subscribedAt')}>
                <div className="header-content">
                  <span>Date d'inscription</span>
                  <SortIcon field="subscribedAt" sortField={sortField} sortDirection={sortDirection} />
                </div>
              </th>
            </tr>
          </thead>
          <tbody>
            {filteredAndSorted.map((learner) => (
              <tr
                key={learner.id}
                onClick={() => onRowClick?.(learner)}
                style={{ cursor: onRowClick ? 'pointer' : 'default' }}
              >
                <td>
                  <div className="learner-info">
                    <div className="learner-avatar">
                      {learner.fullName.charAt(0).toUpperCase()}
                    </div>
                    <div>
                      <strong>{learner.fullName}</strong>
                      <p className="learner-email">{learner.email}</p>
                    </div>
                  </div>
                </td>
                <td>
                  <div className="time-cell">
                    <Clock size={16} />
                    <strong>{formatDuration(learner.sessionTime + learner.elearningTime)}</strong>
                  </div>
                </td>
                <td>
                  <div className="time-cell">
                    <Clock size={16} />
                    <strong>{formatDuration(learner.sessionTime)}</strong>
                  </div>
                </td>
                <td>
                  <div className="time-cell">
                    <Clock size={16} />
                    <strong>{formatDuration(learner.expectedTime)}</strong>
                  </div>
                </td>
                <td>
                  <div className="completion-cell">
                    <div className="progress-bar-small">
                      <div
                        className="progress-fill-small"
                        style={{ 
                          width: `${Math.min(learner.expectedTime > 0 ? (learner.sessionTime / learner.expectedTime) * 100 : 0, 100)}%`,
                          background: learner.expectedTime > 0 && (learner.sessionTime / learner.expectedTime) >= 0.8 
                            ? 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' 
                            : 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)'
                        }}
                      />
                    </div>
                    <span className="progress-text">
                      {learner.expectedTime > 0 
                        ? formatPercentage((learner.sessionTime / learner.expectedTime) * 100)
                        : '0%'
                      }
                    </span>
                  </div>
                </td>
                <td>
                  <div className="time-cell">
                    <Clock size={16} />
                    <strong>{formatDuration(learner.elearningTime)}</strong>
                  </div>
                </td>
                <td>
                  <div className="time-cell">
                    <Clock size={16} />
                    <strong>{formatDuration(learner.expectedElearningTime)}</strong>
                  </div>
                </td>
                <td>
                  <div className="completion-cell">
                    <div className="progress-bar-small">
                      <div
                        className="progress-fill-small"
                        style={{ 
                          width: `${Math.min(learner.expectedElearningTime > 0 ? (learner.elearningTime / learner.expectedElearningTime) * 100 : 0, 100)}%`,
                          background: learner.expectedElearningTime > 0 && (learner.elearningTime / learner.expectedElearningTime) >= 0.8 
                            ? 'linear-gradient(135deg, #34C4AC 0%, #00A67E 100%)' 
                            : 'linear-gradient(135deg, #FFB946 0%, #FF9A00 100%)'
                        }}
                      />
                    </div>
                    <span className="progress-text">
                      {learner.expectedElearningTime > 0 
                        ? formatPercentage((learner.elearningTime / learner.expectedElearningTime) * 100)
                        : '0%'
                      }
                    </span>
                  </div>
                </td>
                {showProgress && (
                  <td>
                    <div className="progress-cell">
                      <div className="progress-bar-small">
                        <div
                          className="progress-fill-small"
                          style={{ width: `${clampPercentage(learner.averageProgress)}%` }}
                        />
                      </div>
                      <span className="progress-text">{formatPercentage(learner.averageProgress ?? 0)}</span>
                    </div>
                  </td>
                )}
                <td>{learner.subscribedAt ? formatDateTime(learner.subscribedAt) : (learner.joinedAt ? formatDateTime(learner.joinedAt) : '-')}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {filteredAndSorted.length === 0 && (
          <div className="empty-state">
            <p>Aucun {title.toLowerCase().slice(0, -1)} trouvé.</p>
          </div>
        )}
      </div>
    </div>
  );
}
