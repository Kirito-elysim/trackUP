import { describe, expect, it } from 'vitest';
import { buildCsv } from './csv';

describe('buildCsv', () => {
  it('joins headers and rows with semicolons, quoting every field', () => {
    const csv = buildCsv(['Nom', 'Age'], [['Alice', 30], ['Bob', 25]]);

    expect(csv).toBe('"Nom";"Age"\n"Alice";"30"\n"Bob";"25"');
  });

  it('escapes embedded double quotes', () => {
    const csv = buildCsv(['Libelle'], [['Il a dit "bonjour"']]);

    expect(csv).toBe('"Libelle"\n"Il a dit ""bonjour"""');
  });

  it('returns just the header line when there are no rows', () => {
    expect(buildCsv(['A', 'B'], [])).toBe('"A";"B"');
  });
});
