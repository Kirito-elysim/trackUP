function decodeJwtPayload(token: string): Record<string, unknown> | null {
  const payload = token.split('.')[1];

  if (!payload) {
    return null;
  }

  try {
    const base64 = payload.replace(/-/g, '+').replace(/_/g, '/');
    return JSON.parse(atob(base64)) as Record<string, unknown>;
  } catch {
    return null;
  }
}

export function isTokenExpired(token: string): boolean {
  const claims = decodeJwtPayload(token);
  const exp = claims?.exp;

  if (typeof exp !== 'number') {
    return true;
  }

  return Date.now() >= exp * 1000;
}
