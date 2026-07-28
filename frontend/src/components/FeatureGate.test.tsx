import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, expect, it, vi } from 'vitest';
import { FeatureGate } from './FeatureGate';

const mockUseAuth = vi.fn();

vi.mock('../contexts/useAuth', () => ({
  useAuth: () => mockUseAuth(),
}));

function renderGate(feature: string) {
  return render(
    <MemoryRouter initialEntries={['/analytics']}>
      <Routes>
        <Route path="/dashboard" element={<div>Dashboard content</div>} />
        <Route
          path="/analytics"
          element={
            <FeatureGate feature={feature}>
              <div>Analytics content</div>
            </FeatureGate>
          }
        />
      </Routes>
    </MemoryRouter>,
  );
}

describe('FeatureGate', () => {
  it('renders the children when the user can access the feature', () => {
    mockUseAuth.mockReturnValue({ canAccess: (feature: string) => feature === 'analytics.view' });

    renderGate('analytics.view');

    expect(screen.getByText('Analytics content')).toBeInTheDocument();
  });

  it('redirects to /dashboard when the user cannot access the feature', () => {
    mockUseAuth.mockReturnValue({ canAccess: () => false });

    renderGate('analytics.view');

    expect(screen.getByText('Dashboard content')).toBeInTheDocument();
    expect(screen.queryByText('Analytics content')).not.toBeInTheDocument();
  });
});
