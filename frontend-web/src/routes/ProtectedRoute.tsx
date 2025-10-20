import { Navigate, Outlet } from 'react-router-dom';
import { getAuth } from '../utils/auth';

/**
 * Route guard:
 * - If we have a token in auth storage, render the nested route (Outlet).
 * - Otherwise, bounce to /login.
 *
 * Usage in App.tsx:
 *   <Route element={<ProtectedRoute />}>
 *     <Route path="/dashboard" element={<Dashboard />} />
 *   </Route>
 */
export default function ProtectedRoute() {
  const token = getAuth()?.token || localStorage.getItem('token');
  return token ? <Outlet /> : <Navigate to="/login" replace />;
}
