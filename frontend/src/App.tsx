import { lazy, Suspense } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AppLayout } from './components/AppLayout';
import { FeatureGate } from './components/FeatureGate';
import { ProtectedRoute } from './components/ProtectedRoute';
import { AuthProvider } from './contexts/AuthContext';
import { LoginPage } from './pages/LoginPage';

const AnalyticsPage = lazy(() => import('./pages/AnalyticsPage').then((m) => ({ default: m.AnalyticsPage })));
const DashboardPage = lazy(() => import('./pages/DashboardPage').then((m) => ({ default: m.DashboardPage })));
const ExportsPage = lazy(() => import('./pages/ExportsPage').then((m) => ({ default: m.ExportsPage })));
const IntegrationsPage = lazy(() => import('./pages/IntegrationsPage').then((m) => ({ default: m.IntegrationsPage })));
const LearnersPage = lazy(() => import('./pages/LearnersPage').then((m) => ({ default: m.LearnersPage })));
const LearningPathsPage = lazy(() => import('./pages/LearningPathsPage').then((m) => ({ default: m.LearningPathsPage })));
const LearningPathDetailPage = lazy(() =>
  import('./pages/LearningPathDetailPage').then((m) => ({ default: m.LearningPathDetailPage })),
);
const GroupDetailPage = lazy(() => import('./pages/GroupDetailPage').then((m) => ({ default: m.GroupDetailPage })));
const RiseUpLogsPage = lazy(() => import('./pages/RiseUpLogsPage').then((m) => ({ default: m.RiseUpLogsPage })));
const RolesPage = lazy(() => import('./pages/RolesPage').then((m) => ({ default: m.RolesPage })));
const SyncManagementPage = lazy(() =>
  import('./pages/SyncManagementPage').then((m) => ({ default: m.SyncManagementPage })),
);
const TrainingsPage = lazy(() => import('./pages/TrainingsPage').then((m) => ({ default: m.TrainingsPage })));
const UsersPage = lazy(() => import('./pages/UsersPage').then((m) => ({ default: m.UsersPage })));

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Suspense
          fallback={
            <div className="grid min-h-screen place-items-center bg-background">
              <p className="flex items-center gap-2.5 text-xs uppercase tracking-[0.2em] text-muted-foreground">
                <span className="h-2 w-2 animate-pulse rounded-full bg-primary" aria-hidden="true" />
                Chargement...
              </p>
            </div>
          }
        >
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/" element={<Navigate replace to="/dashboard" />} />
              <Route
                path="/dashboard"
                element={
                  <FeatureGate feature="dashboard.view">
                    <DashboardPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/analytics"
                element={
                  <FeatureGate feature="analytics.view">
                    <AnalyticsPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/pathways"
                element={<Navigate replace to="/learningpaths" />}
              />
              <Route
                path="/learningpaths"
                element={
                  <FeatureGate feature="learningpaths.view">
                    <LearningPathsPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/learningpaths/:id"
                element={
                  <FeatureGate feature="learningpaths.view">
                    <LearningPathDetailPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/groups/:id"
                element={
                  <FeatureGate feature="dashboard.view">
                    <GroupDetailPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/learners"
                element={
                  <FeatureGate feature="learners.view">
                    <LearnersPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/courses"
                element={
                  <FeatureGate feature="courses.view">
                    <TrainingsPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/exports"
                element={
                  <FeatureGate feature="exports.view">
                    <ExportsPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/riseup-logs"
                element={
                  <FeatureGate feature="exports.view">
                    <RiseUpLogsPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/sync"
                element={
                  <FeatureGate feature="settings.users">
                    <SyncManagementPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/integrations"
                element={
                  <FeatureGate feature="integrations.view">
                    <IntegrationsPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/roles"
                element={
                  <FeatureGate feature="settings.roles">
                    <RolesPage />
                  </FeatureGate>
                }
              />
              <Route
                path="/users"
                element={
                  <FeatureGate feature="settings.users">
                    <UsersPage />
                  </FeatureGate>
                }
              />
            </Route>
          </Route>
        </Routes>
        </Suspense>
      </BrowserRouter>
    </AuthProvider>
  );
}

export default App;
