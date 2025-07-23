import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';  // Import useNavigate for redirection
import api from '../utils/axiosnapi';
import { getCsrfToken } from '../utils/csrf';

interface Company {
  id: number;
  company_name: string;
}

const Login = () => {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [selectedCompany, setSelectedCompany] = useState('');
  const [form, setForm] = useState({ username: '', password: '' });
  const [errorMessage, setErrorMessage] = useState('');
  const navigate = useNavigate();  // To handle the redirection
  
  useEffect(() => {
    const fetchCompanies = async () => {
      try {
        const response = await api.get<Company[]>('/api/companies');
        setCompanies(response.data);
      } catch (error) {
        console.error('Failed to fetch companies:', error);
      }
    };

    fetchCompanies();
  }, []);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setForm({ ...form, [e.target.name]: e.target.value });
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      await getCsrfToken();  // Ensure CSRF token is set for Sanctum

      // Send login request
      const response = await api.post('/api/login', {
        ...form,
        company_id: selectedCompany,
      });

      // Store token and user in localStorage (or in memory, or context)
      localStorage.setItem('token', response.data.token);
      localStorage.setItem('user', JSON.stringify(response.data.user));


      // Redirect to dashboard upon successful login
      navigate('/dashboard');  // Redirect to dashboard page

    } catch (error: any) {
      setErrorMessage(error.response?.data?.message || 'Login failed');
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
            <label htmlFor="email" className="block text-sm font-medium text-gray-700">
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
            <label htmlFor="company" className="block text-sm font-medium text-gray-700">
              Company
            </label>
            <select
              id="company"
              name="company"
              value={selectedCompany}
              onChange={(e) => setSelectedCompany(e.target.value)}
              required
              className="mt-1 w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            >
              <option value="" disabled>Select a company</option>
              {companies.map((company) => (
                <option key={company.id} value={company.id}>
                  {company.company_name}
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
};

export default Login;
