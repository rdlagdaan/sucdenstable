import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { getAuth } from '../utils/auth';

/**
 * Route guard:
 * - If a token exists → render the protected route (Outlet).
 * - Otherwise → bounce to /login and remember where we came from.
 */
export default function RequireAuth() {
  const { token } = getAuth();
  const location = useLocation();

  if (!token) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }
  return <Outlet />;
}
