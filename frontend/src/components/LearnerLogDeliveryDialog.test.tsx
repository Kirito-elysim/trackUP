import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { LearnerLogDeliveryDialog } from './LearnerLogDeliveryDialog';
import { apiRequest } from '../lib/api';
vi.mock('../contexts/useAuth', () => ({ useAuth: () => ({ token: 'test' }) }));
vi.mock('../lib/api', () => ({ apiRequest: vi.fn(), apiUrl: (path: string) => path }));
const item = { learnerId: 1, name: 'Alice', tutor: 'Tuteur A', email: 'tutor@example.test', problem: null, subject: 'Logs Alice', text: 'Bonjour', filename: 'alice.pdf', status: 'pending' };
beforeEach(() => { vi.resetAllMocks(); URL.createObjectURL = vi.fn(() => 'blob:test'); URL.revokeObjectURL = vi.fn(); });

it('vérifie les tuteurs dès l’ouverture, affiche les exclusions et envoie seulement après confirmation', async () => {
  vi.mocked(apiRequest).mockResolvedValueOnce({ token: 'draft', items: [item, { ...item, learnerId: 2, name: 'Bob', problem: 'Aucun tuteur associé' }] }).mockResolvedValueOnce({ items: [{ ...item, status: 'sent' }] });
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, blob: async () => new Blob(['pdf']) }));
  render(<LearnerLogDeliveryDialog learnerIds={[1, 2]} paths={[{ id: 9, title: 'Parcours A' }]} onClose={() => {}} />);
  await waitFor(() => expect(screen.getByText('Confirmer l’envoi (1)')).toBeEnabled());
  expect(screen.getByText('Aucun tuteur associé')).toBeInTheDocument();
  expect(screen.getByTitle('PDF de Alice')).toBeInTheDocument();
  expect(apiRequest).toHaveBeenCalledTimes(1);
  expect(apiRequest).toHaveBeenCalledWith('/api/learner-log-deliveries/preview', { method: 'POST', token: 'test', body: { learnerIds: [1, 2], learningPathId: 9 } });
  fireEvent.click(screen.getByText('Confirmer l’envoi (1)'));
  await screen.findByText(/1 email envoyé/);
  expect(apiRequest).toHaveBeenLastCalledWith('/api/learner-log-deliveries/draft/send', { method: 'POST', token: 'test' });
});

it('interdit de confirmer quand aucun tuteur ne peut recevoir le mail', async () => {
  vi.mocked(apiRequest).mockResolvedValue({ token: 'draft', items: [{ ...item, problem: 'Email du tuteur absent ou invalide' }] });
  render(<LearnerLogDeliveryDialog learnerIds={[1]} paths={[{ id: 9, title: 'Parcours A' }]} onClose={() => {}} />);
  expect(await screen.findByText('Confirmer l’envoi (0)')).toBeDisabled();
});

it('fonctionne dans un groupe même sans parcours rattaché', async () => {
  vi.mocked(apiRequest).mockResolvedValue({ token: 'draft', items: [] });
  render(<LearnerLogDeliveryDialog learnerIds={[1]} groupId={4} paths={[]} contextLabel="TP REM" onClose={() => {}} />);
  await waitFor(() => expect(apiRequest).toHaveBeenCalledWith('/api/learner-log-deliveries/preview', { method: 'POST', token: 'test', body: { learnerIds: [1], groupId: 4 } }));
  expect(screen.getByText(/TP REM/)).toBeInTheDocument();
});

it('propose le bon lien de correction selon la raison de l’exclusion', async () => {
  vi.mocked(apiRequest).mockResolvedValue({ token: 'draft', items: [
    { ...item, learnerId: 5, name: 'Sans Tuteur', tutorId: null, tutor: null, email: null, problem: 'Aucun tuteur associé', fix: 'learner' },
    { ...item, learnerId: 6, name: 'Email KO', tutorId: 12, email: null, problem: 'Email du tuteur absent ou invalide', fix: 'tutor' },
  ] });
  render(<LearnerLogDeliveryDialog learnerIds={[5, 6]} groupId={4} paths={[]} onClose={() => {}} />);
  expect((await screen.findAllByRole('link', { name: /Assigner un tuteur/ }))[0]).toHaveAttribute('href', '/learners/5');
  expect(screen.getByRole('link', { name: /Corriger l’email du tuteur/ })).toHaveAttribute('href', '/tutors/12');
  fireEvent.click(screen.getAllByText('Revérifier')[0]);
  await waitFor(() => expect(apiRequest).toHaveBeenCalledTimes(2));
});
