import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AbsenceJustificationPage } from './AbsenceJustificationPage';
import { apiRequest } from '../lib/api';

vi.mock('../lib/api', () => ({
  apiRequest: vi.fn(),
  apiUrl: (path: string) => path,
  ApiError: class extends Error {},
}));

describe('AbsenceJustificationPage', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows a read-only document after validation', async () => {
    vi.mocked(apiRequest).mockResolvedValue({
      sessionLabel: 'Session', sessionStartAt: null, alreadySubmitted: true,
      fileOriginalName: 'document.pdf', submittedAt: null, daysRemaining: 3, canSubmit: false,
    });
    render(<MemoryRouter initialEntries={['/absences/justificatif?token=test']}><AbsenceJustificationPage /></MemoryRouter>);
    expect(await screen.findByText(/Cette absence a déjà été traitée/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Voir le justificatif transmis' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Remplacer/ })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Justificatif')).not.toBeInTheDocument();
  });

  it('allows a first upload while the absence is pending', async () => {
    vi.mocked(apiRequest).mockResolvedValue({
      sessionLabel: 'Session', sessionStartAt: null, alreadySubmitted: false,
      fileOriginalName: null, submittedAt: null, daysRemaining: 3, canSubmit: true,
    });
    render(<MemoryRouter initialEntries={['/absences/justificatif?token=test']}><AbsenceJustificationPage /></MemoryRouter>);
    expect(await screen.findByLabelText('Justificatif')).toBeInTheDocument();
  });
});
