export type AuthUser = Record<string, unknown> | null;

export function getAuth(): { token: string | null; user: AuthUser } {
  const token = localStorage.getItem('token');
  let user: AuthUser = null;
  const raw = localStorage.getItem('user');
  if (raw) {
    try {
      user = JSON.parse(raw);
    } catch {
      user = null;
    }
  }
  return { token, user };
}

export function setAuth(token: string, user: unknown) {
  localStorage.setItem('token', token);
  localStorage.setItem('user', JSON.stringify(user ?? null));
}

/** Back-compat alias for older code that imports `saveAuth` */
export function saveAuth(token: string, user: unknown) {
  return setAuth(token, user);
}

export function clearAuth() {
  localStorage.removeItem('token');
  localStorage.removeItem('user');
}
