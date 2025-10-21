import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api, { ensureCsrf } from '../utils/axiosnapi';

type Company = { id: number; company_name: string };

export default function Login() {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [companyId, setCompanyId] = useState<string>('');
  const [form, setForm] = useState({ username: '', password: '' });
  const [errorMessage, setErrorMessage] = useState('');
  const navigate = useNavigate();

  useEffect(() => {
    (async () => {
      try {
        const { data } = await api.get<Company[]>('/api/companies');
        setCompanies(Array.isArray(data) ? data : []);
      } catch (err) {
        console.error('Failed to fetch companies:', err);
      }
    })();
  }, []);

  const handleChange = (
    e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>
  ) => {
    const { name, value } = e.target;
    if (name === 'company_id') setCompanyId(value);
    else setForm((s) => ({ ...s, [name]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage('');

    try {
      // 1) Prime CSRF cookies (XSRF-TOKEN + session) for the :3001 origin
      await ensureCsrf();

      // 2) POST login with CSRF header auto-attached by axiosnapi
      const { data } = await api.post('/api/login', {
        username: form.username,
        password: form.password,
        company_id: companyId ? Number(companyId) : undefined,
      });

      // 3) Store auth & redirect
      localStorage.setItem('token', data?.token ?? '');
      localStorage.setItem('user', JSON.stringify(data?.user ?? {}));
      navigate('/dashboard');
    } catch (error: any) {
      const msg =
        error?.response?.data?.message ||
        error?.message ||
        'Login failed';
      setErrorMessage(msg);
    }
  };

  return (
    <div
      className="w-screen h-screen bg-cover bg-center bg-no-repeat flex items-center justify-center"
      style={{ backgroundImage: "url('/sugarcaneBG.jpg')" }}
    >
      <div className="bg-white/80 backdrop-blur-md shadow-lg rounded-lg p-8 w-full max-w-sm mx-4">
        <div className="flex justify-center items-center space-x-4 mb-2">
          <img src="/sucdenLogo.jpg" alt="SUCEDEN Logo" className="h-16" />
          <img src="/ameropLogo.jpg" alt="AMEROP Logo" className="h-14" />
        </div>

        <form className="space-y-4" onSubmit={handleSubmit}>
          <div>
            <label htmlFor="username" className="block text-sm font-medium text-gray-700">
              Username
            </label>
            <input
              id="username"
              name="username"
              value={form.username}
              onChange={handleChange}
              type="text"
              placeholder="Username"
              required
              className="mt-1 w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
          </div>

          <div>
            <label htmlFor="password" className="block text-sm font-medium text-gray-700">
              Password
            </label>
            <input
              id="password"
              name="password"
              value={form.password}
              onChange={handleChange}
              type="password"
              placeholder="Password"
              required
              className="mt-1 w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
          </div>

          <div>
            <label htmlFor="company_id" className="block text-sm font-medium text-gray-700">
              Company
            </label>
            <select
              id="company_id"
              name="company_id"
              value={companyId}
              onChange={handleChange}
              required
              className="mt-1 w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            >
              <option value="" disabled>Select a company</option>
              {companies.map((c) => (
                <option key={c.id} value={c.id.toString()}>
                  {c.company_name}
                </option>
              ))}
            </select>
          </div>

          {errorMessage && <div className="text-red-500 text-sm">{errorMessage}</div>}

          <button
            type="submit"
            className="w-full mt-4 rounded-md bg-indigo-600 px-4 py-2 text-white font-semibold hover:bg-indigo-500"
          >
            Sign in
          </button>
        </form>
      </div>
    </div>
  );
}
