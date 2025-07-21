import { useEffect, useState } from 'react';
import api from '../utils/axios'; // Adjust based on your file structure

interface Company {
  id: number;
  company_name: string;
}

const Login = () => {

  const [companies, setCompanies] = useState<Company[]>([]);
  const [selectedCompany, setSelectedCompany] = useState('');


  useEffect(() => {
    const fetchCompanies = async () => {
      try {
        const response = await api.get<Company[]>('/api/companies');
        console.log(response.data);
        setCompanies(response.data); // ✅ Now properly typed
      } catch (error) {
        console.error('Failed to fetch companies:', error);
      }
    };

    fetchCompanies();
  }, []);

  return (
    <div
      className="w-screen h-screen bg-cover bg-center bg-no-repeat flex items-center justify-center"
      style={{
        backgroundImage: "url('/sugarcaneBG.jpg')",
      }}
    >
      <div className="bg-white/80 backdrop-blur-md shadow-lg rounded-lg p-8 w-full max-w-sm mx-4">
        <div className="flex justify-center items-center space-x-4 mb-2">
            <img src="/sucdenLogo.jpg" alt="SUCEDEN Logo" className="h-16" />
            <img src="/ameropLogo.jpg" alt="AMEROP Logo" className="h-14" />
        </div>

        <form className="space-y-4">
          <div>
            <label htmlFor="email" className="block text-sm font-medium text-gray-700">
              Email address
            </label>
            <input
              id="email"
              name="email"
              type="email"
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
              type="password"
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
