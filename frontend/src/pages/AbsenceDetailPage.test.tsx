import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AbsenceDetailPage } from './AbsenceDetailPage';
import { apiRequest } from '../lib/api';

const auth = vi.hoisted(() => ({ canManage: true }));
vi.mock('../contexts/useAuth', () => ({ useAuth: () => ({ token: 'test', canAccess: (feature: string) => feature !== 'absences.manage' || auth.canManage }) }));
vi.mock('../lib/api', () => ({
  apiRequest: vi.fn(),
  openAuthenticatedFile: vi.fn(),
  ApiError: class extends Error {},
}));

const absence = {
  id: 7, type: 'masterclass', status: 'non_justifiee', detectedAt: '2026-10-01T03:00:00+02:00',
  notificationSentAt: null, hasActiveJustificationToken: false, justificationTokenExpiresAt: null,
  justificationSubmittedAt: null, justificationFileOriginalName: null, justificationFileAvailable: false,
  confirmationSentAt: null, validatedAt: null, adminNote: null,
  learner: { id: 1, fullName: 'Alice Martin', email: 'alice@example.test', consecutiveUnjustifiedMasterclassAbsences: 0, alertTriggered: false },
  session: { id: 3, title: 'Masterclass', startAt: null, endAt: null },
  validatedByName: null, events: [],
};

function renderPage() {
  render(
    <MemoryRouter initialEntries={['/absences/7']}>
      <Routes><Route path="/absences/:id" element={<AbsenceDetailPage />} /></Routes>
    </MemoryRouter>,
  );
}

describe('AbsenceDetailPage – dépôt par l’équipe', () => {
  beforeEach(() => { vi.clearAllMocks(); auth.canManage = true; });

  it('envoie le fichier choisi en multipart et affiche le justificatif sans changer le statut', async () => {
    vi.mocked(apiRequest)
      .mockResolvedValueOnce(absence)
      .mockResolvedValueOnce({
        justificationFileOriginalName: 'certificat.pdf', justificationFileAvailable: true,
        justificationSubmittedAt: '2026-10-08T15:00:00+02:00', status: 'non_justifiee',
        events: [{ type: 'justification_submitted', occurredAt: '2026-10-08T15:00:00+02:00', actorName: 'Sophie Durand', metadata: { fileOriginalName: 'certificat.pdf', replacement: false, uploadedByTeam: true } }],
      });
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: /Déposer un justificatif/ }));
    const file = new File(['%PDF-1.4'], 'certificat.pdf', { type: 'application/pdf' });
    fireEvent.change(screen.getByTestId('justification-upload-input'), { target: { files: [file] } });

    await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
    const [path, options] = vi.mocked(apiRequest).mock.calls[1];
    expect(path).toBe('/api/admin/absences/7/justification-file');
    expect(options?.method).toBe('POST');
    expect((options?.body as FormData).get('file')).toBe(file);
    expect(await screen.findByText(/Le statut n’a pas changé/)).toBeInTheDocument();
    expect(screen.getAllByText('certificat.pdf').length).toBeGreaterThan(0);
    expect(screen.getByText('Justificatif déposé par Sophie Durand')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Remplacer le justificatif/ })).toBeInTheDocument();
  });

  it('ne propose pas le dépôt sans le droit de gestion des absences', async () => {
    auth.canManage = false;
    vi.mocked(apiRequest).mockResolvedValueOnce(absence);
    renderPage();

    expect(await screen.findByText('Aucun justificatif déposé.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Déposer un justificatif/ })).not.toBeInTheDocument();
  });
});
