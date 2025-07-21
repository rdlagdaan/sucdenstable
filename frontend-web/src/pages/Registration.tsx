import { getCsrfToken } from '../utils/csrf';
import { useState, useEffect } from 'react';
//import api from '../utils/axios';
import napi from '../utils/axiosnapi';
import Cookies from 'js-cookie';

interface Company {
  id: number;
  company_name: string;
}

interface Role {
  id: number;
  role: string;
}


interface RegisterResponse {
  status: string;
  message: string;
  data: {
    id: number;
    role: string;
    created_at: string;
    updated_at: string;
  };
}


const Registration = () => {
  const [companies, setCompanies] = useState<Company[]>([]);
  const [roles, setRoles] = useState<Role[]>([]);
  const [showPassword, setShowPassword] = useState(false);
  const [formErrors, setFormErrors] = useState<{ [key: string]: string }>({});

  const [formData, setFormData] = useState({
    last_name: '',
    first_name: '',
    middle_name: '',
    username: '',
    email: '',
    password: '',
    confirmPassword: '',
    company: '',
    role_id: '',
    designation: '',
  });

  useEffect(() => {
    const fetchData = async () => {
      try {
        const companiesResponse = await napi.get<Company[]>('/api/companies');
        setCompanies(companiesResponse.data);

        const rolesResponse = await napi.get<Role[]>('/api/roles');
        setRoles(rolesResponse.data);
      } catch (error) {
        console.error('Failed to fetch companies or roles:', error);
      }
    };

    fetchData();
  }, []);

  const validate = () => {
    const errors: { [key: string]: string } = {};
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const strongPassword = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/;

    if (!formData.username.trim()) errors.username = 'Username is required.';

    if (!formData.last_name.trim()) errors.last_name = 'Last Name is required.';

    if (!formData.first_name.trim()) errors.first_name = 'First Name is required.';
    
    if (!formData.middle_name.trim()) errors.middle_name = 'Middle Name is required.';

    if (!formData.email.trim()) errors.email = 'Email is required.';
    else if (!emailRegex.test(formData.email)) errors.email = 'Invalid email format.';
    
    if (!formData.password) errors.password = 'Password is required.';
    else if (!strongPassword.test(formData.password)) errors.password = 'Password must be at least 8 characters and include uppercase, lowercase, and number.';
    
    if (formData.password !== formData.confirmPassword) errors.confirmPassword = 'Passwords do not match.';
    
    if (!formData.company.trim()) errors.company = 'Please select a company name.';

    if (!formData.role_id.trim()) errors.role = 'Please select a role.';

    if (!formData.designation.trim()) errors.designation = 'Designation is required.';
    return errors;
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
    setFormData({ ...formData, [e.target.name]: e.target.value });
  };




const handleSubmit = async (e: React.FormEvent) => {
  e.preventDefault();
    const errors = validate();
    setFormErrors(errors);
  try {
      await getCsrfToken(); // centralized

      // Step 2: Submit
      const response = await napi.post<RegisterResponse>('/api/register', formData, {
        headers: {
          'X-XSRF-TOKEN': Cookies.get('XSRF-TOKEN') || '',
        },
        withCredentials: true,
      });
      alert(response.data.message);
  } catch (error: any) {
      if (error.response && error.response.status === 409) {
        alert(error.response.data.message);
      } else if (error.response && error.response.data?.message) {
        alert('Error: ' + error.response.data.message);
      } else {
        alert('An unexpected error occurred.');
      }
    }
};


  return (
    <div
      className="bg-fixed w-screen h-screen bg-cover bg-center flex items-center justify-center"
      style={{ backgroundImage: "url('/sugarcaneBG.jpg')" }}
    >
      <div className="absolute top-[2%] bg-blue backdrop-blur-md shadow-lg rounded-lg p-8 w-full max-w-xl mx-2">
        <div className="flex justify-center items-center gap-4 mb-6">
          <img src="/sucdenLogo.jpg" alt="SUCDEN" className="h-14" />
          <img src="/ameropLogo.jpg" alt="AMEROP" className="h-14" />
        </div>
        <h4 className="text-lg font-bold text-center text-gray-800 mb-6">
          Create an Account
        </h4>
        <form className="space-y-2" onSubmit={handleSubmit}>



        <div className="grid grid-cols-2 gap-2">
        <div>
            <input
            type="text"
            name="last_name"
            placeholder="Last Name"
            value={formData.last_name}
            onChange={handleChange}
            className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-green"
            />
            {formErrors.last_name && (
            <p className="text-red-500 text-sm">{formErrors.last_name}</p>
            )}
        </div>

        <div>
            <input
            type="text"
            name="first_name"
            placeholder="First Name"
            value={formData.first_name}
            onChange={handleChange}
            className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
            {formErrors.first_name && (
            <p className="text-red-500 text-sm">{formErrors.first_name}</p>
            )}
        </div>
        </div>



        <div className="grid grid-cols-2 gap-2">
        <div>
            <input
            type="text"
            name="middle_name"
            placeholder="Middle Name"
            value={formData.middle_name}
            onChange={handleChange}
            className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
            {formErrors.middle_name && (
            <p className="text-red-500 text-sm">{formErrors.middle_name}</p>
            )}
        </div>

        <div>
        <input
            type="text"
            name="designation"
            placeholder="Designation"
            value={formData.designation}
            onChange={handleChange}
            className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
        />
        {formErrors.designation && <p className="text-red-500 text-sm">{formErrors.designation}</p>}
          </div>
        </div>


        <div className="grid grid-cols-2 gap-2">
        <div>
            <input
            type="text"
            name="username"
            placeholder="Username"
            value={formData.username}
            onChange={handleChange}
            className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
            {formErrors.username && (
            <p className="text-red-500 text-sm">{formErrors.username}</p>
            )}
        </div>

        <div>
            <input
            type="email"
            name="email"
            placeholder="Email"
            value={formData.email}
            onChange={handleChange}
            className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
            {formErrors.email && (
            <p className="text-red-500 text-sm">{formErrors.email}</p>
            )}
        </div>
        </div>


        <div className="grid grid-cols-2 gap-2">
          <div>
            <input
              type={showPassword ? 'text' : 'password'}
              name="password"
              placeholder="Password"
              value={formData.password}
              onChange={handleChange}
              className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
            <button
              type="button"
              className="text-xs mt-1 text-blue-600 hover:underline"
              onClick={() => setShowPassword(prev => !prev)}
            >
              {showPassword ? 'Hide' : 'Show'} Password
            </button>
            {formErrors.password && <p className="text-red-500 text-sm">{formErrors.password}</p>}
          </div>

          <div>
            <input
              type="password"
              name="confirmPassword"
              placeholder="Confirm Password"
              value={formData.confirmPassword}
              onChange={handleChange}
              className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            />
            {formErrors.confirmPassword && <p className="text-red-500 text-sm">{formErrors.confirmPassword}</p>}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-2">
          <div>
            <select
              name="company"
              value={formData.company}
              onChange={handleChange}
              className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            >
              <option value="">Select Company</option>
              {companies.map((company) => (
                <option key={company.id} value={company.id}>
                  {company.company_name}
                </option>
              ))}
            </select>
            {formErrors.company && <p className="text-red-500 text-sm">{formErrors.company}</p>}

          </div>

          <div>
            <select
              name="role_id"
              value={formData.role_id}
              onChange={handleChange}
              className="text-lg w-full rounded-md border px-3 py-2 shadow-sm text-gray-800"
            >
              <option value="">Select Role</option>
              {roles.map((role) => (
                <option key={role.id} value={role.id}>
                  {role.role}
                </option>
              ))}
            </select>
            {formErrors.role && <p className="text-red-500 text-sm">{formErrors.role}</p>}

          </div>
        </div>
        


          <button
            type="submit"
            className="text-lg w-full mt-2 rounded-md bg-indigo-600 px-3 py-2 text-white font-semibold hover:bg-indigo-500"
          >
            Register
          </button>
        </form>
      </div>
    </div>
  );
};

export default Registration;
