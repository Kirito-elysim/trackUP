import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AppLayout } from './components/AppLayout';
import { FeatureGate } from './components/FeatureGate';
import { ProtectedRoute } from './components/ProtectedRoute';
import { AuthProvider } from './contexts/AuthContext';
import { DashboardPage } from './pages/DashboardPage';
import { ExportsPage } from './pages/ExportsPage';
import { IntegrationsPage } from './pages/IntegrationsPage';
import { LearnersPage } from './pages/LearnersPage';
import { LoginPage } from './pages/LoginPage';
import { RolesPage } from './pages/RolesPage';
import { TrainingsPage } from './pages/TrainingsPage';
import { UsersPage } from './pages/UsersPage';

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
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
      </BrowserRouter>
    </AuthProvider>
  );
}

export default App;
